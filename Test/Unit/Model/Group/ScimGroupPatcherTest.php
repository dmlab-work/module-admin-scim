<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\EndpointUrlBuilder;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupPatcher;
use PHPUnit\Framework\TestCase;

class ScimGroupPatcherTest extends TestCase
{
    /** @var ScimGroupPatcher */
    private ScimGroupPatcher $patcher;

    protected function setUp(): void
    {
        // Real mapper — member-value parsing is part of what we exercise.
        $this->patcher = new ScimGroupPatcher(new ScimGroupMapper($this->createStub(EndpointUrlBuilder::class)));
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @param int[] $memberIds
     * @return array{group:ScimGroup,members:int[]}
     */
    private function apply(ScimGroup $group, array $memberIds, array $operations): array
    {
        $this->patcher->apply($group, $memberIds, ['Operations' => $operations]);

        return ['group' => $group, 'members' => $memberIds];
    }

    public function testReplacesDisplayName(): void
    {
        $result = $this->apply(new ScimGroup(1, 'Old'), [], [
            ['op' => 'replace', 'path' => 'displayName', 'value' => 'New'],
        ]);

        self::assertSame('New', $result['group']->displayName);
    }

    public function testAddMembersMergesWithoutDuplicates(): void
    {
        $result = $this->apply(new ScimGroup(1, 'G'), [5], [
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => '5'], ['value' => '7']]],
        ]);

        self::assertSame([5, 7], $result['members']);
    }

    public function testReplaceMembersOverwritesCollection(): void
    {
        $result = $this->apply(new ScimGroup(1, 'G'), [5, 6], [
            ['op' => 'replace', 'path' => 'members', 'value' => [['value' => '9']]],
        ]);

        self::assertSame([9], $result['members']);
    }

    public function testRemovesMemberByFilteredPath(): void
    {
        $result = $this->apply(new ScimGroup(1, 'G'), [5, 7], [
            ['op' => 'remove', 'path' => 'members[value eq "5"]'],
        ]);

        self::assertSame([7], $result['members']);
    }

    public function testRemoveMembersWithoutValueClearsAll(): void
    {
        $result = $this->apply(new ScimGroup(1, 'G'), [5, 7], [
            ['op' => 'remove', 'path' => 'members'],
        ]);

        self::assertSame([], $result['members']);
    }

    public function testRemoveMembersWithValueListRemovesThose(): void
    {
        $result = $this->apply(new ScimGroup(1, 'G'), [5, 7, 9], [
            ['op' => 'remove', 'path' => 'members', 'value' => [['value' => '7']]],
        ]);

        self::assertSame([5, 9], $result['members']);
    }

    public function testRemovesExternalId(): void
    {
        $result = $this->apply(new ScimGroup(1, 'G', 'grp-1'), [], [
            ['op' => 'remove', 'path' => 'externalId'],
        ]);

        self::assertNull($result['group']->externalId);
    }

    public function testPathlessValueObjectAppliesEachAttribute(): void
    {
        $result = $this->apply(new ScimGroup(1, 'Old'), [1], [
            ['op' => 'replace', 'value' => ['displayName' => 'New', 'members' => [['value' => '3']]]],
        ]);

        self::assertSame('New', $result['group']->displayName);
        self::assertSame([3], $result['members']);
    }

    public function testAddOnFilteredMemberPathIsRejected(): void
    {
        $this->expectException(ScimException::class);

        try {
            $this->apply(new ScimGroup(1, 'G'), [], [
                ['op' => 'add', 'path' => 'members[value eq "5"]', 'value' => 'x'],
            ]);
        } catch (ScimException $e) {
            self::assertSame('invalidPath', $e->getScimType());
            throw $e;
        }
    }

    public function testUnsupportedPathIsRejected(): void
    {
        $this->expectException(ScimException::class);

        $this->apply(new ScimGroup(1, 'G'), [], [
            ['op' => 'replace', 'path' => 'nonsense', 'value' => 'x'],
        ]);
    }

    public function testEmptyOperationsIsRejected(): void
    {
        $this->expectException(ScimException::class);

        $members = [];
        try {
            $this->patcher->apply(new ScimGroup(1, 'G'), $members, ['Operations' => []]);
        } catch (ScimException $e) {
            self::assertSame('invalidSyntax', $e->getScimType());
            throw $e;
        }
    }

    public function testRemoveWithoutPathIsRejected(): void
    {
        $this->expectException(ScimException::class);

        $this->apply(new ScimGroup(1, 'G'), [], [['op' => 'remove']]);
    }

    public function testUnsupportedOpIsRejected(): void
    {
        $this->expectException(ScimException::class);

        $this->apply(new ScimGroup(1, 'G'), [], [['op' => 'move', 'path' => 'members', 'value' => []]]);
    }

    public function testDisplayNameReplaceRequiresNonEmptyValue(): void
    {
        $this->expectException(ScimException::class);

        try {
            $this->apply(new ScimGroup(1, 'G'), [], [
                ['op' => 'replace', 'path' => 'displayName', 'value' => '  '],
            ]);
        } catch (ScimException $e) {
            self::assertSame('invalidValue', $e->getScimType());
            throw $e;
        }
    }
}
