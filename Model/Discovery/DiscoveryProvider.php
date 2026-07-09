<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Discovery;

/**
 * Produces the three SCIM discovery documents (RFC 7643 §5/§6, RFC 7644 §4):
 * `ServiceProviderConfig`, `ResourceTypes` and `Schemas`. Feature declarations
 * reflect what this server actually implements — PATCH and a filter subset are
 * supported; bulk, sort, ETag and password change are not. The `User`/`Group`
 * schema attributes list only the fields the server provisions.
 */
class DiscoveryProvider
{
    private const SCHEMA_SERVICE_PROVIDER_CONFIG = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';
    private const SCHEMA_RESOURCE_TYPE = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';
    private const SCHEMA_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Schema';
    private const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const SCHEMA_GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const SCHEMA_LIST_RESPONSE = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    /** Server-imposed cap on the number of results a filter query returns. */
    public const FILTER_MAX_RESULTS = 200;

    /**
     * @param EndpointUrlBuilder $urls
     */
    public function __construct(
        private readonly EndpointUrlBuilder $urls
    ) {
    }

    /**
     * The ServiceProviderConfig resource: honest capability advertisement.
     *
     * @return array<string,mixed>
     */
    public function serviceProviderConfig(): array
    {
        return [
            'schemas' => [self::SCHEMA_SERVICE_PROVIDER_CONFIG],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => self::FILTER_MAX_RESULTS],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [
                [
                    'type' => 'oauthbearertoken',
                    'name' => 'OAuth Bearer Token',
                    'description' => 'Authentication via the OAuth Bearer Token standard.',
                    'specUri' => 'http://www.rfc-editor.org/info/rfc6750',
                    'primary' => true,
                ],
            ],
            'meta' => [
                'resourceType' => 'ServiceProviderConfig',
                'location' => $this->urls->resourceUrl('ServiceProviderConfig'),
            ],
        ];
    }

    /**
     * The ResourceTypes collection (User + Group) as a SCIM ListResponse.
     *
     * @return array<string,mixed>
     */
    public function resourceTypes(): array
    {
        $resources = [
            $this->resourceType('User', '/Users', 'User Account', self::SCHEMA_USER),
            $this->resourceType('Group', '/Groups', 'Group', self::SCHEMA_GROUP),
        ];

        return $this->listResponse($resources);
    }

    /**
     * The Schemas collection (User + Group) as a SCIM ListResponse.
     *
     * @return array<string,mixed>
     */
    public function schemas(): array
    {
        $resources = [
            $this->userSchema(),
            $this->groupSchema(),
        ];

        return $this->listResponse($resources);
    }

    /**
     * Wrap resources in a SCIM ListResponse envelope.
     *
     * @param array<int,array<string,mixed>> $resources
     * @return array<string,mixed>
     */
    private function listResponse(array $resources): array
    {
        return [
            'schemas' => [self::SCHEMA_LIST_RESPONSE],
            'totalResults' => count($resources),
            'itemsPerPage' => count($resources),
            'startIndex' => 1,
            'Resources' => $resources,
        ];
    }

    /**
     * A single ResourceType resource.
     *
     * @param string $id
     * @param string $endpoint
     * @param string $description
     * @param string $schema
     * @return array<string,mixed>
     */
    private function resourceType(string $id, string $endpoint, string $description, string $schema): array
    {
        return [
            'schemas' => [self::SCHEMA_RESOURCE_TYPE],
            'id' => $id,
            'name' => $id,
            'endpoint' => $endpoint,
            'description' => $description,
            'schema' => $schema,
            'meta' => [
                'resourceType' => 'ResourceType',
                'location' => $this->urls->resourceUrl('ResourceTypes/' . $id),
            ],
        ];
    }

    /**
     * The User schema definition, limited to provisioned attributes.
     *
     * @return array<string,mixed>
     */
    private function userSchema(): array
    {
        return [
            'schemas' => [self::SCHEMA_SCHEMA],
            'id' => self::SCHEMA_USER,
            'name' => 'User',
            'description' => 'SCIM User mapped to a Magento admin user.',
            'attributes' => [
                $this->attribute('userName', 'string', [
                    'required' => true,
                    'uniqueness' => 'server',
                    'description' => 'Unique identifier for the user, mapped to the admin username.',
                ]),
                $this->complexAttribute('name', [
                    $this->attribute('formatted', 'string'),
                    $this->attribute('familyName', 'string'),
                    $this->attribute('givenName', 'string'),
                ], ['description' => "The user's real name."]),
                $this->complexAttribute('emails', [
                    $this->attribute('value', 'string'),
                    $this->attribute('primary', 'boolean'),
                ], ['multiValued' => true, 'description' => 'Email addresses for the user.']),
                $this->attribute('active', 'boolean', [
                    'description' => 'Whether the admin user is enabled; false deprovisions the account.',
                ]),
            ],
            'meta' => [
                'resourceType' => 'Schema',
                'location' => $this->urls->resourceUrl('Schemas/' . self::SCHEMA_USER),
            ],
        ];
    }

    /**
     * The Group schema definition, limited to provisioned attributes.
     *
     * @return array<string,mixed>
     */
    private function groupSchema(): array
    {
        return [
            'schemas' => [self::SCHEMA_SCHEMA],
            'id' => self::SCHEMA_GROUP,
            'name' => 'Group',
            'description' => 'SCIM Group mapped to Magento admin ACL roles.',
            'attributes' => [
                $this->attribute('displayName', 'string', [
                    'required' => true,
                    'description' => 'Human-readable name of the group.',
                ]),
                $this->complexAttribute('members', [
                    $this->attribute('value', 'string', ['mutability' => 'immutable']),
                    $this->attribute('display', 'string', ['mutability' => 'immutable']),
                ], ['multiValued' => true, 'description' => 'Members of the group.']),
            ],
            'meta' => [
                'resourceType' => 'Schema',
                'location' => $this->urls->resourceUrl('Schemas/' . self::SCHEMA_GROUP),
            ],
        ];
    }

    /**
     * A singular (non-complex) schema attribute definition with RFC defaults.
     *
     * @param string $name
     * @param string $type
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function attribute(string $name, string $type, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'type' => $type,
            'multiValued' => false,
            'required' => false,
            'caseExact' => false,
            'mutability' => 'readWrite',
            'returned' => 'default',
            'uniqueness' => 'none',
        ], $overrides);
    }

    /**
     * A complex schema attribute definition wrapping sub-attributes.
     *
     * @param string $name
     * @param array<int,array<string,mixed>> $subAttributes
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function complexAttribute(string $name, array $subAttributes, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'type' => 'complex',
            'multiValued' => false,
            'required' => false,
            'mutability' => 'readWrite',
            'returned' => 'default',
            'subAttributes' => $subAttributes,
        ], $overrides);
    }
}
