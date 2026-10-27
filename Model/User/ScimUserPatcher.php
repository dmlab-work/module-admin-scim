<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\AttributeLength;
use Magento\User\Model\User;

/**
 * Applies a SCIM PATCH `PatchOp` (RFC 7644 §3.5.2) to a loaded `admin_user`.
 *
 * Stores `active`, `userName`, `externalId`, `name` (`givenName`/`familyName`/
 * `formatted`) and `emails`; `emails[type eq "work"].value` and
 * `emails[primary eq true].value` set the email. `add` and `replace` are identical
 * for these single-valued attributes (RFC 7644 §3.5.2.1). Paths may carry the core
 * User URN prefix, and the bare core URN with an object value is path-less over it.
 * Attributes `admin_user` cannot store (other core attributes, extension schemas,
 * unknown names, other value filters) are accepted and ignored, so one unstored
 * attribute does not fail a whole IdP PatchOp; only malformed ops are 400. Mutates
 * the model in place; persistence and uniqueness live in {@see UserProvisioner}.
 */
class ScimUserPatcher
{
    use AttributeLength;

    /** admin_user.firstname / lastname column length. */
    private const NAME_MAX_LENGTH = 32;

    /** `attr`, `attr.sub`, `attr[filter]`, `attr[filter].sub` (RFC 7644 §3.10, after the URN prefix). */
    private const PATH_PATTERN = '/^([a-z][\w$-]*)(?:\[([^\[\]]+)\])?(?:\.([a-z][\w$-]*))?$/i';

    /** Value filters on `emails` that select the address `admin_user.email` holds. */
    private const EMAIL_FILTER_PATTERN = '/^\s*(?:type\s+eq\s+"work"|primary\s+eq\s+true)\s*$/i';

    /** Resolved target: the core User schema itself (path-less over the value). */
    private const TARGET_ROOT = '#root';

    /** Resolved target: an attribute admin_user does not store. */
    private const TARGET_IGNORED = '#ignored';

    /** Resolved target: `emails[type eq "work"|primary eq true].value`. */
    private const TARGET_FILTERED_EMAIL = 'emails[primary].value';

    /** Stored attribute paths, lower-cased. */
    private const STORED_TARGETS = [
        'active',
        'username',
        'externalid',
        'name',
        'name.givenname',
        'name.familyname',
        'name.formatted',
        'emails',
    ];

    /** Targets `remove` may not clear: Magento requires them on every admin. */
    private const REQUIRED_TARGETS = [
        self::TARGET_ROOT,
        self::TARGET_FILTERED_EMAIL,
        'active',
        'username',
        'name',
        'name.givenname',
        'name.familyname',
        'emails',
    ];

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
     * @throws ScimException 400 on a malformed op, unparseable path or bad value
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
     * @throws ScimException 400 on a malformed op, unparseable path or bad value
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
            $this->assignPathless($user, $operation['value'] ?? null);

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
     * Assign each attribute of a path-less (attribute→value object) value.
     *
     * @param User $user
     * @param mixed $value
     * @throws ScimException 400 `invalidValue` when the value is not an object
     */
    private function assignPathless(User $user, mixed $value): void
    {
        if (!is_array($value) || array_is_list($value)) {
            throw ScimException::badRequest(
                'A path-less PATCH operation requires an object "value".',
                'invalidValue'
            );
        }
        foreach ($value as $subPath => $subValue) {
            $this->assign($user, (string)$subPath, $subValue);
        }
    }

    /**
     * Set the attribute a SCIM path resolves to; unstored attributes are ignored.
     *
     * @param User $user
     * @param string $path SCIM attribute path (case-insensitive)
     * @param mixed $value
     * @throws ScimException 400 `invalidPath` when the path is unparseable, or
     *         `invalidValue` when the value is invalid for the attribute
     */
    private function assign(User $user, string $path, mixed $value): void
    {
        switch ($this->resolve($path)) {
            case self::TARGET_ROOT:
                $this->assignPathless($user, $value);
                break;
            case 'active':
                $user->setData('is_active', $this->toBool($value) ? 1 : 0);
                break;
            case 'username':
                $userName = $this->assertLength(
                    $this->requiredString($value, 'userName'),
                    'userName',
                    ScimUserMapper::USER_NAME_MAX_LENGTH
                );
                $user->setData(ScimUserMapper::USER_NAME_FIELD, $userName);
                $user->setData('username', $this->mapper->toLogin($userName));
                break;
            case 'externalid':
                $external = is_string($value) ? trim($value) : '';
                $user->setData(
                    ScimUserMapper::EXTERNAL_ID_FIELD,
                    $external === '' ? null : $this->assertLength($external, 'externalId')
                );
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
            case self::TARGET_FILTERED_EMAIL:
                $user->setData('email', $this->mapper->extractEmail(['emails' => [['value' => $value]]]));
                break;
            default:
                // TARGET_IGNORED: admin_user does not store it.
                break;
        }
    }

    /**
     * Apply a `remove` op.
     *
     * `externalId` is cleared; an unstored attribute is a no-op; removing a
     * Magento-required attribute is a mutability error.
     *
     * @param User $user
     * @param string $path
     * @throws ScimException 400 `mutability` for a required attribute, `invalidPath`
     *         for an unparseable path
     */
    private function remove(User $user, string $path): void
    {
        $target = $this->resolve($path);
        if ($target === 'externalid') {
            $user->setData(ScimUserMapper::EXTERNAL_ID_FIELD, null);

            return;
        }
        if (in_array($target, self::REQUIRED_TARGETS, true)) {
            throw ScimException::badRequest(sprintf('Attribute "%s" cannot be removed.', $path), 'mutability');
        }
    }

    /**
     * Resolve a SCIM path to a stored attribute target or a TARGET_* marker.
     *
     * @param string $path
     * @throws ScimException 400 `invalidPath` when the path does not parse
     */
    private function resolve(string $path): string
    {
        $path = trim($path);
        if (stripos($path, 'urn:') === 0) {
            if (strcasecmp($path, ScimUserMapper::SCHEMA_USER) === 0) {
                return self::TARGET_ROOT;
            }
            $prefix = ScimUserMapper::SCHEMA_USER . ':';
            if (strncasecmp($path, $prefix, strlen($prefix)) !== 0) {
                // Extension schema (e.g. enterprise User): nothing in admin_user.
                return self::TARGET_IGNORED;
            }
            $path = substr($path, strlen($prefix));
        }

        if (preg_match(self::PATH_PATTERN, $path, $m) !== 1) {
            throw ScimException::badRequest(sprintf('Invalid PATCH path "%s".', $path), 'invalidPath');
        }
        $attribute = strtolower($m[1]);
        $filter = $m[2] ?? '';
        $sub = strtolower($m[3] ?? '');

        if ($filter !== '') {
            return $attribute === 'emails' && $sub === 'value'
                && preg_match(self::EMAIL_FILTER_PATTERN, $filter) === 1
                ? self::TARGET_FILTERED_EMAIL
                : self::TARGET_IGNORED;
        }

        $target = $sub === '' ? $attribute : $attribute . '.' . $sub;

        return in_array($target, self::STORED_TARGETS, true) ? $target : self::TARGET_IGNORED;
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
