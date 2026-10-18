<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Auth;

use DmLab\AdminScim\Model\Auth\ClientIp;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase
{
    private const PROXY = '10.0.0.2';

    /**
     * @param array<string,string> $server
     * @param string[] $trustedProxies
     */
    private function clientIp(array $server, array $trustedProxies = []): ClientIp
    {
        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturnCallback(
            static fn (string $name, $default = null) => $server[$name] ?? $default
        );

        return new ClientIp($request, $trustedProxies);
    }

    public function testUsesRemoteAddrAndIgnoresForwardedForWithoutTrustedProxy(): void
    {
        $ip = $this->clientIp(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']);

        self::assertSame('203.0.113.7', $ip->get());
    }

    public function testUnknownRemoteAddrIsEmpty(): void
    {
        self::assertSame('', $this->clientIp([])->get());
    }

    public function testTrustedProxyYieldsRightMostUntrustedHop(): void
    {
        $ip = $this->clientIp(
            ['REMOTE_ADDR' => self::PROXY, 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.7, 10.0.0.3'],
            [self::PROXY, '10.0.0.3']
        );

        self::assertSame('203.0.113.7', $ip->get());
    }

    public function testInvalidHopFallsBackToProxy(): void
    {
        $ip = $this->clientIp(['REMOTE_ADDR' => self::PROXY, 'HTTP_X_FORWARDED_FOR' => 'garbage'], [self::PROXY]);

        self::assertSame(self::PROXY, $ip->get());
    }

    public function testTrustedProxyWithoutForwardedForUsesRemoteAddr(): void
    {
        self::assertSame(self::PROXY, $this->clientIp(['REMOTE_ADDR' => self::PROXY], [self::PROXY])->get());
    }
}
