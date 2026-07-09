<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Controller\V2;

use MageDevGroup\AdminScim\Controller\AbstractScim;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Discovery\DiscoveryProvider;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * `GET /admin-scim/v2/Schemas` — lists the SCIM schema definitions (User, Group)
 * with the attributes this server actually provisions.
 */
class Schemas extends AbstractScim
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
        return $this->response->json($this->discovery->schemas());
    }
}
