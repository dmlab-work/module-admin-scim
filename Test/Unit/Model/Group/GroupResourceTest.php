<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Model\Group\GroupResource;
use DmLab\AdminScim\Model\Group\ScimGroup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupResourceTest extends TestCase
{
    /** @var Mysql&MockObject */
    private $connection;

    /** @var GroupResource */
    private GroupResource $resource;

    protected function setUp(): void
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        // Concrete adapter: lastInsertId is not on AdapterInterface.
        $this->connection = $this->createMock(Mysql::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteIdentifier')->willReturnArgument(0);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->resource = new GroupResource($resourceConnection);
    }

    public function testGetByIdMapsRow(): void
    {
        $this->connection->expects(self::once())->method('fetchRow')->willReturn([
            'group_id' => '42',
            'display_name' => 'Admins',
            'external_id' => 'grp-1',
        ]);

        $group = $this->resource->getById(42);

        self::assertInstanceOf(ScimGroup::class, $group);
        self::assertSame(42, $group->groupId);
        self::assertSame('Admins', $group->displayName);
        self::assertSame('grp-1', $group->externalId);
    }

    public function testGetByIdReturnsNullWhenAbsent(): void
    {
        $this->connection->expects(self::once())->method('fetchRow')->willReturn(false);

        self::assertNull($this->resource->getById(99));
    }

    public function testSaveInsertsWhenIdNull(): void
    {
        $group = new ScimGroup(null, 'Admins', 'grp-1');
        $captured = null;
        $this->connection->expects(self::once())->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });
        $this->connection->method('lastInsertId')->willReturn('10');
        $this->connection->expects(self::never())->method('update');

        $this->resource->save($group);

        self::assertSame(10, $group->groupId);
        self::assertSame(['display_name' => 'Admins', 'external_id' => 'grp-1'], $captured);
    }

    public function testSaveUpdatesWhenIdPresent(): void
    {
        $group = new ScimGroup(10, 'Renamed', null);
        $this->connection->expects(self::never())->method('insert');
        $this->connection->expects(self::once())->method('update')
            ->with('dmlab_scim_group', ['display_name' => 'Renamed', 'external_id' => null], self::anything());

        $this->resource->save($group);
    }

    public function testFindConflictIdReturnsConflictingId(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')->willReturn('7');

        self::assertSame(7, $this->resource->findConflictId('display_name', 'Admins'));
    }

    public function testFindConflictIdReturnsNullWhenFree(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')->willReturn(false);

        self::assertNull($this->resource->findConflictId('external_id', 'grp-1', 5));
    }

    public function testCount(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')->willReturn('3');

        self::assertSame(3, $this->resource->count(['display_name', 'Admins']));
    }

    public function testFetchPageMapsRows(): void
    {
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([
            ['group_id' => '1', 'display_name' => 'A', 'external_id' => null],
            ['group_id' => '2', 'display_name' => 'B', 'external_id' => ''],
        ]);

        $groups = $this->resource->fetchPage(null, 0, 10);

        self::assertCount(2, $groups);
        self::assertSame(1, $groups[0]->groupId);
        self::assertNull($groups[0]->externalId);
        self::assertNull($groups[1]->externalId);
    }
}
