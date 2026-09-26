<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

use Magento\Framework\App\ResourceConnection;

/**
 * Membership storage for SCIM Groups (`dmlab_scim_group_member`).
 *
 * Direct-connection access to the join table: read a group's members (with the
 * admin username for the SCIM `display` sub-attribute), add/remove/replace members,
 * and — for role synchronization — list the group display names an admin user
 * belongs to. Member add/remove is idempotent (an INSERT IGNORE / targeted DELETE).
 */
class MemberResource
{
    private const MEMBER_TABLE = 'dmlab_scim_group_member';
    private const GROUP_TABLE = 'dmlab_scim_group';
    private const USER_TABLE = 'admin_user';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Members of a group as SCIM member records, ordered by user id.
     *
     * @param int $groupId
     * @return array<int,array{value:string,display:string}>
     */
    public function getMembers(int $groupId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['m' => $this->resource->getTableName(self::MEMBER_TABLE)], ['user_id'])
            ->joinLeft(
                ['u' => $this->resource->getTableName(self::USER_TABLE)],
                'u.user_id = m.user_id',
                ['username']
            )
            ->where('m.group_id = ?', $groupId)
            ->order('m.user_id ASC');

        $members = [];
        foreach ($connection->fetchAll($select) as $row) {
            $members[] = [
                'value' => (string)$row['user_id'],
                'display' => (string)($row['username'] ?? ''),
            ];
        }

        return $members;
    }

    /**
     * User ids belonging to a group, ascending.
     *
     * @param int $groupId
     * @return int[]
     */
    public function getUserIds(int $groupId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::MEMBER_TABLE), ['user_id'])
            ->where('group_id = ?', $groupId)
            ->order('user_id ASC');

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Add users to a group; existing rows are left untouched (idempotent).
     *
     * @param int $groupId
     * @param int[] $userIds
     */
    public function addMembers(int $groupId, array $userIds): void
    {
        $userIds = $this->normalizeIds($userIds);
        if ($userIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $rows = array_map(
            static fn (int $userId): array => ['group_id' => $groupId, 'user_id' => $userId],
            $userIds
        );
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::MEMBER_TABLE),
            $rows,
            ['group_id']
        );
    }

    /**
     * Remove users from a group; a no-op for non-members.
     *
     * @param int $groupId
     * @param int[] $userIds
     */
    public function removeMembers(int $groupId, array $userIds): void
    {
        $userIds = $this->normalizeIds($userIds);
        if ($userIds === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName(self::MEMBER_TABLE),
            ['group_id = ?' => $groupId, 'user_id IN (?)' => $userIds]
        );
    }

    /**
     * Replace a group's whole membership with the given users.
     *
     * @param int $groupId
     * @param int[] $userIds
     */
    public function setMembers(int $groupId, array $userIds): void
    {
        $this->removeAllForGroup($groupId);
        $this->addMembers($groupId, $userIds);
    }

    /**
     * Drop every membership row of a group.
     *
     * @param int $groupId
     */
    public function removeAllForGroup(int $groupId): void
    {
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName(self::MEMBER_TABLE),
            ['group_id = ?' => $groupId]
        );
    }

    /**
     * Of the given ids, those with no matching `admin_user` row.
     *
     * The member join table has an FK to `admin_user`; callers validate against
     * this before writing so an unknown-but-well-formed id fails as a SCIM 400
     * rather than an opaque FK-violation 500.
     *
     * @param int[] $userIds
     * @return int[] the ids that do not exist, de-duplicated
     */
    public function findMissingUserIds(array $userIds): array
    {
        $userIds = $this->normalizeIds($userIds);
        if ($userIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::USER_TABLE), ['user_id'])
            ->where('user_id IN (?)', $userIds);
        $existing = array_map('intval', $connection->fetchCol($select));

        return array_values(array_diff($userIds, $existing));
    }

    /**
     * Display names of every group an admin user belongs to.
     *
     * Drives role resolution: an admin's ACL role derives from all its groups.
     *
     * @param int $userId
     * @return string[]
     */
    public function getGroupDisplayNamesForUser(int $userId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['m' => $this->resource->getTableName(self::MEMBER_TABLE)], [])
            ->join(
                ['g' => $this->resource->getTableName(self::GROUP_TABLE)],
                'g.group_id = m.group_id',
                ['display_name']
            )
            ->where('m.user_id = ?', $userId)
            ->order('g.group_id ASC');

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * De-duplicated list of positive integer ids.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function normalizeIds(array $userIds): array
    {
        $normalized = [];
        foreach ($userIds as $userId) {
            $id = (int)$userId;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }
}
