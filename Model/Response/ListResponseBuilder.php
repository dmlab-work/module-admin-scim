<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Response;

/**
 * Assembles a SCIM `ListResponse` document (RFC 7644 §3.4.2) from already-rendered
 * resource arrays. Resource-agnostic so both Users and (later) Groups reuse it.
 */
class ListResponseBuilder
{
    /** URN of the SCIM list-response message schema. */
    public const SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    /**
     * Build a ListResponse envelope.
     *
     * @param array<int,array<string,mixed>> $resources rendered resource documents for this page
     * @param int $totalResults total matches across all pages
     * @param int $startIndex 1-based index of the first returned resource
     * @param int $itemsPerPage number of resources in this page
     * @return array<string,mixed>
     */
    public function build(array $resources, int $totalResults, int $startIndex, int $itemsPerPage): array
    {
        return [
            'schemas' => [self::SCHEMA],
            'totalResults' => $totalResults,
            'startIndex' => $startIndex,
            'itemsPerPage' => $itemsPerPage,
            'Resources' => array_values($resources),
        ];
    }
}
