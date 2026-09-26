<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Discovery;

use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DiscoveryProviderTest extends TestCase
{
    private const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const SCHEMA_GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const SCHEMA_LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    /** @var EndpointUrlBuilder&Stub */
    private $urls;

    /** @var DiscoveryProvider */
    private DiscoveryProvider $provider;

    protected function setUp(): void
    {
        $this->urls = $this->createStub(EndpointUrlBuilder::class);
        $this->urls->method('resourceUrl')
            ->willReturnCallback(static fn (string $path): string => 'https://magento.loc/admin-scim/v2/' . $path);

        $this->provider = new DiscoveryProvider($this->urls);
    }

    public function testServiceProviderConfigDeclaresSchemaAndCapabilities(): void
    {
        $doc = $this->provider->serviceProviderConfig();

        self::assertSame(
            ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            $doc['schemas']
        );
        self::assertTrue($doc['patch']['supported']);
        self::assertTrue($doc['filter']['supported']);
        self::assertSame(DiscoveryProvider::FILTER_MAX_RESULTS, $doc['filter']['maxResults']);
        self::assertFalse($doc['bulk']['supported']);
        self::assertFalse($doc['changePassword']['supported']);
        self::assertFalse($doc['sort']['supported']);
        self::assertFalse($doc['etag']['supported']);
        self::assertSame('ServiceProviderConfig', $doc['meta']['resourceType']);
        self::assertSame(
            'https://magento.loc/admin-scim/v2/ServiceProviderConfig',
            $doc['meta']['location']
        );
    }

    public function testServiceProviderConfigAdvertisesBearerAuthentication(): void
    {
        $doc = $this->provider->serviceProviderConfig();

        self::assertCount(1, $doc['authenticationSchemes']);
        $scheme = $doc['authenticationSchemes'][0];
        self::assertSame('oauthbearertoken', $scheme['type']);
        self::assertTrue($scheme['primary']);
    }

    public function testResourceTypesIsListResponseWithUserAndGroup(): void
    {
        $doc = $this->provider->resourceTypes();

        self::assertSame([self::SCHEMA_LIST_RESPONSE], $doc['schemas']);
        self::assertSame(2, $doc['totalResults']);
        self::assertCount(2, $doc['Resources']);

        $byId = [];
        foreach ($doc['Resources'] as $resource) {
            self::assertSame(
                ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                $resource['schemas']
            );
            $byId[$resource['id']] = $resource;
        }

        self::assertArrayHasKey('User', $byId);
        self::assertArrayHasKey('Group', $byId);
        self::assertSame('/Users', $byId['User']['endpoint']);
        self::assertSame(self::SCHEMA_USER, $byId['User']['schema']);
        self::assertSame('/Groups', $byId['Group']['endpoint']);
        self::assertSame(self::SCHEMA_GROUP, $byId['Group']['schema']);
        self::assertSame(
            'https://magento.loc/admin-scim/v2/ResourceTypes/User',
            $byId['User']['meta']['location']
        );
    }

    public function testSchemasIsListResponseWithUserAndGroupDefinitions(): void
    {
        $doc = $this->provider->schemas();

        self::assertSame([self::SCHEMA_LIST_RESPONSE], $doc['schemas']);
        self::assertSame(2, $doc['totalResults']);

        $byId = [];
        foreach ($doc['Resources'] as $resource) {
            self::assertSame(
                ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
                $resource['schemas']
            );
            $byId[$resource['id']] = $resource;
        }

        self::assertArrayHasKey(self::SCHEMA_USER, $byId);
        self::assertArrayHasKey(self::SCHEMA_GROUP, $byId);
    }

    public function testUserSchemaDeclaresProvisionedAttributes(): void
    {
        $user = $this->schemaById(self::SCHEMA_USER);
        $attributes = $this->attributesByName($user);

        self::assertArrayHasKey('userName', $attributes);
        self::assertTrue($attributes['userName']['required']);
        self::assertSame('server', $attributes['userName']['uniqueness']);

        self::assertArrayHasKey('active', $attributes);
        self::assertSame('boolean', $attributes['active']['type']);

        self::assertArrayHasKey('name', $attributes);
        self::assertSame('complex', $attributes['name']['type']);
        $nameSubs = $this->subAttributeNames($attributes['name']);
        self::assertContains('givenName', $nameSubs);
        self::assertContains('familyName', $nameSubs);

        self::assertArrayHasKey('emails', $attributes);
        self::assertTrue($attributes['emails']['multiValued']);
    }

    public function testGroupSchemaDeclaresProvisionedAttributes(): void
    {
        $group = $this->schemaById(self::SCHEMA_GROUP);
        $attributes = $this->attributesByName($group);

        self::assertArrayHasKey('displayName', $attributes);
        self::assertTrue($attributes['displayName']['required']);

        self::assertArrayHasKey('members', $attributes);
        self::assertSame('complex', $attributes['members']['type']);
        self::assertTrue($attributes['members']['multiValued']);
        self::assertContains('value', $this->subAttributeNames($attributes['members']));
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaById(string $id): array
    {
        foreach ($this->provider->schemas()['Resources'] as $resource) {
            if ($resource['id'] === $id) {
                return $resource;
            }
        }

        self::fail("Schema {$id} not found.");
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,array<string,mixed>>
     */
    private function attributesByName(array $schema): array
    {
        $byName = [];
        foreach ($schema['attributes'] as $attribute) {
            $byName[$attribute['name']] = $attribute;
        }

        return $byName;
    }

    /**
     * @param array<string,mixed> $attribute
     * @return array<int,string>
     */
    private function subAttributeNames(array $attribute): array
    {
        return array_map(
            static fn (array $sub): string => $sub['name'],
            $attribute['subAttributes']
        );
    }
}
