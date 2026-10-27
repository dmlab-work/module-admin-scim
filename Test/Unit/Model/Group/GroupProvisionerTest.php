<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\Group\GroupProvisioner;
use DmLab\AdminScim\Model\Group\GroupResource;
use DmLab\AdminScim\Model\Group\GroupRoleSynchronizer;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupPatcher;
use DmLab\AdminScim\Model\Group\ScimGroupRepository;
use DmLab\AdminScim\Model\User\LastAdminGuard;
use DmLab\AdminScim\Model\WriteLock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupProvisionerTest extends TestCase
{
    /** @var ScimGroupMapper&\PHPUnit\Framework\MockObject\Stub */
    private $mapper;

    /** @var GroupResource&MockObject */
    private $groupResource;

    /** @var ScimGroupRepository&\PHPUnit\Framework\MockObject\Stub */
    private $repository;

    /** @var ScimGroupPatcher&\PHPUnit\Framework\MockObject\Stub */
    private $patcher;

    /** @var MemberResource&MockObject */
    private $memberResource;

    /** @var GroupRoleSynchronizer&MockObject */
    private $roleSynchronizer;

    /** @var LastAdminGuard&\PHPUnit\Framework\MockObject\Stub */
    private $guard;

    /** @var GroupProvisioner */
    private GroupProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->mapper = $this->createStub(ScimGroupMapper::class);
        $this->groupResource = $this->createMock(GroupResource::class);
        $this->repository = $this->createStub(ScimGroupRepository::class);
        $this->patcher = $this->createStub(ScimGroupPatcher::class);
        $this->memberResource = $this->createMock(MemberResource::class);
        $this->roleSynchronizer = $this->createMock(GroupRoleSynchronizer::class);
        $this->guard = $this->createStub(LastAdminGuard::class);

        $this->provisioner = $this->createProvisioner($this->createStub(AdapterInterface::class));
    }

    /** A provisioner over the shared collaborators, the given DB adapter, audit logger and lock. */
    private function createProvisioner(
        AdapterInterface $adapter,
        ?AuditLogger $auditLogger = null,
        ?LockManagerInterface $lockManager = null
    ): GroupProvisioner {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        if ($lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('lock')->willReturn(true);
        }

        return new GroupProvisioner(
            $this->mapper,
            $this->groupResource,
            $this->repository,
            $this->patcher,
            $this->memberResource,
            $this->roleSynchronizer,
            $resource,
            $auditLogger ?? $this->createStub(AuditLogger::class),
            new WriteLock($lockManager),
            $this->guard
        );
    }

    /** Program the mapper for a create payload. */
    private function mapperReturns(string $displayName, ?string $externalId, array $members): void
    {
        $this->mapper->method('extractDisplayName')->willReturn($displayName);
        $this->mapper->method('extractExternalId')->willReturn($externalId);
        $this->mapper->method('extractMemberIds')->willReturn($members);
    }

    /** Make save() assign the given id to the persisted group. */
    private function saveAssignsId(int $id): void
    {
        $this->groupResource->method('save')->willReturnCallback(
            static function (ScimGroup $group) use ($id): void {
                $group->groupId = $id;
            }
        );
    }

    public function testCreatePersistsGroupMembersAndSyncsRoles(): void
    {
        $this->mapperReturns('Admins', 'grp-1', [5, 7]);
        $this->groupResource->expects(self::once())->method('findConflictId')
            ->with('external_id', 'grp-1', null)->willReturn(null);
        $this->saveAssignsId(10);

        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 7]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 7]);

        $group = $this->provisioner->create(['displayName' => 'Admins']);

        self::assertSame(10, $group->groupId);
        self::assertSame('Admins', $group->displayName);
        self::assertSame('grp-1', $group->externalId);
    }

    public function testCreateSkipsExternalIdUniquenessWhenAbsent(): void
    {
        $this->mapperReturns('Admins', null, []);
        // displayName is not unique, so no probe runs without an externalId.
        $this->groupResource->expects(self::never())->method('findConflictId');
        $this->saveAssignsId(3);
        $this->memberResource->expects(self::once())->method('setMembers')->with(3, []);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([]);

        $this->provisioner->create(['displayName' => 'Admins']);
    }

    public function testCreateRejectsUnknownMemberWithBadRequestAndNoWrite(): void
    {
        $this->mapperReturns('Admins', null, [5, 999]);
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->memberResource->method('findMissingUserIds')->with([5, 999])->willReturn([999]);
        // A bad member is caught before any write, so no orphaned group survives.
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->create(['displayName' => 'Admins']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    public function testCreateThrowsConflictOnDuplicateExternalId(): void
    {
        $this->mapperReturns('Admins', 'grp-1', []);
        $this->groupResource->method('findConflictId')->willReturn(99);
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->create(['displayName' => 'Admins']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('uniqueness', $e->getScimType());
        }
    }

    public function testDuplicateDisplayNameIsAllowed(): void
    {
        $this->mapperReturns('Admins', 'grp-2', []);
        $this->groupResource->expects(self::once())->method('findConflictId')
            ->with('external_id', 'grp-2', null)->willReturn(null);
        $this->saveAssignsId(11);
        $this->memberResource->expects(self::once())->method('setMembers')->with(11, []);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->willReturn([]);

        self::assertSame(11, $this->provisioner->create(['displayName' => 'Admins'])->groupId);
    }

    public function testPatchAppliesOpsPersistsAndSyncsAffectedUnion(): void
    {
        $group = new ScimGroup(10, 'Admins');
        $this->repository->method('getById')->willReturn($group);
        $this->memberResource->method('getUserIds')->with(10)->willReturn([5, 6]);

        // The patcher drops member 6 and adds member 8.
        $this->patcher->method('apply')->willReturnCallback(
            static function (ScimGroup $g, array &$members, array $body): void {
                $members = [5, 8];
            }
        );
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save')->with($group);

        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 8]);
        // Everyone who joined or left is re-synced: before ∪ after.
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6, 5, 8]);

        $body = ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "6"]']]];
        self::assertSame($group, $this->provisioner->patch('10', $body));
    }

    public function testPatchValidatesOnlyAddedMembers(): void
    {
        $group = new ScimGroup(10, 'Admins');
        $this->repository->method('getById')->willReturn($group);
        // 6 is a stale row naming an unmanaged admin; the patch adds 8.
        $this->memberResource->method('getUserIds')->willReturn([5, 6]);
        $this->patcher->method('apply')->willReturnCallback(
            static function (ScimGroup $g, array &$members, array $body): void {
                $members = [5, 6, 8];
            }
        );
        $this->groupResource->expects(self::never())->method('findConflictId');
        $this->memberResource->expects(self::once())->method('findMissingUserIds')
            ->with([2 => 8])->willReturn([]);
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 6, 8]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6, 5, 6, 8]);

        $this->provisioner->patch('10', ['Operations' => [['op' => 'add']]]);
    }

    public function testPatchRejectsUnknownAddedMemberWithNoWrite(): void
    {
        $group = new ScimGroup(10, 'Admins');
        $this->repository->method('getById')->willReturn($group);
        $this->memberResource->method('getUserIds')->willReturn([5]);
        $this->patcher->method('apply')->willReturnCallback(
            static function (ScimGroup $g, array &$members, array $body): void {
                $members = [5, 999];
            }
        );
        $this->memberResource->method('findMissingUserIds')->willReturn([999]);
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->patch('10', ['Operations' => [['op' => 'add']]]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    public function testPatchMapsDuplicateKeyToConflictAndRollsBack(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('beginTransaction');
        $adapter->expects(self::once())->method('rollBack');
        $adapter->expects(self::never())->method('commit');
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->expects(self::once())->method('getUserIds')->willReturn([]);
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save')->willThrowException(new DuplicateException('dup'));
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $provisioner->patch('10', ['Operations' => [['op' => 'replace']]]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function testReplaceRollsBackWhenSyncFails(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('rollBack');
        $adapter->expects(self::never())->method('commit');
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->mapperReturns('Admins', null, [5]);
        $this->groupResource->expects(self::once())->method('save');
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5]);
        $this->memberResource->method('getUserIds')->willReturn([]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')
            ->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $provisioner->replace('10', ['displayName' => 'Admins']);
    }

    public function testWriteLeavingNoUsableAdminRollsBack(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('rollBack');
        $adapter->expects(self::never())->method('commit');
        $this->guard = $this->createMock(LastAdminGuard::class);
        $this->guard->expects(self::once())->method('countUsable')->willReturn(1);
        $this->guard->expects(self::once())->method('assertUsableAdminRemains')->with(1)
            ->willThrowException(ScimException::badRequest('last admin', 'mutability'));
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->expects(self::once())->method('getUserIds')->willReturn([5]);
        $this->patcher->method('apply')->willReturnCallback(
            static function (ScimGroup $g, array &$members): void {
                $members = [];
            }
        );
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save');
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5])->willReturn([]);

        try {
            $provisioner->patch('10', ['Operations' => [['op' => 'remove', 'path' => 'members']]]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame('mutability', $e->getScimType());
        }
    }

    public function testDeleteLeavingNoUsableAdminRollsBack(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('rollBack');
        $adapter->expects(self::never())->method('commit');
        $this->guard = $this->createMock(LastAdminGuard::class);
        $this->guard->expects(self::once())->method('countUsable')->willReturn(1);
        $this->guard->expects(self::once())->method('assertUsableAdminRemains')->with(1)
            ->willThrowException(ScimException::badRequest('last admin', 'mutability'));
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->expects(self::once())->method('getUserIds')->willReturn([5]);
        $this->groupResource->expects(self::once())->method('delete');
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->willReturn([]);

        $this->expectException(ScimException::class);
        $provisioner->delete('10');
    }

    public function testPatchRechecksExternalIdUniqueness(): void
    {
        $group = new ScimGroup(10, 'Renamed', 'grp-9');
        $this->repository->method('getById')->willReturn($group);
        $this->memberResource->expects(self::once())->method('getUserIds')->willReturn([]);
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->patcher->method('apply');
        // The externalId is held by another group (id excluded = 10).
        $this->groupResource->method('findConflictId')
            ->with('external_id', 'grp-9', 10)->willReturn(42);
        $this->groupResource->expects(self::never())->method('save');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->patch('10', ['Operations' => [['op' => 'replace']]]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function testPatchPropagatesNotFound(): void
    {
        $this->repository->method('getById')
            ->willThrowException(ScimException::notFound('Group "999" not found.'));
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('getUserIds');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        $this->expectException(ScimException::class);
        $this->provisioner->patch('999', ['Operations' => [['op' => 'replace']]]);
    }

    public function testReplaceSwapsMembersAndSyncsUnion(): void
    {
        $group = new ScimGroup(10, 'Admins', 'grp-1');
        $this->repository->method('getById')->willReturn($group);
        $this->mapperReturns('Ops', 'grp-2', [5, 8]);
        $this->memberResource->method('getUserIds')->with(10)->willReturn([5, 6]);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->groupResource->expects(self::once())->method('findConflictId')
            ->willReturnCallback(static function (string $field, string $value, ?int $excludeId): ?int {
                self::assertSame(10, $excludeId);

                return null;
            });
        $this->groupResource->expects(self::once())->method('save')->with($group);

        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 8]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6, 5, 8]);

        $result = $this->provisioner->replace('10', ['displayName' => 'Ops']);

        self::assertSame($group, $result);
        self::assertSame('Ops', $result->displayName);
        self::assertSame('grp-2', $result->externalId);
    }

    public function testReplaceClearsExternalIdWhenAbsent(): void
    {
        $group = new ScimGroup(10, 'Admins', 'grp-1');
        $this->repository->method('getById')->willReturn($group);
        $this->mapperReturns('Admins', null, []);
        $this->memberResource->method('getUserIds')->willReturn([]);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->groupResource->expects(self::never())->method('findConflictId');
        $this->groupResource->expects(self::once())->method('save');
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, []);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([]);

        self::assertNull($this->provisioner->replace('10', ['displayName' => 'Admins'])->externalId);
    }

    public function testReplaceThrowsConflictOnExternalIdClash(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->mapperReturns('Ops', 'grp-2', []);
        $this->groupResource->method('findConflictId')->with('external_id', 'grp-2', 10)->willReturn(42);
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->replace('10', ['displayName' => 'Ops']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('uniqueness', $e->getScimType());
        }
    }

    public function testReplaceRejectsUnknownMember(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->mapperReturns('Admins', null, [5, 999]);
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->memberResource->method('findMissingUserIds')->with([5, 999])->willReturn([999]);
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->replace('10', ['displayName' => 'Admins']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    public function testReplaceMapsDuplicateKeyToConflictAndRollsBack(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('beginTransaction');
        $adapter->expects(self::once())->method('rollBack');
        $adapter->expects(self::never())->method('commit');
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->mapperReturns('Ops', null, []);
        $this->memberResource->method('getUserIds')->willReturn([]);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save')
            ->willThrowException(new DuplicateException('dup'));
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $provisioner->replace('10', ['displayName' => 'Ops']);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(409, $e->getHttpStatus());
        }
    }

    public function testReplacePropagatesNotFound(): void
    {
        $this->repository->method('getById')
            ->willThrowException(ScimException::notFound('Group "999" not found.'));
        $this->groupResource->expects(self::never())->method('save');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        $this->expectException(ScimException::class);
        $this->provisioner->replace('999', ['displayName' => 'Ops']);
    }

    public function testDeleteRemovesMembershipAndGroupThenSyncsFormerMembers(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('beginTransaction');
        $adapter->expects(self::once())->method('commit');
        $adapter->expects(self::never())->method('rollBack');
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->with(10)->willReturn([5, 6]);

        $calls = [];
        $this->memberResource->expects(self::once())->method('removeAllForGroup')->with(10)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'members';
            });
        $this->groupResource->expects(self::once())->method('delete')->with(10)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'group';
            });
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6])
            ->willReturnCallback(static function () use (&$calls): array {
                $calls[] = 'sync';
                return [];
            });

        $provisioner->delete('10');

        self::assertSame(['members', 'group', 'sync'], $calls);
    }

    public function testDeleteRollsBackWhenSyncFails(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::once())->method('beginTransaction');
        $adapter->expects(self::once())->method('rollBack');
        $adapter->expects(self::never())->method('commit');
        $provisioner = $this->createProvisioner($adapter);

        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->willReturn([5]);
        $this->memberResource->expects(self::once())->method('removeAllForGroup');
        $this->groupResource->expects(self::once())->method('delete');
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')
            ->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $provisioner->delete('10');
    }

    public function testDeletePropagatesNotFound(): void
    {
        $this->repository->method('getById')
            ->willThrowException(ScimException::notFound('Group "999" not found.'));
        $this->memberResource->expects(self::never())->method('removeAllForGroup');
        $this->groupResource->expects(self::never())->method('delete');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->provisioner->delete('999');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }
    }

    /** A provisioner whose audit logger expects exactly one event with the given context. */
    private function provisionerExpectingAudit(string $event, array $context): GroupProvisioner
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('log')->with($event, $context);

        return $this->createProvisioner($this->createStub(AdapterInterface::class), $auditLogger);
    }

    public function testCreateLogsGroupCreateWithAddedMembers(): void
    {
        $this->mapperReturns('Admins', null, [5, 7]);
        $this->groupResource->expects(self::never())->method('findConflictId');
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 7]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 7]);
        $this->saveAssignsId(10);

        $this->provisionerExpectingAudit('group.create', [
            'group_id' => 10,
            'displayName' => 'Admins',
            'members_added' => [5, 7],
            'members_removed' => [],
        ])->create(['displayName' => 'Admins']);
    }

    public function testPatchLogsGroupPatchWithMemberDiff(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->willReturn([5, 6]);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->patcher->method('apply')->willReturnCallback(
            static function (ScimGroup $g, array &$members): void {
                $members = [5, 8];
            }
        );
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save');
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 8]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6, 5, 8]);

        $this->provisionerExpectingAudit('group.patch', [
            'group_id' => 10,
            'displayName' => 'Admins',
            'members_added' => [8],
            'members_removed' => [6],
        ])->patch('10', ['Operations' => []]);
    }

    public function testReplaceLogsGroupReplaceWithMemberDiff(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->mapperReturns('Ops', null, [5, 8]);
        $this->memberResource->method('getUserIds')->willReturn([5, 6]);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save');
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 8]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6, 5, 8]);

        $this->provisionerExpectingAudit('group.replace', [
            'group_id' => 10,
            'displayName' => 'Ops',
            'members_added' => [8],
            'members_removed' => [6],
        ])->replace('10', ['displayName' => 'Ops']);
    }

    public function testDeleteLogsGroupDeleteWithRemovedMembers(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->willReturn([5, 6]);
        $this->memberResource->expects(self::once())->method('removeAllForGroup')->with(10);
        $this->groupResource->expects(self::once())->method('delete')->with(10);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5, 6]);

        $this->provisionerExpectingAudit('group.delete', [
            'group_id' => 10,
            'displayName' => 'Admins',
            'members_added' => [],
            'members_removed' => [5, 6],
        ])->delete('10');
    }

    public function testRolledBackDeleteLogsNothing(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->willReturn([5]);
        $this->memberResource->expects(self::once())->method('removeAllForGroup')->with(10);
        $this->groupResource->expects(self::once())->method('delete')->with(10);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')
            ->willThrowException(new \RuntimeException('boom'));
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('log');

        $this->expectException(\RuntimeException::class);

        $this->createProvisioner($this->createStub(AdapterInterface::class), $auditLogger)->delete('10');
    }

    public function testDeleteReadsMembershipOnlyAfterTakingTheLock(): void
    {
        $calls = [];
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'lock';
                return true;
            });
        $lockManager->expects(self::once())->method('unlock')
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'unlock';
                return true;
            });
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->expects(self::once())->method('getUserIds')
            ->willReturnCallback(static function () use (&$calls): array {
                $calls[] = 'read';
                return [5];
            });
        $this->groupResource->expects(self::once())->method('delete')->with(10);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->with([5]);

        $this->createProvisioner($this->createStub(AdapterInterface::class), null, $lockManager)->delete('10');

        self::assertSame(['lock', 'read', 'unlock'], $calls);
    }

    public function testPatchIsRejectedWithoutTheLock(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $this->memberResource->expects(self::never())->method('getUserIds');
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->groupResource->expects(self::never())->method('save');
        $this->roleSynchronizer->expects(self::never())->method('syncUsers');

        try {
            $this->createProvisioner($this->createStub(AdapterInterface::class), null, $lockManager)
                ->patch('10', ['Operations' => []]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(500, $e->getHttpStatus());
        }
    }

    public function testRoleChangesAreLoggedAfterCommit(): void
    {
        $calls = [];
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('commit')->willReturnCallback(static function () use (&$calls, $adapter): AdapterInterface {
            $calls[] = 'commit';
            return $adapter;
        });
        $change = ['user_id' => 5, 'userName' => 'jdoe', 'from' => 2, 'to' => null];
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->willReturn([5]);
        $this->memberResource->expects(self::once())->method('removeAllForGroup')->with(10);
        $this->groupResource->expects(self::once())->method('delete')->with(10);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')->willReturn([$change]);
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::exactly(2))->method('log')->willReturnCallback(
            static function (string $event, array $context) use (&$calls, $change): void {
                $calls[] = $event;
                if ($event === 'role.change') {
                    self::assertSame($change, $context);
                }
            }
        );

        $this->createProvisioner($adapter, $auditLogger)->delete('10');

        self::assertSame(['commit', 'group.delete', 'role.change'], $calls);
    }

    public function testRolledBackPatchLogsNoRoleChange(): void
    {
        $this->repository->method('getById')->willReturn(new ScimGroup(10, 'Admins'));
        $this->memberResource->method('getUserIds')->willReturn([5, 6]);
        $this->memberResource->method('findMissingUserIds')->willReturn([]);
        $this->groupResource->method('findConflictId')->willReturn(null);
        $this->groupResource->expects(self::once())->method('save');
        $this->memberResource->expects(self::once())->method('setMembers')->with(10, [5, 6]);
        $this->roleSynchronizer->expects(self::once())->method('syncUsers')
            ->willThrowException(new \RuntimeException('boom'));
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('log');

        $this->expectException(\RuntimeException::class);
        $this->createProvisioner($this->createStub(AdapterInterface::class), $auditLogger)
            ->patch('10', ['Operations' => []]);
    }
}
