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
use DmLab\AdminScim\Model\Discovery\DiscoveryProvider;
use DmLab\AdminScim\Model\Group\GroupProvisioner;
use DmLab\AdminScim\Model\Group\MemberResource;
use DmLab\AdminScim\Model\Group\ScimGroup;
use DmLab\AdminScim\Model\Group\ScimGroupMapper;
use DmLab\AdminScim\Model\Group\ScimGroupRepository;
use DmLab\AdminScim\Model\Normalization\RequestNormalizerChain;
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
 * Serves POST (create), GET (read one by `id`, or list with the supported filter
 * subset + pagination), PUT (full replace), PATCH (add/replace/remove of attributes
 * and members) and DELETE (204). A member change re-derives the affected admins'
 * ACL role (see {@see GroupProvisioner}). Returned resources honour `attributes` /
 * `excludedAttributes`; members are not loaded when not returned. Unsupported verbs
 * answer `501 Not Implemented`.
 */
class Groups extends AbstractScim
{
    /** Page size when the client sends no `count`. */
    private const DEFAULT_COUNT = 100;

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
        private readonly AttributeProjector $projector
    ) {
        parent::__construct($request, $authenticator, $response, $logger);
    }

    /**
     * @inheritDoc
     */
    protected function handle(): ResultInterface
    {
        return match (strtoupper($this->request->getMethod())) {
            'POST' => $this->create(),
            'GET' => $this->read(),
            'PUT' => $this->replace(),
            'PATCH' => $this->patch(),
            'DELETE' => $this->delete(),
            default => throw ScimException::notImplemented(),
        };
    }

    /**
     * Create a group from the request's SCIM Group body. Returns a `201`.
     */
    private function create(): ResultInterface
    {
        $group = $this->provisioner->create($this->parseBody());

        return $this->response->json($this->resource($group), 201);
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

        return $this->listGroups();
    }

    /**
     * List groups with the supported filter subset and pagination.
     */
    private function listGroups(): ResultInterface
    {
        $filter = trim((string)$this->request->getParam('filter'));
        $startIndex = $this->intParam('startIndex', 1, 1);
        $count = $this->intParam('count', self::DEFAULT_COUNT, 0, DiscoveryProvider::FILTER_MAX_RESULTS);

        $result = $this->repository->search($filter === '' ? null : $filter, $startIndex, $count);
        $resources = array_map(
            fn (ScimGroup $group): array => $this->resource($group),
            $result['groups']
        );

        return $this->response->json(
            $this->listBuilder->build($resources, $result['total'], $startIndex, count($resources))
        );
    }

    /**
     * A group as a SCIM resource, projected per the request's `attributes` / `excludedAttributes`.
     *
     * Members cost a query per group, so they are loaded only when returned.
     *
     * @param ScimGroup $group
     * @return array<string,mixed>
     */
    private function resource(ScimGroup $group): array
    {
        $attributes = $this->stringParam('attributes');
        $excluded = $this->stringParam('excludedAttributes');
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
        $path = trim((string)$this->request->getPathInfo(), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if (strcasecmp($segment, 'Groups') === 0) {
                $id = trim($segments[$i + 1] ?? '');
                return $id === '' ? null : $id;
            }
        }

        return null;
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
     * A non-negative integer query parameter, clamped to `[$min, $max]`.
     *
     * @param string $name
     * @param int $default value when absent or non-numeric
     * @param int $min lower clamp
     * @param int|null $max upper clamp, or null for none
     */
    private function intParam(string $name, int $default, int $min, ?int $max = null): int
    {
        $raw = $this->request->getParam($name);
        $value = is_numeric($raw) ? (int)$raw : $default;
        $value = max($min, $value);

        return $max !== null ? min($max, $value) : $value;
    }

    /**
     * Decode the request body as a SCIM JSON object.
     *
     * @return array<string,mixed>
     * @throws ScimException 400 `invalidSyntax` when the body is not a JSON object
     */
    private function parseBody(): array
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

        return $this->normalizerChain->apply(
            RequestNormalizerInterface::RESOURCE_GROUP,
            strtoupper($this->request->getMethod()),
            $decoded
        );
    }
}
