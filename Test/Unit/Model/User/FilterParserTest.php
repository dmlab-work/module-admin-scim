<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\User;

use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\User\FilterParser;
use MageDevGroup\AdminScim\Model\User\ScimUserMapper;
use PHPUnit\Framework\TestCase;

class FilterParserTest extends TestCase
{
    /** @var FilterParser */
    private FilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FilterParser();
    }

    public function testParsesUserNameFilter(): void
    {
        self::assertSame(['username', 'jane.doe'], $this->parser->parse('userName eq "jane.doe"'));
    }

    public function testParsesExternalIdFilter(): void
    {
        self::assertSame(
            [ScimUserMapper::EXTERNAL_ID_FIELD, 'ext-9'],
            $this->parser->parse('externalId eq "ext-9"')
        );
    }

    public function testAttributeAndOperatorAreCaseInsensitive(): void
    {
        self::assertSame(['username', 'jane'], $this->parser->parse('USERNAME EQ "jane"'));
    }

    public function testUnescapesQuotedLiteral(): void
    {
        self::assertSame(['username', 'a"b\\c'], $this->parser->parse('userName eq "a\\"b\\\\c"'));
    }

    public function testRejectsUnsupportedAttribute(): void
    {
        try {
            $this->parser->parse('displayName eq "admins"');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidFilter', $e->getScimType());
        }
    }

    public function testRejectsUnsupportedOperator(): void
    {
        try {
            $this->parser->parse('userName co "jane"');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidFilter', $e->getScimType());
        }
    }

    public function testRejectsMalformedExpression(): void
    {
        try {
            $this->parser->parse('not a filter');
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidFilter', $e->getScimType());
        }
    }
}
