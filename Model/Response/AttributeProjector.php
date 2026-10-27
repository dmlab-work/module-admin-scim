<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Response;

/**
 * Applies the SCIM `attributes` / `excludedAttributes` parameters (RFC 7644 §3.4.2.5, §3.9)
 * to a rendered resource.
 *
 * Both take a comma-separated list of attribute paths (`userName`, `name.givenName`, or
 * URN-qualified `urn:…:User:emails`), matched case-insensitively. `id` and `schemas` are
 * `returned: always` and survive both; any other attribute — `meta` included — is dropped
 * by `attributes` unless listed. Unknown names are ignored.
 */
class AttributeProjector
{
    /** Attributes returned regardless of the parameters. */
    private const ALWAYS = ['id', 'schemas'];

    /**
     * The resource reduced per the parameters.
     *
     * @param array<string,mixed> $resource rendered resource
     * @param string $schema the resource's core schema URN (allowed as a path prefix)
     * @param string|null $attributes raw `attributes` value
     * @param string|null $excludedAttributes raw `excludedAttributes` value
     * @return array<string,mixed>
     */
    public function project(array $resource, string $schema, ?string $attributes, ?string $excludedAttributes): array
    {
        $include = $this->parse($attributes, $schema);
        if ($include !== []) {
            $resource = $this->keep($resource, $include);
        }
        foreach ($this->parse($excludedAttributes, $schema) as [$top, $sub]) {
            $resource = $this->drop($resource, $top, $sub);
        }

        return $resource;
    }

    /**
     * Whether a top-level attribute survives the parameters, so callers can skip loading it.
     *
     * @param string $attribute top-level attribute name
     * @param string $schema
     * @param string|null $attributes
     * @param string|null $excludedAttributes
     */
    public function isReturned(
        string $attribute,
        string $schema,
        ?string $attributes,
        ?string $excludedAttributes
    ): bool {
        $attribute = strtolower($attribute);
        $include = $this->parse($attributes, $schema);
        if ($include !== [] && !in_array($attribute, array_column($include, 0), true)) {
            return false;
        }
        foreach ($this->parse($excludedAttributes, $schema) as [$top, $sub]) {
            if ($top === $attribute && $sub === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower-cased `[attribute, sub-attribute|null]` pairs of a parameter value.
     *
     * @param string|null $value
     * @param string $schema
     * @return array<int,array{0:string,1:string|null}>
     */
    private function parse(?string $value, string $schema): array
    {
        $paths = [];
        $prefix = $schema . ':';
        foreach (explode(',', (string)$value) as $path) {
            $path = trim($path);
            if (strncasecmp($path, $prefix, strlen($prefix)) === 0) {
                $path = substr($path, strlen($prefix));
            }
            if ($path === '') {
                continue;
            }
            $parts = explode('.', strtolower($path), 2);
            $paths[] = [$parts[0], $parts[1] ?? null];
        }

        return $paths;
    }

    /**
     * Only the listed attributes (whole, or the listed sub-attributes) plus the always-returned ones.
     *
     * @param array<string,mixed> $resource
     * @param array<int,array{0:string,1:string|null}> $paths
     * @return array<string,mixed>
     */
    private function keep(array $resource, array $paths): array
    {
        $wanted = [];
        foreach ($paths as [$top, $sub]) {
            if ($sub === null || ($wanted[$top] ?? null) === true) {
                $wanted[$top] = true;
                continue;
            }
            $wanted[$top][$sub] = true;
        }

        $kept = [];
        foreach ($resource as $key => $value) {
            $name = strtolower((string)$key);
            if (in_array($name, self::ALWAYS, true)) {
                $kept[$key] = $value;
            } elseif (isset($wanted[$name])) {
                $kept[$key] = $wanted[$name] === true || !is_array($value)
                    ? $value
                    : $this->subAttributes($value, $wanted[$name], true);
            }
        }

        return $kept;
    }

    /**
     * The resource without one attribute or sub-attribute; always-returned ones stay.
     *
     * @param array<string,mixed> $resource
     * @param string $top
     * @param string|null $sub
     * @return array<string,mixed>
     */
    private function drop(array $resource, string $top, ?string $sub): array
    {
        if (in_array($top, self::ALWAYS, true)) {
            return $resource;
        }
        foreach ($resource as $key => $value) {
            if (strtolower((string)$key) !== $top) {
                continue;
            }
            if ($sub === null) {
                unset($resource[$key]);
            } elseif (is_array($value)) {
                $resource[$key] = $this->subAttributes($value, [$sub => true], false);
            }
        }

        return $resource;
    }

    /**
     * Keep (or drop) the named sub-attributes of a complex value, or of each element of a multi-valued one.
     *
     * @param array<mixed> $value
     * @param array<string,bool> $subs lower-cased sub-attribute names
     * @param bool $keep true keeps only `$subs`, false removes them
     * @return array<mixed>
     */
    private function subAttributes(array $value, array $subs, bool $keep): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn ($item) => is_array($item) ? $this->subAttributes($item, $subs, $keep) : $item,
                $value
            );
        }

        return array_filter(
            $value,
            static fn ($key): bool => isset($subs[strtolower((string)$key)]) === $keep,
            ARRAY_FILTER_USE_KEY
        );
    }
}
