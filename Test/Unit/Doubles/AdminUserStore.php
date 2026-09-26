<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Doubles;

use Magento\Framework\Math\Random;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\UserFactory;

/**
 * In-memory `admin_user` backing store for the acceptance lifecycle tests.
 *
 * Holds the persisted rows keyed by `user_id` plus the stateful test doubles
 * ({@see UserFactory}, {@see UserResource}, {@see UserCollectionFactory}) that
 * read and mutate them, so the real provisioner/repository/patcher/mapper stack
 * runs end-to-end without a database. Built by {@see InMemoryScimTrait}.
 */
class AdminUserStore
{
    /** @var array<int,array<string,mixed>> persisted rows, keyed by user_id */
    public array $rows = [];

    /** @var int last assigned auto-increment id */
    public int $autoId = 0;

    /** @var UserFactory */
    public UserFactory $userFactory;

    /** @var UserResource */
    public UserResource $userResource;

    /** @var UserCollectionFactory */
    public UserCollectionFactory $collectionFactory;

    /** @var Random */
    public Random $random;
}
