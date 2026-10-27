<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Request;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use Magento\Framework\App\RequestInterface;

/**
 * Reads list/search parameters — `filter`, `startIndex`, `count`, `attributes`,
 * `excludedAttributes` — from a query string (RFC 7644 §3.4.2) or a `POST /.search`
 * SearchRequest body (§3.4.3), clamping paging to what the server supports.
 */
class ListQuery
{
    /** Page size when the client sends no `count`. */
    public const DEFAULT_COUNT = 100;

    /** SearchRequest message schema. */
    public const SEARCH_REQUEST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:SearchRequest';

    /**
     * Parameters from the request's query string.
     *
     * @param RequestInterface $request
     * @return array{filter:?string,startIndex:int,count:int,attributes:?string,excludedAttributes:?string}
     */
    public function fromQuery(RequestInterface $request): array
    {
        return $this->normalize([
            'filter' => $request->getParam('filter'),
            'startIndex' => $request->getParam('startIndex'),
            'count' => $request->getParam('count'),
            'attributes' => $request->getParam('attributes'),
            'excludedAttributes' => $request->getParam('excludedAttributes'),
        ]);
    }

    /**
     * Parameters from a decoded SearchRequest body; `attributes` lists may be arrays.
     *
     * @param array<string,mixed> $body
     * @return array{filter:?string,startIndex:int,count:int,attributes:?string,excludedAttributes:?string}
     * @throws ScimException 400 `invalidSyntax` when the body is not a SearchRequest
     */
    public function fromSearchBody(array $body): array
    {
        $schemas = $body['schemas'] ?? [self::SEARCH_REQUEST_SCHEMA];
        if (!is_array($schemas) || !in_array(self::SEARCH_REQUEST_SCHEMA, $schemas, true)) {
            throw ScimException::badRequest(
                'A search request must use the ' . self::SEARCH_REQUEST_SCHEMA . ' schema.',
                'invalidSyntax'
            );
        }
        foreach (['attributes', 'excludedAttributes'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $body[$key] = implode(',', array_filter($body[$key], 'is_string'));
            }
        }

        return $this->normalize($body);
    }

    /**
     * Typed, clamped parameters.
     *
     * @param array<string,mixed> $raw
     * @return array{filter:?string,startIndex:int,count:int,attributes:?string,excludedAttributes:?string}
     */
    private function normalize(array $raw): array
    {
        $filter = is_string($raw['filter'] ?? null) ? trim($raw['filter']) : '';

        return [
            'filter' => $filter === '' ? null : $filter,
            'startIndex' => $this->int($raw['startIndex'] ?? null, 1, 1),
            'count' => $this->int($raw['count'] ?? null, self::DEFAULT_COUNT, 0, DiscoveryProvider::FILTER_MAX_RESULTS),
            'attributes' => is_string($raw['attributes'] ?? null) ? $raw['attributes'] : null,
            'excludedAttributes' => is_string($raw['excludedAttributes'] ?? null) ? $raw['excludedAttributes'] : null,
        ];
    }

    /**
     * A numeric value as an int clamped to `[$min, $max]`, or the default when not numeric.
     *
     * @param mixed $raw
     * @param int $default
     * @param int $min
     * @param int|null $max
     */
    private function int(mixed $raw, int $default, int $min, ?int $max = null): int
    {
        $value = max($min, is_numeric($raw) ? (int)$raw : $default);

        return $max !== null ? min($max, $value) : $value;
    }
}
