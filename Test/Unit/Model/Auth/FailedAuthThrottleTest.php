<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\ClientIp;
use DmLab\AdminScim\Model\Auth\FailedAuthThrottle;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;

class FailedAuthThrottleTest extends TestCase
{
    private const IP = '203.0.113.7';

    /** @var array<string,string> */
    private array $store = [];

    /** @var list<array{0:string,1:string,2:array,3:?int}> */
    private array $saves = [];

    private function throttle(string $ip = self::IP): FailedAuthThrottle
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->store[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function ($data, $key, $tags = [], $lifeTime = null): bool {
                $this->store[$key] = $data;
                $this->saves[] = [$data, $key, $tags, $lifeTime];
                return true;
            }
        );

        $clientIp = $this->createStub(ClientIp::class);
        $clientIp->method('get')->willReturn($ip);

        return new FailedAuthThrottle($cache, $clientIp);
    }

    private function key(string $ip = self::IP): string
    {
        return 'dmlab_scim_auth_fail_' . hash('sha256', $ip);
    }

    public function testFreshClientIsNotBlocked(): void
    {
        $this->expectNotToPerformAssertions();

        $this->throttle()->assertNotBlocked();
    }

    public function testBelowThresholdPasses(): void
    {
        $throttle = $this->throttle();
        for ($i = 1; $i < FailedAuthThrottle::THRESHOLD; $i++) {
            $throttle->registerFailure();
        }

        $throttle->assertNotBlocked();

        self::assertSame((string)(FailedAuthThrottle::THRESHOLD - 1), $this->store[$this->key()]);
    }

    public function testAtThresholdThrows429WithRetryAfter(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < FailedAuthThrottle::THRESHOLD; $i++) {
            $throttle->registerFailure();
        }

        try {
            $throttle->assertNotBlocked();
            self::fail('Expected ScimException was not thrown.');
        } catch (ScimException $e) {
            self::assertSame(429, $e->getHttpStatus());
            self::assertNull($e->getScimType());
            self::assertSame(900, $e->getRetryAfter());
        }
    }

    public function testCounterIsSavedWithWindowTtlUnderHashedIpKey(): void
    {
        $this->throttle()->registerFailure();

        self::assertSame([['1', $this->key(), [], 900]], $this->saves);
        self::assertStringNotContainsString(self::IP, $this->key());
    }

    public function testCountersAreIsolatedPerIp(): void
    {
        $blocked = $this->throttle();
        for ($i = 0; $i < FailedAuthThrottle::THRESHOLD; $i++) {
            $blocked->registerFailure();
        }

        $this->expectNotToPerformAssertions();
        $this->throttle('198.51.100.1')->assertNotBlocked();
    }

    public function testUnknownClientIpStillCounts(): void
    {
        $this->throttle('')->registerFailure();

        self::assertSame('1', $this->store[$this->key('')]);
    }
}
