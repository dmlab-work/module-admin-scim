<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Controller\V2;

use MageDevGroup\AdminScim\Controller\V2\Groups;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Config;
use MageDevGroup\AdminScim\Model\Group\GroupFilterParser;
use MageDevGroup\AdminScim\Model\Group\GroupProvisioner;
use MageDevGroup\AdminScim\Model\Group\GroupResource;
use MageDevGroup\AdminScim\Model\Group\GroupRoleSynchronizer;
use MageDevGroup\AdminScim\Model\Group\MemberResource;
use MageDevGroup\AdminScim\Model\Group\ScimGroup;
use MageDevGroup\AdminScim\Model\Group\ScimGroupMapper;
use MageDevGroup\AdminScim\Model\Group\ScimGroupPatcher;
use MageDevGroup\AdminScim\Model\Group\ScimGroupRepository;
use MageDevGroup\AdminScim\Model\Normalization\RequestNormalizerChain;
use MageDevGroup\AdminScim\Model\Response\ListResponseBuilder;
use MageDevGroup\AdminScim\Test\Unit\Doubles\AdminUserStore;
use MageDevGroup\AdminScim\Test\Unit\Doubles\InMemoryScimTrait;
use MageDevGroup\AdminScim\Test\Unit\Doubles\ScimResponseCapture;
use MageDevGroup\AdminScim\Model\Mapping\MappingEngine;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Acceptance test: SCIM Group provisioning drives an admin's ACL role — create a
 * group with a mapped displayName grants the role to its members; removing a
 * member revokes it. Exercised end-to-end through the real {@see Groups}
 * controller, {@see GroupProvisioner} and {@see GroupRoleSynchronizer} (real
 * the {@see MappingEngine}) against in-memory group/member/admin_user stores
 * (Task 9 acceptance criteria — the group→role step).
 */
class GroupRoleLifecycleTest extends TestCase
{
    use InMemoryScimTrait;

    private const BASE = '/admin-scim/v2/Groups';

    /** @var AdminUserStore */
    private AdminUserStore $store;

    /** @var GroupProvisioner */
    private GroupProvisioner $provisioner;

    /** @var ScimGroupRepository */
    private ScimGroupRepository $repository;

    /** @var ScimGroupMapper */
    private ScimGroupMapper $mapper;

    /** @var MemberResource */
    private MemberResource $memberResource;

    /** @var array<int,ScimGroup> persisted groups, keyed by group_id */
    private array $groups = [];

    /** @var array<int,int[]> membership, group_id => user ids */
    private array $members = [];

    /** @var int last assigned group auto-increment id */
    private int $groupAutoId = 0;

    protected function setUp(): void
    {
        $this->store = $this->createAdminUserStore();
        // Seed one provisioned admin (as if created via POST /Users); no role yet.
        $this->store->autoId = 1;
        $this->store->rows[1] = [
            'user_id' => 1,
            'username' => 'jane',
            'email' => 'jane@example.com',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'is_active' => 1,
        ];

        $urls = $this->createUrlBuilder();
        $this->mapper = new ScimGroupMapper($urls);
        $groupResource = $this->makeGroupResource();
        $this->memberResource = $this->makeMemberResource();
        $this->repository = new ScimGroupRepository($groupResource, new GroupFilterParser());

        $config = $this->createStub(Config::class);
        $config->method('getGroupRoleMap')->willReturn(['Admins' => '5']);
        $config->method('getDefaultRoleId')->willReturn(null);

        $synchronizer = new GroupRoleSynchronizer(
            $config,
            new MappingEngine(),
            $this->memberResource,
            $this->store->userFactory,
            $this->store->userResource,
            $this->makeRoleFactory()
        );

        $this->provisioner = new GroupProvisioner(
            $this->mapper,
            $groupResource,
            $this->repository,
            new ScimGroupPatcher($this->mapper),
            $this->memberResource,
            $synchronizer,
            $this->createResourceConnection()
        );
    }

    public function testGroupMembershipDrivesAdminRole(): void
    {
        // 1. Create a mapped group with the admin as a member → 201; role granted.
        [$status, $created] = $this->dispatch('POST', self::BASE, json_encode([
            'schemas' => [ScimGroupMapper::SCHEMA_GROUP],
            'displayName' => 'Admins',
            'externalId' => 'okta-grp-1',
            'members' => [['value' => '1']],
        ]));
        self::assertSame(201, $status);
        self::assertSame('Admins', $created['displayName']);
        self::assertSame('1', $created['members'][0]['value']);
        $id = $created['id'];
        // Membership in the mapped "Admins" group granted ACL role 5.
        self::assertSame(5, (int)$this->store->rows[1]['role_id']);

        // 2. Read the group — its member is listed with the admin's username.
        [$status, $read] = $this->dispatch('GET', self::BASE . '/' . $id);
        self::assertSame(200, $status);
        self::assertSame('jane', $read['members'][0]['display']);

        // 3. PATCH remove the member → role revoked (deny), the account is left in place.
        [$status, $patched] = $this->dispatch('PATCH', self::BASE . '/' . $id, json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'remove', 'path' => 'members[value eq "1"]']],
        ]));
        self::assertSame(200, $status);
        self::assertSame([], $patched['members']);
        // Losing the last mapped group strips the role (0), without deleting the user.
        self::assertSame(0, (int)$this->store->rows[1]['role_id']);
        self::assertArrayHasKey(1, $this->store->rows);
    }

    public function testCreateWithUnknownMemberReturns400AndLeavesNoGroup(): void
    {
        [$status, $error] = $this->dispatch('POST', self::BASE, json_encode([
            'schemas' => [ScimGroupMapper::SCHEMA_GROUP],
            'displayName' => 'Admins',
            'members' => [['value' => '999']],
        ]));

        self::assertSame(400, $status);
        self::assertSame('invalidValue', $error['scimType']);
        // No orphaned group row was persisted, so the displayName stays free for a retry.
        self::assertSame([], $this->groups);
    }

    /**
     * A stateful {@see GroupResource} over the in-memory group map.
     */
    private function makeGroupResource(): GroupResource
    {
        $resource = $this->createStub(GroupResource::class);
        $resource->method('findConflictId')->willReturnCallback(
            function (string $field, string $value, ?int $excludeId = null): ?int {
                foreach ($this->groups as $groupId => $group) {
                    if ($excludeId !== null && $groupId === $excludeId) {
                        continue;
                    }
                    $current = $field === 'external_id' ? (string)$group->externalId : $group->displayName;
                    if ($current === $value) {
                        return $groupId;
                    }
                }

                return null;
            }
        );
        $resource->method('save')->willReturnCallback(function (ScimGroup $group): void {
            if ($group->groupId === null) {
                $group->groupId = ++$this->groupAutoId;
            }
            $this->groups[$group->groupId] = new ScimGroup(
                $group->groupId,
                $group->displayName,
                $group->externalId
            );
        });
        $resource->method('getById')->willReturnCallback(function (int $groupId): ?ScimGroup {
            if (!isset($this->groups[$groupId])) {
                return null;
            }
            $group = $this->groups[$groupId];

            return new ScimGroup($group->groupId, $group->displayName, $group->externalId);
        });

        return $resource;
    }

    /**
     * A stateful {@see MemberResource} over the in-memory membership map.
     */
    private function makeMemberResource(): MemberResource
    {
        $resource = $this->createStub(MemberResource::class);
        $resource->method('setMembers')->willReturnCallback(function (int $groupId, array $userIds): void {
            $this->members[$groupId] = array_values(array_unique(array_map('intval', $userIds)));
        });
        $resource->method('getUserIds')->willReturnCallback(
            fn (int $groupId): array => $this->members[$groupId] ?? []
        );
        $resource->method('getMembers')->willReturnCallback(function (int $groupId): array {
            return array_map(fn (int $userId): array => [
                'value' => (string)$userId,
                'display' => (string)($this->store->rows[$userId]['username'] ?? ''),
            ], $this->members[$groupId] ?? []);
        });
        $resource->method('findMissingUserIds')->willReturnCallback(
            fn (array $userIds): array => array_values(array_filter(
                array_map('intval', $userIds),
                fn (int $userId): bool => !isset($this->store->rows[$userId])
            ))
        );
        $resource->method('getGroupDisplayNamesForUser')->willReturnCallback(function (int $userId): array {
            $names = [];
            foreach ($this->members as $groupId => $userIds) {
                if (in_array($userId, $userIds, true) && isset($this->groups[$groupId])) {
                    $names[] = $this->groups[$groupId]->displayName;
                }
            }

            return $names;
        });

        return $resource;
    }

    /**
     * A {@see RoleFactory} whose roles report existence for id 5 only.
     */
    private function makeRoleFactory(): RoleFactory
    {
        $factory = $this->createStub(RoleFactory::class);
        $factory->method('create')->willReturnCallback(function (): Role {
            $loaded = new class {
                /** @var string|null */
                public ?string $id = null;
            };
            $role = $this->createStub(Role::class);
            $role->method('load')->willReturnCallback(function ($id) use ($role, $loaded): Role {
                $loaded->id = (int)$id === 5 ? '5' : null;

                return $role;
            });
            $role->method('getId')->willReturnCallback(fn () => $loaded->id);

            return $role;
        });

        return $factory;
    }

    /**
     * Dispatch one SCIM Group request through a fresh controller against the store.
     *
     * @param string $method
     * @param string $path
     * @param string $body
     * @return array{0:int,1:array<string,mixed>}
     */
    private function dispatch(string $method, string $path, string $body = ''): array
    {
        $capture = new ScimResponseCapture();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');

        $controller = new Groups(
            $this->scimRequest($method, $path, $body),
            $this->createStub(BearerTokenAuthenticator::class),
            $this->createCapturingResponse($capture),
            $logger,
            $this->provisioner,
            $this->repository,
            $this->mapper,
            $this->memberResource,
            new ListResponseBuilder(),
            new Json(),
            new RequestNormalizerChain()
        );
        $controller->execute();

        return [$capture->status, $capture->document()];
    }
}
