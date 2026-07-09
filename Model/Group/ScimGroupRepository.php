<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Group;

use MageDevGroup\AdminScim\Exception\ScimException;

/**
 * Read side of SCIM Group provisioning: fetch a single group by its SCIM `id`
 * (the `magedevgroup_scim_group.group_id`) and search the collection with the
 * supported filter subset + pagination. The write side (create/patch/uniqueness,
 * membership, role sync) lives in {@see GroupProvisioner}; this class only loads.
 */
class ScimGroupRepository
{
    /**
     * @param GroupResource $groupResource
     * @param GroupFilterParser $filterParser
     */
    public function __construct(
        private readonly GroupResource $groupResource,
        private readonly GroupFilterParser $filterParser
    ) {
    }

    /**
     * Load a group by its SCIM `id`.
     *
     * @param string $id the SCIM resource id (magedevgroup_scim_group.group_id)
     * @return ScimGroup the loaded group
     * @throws ScimException 404 when no group carries that id
     */
    public function getById(string $id): ScimGroup
    {
        $group = ctype_digit($id) ? $this->groupResource->getById((int)$id) : null;
        if ($group === null) {
            throw ScimException::notFound(sprintf('Group "%s" not found.', $id));
        }

        return $group;
    }

    /**
     * Search groups, optionally filtered, returning a single page.
     *
     * `$startIndex` is the 1-based index of the first result and `$count` the page
     * size; the returned `total` is the unpaged match count (SCIM `totalResults`).
     * A `$count` of 0 loads no rows but still reports `total`.
     *
     * @param string|null $filter raw SCIM `filter` expression, or null for all groups
     * @param int $startIndex 1-based first-result index (>= 1)
     * @param int $count page size (>= 0)
     * @return array{groups:ScimGroup[],total:int}
     * @throws ScimException 400 `invalidFilter` when the filter is unsupported
     */
    public function search(?string $filter, int $startIndex, int $count): array
    {
        $parsed = $filter !== null && $filter !== '' ? $this->filterParser->parse($filter) : null;

        $total = $this->groupResource->count($parsed);

        $groups = [];
        if ($count > 0 && $startIndex <= $total) {
            $groups = $this->groupResource->fetchPage($parsed, $startIndex - 1, $count);
        }

        return ['groups' => $groups, 'total' => $total];
    }
}
