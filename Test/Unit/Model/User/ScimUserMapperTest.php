<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ScimUserMapperTest extends TestCase
{
    /** @var ScimUserMapper */
    private ScimUserMapper $mapper;

    protected function setUp(): void
    {
        $urls = $this->createStub(EndpointUrlBuilder::class);
        $urls->method('resourceUrl')->willReturnCallback(
            static fn (string $path): string => 'https://magento.loc/admin-scim/v2/' . $path
        );

        $this->mapper = new ScimUserMapper($urls);
    }

    public function testExtractUserNameReturnsTrimmedValue(): void
    {
        self::assertSame('jane.doe', $this->mapper->extractUserName(['userName' => '  jane.doe  ']));
    }

    public function testExtractUserNameThrowsWhenMissing(): void
    {
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('Attribute "userName" is required.');

        try {
            $this->mapper->extractUserName([]);
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }

    public function testExtractUserNameThrowsWhenBlank(): void
    {
        $this->expectException(ScimException::class);

        $this->mapper->extractUserName(['userName' => '   ']);
    }

    public function testExtractUserNameThrowsWhenTooLong(): void
    {
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('exceeds the maximum length of 40');

        try {
            $this->mapper->extractUserName(['userName' => str_repeat('a', 41)]);
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }

    public function testExtractUserNameAcceptsMaxLength(): void
    {
        $userName = str_repeat('a', 40);
        self::assertSame($userName, $this->mapper->extractUserName(['userName' => $userName]));
    }

    public function testExtractEmailThrowsWhenTooLong(): void
    {
        // Valid per RFC (local <= 64, each label <= 63) but 136 chars total.
        $email = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 10) . '.com';
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('exceeds the maximum length of 128');

        try {
            $this->mapper->extractEmail(['emails' => [['value' => $email, 'primary' => true]]]);
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }

    public function testExtractEmailPrefersPrimary(): void
    {
        $email = $this->mapper->extractEmail([
            'emails' => [
                ['value' => 'secondary@example.com'],
                ['value' => 'Primary@Example.com', 'primary' => true],
            ],
        ]);

        self::assertSame('primary@example.com', $email);
    }

    public function testExtractEmailFallsBackToFirstWhenNoPrimary(): void
    {
        $email = $this->mapper->extractEmail([
            'emails' => [
                ['value' => 'First@Example.com'],
                ['value' => 'second@example.com'],
            ],
        ]);

        self::assertSame('first@example.com', $email);
    }

    public function testExtractEmailFallsBackToUserNameWhenItIsAnAddress(): void
    {
        self::assertSame(
            'jane@example.com',
            $this->mapper->extractEmail(['userName' => 'Jane@Example.com'])
        );
    }

    public function testExtractEmailThrowsWhenNoneDerivable(): void
    {
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('A valid email address is required to provision an admin user.');

        try {
            $this->mapper->extractEmail(['userName' => 'jane.doe']);
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }

    public function testExtractNameUsesGivenAndFamily(): void
    {
        self::assertSame(
            ['Jane', 'Doe'],
            $this->mapper->extractName(['name' => ['givenName' => 'Jane', 'familyName' => 'Doe']], 'fallback')
        );
    }

    public function testExtractNameSplitsFormattedWhenPartsMissing(): void
    {
        self::assertSame(
            ['Jane', 'Van Doe'],
            $this->mapper->extractName(['name' => ['formatted' => 'Jane Van Doe']], 'fallback')
        );
    }

    public function testExtractNameFallsBackForSingleWordAndMissingName(): void
    {
        self::assertSame(['fallbackName', 'SCIM'], $this->mapper->extractName([], 'fallbackName'));
        self::assertSame(
            ['Madonna', 'SCIM'],
            $this->mapper->extractName(['name' => ['givenName' => 'Madonna']], 'fallback')
        );
    }

    public function testExtractNameTruncatesToColumnLength(): void
    {
        [$first, $last] = $this->mapper->extractName(
            ['name' => ['givenName' => str_repeat('a', 40), 'familyName' => str_repeat('b', 40)]],
            'fallback'
        );

        self::assertSame(32, mb_strlen($first));
        self::assertSame(32, mb_strlen($last));
    }

    public function testExtractActiveDefaultsToTrue(): void
    {
        self::assertTrue($this->mapper->extractActive([]));
    }

    public function testExtractActiveHonorsExplicitFalse(): void
    {
        self::assertFalse($this->mapper->extractActive(['active' => false]));
    }

    public function testExtractActiveHonorsStringifiedBooleans(): void
    {
        self::assertFalse($this->mapper->extractActive(['active' => 'false']));
        self::assertFalse($this->mapper->extractActive(['active' => '0']));
        self::assertTrue($this->mapper->extractActive(['active' => 'true']));
        self::assertTrue($this->mapper->extractActive(['active' => '1']));
    }

    public function testExtractExternalId(): void
    {
        self::assertSame('ext-1', $this->mapper->extractExternalId(['externalId' => '  ext-1  ']));
        self::assertNull($this->mapper->extractExternalId([]));
        self::assertNull($this->mapper->extractExternalId(['externalId' => '   ']));
    }

    public function testToResourceRendersScimUserDocument(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getData')->willReturnCallback(
            static fn (string $field) => match ($field) {
                'username' => 'jane.doe',
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'email' => 'jane@example.com',
                'is_active' => 1,
                ScimUserMapper::EXTERNAL_ID_FIELD => 'ext-9',
                default => null,
            }
        );

        $resource = $this->mapper->toResource($user);

        self::assertSame([ScimUserMapper::SCHEMA_USER], $resource['schemas']);
        self::assertSame('42', $resource['id']);
        self::assertSame('ext-9', $resource['externalId']);
        self::assertSame('jane.doe', $resource['userName']);
        self::assertSame(
            ['formatted' => 'Jane Doe', 'givenName' => 'Jane', 'familyName' => 'Doe'],
            $resource['name']
        );
        self::assertSame([['value' => 'jane@example.com', 'primary' => true]], $resource['emails']);
        self::assertTrue($resource['active']);
        self::assertSame('User', $resource['meta']['resourceType']);
        self::assertSame('https://magento.loc/admin-scim/v2/Users/42', $resource['meta']['location']);
    }

    public function testToResourceOmitsExternalIdWhenUnset(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getData')->willReturnCallback(
            static fn (string $field) => $field === 'is_active' ? 0 : ''
        );

        $resource = $this->mapper->toResource($user);

        self::assertArrayNotHasKey('externalId', $resource);
        self::assertFalse($resource['active']);
    }
}
