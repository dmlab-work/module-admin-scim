<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\Group\GroupRoleSynchronizer;
use DmLab\AdminScim\Model\User\FilterParser;
use DmLab\AdminScim\Model\User\LastAdminGuard;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use DmLab\AdminScim\Model\User\ScimUserPatcher;
use DmLab\AdminScim\Model\User\ScimUserRepository;
use DmLab\AdminScim\Model\User\UserProvisioner;
use DmLab\AdminScim\Model\WriteLock;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\ResourceModel\AdminSessionInfo as AdminSessionInfoResource;
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

    /** @var GroupRoleSynchronizer&\PHPUnit\Framework\MockObject\Stub */
    private $synchronizer;

    /** @var AuditLogger&\PHPUnit\Framework\MockObject\Stub */
    private $auditLogger;

    /** @var AdminSessionInfoResource&\PHPUnit\Framework\MockObject\Stub */
    private $sessionResource;

    /** @var LastAdminGuard&\PHPUnit\Framework\MockObject\Stub */
    private $guard;

    /** @var DateTime&\PHPUnit\Framework\MockObject\Stub */
    private $dateTime;

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
        $this->synchronizer = $this->createStub(GroupRoleSynchronizer::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->sessionResource = $this->createStub(AdminSessionInfoResource::class);
        $this->guard = $this->createStub(LastAdminGuard::class);
        $this->mapper->method('toLogin')->willReturnArgument(0);
        $this->dateTime = $this->createStub(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-05-01 10:00:00');

        $this->provisioner = $this->provisionerWith($this->synchronizer);
    }

    /**
     * The provisioner under test over the shared collaborators and the given synchronizer.
     *
     * @param GroupRoleSynchronizer $synchronizer
     * @param AuditLogger|null $auditLogger
     */
    private function provisionerWith(
        GroupRoleSynchronizer $synchronizer,
        ?AuditLogger $auditLogger = null
    ): UserProvisioner {
        return new UserProvisioner(
            $this->mapper,
            $this->userFactory,
            $this->userResource,
            $this->collectionFactory,
            $this->random,
            $this->repository,
            $this->patcher,
            $synchronizer,
            $auditLogger ?? $this->auditLogger,
            $this->writeLock(),
            $this->sessionResource,
            $this->guard,
            $this->dateTime
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
        $this->collectionFactory->expects(self::exactly(4))
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
            ScimUserMapper::USER_NAME_FIELD => 'jane.doe',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'RANDOMa1',
            'is_active' => 1,
            'interface_locale' => 'en_US',
            ScimUserMapper::EXTERNAL_ID_FIELD => 'ext-9',
            ScimUserMapper::MANAGED_FIELD => 1,
            'created' => '2026-05-01 10:00:00',
            'modified' => '2026-05-01 10:00:00',
        ], $captured);
    }

    public function testInactivePayloadCreatesDisabledUser(): void
    {
        $this->mapperReturns(null, false);

        // username + email lookups run (no externalId).
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

        $this->provisioner->create(['userName' => 'jane.doe', 'active' => false]);

        self::assertSame(0, $captured['is_active']);
        self::assertNull($captured[ScimUserMapper::EXTERNAL_ID_FIELD]);
    }

    public function testSkipsExternalIdUniquenessWhenAbsent(): void
    {
        $this->mapperReturns(null);

        // username + email lookups run; the externalId lookup is skipped when absent.
        $this->collectionFactory->expects(self::exactly(3))
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

        // userName + login + email lookups miss, externalId lookup collides.
        $this->collectionFactory->expects(self::exactly(4))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
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

        // userName + login lookups miss, email lookup collides with another admin.
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

        $this->collectionFactory->expects(self::exactly(4))
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
        $this->collectionFactory->expects(self::exactly(4))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userResource->expects(self::once())->method('save')->with($user);

        self::assertSame($user, $this->provisioner->replace('42', ['userName' => 'jane.doe']));
        self::assertSame([
            'username' => 'jane.doe',
            ScimUserMapper::USER_NAME_FIELD => 'jane.doe',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'is_active' => 1,
            ScimUserMapper::EXTERNAL_ID_FIELD => 'ext-9',
            'modified' => '2026-05-01 10:00:00',
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
        $this->collectionFactory->expects(self::exactly(3))
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
        // userName + login + email miss, externalId collides with another admin.
        $this->collectionFactory->expects(self::exactly(4))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
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

    /**
     * A provisioner over a real repository that loads a local (non-SCIM) admin.
     *
     * @param ScimUserPatcher $patcher
     */
    private function provisionerOverUnmanagedAdmin(ScimUserPatcher $patcher): UserProvisioner
    {
        $local = $this->createStub(User::class);
        $local->method('getId')->willReturn(42);
        $local->method('getData')->willReturnMap([[ScimUserMapper::MANAGED_FIELD, '0']]);
        $factory = $this->createStub(UserFactory::class);
        $factory->method('create')->willReturn($local);
        $repository = new ScimUserRepository(
            $factory,
            $this->createStub(UserResource::class),
            $this->createStub(UserCollectionFactory::class),
            $this->createStub(FilterParser::class)
        );

        return new UserProvisioner(
            $this->mapper,
            $this->userFactory,
            $this->userResource,
            $this->collectionFactory,
            $this->random,
            $repository,
            $patcher,
            $this->synchronizer,
            $this->auditLogger,
            $this->writeLock(),
            $this->sessionResource,
            $this->guard,
            $this->dateTime
        );
    }

    public function testReplaceOfUnmanagedAdminIs404AndSavesNothing(): void
    {
        $this->mapperReturns(null);
        $provisioner = $this->provisionerOverUnmanagedAdmin($this->patcher);
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        try {
            $provisioner->replace('42', ['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testPatchOfUnmanagedAdminIs404AndSavesNothing(): void
    {
        $patcher = $this->createMock(ScimUserPatcher::class);
        $patcher->expects(self::never())->method('apply');
        $provisioner = $this->provisionerOverUnmanagedAdmin($patcher);
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        try {
            $provisioner->patch('42', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testReplaceNeverClearsManagedFlag(): void
    {
        $this->mapperReturns(null);
        $captured = [];
        $user = $this->loadedUser(42, $captured);
        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userResource->expects(self::once())->method('save');

        $this->provisioner->replace('42', ['userName' => 'jane.doe']);

        self::assertArrayNotHasKey(ScimUserMapper::MANAGED_FIELD, $captured);
    }

    public function testCreateSyncsRoleOfNewUser(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $newUser = $this->createStub(User::class);
        $newUser->method('setData')->willReturnSelf();
        $newUser->method('getId')->willReturn(42);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $synchronizer = $this->createMock(GroupRoleSynchronizer::class);
        $synchronizer->expects(self::once())->method('syncUsers')->with([42]);

        $this->provisionerWith($synchronizer)->create(['userName' => 'jane.doe']);
    }

    public function testReplaceSyncsRoleOfUser(): void
    {
        $this->mapperReturns(null);
        $captured = [];
        $user = $this->loadedUser(42, $captured);
        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userResource->expects(self::once())->method('save')->with($user);

        $synchronizer = $this->createMock(GroupRoleSynchronizer::class);
        $synchronizer->expects(self::once())->method('syncUsers')->with([42]);

        $this->provisionerWith($synchronizer)->replace('42', ['userName' => 'jane.doe']);
    }

    public function testPatchSyncsRoleOfUser(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getData')->willReturnMap([
            ['username', 'jane.doe'],
            ['email', 'jane@example.com'],
            [ScimUserMapper::EXTERNAL_ID_FIELD, null],
        ]);
        $this->repository->method('getById')->willReturn($user);
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userResource->expects(self::once())->method('save')->with($user);

        $synchronizer = $this->createMock(GroupRoleSynchronizer::class);
        $synchronizer->expects(self::once())->method('syncUsers')->with([42]);

        $this->provisionerWith($synchronizer)->patch(
            '42',
            ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]
        );
    }

    public function testNoRoleSyncWhenCreateConflicts(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($this->existingUser(5)));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        $synchronizer = $this->createMock(GroupRoleSynchronizer::class);
        $synchronizer->expects(self::never())->method('syncUsers');

        $this->expectException(ScimException::class);

        $this->provisionerWith($synchronizer)->create(['userName' => 'jane.doe']);
    }

    public function testDeleteRemovesLoadedUser(): void
    {
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $user = $this->existingUser(42);
        $this->repository->method('getById')->willReturn($user);
        $this->userResource->expects(self::once())->method('delete')->with($user)->willReturn(true);
        $this->userResource->expects(self::never())->method('save');

        $this->provisioner->delete('42');
    }

    public function testDeletePropagatesNotFound(): void
    {
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $this->repository->method('getById')
            ->willThrowException(ScimException::notFound('User "999" not found.'));
        $this->userResource->expects(self::never())->method('delete');

        try {
            $this->provisioner->delete('999');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testDeleteOfUnmanagedAdminIs404AndDeletesNothing(): void
    {
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $provisioner = $this->provisionerOverUnmanagedAdmin($this->patcher);
        $this->userResource->expects(self::never())->method('delete');

        try {
            $provisioner->delete('42');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    public function testDeleteFailureReportedByResourceIs500(): void
    {
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $this->repository->method('getById')->willReturn($this->existingUser(42));
        $this->userResource->expects(self::once())->method('delete')->willReturn(false);

        try {
            $this->provisioner->delete('42');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(500, $e->getHttpStatus());
        }
    }

    /** An audit logger expecting exactly one event with the given context. */
    private function expectAudit(string $event, array $context): AuditLogger
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log')->with($event, $context);

        return $auditLogger;
    }

    /** A loaded admin user whose getData reads from a mutable array. */
    private function userWithData(int $id, array &$data): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getData')->willReturnCallback(
            static function ($key = '') use (&$data) {
                return $data[$key] ?? null;
            }
        );
        $user->method('addData')->willReturnCallback(
            static function (array $more) use (&$data, $user) {
                $data = $more + $data;
                return $user;
            }
        );

        return $user;
    }

    public function testCreateLogsUserCreate(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $data = [];
        $newUser = $this->userWithData(42, $data);
        $newUser->method('setData')->willReturnCallback(
            static function (array $values) use (&$data, $newUser) {
                $data = $values;
                return $newUser;
            }
        );
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $this->provisionerWith(
            $this->synchronizer,
            $this->expectAudit('user.create', ['user_id' => 42, 'userName' => 'jane.doe'])
        )->create(['userName' => 'jane.doe']);
    }

    public function testReplaceLogsUserReplace(): void
    {
        $this->mapperReturns(null);
        $data = ['username' => 'old.name'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::once())->method('save');

        $this->provisionerWith(
            $this->synchronizer,
            $this->expectAudit('user.replace', ['user_id' => 42, 'userName' => 'jane.doe'])
        )->replace('42', ['userName' => 'jane.doe']);
    }

    public function testPatchLogsActiveBeforeAndAfter(): void
    {
        $data = ['username' => 'jane.doe', 'email' => 'jane@example.com', 'is_active' => '1'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->patcher->method('apply')->willReturnCallback(
            static function () use (&$data): void {
                $data['is_active'] = 0;
            }
        );
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::once())->method('save');

        $this->provisionerWith(
            $this->synchronizer,
            $this->expectAudit('user.patch', [
                'user_id' => 42,
                'userName' => 'jane.doe',
                'active_before' => true,
                'active_after' => false,
            ])
        )->patch('42', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
    }

    /**
     * Swap in a session resource expecting the LOGGED_IN → LOGGED_OUT update for admin 42.
     *
     * @param \PHPUnit\Framework\MockObject\Rule\InvocationOrder $times
     */
    private function expectSessionLogout($times): void
    {
        $this->sessionResource = $this->createMock(AdminSessionInfoResource::class);
        $this->sessionResource->expects($times)->method('updateStatusByUserId')
            ->with(AdminSessionInfo::LOGGED_OUT, 42, [AdminSessionInfo::LOGGED_IN]);
        $this->provisioner = $this->provisionerWith($this->synchronizer);
    }

    public function testPatchDeactivationLogsOutOpenSessions(): void
    {
        $data = ['username' => 'jane.doe', 'email' => 'jane@example.com', 'is_active' => '1'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->patcher->method('apply')->willReturnCallback(
            static function () use (&$data): void {
                $data['is_active'] = 0;
            }
        );
        $this->collectionFactory->expects(self::atLeastOnce())->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::atLeastOnce())->method('save');
        $this->expectSessionLogout(self::once());

        $this->provisioner->patch('42', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
    }

    public function testReplaceDeactivationLogsOutOpenSessions(): void
    {
        $this->mapperReturns(null, false);
        $data = ['username' => 'jane.doe', 'is_active' => '1'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->collectionFactory->expects(self::atLeastOnce())->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::atLeastOnce())->method('save');
        $this->expectSessionLogout(self::once());

        $this->provisioner->replace('42', ['userName' => 'jane.doe', 'active' => false]);
    }

    public function testSessionsKeptWhenActiveStateDoesNotDrop(): void
    {
        $data = ['username' => 'jane.doe', 'email' => 'jane@example.com', 'is_active' => '0'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->collectionFactory->expects(self::atLeastOnce())->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::atLeastOnce())->method('save');
        $this->expectSessionLogout(self::never());

        // Already inactive: deactivating again ends nothing new.
        $this->provisioner->patch('42', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        $data['is_active'] = '1';
        $rename = ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'J'];
        $this->provisioner->patch('42', ['Operations' => [$rename]]);
    }

    public function testDeactivationSessionLogoutRollsBackWithTheWrite(): void
    {
        $data = ['username' => 'jane.doe', 'email' => 'jane@example.com', 'is_active' => '1'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->patcher->method('apply')->willReturnCallback(
            static function () use (&$data): void {
                $data['is_active'] = 0;
            }
        );
        $this->collectionFactory->expects(self::atLeastOnce())->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::atLeastOnce())->method('save');
        $calls = [];
        $this->sessionResource->method('updateStatusByUserId')->willReturnCallback(
            static function () use (&$calls): int {
                $calls[] = 'logout';
                return 1;
            }
        );
        $this->userResource->method('beginTransaction')->willReturnCallback(
            function () use (&$calls) {
                $calls[] = 'begin';
                return $this->userResource;
            }
        );
        $this->userResource->method('commit')->willReturnCallback(
            function () use (&$calls) {
                $calls[] = 'commit';
                return $this->userResource;
            }
        );

        $this->provisioner->patch('42', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);

        self::assertSame(['begin', 'logout', 'commit'], $calls);
    }

    public function testUpdateLeavingNoUsableAdminRollsBack(): void
    {
        $data = ['username' => 'jane.doe', 'email' => 'jane@example.com', 'is_active' => '1'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->patcher->method('apply')->willReturnCallback(
            static function () use (&$data): void {
                $data['is_active'] = 0;
            }
        );
        $this->collectionFactory->expects(self::atLeastOnce())->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::once())->method('save');
        $this->userResource->expects(self::once())->method('rollBack');
        $this->userResource->expects(self::never())->method('commit');
        $this->guard = $this->createMock(LastAdminGuard::class);
        $this->guard->expects(self::once())->method('countUsable')->with()->willReturn(1);
        $this->guard->expects(self::once())->method('assertUsableAdminRemains')->with(1)
            ->willThrowException(ScimException::badRequest('last admin', 'mutability'));
        $provisioner = $this->provisionerWith($this->synchronizer, $this->expectNoAudit());

        try {
            $provisioner->patch('42', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('mutability', $e->getScimType());
        }
    }

    public function testCreateSkipsTheLastAdminCount(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::atLeastOnce())->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::once())->method('create')->willReturn($this->existingUser(42));
        $this->userResource->expects(self::once())->method('save');
        $this->guard = $this->createMock(LastAdminGuard::class);
        $this->guard->expects(self::never())->method('countUsable');
        $this->guard->expects(self::once())->method('assertUsableAdminRemains')->with(0);

        $this->provisionerWith($this->synchronizer)->create(['userName' => 'jane.doe']);
    }

    public function testDeleteOfLastUsableAdminIsRejected(): void
    {
        $this->repository->method('getById')->willReturn($this->existingUser(42));
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('delete');
        $this->guard = $this->createMock(LastAdminGuard::class);
        $this->guard->expects(self::once())->method('countUsable')->with()->willReturn(1);
        $this->guard->expects(self::once())->method('assertUsableAdminRemains')->with(1, 42)
            ->willThrowException(ScimException::badRequest('last admin', 'mutability'));

        $this->expectException(ScimException::class);
        $this->provisionerWith($this->synchronizer)->delete('42');
    }

    public function testDeleteLogsUserDelete(): void
    {
        $data = ['username' => 'jane.doe'];
        $this->repository->method('getById')->willReturn($this->userWithData(42, $data));
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::once())->method('delete')->willReturn(true);

        $this->provisionerWith(
            $this->synchronizer,
            $this->expectAudit('user.delete', ['user_id' => 42, 'userName' => 'jane.doe'])
        )->delete('42');
    }

    public function testConflictingCreateLogsNothing(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::once())->method('create')
            ->willReturn($this->collectionReturning($this->existingUser(5)));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        try {
            $this->provisionerWith($this->synchronizer, $this->expectNoAudit())->create(['userName' => 'jane.doe']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function testFailedDeleteLogsNothing(): void
    {
        $this->repository->method('getById')->willReturn($this->existingUser(42));
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::once())->method('delete')->willReturn(false);

        try {
            $this->provisionerWith($this->synchronizer, $this->expectNoAudit())->delete('42');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(500, $e->getHttpStatus());
        }
    }

    public function testCreateRollsBackWhenRoleSyncFails(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $newUser = $this->createStub(User::class);
        $newUser->method('getId')->willReturn(42);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('beginTransaction');
        $this->userResource->expects(self::once())->method('save');
        $this->userResource->expects(self::once())->method('rollBack');
        $this->userResource->expects(self::never())->method('commit');

        $synchronizer = $this->createStub(GroupRoleSynchronizer::class);
        $synchronizer->method('syncUsers')->willThrowException(new \RuntimeException('role save failed'));

        $this->expectException(\RuntimeException::class);
        $this->provisionerWith($synchronizer, $this->expectNoAudit())->create(['userName' => 'jane.doe']);
    }

    public function testDuplicateOnSaveRollsBackWithoutRoleSync(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::once())->method('create')->willReturn($this->createStub(User::class));
        $this->userResource->method('save')
            ->willThrowException(new AlreadyExistsException(new Phrase('Unique constraint violation found')));
        $this->userResource->expects(self::once())->method('rollBack');
        $this->userResource->expects(self::never())->method('commit');

        $synchronizer = $this->createMock(GroupRoleSynchronizer::class);
        $synchronizer->expects(self::never())->method('syncUsers');

        $this->expectException(ScimException::class);
        $this->provisionerWith($synchronizer)->create(['userName' => 'jane.doe']);
    }

    public function testSuccessfulWriteCommits(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $newUser = $this->createStub(User::class);
        $newUser->method('getId')->willReturn(42);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('beginTransaction');
        $this->userResource->expects(self::once())->method('commit');
        $this->userResource->expects(self::never())->method('rollBack');

        $this->provisioner->create(['userName' => 'jane.doe']);
    }

    /** An audit logger that must never be written to. */
    private function expectNoAudit(): AuditLogger
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('log');

        return $auditLogger;
    }

    /** A write lock that is always granted. */
    private function writeLock(): WriteLock
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        return new WriteLock($lockManager);
    }

    public function testRoleChangeIsLoggedAfterCommit(): void
    {
        $this->mapperReturns(null);
        $this->collectionFactory->expects(self::exactly(3))->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $newUser = $this->createStub(User::class);
        $newUser->method('getId')->willReturn(42);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);

        $calls = [];
        $change = ['user_id' => 42, 'userName' => 'jane.doe', 'from' => null, 'to' => 1];
        $synchronizer = $this->createStub(GroupRoleSynchronizer::class);
        $synchronizer->method('syncUsers')->willReturn([$change]);
        $this->userResource->expects(self::once())->method('save');
        $this->userResource->method('commit')->willReturnCallback(function () use (&$calls): UserResource {
            $calls[] = 'commit';
            return $this->userResource;
        });
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::exactly(2))->method('log')->willReturnCallback(
            static function (string $event, array $context) use (&$calls): void {
                $calls[] = $event;
            }
        );

        $this->provisionerWith($synchronizer, $auditLogger)->create(['userName' => 'jane.doe']);

        self::assertSame(['commit', 'user.create', 'role.change'], $calls);
    }

    public function testWritesRunUnderTheWriteLock(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->willReturn(false);
        $this->repository->method('getById')->willReturn($this->existingUser(42));
        $this->userResource->expects(self::never())->method('delete');
        $this->userFactory->expects(self::never())->method('create');
        $this->collectionFactory->expects(self::never())->method('create');

        $provisioner = new UserProvisioner(
            $this->mapper,
            $this->userFactory,
            $this->userResource,
            $this->collectionFactory,
            $this->random,
            $this->repository,
            $this->patcher,
            $this->synchronizer,
            $this->auditLogger,
            new WriteLock($lockManager),
            $this->sessionResource,
            $this->guard,
            $this->dateTime
        );

        $this->expectException(ScimException::class);
        $provisioner->delete('42');
    }
}
