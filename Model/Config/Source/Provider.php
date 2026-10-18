<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Config\Source;

use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for the Provider field: the registered normalizer codes plus "None".
 */
class Provider implements OptionSourceInterface
{
    /**
     * @param RequestNormalizerChain $normalizerChain
     */
    public function __construct(
        private readonly RequestNormalizerChain $normalizerChain
    ) {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('— None —')]];
        foreach ($this->normalizerChain->getProviderCodes() as $code) {
            $options[] = ['value' => $code, 'label' => $code];
        }

        return $options;
    }
}
