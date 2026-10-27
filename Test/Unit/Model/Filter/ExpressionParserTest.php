<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Filter;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Filter\ExpressionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExpressionParserTest extends TestCase
{
    private const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /** @var ExpressionParser */
    private ExpressionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ExpressionParser();
    }

    public function testParsesComparisonWithCaseInsensitiveKeywords(): void
    {
        self::assertSame(
            ['op' => 'eq', 'attr' => 'username', 'value' => 'jane'],
            $this->parser->parse('userName EQ "jane"', self::USER)
        );
    }

    public function testStripsTheCoreSchemaPrefix(): void
    {
        self::assertSame(
            ['op' => 'sw', 'attr' => 'name.givenname', 'value' => 'J'],
            $this->parser->parse(self::USER . ':name.givenName sw "J"', self::USER)
        );
    }

    public function testKeepsOtherSchemaUrnsForRejectionDownstream(): void
    {
        $node = $this->parser->parse(
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber eq "1"',
            self::USER
        );

        self::assertSame('urn:ietf:params:scim:schemas:extension:enterprise:2.0:user:employeenumber', $node['attr']);
    }

    public function testAndBindsTighterThanOr(): void
    {
        $node = $this->parser->parse('a eq "1" or b eq "2" and c pr', self::USER);

        self::assertSame('or', $node['op']);
        self::assertSame(['op' => 'eq', 'attr' => 'a', 'value' => '1'], $node['left']);
        self::assertSame('and', $node['right']['op']);
        self::assertSame(['op' => 'pr', 'attr' => 'c'], $node['right']['right']);
    }

    public function testParenthesesNotAndValuePaths(): void
    {
        $node = $this->parser->parse('not (active eq false) and (emails[value co "@x.io"])', self::USER);

        self::assertSame(
            ['op' => 'not', 'expr' => ['op' => 'eq', 'attr' => 'active', 'value' => false]],
            $node['left']
        );
        self::assertSame(['op' => 'co', 'attr' => 'emails.value', 'value' => '@x.io'], $node['right']);
    }

    public function testDecodesJsonStringsAndScalars(): void
    {
        self::assertSame('a"b\\c', $this->parser->parse('userName eq "a\"b\\\\c"', self::USER)['value']);
        self::assertSame(5, $this->parser->parse('id gt 5', self::USER)['value']);
        self::assertNull($this->parser->parse('x eq null', self::USER)['value']);
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedFilters(string $filter): void
    {
        try {
            $this->parser->parse($filter, self::USER);
            self::fail('Expected ScimException');
        } catch (ScimException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('invalidFilter', $e->getScimType());
        }
    }

    public static function malformed(): array
    {
        return [
            'empty' => ['  '],
            'unknown operator' => ['userName xx "a"'],
            'missing value' => ['userName eq'],
            'unquoted string' => ['userName eq jane'],
            'unclosed paren' => ['(userName eq "a"'],
            'dangling and' => ['userName eq "a" and'],
            'nested value path' => ['emails[type[value eq "x"]]'],
            'trailing token' => ['userName eq "a" "b"'],
            'bad attribute' => ['1abc eq "a"'],
            'too deep' => [str_repeat('(', 20) . 'a pr' . str_repeat(')', 20)],
        ];
    }
}
