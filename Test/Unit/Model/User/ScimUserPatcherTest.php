<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\User;

use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\User\ScimUserMapper;
use MageDevGroup\AdminScim\Model\User\ScimUserPatcher;
use Magento\User\Model\User;
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

        self::assertSame('new.name', $captured['username']);
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

    public function testUnsupportedPathIsRejected(): void
    {
        $user = $this->createStub(User::class);

        try {
            $this->patcher->apply(
                $user,
                $this->patchOp([['op' => 'replace', 'path' => 'title', 'value' => 'Dr']])
            );
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidPath', $e->getScimType());
        }
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
                $this->patchOp([['op' => 'replace', 'path' => 'userName', 'value' => str_repeat('a', 41)]])
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
