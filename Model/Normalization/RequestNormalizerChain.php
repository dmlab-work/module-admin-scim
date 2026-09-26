<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Normalization;

use DmLab\AdminScim\Api\RequestNormalizerInterface;

/**
 * Open/closed seam for provider quirks: the di-merged pipeline of request
 * normalizers the controllers run before handling a SCIM write.
 *
 * The core registers none, so {@see apply()} returns the body untouched (strict
 * RFC). Provider plugins di-merge their {@see RequestNormalizerInterface} into
 * the `normalizers` argument; the chain feeds each one's output into the next, so
 * only the IdP whose payload matches acts and the rest pass through.
 */
class RequestNormalizerChain
{
    /** @var RequestNormalizerInterface[] */
    private array $normalizers;

    /**
     * @param RequestNormalizerInterface[] $normalizers di-merged provider normalizers
     */
    public function __construct(array $normalizers = [])
    {
        foreach ($normalizers as $normalizer) {
            if (!$normalizer instanceof RequestNormalizerInterface) {
                throw new \InvalidArgumentException(
                    'Registered normalizer must implement ' . RequestNormalizerInterface::class . '.'
                );
            }
        }
        $this->normalizers = array_values($normalizers);
    }

    /**
     * Run the decoded body through every registered normalizer in order.
     *
     * With no normalizers registered the body is returned unchanged.
     *
     * @param string $resourceType one of the `RequestNormalizerInterface::RESOURCE_*` constants
     * @param string $operation the upper-case HTTP method (`POST`, `PUT`, `PATCH`)
     * @param array<string,mixed> $payload the decoded request body
     * @return array<string,mixed> the normalized request body
     */
    public function apply(string $resourceType, string $operation, array $payload): array
    {
        foreach ($this->normalizers as $normalizer) {
            $payload = $normalizer->normalize($resourceType, $operation, $payload);
        }

        return $payload;
    }
}
