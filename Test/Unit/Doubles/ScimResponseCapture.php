<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Test\Unit\Doubles;

/**
 * Captures the HTTP status and JSON body a {@see \MageDevGroup\AdminScim\Model\Response\ScimResponse}
 * writes into its `Raw` result, so a lifecycle test can assert the SCIM document
 * a controller returned. Populated by the `Raw` double built in {@see InMemoryScimTrait}.
 */
class ScimResponseCapture
{
    /** @var int|null HTTP status code of the last response, or null before any */
    public ?int $status = null;

    /** @var string serialized JSON body of the last response */
    public string $contents = '';

    /**
     * The last response body decoded to an array.
     *
     * @return array<string,mixed>
     */
    public function document(): array
    {
        if ($this->contents === '') {
            return [];
        }

        $decoded = json_decode($this->contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
