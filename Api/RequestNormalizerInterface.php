<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Api;

/**
 * Provider-quirk extension point: rewrite a non-compliant IdP request body into
 * strict RFC 7644 shape before the core handles it.
 *
 * The core is strict-RFC and ships no normalizer, so by default request bodies
 * pass through untouched. A provider plugin (`admin-scim-azure`, `admin-scim-okta`)
 * di-merges its normalizer into the {@see \DmLab\AdminScim\Model\Normalization\RequestNormalizerChain}
 * to absorb that IdP's deviations (Entra flat complex attrs, `value` in group-member
 * remove, ADD/REPLACE inconsistency) — the core never learns IdP specifics.
 *
 * The di item key is the provider code (`okta`, `azure`, `google`); the core invokes
 * a normalizer only while its provider is active (configured, or the only one registered).
 */
interface RequestNormalizerInterface
{
    /** SCIM resource type of the request being normalized. */
    public const RESOURCE_USER = 'User';
    public const RESOURCE_GROUP = 'Group';

    /**
     * Normalize a decoded SCIM request body toward strict RFC shape.
     *
     * Implementations must be pure and total: return the payload unchanged when
     * nothing needs rewriting, never throw on unexpected input.
     *
     * @param string $resourceType one of the `RESOURCE_*` constants
     * @param string $operation the upper-case HTTP method (`POST`, `PUT`, `PATCH`)
     * @param array<string,mixed> $payload the decoded request body
     * @return array<string,mixed> the normalized request body
     */
    public function normalize(string $resourceType, string $operation, array $payload): array;
}
