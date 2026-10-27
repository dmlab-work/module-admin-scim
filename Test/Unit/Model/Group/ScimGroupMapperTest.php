<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use PHPUnit\Framework\TestCase;

class ScimGroupMapperTest extends TestCase
{
    /** @var ScimGroupMapper */
    private ScimGroupMapper $mapper;

    protected function setUp(): void
    {
        $urls = $this->createStub(EndpointUrlBuilder::class);
        $urls->method('resourceUrl')->willReturnCallback(
            static fn (string $path): string => 'https://magento.loc/admin-scim/v2/' . $path
        );

        $this->mapper = new ScimGroupMapper($urls);
    }

    public function testExtractDisplayNameReturnsTrimmedValue(): void
    {
        self::assertSame('Admins', $this->mapper->extractDisplayName(['displayName' => '  Admins  ']));
    }

    public function testExtractDisplayNameThrowsWhenMissing(): void
    {
        $this->expectException(ScimException::class);

        try {
            $this->mapper->extractDisplayName([]);
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }

    public function testExtractExternalId(): void
    {
        self::assertSame('grp-1', $this->mapper->extractExternalId(['externalId' => ' grp-1 ']));
        self::assertNull($this->mapper->extractExternalId([]));
        self::assertNull($this->mapper->extractExternalId(['externalId' => '  ']));
    }

    public function testExtractMemberIdsFromMemberObjects(): void
    {
        $ids = $this->mapper->extractMemberIds([
            'members' => [['value' => '5'], ['value' => 7], ['value' => '5']],
        ]);

        self::assertSame([5, 7], $ids);
    }

    public function testExtractMemberIdsEmptyWhenAbsent(): void
    {
        self::assertSame([], $this->mapper->extractMemberIds([]));
        self::assertSame([], $this->mapper->extractMemberIds(['members' => []]));
    }

    public function testExtractMemberIdsRejectsNonList(): void
    {
        $this->expectException(ScimException::class);

        $this->mapper->extractMemberIds(['members' => ['value' => '5']]);
    }

    public function testMemberValuesToIdsRejectsNonNumericValue(): void
    {
        $this->expectException(ScimException::class);

        try {
            $this->mapper->memberValuesToIds([['value' => 'abc']]);
        } catch (ScimException $e) {
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }

    public function testMemberValuesToIdsRejectsMalformedNumericValues(): void
    {
        // Loose numeric forms must not be truncated to a different admin-user id;
        // membership drives ACL role assignment.
        foreach (['1.5', '1e2', 1.9, '+1', '0', '1abc'] as $value) {
            try {
                $this->mapper->memberValuesToIds([['value' => $value]]);
                self::fail(sprintf('Expected rejection of member value %s.', var_export($value, true)));
            } catch (ScimException $e) {
                self::assertSame('invalidValue', $e->getScimType());
            }
        }
    }

    public function testToResourceRendersGroupDocument(): void
    {
        $group = new ScimGroup(42, 'Admins', 'grp-1');

        $resource = $this->mapper->toResource($group, [
            ['value' => '5', 'display' => 'jane.doe'],
        ]);

        self::assertSame(['urn:ietf:params:scim:schemas:core:2.0:Group'], $resource['schemas']);
        self::assertSame('42', $resource['id']);
        self::assertSame('grp-1', $resource['externalId']);
        self::assertSame('Admins', $resource['displayName']);
        self::assertSame([
            [
                'value' => '5',
                'display' => 'jane.doe',
                '$ref' => 'https://magento.loc/admin-scim/v2/Users/5',
            ],
        ], $resource['members']);
        self::assertSame('Group', $resource['meta']['resourceType']);
        self::assertSame('https://magento.loc/admin-scim/v2/Groups/42', $resource['meta']['location']);
    }

    public function testToResourceRendersMetaTimestamps(): void
    {
        $group = new ScimGroup(1, 'Editors', null, '2026-01-02 03:04:05', '2026-01-03 00:00:00');

        $meta = $this->mapper->toResource($group, [])['meta'];

        self::assertSame('2026-01-02T03:04:05Z', $meta['created']);
        self::assertSame('2026-01-03T00:00:00Z', $meta['lastModified']);
        self::assertArrayNotHasKey('created', $this->mapper->toResource(new ScimGroup(2, 'X'), [])['meta']);
    }

    public function testOverLongDisplayNameAndExternalIdAreRejected(): void
    {
        foreach (['extractDisplayName' => 'displayName', 'extractExternalId' => 'externalId'] as $method => $key) {
            try {
                $this->mapper->$method([$key => str_repeat('g', 256)]);
                self::fail('Expected ScimException');
            } catch (ScimException $e) {
                self::assertSame('invalidValue', $e->getScimType());
            }
        }
    }

    public function testToResourceOmitsExternalIdWhenEmpty(): void
    {
        $group = new ScimGroup(1, 'Editors', null);

        $resource = $this->mapper->toResource($group, []);

        self::assertArrayNotHasKey('externalId', $resource);
        self::assertSame([], $resource['members']);
    }
}
