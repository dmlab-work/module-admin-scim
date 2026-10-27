<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Controller\V2;

use DmLab\AdminScim\Api\RequestNormalizerInterface;
use DmLab\AdminScim\Controller\AbstractScim;
use DmLab\AdminScim\Exception\ScimException;
use DmLab\AdminScim\Model\Auth\BearerTokenAuthenticator;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use DmLab\AdminScim\Model\Request\ListQuery;
use DmLab\AdminScim\Model\Response\AttributeProjector;
use DmLab\AdminScim\Model\Response\ListResponseBuilder;
use DmLab\AdminScim\Model\Response\ScimResponse;
use DmLab\AdminScim\Model\User\ScimUserMapper;
use DmLab\AdminScim\Model\User\ScimUserRepository;
use DmLab\AdminScim\Model\User\UserProvisioner;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\User;
use Psr\Log\LoggerInterface;

/**
 * `/admin-scim/v2/Users` — the SCIM User collection endpoint.
 *
 * Serves POST (create, `201` with `Location`), GET (read one by `id`, or list with
 * filter + pagination), `POST /Users/.search`, PUT (full replace), PATCH (partial ops
 * incl. `active` deprovisioning) and DELETE (hard delete, 204). Returned resources
 * honour `attributes` / `excludedAttributes`. Unsupported verbs answer `501 Not
 * Implemented`; a single action class serves every verb because Magento routes the
 * whole `Users` path here.
 */
class Users extends AbstractScim
{
    /** Path segment of the search endpoint (RFC 7644 §3.4.3). */
    private const SEARCH = '.search';

    /**
     * @param Http $request
     * @param BearerTokenAuthenticator $authenticator
     * @param ScimResponse $response
     * @param LoggerInterface $logger
     * @param UserProvisioner $provisioner
     * @param ScimUserRepository $repository
     * @param ScimUserMapper $mapper
     * @param ListResponseBuilder $listBuilder
     * @param Json $serializer
     * @param RequestNormalizerChain $normalizerChain
     * @param AttributeProjector $projector
     * @param ListQuery $listQuery
     */
    public function __construct(
        Http $request,
        BearerTokenAuthenticator $authenticator,
        ScimResponse $response,
        LoggerInterface $logger,
        private readonly UserProvisioner $provisioner,
        private readonly ScimUserRepository $repository,
        private readonly ScimUserMapper $mapper,
        private readonly ListResponseBuilder $listBuilder,
        private readonly Json $serializer,
        private readonly RequestNormalizerChain $normalizerChain,
        private readonly AttributeProjector $projector,
        private readonly ListQuery $listQuery
    ) {
        parent::__construct($request, $authenticator, $response, $logger);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): ResultInterface
    {
        $method = strtoupper($this->request->getMethod());
        if ($method === 'POST' && $this->resourceId() === self::SEARCH) {
            return $this->listUsers($this->listQuery->fromSearchBody($this->decodeBody()));
        }

        return match ($method) {
            'POST' => $this->create(),
            'GET' => $this->read(),
            'PUT' => $this->replace(),
            'PATCH' => $this->patch(),
            'DELETE' => $this->delete(),
            default => throw ScimException::notImplemented(),
        };
    }

    /**
     * Create an admin user from the request's SCIM User body; `201` with its `Location`.
     */
    private function create(): ResultInterface
    {
        $resource = $this->mapper->toResource($this->provisioner->create($this->parseBody()));
        $location = $resource['meta']['location'] ?? null;

        return $this->response->json(
            $this->project($resource),
            201,
            is_string($location) ? ['Location' => $location] : []
        );
    }

    /**
     * `PUT /Users/{id}` replaces the whole resource, returning the updated one.
     */
    private function replace(): ResultInterface
    {
        $user = $this->provisioner->replace($this->requireResourceId(), $this->parseBody());

        return $this->response->json($this->project($this->mapper->toResource($user)));
    }

    /**
     * `PATCH /Users/{id}` applies a PatchOp, returning the updated resource.
     */
    private function patch(): ResultInterface
    {
        $user = $this->provisioner->patch($this->requireResourceId(), $this->parseBody());

        return $this->response->json($this->project($this->mapper->toResource($user)));
    }

    /**
     * `DELETE /Users/{id}` hard-deletes the admin, answering `204 No Content`.
     */
    private function delete(): ResultInterface
    {
        $this->provisioner->delete($this->requireResourceId());

        return $this->response->noContent();
    }

    /**
     * `GET /Users/{id}` returns one resource; `GET /Users` returns a ListResponse.
     */
    private function read(): ResultInterface
    {
        $id = $this->resourceId();
        if ($id !== null) {
            return $this->response->json($this->project($this->mapper->toResource($this->repository->getById($id))));
        }

        return $this->listUsers($this->listQuery->fromQuery($this->request));
    }

    /**
     * List admin users per the given list/search parameters.
     *
     * @param array{filter:?string,startIndex:int,count:int,attributes:?string,excludedAttributes:?string} $query
     */
    private function listUsers(array $query): ResultInterface
    {
        $result = $this->repository->search($query['filter'], $query['startIndex'], $query['count']);
        $resources = array_map(
            fn (User $user): array => $this->project(
                $this->mapper->toResource($user),
                $query['attributes'],
                $query['excludedAttributes']
            ),
            $result['users']
        );

        return $this->response->json(
            $this->listBuilder->build($resources, $result['total'], $query['startIndex'], count($resources))
        );
    }

    /**
     * A rendered user projected per `attributes` / `excludedAttributes` (from the query string by default).
     *
     * @param array<string,mixed> $resource
     * @param string|null $attributes
     * @param string|null $excluded
     * @return array<string,mixed>
     */
    private function project(array $resource, ?string $attributes = null, ?string $excluded = null): array
    {
        return $this->projector->project(
            $resource,
            ScimUserMapper::SCHEMA_USER,
            $attributes ?? $this->stringParam('attributes'),
            $excluded ?? $this->stringParam('excludedAttributes')
        );
    }

    /**
     * The `{id}` path segment after `Users`, or null for the collection endpoint.
     */
    private function resourceId(): ?string
    {
        return $this->pathSegmentAfter('Users');
    }

    /**
     * The `{id}` path segment, required for single-resource verbs (PUT/PATCH/DELETE).
     *
     * @throws ScimException 400 `noTarget` when the request targets the collection
     */
    private function requireResourceId(): string
    {
        $id = $this->resourceId();
        if ($id === null) {
            throw ScimException::badRequest('A resource id is required in the request path.', 'noTarget');
        }

        return $id;
    }

    /**
     * Decode the request body as a JSON object.
     *
     * @return array<string,mixed>
     * @throws ScimException 400 `invalidSyntax` when the body is not a JSON object
     */
    private function decodeBody(): array
    {
        $raw = (string)$this->request->getContent();
        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            throw ScimException::badRequest('Request body is not valid JSON.', 'invalidSyntax');
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw ScimException::badRequest('Request body must be a JSON object.', 'invalidSyntax');
        }

        return $decoded;
    }

    /**
     * Decode the request body as a SCIM User and run the active provider normalizer.
     *
     * @return array<string,mixed>
     * @throws ScimException 400 `invalidSyntax` when the body is not a JSON object
     */
    private function parseBody(): array
    {
        return $this->normalizerChain->apply(
            RequestNormalizerInterface::RESOURCE_USER,
            strtoupper($this->request->getMethod()),
            $this->decodeBody()
        );
    }
}
