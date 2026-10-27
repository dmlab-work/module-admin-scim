<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Doubles;

use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use DmLab\AdminScim\Model\Filter\ExpressionParser;
use DmLab\AdminScim\Model\Filter\SqlBuilder;
use DmLab\AdminScim\Model\User\FilterParser;
use DmLab\AdminScim\Model\Response\ScimResponse;
use DmLab\AdminScim\Model\WriteLock;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Stateful test doubles that let the real SCIM stack run end-to-end without a
 * database: an in-memory `admin_user` store, a capturing {@see ScimResponse} and
 * request/URL helpers. Used by the acceptance lifecycle tests. Consumed by a
 * PHPUnit `TestCase` (relies on `createStub`).
 */
trait InMemoryScimTrait
{
    /** @var array<string,array{0:array<string,mixed>,1:array<string,array<string,mixed>>}> compiled filters by key */
    private array $inMemoryFilters = [];

    /**
     * A real user filter parser whose SQL step yields a key the in-memory collection evaluates.
     */
    private function createInMemoryFilterParser(): FilterParser
    {
        $builder = $this->createStub(SqlBuilder::class);
        $builder->method('build')->willReturnCallback(function (array $node, array $attributes): string {
            $key = 'in-memory-filter-' . count($this->inMemoryFilters);
            $this->inMemoryFilters[$key] = [$node, $attributes];

            return $key;
        });

        return new FilterParser(new ExpressionParser(), $builder);
    }

    /**
     * Evaluate a filter tree against a stored row (string ops honour `caseExact`).
     *
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $attributes
     * @param array<string,mixed> $row
     */
    private function rowMatchesFilter(array $node, array $attributes, array $row): bool
    {
        switch ($node['op']) {
            case 'and':
                return $this->rowMatchesFilter($node['left'], $attributes, $row)
                    && $this->rowMatchesFilter($node['right'], $attributes, $row);
            case 'or':
                return $this->rowMatchesFilter($node['left'], $attributes, $row)
                    || $this->rowMatchesFilter($node['right'], $attributes, $row);
            case 'not':
                return !$this->rowMatchesFilter($node['expr'], $attributes, $row);
        }
        $spec = $attributes[$node['attr']];
        $column = static fn (string $sql): string => preg_replace('/^main_table\./', '', $sql);
        $value = $row[$column($spec['column'])]
            ?? (isset($spec['fallback']) ? ($row[$column($spec['fallback'])] ?? null) : null);
        if ($node['op'] === 'pr') {
            return $value !== null && $value !== '';
        }
        if ($spec['type'] === 'boolean') {
            return ((bool)(int)$value === $node['value']) === ($node['op'] === 'eq');
        }
        $actual = (string)$value;
        $expected = (string)$node['value'];
        if (empty($spec['caseExact'])) {
            $actual = mb_strtolower($actual);
            $expected = mb_strtolower($expected);
        }

        return match ($node['op']) {
            'eq' => $value !== null && $actual === $expected,
            'ne' => $value === null || $actual !== $expected,
            'co' => $value !== null && str_contains($actual, $expected),
            'sw' => $value !== null && str_starts_with($actual, $expected),
            'ew' => $value !== null && str_ends_with($actual, $expected),
        };
    }
    /**
     * A write lock that is always granted.
     */
    private function createWriteLock(): WriteLock
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        return new WriteLock($lockManager);
    }

    /**
     * Build the in-memory `admin_user` store with its stateful doubles.
     */
    private function createAdminUserStore(): AdminUserStore
    {
        $store = new AdminUserStore();
        $store->userFactory = $this->createStub(UserFactory::class);
        $store->userResource = $this->createStub(UserResource::class);
        $store->collectionFactory = $this->createStub(UserCollectionFactory::class);
        $store->random = $this->createStub(Random::class);
        $store->random->method('getRandomString')->willReturn('Passw0rd');

        $store->userFactory->method('create')->willReturnCallback(fn (): User => $this->makeAdminUser());

        $store->userResource->method('save')->willReturnCallback(function (User $user) use ($store): UserResource {
            $id = $user->getId();
            if (!$id) {
                $id = ++$store->autoId;
                $user->setId($id);
            }
            $store->rows[(int)$id] = $user->getData();

            return $store->userResource;
        });
        $store->userResource->method('delete')->willReturnCallback(function (User $user) use ($store): bool {
            unset($store->rows[(int)$user->getId()]);

            return true;
        });
        $store->userResource->method('load')->willReturnCallback(
            function (User $user, $id) use ($store): UserResource {
                if (isset($store->rows[(int)$id])) {
                    $user->setData($store->rows[(int)$id]);
                }

                return $store->userResource;
            }
        );

        $store->collectionFactory->method('create')
            ->willReturnCallback(fn (): UserCollection => $this->makeUserCollection($store));

        return $store;
    }

    /**
     * A fake `admin_user` model backed by an in-memory data array.
     *
     * @param array<string,mixed> $data
     * @return User
     */
    private function makeAdminUser(array $data = []): User
    {
        $holder = new class {
            /** @var array<string,mixed> */
            public array $data = [];
        };
        $holder->data = $data;

        $user = $this->createStub(User::class);
        $user->method('getData')->willReturnCallback(
            fn ($key = null, $index = null) => $key === null || $key === ''
                ? $holder->data
                : ($holder->data[$key] ?? null)
        );
        $user->method('setData')->willReturnCallback(function ($key, $value = null) use ($holder, $user): User {
            if (is_array($key)) {
                $holder->data = $key;
            } else {
                $holder->data[$key] = $value;
            }

            return $user;
        });
        $user->method('addData')->willReturnCallback(function (array $arr) use ($holder, $user): User {
            foreach ($arr as $key => $value) {
                $holder->data[$key] = $value;
            }

            return $user;
        });
        $user->method('setId')->willReturnCallback(function ($id) use ($holder, $user): User {
            $holder->data['user_id'] = $id;

            return $user;
        });
        $user->method('getId')->willReturnCallback(fn () => $holder->data['user_id'] ?? null);
        $user->method('getRole')->willReturnCallback(function () use ($holder) {
            $roleId = isset($holder->data['role_id']) && (int)$holder->data['role_id'] > 0
                ? (string)$holder->data['role_id']
                : null;
            $role = $this->createStub(\Magento\Authorization\Model\Role::class);
            $role->method('getId')->willReturn($roleId);

            return $role;
        });

        return $user;
    }

    /**
     * A fake user collection that queries the store's current rows.
     *
     * @param AdminUserStore $store
     * @return UserCollection
     */
    private function makeUserCollection(AdminUserStore $store): UserCollection
    {
        $state = new class {
            /** @var array<int,array{0:string,1:mixed}> */
            public array $filters = [];
            /** @var string[] in-memory filter keys passed to Select::where() */
            public array $wheres = [];
            /** @var int|null */
            public ?int $limit = null;
            /** @var int */
            public int $offset = 0;
        };

        $match = function () use ($store, $state): array {
            $rows = array_values($store->rows);
            foreach ($state->filters as [$field, $condition]) {
                $rows = array_values(array_filter($rows, static function (array $row) use ($field, $condition): bool {
                    $value = $row[$field] ?? null;
                    if (is_array($condition)) {
                        return !isset($condition['neq']) || (string)$value !== (string)$condition['neq'];
                    }

                    return (string)$value === (string)$condition;
                }));
            }

            foreach ($state->wheres as $key) {
                [$node, $attributes] = $this->inMemoryFilters[$key];
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => $this->rowMatchesFilter($node, $attributes, $row)
                ));
            }

            return $rows;
        };

        $collection = $this->createStub(UserCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition = null) use ($state, $collection): UserCollection {
                $state->filters[] = [$field, $condition];

                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnCallback(fn ($size): UserCollection => $collection);

        $select = $this->createStub(Select::class);
        $select->method('order')->willReturnCallback(fn ($spec): Select => $select);
        $select->method('where')->willReturnCallback(function ($condition) use ($state, $select): Select {
            $state->wheres[] = (string)$condition;

            return $select;
        });
        $select->method('limit')->willReturnCallback(
            function ($count = null, $offset = null) use ($state, $select): Select {
                $state->limit = $count === null ? null : (int)$count;
                $state->offset = (int)$offset;

                return $select;
            }
        );
        $collection->method('getSelect')->willReturn($select);

        $collection->method('getSize')->willReturnCallback(fn (): int => count($match()));
        $collection->method('getFirstItem')->willReturnCallback(
            fn (): User => $this->makeAdminUser($match()[0] ?? [])
        );
        $collection->method('getItems')->willReturnCallback(function () use ($match, $state): array {
            $rows = $match();
            if ($state->limit !== null) {
                $rows = array_slice($rows, $state->offset, $state->limit);
            }

            return array_map(fn (array $row): User => $this->makeAdminUser($row), $rows);
        });

        return $collection;
    }

    /**
     * A real {@see ScimResponse} whose `Raw` result captures status + body.
     *
     * @param ScimResponseCapture $capture
     * @return ScimResponse
     */
    private function createCapturingResponse(ScimResponseCapture $capture): ScimResponse
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($capture, $raw): Raw {
            $capture->status = (int)$code;

            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($content) use ($capture, $raw): Raw {
            $capture->contents = (string)$content;

            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(
            function ($name, $value, $replace = false) use ($capture, $raw): Raw {
                $capture->headers[$name] = (string)$value;

                return $raw;
            }
        );

        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        return new ScimResponse($rawFactory, new Json());
    }

    /**
     * A {@see ResourceConnection} whose adapter accepts (no-op) transaction calls,
     * so code wrapping writes in begin/commit/rollBack runs without a database.
     */
    private function createResourceConnection(): ResourceConnection
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);

        return $resource;
    }

    /**
     * A real {@see EndpointUrlBuilder} over a fixed store base URL.
     */
    private function createUrlBuilder(): EndpointUrlBuilder
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://magento.loc/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new EndpointUrlBuilder($storeManager);
    }

    /**
     * An {@see Http} request stub for one SCIM call.
     *
     * @param string $method
     * @param string $path request path info (drives resource-id extraction)
     * @param string $body raw request body
     * @param array<string,string> $params query parameters (filter/startIndex/count)
     * @return Http
     */
    private function scimRequest(string $method, string $path, string $body = '', array $params = []): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getContent')->willReturn($body);
        $request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $params[$key] ?? $default);

        return $request;
    }
}
