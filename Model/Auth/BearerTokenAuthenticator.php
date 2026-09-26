<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Config;
use Magento\Framework\App\Request\Http;

/**
 * Authenticates a SCIM request against the configured bearer token.
 *
 * The IdP presents the credential as `Authorization: Bearer <token>`; it is
 * compared to the encrypted-at-rest configured token in constant time. Any
 * failure — endpoint disabled, no token configured, missing/malformed header, or
 * mismatch — throws an unauthorized {@see ScimException} with a single generic
 * message, so the endpoint never reveals whether SCIM is enabled or a token set.
 */
class BearerTokenAuthenticator
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Verify the request carries the configured bearer token.
     *
     * @param Http $request
     * @throws ScimException 401 when authentication fails.
     */
    public function authenticate(Http $request): void
    {
        if (!$this->config->isEnabled()) {
            throw ScimException::unauthorized('Authentication failed.');
        }

        $configured = $this->config->getBearerToken();
        if ($configured === null || $configured === '') {
            throw ScimException::unauthorized('Authentication failed.');
        }

        $provided = $this->extractBearerToken($request);
        if ($provided === null) {
            throw ScimException::unauthorized('Authentication failed.');
        }

        if (!hash_equals($configured, $provided)) {
            throw ScimException::unauthorized('Authentication failed.');
        }
    }

    /**
     * Extract the token from an `Authorization: Bearer <token>` header.
     *
     * Null when the header is absent or not a non-empty bearer credential.
     *
     * @param Http $request
     */
    private function extractBearerToken(Http $request): ?string
    {
        $header = $request->getHeader('Authorization');
        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S.*)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }
}
