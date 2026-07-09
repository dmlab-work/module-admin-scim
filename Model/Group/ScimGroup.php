<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Group;

/**
 * A provisioned SCIM Group: its SCIM id (`groupId`), `displayName` and optional
 * IdP `externalId`. A plain mutable data holder — persistence lives in
 * {@see GroupResource}, membership in {@see MemberResource} and role mapping in
 * {@see GroupRoleSynchronizer}. `groupId` is null until the row is persisted.
 */
class ScimGroup
{
    /**
     * @param int|null $groupId SCIM resource id (null before persistence)
     * @param string $displayName SCIM displayName
     * @param string|null $externalId IdP externalId, or null
     */
    public function __construct(
        public ?int $groupId = null,
        public string $displayName = '',
        public ?string $externalId = null
    ) {
    }
}
