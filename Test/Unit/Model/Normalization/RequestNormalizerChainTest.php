<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Normalization;

use DmLab\AdminScim\Api\RequestNormalizerInterface;
use DmLab\AdminScim\Model\Config;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use PHPUnit\Framework\TestCase;

class RequestNormalizerChainTest extends TestCase
{
    private function chain(?string $provider, array $normalizers = []): RequestNormalizerChain
    {
        $config = $this->createStub(Config::class);
        $config->method('getProvider')->willReturn($provider);

        return new RequestNormalizerChain($config, $normalizers);
    }

    private function apply(RequestNormalizerChain $chain): array
    {
        return $chain->apply(RequestNormalizerInterface::RESOURCE_GROUP, 'PATCH', ['tags' => []]);
    }

    public function testEmptyChainPassesPayloadThroughUnchanged(): void
    {
        $payload = ['userName' => 'jane.doe', 'active' => true];

        self::assertSame(
            $payload,
            $this->chain(null)->apply(RequestNormalizerInterface::RESOURCE_USER, 'POST', $payload)
        );
    }

    public function testConfiguredProviderRunsOnlyItsNormalizer(): void
    {
        $chain = $this->chain('azure', [
            'okta' => $this->appendTagNormalizer('okta'),
            'azure' => $this->appendTagNormalizer('azure'),
            'google' => $this->appendTagNormalizer('google'),
        ]);

        self::assertSame(['tags' => ['azure']], $this->apply($chain));
    }

    public function testUnsetProviderRunsTheOnlyRegisteredNormalizer(): void
    {
        $chain = $this->chain(null, ['okta' => $this->appendTagNormalizer('okta')]);

        self::assertSame(['tags' => ['okta']], $this->apply($chain));
    }

    public function testUnsetProviderWithSeveralRegisteredRunsNone(): void
    {
        $chain = $this->chain(null, [
            'okta' => $this->appendTagNormalizer('okta'),
            'azure' => $this->appendTagNormalizer('azure'),
        ]);

        self::assertSame(['tags' => []], $this->apply($chain));
    }

    public function testUnknownProviderRunsNone(): void
    {
        $chain = $this->chain('onelogin', ['okta' => $this->appendTagNormalizer('okta')]);

        self::assertSame(['tags' => []], $this->apply($chain));
    }

    public function testProviderCodesPreserveDiKeysInOrder(): void
    {
        $chain = $this->chain(null, [
            'okta' => $this->appendTagNormalizer('okta'),
            'azure' => $this->appendTagNormalizer('azure'),
        ]);

        self::assertSame(['okta', 'azure'], $chain->getProviderCodes());
        self::assertSame([], $this->chain(null)->getProviderCodes());
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

        $this->apply($this->chain('rec', ['rec' => $recorder]));

        self::assertSame(
            ['resourceType' => RequestNormalizerInterface::RESOURCE_GROUP, 'operation' => 'PATCH'],
            $captured
        );
    }

    public function testRejectsNormalizerNotImplementingTheInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->chain(null, ['bad' => new \stdClass()]);
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
