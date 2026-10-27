<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Filter;

use DmLab\AdminScim\Exception\ScimException;
use Magento\Framework\App\ResourceConnection;

/**
 * Compiles an {@see ExpressionParser} tree into a SQL `WHERE` condition.
 *
 * The caller maps each filterable attribute path to a spec:
 * `['column' => sql, 'type' => string|boolean|integer|datetime, 'fallback' => sql, 'caseExact' => bool]`
 * (`fallback` is COALESCEd in when `column` is NULL), or a membership spec
 * `['type' => 'reference', 'table' => name, 'alias' => a, 'link' => sql, 'column' => sql]`
 * compiled to `EXISTS`. Comparisons never yield SQL NULL, so `not (…)` matches absent values.
 * String comparisons follow the column collation (case-insensitive) unless `caseExact`.
 */
class SqlBuilder
{
    private const SQL_OPERATORS = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<='];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * The SQL condition for a filter tree.
     *
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $attributes filterable attribute path => spec
     * @throws ScimException 400 `invalidFilter` for an unsupported attribute, operator or value
     */
    public function build(array $node, array $attributes): string
    {
        return match ($node['op']) {
            'and', 'or' => '(' . $this->build($node['left'], $attributes) . ' ' . strtoupper($node['op']) . ' '
                . $this->build($node['right'], $attributes) . ')',
            'not' => '(NOT ' . $this->build($node['expr'], $attributes) . ')',
            default => $this->comparison($node, $attributes),
        };
    }

    /**
     * One attribute comparison or presence test.
     *
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $attributes
     */
    private function comparison(array $node, array $attributes): string
    {
        $spec = $attributes[$node['attr']] ?? null;
        if ($spec === null) {
            throw $this->invalid(sprintf('Filtering on attribute "%s" is not supported.', $node['attr']));
        }
        if ($spec['type'] === 'reference') {
            return $this->reference($node, $spec);
        }

        $column = isset($spec['fallback'])
            ? sprintf('COALESCE(%s, %s)', $spec['column'], $spec['fallback'])
            : $spec['column'];
        if ($node['op'] === 'pr') {
            return $spec['type'] === 'string'
                ? sprintf("(%s IS NOT NULL AND %s <> '')", $column, $column)
                : sprintf('(%s IS NOT NULL)', $column);
        }

        return match ($spec['type']) {
            'string' => $this->stringComparison($column, $node, !empty($spec['caseExact'])),
            'boolean' => $this->booleanComparison($column, $node),
            'integer' => $this->orderedComparison($column, $node, $this->integerValue($node)),
            'datetime' => $this->orderedComparison($column, $node, $this->dateTimeValue($node)),
        };
    }

    /**
     * A string comparison; LIKE for `co`/`sw`/`ew`.
     *
     * @param string $column
     * @param array<string,mixed> $node
     * @param bool $caseExact compare bytes instead of the case-insensitive collation
     */
    private function stringComparison(string $column, array $node, bool $caseExact): string
    {
        $value = $node['value'];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw $this->invalid(sprintf('Attribute "%s" must be compared with a string.', $node['attr']));
        }
        $value = (string)$value;
        $connection = $this->resource->getConnection();
        $lhs = $caseExact ? sprintf('CAST(%s AS BINARY)', $column) : $column;
        $rhs = $caseExact ? 'CAST(? AS BINARY)' : '?';
        $op = $node['op'];

        if (in_array($op, ['co', 'sw', 'ew'], true)) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
            $pattern = ($op === 'sw' ? '' : '%') . $escaped . ($op === 'ew' ? '' : '%');

            return sprintf('(%s IS NOT NULL AND %s)', $column, $connection->quoteInto("$lhs LIKE $rhs", $pattern));
        }

        $condition = $connection->quoteInto(sprintf('%s %s %s', $lhs, self::SQL_OPERATORS[$op], $rhs), $value);
        if ($op === 'ne') {
            return sprintf('(%s IS NULL OR %s)', $column, $condition);
        }
        if ($caseExact && $op === 'eq') {
            // The collation-aware equality keeps the column index usable.
            $condition = $connection->quoteInto("$column = ?", $value) . ' AND ' . $condition;
        }

        return sprintf('(%s IS NOT NULL AND %s)', $column, $condition);
    }

    /**
     * `eq`/`ne` against a 0/1 flag column.
     *
     * @param string $column
     * @param array<string,mixed> $node
     */
    private function booleanComparison(string $column, array $node): string
    {
        if (!in_array($node['op'], ['eq', 'ne'], true) || !is_bool($node['value'])) {
            throw $this->invalid(sprintf('Attribute "%s" supports only eq/ne with true or false.', $node['attr']));
        }

        return sprintf('(%s %s %d)', $column, self::SQL_OPERATORS[$node['op']], $node['value'] ? 1 : 0);
    }

    /**
     * `eq ne gt ge lt le` against an already-converted value.
     *
     * @param string $column
     * @param array<string,mixed> $node
     * @param int|string $value
     */
    private function orderedComparison(string $column, array $node, int|string $value): string
    {
        $op = $node['op'];
        if (!isset(self::SQL_OPERATORS[$op])) {
            throw $this->invalid(sprintf('Operator "%s" is not supported for attribute "%s".', $op, $node['attr']));
        }
        $condition = $this->resource->getConnection()->quoteInto(
            sprintf('%s %s ?', $column, self::SQL_OPERATORS[$op]),
            $value
        );

        return $op === 'ne'
            ? sprintf('(%s IS NULL OR %s)', $column, $condition)
            : sprintf('(%s IS NOT NULL AND %s)', $column, $condition);
    }

    /**
     * `EXISTS` over a membership table: `pr`, `eq` and `ne` on the referenced id.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $spec
     */
    private function reference(array $node, array $spec): string
    {
        $connection = $this->resource->getConnection();
        $subquery = sprintf(
            'SELECT 1 FROM %s AS %s WHERE %s',
            $connection->quoteIdentifier($this->resource->getTableName($spec['table'])),
            $spec['alias'],
            $spec['link']
        );
        if ($node['op'] === 'pr') {
            return sprintf('EXISTS (%s)', $subquery);
        }
        if (!in_array($node['op'], ['eq', 'ne'], true)) {
            throw $this->invalid(sprintf('Attribute "%s" supports only eq, ne and pr.', $node['attr']));
        }
        $exists = sprintf(
            'EXISTS (%s AND %s)',
            $subquery,
            $connection->quoteInto($spec['column'] . ' = ?', $this->integerValue($node))
        );

        return $node['op'] === 'eq' ? $exists : '(NOT ' . $exists . ')';
    }

    /**
     * A non-negative integer id from an int or digit-string value.
     *
     * @param array<string,mixed> $node
     */
    private function integerValue(array $node): int
    {
        $value = $node['value'];
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }

        throw $this->invalid(sprintf('Attribute "%s" must be compared with an integer id.', $node['attr']));
    }

    /**
     * A UTC `Y-m-d H:i:s` timestamp from an xsd:dateTime value.
     *
     * @param array<string,mixed> $node
     */
    private function dateTimeValue(array $node): string
    {
        $value = $node['value'];
        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable($value))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                // Rejected below.
            }
        }

        throw $this->invalid(sprintf('Attribute "%s" must be compared with a dateTime.', $node['attr']));
    }

    /**
     * A 400 `invalidFilter` error.
     *
     * @param string $detail
     */
    private function invalid(string $detail): ScimException
    {
        return ScimException::badRequest($detail, 'invalidFilter');
    }
}
