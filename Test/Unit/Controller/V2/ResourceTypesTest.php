<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Controller\V2;

use MageDevGroup\AdminScim\Controller\V2\ResourceTypes;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Discovery\DiscoveryProvider;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
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

    /** @var ResourceTypes */
    private ResourceTypes $controller;

    protected function setUp(): void
    {
        $request = $this->createStub(Http::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');

        $this->authenticator = $this->createMock(BearerTokenAuthenticator::class);
        $this->response = $this->createMock(ScimResponse::class);
        $this->discovery = $this->createMock(DiscoveryProvider::class);

        $this->controller = new ResourceTypes(
            $request,
            $this->authenticator,
            $this->response,
            $logger,
            $this->discovery
        );
    }

    public function testRendersResourceTypesDocument(): void
    {
        $document = ['schemas' => ['list']];
        $raw = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->discovery->expects(self::once())->method('resourceTypes')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document)->willReturn($raw);

        self::assertSame($raw, $this->controller->execute());
    }
}
