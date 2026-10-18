<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Auth\FailedAuthThrottle;
use DmLab\AdminScim\Model\Config;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class BearerTokenAuthenticatorTest extends TestCase
{
    private function authenticator(
        ?string $configuredToken,
        bool $enabled = true,
        ?FailedAuthThrottle $throttle = null,
        ?AuditLogger $auditLogger = null
    ): BearerTokenAuthenticator {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getBearerToken')->willReturn($configuredToken);

        return new BearerTokenAuthenticator(
            $config,
            $throttle ?? $this->createStub(FailedAuthThrottle::class),
            $auditLogger ?? $this->createStub(AuditLogger::class)
        );
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
        $throttle = $this->createMock(FailedAuthThrottle::class);
        $throttle->expects(self::once())->method('assertNotBlocked');
        $throttle->expects(self::never())->method('registerFailure');
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('log');

        $this->authenticator('secret-token', true, $throttle, $auditLogger)
            ->authenticate($this->requestWithHeader('Bearer secret-token'));
    }

    public function testBlockedClientGets429BeforeTokenCompare(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::never())->method('isEnabled');
        $config->expects(self::never())->method('getBearerToken');

        $throttle = $this->createMock(FailedAuthThrottle::class);
        $throttle->method('assertNotBlocked')->willThrowException(ScimException::tooManyRequests(900));
        $throttle->expects(self::never())->method('registerFailure');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log')->with('auth.throttled');

        try {
            (new BearerTokenAuthenticator($config, $throttle, $auditLogger))
                ->authenticate($this->requestWithHeader('Bearer secret-token'));
            self::fail('Expected ScimException was not thrown.');
        } catch (ScimException $e) {
            self::assertSame(429, $e->getHttpStatus());
            self::assertSame(900, $e->getRetryAfter());
        }
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
        $this->assertUnauthorized('secret-token', 'Bearer secret-token', false);
    }

    private function assertUnauthorized(
        ?string $configuredToken,
        string|false $authHeader,
        bool $enabled = true
    ): void {
        $throttle = $this->createMock(FailedAuthThrottle::class);
        $throttle->expects(self::once())->method('assertNotBlocked');
        $throttle->expects(self::once())->method('registerFailure');

        // Exactly one auth.failure, never carrying the configured or presented token.
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log')->willReturnCallback(
            static function (string $event, array $context = []) use ($configuredToken, $authHeader): void {
                self::assertSame('auth.failure', $event);
                $logged = json_encode($context);
                foreach (array_filter([$configuredToken, 'wrong-token', 'secret-token']) as $token) {
                    self::assertStringNotContainsString($token, $logged);
                }
                if (is_string($authHeader) && trim($authHeader) !== '') {
                    self::assertStringNotContainsString(trim($authHeader), $logged);
                }
            }
        );

        try {
            $this->authenticator($configuredToken, $enabled, $throttle, $auditLogger)
                ->authenticate($this->requestWithHeader($authHeader));
            self::fail('Expected ScimException was not thrown.');
        } catch (ScimException $e) {
            self::assertSame(401, $e->getHttpStatus());
        }
    }
}
