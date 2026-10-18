<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use Magento\Framework\App\CacheInterface;

/**
 * Per-client-IP throttle for failed SCIM authentications: after THRESHOLD
 * failures, each within WINDOW of the previous one, the IP gets 429 until the
 * counter expires. The read-increment-write counter is best-effort under concurrency.
 */
class FailedAuthThrottle
{
    /** Failures per window that block the client. */
    public const THRESHOLD = 10;

    /** Counter TTL (refreshed on each failure) and Retry-After, in seconds. */
    public const WINDOW = 900;

    private const KEY_PREFIX = 'dmlab_scim_auth_fail_';

    /**
     * @param CacheInterface $cache
     * @param ClientIp $clientIp
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ClientIp $clientIp
    ) {
    }

    /**
     * Reject the request when the client IP has reached the failure threshold.
     *
     * @throws ScimException 429 with Retry-After.
     */
    public function assertNotBlocked(): void
    {
        if ($this->count() >= self::THRESHOLD) {
            throw ScimException::tooManyRequests(self::WINDOW);
        }
    }

    /**
     * Count one failed authentication for the client IP.
     */
    public function registerFailure(): void
    {
        $this->cache->save((string)($this->count() + 1), $this->key(), [], self::WINDOW);
    }

    /**
     * Current failure count for the client IP.
     */
    private function count(): int
    {
        $value = $this->cache->load($this->key());

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Cache key for the client IP (hashed so raw addresses never land in cache ids).
     */
    private function key(): string
    {
        return self::KEY_PREFIX . hash('sha256', $this->clientIp->get());
    }
}
