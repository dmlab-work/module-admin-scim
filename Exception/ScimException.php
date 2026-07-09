<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Exception;

/**
 * A SCIM protocol error carrying the HTTP status and optional `scimType`
 * (RFC 7644 §3.12) that the endpoint must return. The exception message is the
 * human-readable `detail`. Named constructors keep call sites intention-revealing
 * and the status/scimType pairing consistent.
 */
class ScimException extends \RuntimeException
{
    /**
     * @param int $httpStatus HTTP status code to return.
     * @param string $detail Human-readable error detail.
     * @param string|null $scimType RFC 7644 detail error keyword (e.g. `invalidValue`, `uniqueness`).
     * @param \Throwable|null $previous
     */
    public function __construct(
        private readonly int $httpStatus,
        string $detail,
        private readonly ?string $scimType = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($detail, 0, $previous);
    }

    /**
     * HTTP status code to return for this error.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * RFC 7644 `scimType` keyword, or null when not applicable.
     */
    public function getScimType(): ?string
    {
        return $this->scimType;
    }

    // Named constructors keep call sites readable; static is intentional (these
    // never need interception), so the StaticFunction advisory does not apply.
    // phpcs:disable Magento2.Functions.StaticFunction.StaticFunction

    /**
     * 400 Bad Request — a malformed or invalid request.
     *
     * @param string $detail
     * @param string|null $scimType
     */
    public static function badRequest(string $detail, ?string $scimType = null): self
    {
        return new self(400, $detail, $scimType);
    }

    /**
     * 401 Unauthorized — missing or invalid bearer credential.
     *
     * @param string $detail
     */
    public static function unauthorized(string $detail = 'Authentication failed.'): self
    {
        return new self(401, $detail);
    }

    /**
     * 404 Not Found — the target resource does not exist.
     *
     * @param string $detail
     */
    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, $detail);
    }

    /**
     * 409 Conflict — a uniqueness constraint would be violated.
     *
     * @param string $detail
     * @param string|null $scimType
     */
    public static function conflict(string $detail, ?string $scimType = 'uniqueness'): self
    {
        return new self(409, $detail, $scimType);
    }

    /**
     * 500 Internal Server Error — an unexpected server-side failure.
     *
     * @param string $detail
     */
    public static function internal(string $detail = 'Internal server error.'): self
    {
        return new self(500, $detail);
    }

    /**
     * 501 Not Implemented — an operation the server does not support.
     *
     * @param string $detail
     */
    public static function notImplemented(string $detail = 'Operation not supported.'): self
    {
        return new self(501, $detail);
    }

    // phpcs:enable Magento2.Functions.StaticFunction.StaticFunction
}
