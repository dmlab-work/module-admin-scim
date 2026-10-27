<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use DmLab\AdminScim\Model\User\ScimUserPatcher;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScimUserPatcherTest extends TestCase
{
    /** @var ScimUserMapper&\PHPUnit\Framework\MockObject\Stub */
    private $mapper;

    /** @var ScimUserPatcher */
    private ScimUserPatcher $patcher;

    protected function setUp(): void
    {
        $this->mapper = $this->createStub(ScimUserMapper::class);
        $this->mapper->method('toLogin')->willReturnCallback(static fn (string $u): string => 'login:' . $u);
        $this->patcher = new ScimUserPatcher($this->mapper);
    }

    /**
     * A User model whose setData() records every field into $captured.
     *
     * @param array<string,mixed> $captured
     * @return User&\PHPUnit\Framework\MockObject\Stub
     */
    private function recordingUser(array &$captured)
    {
        $user = $this->createStub(User::class);
        $user->method('setData')->willReturnCallback(
            function ($key, $value) use (&$captured, $user) {
                $captured[$key] = $value;
                return $user;
            }
        );

        return $user;
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @return array<string,mixed>
     */
    private function patchOp(array $operations): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => $operations,
        ];
    }

    public function testReplaceActiveFalseDisablesUser(): void
    {
        $captured = [];
        $user = $this->recordingUser($captured);

        $this->patcher->apply($user, $this->patchOp([
            ['op' => 'replace', 'path' => 'active', 'value' => false],
        ]));

        self::assertSame(0, $captured['is_active']);
    }

    public function testReplaceActiveTrueReenablesUser(): void
    {
        $captured = [];
        $user = $this->recordingUser($captured);

        $this->patcher->apply($user, $this->patchOp([
            ['op' => 'replace', 'path' => 'active', 'value' => true],
        ]));

        self::assertSame(1, $captured['is_active']);
    }

    public function testAddAndReplaceBehaveIdenticallyForActive(): void
    {
        $addCaptured = [];
        $replaceCaptured = [];

        $this->patcher->apply(
            $this->recordingUser($addCaptured),
            $this->patchOp([['op' => 'add', 'path' => 'active', 'value' => false]])
        );
        $this->patcher->apply(
            $this->recordingUser($replaceCaptured),
            $this->patchOp([['op' => 'Replace', 'path' => 'active', 'value' => false]])
        );

        self::assertSame(0, $addCaptured['is_active']);
        self::assertSame($addCaptured, $replaceCaptured);
    }

    public function testCoercesStringBooleanForActive(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'path' => 'active', 'value' => 'False']])
        );

        self::assertSame(0, $captured['is_active']);
    }

    public function testUpdatesGivenName(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Janet']])
        );

        self::assertSame('Janet', $captured['firstname']);
    }

    public function testUpdatesUserName(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'path' => 'userName', 'value' => ' new.name ']])
        );

        self::assertSame('new.name', $captured[ScimUserMapper::USER_NAME_FIELD]);
        self::assertSame('login:new.name', $captured['username']);
    }

    public function testPathlessObjectValueAssignsEachAttribute(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([[
                'op' => 'replace',
                'value' => ['active' => false, 'name.givenName' => 'Jo', 'externalId' => 'ext-77'],
            ]])
        );

        self::assertSame(0, $captured['is_active']);
        self::assertSame('Jo', $captured['firstname']);
        self::assertSame('ext-77', $captured[ScimUserMapper::EXTERNAL_ID_FIELD]);
    }

    public function testNameComplexValueSetsBothParts(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([[
                'op' => 'replace',
                'path' => 'name',
                'value' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
            ]])
        );

        self::assertSame('Ada', $captured['firstname']);
        self::assertSame('Lovelace', $captured['lastname']);
    }

    public function testFormattedNameIsSplit(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'path' => 'name.formatted', 'value' => 'Grace Hopper']])
        );

        self::assertSame('Grace', $captured['firstname']);
        self::assertSame('Hopper', $captured['lastname']);
    }

    public function testEmailsPathDelegatesToMapper(): void
    {
        $this->mapper->method('extractEmail')->willReturn('new@example.com');
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([[
                'op' => 'replace',
                'path' => 'emails',
                'value' => [['value' => 'new@example.com', 'primary' => true]],
            ]])
        );

        self::assertSame('new@example.com', $captured['email']);
    }

    public function testRemoveExternalIdClearsIt(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'remove', 'path' => 'externalId']])
        );

        self::assertArrayHasKey(ScimUserMapper::EXTERNAL_ID_FIELD, $captured);
        self::assertNull($captured[ScimUserMapper::EXTERNAL_ID_FIELD]);
    }

    public function testMultipleOperationsApplyInOrder(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([
                ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'First'],
                ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Second'],
            ])
        );

        self::assertSame('Second', $captured['firstname']);
    }

    public function testEmptyOperationsListIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidSyntax', $e->getScimType());
        }
    }

    public function testMissingOperationsIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, ['schemas' => ['x']]);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidSyntax', $e->getScimType());
        }
    }

    public function testUnknownOpIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'merge', 'path' => 'active', 'value' => true]]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidSyntax', $e->getScimType());
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unparseablePathProvider(): array
    {
        return [
            'unbalanced bracket' => ['emails[type eq "work"'],
            'empty filter' => ['emails[]'],
            'space in name' => ['name givenName'],
            'leading digit' => ['1name'],
            'double sub' => ['name.givenName.x'],
        ];
    }

    #[DataProvider('unparseablePathProvider')]
    public function testUnparseablePathIsRejected(string $path): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'replace', 'path' => $path, 'value' => 'x']]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidPath', $e->getScimType());
        }
    }

    public function testUnparseablePathInRemoveIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'remove', 'path' => 'emails[']]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidPath', $e->getScimType());
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function ignoredPathProvider(): array
    {
        $paths = [
            'displayName', 'nickName', 'profileUrl', 'title', 'userType', 'preferredLanguage', 'locale',
            'timezone', 'password', 'phoneNumbers', 'ims', 'photos', 'addresses', 'groups', 'entitlements',
            'roles', 'x509Certificates', 'name.middleName', 'name.honorificPrefix', 'name.honorificSuffix',
            'id', 'meta', 'schemas', 'unknownAttribute', 'meta.lastModified',
            'phoneNumbers[type eq "work"].value',
            'emails[type eq "home"].value',
            'emails[type eq "work"]',
            'urn:ietf:params:scim:schemas:core:2.0:User:title',
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User',
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department',
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager.value',
        ];

        return array_combine($paths, array_map(static fn (string $p): array => [$p], $paths));
    }

    #[DataProvider('ignoredPathProvider')]
    public function testUnstoredAttributeIsIgnoredOnReplace(string $path): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'path' => $path, 'value' => 'x']])
        );

        self::assertSame([], $captured);
    }

    #[DataProvider('ignoredPathProvider')]
    public function testUnstoredAttributeRemoveIsNoOp(string $path): void
    {
        $captured = [];
        $this->patcher->apply($this->recordingUser($captured), $this->patchOp([['op' => 'remove', 'path' => $path]]));

        self::assertSame([], $captured);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function requiredPathProvider(): array
    {
        $paths = [
            'userName', 'name', 'name.givenName', 'name.familyName', 'emails', 'active',
            'urn:ietf:params:scim:schemas:core:2.0:User:userName',
            'urn:ietf:params:scim:schemas:core:2.0:User',
            'emails[type eq "work"].value',
        ];

        return array_combine($paths, array_map(static fn (string $p): array => [$p], $paths));
    }

    #[DataProvider('requiredPathProvider')]
    public function testRemoveOfRequiredAttributeIsMutabilityError(string $path): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'remove', 'path' => $path]]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('mutability', $e->getScimType());
        }
    }

    public function testCoreUrnPrefixIsStripped(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([[
                'op' => 'replace',
                'path' => 'urn:ietf:params:scim:schemas:core:2.0:User:name.givenName',
                'value' => 'Ada',
            ]])
        );

        self::assertSame(['firstname' => 'Ada'], $captured);
    }

    public function testCoreUrnPathWithObjectValueIsPathless(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([[
                'op' => 'Replace',
                'path' => 'urn:ietf:params:scim:schemas:core:2.0:User',
                'value' => ['userName' => 'jdoe@x.io', 'active' => false],
            ]])
        );

        self::assertSame(
            [ScimUserMapper::USER_NAME_FIELD => 'jdoe@x.io', 'username' => 'login:jdoe@x.io', 'is_active' => 0],
            $captured
        );
    }

    public function testCoreUrnPathWithScalarValueIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([[
                'op' => 'replace',
                'path' => 'urn:ietf:params:scim:schemas:core:2.0:User',
                'value' => 'x',
            ]]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function emailFilterPathProvider(): array
    {
        return [
            'type work' => ['emails[type eq "work"].value'],
            'primary true' => ['emails[primary eq true].value'],
            'case and spacing' => ['Emails[ TYPE EQ "Work" ].Value'],
            'core urn prefix' => ['urn:ietf:params:scim:schemas:core:2.0:User:emails[type eq "work"].value'],
        ];
    }

    #[DataProvider('emailFilterPathProvider')]
    public function testFilteredEmailPathSetsEmail(string $path): void
    {
        $patcher = new ScimUserPatcher(new ScimUserMapper($this->createStub(EndpointUrlBuilder::class)));
        $captured = [];
        $patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'Replace', 'path' => $path, 'value' => 'A@X.io']])
        );

        self::assertSame(['email' => 'a@x.io'], $captured);
    }

    public function testFilteredEmailPathValidatesValue(): void
    {
        $patcher = new ScimUserPatcher(new ScimUserMapper($this->createStub(EndpointUrlBuilder::class)));

        try {
            $patcher->apply(
                $this->createStub(User::class),
                $this->patchOp([['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'nope']])
            );
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    public function testPathlessValueIgnoresUnstoredAndExtensionKeys(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([[
                'op' => 'Replace',
                'value' => [
                    'displayName' => 'J Doe',
                    'name.givenName' => 'J',
                    'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User' => ['manager' => ['value' => '9']],
                    'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:department' => 'R&D',
                ],
            ]])
        );

        self::assertSame(['firstname' => 'J'], $captured);
    }

    public function testBatchedDeactivateWithUnstoredAttributeApplies(): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([
                ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
                ['op' => 'Replace', 'path' => 'title', 'value' => 'Eng'],
            ])
        );

        self::assertSame(['is_active' => 0], $captured);
    }

    public function testNonObjectOperationIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp(['replace']));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidSyntax', $e->getScimType());
        }
    }

    public function testPathlessNonObjectValueIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'replace', 'value' => ['a', 'b']]]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    /**
     * @return array<string,array{0:mixed,1:int}>
     */
    public static function activeValueProvider(): array
    {
        return [
            '"true"' => ['true', 1],
            '"True"' => ['True', 1],
            '"false"' => ['false', 0],
            '"False"' => ['False', 0],
            '"1"' => ['1', 1],
            '"0"' => ['0', 0],
            'true' => [true, 1],
            'false' => [false, 0],
        ];
    }

    #[DataProvider('activeValueProvider')]
    public function testActivePathCoercesValue(mixed $value, int $expected): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'path' => 'active', 'value' => $value]])
        );

        self::assertSame($expected, $captured['is_active']);
    }

    #[DataProvider('activeValueProvider')]
    public function testPathlessActiveCoercesValue(mixed $value, int $expected): void
    {
        $captured = [];
        $this->patcher->apply(
            $this->recordingUser($captured),
            $this->patchOp([['op' => 'replace', 'value' => ['active' => $value]]])
        );

        self::assertSame($expected, $captured['is_active']);
    }

    public function testEmptyUserNameIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply(
                $user,
                $this->patchOp([['op' => 'replace', 'path' => 'userName', 'value' => '   ']])
            );
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    public function testOverLongUserNameIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply(
                $user,
                $this->patchOp([['op' => 'replace', 'path' => 'userName', 'value' => str_repeat('a', 256)]])
            );
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }

    public function testRemoveRequiredAttributeIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'remove', 'path' => 'userName']]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('mutability', $e->getScimType());
        }
    }

    public function testRemoveWithoutPathIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'remove']]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('noTarget', $e->getScimType());
        }
    }

    public function testAddWithoutValueIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply($user, $this->patchOp([['op' => 'add', 'path' => 'active']]));
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidValue', $e->getScimType());
        }
    }
}
