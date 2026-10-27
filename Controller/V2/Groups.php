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
use DmLab\AdminScim\Model\Group\GroupProvisioner;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupRepository;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
use DmLab\AdminScim\Model\Request\ListQuery;
use DmLab\AdminScim\Model\Response\AttributeProjector;
use DmLab\AdminScim\Model\Response\ListResponseBuilder;
use DmLab\AdminScim\Model\Response\ScimResponse;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * `/admin-scim/v2/Groups` — the SCIM Group collection endpoint.
 *
 * Serves POST (create, `201` with `Location`), GET (read one by `id`, or list with
 * filter + pagination), `POST /Groups/.search`, PUT (full replace), PATCH
 * (add/replace/remove of attributes and members) and DELETE (204). A member change
 * re-derives the affected admins' ACL role (see {@see GroupProvisioner}). Returned
 * resources honour `attributes` / `excludedAttributes`; members are not loaded when
 * not returned. Unsupported verbs answer `501 Not Implemented`.
 */
class Groups extends AbstractScim
{
    /** Path segment of the search endpoint (RFC 7644 §3.4.3). */
    private const SEARCH = '.search';

    /**
     * @param Http $request
     * @param BearerTokenAuthenticator $authenticator
     * @param ScimResponse $response
     * @param LoggerInterface $logger
     * @param GroupProvisioner $provisioner
     * @param ScimGroupRepository $repository
     * @param ScimGroupMapper $mapper
     * @param MemberResource $memberResource
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
        private readonly GroupProvisioner $provisioner,
        private readonly ScimGroupRepository $repository,
        private readonly ScimGroupMapper $mapper,
        private readonly MemberResource $memberResource,
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
            return $this->listGroups($this->listQuery->fromSearchBody($this->decodeBody()));
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
     * Create a group from the request's SCIM Group body; `201` with its `Location`.
     */
    private function create(): ResultInterface
    {
        $group = $this->provisioner->create($this->parseBody());
        $location = $this->mapper->toResource($group, [])['meta']['location'] ?? null;

        return $this->response->json(
            $this->resource($group),
            201,
            is_string($location) ? ['Location' => $location] : []
        );
    }

    /**
     * `PUT /Groups/{id}` replaces the whole resource, returning the updated one.
     */
    private function replace(): ResultInterface
    {
        $group = $this->provisioner->replace($this->requireResourceId(), $this->parseBody());

        return $this->response->json($this->resource($group));
    }

    /**
     * `DELETE /Groups/{id}` deletes the group, answering `204 No Content`.
     */
    private function delete(): ResultInterface
    {
        $this->provisioner->delete($this->requireResourceId());

        return $this->response->noContent();
    }

    /**
     * `PATCH /Groups/{id}` applies a PatchOp, returning the updated resource.
     */
    private function patch(): ResultInterface
    {
        $group = $this->provisioner->patch($this->requireResourceId(), $this->parseBody());

        return $this->response->json($this->resource($group));
    }

    /**
     * `GET /Groups/{id}` returns one resource; `GET /Groups` returns a ListResponse.
     */
    private function read(): ResultInterface
    {
        $id = $this->resourceId();
        if ($id !== null) {
            return $this->response->json($this->resource($this->repository->getById($id)));
        }

        return $this->listGroups($this->listQuery->fromQuery($this->request));
    }

    /**
     * List groups per the given list/search parameters.
     *
     * @param array{filter:?string,startIndex:int,count:int,attributes:?string,excludedAttributes:?string} $query
     */
    private function listGroups(array $query): ResultInterface
    {
        $result = $this->repository->search($query['filter'], $query['startIndex'], $query['count']);
        $resources = array_map(
            fn (ScimGroup $group): array => $this->resource(
                $group,
                $query['attributes'],
                $query['excludedAttributes']
            ),
            $result['groups']
        );

        return $this->response->json(
            $this->listBuilder->build($resources, $result['total'], $query['startIndex'], count($resources))
        );
    }

    /**
     * A group as a SCIM resource, projected per `attributes` / `excludedAttributes`
     * (from the query string by default).
     *
     * Members cost a query per group, so they are loaded only when returned.
     *
     * @param ScimGroup $group
     * @param string|null $attributes
     * @param string|null $excluded
     * @return array<string,mixed>
     */
    private function resource(ScimGroup $group, ?string $attributes = null, ?string $excluded = null): array
    {
        $attributes ??= $this->stringParam('attributes');
        $excluded ??= $this->stringParam('excludedAttributes');
        $members = $this->projector->isReturned('members', ScimGroupMapper::SCHEMA_GROUP, $attributes, $excluded)
            ? $this->memberResource->getMembers((int)$group->groupId)
            : [];

        return $this->projector->project(
            $this->mapper->toResource($group, $members),
            ScimGroupMapper::SCHEMA_GROUP,
            $attributes,
            $excluded
        );
    }

    /**
     * The `{id}` path segment after `Groups`, or null for the collection endpoint.
     */
    private function resourceId(): ?string
    {
        return $this->pathSegmentAfter('Groups');
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
     * Decode the request body as a SCIM Group and run the active provider normalizer.
     *
     * @return array<string,mixed>
     * @throws ScimException 400 `invalidSyntax` when the body is not a JSON object
     */
    private function parseBody(): array
    {
        return $this->normalizerChain->apply(
            RequestNormalizerInterface::RESOURCE_GROUP,
            strtoupper($this->request->getMethod()),
            $this->decodeBody()
        );
    }
}
