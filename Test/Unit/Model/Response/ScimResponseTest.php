<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\Response;

use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class ScimResponseTest extends TestCase
{
    /** @var array<string,mixed>|null last payload handed to the serializer */
    private ?array $serialized = null;

    private function response(Raw $raw): ScimResponse
    {
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $serializer = $this->createStub(Json::class);
        $serializer->method('serialize')->willReturnCallback(function ($value): string {
            $this->serialized = $value;
            return json_encode($value);
        });

        return new ScimResponse($rawFactory, $serializer);
    }

    public function testJsonSetsStatusMediaTypeAndBody(): void
    {
        $raw = $this->createMock(Raw::class);
        $raw->expects(self::once())->method('setHttpResponseCode')->with(200)->willReturnSelf();
        $raw->expects(self::once())->method('setHeader')
            ->with('Content-Type', 'application/scim+json', true)->willReturnSelf();
        $raw->expects(self::once())->method('setContents')
            ->with('{"totalResults":0}')->willReturnSelf();

        $result = $this->response($raw)->json(['totalResults' => 0]);

        self::assertSame($raw, $result);
    }

    public function testJsonUsesGivenStatus(): void
    {
        $raw = $this->createMock(Raw::class);
        $raw->expects(self::once())->method('setHttpResponseCode')->with(201)->willReturnSelf();
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();

        $this->response($raw)->json(['id' => 'abc'], 201);
    }

    public function testErrorBuildsRfcSchemaWithoutScimType(): void
    {
        $raw = $this->createMock(Raw::class);
        $raw->expects(self::once())->method('setHttpResponseCode')->with(401)->willReturnSelf();
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();

        $this->response($raw)->error(ScimException::unauthorized('Invalid bearer token.'));

        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => 'Invalid bearer token.',
            'status' => '401',
        ], $this->serialized);
    }

    public function testErrorIncludesScimTypeWhenPresent(): void
    {
        $raw = $this->createMock(Raw::class);
        $raw->expects(self::once())->method('setHttpResponseCode')->with(409)->willReturnSelf();
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();

        $this->response($raw)->error(ScimException::conflict('userName already exists.'));

        self::assertSame([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'scimType' => 'uniqueness',
            'detail' => 'userName already exists.',
            'status' => '409',
        ], $this->serialized);
    }
}
