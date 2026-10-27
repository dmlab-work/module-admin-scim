<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Persistence for the `dmlab_scim_group` table.
 *
 * Direct-connection CRUD over the group row (`display_name`, `external_id`),
 * translating rows to/from the {@see ScimGroup} DTO, plus the filtered/paginated
 * search backing `GET /Groups` and the uniqueness probe used before write.
 */
class GroupResource
{
    private const TABLE = 'dmlab_scim_group';

    /**
     * @param ResourceConnection $resource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Load a group by id, or null when absent.
     *
     * @param int $groupId
     */
    public function getById(int $groupId): ?ScimGroup
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->where('group_id = ?', $groupId);

        $row = $connection->fetchRow($select);

        return is_array($row) && $row !== [] ? $this->toGroup($row) : null;
    }

    /**
     * Persist a group. Inserts when `groupId` is null (populating it), else updates.
     *
     * Every save bumps `updated_at`, so a membership-only change moves `meta.lastModified`.
     *
     * @param ScimGroup $group
     */
    public function save(ScimGroup $group): void
    {
        $connection = $this->resource->getConnection();
        $group->updatedAt = $this->dateTime->gmtDate();
        $data = [
            'display_name' => $group->displayName,
            'external_id' => $group->externalId,
            'updated_at' => $group->updatedAt,
        ];

        if ($group->groupId === null) {
            $group->createdAt = $group->updatedAt;
            $connection->insert($this->table(), $data + ['created_at' => $group->createdAt]);
            $group->groupId = (int)$connection->lastInsertId($this->table());

            return;
        }

        $connection->update($this->table(), $data, ['group_id = ?' => $group->groupId]);
    }

    /**
     * Delete a group row; a no-op when absent.
     *
     * @param int $groupId
     */
    public function delete(int $groupId): void
    {
        $this->resource->getConnection()->delete($this->table(), ['group_id = ?' => $groupId]);
    }

    /**
     * The id of another group holding `$value` in `$field`, or null when free.
     *
     * @param string $field group column, e.g. `external_id`
     * @param string $value
     * @param int|null $excludeId group id to ignore (the row being updated)
     */
    public function findConflictId(string $field, string $value, ?int $excludeId = null): ?int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['group_id'])
            ->where($connection->quoteIdentifier($field) . ' = ?', $value)
            ->limit(1);
        if ($excludeId !== null) {
            $select->where('group_id != ?', $excludeId);
        }

        $id = $connection->fetchOne($select);

        return $id === false || $id === null ? null : (int)$id;
    }

    /**
     * Count groups matching an optional condition.
     *
     * @param string|null $where SQL condition over alias `g` ({@see GroupFilterParser}), or null for all
     */
    public function count(?string $where): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from([GroupFilterParser::TABLE_ALIAS => $this->table()], ['count' => 'COUNT(*)']);
        if ($where !== null) {
            $select->where($where);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * A page of groups matching an optional condition, ordered by id.
     *
     * @param string|null $where SQL condition over alias `g` ({@see GroupFilterParser}), or null for all
     * @param int $offset zero-based first row
     * @param int $count page size (> 0)
     * @return ScimGroup[]
     */
    public function fetchPage(?string $where, int $offset, int $count): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from([GroupFilterParser::TABLE_ALIAS => $this->table()])
            ->order(GroupFilterParser::TABLE_ALIAS . '.group_id ASC')
            ->limit($count, $offset);
        if ($where !== null) {
            $select->where($where);
        }

        return array_map([$this, 'toGroup'], $connection->fetchAll($select));
    }

    /**
     * Map a table row to a {@see ScimGroup}.
     *
     * @param array<string,mixed> $row
     */
    private function toGroup(array $row): ScimGroup
    {
        return new ScimGroup(
            (int)$row['group_id'],
            (string)$row['display_name'],
            isset($row['external_id']) && $row['external_id'] !== '' ? (string)$row['external_id'] : null,
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        );
    }

    /**
     * Resolved (prefixed) table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
