<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Stops a SCIM write from leaving the store with no usable admin (active, holding an ACL role).
 *
 * Callers count usable admins before a write and assert afterwards, inside the same
 * transaction, so a deactivate/delete/role strip that would lock everyone out rolls back.
 * When no usable admin existed before, the write is allowed — SCIM did not cause it.
 */
class LastAdminGuard
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Number of active admins holding an ACL role, optionally ignoring one admin.
     *
     * @param int|null $excludeUserId admin_user.user_id not to count (e.g. one about to be deleted)
     */
    public function countUsable(?int $excludeUserId = null): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['u' => $this->resource->getTableName('admin_user')],
                ['count' => new Expression('COUNT(DISTINCT u.user_id)')]
            )
            ->join(['r' => $this->resource->getTableName('authorization_role')], 'r.user_id = u.user_id', [])
            ->where('r.user_type = ?', (string)UserContextInterface::USER_TYPE_ADMIN)
            ->where('r.parent_id > ?', 0)
            ->where('u.is_active = ?', 1);
        if ($excludeUserId !== null) {
            $select->where('u.user_id != ?', $excludeUserId);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * Reject the write when it removed the last usable admin.
     *
     * @param int $before {@see countUsable()} taken before the write
     * @param int|null $excludeUserId admin to ignore in the post-write count
     * @throws ScimException 400 `mutability` when usable admins existed before and none remain
     */
    public function assertUsableAdminRemains(int $before, ?int $excludeUserId = null): void
    {
        if ($before > 0 && $this->countUsable($excludeUserId) === 0) {
            throw ScimException::badRequest(
                'This change would leave no active admin user with a role.',
                'mutability'
            );
        }
    }
}
