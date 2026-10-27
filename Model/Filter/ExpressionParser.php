<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Filter;

use DmLab\AdminScim\Exception\ScimException;

/**
 * Parses a SCIM filter (RFC 7644 §3.4.2.2) into a tree of plain arrays.
 *
 * Supports `eq ne co sw ew gt ge lt le pr`, `and`/`or`/`not (…)`, grouping parentheses
 * and value paths (`emails[type eq "work"]`, flattened to `emails.type`). Attribute paths
 * are lower-cased; the resource's core schema URN prefix is stripped. Nodes:
 * `['op' => 'and'|'or', 'left' => node, 'right' => node]`, `['op' => 'not', 'expr' => node]`,
 * `['op' => 'pr', 'attr' => path]`, `['op' => <compare>, 'attr' => path, 'value' => scalar|null]`.
 */
class ExpressionParser
{
    /** Comparison operators taking a value. */
    public const COMPARE = ['eq', 'ne', 'co', 'sw', 'ew', 'gt', 'ge', 'lt', 'le'];

    /** Longest filter accepted, in bytes. */
    private const MAX_LENGTH = 4096;

    /** Deepest nesting of parentheses / value paths accepted. */
    private const MAX_DEPTH = 16;

    private const TOKEN_PATTERN = '/\G\s*(?:(\()|(\))|(\[)|(\])|("(?:[^"\\\\]|\\\\.)*")|([^\s()\[\]"]+))/';

    private const ATTRIBUTE_PATTERN = '/^[a-z][\w$-]*(?:\.[a-z][\w$-]*)?$/i';

    /**
     * Parse a filter expression.
     *
     * @param string $filter
     * @param string $schema the resource's core schema URN, allowed as an attribute prefix
     * @return array<string,mixed>
     * @throws ScimException 400 `invalidFilter` when the expression is malformed
     */
    public function parse(string $filter, string $schema): array
    {
        if (strlen($filter) > self::MAX_LENGTH) {
            throw $this->invalid('The filter is too long.');
        }
        $tokens = $this->tokenize($filter);
        $pos = 0;
        $node = $this->parseOr($tokens, $pos, $schema, null, 0);
        if ($pos < count($tokens)) {
            throw $this->invalid(sprintf('Unexpected "%s" in filter.', $tokens[$pos][1]));
        }

        return $node;
    }

    /**
     * Split a filter into `[type, text]` tokens.
     *
     * @param string $filter
     * @return array<int,array{0:string,1:string}>
     */
    private function tokenize(string $filter): array
    {
        $types = [1 => '(', 2 => ')', 3 => '[', 4 => ']', 5 => 'string', 6 => 'word'];
        $tokens = [];
        $offset = 0;
        $length = strlen($filter);
        while ($offset < $length && trim(substr($filter, $offset)) !== '') {
            if (preg_match(self::TOKEN_PATTERN, $filter, $m, 0, $offset) !== 1) {
                throw $this->invalid('The filter is malformed.');
            }
            $offset += strlen($m[0]);
            foreach ($types as $group => $type) {
                if (isset($m[$group]) && $m[$group] !== '') {
                    $tokens[] = [$type, $m[$group]];
                    break;
                }
            }
        }
        if ($tokens === []) {
            throw $this->invalid('The filter is empty.');
        }

        return $tokens;
    }

    /**
     * `term ("or" term)*`.
     *
     * @param array<int,array{0:string,1:string}> $tokens
     * @param int $pos
     * @param string $schema
     * @param string|null $parent attribute of the enclosing value path
     * @param int $depth
     * @return array<string,mixed>
     */
    private function parseOr(array $tokens, int &$pos, string $schema, ?string $parent, int $depth): array
    {
        $node = $this->parseAnd($tokens, $pos, $schema, $parent, $depth);
        while ($this->isWord($tokens, $pos, 'or')) {
            $pos++;
            $node = [
                'op' => 'or',
                'left' => $node,
                'right' => $this->parseAnd($tokens, $pos, $schema, $parent, $depth),
            ];
        }

        return $node;
    }

    /**
     * `factor ("and" factor)*`.
     *
     * @param array<int,array{0:string,1:string}> $tokens
     * @param int $pos
     * @param string $schema
     * @param string|null $parent
     * @param int $depth
     * @return array<string,mixed>
     */
    private function parseAnd(array $tokens, int &$pos, string $schema, ?string $parent, int $depth): array
    {
        $node = $this->parseFactor($tokens, $pos, $schema, $parent, $depth);
        while ($this->isWord($tokens, $pos, 'and')) {
            $pos++;
            $node = [
                'op' => 'and',
                'left' => $node,
                'right' => $this->parseFactor($tokens, $pos, $schema, $parent, $depth),
            ];
        }

        return $node;
    }

    /**
     * `"(" filter ")"`, `"not" "(" filter ")"`, `attrPath "[" filter "]"` or an attribute expression.
     *
     * @param array<int,array{0:string,1:string}> $tokens
     * @param int $pos
     * @param string $schema
     * @param string|null $parent
     * @param int $depth
     * @return array<string,mixed>
     */
    private function parseFactor(array $tokens, int &$pos, string $schema, ?string $parent, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->invalid('The filter is nested too deeply.');
        }
        if (($tokens[$pos][0] ?? null) === '(') {
            $pos++;
            $node = $this->parseOr($tokens, $pos, $schema, $parent, $depth + 1);
            $this->expect($tokens, $pos, ')');

            return $node;
        }
        if ($this->isWord($tokens, $pos, 'not') && ($tokens[$pos + 1][0] ?? null) === '(') {
            $pos += 2;
            $node = $this->parseOr($tokens, $pos, $schema, $parent, $depth + 1);
            $this->expect($tokens, $pos, ')');

            return ['op' => 'not', 'expr' => $node];
        }

        $attribute = $this->attribute($tokens[$pos] ?? null, $schema, $parent);
        $pos++;
        if (($tokens[$pos][0] ?? null) === '[') {
            if ($parent !== null) {
                throw $this->invalid('Nested value paths are not allowed.');
            }
            $pos++;
            $node = $this->parseOr($tokens, $pos, $schema, $attribute, $depth + 1);
            $this->expect($tokens, $pos, ']');

            return $node;
        }

        $operator = ($tokens[$pos][0] ?? null) === 'word' ? strtolower($tokens[$pos][1]) : '';
        $pos++;
        if ($operator === 'pr') {
            return ['op' => 'pr', 'attr' => $attribute];
        }
        if (!in_array($operator, self::COMPARE, true)) {
            throw $this->invalid(sprintf('Unsupported filter operator after "%s".', $attribute));
        }
        $value = $this->literal($tokens[$pos] ?? null);
        $pos++;

        return ['op' => $operator, 'attr' => $attribute, 'value' => $value];
    }

    /**
     * The normalized attribute path a token names.
     *
     * @param array{0:string,1:string}|null $token
     * @param string $schema
     * @param string|null $parent
     */
    private function attribute(?array $token, string $schema, ?string $parent): string
    {
        if ($token === null || $token[0] !== 'word') {
            throw $this->invalid('An attribute path is expected in the filter.');
        }
        $path = $token[1];
        $prefix = $schema . ':';
        if (strncasecmp($path, $prefix, strlen($prefix)) === 0) {
            $path = substr($path, strlen($prefix));
        } elseif (stripos($path, 'urn:') === 0) {
            // Another schema (e.g. an extension): never stored, rejected as unsupported downstream.
            return strtolower($path);
        }
        if (preg_match(self::ATTRIBUTE_PATTERN, $path) !== 1) {
            throw $this->invalid(sprintf('Invalid attribute path "%s" in filter.', $token[1]));
        }

        return strtolower($parent === null ? $path : $parent . '.' . $path);
    }

    /**
     * A comparison value: JSON string, number, `true`, `false` or `null`.
     *
     * @param array{0:string,1:string}|null $token
     * @return string|int|float|bool|null
     */
    private function literal(?array $token): string|int|float|bool|null
    {
        if ($token !== null && $token[0] === 'string') {
            $value = json_decode($token[1]);
            if (is_string($value)) {
                return $value;
            }
        } elseif ($token !== null && $token[0] === 'word') {
            $word = strtolower($token[1]);
            if (in_array($word, ['true', 'false', 'null'], true)) {
                return $word === 'null' ? null : $word === 'true';
            }
            if (is_numeric($token[1])) {
                return str_contains($token[1], '.') || stripos($token[1], 'e') !== false
                    ? (float)$token[1]
                    : (int)$token[1];
            }
        }

        throw $this->invalid('A comparison value is expected in the filter.');
    }

    /**
     * Whether the token at `$pos` is the given keyword (case-insensitive).
     *
     * @param array<int,array{0:string,1:string}> $tokens
     * @param int $pos
     * @param string $word
     */
    private function isWord(array $tokens, int $pos, string $word): bool
    {
        return ($tokens[$pos][0] ?? null) === 'word' && strcasecmp($tokens[$pos][1], $word) === 0;
    }

    /**
     * Consume the expected punctuation token.
     *
     * @param array<int,array{0:string,1:string}> $tokens
     * @param int $pos
     * @param string $type
     */
    private function expect(array $tokens, int &$pos, string $type): void
    {
        if (($tokens[$pos][0] ?? null) !== $type) {
            throw $this->invalid(sprintf('"%s" is expected in the filter.', $type));
        }
        $pos++;
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
