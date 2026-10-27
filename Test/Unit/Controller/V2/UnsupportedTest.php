<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller\V2;

use DmLab\AdminScim\Controller\V2\Unsupported;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Test\Unit\Doubles\InMemoryScimTrait;
use DmLab\AdminScim\Test\Unit\Doubles\ScimResponseCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UnsupportedTest extends TestCase
{
    use InMemoryScimTrait;

    #[DataProvider('paths')]
    public function testAnswersScimJsonErrors(string $path, int $status): void
    {
        $capture = new ScimResponseCapture();
        $controller = new Unsupported(
            $this->scimRequest('POST', $path),
            $this->createStub(BearerTokenAuthenticator::class),
            $this->createCapturingResponse($capture),
            $this->createStub(LoggerInterface::class)
        );

        $controller->execute();

        self::assertSame($status, $capture->status);
        self::assertSame(['urn:ietf:params:scim:api:messages:2.0:Error'], $capture->document()['schemas']);
        self::assertSame((string)$status, $capture->document()['status']);
    }

    public static function paths(): array
    {
        return [
            'Me' => ['/admin-scim/v2/Me', 501],
            'Bulk' => ['/admin-scim/v2/Bulk', 501],
            'root search' => ['/admin-scim/v2/.search/', 501],
            'unknown' => ['/admin-scim/v2/Nope', 404],
        ];
    }
}
