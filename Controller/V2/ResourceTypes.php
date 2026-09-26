<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Controller\V2;

use DmLab\AdminScim\Controller\AbstractScim;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * `GET /admin-scim/v2/ResourceTypes` — lists the resource types the server
 * exposes (User, Group) so a client can discover their endpoints and schemas.
 */
class ResourceTypes extends AbstractScim
{
    /**
     * @param Http $request
     * @param BearerTokenAuthenticator $authenticator
     * @param ScimResponse $response
     * @param LoggerInterface $logger
     * @param DiscoveryProvider $discovery
     */
    public function __construct(
        Http $request,
        BearerTokenAuthenticator $authenticator,
        ScimResponse $response,
        LoggerInterface $logger,
        private readonly DiscoveryProvider $discovery
    ) {
        parent::__construct($request, $authenticator, $response, $logger);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): ResultInterface
    {
        return $this->response->json($this->discovery->resourceTypes());
    }
}
