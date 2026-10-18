<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Setup\Patch\Data;

use DmLab\AdminScim\Setup\Patch\Data\MarkExistingScimAdmins;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class MarkExistingScimAdminsTest extends TestCase
{
    public function testFlagsAdminsCarryingAnExternalIdOrGroupMembership(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $id): string => '`' . $id . '`');
        $connection->expects(self::once())->method('update')->with(
            'prefixed_admin_user',
            ['dmlab_scim_managed' => 1],
            'dmlab_scim_external_id IS NOT NULL OR user_id IN (SELECT user_id FROM `prefixed_dmlab_scim_group_member`)'
        )->willReturn(3);

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(fn (string $table): string => 'prefixed_' . $table);

        $patch = new MarkExistingScimAdmins($setup);

        self::assertSame($patch, $patch->apply());
        self::assertSame([], MarkExistingScimAdmins::getDependencies());
        self::assertSame([], $patch->getAliases());
    }
}
