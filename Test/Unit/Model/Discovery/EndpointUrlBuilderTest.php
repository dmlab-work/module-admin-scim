<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\Discovery;

use MageDevGroup\AdminScim\Model\Discovery\EndpointUrlBuilder;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class EndpointUrlBuilderTest extends TestCase
{
    /** @var EndpointUrlBuilder */
    private EndpointUrlBuilder $builder;

    protected function setUp(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://magento.loc/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->builder = new EndpointUrlBuilder($storeManager);
    }

    public function testBaseUrlStripsTrailingSlashAndAppendsRouteArea(): void
    {
        self::assertSame('https://magento.loc/admin-scim/v2', $this->builder->baseUrl());
    }

    public function testResourceUrlJoinsPath(): void
    {
        self::assertSame(
            'https://magento.loc/admin-scim/v2/ServiceProviderConfig',
            $this->builder->resourceUrl('ServiceProviderConfig')
        );
    }

    public function testResourceUrlNormalisesLeadingSlash(): void
    {
        self::assertSame(
            'https://magento.loc/admin-scim/v2/ResourceTypes/User',
            $this->builder->resourceUrl('/ResourceTypes/User')
        );
    }
}
