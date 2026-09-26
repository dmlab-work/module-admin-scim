<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\User\FilterParser;
use DmLab\AdminScim\Model\User\ScimUserRepository;
use Magento\Framework\DB\Select;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\Collection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;

class ScimUserRepositoryTest extends TestCase
{
    /** @var UserFactory&\PHPUnit\Framework\MockObject\Stub */
    private $userFactory;

    /** @var UserResource&\PHPUnit\Framework\MockObject\Stub */
    private $userResource;

    /** @var UserCollectionFactory&\PHPUnit\Framework\MockObject\Stub */
    private $collectionFactory;

    /** @var FilterParser&\PHPUnit\Framework\MockObject\Stub */
    private $filterParser;

    /** @var ScimUserRepository */
    private ScimUserRepository $repository;

    protected function setUp(): void
    {
        $this->userFactory = $this->createStub(UserFactory::class);
        $this->userResource = $this->createStub(UserResource::class);
        $this->collectionFactory = $this->createStub(UserCollectionFactory::class);
        $this->filterParser = $this->createStub(FilterParser::class);

        $this->repository = new ScimUserRepository(
            $this->userFactory,
            $this->userResource,
            $this->collectionFactory,
            $this->filterParser
        );
    }

    /**
     * A collection stub with the given size, capturing filters and limit args.
     *
     * @param int $size value returned by getSize()
     * @param User[] $items value returned by getItems()
     * @param array<int,array{0:string,1:mixed}> $filters captured addFieldToFilter calls (by ref)
     * @param array<int,int> $limit captured getSelect()->limit args [count, offset] (by ref)
     * @return Collection&\PHPUnit\Framework\MockObject\Stub
     */
    private function collection(int $size, array $items, array &$filters, array &$limit)
    {
        $select = $this->createStub(Select::class);
        $select->method('order')->willReturnCallback(fn ($spec) => $select);
        $select->method('limit')->willReturnCallback(
            function ($count, $offset) use (&$limit, $select) {
                $limit = [$count, $offset];
                return $select;
            }
        );

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $value) use (&$filters, $collection) {
                $filters[] = [$field, $value];
                return $collection;
            }
        );
        $collection->method('getSize')->willReturn($size);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }

    public function testGetByIdReturnsLoadedUser(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);

        $loaded = null;
        $this->userFactory->method('create')->willReturn($user);
        $this->userResource->method('load')->willReturnCallback(
            function ($model, $value) use (&$loaded) {
                $loaded = [$model, $value];
            }
        );

        self::assertSame($user, $this->repository->getById('42'));
        self::assertSame([$user, 42], $loaded);
    }

    public function testGetByIdThrows404ForNonDigitId(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);
        $this->userFactory->method('create')->willReturn($user);

        try {
            $this->repository->getById('42abc');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testGetByIdThrows404WhenAbsent(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);
        $this->userFactory->method('create')->willReturn($user);

        try {
            $this->repository->getById('999');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testSearchWithoutFilterPaginates(): void
    {
        $filters = [];
        $limit = [];
        $userA = $this->createStub(User::class);
        $collection = $this->collection(5, [$userA], $filters, $limit);
        $this->collectionFactory->method('create')->willReturn($collection);

        $result = $this->repository->search(null, 1, 2);

        self::assertSame([], $filters);
        self::assertSame([2, 0], $limit);
        self::assertSame(5, $result['total']);
        self::assertSame([$userA], $result['users']);
    }

    public function testSearchAppliesParsedFilter(): void
    {
        $filters = [];
        $limit = [];
        $collection = $this->collection(1, [$this->createStub(User::class)], $filters, $limit);
        $this->collectionFactory->method('create')->willReturn($collection);
        $this->filterParser->method('parse')->willReturn(['username', 'jane']);

        $this->repository->search('userName eq "jane"', 1, 10);

        self::assertSame([['username', 'jane']], $filters);
    }

    public function testSearchTranslatesStartIndexToOffset(): void
    {
        $filters = [];
        $limit = [];
        $collection = $this->collection(20, [$this->createStub(User::class)], $filters, $limit);
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->repository->search(null, 5, 3);

        self::assertSame([3, 4], $limit);
    }

    public function testSearchWithZeroCountLoadsNoUsersButReportsTotal(): void
    {
        $filters = [];
        $limit = [];
        $collection = $this->collection(8, [$this->createStub(User::class)], $filters, $limit);
        $this->collectionFactory->method('create')->willReturn($collection);

        $result = $this->repository->search(null, 1, 0);

        self::assertSame([], $limit);
        self::assertSame(8, $result['total']);
        self::assertSame([], $result['users']);
    }

    public function testSearchBeyondLastPageReturnsNoUsers(): void
    {
        $filters = [];
        $limit = [];
        $collection = $this->collection(3, [$this->createStub(User::class)], $filters, $limit);
        $this->collectionFactory->method('create')->willReturn($collection);

        $result = $this->repository->search(null, 10, 5);

        self::assertSame([], $limit);
        self::assertSame(3, $result['total']);
        self::assertSame([], $result['users']);
    }
}
