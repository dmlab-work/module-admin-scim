<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Controller\V2;

use DmLab\AdminScim\Controller\AbstractScim;
use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * `GET /admin-scim/v2/Schemas[/{id}]` — lists the SCIM schema definitions (User, Group)
 * with the attributes this server actually provisions, or returns one by its URN id
 * (RFC 7644 §4).
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
        $this->assertGet();
        $id = $this->pathSegmentAfter('Schemas');
        if ($id === null) {
            return $this->response->json($this->discovery->schemas());
        }

        $resource = $this->discovery->schema($id);
        if ($resource === null) {
            throw ScimException::notFound(sprintf('Schema "%s" not found.', $id));
        }

        return $this->response->json($resource);
    }
}
