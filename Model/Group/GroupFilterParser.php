<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Filter\ExpressionParser;
use DmLab\AdminScim\Model\Filter\SqlBuilder;

/**
 * Compiles a `GET /Groups` filter (RFC 7644 §3.4.2.2) into a condition on the group table
 * (alias `g`). Filterable: `id`, `displayName`, `externalId` (case-exact), `members[.value]`
 * (`eq`/`ne`/`pr`), `meta.created`, `meta.lastModified`; anything else is `400 invalidFilter`.
 */
class GroupFilterParser
{
    /** Alias of the group table in {@see GroupResource} list queries. */
    public const TABLE_ALIAS = 'g';

    private const MEMBERS = [
        'type' => 'reference',
        'table' => 'dmlab_scim_group_member',
        'alias' => 'm',
        'link' => 'm.group_id = g.group_id',
        'column' => 'm.user_id',
    ];

    /** Filterable attribute path => {@see SqlBuilder} spec. */
    private const ATTRIBUTES = [
        'id' => ['column' => 'g.group_id', 'type' => 'integer'],
        'displayname' => ['column' => 'g.display_name', 'type' => 'string'],
        'externalid' => ['column' => 'g.external_id', 'type' => 'string', 'caseExact' => true],
        'members' => self::MEMBERS,
        'members.value' => self::MEMBERS,
        'meta.created' => ['column' => 'g.created_at', 'type' => 'datetime'],
        'meta.lastmodified' => ['column' => 'g.updated_at', 'type' => 'datetime'],
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
     * The SQL condition selecting the groups a filter matches.
     *
     * @param string $filter raw `filter` parameter
     * @throws ScimException 400 `invalidFilter` for a malformed or unsupported filter
     */
    public function parse(string $filter): string
    {
        return $this->sqlBuilder->build(
            $this->parser->parse($filter, ScimGroupMapper::SCHEMA_GROUP),
            self::ATTRIBUTES
        );
    }
}
