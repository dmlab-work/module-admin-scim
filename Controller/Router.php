<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Controller;

use DmLab\AdminScim\Controller\V2\Unsupported;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Runs after the standard router: an `admin-scim/…` path it found no action for
 * (`/Me`, `/Bulk`, `/.search`, typos) gets a SCIM JSON error, not the storefront 404 page.
 */
class Router implements RouterInterface
{
    /** frontName of the SCIM route (see etc/frontend/routes.xml). */
    private const FRONT_NAME = 'admin-scim';

    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = trim((string)$request->getPathInfo(), '/');
        if (strcasecmp(explode('/', $path)[0], self::FRONT_NAME) !== 0) {
            return null;
        }
        $request->setModuleName(self::FRONT_NAME);
        $request->setActionName('unsupported');

        return $this->actionFactory->create(Unsupported::class);
    }
}
