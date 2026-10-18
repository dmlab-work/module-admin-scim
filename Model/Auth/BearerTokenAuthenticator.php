<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Auth;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Audit\AuditLogger;
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
 * Each failure counts towards the per-IP {@see FailedAuthThrottle}; a blocked
 * client gets a 429 before any token comparison. Failures and throttled requests
 * are audit-logged without the token or headers.
 */
class BearerTokenAuthenticator
{
    /**
     * @param Config $config
     * @param FailedAuthThrottle $throttle
     * @param AuditLogger $auditLogger
     */
    public function __construct(
        private readonly Config $config,
        private readonly FailedAuthThrottle $throttle,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * Verify the request carries the configured bearer token.
     *
     * @param Http $request
     * @throws ScimException 429 when the client is throttled, 401 when authentication fails.
     */
    public function authenticate(Http $request): void
    {
        try {
            $this->throttle->assertNotBlocked();
        } catch (ScimException $e) {
            $this->auditLogger->log('auth.throttled');
            throw $e;
        }

        if (!$this->config->isEnabled()) {
            throw $this->fail('disabled');
        }

        $configured = $this->config->getBearerToken();
        if ($configured === null || $configured === '') {
            throw $this->fail('no_token_configured');
        }

        $provided = $this->extractBearerToken($request);
        if ($provided === null) {
            throw $this->fail('missing_bearer');
        }

        if (!hash_equals($configured, $provided)) {
            throw $this->fail('token_mismatch');
        }
    }

    /**
     * Record and audit the failure, then build the generic 401.
     *
     * @param string $reason audit-only; never sent to the client
     */
    private function fail(string $reason): ScimException
    {
        $this->throttle->registerFailure();
        $this->auditLogger->log('auth.failure', ['reason' => $reason]);

        return ScimException::unauthorized('Authentication failed.');
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
