<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Controller\V2;

use DmLab\AdminScim\Controller\AbstractScim;
use DmLab\AdminScim\Exception\ScimException;
use Magento\Framework\Controller\ResultInterface;

/**
 * Any `/admin-scim/…` path no other action serves (dispatched by {@see \DmLab\AdminScim\Controller\Router}):
 * `/Me`, `/Bulk` and the root `/.search` answer SCIM `501` (RFC 7644 §3.11, §3.7, §3.4.3);
 * anything else a SCIM `404` instead of Magento's HTML page.
 */
class Unsupported extends AbstractScim
{
    /** Endpoints RFC 7644 defines that this server does not implement. */
    private const NOT_IMPLEMENTED = ['me', 'bulk', '.search'];

    /**
     * @inheritDoc
     */
    protected function handle(): ResultInterface
    {
        $segments = explode('/', trim((string)$this->request->getPathInfo(), '/'));
        $endpoint = (string)end($segments);
        if (in_array(strtolower($endpoint), self::NOT_IMPLEMENTED, true)) {
            throw ScimException::notImplemented(sprintf('The /%s endpoint is not supported.', $endpoint));
        }

        throw ScimException::notFound('Unknown SCIM endpoint.');
    }
}
