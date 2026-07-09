<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Controller;

use MageDevGroup\AdminScim\Controller\AbstractScim;
use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbstractScimTest extends TestCase
{
    /** @var BearerTokenAuthenticator&MockObject */
    private $authenticator;

    /** @var ScimResponse&MockObject */
    private $response;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var Http&MockObject */
    private $request;

    protected function setUp(): void
    {
        $this->request = $this->createStub(Http::class);
        $this->authenticator = $this->createMock(BearerTokenAuthenticator::class);
        $this->response = $this->createMock(ScimResponse::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function controller(callable $handler): AbstractScim
    {
        return new class ($this->request, $this->authenticator, $this->response, $this->logger, $handler)
            extends AbstractScim {
            /** @var callable */
            private $handler;

            public function __construct($request, $authenticator, $response, $logger, callable $handler)
            {
                parent::__construct($request, $authenticator, $response, $logger);
                $this->handler = $handler;
            }

            protected function handle(): ResultInterface
            {
                return ($this->handler)();
            }
        };
    }

    public function testAuthenticatedRequestReturnsHandlerResult(): void
    {
        $expected = $this->createStub(Raw::class);
        $this->authenticator->expects(self::once())->method('authenticate')->with($this->request);
        $this->response->expects(self::never())->method('error');
        $this->logger->expects(self::never())->method('critical');

        $controller = $this->controller(static fn (): ResultInterface => $expected);

        self::assertSame($expected, $controller->execute());
    }

    public function testAuthenticationFailureRendersErrorAndSkipsHandler(): void
    {
        $exception = ScimException::unauthorized('Invalid bearer token.');
        $errorResult = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate')->willThrowException($exception);
        $this->response->expects(self::once())->method('error')->with($exception)->willReturn($errorResult);
        $this->logger->expects(self::never())->method('critical');

        $handlerCalled = false;
        $controller = $this->controller(static function () use (&$handlerCalled): ResultInterface {
            $handlerCalled = true;
            throw new \LogicException('handler must not run');
        });

        self::assertSame($errorResult, $controller->execute());
        self::assertFalse($handlerCalled);
    }

    public function testScimExceptionFromHandlerIsRendered(): void
    {
        $exception = ScimException::notFound('User not found.');
        $errorResult = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->response->expects(self::once())->method('error')->with($exception)->willReturn($errorResult);
        $this->logger->expects(self::never())->method('critical');

        $controller = $this->controller(static fn (): ResultInterface => throw $exception);

        self::assertSame($errorResult, $controller->execute());
    }

    public function testFormKeyCsrfValidationIsBypassed(): void
    {
        $this->authenticator->expects(self::never())->method('authenticate');
        $this->response->expects(self::never())->method('error');
        $this->logger->expects(self::never())->method('critical');

        $controller = $this->controller(static fn (): ResultInterface => throw new \LogicException('unused'));

        self::assertTrue($controller->validateForCsrf($this->request));
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    public function testUnexpectedThrowableIsLoggedAndReturns500(): void
    {
        $boom = new \RuntimeException('database exploded');
        $errorResult = $this->createStub(Raw::class);

        $this->authenticator->expects(self::once())->method('authenticate');
        $this->logger->expects(self::once())->method('critical')->with($boom);
        $this->response->expects(self::once())->method('error')
            ->with(self::callback(static fn (ScimException $e): bool => $e->getHttpStatus() === 500))
            ->willReturn($errorResult);

        $controller = $this->controller(static fn (): ResultInterface => throw $boom);

        self::assertSame($errorResult, $controller->execute());
    }
}
