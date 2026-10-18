<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Audit;

use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\Auth\ClientIp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditLoggerTest extends TestCase
{
    private function auditLogger(LoggerInterface $logger, string $ip, ?LoggerInterface $fallback = null): AuditLogger
    {
        $clientIp = $this->createStub(ClientIp::class);
        $clientIp->method('get')->willReturn($ip);

        return new AuditLogger($logger, $clientIp, $fallback ?? $this->createStub(LoggerInterface::class));
    }

    public function testWritesInfoWithIpFirstAndContextPassedThrough(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->willReturnCallback(
            static function (string $message, array $context): void {
                self::assertSame('user.delete', $message);
                self::assertSame(['ip' => '203.0.113.7', 'user_id' => 12, 'userName' => 'jdoe'], $context);
            }
        );

        $this->auditLogger($logger, '203.0.113.7')->log('user.delete', ['user_id' => 12, 'userName' => 'jdoe']);
    }

    public function testUnknownIpLogsEmptyString(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('auth.failure', ['ip' => '']);

        $this->auditLogger($logger, '')->log('auth.failure');
    }

    public function testContextCannotOverrideIp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('auth.failure', ['ip' => '203.0.113.7']);

        $this->auditLogger($logger, '203.0.113.7')->log('auth.failure', ['ip' => 'spoofed']);
    }

    public function testFailedWriteIsReportedToFallbackNotThrown(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willThrowException(new \UnexpectedValueException('not writable'));
        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::once())->method('critical')
            ->with('SCIM audit log write failed: not writable', ['event' => 'user.create']);

        $this->auditLogger($logger, '203.0.113.7', $fallback)->log('user.create', ['user_id' => 12]);
    }

    public function testFailedFallbackIsSwallowed(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willThrowException(new \UnexpectedValueException('not writable'));
        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::once())->method('critical')
            ->willThrowException(new \UnexpectedValueException('disk full'));

        $this->auditLogger($logger, '203.0.113.7', $fallback)->log('user.create', ['user_id' => 12]);
    }
}
