<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminScim\Model\Group;

use MageDevGroup\AdminScim\Exception\ScimException;

/**
 * Applies a SCIM PATCH `PatchOp` (RFC 7644 §3.5.2) to a loaded group and its
 * member set.
 *
 * Handles the strict-RFC subset the server provisions: `displayName`, `externalId`
 * and `members`. For `members`, `add` merges, `replace` overwrites the whole
 * collection, `remove` with a value-filtered path (`members[value eq "id"]`)
 * drops one member and a path-less `remove` clears all. `add`/`replace` treat a
 * single-valued attribute identically (RFC §3.5.2.1). Path-less operations (whose
 * `value` is an attribute→value object) are supported. IdP-specific deviations
 * beyond this baseline (e.g. Entra's member-remove quirk) are normalized in
 * `admin-scim-<idp>` plugins (Task 8), not here. Mutates the group model and the
 * `$memberIds` array in place; persistence and role sync live in
 * {@see GroupProvisioner}.
 */
class ScimGroupPatcher
{
    /** `members[value eq "<id>"]`, operator case-insensitive. */
    private const MEMBER_PATH_PATTERN = '/^members\[\s*value\s+eq\s+"([^"]+)"\s*\]$/i';

    /**
     * @param ScimGroupMapper $mapper
     */
    public function __construct(
        private readonly ScimGroupMapper $mapper
    ) {
    }

    /**
     * Apply every operation in a PatchOp body to the group, in order.
     *
     * @param ScimGroup $group loaded group to mutate
     * @param int[] $memberIds current member ids, mutated in place
     * @param array<string,mixed> $body decoded PatchOp resource
     * @throws ScimException 400 on a malformed op, unsupported path or bad value
     */
    public function apply(ScimGroup $group, array &$memberIds, array $body): void
    {
        foreach ($this->operations($body) as $operation) {
            $this->applyOperation($group, $memberIds, $operation);
        }
    }

    /**
     * The non-empty `Operations` list, validated.
     *
     * @param array<string,mixed> $body
     * @return array<int,mixed>
     * @throws ScimException 400 `invalidSyntax` when absent, empty or not a list
     */
    private function operations(array $body): array
    {
        $operations = $body['Operations'] ?? $body['operations'] ?? null;
        if (!is_array($operations) || $operations === [] || !array_is_list($operations)) {
            throw ScimException::badRequest(
                'A PATCH request must carry a non-empty "Operations" array.',
                'invalidSyntax'
            );
        }

        return $operations;
    }

    /**
     * Apply a single PatchOp operation.
     *
     * @param ScimGroup $group
     * @param int[] $memberIds mutated in place
     * @param mixed $operation
     * @throws ScimException 400 on a malformed op, unsupported path or bad value
     */
    private function applyOperation(ScimGroup $group, array &$memberIds, mixed $operation): void
    {
        if (!is_array($operation)) {
            throw ScimException::badRequest('Each PATCH operation must be an object.', 'invalidSyntax');
        }

        $op = strtolower(trim((string)($operation['op'] ?? '')));
        if (!in_array($op, ['add', 'replace', 'remove'], true)) {
            throw ScimException::badRequest(
                sprintf('Unsupported PATCH op "%s".', (string)($operation['op'] ?? '')),
                'invalidSyntax'
            );
        }

        $path = isset($operation['path']) && is_string($operation['path']) ? trim($operation['path']) : '';

        if ($op === 'remove') {
            $this->applyRemove($group, $memberIds, $path, $operation);

            return;
        }

        if ($path === '') {
            $value = $operation['value'] ?? null;
            if (!is_array($value) || array_is_list($value)) {
                throw ScimException::badRequest(
                    'A path-less PATCH operation requires an object "value".',
                    'invalidValue'
                );
            }
            foreach ($value as $subPath => $subValue) {
                $this->assign($group, $memberIds, $op, (string)$subPath, $subValue);
            }

            return;
        }

        if (!array_key_exists('value', $operation)) {
            throw ScimException::badRequest(
                sprintf('PATCH "%s" for path "%s" requires a "value".', $op, $path),
                'invalidValue'
            );
        }
        $this->assign($group, $memberIds, $op, $path, $operation['value']);
    }

    /**
     * Apply a `remove` operation.
     *
     * @param ScimGroup $group
     * @param int[] $memberIds mutated in place
     * @param string $path
     * @param array<string,mixed> $operation
     * @throws ScimException 400 on an unsupported path
     */
    private function applyRemove(ScimGroup $group, array &$memberIds, string $path, array $operation): void
    {
        if ($path === '') {
            throw ScimException::badRequest('A "remove" operation requires a "path".', 'noTarget');
        }

        if (preg_match(self::MEMBER_PATH_PATTERN, $path, $m) === 1) {
            $memberIds = $this->without($memberIds, [$this->mapper->memberValueToId($m[1])]);

            return;
        }

        switch (strtolower($path)) {
            case 'members':
                // A path-less members remove clears the whole collection; a value
                // list (tolerated) removes just those members.
                $value = $operation['value'] ?? null;
                $memberIds = is_array($value) && $value !== []
                    ? $this->without($memberIds, $this->mapper->memberValuesToIds($value))
                    : [];
                break;
            case 'externalid':
                $group->externalId = null;
                break;
            default:
                throw ScimException::badRequest(sprintf('Attribute "%s" cannot be removed.', $path), 'mutability');
        }
    }

    /**
     * Apply an `add`/`replace` to one supported attribute path.
     *
     * @param ScimGroup $group
     * @param int[] $memberIds mutated in place
     * @param string $op `add` or `replace`
     * @param string $path SCIM attribute path (case-insensitive)
     * @param mixed $value
     * @throws ScimException 400 `invalidPath`/`invalidValue`
     */
    private function assign(ScimGroup $group, array &$memberIds, string $op, string $path, mixed $value): void
    {
        if (preg_match(self::MEMBER_PATH_PATTERN, $path) === 1) {
            throw ScimException::badRequest(
                sprintf('PATCH "%s" is not supported for a filtered member path.', $op),
                'invalidPath'
            );
        }

        switch (strtolower($path)) {
            case 'displayname':
                $group->displayName = $this->requiredString($value, 'displayName');
                break;
            case 'externalid':
                $external = is_string($value) ? trim($value) : '';
                $group->externalId = $external === '' ? null : $external;
                break;
            case 'members':
                $ids = $this->mapper->memberValuesToIds(is_array($value) ? $value : []);
                // `add` merges into the collection; `replace` overwrites it.
                $memberIds = $op === 'add' ? $this->merge($memberIds, $ids) : array_values($ids);
                break;
            default:
                throw ScimException::badRequest(sprintf('Unsupported PATCH path "%s".', $path), 'invalidPath');
        }
    }

    /**
     * Union of two id lists, first-seen order preserved.
     *
     * @param int[] $ids
     * @param int[] $add
     * @return int[]
     */
    private function merge(array $ids, array $add): array
    {
        $merged = [];
        foreach ([...$ids, ...$add] as $id) {
            $merged[(int)$id] = (int)$id;
        }

        return array_values($merged);
    }

    /**
     * `$ids` without the given ids.
     *
     * @param int[] $ids
     * @param int[] $remove
     * @return int[]
     */
    private function without(array $ids, array $remove): array
    {
        $drop = array_flip(array_map('intval', $remove));

        return array_values(array_filter($ids, static fn (int $id): bool => !isset($drop[$id])));
    }

    /**
     * A trimmed, non-empty string value for a required attribute.
     *
     * @param mixed $value
     * @param string $attribute SCIM attribute name for the error detail
     * @throws ScimException 400 `invalidValue` when empty or not a string
     */
    private function requiredString(mixed $value, string $attribute): string
    {
        $string = is_string($value) ? trim($value) : '';
        if ($string === '') {
            throw ScimException::badRequest(
                sprintf('Attribute "%s" must be a non-empty string.', $attribute),
                'invalidValue'
            );
        }

        return $string;
    }
}
