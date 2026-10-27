<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Controller;

use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Action\HttpConnectActionInterface;
use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\Action\HttpPatchActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpPropfindActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;
use Magento\Framework\App\Action\HttpTraceActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Base action for every SCIM endpoint. Authenticates the bearer token, then
 * delegates to {@see handle()}, translating any {@see ScimException} into an
 * RFC-shaped error response and any unexpected throwable into a 500 (logged, no
 * internals leaked). Implements every HTTP verb interface Magento routes so a single
 * controller serves GET/POST/PUT/PATCH/DELETE on a resource and the remaining verbs
 * reach {@see handle()} for a SCIM `501` instead of Magento's HTML 404.
 *
 * These are `standard`-router (frontend) actions; the bearer token — not a
 * Magento form key — is the credential, so form-key CSRF validation is bypassed
 * via {@see CsrfAwareActionInterface}. Without it Magento rejects every POST
 * (create) with an "Invalid Form Key" redirect before {@see execute()} runs.
 */
abstract class AbstractScim implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpPutActionInterface,
    HttpPatchActionInterface,
    HttpDeleteActionInterface,
    HttpOptionsActionInterface,
    HttpConnectActionInterface,
    HttpPropfindActionInterface,
    HttpTraceActionInterface,
    CsrfAwareActionInterface
{
    /**
     * @param Http $request
     * @param BearerTokenAuthenticator $authenticator
     * @param ScimResponse $response
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly Http $request,
        private readonly BearerTokenAuthenticator $authenticator,
        protected readonly ScimResponse $response,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Authenticate, then run the concrete handler under SCIM error translation.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        try {
            $this->authenticator->authenticate($this->request);

            return $this->handle();
        } catch (ScimException $e) {
            return $this->response->error($e);
        } catch (\Throwable $e) {
            $this->logger->critical($e);

            return $this->response->error(ScimException::internal());
        }
    }

    /**
     * SCIM endpoints authenticate by bearer token, not by Magento form key.
     *
     * @param RequestInterface $request
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Bypass form-key CSRF validation; the bearer token is the credential.
     *
     * @param RequestInterface $request
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Reject every verb but GET on a read-only endpoint.
     *
     * @throws ScimException 501
     */
    protected function assertGet(): void
    {
        if (strtoupper((string)$this->request->getMethod()) !== 'GET') {
            throw ScimException::notImplemented();
        }
    }

    /**
     * The path segment following `$collection`, e.g. `{id}` in `/v2/Schemas/{id}`; null when absent.
     *
     * @param string $collection
     */
    protected function pathSegmentAfter(string $collection): ?string
    {
        $segments = explode('/', trim((string)$this->request->getPathInfo(), '/'));
        foreach ($segments as $i => $segment) {
            if (strcasecmp($segment, $collection) === 0) {
                // Path info is not URL-decoded; a Schemas URN id may arrive with `%3A` colons.
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $id = trim(rawurldecode($segments[$i + 1] ?? ''));

                return $id === '' ? null : $id;
            }
        }

        return null;
    }

    /**
     * A string query parameter, or null when absent or not a string.
     *
     * @param string $name
     */
    protected function stringParam(string $name): ?string
    {
        $value = $this->request->getParam($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Handle the authenticated request and produce a SCIM result.
     *
     * @return ResultInterface
     * @throws ScimException
     */
    abstract protected function handle(): ResultInterface;
}
