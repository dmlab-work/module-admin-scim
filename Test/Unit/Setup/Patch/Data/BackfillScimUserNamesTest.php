<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Setup\Patch\Data;

use DmLab\AdminScim\Setup\Patch\Data\BackfillScimUserNames;
use DmLab\AdminScim\Setup\Patch\Data\MarkExistingScimAdmins;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class BackfillScimUserNamesTest extends TestCase
{
    public function testCopiesLoginIntoUserNameOfManagedAdmins(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('update')->with(
            'prefixed_admin_user',
            self::callback(static fn (array $bind): bool => array_keys($bind) === ['dmlab_scim_user_name']
                && $bind['dmlab_scim_user_name'] instanceof Expression
                && (string)$bind['dmlab_scim_user_name'] === 'username'),
            ['dmlab_scim_managed = ?' => 1, 'dmlab_scim_user_name IS NULL']
        )->willReturn(2);

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(fn (string $table): string => 'prefixed_' . $table);

        $patch = new BackfillScimUserNames($setup);

        self::assertSame($patch, $patch->apply());
        self::assertSame([MarkExistingScimAdmins::class], BackfillScimUserNames::getDependencies());
        self::assertSame([], $patch->getAliases());
    }
}
