<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

use DmLab\AdminScim\Model\Config;
use DmLab\AdminScim\Model\Mapping\MappingEngine;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use Magento\Authorization\Model\RoleFactory;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Keeps an admin user's ACL role in sync with its SCIM group membership.
 *
 * Whenever membership changes, the affected admin's role is re-derived from *all*
 * the groups it now belongs to: the {@see MappingEngine} resolves the group
 * display names against the configured `displayName => role_id` rules (falling back
 * to the default role), and the first resolved role wins — a Magento admin holds a
 * single role. When nothing resolves the current role is stripped (deny), so
 * removing a user from its last mapped group revokes admin access. Admins not
 * SCIM-managed are never touched, even if a stale membership row names them.
 * Every role change, including a strip, is returned for the caller to audit-log
 * once its transaction commits, so a rolled-back change is never recorded.
 *
 * Mirrors admin-sso's login-time RoleAssigner, but the group source is SCIM
 * membership rather than an SSO identity — SCIM provisions the role in the
 * background, without a login.
 */
class GroupRoleSynchronizer
{
    /**
     * @param Config $config
     * @param MappingEngine $mappingEngine
     * @param MemberResource $memberResource
     * @param UserFactory $userFactory
     * @param UserResource $userResource
     * @param RoleFactory $roleFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly MappingEngine $mappingEngine,
        private readonly MemberResource $memberResource,
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly RoleFactory $roleFactory
    ) {
    }

    /**
     * Re-derive and apply the ACL role for each of the given admin users.
     *
     * @param int[] $userIds admin_user.user_id values whose membership changed
     * @return array<int,array<string,mixed>> `role.change` audit contexts of the applied changes
     */
    public function syncUsers(array $userIds): array
    {
        $rules = $this->config->getGroupRoleMap();
        $default = $this->config->getDefaultRoleId();

        $changes = [];
        foreach ($this->uniqueIds($userIds) as $userId) {
            $change = $this->syncUser($userId, $rules, $default);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * Apply the resolved role for one admin user.
     *
     * @param int $userId
     * @param array<string,string> $rules displayName => role_id
     * @param string|null $default fallback role id
     * @return array<string,mixed>|null the role change, or null when none was applied
     */
    private function syncUser(int $userId, array $rules, ?string $default): ?array
    {
        $user = $this->userFactory->create();
        $this->userResource->load($user, $userId);
        if (!$user->getId()) {
            // The admin was removed between the membership change and this sync.
            return null;
        }
        if ((int)$user->getData(ScimUserMapper::MANAGED_FIELD) !== 1) {
            return null;
        }

        $displayNames = $this->memberResource->getGroupDisplayNamesForUser($userId);
        $resolved = $this->mappingEngine->resolve($displayNames, $rules, $default);
        $roleId = $resolved[0] ?? null;
        $currentRoleId = (string)$user->getRole()->getId();

        if ($roleId !== null && $currentRoleId === $roleId) {
            // Already on the resolved role — nothing to do.
            return null;
        }

        // Fail closed: deny (strip the current role) when nothing resolves, or when
        // the resolved role id no longer exists. Losing the last mapped group must
        // revoke access, and a stale/mistyped group-role map or default must not
        // silently preserve the user's prior — possibly higher — role. A user that
        // already holds no role is left alone.
        if ($roleId === null || !$this->roleExists($roleId)) {
            if ($currentRoleId === '') {
                return null;
            }
            $user->setData('role_id', 0);
            $this->userResource->save($user);
            return $this->roleChange($user, $currentRoleId, null);
        }

        $user->setData('role_id', (int)$roleId);
        $this->userResource->save($user);
        return $this->roleChange($user, $currentRoleId, $roleId);
    }

    /**
     * Audit context of a role change; a null/empty role id means "no role".
     *
     * @param User $user
     * @param string $from
     * @param string|null $to
     * @return array<string,mixed>
     */
    private function roleChange(User $user, string $from, ?string $to): array
    {
        return [
            'user_id' => (int)$user->getId(),
            'userName' => (string)$user->getData('username'),
            'from' => $from === '' ? null : (int)$from,
            'to' => $to === null ? null : (int)$to,
        ];
    }

    /**
     * Whether an admin ACL role with the given id exists.
     *
     * @param string $roleId
     */
    private function roleExists(string $roleId): bool
    {
        return (bool)$this->roleFactory->create()->load($roleId)->getId();
    }

    /**
     * De-duplicated positive ids, order preserved.
     *
     * @param int[] $userIds
     * @return int[]
     */
    private function uniqueIds(array $userIds): array
    {
        $unique = [];
        foreach ($userIds as $userId) {
            $id = (int)$userId;
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }

        return array_values($unique);
    }
}
