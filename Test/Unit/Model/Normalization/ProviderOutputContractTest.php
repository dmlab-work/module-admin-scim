<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Normalization;

use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupPatcher;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use DmLab\AdminScim\Model\User\ScimUserPatcher;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every documented provider-normalizer output must apply through the real core
 * patchers/mappers without exception.
 */
class ProviderOutputContractTest extends TestCase
{
    /**
     * @param string $section
     * @return array<string,array<int,mixed>>
     */
    private static function fixtures(string $section): array
    {
        return (require __DIR__ . '/../../_files/provider-normalizer-outputs.php')[$section];
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public static function userPatchProvider(): array
    {
        return self::fixtures('user_patch');
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public static function userCreateProvider(): array
    {
        return self::fixtures('user_create');
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public static function groupPatchProvider(): array
    {
        return self::fixtures('group_patch');
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @param array<string,mixed> $expected
     */
    #[DataProvider('userPatchProvider')]
    public function testUserPatchApplies(array $operations, array $expected): void
    {
        $captured = [];
        $user = $this->createStub(User::class);
        $user->method('setData')->willReturnCallback(
            function ($key, $value) use (&$captured, $user) {
                $captured[$key] = $value;
                return $user;
            }
        );

        (new ScimUserPatcher($this->userMapper()))->apply($user, ['Operations' => $operations]);

        self::assertSame($expected, $captured);
    }

    /**
     * @param array<string,mixed> $payload
     */
    #[DataProvider('userCreateProvider')]
    public function testUserCreatePayloadMaps(array $payload, bool $expectedActive): void
    {
        $mapper = $this->userMapper();

        self::assertSame($expectedActive, $mapper->extractActive($payload));
        self::assertSame($payload['userName'], $mapper->extractUserName($payload));
    }

    /**
     * @param int[] $members
     * @param array<int,array<string,mixed>> $operations
     * @param int[] $expectedMembers
     */
    #[DataProvider('groupPatchProvider')]
    public function testGroupPatchApplies(
        array $members,
        array $operations,
        array $expectedMembers,
        string $expectedDisplayName
    ): void {
        $group = new ScimGroup(5, 'G');
        $patcher = new ScimGroupPatcher(new ScimGroupMapper($this->createStub(EndpointUrlBuilder::class)));

        $patcher->apply($group, $members, ['Operations' => $operations]);

        self::assertSame($expectedMembers, $members);
        self::assertSame($expectedDisplayName, $group->displayName);
        self::assertSame(5, $group->groupId);
    }

    private function userMapper(): ScimUserMapper
    {
        return new ScimUserMapper($this->createStub(EndpointUrlBuilder::class));
    }
}
