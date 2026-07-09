<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Model\Normalization;

use MageDevGroup\AdminScim\Api\RequestNormalizerInterface;
use MageDevGroup\AdminScim\Model\Normalization\RequestNormalizerChain;
use PHPUnit\Framework\TestCase;

class RequestNormalizerChainTest extends TestCase
{
    public function testEmptyChainPassesPayloadThroughUnchanged(): void
    {
        $chain = new RequestNormalizerChain();
        $payload = ['userName' => 'jane.doe', 'active' => true];

        self::assertSame(
            $payload,
            $chain->apply(RequestNormalizerInterface::RESOURCE_USER, 'POST', $payload)
        );
    }

    public function testRegisteredNormalizerRewritesPayload(): void
    {
        $chain = new RequestNormalizerChain([$this->upperCaseUserNameNormalizer()]);

        self::assertSame(
            ['userName' => 'JANE.DOE'],
            $chain->apply(RequestNormalizerInterface::RESOURCE_USER, 'POST', ['userName' => 'jane.doe'])
        );
    }

    public function testNormalizersRunInOrderFeedingEachOutputToTheNext(): void
    {
        $appendA = $this->appendTagNormalizer('a');
        $appendB = $this->appendTagNormalizer('b');
        $chain = new RequestNormalizerChain([$appendA, $appendB]);

        self::assertSame(
            ['tags' => ['a', 'b']],
            $chain->apply(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', ['tags' => []])
        );
    }

    public function testNormalizerReceivesResourceTypeAndOperation(): void
    {
        $captured = [];
        $recorder = new class ($captured) implements RequestNormalizerInterface {
            /** @param array<string,string> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function normalize(string $resourceType, string $operation, array $payload): array
            {
                $this->captured['resourceType'] = $resourceType;
                $this->captured['operation'] = $operation;
                return $payload;
            }
        };

        (new RequestNormalizerChain([$recorder]))
            ->apply(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', []);

        self::assertSame(
            ['resourceType' => RequestNormalizerInterface::RESOURCE_GROUP, 'operation' => 'PATCH'],
            $captured
        );
    }

    public function testRejectsNormalizerNotImplementingTheInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RequestNormalizerChain([new \stdClass()]);
    }

    private function upperCaseUserNameNormalizer(): RequestNormalizerInterface
    {
        return new class implements RequestNormalizerInterface {
            public function normalize(string $resourceType, string $operation, array $payload): array
            {
                if (isset($payload['userName'])) {
                    $payload['userName'] = strtoupper((string)$payload['userName']);
                }
                return $payload;
            }
        };
    }

    private function appendTagNormalizer(string $tag): RequestNormalizerInterface
    {
        return new class ($tag) implements RequestNormalizerInterface {
            public function __construct(private readonly string $tag)
            {
            }

            public function normalize(string $resourceType, string $operation, array $payload): array
            {
                $payload['tags'][] = $this->tag;
                return $payload;
            }
        };
    }
}
