<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\User\LastAdminGuard;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LastAdminGuardTest extends TestCase
{
    /** @var array<int,array{0:string,1:mixed}> captured Select::where() calls */
    private array $wheres = [];

    /**
     * A guard whose count query answers the given value.
     *
     * @param int $count
     */
    private function guard(int $count): LastAdminGuard
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select): Select {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn((string)$count);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new LastAdminGuard($resource);
    }

    public function testCountsActiveAdminsWithARole(): void
    {
        self::assertSame(3, $this->guard(3)->countUsable());
        self::assertSame([
            ['r.user_type = ?', '2'],
            ['r.parent_id > ?', 0],
            ['u.is_active = ?', 1],
        ], $this->wheres);
    }

    public function testCountCanExcludeOneAdmin(): void
    {
        $this->guard(1)->countUsable(42);

        self::assertContains(['u.user_id != ?', 42], $this->wheres);
    }

    public function testRejectsAWriteThatRemovedTheLastUsableAdmin(): void
    {
        try {
            $this->guard(0)->assertUsableAdminRemains(1);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('mutability', $e->getScimType());
        }
    }

    #[DataProvider('allowedCounts')]
    public function testAllowsWhenAnAdminRemainsOrNoneExistedBefore(int $before, int $after): void
    {
        $this->guard($after)->assertUsableAdminRemains($before);
        $this->addToAssertionCount(1);
    }

    public static function allowedCounts(): array
    {
        return [
            'one remains' => [2, 1],
            'none before' => [0, 0],
        ];
    }
}
