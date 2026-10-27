<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller\V2;

use DmLab\AdminScim\Controller\V2\Users;
use DmLab\AdminScim\Model\Audit\AuditLogger;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Config;
use DmLab\AdminScim\Model\Group\GroupRoleSynchronizer;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Mapping\MappingEngine;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use DmLab\AdminScim\Model\Response\ListResponseBuilder;
use DmLab\AdminScim\Model\User\FilterParser;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use DmLab\AdminScim\Model\User\ScimUserPatcher;
use DmLab\AdminScim\Model\User\ScimUserRepository;
use DmLab\AdminScim\Model\User\UserProvisioner;
use DmLab\AdminScim\Test\Unit\Doubles\AdminUserStore;
use DmLab\AdminScim\Test\Unit\Doubles\InMemoryScimTrait;
use DmLab\AdminScim\Test\Unit\Doubles\ScimResponseCapture;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Security\Model\ResourceModel\AdminSessionInfo as AdminSessionInfoResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Acceptance test: the full User provisioning lifecycle a strict-RFC SCIM client
 * drives — create → read by id → list with filter → PUT replace → PATCH
 * deactivate → PATCH reactivate — exercised end-to-end through the real
 * {@see Users} controller and the real provisioner/repository/patcher/mapper
 * stack against an in-memory `admin_user` store (Task 9 acceptance criteria).
 */
class UserLifecycleTest extends TestCase
{
    use InMemoryScimTrait;

    private const BASE = '/admin-scim/v2/Users';

    private const DEFAULT_ROLE_ID = '3';

    /** @var AdminUserStore */
    private AdminUserStore $store;

    /** @var UserProvisioner */
    private UserProvisioner $provisioner;

    /** @var ScimUserRepository */
    private ScimUserRepository $repository;

    /** @var ScimUserMapper */
    private ScimUserMapper $mapper;

    protected function setUp(): void
    {
        $this->store = $this->createAdminUserStore();
        $urls = $this->createUrlBuilder();
        $this->mapper = new ScimUserMapper($urls);
        $patcher = new ScimUserPatcher($this->mapper);
        $this->repository = new ScimUserRepository(
            $this->store->userFactory,
            $this->store->userResource,
            $this->store->collectionFactory,
            new FilterParser()
        );
        $this->provisioner = new UserProvisioner(
            $this->mapper,
            $this->store->userFactory,
            $this->store->userResource,
            $this->store->collectionFactory,
            $this->store->random,
            $this->repository,
            $patcher,
            $this->makeSynchronizer(),
            $this->createStub(AuditLogger::class),
            $this->createWriteLock(),
            $this->createStub(AdminSessionInfoResource::class)
        );
    }

    /**
     * A real synchronizer with `default_role` set and no group pushes (Google-style IdP).
     */
    private function makeSynchronizer(): GroupRoleSynchronizer
    {
        $config = $this->createStub(Config::class);
        $config->method('getGroupRoleMap')->willReturn(['Admins' => '5']);
        $config->method('getDefaultRoleId')->willReturn(self::DEFAULT_ROLE_ID);

        $memberResource = $this->createStub(MemberResource::class);
        $memberResource->method('getGroupDisplayNamesForUser')->willReturn([]);

        $roleFactory = $this->createStub(RoleFactory::class);
        $roleFactory->method('create')->willReturnCallback(function (): Role {
            $role = $this->createStub(Role::class);
            $role->method('load')->willReturnSelf();
            $role->method('getId')->willReturn(self::DEFAULT_ROLE_ID);

            return $role;
        });

        return new GroupRoleSynchronizer(
            $config,
            new MappingEngine(),
            $memberResource,
            $this->store->userFactory,
            $this->store->userResource,
            $roleFactory
        );
    }

    public function testFullUserLifecycle(): void
    {
        // 1. Create — a strict-RFC SCIM User → 201 with a server-assigned id.
        [$status, $created] = $this->dispatch('POST', self::BASE, json_encode([
            'schemas' => [ScimUserMapper::SCHEMA_USER],
            'userName' => 'jane.doe@example.com',
            'externalId' => 'okta-1',
            'name' => ['givenName' => 'Jane', 'familyName' => 'Doe'],
            'emails' => [['value' => 'jane.doe@example.com', 'primary' => true]],
        ]));
        self::assertSame(201, $status);
        self::assertSame('Jane', $created['name']['givenName']);
        self::assertTrue($created['active']);
        $id = $created['id'];
        self::assertNotSame('', $id);

        // 2. Read by id — the created resource round-trips.
        [$status, $read] = $this->dispatch('GET', self::BASE . '/' . $id);
        self::assertSame(200, $status);
        self::assertSame('jane.doe@example.com', $read['userName']);
        self::assertSame('okta-1', $read['externalId']);

        // 3. List with the supported filter subset — matches the created user.
        [$status, $list] = $this->dispatch('GET', self::BASE, '', ['filter' => 'userName eq "jane.doe@example.com"']);
        self::assertSame(200, $status);
        self::assertSame(1, $list['totalResults']);
        self::assertSame($id, $list['Resources'][0]['id']);

        // A non-matching filter yields an empty page (still a valid ListResponse).
        [, $empty] = $this->dispatch('GET', self::BASE, '', ['filter' => 'externalId eq "nope"']);
        self::assertSame(0, $empty['totalResults']);
        self::assertSame([], $empty['Resources']);

        // 4. PUT replace — the whole resource is overwritten.
        [$status, $replaced] = $this->dispatch('PUT', self::BASE . '/' . $id, json_encode([
            'schemas' => [ScimUserMapper::SCHEMA_USER],
            'userName' => 'jane.roe@example.com',
            'externalId' => 'okta-1',
            'name' => ['givenName' => 'Jane', 'familyName' => 'Roe'],
            'emails' => [['value' => 'jane.roe@example.com', 'primary' => true]],
        ]));
        self::assertSame(200, $status);
        self::assertSame('jane.roe@example.com', $replaced['userName']);
        self::assertSame('Roe', $replaced['name']['familyName']);

        // 5. PATCH deactivate — active=false disables (deprovision), not delete.
        [$status, $disabled] = $this->dispatch('PATCH', self::BASE . '/' . $id, json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
        ]));
        self::assertSame(200, $status);
        self::assertFalse($disabled['active']);
        self::assertSame(0, (int)$this->store->rows[(int)$id]['is_active']);

        // 6. PATCH reactivate — active=true re-enables the same account.
        [$status, $reenabled] = $this->dispatch('PATCH', self::BASE . '/' . $id, json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'add', 'path' => 'active', 'value' => true]],
        ]));
        self::assertSame(200, $status);
        self::assertTrue($reenabled['active']);
        self::assertSame(1, (int)$this->store->rows[(int)$id]['is_active']);

        // The lifecycle never forked a second account.
        self::assertCount(1, $this->store->rows);
    }

    public function testCreateWithoutGroupPushAssignsDefaultRole(): void
    {
        [$status, $created] = $this->dispatch('POST', self::BASE, json_encode([
            'schemas' => [ScimUserMapper::SCHEMA_USER],
            'userName' => 'jane@example.com',
        ]));

        self::assertSame(201, $status);
        self::assertSame((int)self::DEFAULT_ROLE_ID, (int)$this->store->rows[(int)$created['id']]['role_id']);
    }

    public function testDeleteThenRecreateSameUserName(): void
    {
        $body = json_encode([
            'schemas' => [ScimUserMapper::SCHEMA_USER],
            'userName' => 'jane@example.com',
            'externalId' => 'okta-1',
        ]);
        [, $created] = $this->dispatch('POST', self::BASE, $body);
        $id = $created['id'];

        [$status, $document] = $this->dispatch('DELETE', self::BASE . '/' . $id);
        self::assertSame(204, $status);
        self::assertSame([], $document);
        self::assertArrayNotHasKey((int)$id, $this->store->rows);

        [$status] = $this->dispatch('GET', self::BASE . '/' . $id);
        self::assertSame(404, $status);

        [$status] = $this->dispatch('POST', self::BASE, $body);
        self::assertSame(201, $status);
        self::assertCount(1, $this->store->rows);
    }

    public function testDuplicateUserNameIsRejectedWithConflict(): void
    {
        $body = json_encode(['userName' => 'dup@example.com']);
        [$first] = $this->dispatch('POST', self::BASE, $body);
        self::assertSame(201, $first);

        [$status, $error] = $this->dispatch('POST', self::BASE, $body);
        self::assertSame(409, $status);
        self::assertSame('uniqueness', $error['scimType']);
    }

    public function testLocalAdminIsInvisibleAndImmutable(): void
    {
        $local = $this->seedLocalAdmin();

        [$status] = $this->dispatch('GET', self::BASE . '/7');
        self::assertSame(404, $status);

        [, $list] = $this->dispatch('GET', self::BASE);
        self::assertSame(0, $list['totalResults']);
        [, $filtered] = $this->dispatch('GET', self::BASE, '', ['filter' => 'userName eq "local.admin"']);
        self::assertSame(0, $filtered['totalResults']);

        [$status] = $this->dispatch('PUT', self::BASE . '/7', json_encode(['userName' => 'hijack@example.com']));
        self::assertSame(404, $status);

        [$status] = $this->dispatch('PATCH', self::BASE . '/7', json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
        ]));
        self::assertSame(404, $status);

        [$status] = $this->dispatch('DELETE', self::BASE . '/7');
        self::assertSame(404, $status);

        self::assertSame($local, $this->store->rows[7]);
    }

    public function testCreateCollidingWithLocalAdminIsConflictWithoutAdoption(): void
    {
        $local = $this->seedLocalAdmin();

        [$status, $error] = $this->dispatch('POST', self::BASE, json_encode([
            'userName' => 'local.admin',
            'emails' => [['value' => 'other@example.com', 'primary' => true]],
        ]));

        self::assertSame(409, $status);
        self::assertSame('uniqueness', $error['scimType']);
        self::assertSame($local, $this->store->rows[7]);
        self::assertCount(1, $this->store->rows);
    }

    public function testCreateFlagsManagedAndRemovingExternalIdKeepsIt(): void
    {
        [, $created] = $this->dispatch('POST', self::BASE, json_encode([
            'userName' => 'jane@example.com',
            'externalId' => 'okta-1',
        ]));
        $id = (int)$created['id'];
        self::assertSame(1, $this->store->rows[$id][ScimUserMapper::MANAGED_FIELD]);

        [$status] = $this->dispatch('PATCH', self::BASE . '/' . $id, json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'remove', 'path' => 'externalId']],
        ]));
        self::assertSame(200, $status);
        self::assertNull($this->store->rows[$id][ScimUserMapper::EXTERNAL_ID_FIELD]);
        self::assertSame(1, $this->store->rows[$id][ScimUserMapper::MANAGED_FIELD]);

        [$status] = $this->dispatch('GET', self::BASE . '/' . $id);
        self::assertSame(200, $status);
    }

    /**
     * Seed a locally created (non-SCIM) admin with id 7.
     *
     * @return array<string,mixed> the seeded row
     */
    private function seedLocalAdmin(): array
    {
        $this->store->autoId = 7;
        $this->store->rows[7] = [
            'user_id' => 7,
            'username' => 'local.admin',
            'email' => 'local@example.com',
            'firstname' => 'Local',
            'lastname' => 'Admin',
            'is_active' => 1,
            ScimUserMapper::MANAGED_FIELD => 0,
        ];

        return $this->store->rows[7];
    }

    /**
     * Dispatch one SCIM request through a fresh controller against the shared store.
     *
     * @param string $method
     * @param string $path
     * @param string $body
     * @param array<string,string> $params
     * @return array{0:int,1:array<string,mixed>}
     */
    private function dispatch(string $method, string $path, string $body = '', array $params = []): array
    {
        $capture = new ScimResponseCapture();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');

        $controller = new Users(
            $this->scimRequest($method, $path, $body, $params),
            $this->createStub(BearerTokenAuthenticator::class),
            $this->createCapturingResponse($capture),
            $logger,
            $this->provisioner,
            $this->repository,
            $this->mapper,
            new ListResponseBuilder(),
            new Json(),
            new RequestNormalizerChain($this->createStub(Config::class))
        );
        $controller->execute();

        return [$capture->status, $capture->document()];
    }
}
