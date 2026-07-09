<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\User;

use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\User\ScimUserMapper;
use MageDevGroup\AdminScim\Model\User\ScimUserPatcher;
use MageDevGroup\AdminScim\Model\User\ScimUserRepository;
use MageDevGroup\AdminScim\Model\User\UserProvisioner;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Math\Random;
use Magento\Framework\Phrase;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\Collection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserProvisionerTest extends TestCase
{
    /** @var ScimUserMapper&\PHPUnit\Framework\MockObject\Stub */
    private $mapper;

    /** @var UserFactory&MockObject */
    private $userFactory;

    /** @var UserResource&MockObject */
    private $userResource;

    /** @var UserCollectionFactory&MockObject */
    private $collectionFactory;

    /** @var Random&\PHPUnit\Framework\MockObject\Stub */
    private $random;

    /** @var ScimUserRepository&\PHPUnit\Framework\MockObject\Stub */
    private $repository;

    /** @var ScimUserPatcher&\PHPUnit\Framework\MockObject\Stub */
    private $patcher;

    /** @var UserProvisioner */
    private UserProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->mapper = $this->createStub(ScimUserMapper::class);
        $this->userFactory = $this->createMock(UserFactory::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->collectionFactory = $this->createMock(UserCollectionFactory::class);
        $this->random = $this->createStub(Random::class);
        $this->random->method('getRandomString')->willReturn('RANDOM');
        $this->repository = $this->createStub(ScimUserRepository::class);
        $this->patcher = $this->createStub(ScimUserPatcher::class);

        $this->provisioner = new UserProvisioner(
            $this->mapper,
            $this->userFactory,
            $this->userResource,
            $this->collectionFactory,
            $this->random,
            $this->repository,
            $this->patcher
        );
    }

    /**
     * Collection whose single-item lookup yields the given admin user.
     *
     * @param User $firstItem
     * @return Collection&\PHPUnit\Framework\MockObject\Stub
     */
    private function collectionReturning(User $firstItem)
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($firstItem);

        return $collection;
    }

    /** A "not found" result: getFirstItem returns a user with no id. */
    private function emptyUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);

        return $user;
    }

    /** An existing admin user with the given id (a uniqueness collision). */
    private function existingUser(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    /** Program the mapper to return a full, valid attribute set. */
    private function mapperReturns(?string $externalId, bool $active = true): void
    {
        $this->mapper->method('extractUserName')->willReturn('jane.doe');
        $this->mapper->method('extractEmail')->willReturn('jane@example.com');
        $this->mapper->method('extractName')->willReturn(['Jane', 'Doe']);
        $this->mapper->method('extractExternalId')->willReturn($externalId);
        $this->mapper->method('extractActive')->willReturn($active);
    }

    public function testCreatesAdminUserFromPayload(): void
    {
        $this->mapperReturns('ext-9');

        // All three uniqueness lookups (username, email, externalId) miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $captured = [];
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );

        self::assertSame($newUser, $this->provisioner->create(['userName' => 'jane.doe']));
        self::assertSame([
            'username' => 'jane.doe',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'RANDOMa1',
            'is_active' => 1,
            'interface_locale' => 'en_US',
            ScimUserMapper::EXTERNAL_ID_FIELD => 'ext-9',
        ], $captured);
    }

    public function testInactivePayloadCreatesDisabledUser(): void
    {
        $this->mapperReturns(null, false);

        // username + email lookups run (no externalId).
        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $captured = [];
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );

        $this->provisioner->create(['userName' => 'jane.doe', 'active' => false]);

        self::assertSame(0, $captured['is_active']);
        self::assertNull($captured[ScimUserMapper::EXTERNAL_ID_FIELD]);
    }

    public function testSkipsExternalIdUniquenessWhenAbsent(): void
    {
        $this->mapperReturns(null);

        // username + email lookups run; the externalId lookup is skipped when absent.
        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $newUser->method('setData')->willReturnSelf();
        $this->userResource->expects(self::once())->method('save');

        $this->provisioner->create(['userName' => 'jane.doe']);
    }

    public function testThrowsConflictWhenUserNameTaken(): void
    {
        $this->mapperReturns('ext-9');

        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($this->existingUser(5)));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        try {
            $this->provisioner->create(['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('uniqueness', $e->getScimType());
            self::assertSame('A user with this userName already exists.', $e->getMessage());
        }
    }

    public function testThrowsConflictWhenExternalIdTaken(): void
    {
        $this->mapperReturns('ext-9');

        // username + email lookups miss, externalId lookup collides.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($this->existingUser(7))
            );
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        try {
            $this->provisioner->create(['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('A user with this externalId already exists.', $e->getMessage());
        }
    }

    public function testThrowsConflictWhenEmailTaken(): void
    {
        $this->mapperReturns('ext-9');

        // username lookup misses, email lookup collides with another admin.
        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($this->existingUser(7))
            );
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        try {
            $this->provisioner->create(['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('uniqueness', $e->getScimType());
            self::assertSame('A user with this email already exists.', $e->getMessage());
        }
    }

    /**
     * A concurrent insert can slip past the pre-checks and hit the DB unique
     * index; the resource model reports that as AlreadyExistsException (it wraps
     * the adapter DuplicateException). That must map to a 409, not a 500.
     */
    public function testMapsResourceUniquenessViolationToConflict(): void
    {
        $this->mapperReturns('ext-9');

        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())
            ->method('save')
            ->willThrowException(new AlreadyExistsException(new Phrase('Unique constraint violation found')));

        try {
            $this->provisioner->create(['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('uniqueness', $e->getScimType());
        }
    }

    public function testPropagatesMapperValidationError(): void
    {
        $this->mapper->method('extractUserName')
            ->willThrowException(ScimException::badRequest('Attribute "userName" is required.', 'invalidValue'));
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        $this->expectException(ScimException::class);

        $this->provisioner->create([]);
    }

    /**
     * An existing admin user loaded for update, recording addData into $captured.
     *
     * @param array<string,mixed> $captured
     * @return User&\PHPUnit\Framework\MockObject\Stub
     */
    private function loadedUser(int $id, array &$captured)
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('addData')->willReturnCallback(
            function ($data) use (&$captured, $user) {
                $captured = $data;
                return $user;
            }
        );

        return $user;
    }

    public function testReplaceUpdatesLoadedUser(): void
    {
        $this->mapperReturns('ext-9');
        $captured = [];
        $user = $this->loadedUser(42, $captured);
        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');

        // All three uniqueness lookups (username, email, externalId) miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userResource->expects(self::once())->method('save')->with($user);

        self::assertSame($user, $this->provisioner->replace('42', ['userName' => 'jane.doe']));
        self::assertSame([
            'username' => 'jane.doe',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'is_active' => 1,
            ScimUserMapper::EXTERNAL_ID_FIELD => 'ext-9',
        ], $captured);
    }

    public function testReplacePropagatesNotFound(): void
    {
        $this->repository->method('getById')
            ->willThrowException(ScimException::notFound('User "999" not found.'));
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        $this->expectException(ScimException::class);

        $this->provisioner->replace('999', ['userName' => 'jane.doe']);
    }

    public function testReplaceConflictsWithAnotherUser(): void
    {
        $this->mapperReturns(null);
        $captured = [];
        $user = $this->loadedUser(42, $captured);
        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');

        // The username belongs to a different admin (id 7).
        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($this->existingUser(7)));
        $this->userResource->expects(self::never())->method('save');

        try {
            $this->provisioner->replace('42', ['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function testPatchAppliesOperationsAndSaves(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getData')->willReturnMap([
            ['username', 'jane.doe'],
            ['email', 'jane@example.com'],
            [ScimUserMapper::EXTERNAL_ID_FIELD, null],
        ]);
        $body = ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]];

        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');
        // The patcher receives the loaded user and the raw PatchOp body.
        $applied = [];
        $this->patcher->method('apply')->willReturnCallback(
            function ($u, $b) use (&$applied) {
                $applied = ['user' => $u, 'body' => $b];
            }
        );
        // username + email uniqueness checks run (externalId is null).
        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userResource->expects(self::once())->method('save')->with($user);

        self::assertSame($user, $this->provisioner->patch('42', $body));
        self::assertSame(['user' => $user, 'body' => $body], $applied);
    }

    public function testPatchRechecksExternalIdUniqueness(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getData')->willReturnMap([
            ['username', 'jane.doe'],
            ['email', 'jane@example.com'],
            [ScimUserMapper::EXTERNAL_ID_FIELD, 'ext-9'],
        ]);
        $body = ['Operations' => [['op' => 'replace', 'path' => 'externalId', 'value' => 'ext-9']]];

        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');
        // username + email miss, externalId collides with another admin.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($this->existingUser(7))
            );
        $this->userResource->expects(self::never())->method('save');

        try {
            $this->provisioner->patch('42', $body);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('A user with this externalId already exists.', $e->getMessage());
        }
    }
}
