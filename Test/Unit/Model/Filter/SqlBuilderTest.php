<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Filter;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Filter\SqlBuilder;
use DmLab\AdminScim\Test\Unit\Doubles\QuotingConnectionTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlBuilderTest extends TestCase
{
    use QuotingConnectionTrait;

    private const ATTRIBUTES = [
        'name' => ['column' => 't.name', 'type' => 'string'],
        'login' => ['column' => 't.scim', 'fallback' => 't.login', 'type' => 'string'],
        'ext' => ['column' => 't.ext', 'type' => 'string', 'caseExact' => true],
        'active' => ['column' => 't.active', 'type' => 'boolean'],
        'id' => ['column' => 't.id', 'type' => 'integer'],
        'created' => ['column' => 't.created', 'type' => 'datetime'],
        'members' => [
            'type' => 'reference',
            'table' => 'member',
            'alias' => 'm',
            'link' => 'm.group_id = t.id',
            'column' => 'm.user_id',
        ],
    ];

    private function build(array $node): string
    {
        return (new SqlBuilder($this->quotingResource()))->build($node, self::ATTRIBUTES);
    }

    #[DataProvider('comparisons')]
    public function testCompilesComparisons(array $node, string $sql): void
    {
        self::assertSame($sql, $this->build($node));
    }

    public static function comparisons(): array
    {
        return [
            'eq' => [['op' => 'eq', 'attr' => 'name', 'value' => 'Ann'], "(t.name IS NOT NULL AND t.name = 'Ann')"],
            'ne matches null' => [
                ['op' => 'ne', 'attr' => 'name', 'value' => 'Ann'],
                "(t.name IS NULL OR t.name <> 'Ann')",
            ],
            'co escapes wildcards' => [
                ['op' => 'co', 'attr' => 'name', 'value' => '50%_off'],
                "(t.name IS NOT NULL AND t.name LIKE '%50\\\\%\\\\_off%')",
            ],
            'sw' => [['op' => 'sw', 'attr' => 'name', 'value' => 'A'], "(t.name IS NOT NULL AND t.name LIKE 'A%')"],
            'ew' => [['op' => 'ew', 'attr' => 'name', 'value' => 'n'], "(t.name IS NOT NULL AND t.name LIKE '%n')"],
            'pr string' => [['op' => 'pr', 'attr' => 'name'], "(t.name IS NOT NULL AND t.name <> '')"],
            'fallback' => [
                ['op' => 'eq', 'attr' => 'login', 'value' => 'x'],
                "(COALESCE(t.scim, t.login) IS NOT NULL AND COALESCE(t.scim, t.login) = 'x')",
            ],
            'case-exact eq keeps the index predicate' => [
                ['op' => 'eq', 'attr' => 'ext', 'value' => 'AbC'],
                "(t.ext IS NOT NULL AND t.ext = 'AbC' AND CAST(t.ext AS BINARY) = CAST('AbC' AS BINARY))",
            ],
            'boolean' => [['op' => 'eq', 'attr' => 'active', 'value' => false], '(t.active = 0)'],
            'integer from string id' => [
                ['op' => 'gt', 'attr' => 'id', 'value' => '5'],
                '(t.id IS NOT NULL AND t.id > 5)',
            ],
            'datetime converted to UTC' => [
                ['op' => 'ge', 'attr' => 'created', 'value' => '2026-01-01T02:00:00+02:00'],
                "(t.created IS NOT NULL AND t.created >= '2026-01-01 00:00:00')",
            ],
            'members eq' => [
                ['op' => 'eq', 'attr' => 'members', 'value' => '7'],
                'EXISTS (SELECT 1 FROM `member` AS m WHERE m.group_id = t.id AND m.user_id = 7)',
            ],
            'members pr' => [
                ['op' => 'pr', 'attr' => 'members'],
                'EXISTS (SELECT 1 FROM `member` AS m WHERE m.group_id = t.id)',
            ],
        ];
    }

    public function testCompilesLogicalOperators(): void
    {
        $sql = $this->build([
            'op' => 'or',
            'left' => ['op' => 'not', 'expr' => ['op' => 'pr', 'attr' => 'id']],
            'right' => [
                'op' => 'and',
                'left' => ['op' => 'pr', 'attr' => 'id'],
                'right' => ['op' => 'pr', 'attr' => 'id'],
            ],
        ]);

        self::assertSame('((NOT (t.id IS NOT NULL)) OR ((t.id IS NOT NULL) AND (t.id IS NOT NULL)))', $sql);
    }

    #[DataProvider('unsupported')]
    public function testRejectsUnsupportedComparisons(array $node): void
    {
        try {
            $this->build($node);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame('invalidFilter', $e->getScimType());
        }
    }

    public static function unsupported(): array
    {
        return [
            'unknown attribute' => [['op' => 'eq', 'attr' => 'title', 'value' => 'x']],
            'boolean co' => [['op' => 'co', 'attr' => 'active', 'value' => true]],
            'boolean with string' => [['op' => 'eq', 'attr' => 'active', 'value' => 'true']],
            'integer co' => [['op' => 'co', 'attr' => 'id', 'value' => '1']],
            'non-numeric id' => [['op' => 'eq', 'attr' => 'id', 'value' => 'abc']],
            'bad date' => [['op' => 'gt', 'attr' => 'created', 'value' => 'yesterday-ish']],
            'string with null' => [['op' => 'eq', 'attr' => 'name', 'value' => null]],
            'members gt' => [['op' => 'gt', 'attr' => 'members', 'value' => '1']],
        ];
    }
}
