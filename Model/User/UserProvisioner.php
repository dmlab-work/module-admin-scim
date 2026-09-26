<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
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
 * Write side of User provisioning: create, PUT (full replace) and PATCH
 * (partial ops, incl. `active=false` deprovisioning / `active=true`
 * reactivation). An admin is keyed on the SCIM `userName` (mapped to
 * `admin_user.username`) and, when supplied, linked to its IdP resource via the
 * `externalId` column. Both must be unique — a collision is a SCIM `409`
 * `uniqueness` per RFC 7644 §3.3 — so a replayed create never silently forks a
 * second account and an update never steals another admin's identifier. The
 * account password is random: login is via SSO, never this credential, but a
 * valid value is required to persist a new row.
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
     */
    public function __construct(
        private readonly ScimUserMapper $mapper,
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly Random $random,
        private readonly ScimUserRepository $repository,
        private readonly ScimUserPatcher $patcher
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
        ]);
        $this->persist($user);

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
        $this->persist($user);

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
        $user = $this->repository->getById($id);
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
        $this->persist($user);

        return $user;
    }

    /**
     * Persist the admin user, mapping a concurrent duplicate-key insert to a 409.
     *
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
     * @throws ScimException 409 `uniqueness` on a duplicate-key violation
     */
    private function persist(User $user): void
    {
        try {
            $this->userResource->save($user);
        } catch (AlreadyExistsException | DuplicateException $e) {
            throw ScimException::conflict('A user with this userName or externalId already exists.');
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
