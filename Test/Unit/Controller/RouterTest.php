<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Controller;

use DmLab\AdminScim\Controller\Router;
use DmLab\AdminScim\Controller\V2\Unsupported;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testUnroutedScimPathGetsTheUnsupportedAction(): void
    {
        $action = $this->createStub(Unsupported::class);
        $factory = $this->createMock(ActionFactory::class);
        $factory->expects(self::once())->method('create')->with(Unsupported::class)->willReturn($action);
        $request = $this->createStub(Http::class);
        $request->method('getPathInfo')->willReturn('/admin-scim/v2/Me');

        self::assertSame($action, (new Router($factory))->match($request));
    }

    public function testOtherPathsAreLeftToTheNextRouter(): void
    {
        $factory = $this->createMock(ActionFactory::class);
        $factory->expects(self::never())->method('create');
        $request = $this->createStub(Http::class);
        $request->method('getPathInfo')->willReturn('/admin-scimx/v2/Me');

        self::assertNull((new Router($factory))->match($request));
    }
}
