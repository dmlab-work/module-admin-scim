<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Group;

use MageDevGroup\AdminScim\Exception\ScimException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Provisions SCIM Groups and keeps admin ACL roles in step with their membership.
 *
 * Write side of Group provisioning: create (POST) and PATCH (add/replace/remove of
 * displayName, externalId and members). `displayName` and `externalId` are unique
 * — a collision is a SCIM `409` `uniqueness` (RFC 7644 §3.3). Every membership
 * change re-derives the affected admins' ACL role via {@see GroupRoleSynchronizer}
 * so assigning/unassigning a group grants/revokes admin access with no login.
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
     */
    public function __construct(
        private readonly ScimGroupMapper $mapper,
        private readonly GroupResource $groupResource,
        private readonly ScimGroupRepository $repository,
        private readonly ScimGroupPatcher $patcher,
        private readonly MemberResource $memberResource,
        private readonly GroupRoleSynchronizer $roleSynchronizer,
        private readonly ResourceConnection $resource
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
        $displayName = $this->mapper->extractDisplayName($payload);
        $externalId = $this->mapper->extractExternalId($payload);
        $memberIds = $this->mapper->extractMemberIds($payload);

        $this->assertUnique('display_name', $displayName, 'A group with this displayName already exists.');
        if ($externalId !== null) {
            $this->assertUnique('external_id', $externalId, 'A group with this externalId already exists.');
        }
        $this->assertMembersExist($memberIds);

        $group = new ScimGroup(null, $displayName, $externalId);

        // Group row, membership and role sync are one unit: a mid-sequence failure
        // must not leave a persisted group whose retry then collides on uniqueness.
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->groupResource->save($group);
            $this->memberResource->setMembers((int)$group->groupId, $memberIds);
            $this->roleSynchronizer->syncUsers($memberIds);
            $connection->commit();
        } catch (DuplicateException $e) {
            $connection->rollBack();
            throw ScimException::conflict('A group with this displayName or externalId already exists.');
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $group;
    }

    /**
     * Apply a SCIM PatchOp to an existing group (PATCH).
     *
     * Attribute and membership changes are applied by {@see ScimGroupPatcher};
     * uniqueness is re-checked against every *other* group. Every admin whose
     * membership changed has its ACL role re-derived.
     *
     * @param string $id the SCIM resource id (magedevgroup_scim_group.group_id)
     * @param array<string,mixed> $body decoded PatchOp resource
     * @return ScimGroup the updated group
     * @throws ScimException 404 when absent, 400 on a malformed op, 409 on a
     *         displayName or externalId already held by another group
     */
    public function patch(string $id, array $body): ScimGroup
    {
        $group = $this->repository->getById($id);
        $groupId = (int)$group->groupId;

        $before = $this->memberResource->getUserIds($groupId);
        $members = $before;
        $this->patcher->apply($group, $members, $body);
        $this->assertMembersExist($members);

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

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->groupResource->save($group);
            $this->memberResource->setMembers($groupId, $members);
            $this->roleSynchronizer->syncUsers([...$before, ...$members]);
            $connection->commit();
        } catch (DuplicateException $e) {
            $connection->rollBack();
            throw ScimException::conflict('A group with this displayName or externalId already exists.');
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $group;
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
