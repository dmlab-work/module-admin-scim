<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\User;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Filter\ExpressionParser;
use DmLab\AdminScim\Model\Filter\SqlBuilder;
use DmLab\AdminScim\Model\User\FilterParser;
use DmLab\AdminScim\Test\Unit\Doubles\QuotingConnectionTrait;
use PHPUnit\Framework\TestCase;

class FilterParserTest extends TestCase
{
    use QuotingConnectionTrait;

    /** @var FilterParser */
    private FilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FilterParser(new ExpressionParser(), new SqlBuilder($this->quotingResource()));
    }

    public function testUserNameMatchesTheStoredScimUserNameOrTheLogin(): void
    {
        self::assertSame(
            "(COALESCE(main_table.dmlab_scim_user_name, main_table.username) IS NOT NULL"
            . " AND COALESCE(main_table.dmlab_scim_user_name, main_table.username) = 'jane.doe')",
            $this->parser->parse('userName eq "jane.doe"')
        );
    }

    public function testExternalIdIsCaseExact(): void
    {
        self::assertStringContainsString(
            "CAST(main_table.dmlab_scim_external_id AS BINARY) = CAST('Ext-9' AS BINARY)",
            $this->parser->parse('urn:ietf:params:scim:schemas:core:2.0:User:externalId eq "Ext-9"')
        );
    }

    public function testCombinesSupportedAttributes(): void
    {
        $sql = $this->parser->parse('active eq true and (emails co "@x.io" or name.familyName sw "D")');

        self::assertStringContainsString('main_table.is_active = 1', $sql);
        self::assertStringContainsString("main_table.email LIKE '%@x.io%'", $sql);
        self::assertStringContainsString("main_table.lastname LIKE 'D%'", $sql);
    }

    public function testRejectsUnsupportedAttribute(): void
    {
        try {
            $this->parser->parse('title eq "Boss"');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidFilter', $e->getScimType());
        }
    }
}
