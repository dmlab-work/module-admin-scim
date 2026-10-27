<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Filter\ExpressionParser;
use DmLab\AdminScim\Model\Filter\SqlBuilder;
use DmLab\AdminScim\Model\Group\GroupFilterParser;
use DmLab\AdminScim\Test\Unit\Doubles\QuotingConnectionTrait;
use PHPUnit\Framework\TestCase;

class GroupFilterParserTest extends TestCase
{
    use QuotingConnectionTrait;

    /** @var GroupFilterParser */
    private GroupFilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GroupFilterParser(new ExpressionParser(), new SqlBuilder($this->quotingResource()));
    }

    public function testDisplayNameFilter(): void
    {
        self::assertSame(
            "(g.display_name IS NOT NULL AND g.display_name = 'Admins')",
            $this->parser->parse('urn:ietf:params:scim:schemas:core:2.0:Group:displayName eq "Admins"')
        );
    }

    public function testEntraMembershipProbe(): void
    {
        self::assertSame(
            "((g.group_id IS NOT NULL AND g.group_id = 10) AND EXISTS (SELECT 1 FROM `dmlab_scim_group_member`"
            . " AS m WHERE m.group_id = g.group_id AND m.user_id = 5))",
            $this->parser->parse('id eq "10" and members[value eq "5"]')
        );
    }

    public function testExternalIdIsCaseExact(): void
    {
        self::assertStringContainsString(
            "CAST(g.external_id AS BINARY) = CAST('Grp' AS BINARY)",
            $this->parser->parse('externalId eq "Grp"')
        );
    }

    public function testRejectsUnsupportedAttribute(): void
    {
        $this->expectException(ScimException::class);
        $this->parser->parse('members.display eq "jane"');
    }
}
