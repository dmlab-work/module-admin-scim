<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Read side of SCIM User provisioning: fetch a single admin user by its SCIM `id`
 * (the `admin_user.user_id`) and search the collection with the supported filter
 * subset + pagination. The write side (create/uniqueness) lives in
 * {@see UserProvisioner}; this class only loads and never mutates.
 */
class ScimUserRepository
{
    /**
     * @param UserFactory $userFactory
     * @param UserResource $userResource
     * @param UserCollectionFactory $userCollectionFactory
     * @param FilterParser $filterParser
     */
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly FilterParser $filterParser
    ) {
    }

    /**
     * Load an admin user by its SCIM `id`.
     *
     * @param string $id the SCIM resource id (admin_user.user_id)
     * @return User the loaded admin user
     * @throws ScimException 404 when no admin user carries that id
     */
    public function getById(string $id): User
    {
        $user = $this->userFactory->create();
        if (ctype_digit($id)) {
            $this->userResource->load($user, (int)$id);
        }

        if (!$user->getId()) {
            throw ScimException::notFound(sprintf('User "%s" not found.', $id));
        }

        return $user;
    }

    /**
     * Search admin users, optionally filtered, returning a single page.
     *
     * `$startIndex` is the 1-based index of the first result and `$count` the page
     * size; the returned `total` is the unpaged match count (SCIM `totalResults`).
     * A `$count` of 0 loads no rows but still reports `total`.
     *
     * @param string|null $filter raw SCIM `filter` expression, or null for all users
     * @param int $startIndex 1-based first-result index (>= 1)
     * @param int $count page size (>= 0)
     * @return array{users:User[],total:int}
     * @throws ScimException 400 `invalidFilter` when the filter is unsupported
     */
    public function search(?string $filter, int $startIndex, int $count): array
    {
        $collection = $this->userCollectionFactory->create();

        if ($filter !== null && $filter !== '') {
            [$field, $value] = $this->filterParser->parse($filter);
            $collection->addFieldToFilter($field, $value);
        }

        $total = (int)$collection->getSize();

        $users = [];
        if ($count > 0 && $startIndex <= $total) {
            // Deterministic order is required: each page is a separate query, so
            // without a stable sort LIMIT/OFFSET paging can skip or duplicate rows.
            $collection->getSelect()->order('main_table.user_id ASC')->limit($count, $startIndex - 1);
            $users = array_values($collection->getItems());
        }

        return ['users' => $users, 'total' => $total];
    }
}
