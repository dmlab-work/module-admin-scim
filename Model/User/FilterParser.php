<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Filter\ExpressionParser;
use DmLab\AdminScim\Model\Filter\SqlBuilder;

/**
 * Compiles a `GET /Users` filter (RFC 7644 §3.4.2.2) into a condition on the admin_user
 * collection (`main_table`). Filterable: `id`, `userName`, `externalId` (case-exact),
 * `emails[.value]`, `name.givenName`, `name.familyName`, `active`, `meta.created`,
 * `meta.lastModified`; anything else is `400 invalidFilter`.
 */
class FilterParser
{
    /** Filterable attribute path => {@see SqlBuilder} spec. */
    private const ATTRIBUTES = [
        'id' => ['column' => 'main_table.user_id', 'type' => 'integer'],
        'username' => [
            'column' => 'main_table.' . ScimUserMapper::USER_NAME_FIELD,
            'fallback' => 'main_table.username',
            'type' => 'string',
        ],
        'externalid' => [
            'column' => 'main_table.' . ScimUserMapper::EXTERNAL_ID_FIELD,
            'type' => 'string',
            'caseExact' => true,
        ],
        'emails' => ['column' => 'main_table.email', 'type' => 'string'],
        'emails.value' => ['column' => 'main_table.email', 'type' => 'string'],
        'name.givenname' => ['column' => 'main_table.firstname', 'type' => 'string'],
        'name.familyname' => ['column' => 'main_table.lastname', 'type' => 'string'],
        'active' => ['column' => 'main_table.is_active', 'type' => 'boolean'],
        'meta.created' => ['column' => 'main_table.created', 'type' => 'datetime'],
        'meta.lastmodified' => ['column' => 'main_table.modified', 'type' => 'datetime'],
    ];

    /**
     * @param ExpressionParser $parser
     * @param SqlBuilder $sqlBuilder
     */
    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly SqlBuilder $sqlBuilder
    ) {
    }

    /**
     * The SQL condition selecting the admins a filter matches.
     *
     * @param string $filter raw `filter` parameter
     * @throws ScimException 400 `invalidFilter` for a malformed or unsupported filter
     */
    public function parse(string $filter): string
    {
        return $this->sqlBuilder->build($this->parser->parse($filter, ScimUserMapper::SCHEMA_USER), self::ATTRIBUTES);
    }
}
