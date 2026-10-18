<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\Group\GroupRoleSynchronizer;
use DmLab\AdminScim\Model\WriteLock;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Math\Random;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Provisions Magento `admin_user` rows from SCIM User payloads.
 *
 * Write side of User provisioning: create, PUT (full replace), PATCH
 * (partial ops, incl. `active=false` deprovisioning / `active=true`
 * reactivation) and DELETE (hard delete). An admin is keyed on the SCIM `userName` (mapped to
 * `admin_user.username`) and, when supplied, linked to its IdP resource via the
 * `externalId` column. Both must be unique — a collision is a SCIM `409`
 * `uniqueness` per RFC 7644 §3.3 — so a replayed create never silently forks a
 * second account and an update never steals another admin's identifier. The
 * account password is random: login is via SSO, never this credential, but a
 * valid value is required to persist a new row. Created admins are flagged
 * SCIM-managed; PUT/PATCH/DELETE reach only managed admins (via the repository), while
 * uniqueness is checked against every admin, so a clash with a local one is 409.
 * Every write re-syncs the admin's role, so one with no mapped group gets
 * `default_role` even when the IdP pushes no groups. Every write runs under the
 * {@see WriteLock} and is audit-logged after it commits.
 */
class UserProvisioner
{
    /**
     * @param ScimUserMapper $mapper
     * @param UserFactory $userFactory
     * @param UserResource $userResource
     * @param UserCollectionFactory $userCollectionFactory
     * @param Random $random
     * @param ScimUserRepository $repository
     * @param ScimUserPatcher $patcher
     * @param GroupRoleSynchronizer $roleSynchronizer
     * @param AuditLogger $auditLogger
     * @param WriteLock $writeLock
     */
    public function __construct(
        private readonly ScimUserMapper $mapper,
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly Random $random,
        private readonly ScimUserRepository $repository,
        private readonly ScimUserPatcher $patcher,
        private readonly GroupRoleSynchronizer $roleSynchronizer,
        private readonly AuditLogger $auditLogger,
        private readonly WriteLock $writeLock
    ) {
    }

    /**
     * Create and persist an admin user from a SCIM User payload.
     *
     * @param array<string,mixed> $payload decoded SCIM User resource
     * @return User the created admin user (persisted, id populated)
     * @throws ScimException 400 on missing/invalid attributes, 409 on a userName
     *         or externalId that already exists
     */
    public function create(array $payload): User
    {
        return $this->writeLock->run(fn (): User => $this->doCreate($payload));
    }

    /**
     * Create under the write lock.
     *
     * @param array<string,mixed> $payload
     */
    private function doCreate(array $payload): User
    {
        $userName = $this->mapper->extractUserName($payload);
        $email = $this->mapper->extractEmail($payload);
        [$firstName, $lastName] = $this->mapper->extractName($payload, $userName);
        $externalId = $this->mapper->extractExternalId($payload);

        $this->assertUnique('username', $userName, 'A user with this userName already exists.');
        $this->assertUnique('email', $email, 'A user with this email already exists.');
        if ($externalId !== null) {
            $this->assertUnique(
                ScimUserMapper::EXTERNAL_ID_FIELD,
                $externalId,
                'A user with this externalId already exists.'
            );
        }

        $user = $this->userFactory->create();
        $user->setData([
            'username' => $userName,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $email,
            'password' => $this->generatePassword(),
            'is_active' => $this->mapper->extractActive($payload) ? 1 : 0,
            'interface_locale' => 'en_US',
            ScimUserMapper::EXTERNAL_ID_FIELD => $externalId,
            ScimUserMapper::MANAGED_FIELD => 1,
        ]);
        $roleChanges = $this->write($user);
        $this->auditLogger->log('user.create', $this->auditContext($user));
        $this->logRoleChanges($roleChanges);

        return $user;
    }

    /**
     * Replace an existing admin user from a full SCIM User payload (PUT).
     *
     * Every provisioned attribute is overwritten from the payload; an omitted
     * `active` means active per RFC. `userName`/`externalId` uniqueness is
     * re-checked against every *other* admin so a rename never collides.
     *
     * @param string $id the SCIM resource id (admin_user.user_id)
     * @param array<string,mixed> $payload decoded SCIM User resource
     * @return User the updated admin user
     * @throws ScimException 404 when absent, 400 on invalid attributes, 409 on a
     *         userName or externalId already held by another admin
     */
    public function replace(string $id, array $payload): User
    {
        return $this->writeLock->run(fn (): User => $this->doReplace($id, $payload));
    }

    /**
     * Replace under the write lock.
     *
     * @param string $id
     * @param array<string,mixed> $payload
     */
    private function doReplace(string $id, array $payload): User
    {
        $user = $this->repository->getById($id);

        $userName = $this->mapper->extractUserName($payload);
        $email = $this->mapper->extractEmail($payload);
        [$firstName, $lastName] = $this->mapper->extractName($payload, $userName);
        $externalId = $this->mapper->extractExternalId($payload);

        $currentId = (int)$user->getId();
        $this->assertUnique('username', $userName, 'A user with this userName already exists.', $currentId);
        $this->assertUnique('email', $email, 'A user with this email already exists.', $currentId);
        if ($externalId !== null) {
            $this->assertUnique(
                ScimUserMapper::EXTERNAL_ID_FIELD,
                $externalId,
                'A user with this externalId already exists.',
                $currentId
            );
        }

        $user->addData([
            'username' => $userName,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $email,
            'is_active' => $this->mapper->extractActive($payload) ? 1 : 0,
            ScimUserMapper::EXTERNAL_ID_FIELD => $externalId,
        ]);
        $roleChanges = $this->write($user);
        $this->auditLogger->log('user.replace', $this->auditContext($user));
        $this->logRoleChanges($roleChanges);

        return $user;
    }

    /**
     * Apply a SCIM PatchOp to an existing admin user (PATCH).
     *
     * Operations are applied by {@see ScimUserPatcher}; `active=false` disables
     * the account (deprovision) and `active=true` re-enables it. Uniqueness is
     * re-checked afterwards so a patched `userName`/`externalId` never collides
     * with another admin.
     *
     * @param string $id the SCIM resource id (admin_user.user_id)
     * @param array<string,mixed> $body decoded PatchOp resource
     * @return User the updated admin user
     * @throws ScimException 404 when absent, 400 on a malformed op, 409 on a
     *         userName or externalId already held by another admin
     */
    public function patch(string $id, array $body): User
    {
        return $this->writeLock->run(fn (): User => $this->doPatch($id, $body));
    }

    /**
     * Patch under the write lock.
     *
     * @param string $id
     * @param array<string,mixed> $body
     */
    private function doPatch(string $id, array $body): User
    {
        $user = $this->repository->getById($id);
        $activeBefore = (bool)(int)$user->getData('is_active');
        $this->patcher->apply($user, $body);

        $currentId = (int)$user->getId();
        $this->assertUnique(
            'username',
            (string)$user->getData('username'),
            'A user with this userName already exists.',
            $currentId
        );
        $this->assertUnique(
            'email',
            (string)$user->getData('email'),
            'A user with this email already exists.',
            $currentId
        );
        $externalId = $user->getData(ScimUserMapper::EXTERNAL_ID_FIELD);
        if (is_string($externalId) && $externalId !== '') {
            $this->assertUnique(
                ScimUserMapper::EXTERNAL_ID_FIELD,
                $externalId,
                'A user with this externalId already exists.',
                $currentId
            );
        }
        $roleChanges = $this->write($user);
        $this->auditLogger->log('user.patch', $this->auditContext($user) + [
            'active_before' => $activeBefore,
            'active_after' => (bool)(int)$user->getData('is_active'),
        ]);
        $this->logRoleChanges($roleChanges);

        return $user;
    }

    /**
     * Hard-delete a SCIM-managed admin user (DELETE).
     *
     * A hard delete so a later GET is a 404 (RFC 7644 §3.6) and re-provisioning
     * the same `userName` does not 409 on a leftover row. The core resource model
     * also drops the admin's role rows; group membership rows cascade via FK.
     *
     * @param string $id the SCIM resource id (admin_user.user_id)
     * @throws ScimException 404 when absent or not SCIM-managed, 500 when the
     *         resource model reports a failed delete
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
        $user = $this->repository->getById($id);
        $context = $this->auditContext($user);
        // The core resource model rolls back and returns false instead of throwing.
        if ($this->userResource->delete($user) === false) {
            throw ScimException::internal('Failed to delete the user.');
        }
        $this->auditLogger->log('user.delete', $context);
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
     * Audit context identifying the admin user.
     *
     * @param User $user
     * @return array<string,mixed>
     */
    private function auditContext(User $user): array
    {
        return ['user_id' => (int)$user->getId(), 'userName' => (string)$user->getData('username')];
    }

    /**
     * Persist the admin user and re-sync its role as one unit.
     *
     * A failed role sync must not leave a saved admin whose retry then 409s.
     * A concurrent duplicate-key insert maps to a 409.
     * The {@see assertUnique} pre-checks catch the common collision, but a request
     * that races another can still slip a duplicate `username`/`externalId` past
     * them and hit a DB unique index (core `ADMIN_USER_USERNAME`, this module's
     * `DMLAB_ADMIN_SCIM_EXTERNAL_ID`). Convert that into the same SCIM `409`
     * `uniqueness` instead of leaking a generic 500. `admin_user.email` has no
     * unique index, so its uniqueness rests on the pre-check alone (best-effort
     * under a race); email is not a login key, so a duplicate there is harmless.
     * The resource model's
     * {@see \Magento\Framework\Model\ResourceModel\Db\AbstractDb::save()} wraps the
     * adapter `DuplicateException` in `AlreadyExistsException` (and its own
     * `_checkUnique` throws the same), so both must be caught.
     *
     * @param User $user
     * @return array<int,array<string,mixed>> committed `role.change` audit contexts
     * @throws ScimException 409 `uniqueness` on a duplicate-key violation
     */
    private function write(User $user): array
    {
        $this->userResource->beginTransaction();
        try {
            $this->userResource->save($user);
            $roleChanges = $this->roleSynchronizer->syncUsers([(int)$user->getId()]);
            $this->userResource->commit();

            return $roleChanges;
        } catch (AlreadyExistsException | DuplicateException $e) {
            $this->userResource->rollBack();
            throw ScimException::conflict('A user with this userName or externalId already exists.');
        } catch (\Throwable $e) {
            $this->userResource->rollBack();
            throw $e;
        }
    }

    /**
     * Reject a value that another admin user already carries in the given column.
     *
     * @param string $field
     * @param string $value
     * @param string $detail SCIM error detail on collision
     * @param int|null $excludeId admin_user.user_id to exclude (the row being updated)
     * @throws ScimException 409 `uniqueness` when the value is taken
     */
    private function assertUnique(string $field, string $value, string $detail, ?int $excludeId = null): void
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter($field, $value);
        if ($excludeId !== null) {
            $collection->addFieldToFilter('user_id', ['neq' => $excludeId]);
        }
        $collection->setPageSize(1);

        /** @var User $existing */
        $existing = $collection->getFirstItem();
        if ($existing->getId()) {
            throw ScimException::conflict($detail);
        }
    }

    /**
     * Random password satisfying Magento's admin rules (letters + digits). Never
     * used for login — SCIM-provisioned admins sign in via SSO — but required to
     * persist the user.
     */
    private function generatePassword(): string
    {
        return $this->random->getRandomString(32) . 'a1';
    }
}
