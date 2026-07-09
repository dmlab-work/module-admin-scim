<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\User;

use MageDevGroup\AdminScim\Exception\ScimException;
use Magento\User\Model\User;

/**
 * Applies a SCIM PATCH `PatchOp` (RFC 7644 §3.5.2) to a loaded `admin_user`.
 *
 * Handles the strict-RFC subset the server provisions: `active`, `userName`,
 * `externalId`, `name` (and its `givenName`/`familyName`/`formatted`
 * sub-attributes) and `emails`. `add` and `replace` are treated identically for
 * these single-valued attributes (RFC 7644 §3.5.2.1: an `add` on a single-valued
 * attribute replaces its value) — this also absorbs the Entra ADD/REPLACE
 * inconsistency for our attributes. Path-less operations (whose `value` is an
 * object of attribute→value) are supported, which additionally tolerates Entra's
 * flat complex-attribute form. IdP-specific deviations beyond this baseline are
 * normalized in `admin-scim-<idp>` plugins (Task 8), not here. Mutates the model
 * in place; persistence and uniqueness live in {@see UserProvisioner}.
 */
class ScimUserPatcher
{
    /** admin_user.firstname / lastname column length. */
    private const NAME_MAX_LENGTH = 32;

    /** admin_user.username column length. */
    private const USERNAME_MAX_LENGTH = 40;

    /**
     * @param ScimUserMapper $mapper
     */
    public function __construct(
        private readonly ScimUserMapper $mapper
    ) {
    }

    /**
     * Apply every operation in a PatchOp body to the user, in order.
     *
     * @param User $user loaded admin user to mutate
     * @param array<string,mixed> $body decoded PatchOp resource
     * @throws ScimException 400 on a malformed op, unsupported path or bad value
     */
    public function apply(User $user, array $body): void
    {
        foreach ($this->operations($body) as $operation) {
            $this->applyOperation($user, $operation);
        }
    }

    /**
     * The non-empty `Operations` list, validated.
     *
     * @param array<string,mixed> $body
     * @return array<int,mixed>
     * @throws ScimException 400 `invalidSyntax` when absent, empty or not a list
     */
    private function operations(array $body): array
    {
        $operations = $body['Operations'] ?? $body['operations'] ?? null;
        if (!is_array($operations) || $operations === [] || !array_is_list($operations)) {
            throw ScimException::badRequest(
                'A PATCH request must carry a non-empty "Operations" array.',
                'invalidSyntax'
            );
        }

        return $operations;
    }

    /**
     * Apply a single PatchOp operation.
     *
     * @param User $user
     * @param mixed $operation
     * @throws ScimException 400 on a malformed op, unsupported path or bad value
     */
    private function applyOperation(User $user, mixed $operation): void
    {
        if (!is_array($operation)) {
            throw ScimException::badRequest('Each PATCH operation must be an object.', 'invalidSyntax');
        }

        $op = strtolower(trim((string)($operation['op'] ?? '')));
        if (!in_array($op, ['add', 'replace', 'remove'], true)) {
            throw ScimException::badRequest(
                sprintf('Unsupported PATCH op "%s".', (string)($operation['op'] ?? '')),
                'invalidSyntax'
            );
        }

        $path = isset($operation['path']) && is_string($operation['path']) ? trim($operation['path']) : '';

        if ($op === 'remove') {
            if ($path === '') {
                throw ScimException::badRequest('A "remove" operation requires a "path".', 'noTarget');
            }
            $this->remove($user, $path);

            return;
        }

        // add / replace
        if ($path === '') {
            $value = $operation['value'] ?? null;
            if (!is_array($value) || array_is_list($value)) {
                throw ScimException::badRequest(
                    'A path-less PATCH operation requires an object "value".',
                    'invalidValue'
                );
            }
            foreach ($value as $subPath => $subValue) {
                $this->assign($user, (string)$subPath, $subValue);
            }

            return;
        }

        if (!array_key_exists('value', $operation)) {
            throw ScimException::badRequest(
                sprintf('PATCH "%s" for path "%s" requires a "value".', $op, $path),
                'invalidValue'
            );
        }
        $this->assign($user, $path, $operation['value']);
    }

    /**
     * Set one supported attribute (identified by its SCIM path) on the user.
     *
     * @param User $user
     * @param string $path SCIM attribute path (case-insensitive)
     * @param mixed $value
     * @throws ScimException 400 `invalidPath` when the path is unsupported, or
     *         `invalidValue` when the value is invalid for the attribute
     */
    private function assign(User $user, string $path, mixed $value): void
    {
        switch (strtolower($path)) {
            case 'active':
                $user->setData('is_active', $this->toBool($value) ? 1 : 0);
                break;
            case 'username':
                $userName = $this->requiredString($value, 'userName');
                if (mb_strlen($userName) > self::USERNAME_MAX_LENGTH) {
                    // A login cannot be silently truncated, and an over-length value
                    // would surface as an opaque DB 500 the IdP retries forever —
                    // reject as 400 (mirrors ScimUserMapper::extractUserName).
                    throw ScimException::badRequest(
                        'Attribute "userName" exceeds the maximum length of ' . self::USERNAME_MAX_LENGTH . '.',
                        'invalidValue'
                    );
                }
                $user->setData('username', $userName);
                break;
            case 'externalid':
                $external = is_string($value) ? trim($value) : '';
                $user->setData(ScimUserMapper::EXTERNAL_ID_FIELD, $external === '' ? null : $external);
                break;
            case 'name':
                $this->assignName($user, is_array($value) ? $value : []);
                break;
            case 'name.givenname':
                $user->setData('firstname', $this->truncateName($this->requiredString($value, 'name.givenName')));
                break;
            case 'name.familyname':
                $user->setData('lastname', $this->truncateName($this->requiredString($value, 'name.familyName')));
                break;
            case 'name.formatted':
                $this->assignName($user, ['formatted' => is_string($value) ? $value : '']);
                break;
            case 'emails':
                $user->setData('email', $this->mapper->extractEmail(['emails' => is_array($value) ? $value : []]));
                break;
            default:
                throw ScimException::badRequest(sprintf('Unsupported PATCH path "%s".', $path), 'invalidPath');
        }
    }

    /**
     * Apply the removable subset of a `remove` op.
     *
     * Only `externalId` (an optional link) may be cleared; removing a
     * Magento-required attribute is a mutability error.
     *
     * @param User $user
     * @param string $path
     * @throws ScimException 400 `mutability` for a non-removable attribute
     */
    private function remove(User $user, string $path): void
    {
        if (strtolower($path) === 'externalid') {
            $user->setData(ScimUserMapper::EXTERNAL_ID_FIELD, null);

            return;
        }

        throw ScimException::badRequest(sprintf('Attribute "%s" cannot be removed.', $path), 'mutability');
    }

    /**
     * Set first/last name from a `name` complex value.
     *
     * Splits `formatted` when the explicit parts are absent (mirrors
     * {@see ScimUserMapper::extractName}).
     *
     * @param User $user
     * @param array<string,mixed> $name
     */
    private function assignName(User $user, array $name): void
    {
        $given = $this->stringValue($name, 'givenName');
        $family = $this->stringValue($name, 'familyName');

        if ($given === '' || $family === '') {
            $formatted = $this->stringValue($name, 'formatted');
            if ($formatted !== '') {
                $parts = preg_split('/\s+/', $formatted) ?: [$formatted];
                $given = $given !== '' ? $given : (string)array_shift($parts);
                $family = $family !== '' ? $family : ($parts === [] ? '' : implode(' ', $parts));
            }
        }

        if ($given !== '') {
            $user->setData('firstname', $this->truncateName($given));
        }
        if ($family !== '') {
            $user->setData('lastname', $this->truncateName($family));
        }
    }

    /**
     * Coerce a SCIM boolean value (native bool, or `"true"`/`"1"` string).
     *
     * @param mixed $value
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return $normalized === 'true' || $normalized === '1';
        }

        return (bool)$value;
    }

    /**
     * A trimmed, non-empty string value for a required attribute.
     *
     * @param mixed $value
     * @param string $attribute SCIM attribute name for the error detail
     * @throws ScimException 400 `invalidValue` when empty or not a string
     */
    private function requiredString(mixed $value, string $attribute): string
    {
        $string = is_string($value) ? trim($value) : '';
        if ($string === '') {
            throw ScimException::badRequest(
                sprintf('Attribute "%s" must be a non-empty string.', $attribute),
                'invalidValue'
            );
        }

        return $string;
    }

    /**
     * Trimmed string sub-attribute, or empty string when absent/non-string.
     *
     * @param array<string,mixed> $data
     * @param string $key
     */
    private function stringValue(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : '';
    }

    /**
     * Clamp a name to the admin_user column length.
     *
     * @param string $name
     */
    private function truncateName(string $name): string
    {
        return mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }
}
