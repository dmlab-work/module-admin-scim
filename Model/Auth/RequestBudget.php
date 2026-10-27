<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Per-token request budget: at most `request_limit` authenticated requests per
 * fixed one-minute window, then `429` with `Retry-After` until the window ends.
 * The read-increment-write counter is best-effort under concurrency.
 */
class RequestBudget
{
    /** Window length and counter TTL, in seconds. */
    public const WINDOW = 60;

    private const KEY_PREFIX = 'dmlab_scim_budget_';

    /**
     * @param CacheInterface $cache
     * @param Config $config
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Count one request for the token, rejecting it when the window's budget is spent.
     *
     * @param string $token the authenticated bearer token (only its hash is stored)
     * @throws ScimException 429 with Retry-After
     */
    public function consume(string $token): void
    {
        $limit = $this->config->getRequestLimit();
        if ($limit <= 0) {
            return;
        }

        $now = $this->dateTime->gmtTimestamp();
        $key = self::KEY_PREFIX . hash('sha256', $token) . '_' . intdiv($now, self::WINDOW);
        $value = $this->cache->load($key);
        $count = is_numeric($value) ? (int)$value : 0;
        if ($count >= $limit) {
            throw ScimException::tooManyRequests(self::WINDOW - $now % self::WINDOW);
        }
        $this->cache->save((string)($count + 1), $key, [], self::WINDOW);
    }
}
