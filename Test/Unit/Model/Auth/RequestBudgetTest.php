<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\RequestBudget;
use DmLab\AdminScim\Model\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class RequestBudgetTest extends TestCase
{
    /** @var array<string,string> */
    private array $cache = [];

    private function budget(int $limit, int $now): RequestBudget
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function ($value, $key): bool {
            $this->cache[$key] = $value;
            return true;
        });
        $config = $this->createStub(Config::class);
        $config->method('getRequestLimit')->willReturn($limit);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        return new RequestBudget($cache, $config, $dateTime);
    }

    public function testRequestsBeyondTheLimitAre429UntilTheWindowEnds(): void
    {
        $budget = $this->budget(2, 6000 + 45);
        $budget->consume('token');
        $budget->consume('token');

        try {
            $budget->consume('token');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(429, $e->getHttpStatus());
            self::assertSame(15, $e->getRetryAfter());
        }
        foreach (array_keys($this->cache) as $key) {
            self::assertStringNotContainsString('token', substr($key, strlen('dmlab_scim_budget_')));
        }
    }

    public function testNextWindowStartsAFreshBudget(): void
    {
        $this->budget(1, 6000)->consume('token');
        $this->budget(1, 6060)->consume('token');

        self::assertCount(2, $this->cache);
    }

    public function testZeroLimitDisablesTheBudget(): void
    {
        $budget = $this->budget(0, 6000);
        for ($i = 0; $i < 5; $i++) {
            $budget->consume('token');
        }

        self::assertSame([], $this->cache);
    }
}
