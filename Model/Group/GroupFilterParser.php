<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Group;

use MageDevGroup\AdminScim\Exception\ScimException;

/**
 * Parses the narrow SCIM filter subset this server honours for `GET /Groups`:
 * `displayName eq "value"` and `externalId eq "value"` (RFC 7644 §3.4.2.2). Only
 * these two attributes with the `eq` operator are supported — everything else is
 * a `400 invalidFilter`. Returns the group table column and literal to match on.
 */
class GroupFilterParser
{
    /** SCIM attribute name (case-insensitive) → group table column. */
    private const SUPPORTED = [
        'displayname' => 'display_name',
        'externalid' => 'external_id',
    ];

    /** `<attr> eq "<value>"`, attribute/operator case-insensitive, value double-quoted. */
    private const PATTERN = '/^\s*(\w+)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i';

    /**
     * Resolve a filter expression to the column + value to match.
     *
     * @param string $filter raw `filter` query parameter
     * @return array{0:string,1:string} `[group column, value]`
     * @throws ScimException 400 `invalidFilter` for an unsupported attribute,
     *         operator, or malformed expression
     */
    public function parse(string $filter): array
    {
        if (preg_match(self::PATTERN, $filter, $m) !== 1) {
            throw ScimException::badRequest(
                'Only "displayName eq" and "externalId eq" filters are supported.',
                'invalidFilter'
            );
        }

        $attribute = strtolower($m[1]);
        if (!isset(self::SUPPORTED[$attribute])) {
            throw ScimException::badRequest(
                sprintf('Filtering on attribute "%s" is not supported.', $m[1]),
                'invalidFilter'
            );
        }

        return [self::SUPPORTED[$attribute], $this->unescape($m[2])];
    }

    /**
     * Unescape a SCIM double-quoted string literal (`\"` and `\\`).
     *
     * @param string $value
     */
    private function unescape(string $value): string
    {
        return preg_replace('/\\\\(["\\\\])/', '$1', $value) ?? $value;
    }
}
