<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use Magento\User\Model\User;

/**
 * Pure translation between a SCIM User payload and Magento's `admin_user`.
 *
 * Reads only the attributes this server provisions (userName, emails, name,
 * active, externalId) and renders an admin user back as a SCIM User resource.
 * Persistence, uniqueness and password generation live in {@see UserProvisioner};
 * this class holds no state and touches no storage.
 */
class ScimUserMapper
{
    /** Core SCIM User schema URN (RFC 7643 §4.1). */
    public const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /** admin_user column linking the account to its SCIM resource (see etc/db_schema.xml). */
    public const EXTERNAL_ID_FIELD = 'dmlab_scim_external_id';

    /** Lastname used when the payload carries no usable family name. */
    private const DEFAULT_LAST_NAME = 'SCIM';

    /** admin_user.firstname / lastname column length. */
    private const NAME_MAX_LENGTH = 32;

    /** admin_user.username column length. */
    private const USERNAME_MAX_LENGTH = 40;

    /** admin_user.email column length. */
    private const EMAIL_MAX_LENGTH = 128;

    /**
     * @param EndpointUrlBuilder $urls
     */
    public function __construct(
        private readonly EndpointUrlBuilder $urls
    ) {
    }

    /**
     * The required `userName`, trimmed.
     *
     * A login cannot be silently truncated (unlike a display name), so a value
     * exceeding the column length is rejected as 400 rather than surfacing as an
     * opaque DB 500 — an IdP treats 500 as transient and retries a doomed create.
     *
     * @param array<string,mixed> $payload
     * @throws ScimException 400 `invalidValue` when absent, empty or too long.
     */
    public function extractUserName(array $payload): string
    {
        $userName = isset($payload['userName']) && is_string($payload['userName'])
            ? trim($payload['userName'])
            : '';
        if ($userName === '') {
            throw ScimException::badRequest('Attribute "userName" is required.', 'invalidValue');
        }
        if (mb_strlen($userName) > self::USERNAME_MAX_LENGTH) {
            throw ScimException::badRequest(
                'Attribute "userName" exceeds the maximum length of ' . self::USERNAME_MAX_LENGTH . '.',
                'invalidValue'
            );
        }

        return $userName;
    }

    /**
     * The `externalId`, trimmed, or null when absent.
     *
     * @param array<string,mixed> $payload
     */
    public function extractExternalId(array $payload): ?string
    {
        if (!isset($payload['externalId']) || !is_string($payload['externalId'])) {
            return null;
        }
        $externalId = trim($payload['externalId']);

        return $externalId === '' ? null : $externalId;
    }

    /**
     * The primary email, lower-cased.
     *
     * Falls back to the first email, then to `userName` when that is itself an
     * address. Magento requires a valid admin email.
     *
     * @param array<string,mixed> $payload
     * @throws ScimException 400 `invalidValue` when no email can be derived.
     */
    public function extractEmail(array $payload): string
    {
        $emails = $payload['emails'] ?? null;
        $primary = null;
        $first = null;
        if (is_array($emails)) {
            foreach ($emails as $entry) {
                if (!is_array($entry) || !isset($entry['value']) || !is_string($entry['value'])) {
                    continue;
                }
                $value = trim($entry['value']);
                if ($value === '') {
                    continue;
                }
                $first ??= $value;
                if (!empty($entry['primary'])) {
                    $primary = $value;
                    break;
                }
            }
        }

        $email = $primary ?? $first;
        if ($email === null) {
            $userName = isset($payload['userName']) && is_string($payload['userName'])
                ? trim($payload['userName'])
                : '';
            if (filter_var($userName, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $userName;
            }
        }

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ScimException::badRequest(
                'A valid email address is required to provision an admin user.',
                'invalidValue'
            );
        }
        if (mb_strlen($email) > self::EMAIL_MAX_LENGTH) {
            throw ScimException::badRequest(
                'The email address exceeds the maximum length of ' . self::EMAIL_MAX_LENGTH . '.',
                'invalidValue'
            );
        }

        return strtolower($email);
    }

    /**
     * First/last name from the `name` complex attribute.
     *
     * Both are guaranteed non-empty (Magento requires both) and within the
     * column length.
     *
     * @param array<string,mixed> $payload
     * @param string $fallbackFirst used when the payload releases no given name
     * @return array{0:string,1:string}
     */
    public function extractName(array $payload, string $fallbackFirst): array
    {
        $name = is_array($payload['name'] ?? null) ? $payload['name'] : [];
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

        if ($given === '') {
            $given = $fallbackFirst;
        }
        if ($family === '') {
            $family = self::DEFAULT_LAST_NAME;
        }

        return [
            mb_substr($given, 0, self::NAME_MAX_LENGTH),
            mb_substr($family, 0, self::NAME_MAX_LENGTH),
        ];
    }

    /**
     * The `active` flag, defaulting to true when the payload omits it.
     *
     * Per RFC a created user is active unless explicitly disabled. A stringified
     * boolean (`"false"`/`"0"`) is honoured so a non-conformant client can still
     * deprovision — a bare `(bool)` cast would read `"false"` as true.
     *
     * @param array<string,mixed> $payload
     */
    public function extractActive(array $payload): bool
    {
        if (!array_key_exists('active', $payload)) {
            return true;
        }
        $value = $payload['active'];
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return $normalized === 'true' || $normalized === '1';
        }

        return (bool)$value;
    }

    /**
     * Render an admin user as a SCIM User resource document.
     *
     * @param User $user persisted admin user
     * @return array<string,mixed>
     */
    public function toResource(User $user): array
    {
        $id = (string)$user->getId();
        $resource = [
            'schemas' => [self::SCHEMA_USER],
            'id' => $id,
        ];

        $externalId = (string)$user->getData(self::EXTERNAL_ID_FIELD);
        if ($externalId !== '') {
            $resource['externalId'] = $externalId;
        }

        $first = (string)$user->getData('firstname');
        $last = (string)$user->getData('lastname');
        $email = (string)$user->getData('email');

        $resource['userName'] = (string)$user->getData('username');
        $resource['name'] = [
            'formatted' => trim($first . ' ' . $last),
            'givenName' => $first,
            'familyName' => $last,
        ];
        $resource['emails'] = [['value' => $email, 'primary' => true]];
        $resource['active'] = (int)$user->getData('is_active') === 1;
        $resource['meta'] = [
            'resourceType' => 'User',
            'location' => $this->urls->resourceUrl('Users/' . $id),
        ];

        return $resource;
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
}
