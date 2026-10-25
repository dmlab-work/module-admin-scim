<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller\V2;

use DmLab\AdminScim\Controller\V2\ResourceTypes;
use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResourceTypesTest extends TestCase
{
    /** @var BearerTokenAuthenticator&MockObject */
    private $authenticator;

    /** @var ScimResponse&MockObject */
    private $response;

    /** @var DiscoveryProvider&MockObject */
    private $discovery;

    protected function setUp(): void
    {
        $this->authenticator = $this->createMock(BearerTokenAuthenticator::class);
        $this->response = $this->createMock(ScimResponse::class);
        $this->discovery = $this->createMock(DiscoveryProvider::class);
    }

    private function controller(string $method, string $path): ResourceTypes
    {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getPathInfo')->willReturn($path);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');

        return new ResourceTypes($request, $this->authenticator, $this->response, $logger, $this->discovery);
    }

    public function testRendersResourceTypesDocument(): void
    {
        $document = ['schemas' => ['list']];
        $raw = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->discovery->expects(self::once())->method('resourceTypes')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document)->willReturn($raw);

        self::assertSame($raw, $this->controller('GET', '/admin-scim/v2/ResourceTypes')->execute());
    }

    public function testRendersSingleResourceById(): void
    {
        $resource = ['id' => 'User'];
        $raw = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->discovery->expects(self::never())->method('resourceTypes');
        $this->discovery->expects(self::once())->method('resourceType')->with('User')->willReturn($resource);
        $this->response->expects(self::once())->method('json')->with($resource)->willReturn($raw);

        self::assertSame($raw, $this->controller('GET', '/admin-scim/v2/ResourceTypes/User')->execute());
    }

    public function testUnknownIdIs404(): void
    {
        $raw = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->discovery->expects(self::once())->method('resourceType')->willReturn(null);
        $this->response->expects(self::never())->method('json');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 404))
            ->willReturn($raw);

        self::assertSame($raw, $this->controller('GET', '/admin-scim/v2/ResourceTypes/Nope')->execute());
    }

    public function testNonGetVerbIs501(): void
    {
        $raw = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->discovery->expects(self::never())->method('resourceTypes');
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 501))
            ->willReturn($raw);

        self::assertSame($raw, $this->controller('POST', '/admin-scim/v2/ResourceTypes')->execute());
    }
}
