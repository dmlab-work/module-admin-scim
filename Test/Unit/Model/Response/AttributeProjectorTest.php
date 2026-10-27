<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Response;

use DmLab\AdminScim\Model\Response\AttributeProjector;
use PHPUnit\Framework\TestCase;

class AttributeProjectorTest extends TestCase
{
    private const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    private AttributeProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new AttributeProjector();
    }

    /** @return array<string,mixed> */
    private function user(): array
    {
        return [
            'schemas' => [self::USER],
            'id' => '7',
            'externalId' => 'ext-7',
            'userName' => 'jane',
            'name' => ['formatted' => 'Jane Doe', 'givenName' => 'Jane', 'familyName' => 'Doe'],
            'emails' => [['value' => 'jane@example.com', 'primary' => true]],
            'active' => true,
            'meta' => ['resourceType' => 'User', 'location' => 'https://x/Users/7'],
        ];
    }

    public function testNoParametersReturnTheResourceUnchanged(): void
    {
        self::assertSame($this->user(), $this->projector->project($this->user(), self::USER, null, ''));
    }

    public function testAttributesKeepOnlyListedPlusAlwaysReturned(): void
    {
        self::assertSame(
            ['schemas' => [self::USER], 'id' => '7', 'userName' => 'jane', 'active' => true],
            $this->projector->project($this->user(), self::USER, 'USERNAME, active', null)
        );
    }

    public function testAttributesSelectSubAttributesAndAcceptUrnPrefix(): void
    {
        $projected = $this->projector->project(
            $this->user(),
            self::USER,
            self::USER . ':name.givenName,emails.value',
            null
        );

        self::assertSame(['givenName' => 'Jane'], $projected['name']);
        self::assertSame([['value' => 'jane@example.com']], $projected['emails']);
        self::assertArrayNotHasKey('meta', $projected);
    }

    public function testWholeAttributeWinsOverItsSubAttribute(): void
    {
        $projected = $this->projector->project($this->user(), self::USER, 'name.givenName,name', null);

        self::assertSame($this->user()['name'], $projected['name']);
    }

    public function testExcludedAttributesDropAttributesAndSubAttributes(): void
    {
        $projected = $this->projector->project(
            $this->user(),
            self::USER,
            null,
            'emails,name.formatted,id,schemas,unknown'
        );

        self::assertArrayNotHasKey('emails', $projected);
        self::assertSame(['givenName' => 'Jane', 'familyName' => 'Doe'], $projected['name']);
        self::assertSame('7', $projected['id']);
        self::assertSame([self::USER], $projected['schemas']);
    }

    public function testExcludedSubAttributeOfMultiValuedAttribute(): void
    {
        $group = ['schemas' => [self::GROUP], 'id' => '1', 'members' => [['value' => '5', 'display' => 'jane']]];

        self::assertSame(
            [['value' => '5']],
            $this->projector->project($group, self::GROUP, null, 'members.display')['members']
        );
    }

    public function testIsReturned(): void
    {
        self::assertTrue($this->projector->isReturned('members', self::GROUP, null, null));
        self::assertFalse($this->projector->isReturned('members', self::GROUP, null, 'Members'));
        self::assertTrue($this->projector->isReturned('members', self::GROUP, null, 'members.display'));
        self::assertFalse($this->projector->isReturned('members', self::GROUP, 'displayName', null));
        self::assertTrue($this->projector->isReturned('members', self::GROUP, 'displayName,members.value', null));
        self::assertTrue($this->projector->isReturned('members', self::GROUP, self::GROUP . ':members', null));
    }
}
