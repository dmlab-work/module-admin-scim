<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Response;

use DmLab\AdminScim\Model\Response\ListResponseBuilder;
use PHPUnit\Framework\TestCase;

class ListResponseBuilderTest extends TestCase
{
    /** @var ListResponseBuilder */
    private ListResponseBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ListResponseBuilder();
    }

    public function testBuildsListResponseEnvelope(): void
    {
        $resources = [['id' => '1'], ['id' => '2']];

        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => 7,
            'startIndex' => 3,
            'itemsPerPage' => 2,
            'Resources' => $resources,
        ], $this->builder->build($resources, 7, 3, 2));
    }

    public function testEmptyPageStillCarriesResourcesArray(): void
    {
        $result = $this->builder->build([], 0, 1, 0);

        self::assertSame(0, $result['totalResults']);
        self::assertSame([], $result['Resources']);
    }
}
