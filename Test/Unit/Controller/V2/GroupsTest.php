<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller\V2;

use DmLab\AdminScim\Controller\V2\Groups;
use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Group\GroupProvisioner;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupRepository;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use DmLab\AdminScim\Model\Response\ListResponseBuilder;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GroupsTest extends TestCase
{
    /** @var Http&\PHPUnit\Framework\MockObject\Stub */
    private $request;

    /** @var BearerTokenAuthenticator&\PHPUnit\Framework\MockObject\Stub */
    private $authenticator;

    /** @var ScimResponse&MockObject */
    private $response;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var GroupProvisioner&MockObject */
    private $provisioner;

    /** @var ScimGroupRepository&MockObject */
    private $repository;

    /** @var ScimGroupMapper&\PHPUnit\Framework\MockObject\Stub */
    private $mapper;

    /** @var MemberResource&\PHPUnit\Framework\MockObject\Stub */
    private $memberResource;

    /** @var Groups */
    private Groups $controller;

    protected function setUp(): void
    {
        $this->request = $this->createStub(Http::class);
        $this->authenticator = $this->createStub(BearerTokenAuthenticator::class);
        $this->response = $this->createMock(ScimResponse::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->provisioner = $this->createMock(GroupProvisioner::class);
        $this->repository = $this->createMock(ScimGroupRepository::class);
        $this->mapper = $this->createStub(ScimGroupMapper::class);
        $this->memberResource = $this->createStub(MemberResource::class);

        $this->controller = new Groups(
            $this->request,
            $this->authenticator,
            $this->response,
            $this->logger,
            $this->provisioner,
            $this->repository,
            $this->mapper,
            $this->memberResource,
            new ListResponseBuilder(),
            new Json(),
            new RequestNormalizerChain()
        );
    }

    /** No write verbs and no repository read are exercised on this request. */
    private function expectNoProvisioning(): void
    {
        $this->provisioner->expects(self::never())->method('create');
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::never())->method('getById');
        $this->repository->expects(self::never())->method('search');
        $this->logger->expects(self::never())->method('critical');
    }

    public function testPostCreatesGroupAndReturns201(): void
    {
        $payload = ['displayName' => 'Admins', 'members' => [['value' => '5']]];
        $created = new ScimGroup(42, 'Admins');
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn(json_encode($payload));

        $this->provisioner->expects(self::once())->method('create')->with($payload)->willReturn($created);
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::never())->method('getById');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 201)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testPatchUpdatesGroupAndReturnsResource(): void
    {
        $body = ['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => '5']]]]];
        $updated = new ScimGroup(42, 'Admins');
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('PATCH');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Groups/42');
        $this->request->method('getContent')->willReturn(json_encode($body));

        $this->provisioner->expects(self::once())->method('patch')->with('42', $body)->willReturn($updated);
        $this->provisioner->expects(self::never())->method('create');
        $this->repository->expects(self::never())->method('getById');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 200)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testPatchWithoutResourceIdReturns400(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('PATCH');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Groups');
        $this->request->method('getContent')->willReturn('{"Operations":[]}');

        $this->expectNoProvisioning();
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(
                static fn (ScimException $e): bool =>
                    $e->getHttpStatus() === 400 && $e->getScimType() === 'noTarget'
            ))
            ->willReturn($raw);

        self::assertSame($raw, $this->controller->execute());
    }

    public function testGetByIdReturnsResource(): void
    {
        $group = new ScimGroup(42, 'Admins');
        $document = ['schemas' => ['urn'], 'id' => '42'];
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Groups/42');

        $this->provisioner->expects(self::never())->method('create');
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::once())->method('getById')->with('42')->willReturn($group);
        $this->repository->expects(self::never())->method('search');
        $this->mapper->method('toResource')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document, 200)->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testGetUnknownIdReturns404(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Groups/999');

        $this->provisioner->expects(self::never())->method('create');
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::once())->method('getById')->with('999')
            ->willThrowException(ScimException::notFound('Group "999" not found.'));
        $this->repository->expects(self::never())->method('search');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 404))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testListReturnsScimListResponse(): void
    {
        $groupA = new ScimGroup(1, 'A');
        $groupB = new ScimGroup(2, 'B');
        $raw = $this->createStub(Raw::class);

        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Groups');
        $this->request->method('getParam')->willReturnMap([
            ['filter', null, ''],
            ['startIndex', null, null],
            ['count', null, null],
        ]);

        $this->provisioner->expects(self::never())->method('create');
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::never())->method('getById');
        $this->repository->expects(self::once())->method('search')
            ->with(null, 1, 100)
            ->willReturn(['groups' => [$groupA, $groupB], 'total' => 5]);
        $this->mapper->method('toResource')->willReturnOnConsecutiveCalls(['id' => '1'], ['id' => '2']);

        $captured = null;
        $this->response->expects(self::once())->method('json')
            ->willReturnCallback(function ($body) use (&$captured, $raw) {
                $captured = $body;
                return $raw;
            });
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
        self::assertSame(5, $captured['totalResults']);
        self::assertSame(2, $captured['itemsPerPage']);
        self::assertSame([['id' => '1'], ['id' => '2']], $captured['Resources']);
    }

    public function testListPassesFilterAndPaginationToRepository(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getPathInfo')->willReturn('/admin-scim/v2/Groups');
        $this->request->method('getParam')->willReturnMap([
            ['filter', null, 'displayName eq "Admins"'],
            ['startIndex', null, '3'],
            ['count', null, '2'],
        ]);

        $this->provisioner->expects(self::never())->method('create');
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::never())->method('getById');
        $this->repository->expects(self::once())->method('search')
            ->with('displayName eq "Admins"', 3, 2)
            ->willReturn(['groups' => [], 'total' => 0]);
        $this->response->expects(self::once())->method('json')->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }

    public function testPutReturns501(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('PUT');

        $this->expectNoProvisioning();
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 501))
            ->willReturn($raw);

        self::assertSame($raw, $this->controller->execute());
    }

    public function testDeleteReturns501(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('DELETE');

        $this->expectNoProvisioning();
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 501))
            ->willReturn($raw);

        self::assertSame($raw, $this->controller->execute());
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn('{not json');

        $this->expectNoProvisioning();
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(
                static fn (ScimException $e): bool =>
                    $e->getHttpStatus() === 400 && $e->getScimType() === 'invalidSyntax'
            ))
            ->willReturn($raw);

        self::assertSame($raw, $this->controller->execute());
    }

    public function testProvisionerConflictIsRenderedAsError(): void
    {
        $raw = $this->createStub(Raw::class);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getContent')->willReturn('{"displayName":"Admins"}');

        $this->provisioner->expects(self::once())->method('create')
            ->willThrowException(ScimException::conflict('A group with this displayName already exists.'));
        $this->provisioner->expects(self::never())->method('patch');
        $this->repository->expects(self::never())->method('getById');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 409))
            ->willReturn($raw);
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($raw, $this->controller->execute());
    }
}
