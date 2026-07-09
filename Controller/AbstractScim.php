<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Controller;

use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPatchActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;
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
 * internals leaked). Implements all SCIM HTTP verb interfaces so a single
 * controller can serve GET/POST/PUT/PATCH/DELETE on a resource.
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
     * Handle the authenticated request and produce a SCIM result.
     *
     * @return ResultInterface
     * @throws ScimException
     */
    abstract protected function handle(): ResultInterface;
}
