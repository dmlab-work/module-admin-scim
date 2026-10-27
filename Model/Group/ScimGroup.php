<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

/**
 * A provisioned SCIM Group: its SCIM id (`groupId`), `displayName`, optional
 * IdP `externalId` and UTC `Y-m-d H:i:s` timestamps. A plain mutable data holder — persistence lives in
 * {@see GroupResource}, membership in {@see MemberResource} and role mapping in
 * {@see GroupRoleSynchronizer}. `groupId` is null until the row is persisted.
 */
class ScimGroup
{
    /**
     * @param int|null $groupId SCIM resource id (null before persistence)
     * @param string $displayName SCIM displayName
     * @param string|null $externalId IdP externalId, or null
     * @param string|null $createdAt creation time (null before persistence)
     * @param string|null $updatedAt last write time (null before persistence)
     */
    public function __construct(
        public ?int $groupId = null,
        public string $displayName = '',
        public ?string $externalId = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null
    ) {
    }
}
