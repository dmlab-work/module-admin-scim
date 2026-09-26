<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;

/**
 * Pure translation between a SCIM Group payload and the persisted group.
 *
 * Reads the attributes the server provisions (displayName, externalId, members)
 * and renders a stored group back as a SCIM Group resource. Holds no state and
 * touches no storage; persistence and role mapping live in {@see GroupProvisioner}.
 */
class ScimGroupMapper
{
    /** Core SCIM Group schema URN (RFC 7643 §4.2). */
    public const SCHEMA_GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    /**
     * @param EndpointUrlBuilder $urls
     */
    public function __construct(
        private readonly EndpointUrlBuilder $urls
    ) {
    }

    /**
     * The required `displayName`, trimmed.
     *
     * @param array<string,mixed> $payload
     * @throws ScimException 400 `invalidValue` when absent or empty
     */
    public function extractDisplayName(array $payload): string
    {
        $displayName = isset($payload['displayName']) && is_string($payload['displayName'])
            ? trim($payload['displayName'])
            : '';
        if ($displayName === '') {
            throw ScimException::badRequest('Attribute "displayName" is required.', 'invalidValue');
        }

        return $displayName;
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
     * Admin-user ids from the `members` multi-valued attribute.
     *
     * Each member's `value` is the SCIM id of a User (an `admin_user.user_id`).
     * Non-numeric or non-positive values are rejected; the result is de-duplicated.
     *
     * @param array<string,mixed> $payload
     * @return int[]
     * @throws ScimException 400 `invalidValue` when a member value is not a valid id
     */
    public function extractMemberIds(array $payload): array
    {
        $members = $payload['members'] ?? null;
        if ($members === null) {
            return [];
        }
        if (!is_array($members) || ($members !== [] && !array_is_list($members))) {
            throw ScimException::badRequest('Attribute "members" must be an array.', 'invalidValue');
        }

        return $this->memberValuesToIds($members);
    }

    /**
     * Convert a list of SCIM member entries (or bare id scalars) to admin-user ids.
     *
     * @param array<int,mixed> $members
     * @return int[]
     * @throws ScimException 400 `invalidValue` when an entry has no valid id
     */
    public function memberValuesToIds(array $members): array
    {
        $ids = [];
        foreach ($members as $member) {
            $value = is_array($member) ? ($member['value'] ?? null) : $member;
            $id = $this->memberValueToId($value);
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * Parse a single SCIM member `value` to a positive admin-user id.
     *
     * Accepts only a positive integer or a digit-only string; loose forms like
     * `"1.5"`, `"1e2"` or the JSON number `1.9` are rejected rather than silently
     * truncated to a different id, since the id drives ACL role assignment.
     *
     * @param mixed $value
     * @throws ScimException 400 `invalidValue` when not a positive integer id
     */
    public function memberValueToId(mixed $value): int
    {
        $id = 0;
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $id = (int)$value;
        }
        if ($id <= 0) {
            throw ScimException::badRequest(
                'Each group member must carry a positive integer user "value".',
                'invalidValue'
            );
        }

        return $id;
    }

    /**
     * Render a stored group as a SCIM Group resource document.
     *
     * @param ScimGroup $group persisted group
     * @param array<int,array{value:string,display:string}> $members member records
     * @return array<string,mixed>
     */
    public function toResource(ScimGroup $group, array $members): array
    {
        $id = (string)$group->groupId;
        $resource = [
            'schemas' => [self::SCHEMA_GROUP],
            'id' => $id,
        ];

        if ($group->externalId !== null && $group->externalId !== '') {
            $resource['externalId'] = $group->externalId;
        }

        $resource['displayName'] = $group->displayName;
        $resource['members'] = array_map(
            fn (array $member): array => [
                'value' => $member['value'],
                'display' => $member['display'],
                '$ref' => $this->urls->resourceUrl('Users/' . $member['value']),
            ],
            $members
        );
        $resource['meta'] = [
            'resourceType' => 'Group',
            'location' => $this->urls->resourceUrl('Groups/' . $id),
        ];

        return $resource;
    }
}
