<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Config;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class BearerTokenAuthenticatorTest extends TestCase
{
    private function authenticator(?string $configuredToken, bool $enabled = true): BearerTokenAuthenticator
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getBearerToken')->willReturn($configuredToken);

        return new BearerTokenAuthenticator($config);
    }

    private function requestWithHeader(string|false $authHeader): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name) => strtolower($name) === 'authorization' ? $authHeader : false
        );

        return $request;
    }

    public function testValidTokenPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->authenticator('secret-token')
            ->authenticate($this->requestWithHeader('Bearer secret-token'));
    }

    public function testBearerSchemeIsCaseInsensitive(): void
    {
        $this->expectNotToPerformAssertions();

        $this->authenticator('secret-token')
            ->authenticate($this->requestWithHeader('bearer secret-token'));
    }

    public function testMissingHeaderIsUnauthorized(): void
    {
        $this->assertUnauthorized('secret-token', false);
    }

    public function testMalformedHeaderIsUnauthorized(): void
    {
        $this->assertUnauthorized('secret-token', 'Basic dXNlcjpwYXNz');
    }

    public function testEmptyBearerTokenIsUnauthorized(): void
    {
        $this->assertUnauthorized('secret-token', 'Bearer   ');
    }

    public function testWrongTokenIsUnauthorized(): void
    {
        $this->assertUnauthorized('secret-token', 'Bearer wrong-token');
    }

    public function testUnconfiguredTokenIsUnauthorized(): void
    {
        $this->assertUnauthorized(null, 'Bearer anything');
    }

    public function testDisabledEndpointIsUnauthorizedEvenWithValidToken(): void
    {
        try {
            $this->authenticator('secret-token', false)
                ->authenticate($this->requestWithHeader('Bearer secret-token'));
            self::fail('Expected ScimException was not thrown.');
        } catch (ScimException $e) {
            self::assertSame(401, $e->getHttpStatus());
        }
    }

    private function assertUnauthorized(?string $configuredToken, string|false $authHeader): void
    {
        try {
            $this->authenticator($configuredToken)
                ->authenticate($this->requestWithHeader($authHeader));
            self::fail('Expected ScimException was not thrown.');
        } catch (ScimException $e) {
            self::assertSame(401, $e->getHttpStatus());
        }
    }
}
