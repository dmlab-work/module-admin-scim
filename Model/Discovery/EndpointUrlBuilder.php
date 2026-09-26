<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Discovery;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds absolute URLs under the `/admin-scim/v2` SCIM route area, used for the
 * `meta.location` of discovery resources. Derived from the current store base URL
 * so it follows the store's configured scheme/host.
 */
class EndpointUrlBuilder
{
    /** SCIM route area path, matching the `admin-scim` frontName + `v2` version. */
    private const BASE_PATH = 'admin-scim/v2';

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Base SCIM endpoint URL without a trailing slash, e.g. `https://host/admin-scim/v2`.
     */
    public function baseUrl(): string
    {
        return rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/' . self::BASE_PATH;
    }

    /**
     * Absolute URL of a SCIM resource under the base path.
     *
     * @param string $path Resource path, e.g. `ServiceProviderConfig` or `ResourceTypes/User`.
     */
    public function resourceUrl(string $path): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }
}
