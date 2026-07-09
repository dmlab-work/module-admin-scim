<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Response;

use MageDevGroup\AdminScim\Exception\ScimException;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Builds SCIM HTTP responses: a `Raw` result with the `application/scim+json`
 * media type, the JSON-serialized body and the correct status code. Error
 * responses follow the RFC 7644 §3.12 error schema.
 */
class ScimResponse
{
    /** SCIM media type (RFC 7644 §3.1). */
    public const MEDIA_TYPE = 'application/scim+json';

    /** URN of the SCIM error message schema. */
    public const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    /**
     * @param RawFactory $rawFactory
     * @param Json $serializer
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly Json $serializer
    ) {
    }

    /**
     * A SCIM JSON success response.
     *
     * @param array<string,mixed> $payload
     * @param int $status
     */
    public function json(array $payload, int $status = 200): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', self::MEDIA_TYPE, true);
        $result->setContents($this->serializer->serialize($payload));

        return $result;
    }

    /**
     * A SCIM error response built from a {@see ScimException}.
     *
     * @param ScimException $exception
     */
    public function error(ScimException $exception): Raw
    {
        $body = ['schemas' => [self::ERROR_SCHEMA]];
        if ($exception->getScimType() !== null) {
            $body['scimType'] = $exception->getScimType();
        }
        $body['detail'] = $exception->getMessage();
        $body['status'] = (string)$exception->getHttpStatus();

        return $this->json($body, $exception->getHttpStatus());
    }
}
