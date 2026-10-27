<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Response;

/**
 * Renders DB timestamps (UTC `Y-m-d H:i:s`, the connection's time zone) as SCIM `meta` dates.
 */
trait MetaTimestamps
{
    /**
     * `meta.created` / `meta.lastModified` entries for the known timestamps (RFC 7643 §3.1).
     *
     * @param mixed $created
     * @param mixed $modified falls back to `$created`
     * @return array<string,string>
     */
    private function metaTimestamps(mixed $created, mixed $modified): array
    {
        $created = $this->xsdDateTime($created);
        $modified = $this->xsdDateTime($modified) ?? $created;

        return array_filter(['created' => $created, 'lastModified' => $modified], 'is_string');
    }

    /**
     * An xsd:dateTime in UTC, or null when the value is not a timestamp.
     *
     * @param mixed $value
     */
    private function xsdDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
