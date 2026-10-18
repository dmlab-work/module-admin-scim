<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\WriteLock;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;

class WriteLockTest extends TestCase
{
    public function testRunsWorkInsideLockAndReturnsItsResult(): void
    {
        $calls = [];
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->with('dmlab_admin_scim_write', 30)
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'lock';
                return true;
            });
        $lockManager->expects(self::once())->method('unlock')->with('dmlab_admin_scim_write')
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'unlock';
                return true;
            });

        $result = (new WriteLock($lockManager))->run(static function () use (&$calls): string {
            $calls[] = 'work';
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['lock', 'work', 'unlock'], $calls);
    }

    public function testReleasesLockWhenWorkThrows(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects(self::once())->method('unlock');

        $this->expectException(\RuntimeException::class);
        (new WriteLock($lockManager))->run(static function (): void {
            throw new \RuntimeException('boom');
        });
    }

    public function testLockTimeoutIs500AndSkipsWork(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $lockManager->expects(self::never())->method('unlock');
        $ran = false;

        try {
            (new WriteLock($lockManager))->run(static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(500, $e->getHttpStatus());
        }
        self::assertFalse($ran);
    }
}
