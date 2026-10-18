<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Normalization;

use DmLab\AdminScim\Api\RequestNormalizerInterface;
use DmLab\AdminScim\Model\Config;

/**
 * Open/closed seam for provider quirks: the di-merged set of request
 * normalizers the controllers run before handling a SCIM write.
 *
 * The core registers none, so {@see apply()} returns the body untouched (strict
 * RFC). Provider plugins di-merge their {@see RequestNormalizerInterface} into
 * the `normalizers` argument under their provider code (the di item key). Only
 * the active provider's normalizer runs — see {@see getActiveNormalizer()}.
 */
class RequestNormalizerChain
{
    /** @var array<string,RequestNormalizerInterface> provider code → normalizer */
    private array $normalizers;

    /**
     * @param Config $config
     * @param array<string,RequestNormalizerInterface> $normalizers di-merged provider normalizers, keyed by code
     */
    public function __construct(
        private readonly Config $config,
        array $normalizers = []
    ) {
        foreach ($normalizers as $normalizer) {
            if (!$normalizer instanceof RequestNormalizerInterface) {
                throw new \InvalidArgumentException(
                    'Registered normalizer must implement ' . RequestNormalizerInterface::class . '.'
                );
            }
        }
        $this->normalizers = $normalizers;
    }

    /**
     * Codes of the registered provider normalizers, in di order.
     *
     * @return string[]
     */
    public function getProviderCodes(): array
    {
        return array_map('strval', array_keys($this->normalizers));
    }

    /**
     * Run the decoded body through the active provider's normalizer.
     *
     * Returned unchanged when no provider is active.
     *
     * @param string $resourceType one of the `RequestNormalizerInterface::RESOURCE_*` constants
     * @param string $operation the upper-case HTTP method (`POST`, `PUT`, `PATCH`)
     * @param array<string,mixed> $payload the decoded request body
     * @return array<string,mixed> the normalized request body
     */
    public function apply(string $resourceType, string $operation, array $payload): array
    {
        $normalizer = $this->getActiveNormalizer();

        return $normalizer === null ? $payload : $normalizer->normalize($resourceType, $operation, $payload);
    }

    /**
     * The configured provider's normalizer; when none is configured, the only registered one.
     *
     * Null for an unknown configured code, or when several are registered and none is picked.
     */
    private function getActiveNormalizer(): ?RequestNormalizerInterface
    {
        $code = $this->config->getProvider();
        if ($code !== null) {
            return $this->normalizers[$code] ?? null;
        }

        return count($this->normalizers) === 1 ? reset($this->normalizers) : null;
    }
}
