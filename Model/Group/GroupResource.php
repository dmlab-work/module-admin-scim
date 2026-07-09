<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Group;

use Magento\Framework\App\ResourceConnection;

/**
 * Persistence for the `magedevgroup_scim_group` table.
 *
 * Direct-connection CRUD over the group row (`display_name`, `external_id`),
 * translating rows to/from the {@see ScimGroup} DTO, plus the filtered/paginated
 * search backing `GET /Groups` and the uniqueness probe used before write.
 */
class GroupResource
{
    private const TABLE = 'magedevgroup_scim_group';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
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
     * @param ScimGroup $group
     */
    public function save(ScimGroup $group): void
    {
        $connection = $this->resource->getConnection();
        $data = [
            'display_name' => $group->displayName,
            'external_id' => $group->externalId,
        ];

        if ($group->groupId === null) {
            $connection->insert($this->table(), $data);
            $group->groupId = (int)$connection->lastInsertId($this->table());

            return;
        }

        $connection->update($this->table(), $data, ['group_id = ?' => $group->groupId]);
    }

    /**
     * The id of another group holding `$value` in `$field`, or null when free.
     *
     * @param string $field group column (`display_name` or `external_id`)
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
     * Count groups matching an optional `[column, value]` equality filter.
     *
     * @param array{0:string,1:string}|null $filter
     */
    public function count(?array $filter): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->table(), ['count' => 'COUNT(*)']);
        if ($filter !== null) {
            $select->where($connection->quoteIdentifier($filter[0]) . ' = ?', $filter[1]);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * A page of groups matching an optional filter, ordered by id.
     *
     * @param array{0:string,1:string}|null $filter `[column, value]` equality, or null
     * @param int $offset zero-based first row
     * @param int $count page size (> 0)
     * @return ScimGroup[]
     */
    public function fetchPage(?array $filter, int $offset, int $count): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->order('group_id ASC')
            ->limit($count, $offset);
        if ($filter !== null) {
            $select->where($connection->quoteIdentifier($filter[0]) . ' = ?', $filter[1]);
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
            isset($row['external_id']) && $row['external_id'] !== '' ? (string)$row['external_id'] : null
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
