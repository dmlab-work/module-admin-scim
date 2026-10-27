<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\User\LastAdminGuard;
use DmLab\AdminScim\Model\WriteLock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Provisions SCIM Groups and keeps admin ACL roles in step with their membership.
 *
 * Write side of Group provisioning: create (POST), replace (PUT), PATCH
 * (add/replace/remove of displayName, externalId and members) and delete (DELETE).
 * `displayName` and `externalId` are unique — a collision is a SCIM `409`
 * `uniqueness` (RFC 7644 §3.3). Every membership change re-derives the affected
 * admins' ACL role via {@see GroupRoleSynchronizer} so assigning/unassigning a
 * group grants/revokes admin access with no login, but never strips the last usable
 * admin's role ({@see LastAdminGuard}). Every write runs under the
 * {@see WriteLock} and is audit-logged after it commits.
 */
class GroupProvisioner
{
    /**
     * @param ScimGroupMapper $mapper
     * @param GroupResource $groupResource
     * @param ScimGroupRepository $repository
     * @param ScimGroupPatcher $patcher
     * @param MemberResource $memberResource
     * @param GroupRoleSynchronizer $roleSynchronizer
     * @param ResourceConnection $resource
     * @param AuditLogger $auditLogger
     * @param WriteLock $writeLock
     * @param LastAdminGuard $lastAdminGuard
     */
    public function __construct(
        private readonly ScimGroupMapper $mapper,
        private readonly GroupResource $groupResource,
        private readonly ScimGroupRepository $repository,
        private readonly ScimGroupPatcher $patcher,
        private readonly MemberResource $memberResource,
        private readonly GroupRoleSynchronizer $roleSynchronizer,
        private readonly ResourceConnection $resource,
        private readonly AuditLogger $auditLogger,
        private readonly WriteLock $writeLock,
        private readonly LastAdminGuard $lastAdminGuard
    ) {
    }

    /**
     * Create and persist a group from a SCIM Group payload, applying membership.
     *
     * @param array<string,mixed> $payload decoded SCIM Group resource
     * @return ScimGroup the created group (persisted, id populated)
     * @throws ScimException 400 on invalid attributes, 409 on a displayName or
     *         externalId that already exists
     */
    public function create(array $payload): ScimGroup
    {
        return $this->writeLock->run(fn (): ScimGroup => $this->doCreate($payload));
    }

    /**
     * Create under the write lock.
     *
     * @param array<string,mixed> $payload
     */
    private function doCreate(array $payload): ScimGroup
    {
        $displayName = $this->mapper->extractDisplayName($payload);
        $externalId = $this->mapper->extractExternalId($payload);
        $memberIds = $this->mapper->extractMemberIds($payload);

        $this->assertUnique('display_name', $displayName, 'A group with this displayName already exists.');
        if ($externalId !== null) {
            $this->assertUnique('external_id', $externalId, 'A group with this externalId already exists.');
        }
        $this->assertMembersExist($memberIds);

        $group = new ScimGroup(null, $displayName, $externalId);
        $roleChanges = $this->write($group, $memberIds, $memberIds);
        $this->auditLogger->log('group.create', $this->auditContext($group, [], $memberIds));
        $this->logRoleChanges($roleChanges);

        return $group;
    }

    /**
     * Apply a SCIM PatchOp to an existing group (PATCH).
     *
     * Attribute and membership changes are applied by {@see ScimGroupPatcher};
     * uniqueness is re-checked against every *other* group. Every admin whose
     * membership changed has its ACL role re-derived.
     *
     * @param string $id the SCIM resource id (dmlab_scim_group.group_id)
     * @param array<string,mixed> $body decoded PatchOp resource
     * @return ScimGroup the updated group
     * @throws ScimException 404 when absent, 400 on a malformed op, 409 on a
     *         displayName or externalId already held by another group
     */
    public function patch(string $id, array $body): ScimGroup
    {
        return $this->writeLock->run(fn (): ScimGroup => $this->doPatch($id, $body));
    }

    /**
     * Patch under the write lock.
     *
     * @param string $id
     * @param array<string,mixed> $body
     */
    private function doPatch(string $id, array $body): ScimGroup
    {
        $group = $this->repository->getById($id);
        $groupId = (int)$group->groupId;

        $before = $this->memberResource->getUserIds($groupId);
        $members = $before;
        $this->patcher->apply($group, $members, $body);
        // Only new ids: a stale pre-0.2.0 row naming an unmanaged admin must not block every PATCH.
        $this->assertMembersExist(array_diff($members, $before));

        $this->assertUnique(
            'display_name',
            $group->displayName,
            'A group with this displayName already exists.',
            $groupId
        );
        if ($group->externalId !== null && $group->externalId !== '') {
            $this->assertUnique(
                'external_id',
                $group->externalId,
                'A group with this externalId already exists.',
                $groupId
            );
        }

        $roleChanges = $this->write($group, $members, [...$before, ...$members]);
        $this->auditLogger->log('group.patch', $this->auditContext($group, $before, $members));
        $this->logRoleChanges($roleChanges);

        return $group;
    }

    /**
     * Replace a group's displayName, externalId and members (PUT).
     *
     * Every admin in the old or new membership has its ACL role re-derived.
     *
     * @param string $id the SCIM resource id (dmlab_scim_group.group_id)
     * @param array<string,mixed> $payload decoded SCIM Group resource
     * @return ScimGroup the replaced group
     * @throws ScimException 404 when absent, 400 on invalid attributes or unknown
     *         members, 409 on a displayName or externalId held by another group
     */
    public function replace(string $id, array $payload): ScimGroup
    {
        return $this->writeLock->run(fn (): ScimGroup => $this->doReplace($id, $payload));
    }

    /**
     * Replace under the write lock.
     *
     * @param string $id
     * @param array<string,mixed> $payload
     */
    private function doReplace(string $id, array $payload): ScimGroup
    {
        $group = $this->repository->getById($id);
        $groupId = (int)$group->groupId;

        $displayName = $this->mapper->extractDisplayName($payload);
        $externalId = $this->mapper->extractExternalId($payload);
        $memberIds = $this->mapper->extractMemberIds($payload);

        $this->assertUnique('display_name', $displayName, 'A group with this displayName already exists.', $groupId);
        if ($externalId !== null) {
            $this->assertUnique(
                'external_id',
                $externalId,
                'A group with this externalId already exists.',
                $groupId
            );
        }
        $this->assertMembersExist($memberIds);

        $before = $this->memberResource->getUserIds($groupId);
        $group->displayName = $displayName;
        $group->externalId = $externalId;

        $roleChanges = $this->write($group, $memberIds, [...$before, ...$memberIds]);
        $this->auditLogger->log('group.replace', $this->auditContext($group, $before, $memberIds));
        $this->logRoleChanges($roleChanges);

        return $group;
    }

    /**
     * Delete a group (DELETE); its former members lose the group's role.
     *
     * @param string $id the SCIM resource id (dmlab_scim_group.group_id)
     * @throws ScimException 404 when absent
     */
    public function delete(string $id): void
    {
        $this->writeLock->run(fn () => $this->doDelete($id));
    }

    /**
     * Delete under the write lock.
     *
     * @param string $id
     */
    private function doDelete(string $id): void
    {
        $group = $this->repository->getById($id);
        $groupId = (int)$group->groupId;
        $before = $this->memberResource->getUserIds($groupId);

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $usableBefore = $this->lastAdminGuard->countUsable();
            $this->memberResource->removeAllForGroup($groupId);
            $this->groupResource->delete($groupId);
            $roleChanges = $this->roleSynchronizer->syncUsers($before);
            $this->lastAdminGuard->assertUsableAdminRemains($usableBefore);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->auditLogger->log('group.delete', $this->auditContext($group, $before, []));
        $this->logRoleChanges($roleChanges);
    }

    /**
     * Save the group, set its members and re-sync roles as one unit.
     *
     * A mid-sequence failure must not leave a persisted group whose retry then
     * collides on uniqueness.
     *
     * @param ScimGroup $group
     * @param int[] $members the group's full membership after the write
     * @param int[] $syncIds admins whose role must be re-derived
     * @return array<int,array<string,mixed>> committed `role.change` audit contexts
     * @throws ScimException 409 on a duplicate displayName or externalId, 400 `mutability`
     *         when the role re-sync would leave no usable admin
     */
    private function write(ScimGroup $group, array $members, array $syncIds): array
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $usableBefore = $this->lastAdminGuard->countUsable();
            $this->groupResource->save($group);
            $this->memberResource->setMembers((int)$group->groupId, $members);
            $roleChanges = $this->roleSynchronizer->syncUsers($syncIds);
            $this->lastAdminGuard->assertUsableAdminRemains($usableBefore);
            $connection->commit();

            return $roleChanges;
        } catch (DuplicateException $e) {
            $connection->rollBack();
            throw ScimException::conflict('A group with this displayName or externalId already exists.');
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Audit committed role changes.
     *
     * @param array<int,array<string,mixed>> $roleChanges
     */
    private function logRoleChanges(array $roleChanges): void
    {
        foreach ($roleChanges as $change) {
            $this->auditLogger->log('role.change', $change);
        }
    }

    /**
     * Audit context identifying the group and its membership change.
     *
     * @param ScimGroup $group
     * @param int[] $before member user ids before the write
     * @param int[] $after member user ids after the write
     * @return array<string,mixed>
     */
    private function auditContext(ScimGroup $group, array $before, array $after): array
    {
        return [
            'group_id' => (int)$group->groupId,
            'displayName' => $group->displayName,
            'members_added' => array_values(array_unique(array_diff($after, $before))),
            'members_removed' => array_values(array_unique(array_diff($before, $after))),
        ];
    }

    /**
     * Reject members that reference a non-existent admin user.
     *
     * The membership table has an FK to `admin_user`; validating before any write
     * turns a well-formed but unknown id into a SCIM `400` instead of an opaque
     * FK-violation `500` and keeps a bad member from leaving a half-created group.
     *
     * @param int[] $memberIds
     * @throws ScimException 400 `invalidValue` when a member id has no admin user
     */
    private function assertMembersExist(array $memberIds): void
    {
        $missing = $this->memberResource->findMissingUserIds($memberIds);
        if ($missing !== []) {
            throw ScimException::badRequest(
                'Group members reference unknown user ids: ' . implode(', ', $missing) . '.',
                'invalidValue'
            );
        }
    }

    /**
     * Reject a value that another group already carries in the given column.
     *
     * @param string $field group table column
     * @param string $value
     * @param string $detail SCIM error detail on collision
     * @param int|null $excludeId group_id to exclude (the row being updated)
     * @throws ScimException 409 `uniqueness` when the value is taken
     */
    private function assertUnique(string $field, string $value, string $detail, ?int $excludeId = null): void
    {
        if ($this->groupResource->findConflictId($field, $value, $excludeId) !== null) {
            throw ScimException::conflict($detail);
        }
    }
}
