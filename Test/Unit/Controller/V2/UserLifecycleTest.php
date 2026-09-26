<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller\V2;

use DmLab\AdminScim\Controller\V2\Users;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
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
use Magento\Framework\Serialize\Serializer\Json;
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
            $patcher
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

    public function testDuplicateUserNameIsRejectedWithConflict(): void
    {
        $body = json_encode(['userName' => 'dup@example.com']);
        [$first] = $this->dispatch('POST', self::BASE, $body);
        self::assertSame(201, $first);

        [$status, $error] = $this->dispatch('POST', self::BASE, $body);
        self::assertSame(409, $status);
        self::assertSame('uniqueness', $error['scimType']);
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
            new RequestNormalizerChain()
        );
        $controller->execute();

        return [$capture->status, $capture->document()];
    }
}
