<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Controller\V2;

use MageDevGroup\AdminScim\Api\RequestNormalizerInterface;
use MageDevGroup\AdminScim\Controller\V2\Users;
use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Normalization\RequestNormalizerChain;
use MageDevGroup\AdminScim\Model\Response\ListResponseBuilder;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
use MageDevGroup\AdminScim\Model\User\ScimUserMapper;
use MageDevGroup\AdminScim\Model\User\ScimUserRepository;
use MageDevGroup\AdminScim\Model\User\UserProvisioner;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UsersTest extends TestCase
{
    /** @var Http&\PHPUnit\Framework\MockObject\Stub */
    private $request;

    /** @var BearerTokenAuthenticator&\PHPUnit\Framework\MockObject\Stub */
    private $authenticator;

    /** @var ScimResponse&MockObject */
    private $response;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var UserProvisioner&MockObject */
    private $provisioner;

    /** @var ScimUserRepository&MockObject */
    private $repository;

    /** @var ScimUserMapper&\PHPUnit\Framework\MockObject\Stub */
    private $mapper;

    /** @var Users */
    private Users $controller;

    protected function setUp(): void
    {
        $this->request = $this->createStub(Http::class);
        $this->authenticator = $this->createStub(BearerTokenAuthenticator::class);
        $this->response = $this->createMock(ScimResponse::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provisioner = $this->createMock(UserProvisioner::class);
        $this->repository = $this->createMock(ScimUserRepository::class);
        $this->mapper = $this->createStub(ScimUserMapper::class);

        $this->controller = new Users(
            $this->request,
            $this->authenticator,
            $this->response,
            $this->logger,
            $this->provisioner,
            $this->repository,
            $this->mapper,
            new ListResponseBuilder(),
            new Json(),
            new RequestNormalizerChain()
        );
    }

    public function testPostCreatesUserAndReturns201(): void
    {
        $payload = ['userName' => 'jane.doe', 'externalId' => 'ext-9'];
        $created = $this->createStub(User::class);
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn(json_encode($payload));

        $this->provisioner->expects(self::once())->method('create')->with($payload)->willReturn($created);
        $this->repository->expects(self::never())->method('getById');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 201)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testUnsupportedMethodReturns501(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('DELETE');

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::never())->method('getById');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 501))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testPutReplacesUserAndReturnsResource(): void
    {
        $payload = ['userName' => 'jane.doe'];
        $updated = $this->createStub(User::class);
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('PUT');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users/42');
        $this->request->method('getContent')->willReturn(json_encode($payload));

        $this->provisioner->expects(self::once())->method('replace')->with('42', $payload)->willReturn($updated);
        $this->repository->expects(self::never())->method('getById');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 200)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testPatchUpdatesUserAndReturnsResource(): void
    {
        $body = ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]];
        $updated = $this->createStub(User::class);
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('PATCH');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users/42');
        $this->request->method('getContent')->willReturn(json_encode($body));

        $this->provisioner->expects(self::once())->method('patch')->with('42', $body)->willReturn($updated);
        $this->repository->expects(self::never())->method('getById');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 200)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testPutWithoutResourceIdReturns400(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('PUT');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users');
        $this->request->method('getContent')->willReturn('{"userName":"jane.doe"}');

        $this->provisioner->expects(self::never())->method('replace');
        $this->repository->expects(self::never())->method('getById');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(
                static fn (ScimException $e): bool =>
                    $e->getHttpStatus() === 400 && $e->getScimType() === 'noTarget'
            ))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testGetByIdReturnsResource(): void
    {
        $user = $this->createStub(User::class);
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users/42');

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('getById')->with('42')->willReturn($user);
        $this->repository->expects(self::never())->method('search');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 200)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testGetByUnknownIdReturns404(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users/999');

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('getById')->with('999')
            ->willThrowException(ScimException::notFound('User "999" not found.'));
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 404))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testListReturnsScimListResponse(): void
    {
        $userA = $this->createStub(User::class);
        $userB = $this->createStub(User::class);
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users');
        $this->request->method('getParam')->willReturnMap([
            ['filter', null, ''],
            ['startIndex', null, null],
            ['count', null, null],
        ]);

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('search')
            ->with(null, 1, 100)
            ->willReturn(['users' => [$userA, $userB], 'total' => 5]);
        $this->mapper->method('toResource')->willReturnOnConsecutiveCalls(
            ['id' => '1'],
            ['id' => '2']
        );

        $captured = null;
        $this->response->expects(self::once())->method('json')
            ->willReturnCallback(function ($body, $status) use (&$captured, $raw) {
                $captured = $body;
                return $raw;
            });
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
        self::assertSame(['urn:ietf:params:scim:api:messages:2.0:ListResponse'], $captured['schemas']);
        self::assertSame(5, $captured['totalResults']);
        self::assertSame(1, $captured['startIndex']);
        self::assertSame(2, $captured['itemsPerPage']);
        self::assertSame([['id' => '1'], ['id' => '2']], $captured['Resources']);
    }

    public function testListPassesFilterAndPaginationToRepository(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users');
        $this->request->method('getParam')->willReturnMap([
            ['filter', null, 'userName eq "jane"'],
            ['startIndex', null, '3'],
            ['count', null, '2'],
        ]);

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('search')
            ->with('userName eq "jane"', 3, 2)
            ->willReturn(['users' => [], 'total' => 0]);
        $this->response->expects(self::once())->method('json')->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testListClampsOutOfRangePagination(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users');
        $this->request->method('getParam')->willReturnMap([
            ['filter', null, ''],
            ['startIndex', null, '0'],
            ['count', null, '9999'],
        ]);

        // startIndex clamps to 1, count clamps to the advertised max (200).
        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('search')
            ->with(null, 1, 200)
            ->willReturn(['users' => [], 'total' => 0]);
        $this->response->expects(self::once())->method('json')->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testInvalidFilterIsRenderedAsError(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Users');
        $this->request->method('getParam')->willReturnMap([
            ['filter', null, 'displayName eq "x"'],
            ['startIndex', null, null],
            ['count', null, null],
        ]);

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('search')
            ->willThrowException(ScimException::badRequest('unsupported', 'invalidFilter'));
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(
                static fn (ScimException $e): bool =>
                    $e->getHttpStatus() === 400 && $e->getScimType() === 'invalidFilter'
            ))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn('{not json');

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::never())->method('getById');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(
                static fn (ScimException $e): bool =>
                    $e->getHttpStatus() === 400 && $e->getScimType() === 'invalidSyntax'
            ))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testNonObjectBodyReturns400(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn('["a","b"]');

        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::never())->method('getById');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(
                static fn (ScimException $e): bool =>
                    $e->getHttpStatus() === 400 && $e->getScimType() === 'invalidSyntax'
            ))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testRegisteredNormalizerRewritesBodyBeforeProvisioning(): void
    {
        $normalizer = new class implements RequestNormalizerInterface {
            public function normalize(string $resourceType, string $operation, array $payload): array
            {
                $payload['userName'] = 'normalized';
                return $payload;
            }
        };
        $controller = new Users(
            $this->request,
            $this->authenticator,
            $this->response,
            $this->logger,
            $this->provisioner,
            $this->repository,
            $this->mapper,
            new ListResponseBuilder(),
            new Json(),
            new RequestNormalizerChain([$normalizer])
        );

        $created = $this->createStub(User::class);
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn(json_encode(['userName' => 'raw']));

        $this->provisioner->expects(self::once())->method('create')
            ->with(['userName' => 'normalized'])->willReturn($created);
        $this->repository->expects(self::never())->method('getById');
        $this->mapper->method('toResource')->willReturn(['id' => '1']);
        $this->response->expects(self::once())->method('json')->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $controller->execute());
    }

    public function testProvisionerConflictIsRenderedAsError(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn('{"userName":"jane.doe"}');

        $this->repository->expects(self::never())->method('getById');
        $this->provisioner->expects(self::once())->method('create')
            ->willThrowException(ScimException::conflict('A user with this userName already exists.'));
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 409))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }
}
