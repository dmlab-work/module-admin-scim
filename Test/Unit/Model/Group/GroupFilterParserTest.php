<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Group;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Group\GroupFilterParser;
use PHPUnit\Framework\TestCase;

class GroupFilterParserTest extends TestCase
{
    /** @var GroupFilterParser */
    private GroupFilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GroupFilterParser();
    }

    public function testParsesDisplayNameFilter(): void
    {
        self::assertSame(['display_name', 'Admins'], $this->parser->parse('displayName eq "Admins"'));
    }

    public function testParsesExternalIdFilterCaseInsensitively(): void
    {
        self::assertSame(['external_id', 'grp-1'], $this->parser->parse('ExternalId EQ "grp-1"'));
    }

    public function testUnescapesQuotedValue(): void
    {
        self::assertSame(['display_name', 'a"b'], $this->parser->parse('displayName eq "a\\"b"'));
    }

    public function testRejectsUnsupportedAttribute(): void
    {
        $this->expectException(ScimException::class);

        try {
            $this->parser->parse('members eq "5"');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidFilter', $e->getScimType());
            throw $e;
        }
    }

    public function testRejectsMalformedExpression(): void
    {
        $this->expectException(ScimException::class);

        $this->parser->parse('displayName co "Adm"');
    }
}
