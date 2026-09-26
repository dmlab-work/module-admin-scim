<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Group\GroupProvisioner;
use DmLab\AdminScim\Model\Group\GroupResource;
use DmLab\AdminScim\Model\Group\GroupRoleSynchronizer;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupPatcher;
use DmLab\AdminScim\Model\Group\ScimGroupRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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

        $adapter = $this->createStub(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);

        $this->provisioner = new GroupProvisioner(
            $this->mapper,
            $this->groupResource,
            $this->repository,
            $this->patcher,
            $this->memberResource,
            $this->roleSynchronizer,
            $resource
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
        $this->groupResource->expects(self::exactly(2))->method('findConflictId')->willReturn(null);
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
        // Only the displayName probe runs when there is no externalId.
        $this->groupResource->expects(self::once())->method('findConflictId')
            ->with('display_name', 'Admins', null)->willReturn(null);
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

    public function testCreateThrowsConflictOnDuplicateDisplayName(): void
    {
        $this->mapperReturns('Admins', null, []);
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

    public function testPatchRechecksDisplayNameUniqueness(): void
    {
        $group = new ScimGroup(10, 'Renamed');
        $this->repository->method('getById')->willReturn($group);
        $this->memberResource->expects(self::once())->method('getUserIds')->willReturn([]);
        $this->memberResource->expects(self::never())->method('setMembers');
        $this->patcher->method('apply');
        // The new displayName is held by another group (id excluded = 10).
        $this->groupResource->method('findConflictId')
            ->with('display_name', 'Renamed', 10)->willReturn(42);
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
}
