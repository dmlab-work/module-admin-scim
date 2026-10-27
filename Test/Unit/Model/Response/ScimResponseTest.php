<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Response;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Response\ScimResponse;
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

    public function testJsonSetsExtraHeaders(): void
    {
        $headers = [];
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use (&$headers, $raw) {
            $headers[$name] = $value;
            return $raw;
        });

        $this->response($raw)->json(['id' => '7'], 201, ['Location' => 'https://x/Users/7']);

        self::assertSame('https://x/Users/7', $headers['Location']);
    }

    public function testNoContentIs204WithEmptyBodyAndNoContentType(): void
    {
        $raw = $this->createMock(Raw::class);
        $raw->expects(self::once())->method('setHttpResponseCode')->with(204)->willReturnSelf();
        $raw->expects(self::never())->method('setHeader');
        $raw->expects(self::once())->method('setContents')->with('')->willReturnSelf();

        self::assertSame($raw, $this->response($raw)->noContent());
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

    public function testErrorSetsRetryAfterHeaderWhenPresent(): void
    {
        $headers = [];
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $raw->method('setHeader')->willReturnCallback(
            function (string $name, string $value, bool $replace) use (&$headers, $raw): Raw {
                $headers[$name] = [$value, $replace];
                return $raw;
            }
        );

        $this->response($raw)->error(ScimException::tooManyRequests(900));

        self::assertSame(['900', true], $headers['Retry-After'] ?? null);
        self::assertSame('429', $this->serialized['status']);
        self::assertArrayNotHasKey('scimType', $this->serialized);
    }

    public function testUnauthorizedErrorSetsWwwAuthenticateHeader(): void
    {
        $headers = [];
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $raw->method('setHeader')->willReturnCallback(
            function (string $name, string $value, bool $replace) use (&$headers, $raw): Raw {
                $headers[$name] = [$value, $replace];
                return $raw;
            }
        );

        $this->response($raw)->error(ScimException::unauthorized('Authentication failed.', true));

        self::assertSame(['Bearer realm="SCIM", error="invalid_token"', true], $headers['WWW-Authenticate'] ?? null);
    }

    public function testErrorOmitsRetryAfterByDefault(): void
    {
        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $raw->expects(self::once())->method('setHeader')
            ->with('Content-Type', 'application/scim+json', true)->willReturnSelf();

        $this->response($raw)->error(ScimException::notFound());
    }
}
