<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model\Config\Source;

use DmLab\AdminScim\Model\Config\Source\Provider;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    private function options(array $codes): array
    {
        $chain = $this->createStub(RequestNormalizerChain::class);
        $chain->method('getProviderCodes')->willReturn($codes);

        return array_map(
            static fn (array $option) => ['value' => $option['value'], 'label' => (string)$option['label']],
            (new Provider($chain))->toOptionArray()
        );
    }

    public function testListsNoneThenRegisteredCodes(): void
    {
        self::assertSame(
            [
                ['value' => '', 'label' => '— None —'],
                ['value' => 'okta', 'label' => 'okta'],
                ['value' => 'azure', 'label' => 'azure'],
            ],
            $this->options(['okta', 'azure'])
        );
    }

    public function testOnlyNoneWhenNothingRegistered(): void
    {
        self::assertSame([['value' => '', 'label' => '— None —']], $this->options([]));
    }
}
