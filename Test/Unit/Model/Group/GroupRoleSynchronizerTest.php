<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Model\Config;
use DmLab\AdminScim\Model\Group\GroupRoleSynchronizer;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Mapping\MappingEngine;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\ResourceModel\AdminSessionInfo as AdminSessionInfoResource;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupRoleSynchronizerTest extends TestCase
{
    /** @var Config&\PHPUnit\Framework\MockObject\Stub */
    private $config;

    /** @var MemberResource&\PHPUnit\Framework\MockObject\Stub */
    private $memberResource;

    /** @var UserFactory&\PHPUnit\Framework\MockObject\Stub */
    private $userFactory;

    /** @var UserResource&MockObject */
    private $userResource;

    /** @var RoleFactory&\PHPUnit\Framework\MockObject\Stub */
    private $roleFactory;

    /** @var GroupRoleSynchronizer */
    private GroupRoleSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->memberResource = $this->createStub(MemberResource::class);
        $this->userFactory = $this->createStub(UserFactory::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->roleFactory = $this->createStub(RoleFactory::class);

        $this->synchronizer = $this->makeSynchronizer($this->createStub(AdminSessionInfoResource::class));
    }

    /**
     * Real mapping engine — resolution is what we exercise.
     */
    private function makeSynchronizer(AdminSessionInfoResource $sessionResource): GroupRoleSynchronizer
    {
        return new GroupRoleSynchronizer(
            $this->config,
            new MappingEngine(),
            $this->memberResource,
            $this->userFactory,
            $this->userResource,
            $this->roleFactory,
            $sessionResource
        );
    }

    /**
     * Rebuild the synchronizer on a session resource mock for expectations.
     *
     * @return AdminSessionInfoResource&MockObject
     */
    private function sessionResourceMock()
    {
        $sessionResource = $this->createMock(AdminSessionInfoResource::class);
        $this->synchronizer = $this->makeSynchronizer($sessionResource);

        return $sessionResource;
    }

    /**
     * @param array<string,string> $map
     */
    private function withRules(array $map, ?string $default = null): void
    {
        $this->config->method('getGroupRoleMap')->willReturn($map);
        $this->config->method('getDefaultRoleId')->willReturn($default);
    }

    /**
     * An admin user whose current ACL role has the given id (null = none). Loaded
     * by the factory + resource, and identified as existing (id set).
     *
     * @param int $userId
     * @param string|null $currentRoleId
     * @param bool $managed whether the admin is SCIM-managed
     * @return User&MockObject
     */
    private function user(int $userId, ?string $currentRoleId, bool $managed = true)
    {
        $role = $this->createStub(Role::class);
        $role->method('getId')->willReturn($currentRoleId);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn($role);
        $user->method('getData')->willReturnMap([
            [ScimUserMapper::MANAGED_FIELD, $managed ? '1' : '0'],
            ['username', 'jdoe'],
        ]);

        $this->userFactory->method('create')->willReturn($user);
        $this->userResource->method('load')->willReturnSelf();

        return $user;
    }

    /**
     * Stub the resolved-role existence probe.
     *
     * @param bool $exists
     */
    private function resolvedRoleExists(bool $exists): void
    {
        $role = $this->createStub(Role::class);
        $role->method('load')->willReturnSelf();
        $role->method('getId')->willReturn($exists ? '5' : null);
        $this->roleFactory->method('create')->willReturn($role);
    }

    public function testAssignsMappedRoleWhenGroupMatches(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, '2');

        $user->expects(self::once())->method('setData')->with('role_id', 5);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->synchronizer->syncUsers([1]);
    }

    public function testFallsBackToDefaultRoleWhenNoGroupMatches(): void
    {
        $this->withRules(['Admins' => '5'], '1');
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Engineering']);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, null);

        $user->expects(self::once())->method('setData')->with('role_id', 1);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->synchronizer->syncUsers([1]);
    }

    public function testRevokesRoleWhenNoGroupMatchesAndNoDefault(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $user = $this->user(1, '2');

        // No role resolves → strip the current role (deny).
        $user->expects(self::once())->method('setData')->with('role_id', 0);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->synchronizer->syncUsers([1]);
    }

    public function testNoOpWhenNoRoleResolvesAndUserAlreadyHasNone(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $user = $this->user(1, null);

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->synchronizer->syncUsers([1]);
    }

    public function testNoOpWhenUserAlreadyOnResolvedRole(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $user = $this->user(1, '5');

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->synchronizer->syncUsers([1]);
    }

    public function testStripsRoleWhenResolvedRoleDoesNotExist(): void
    {
        $this->withRules(['Admins' => '999']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(false);
        $user = $this->user(1, '2');

        // A stale/mistyped mapping must fail closed: strip the current role rather
        // than silently preserving prior access.
        $user->expects(self::once())->method('setData')->with('role_id', 0);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->synchronizer->syncUsers([1]);
    }

    public function testLeavesRolelessUserAloneWhenResolvedRoleDoesNotExist(): void
    {
        $this->withRules(['Admins' => '999']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(false);
        $user = $this->user(1, null);

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->synchronizer->syncUsers([1]);
    }

    public function testLeavesUnmanagedAdminUntouched(): void
    {
        // A stale membership row names a local admin; its role must not change.
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $user = $this->user(1, '2', false);

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->synchronizer->syncUsers([1]);
    }

    public function testSkipsRemovedUser(): void
    {
        $this->withRules(['Admins' => '5']);
        // The admin was removed between the membership change and this sync.
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);
        $this->userFactory->method('create')->willReturn($user);
        $this->userResource->method('load')->willReturnSelf();

        $this->userResource->expects(self::never())->method('save');

        $this->synchronizer->syncUsers([1]);
    }

    public function testDeduplicatesUserIds(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, '2');

        // Duplicate ids collapse to a single sync.
        $user->expects(self::once())->method('setData')->with('role_id', 5);
        $this->userResource->expects(self::once())->method('save');

        $this->synchronizer->syncUsers([1, 1]);
    }

    public function testUserWithoutGroupsGetsDefaultRole(): void
    {
        $this->withRules(['Admins' => '5'], '3');
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, null);

        $user->expects(self::once())->method('setData')->with('role_id', 3);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->synchronizer->syncUsers([1]);
    }

    public function testUserWithoutGroupsAndNoDefaultStaysRoleless(): void
    {
        $this->withRules([]);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $user = $this->user(1, null);

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->synchronizer->syncUsers([1]);
    }

    public function testReturnsRoleChange(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, '2');
        $user->expects(self::once())->method('setData')->with('role_id', 5);
        $this->userResource->expects(self::once())->method('save');

        self::assertSame(
            [['user_id' => 1, 'userName' => 'jdoe', 'from' => 2, 'to' => 5]],
            $this->synchronizer->syncUsers([1])
        );
    }

    public function testReturnsRoleStrip(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $user = $this->user(1, '2');
        $user->expects(self::once())->method('setData')->with('role_id', 0);
        $this->userResource->expects(self::once())->method('save');

        self::assertSame(
            [['user_id' => 1, 'userName' => 'jdoe', 'from' => 2, 'to' => null]],
            $this->synchronizer->syncUsers([1])
        );
    }

    public function testReturnsFirstRoleGrantFromNone(): void
    {
        $this->withRules([], '1');
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, null);
        $user->expects(self::once())->method('setData')->with('role_id', 1);
        $this->userResource->expects(self::once())->method('save');

        self::assertSame(
            [['user_id' => 1, 'userName' => 'jdoe', 'from' => null, 'to' => 1]],
            $this->synchronizer->syncUsers([1])
        );
    }

    public function testReturnsNoChangeWhenRoleUnchanged(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(true);
        $user = $this->user(1, '5');
        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        self::assertSame([], $this->synchronizer->syncUsers([1]));
    }

    /**
     * Expect the admin's open backend sessions to be logged out exactly once.
     */
    private function expectSessionsEnded(int $userId): void
    {
        $this->sessionResourceMock()->expects(self::once())->method('updateStatusByUserId')
            ->with(AdminSessionInfo::LOGGED_OUT, $userId, [AdminSessionInfo::LOGGED_IN]);
    }

    public function testRoleChangeEndsBackendSessions(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->resolvedRoleExists(true);
        $this->user(1, '2')->expects(self::once())->method('setData')->with('role_id', 5);
        $this->userResource->expects(self::once())->method('save');
        $this->expectSessionsEnded(1);

        $this->synchronizer->syncUsers([1]);
    }

    public function testRoleStripEndsBackendSessions(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $this->user(1, '2')->expects(self::once())->method('setData')->with('role_id', 0);
        $this->userResource->expects(self::once())->method('save');
        $this->expectSessionsEnded(1);

        $this->synchronizer->syncUsers([1]);
    }

    public function testUnchangedRoleKeepsBackendSessions(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn(['Admins']);
        $this->user(1, '5')->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');
        $this->sessionResourceMock()->expects(self::never())->method('updateStatusByUserId');

        $this->synchronizer->syncUsers([1]);
    }

    public function testUnmanagedAdminKeepsBackendSessions(): void
    {
        $this->withRules(['Admins' => '5']);
        $this->memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);
        $this->user(1, '2', false)->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');
        $this->sessionResourceMock()->expects(self::never())->method('updateStatusByUserId');

        $this->synchronizer->syncUsers([1]);
    }
}
