<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Controller\V2;

use MageDevGroup\AdminScim\Api\RequestNormalizerInterface;
use MageDevGroup\AdminScim\Controller\AbstractScim;
use MageDevGroup\AdminScim\Exception\ScimException;
use MageDevGroup\AdminScim\Model\Auth\BearerTokenAuthenticator;
use MageDevGroup\AdminScim\Model\Discovery\DiscoveryProvider;
use MageDevGroup\AdminScim\Model\Normalization\RequestNormalizerChain;
use MageDevGroup\AdminScim\Model\Response\ListResponseBuilder;
use MageDevGroup\AdminScim\Model\Response\ScimResponse;
use MageDevGroup\AdminScim\Model\User\ScimUserMapper;
use MageDevGroup\AdminScim\Model\User\ScimUserRepository;
use MageDevGroup\AdminScim\Model\User\UserProvisioner;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * `/admin-scim/v2/Users` — the SCIM User collection endpoint.
 *
 * Serves POST (create), GET (read one by `id`, or list with the supported
 * filter subset + pagination), PUT (full replace) and PATCH (partial ops incl.
 * `active` deprovisioning). Unsupported verbs answer `501 Not Implemented`; a
 * single action class serves every verb because Magento routes the whole
 * `Users` path here.
 */
class Users extends AbstractScim
{
    /** Page size when the client sends no `count`. */
    private const DEFAULT_COUNT = 100;

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
        private readonly RequestNormalizerChain $normalizerChain
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
            default => throw ScimException::notImplemented(),
        };
    }

    /**
     * Create an admin user from the request's SCIM User body.
     *
     * Returns the created resource with a `201`.
     */
    private function create(): ResultInterface
    {
        $user = $this->provisioner->create($this->parseBody());

        return $this->response->json($this->mapper->toResource($user), 201);
    }

    /**
     * `PUT /Users/{id}` replaces the whole resource, returning the updated one.
     */
    private function replace(): ResultInterface
    {
        $user = $this->provisioner->replace($this->requireResourceId(), $this->parseBody());

        return $this->response->json($this->mapper->toResource($user));
    }

    /**
     * `PATCH /Users/{id}` applies a PatchOp, returning the updated resource.
     */
    private function patch(): ResultInterface
    {
        $user = $this->provisioner->patch($this->requireResourceId(), $this->parseBody());

        return $this->response->json($this->mapper->toResource($user));
    }

    /**
     * `GET /Users/{id}` returns one resource; `GET /Users` returns a ListResponse.
     */
    private function read(): ResultInterface
    {
        $id = $this->resourceId();
        if ($id !== null) {
            return $this->response->json($this->mapper->toResource($this->repository->getById($id)));
        }

        return $this->listUsers();
    }

    /**
     * List admin users with the supported filter subset and pagination.
     */
    private function listUsers(): ResultInterface
    {
        $filter = trim((string)$this->request->getParam('filter'));
        $startIndex = $this->intParam('startIndex', 1, 1);
        $count = $this->intParam('count', self::DEFAULT_COUNT, 0, DiscoveryProvider::FILTER_MAX_RESULTS);

        $result = $this->repository->search($filter === '' ? null : $filter, $startIndex, $count);
        $resources = array_map(
            fn ($user): array => $this->mapper->toResource($user),
            $result['users']
        );

        return $this->response->json(
            $this->listBuilder->build($resources, $result['total'], $startIndex, count($resources))
        );
    }

    /**
     * The `{id}` path segment after `Users`, or null for the collection endpoint.
     */
    private function resourceId(): ?string
    {
        $path = trim((string)$this->request->getPathInfo(), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if (strcasecmp($segment, 'Users') === 0) {
                $id = trim($segments[$i + 1] ?? '');
                return $id === '' ? null : $id;
            }
        }

        return null;
    }

    /**
     * The `{id}` path segment, required for single-resource verbs (PUT/PATCH).
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
            RequestNormalizerInterface::RESOURCE_USER,
            strtoupper($this->request->getMethod()),
            $decoded
        );
    }
}
