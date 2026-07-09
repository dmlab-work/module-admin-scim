<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\Group;

use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\Group\GroupFilterParser;
use MageDevGroup\AdminScim\Model\Group\GroupResource;
use MageDevGroup\AdminScim\Model\Group\ScimGroup;
use MageDevGroup\AdminScim\Model\Group\ScimGroupRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScimGroupRepositoryTest extends TestCase
{
    /** @var GroupResource&MockObject */
    private $groupResource;

    /** @var GroupFilterParser&\PHPUnit\Framework\MockObject\Stub */
    private $filterParser;

    /** @var ScimGroupRepository */
    private ScimGroupRepository $repository;

    protected function setUp(): void
    {
        $this->groupResource = $this->createMock(GroupResource::class);
        $this->filterParser = $this->createStub(GroupFilterParser::class);

        $this->repository = new ScimGroupRepository($this->groupResource, $this->filterParser);
    }

    public function testGetByIdReturnsGroup(): void
    {
        $group = new ScimGroup(42, 'Admins');
        $this->groupResource->expects(self::once())->method('getById')->with(42)->willReturn($group);

        self::assertSame($group, $this->repository->getById('42'));
    }

    public function testGetByIdThrows404WhenAbsent(): void
    {
        $this->groupResource->expects(self::once())->method('getById')->willReturn(null);

        try {
            $this->repository->getById('999');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testGetByIdThrows404ForNonNumericId(): void
    {
        // A non-numeric id never hits storage.
        $this->groupResource->expects(self::never())->method('getById');

        $this->expectException(ScimException::class);
        $this->repository->getById('abc');
    }

    public function testSearchWithoutFilterPaginates(): void
    {
        $group = new ScimGroup(1, 'G');
        $this->groupResource->expects(self::once())->method('count')->with(null)->willReturn(5);
        $this->groupResource->expects(self::once())->method('fetchPage')->with(null, 0, 2)->willReturn([$group]);

        $result = $this->repository->search(null, 1, 2);

        self::assertSame(5, $result['total']);
        self::assertSame([$group], $result['groups']);
    }

    public function testSearchAppliesParsedFilter(): void
    {
        $this->filterParser->method('parse')->willReturn(['display_name', 'Admins']);
        $this->groupResource->expects(self::once())->method('count')
            ->with(['display_name', 'Admins'])->willReturn(1);
        $this->groupResource->expects(self::once())->method('fetchPage')
            ->with(['display_name', 'Admins'], 0, 10)->willReturn([]);

        $this->repository->search('displayName eq "Admins"', 1, 10);
    }

    public function testSearchTranslatesStartIndexToOffset(): void
    {
        $this->groupResource->method('count')->willReturn(20);
        $this->groupResource->expects(self::once())->method('fetchPage')->with(null, 4, 3)->willReturn([]);

        $this->repository->search(null, 5, 3);
    }

    public function testSearchWithZeroCountLoadsNoGroups(): void
    {
        $this->groupResource->method('count')->willReturn(8);
        $this->groupResource->expects(self::never())->method('fetchPage');

        $result = $this->repository->search(null, 1, 0);

        self::assertSame(8, $result['total']);
        self::assertSame([], $result['groups']);
    }

    public function testSearchBeyondLastPageReturnsNoGroups(): void
    {
        $this->groupResource->method('count')->willReturn(3);
        $this->groupResource->expects(self::never())->method('fetchPage');

        $result = $this->repository->search(null, 10, 5);

        self::assertSame(3, $result['total']);
        self::assertSame([], $result['groups']);
    }
}
