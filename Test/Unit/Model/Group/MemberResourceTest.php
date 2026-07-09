<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\Group;

use MageDevGroup\AdminScim\Model\Group\MemberResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MemberResourceTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var MemberResource */
    private MemberResource $resource;

    protected function setUp(): void
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'joinLeft', 'join', 'where', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->resource = new MemberResource($resourceConnection);
    }

    public function testGetMembersMapsRows(): void
    {
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([
            ['user_id' => '5', 'username' => 'jane.doe'],
            ['user_id' => '7', 'username' => null],
        ]);

        self::assertSame([
            ['value' => '5', 'display' => 'jane.doe'],
            ['value' => '7', 'display' => ''],
        ], $this->resource->getMembers(1));
    }

    public function testGetUserIds(): void
    {
        $this->connection->expects(self::once())->method('fetchCol')->willReturn(['5', '7']);

        self::assertSame([5, 7], $this->resource->getUserIds(1));
    }

    public function testGetGroupDisplayNamesForUser(): void
    {
        $this->connection->expects(self::once())->method('fetchCol')->willReturn(['Admins', 'Editors']);

        self::assertSame(['Admins', 'Editors'], $this->resource->getGroupDisplayNamesForUser(5));
    }

    public function testFindMissingUserIdsReturnsIdsWithNoAdminRow(): void
    {
        // admin_user has only id 5; 7 is unknown.
        $this->connection->expects(self::once())->method('fetchCol')->willReturn(['5']);

        self::assertSame([7], $this->resource->findMissingUserIds([5, 7]));
    }

    public function testFindMissingUserIdsSkipsQueryForEmptyInput(): void
    {
        $this->connection->expects(self::never())->method('fetchCol');

        self::assertSame([], $this->resource->findMissingUserIds([0, -1]));
    }

    public function testAddMembersIsNoOpForEmptyList(): void
    {
        $this->connection->expects(self::never())->method('insertOnDuplicate');

        $this->resource->addMembers(1, []);
        // Non-positive ids are filtered out too.
        $this->resource->addMembers(1, [0, -3]);
    }

    public function testAddMembersInsertsNormalizedRows(): void
    {
        $captured = null;
        $this->connection->expects(self::once())->method('insertOnDuplicate')
            ->willReturnCallback(function ($table, $rows) use (&$captured): int {
                $captured = $rows;
                return 1;
            });

        // Duplicate ids collapse.
        $this->resource->addMembers(2, [5, 5, 7]);

        self::assertSame([
            ['group_id' => 2, 'user_id' => 5],
            ['group_id' => 2, 'user_id' => 7],
        ], $captured);
    }

    public function testRemoveMembersIsNoOpForEmptyList(): void
    {
        $this->connection->expects(self::never())->method('delete');

        $this->resource->removeMembers(1, []);
    }

    public function testRemoveMembersDeletes(): void
    {
        $this->connection->expects(self::once())->method('delete')
            ->with('magedevgroup_scim_group_member', self::anything());

        $this->resource->removeMembers(1, [5]);
    }

    public function testSetMembersClearsThenAdds(): void
    {
        $this->connection->expects(self::once())->method('delete');
        $this->connection->expects(self::once())->method('insertOnDuplicate');

        $this->resource->setMembers(1, [5]);
    }
}
