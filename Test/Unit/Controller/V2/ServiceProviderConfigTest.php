<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller\V2;

use DmLab\AdminScim\Controller\V2\ServiceProviderConfig;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ServiceProviderConfigTest extends TestCase
{
    /** @var BearerTokenAuthenticator&MockObject */
    private $authenticator;

    /** @var ScimResponse&MockObject */
    private $response;

    /** @var DiscoveryProvider&MockObject */
    private $discovery;

    /** @var ServiceProviderConfig */
    private ServiceProviderConfig $controller;

    protected function setUp(): void
    {
        $request = $this->createStub(Http::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');

        $this->authenticator = $this->createMock(BearerTokenAuthenticator::class);
        $this->response = $this->createMock(ScimResponse::class);
        $this->discovery = $this->createMock(DiscoveryProvider::class);

        $this->controller = new ServiceProviderConfig(
            $request,
            $this->authenticator,
            $this->response,
            $logger,
            $this->discovery
        );
    }

    public function testRendersServiceProviderConfigDocument(): void
    {
        $document = ['schemas' => ['spc']];
        $raw = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->discovery->expects(self::once())->method('serviceProviderConfig')->willReturn($document);
        $this->response->expects(self::once())->method('json')->with($document)->willReturn($raw);

        self::assertSame($raw, $this->controller->execute());
    }
}
