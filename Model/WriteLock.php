<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model;

use DmLab\AdminScim\Exception\ScimException;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Serializes SCIM writes so membership reads and role re-derivation never see a stale snapshot.
 *
 * Callers must acquire it before their first read: a concurrent write could otherwise
 * change membership after it was read and leave an admin holding a revoked role.
 */
class WriteLock
{
    private const NAME = 'dmlab_admin_scim_write';
    private const TIMEOUT = 30;

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Run the work while holding the SCIM write lock.
     *
     * @template T
     * @param callable():T $work
     * @return T
     * @throws ScimException 500 when the lock cannot be acquired in time
     */
    public function run(callable $work): mixed
    {
        if (!$this->lockManager->lock(self::NAME, self::TIMEOUT)) {
            throw ScimException::internal('Could not acquire the SCIM write lock.');
        }
        try {
            return $work();
        } finally {
            $this->lockManager->unlock(self::NAME);
        }
    }
}
