<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Typed reader over the module's admin configuration.
 *
 * Wraps {@see ScopeConfigInterface} so the rest of the module never touches raw
 * config paths, and decrypts the bearer token (stored encrypted via the Magento
 * `Encrypted` backend model). The token is the credential an IdP presents to push
 * provisioning requests at the SCIM endpoint.
 */
class Config
{
    /** Whether the SCIM provisioning endpoint is enabled. */
    public const XML_PATH_ENABLED = 'dmlab_admin_scim/general/enabled';

    /** Bearer token the IdP presents, stored encrypted. */
    public const XML_PATH_BEARER_TOKEN = 'dmlab_admin_scim/general/bearer_token';

    /** SCIM-group → ACL-role rules, one `displayName=role_id` per line. */
    public const XML_PATH_GROUP_ROLE_MAP = 'dmlab_admin_scim/general/group_role_map';

    /** ACL role id assigned when a member's groups match no mapping rule. */
    public const XML_PATH_DEFAULT_ROLE = 'dmlab_admin_scim/general/default_role';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Whether the SCIM provisioning endpoint is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Decrypted bearer token, or null when unset.
     *
     * Also null when it decrypts to empty, e.g. after an encryption-key rotation.
     */
    public function getBearerToken(): ?string
    {
        $encrypted = $this->scopeConfig->getValue(self::XML_PATH_BEARER_TOKEN);
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $decrypted = $this->encryptor->decrypt($encrypted);

        return $decrypted === '' ? null : $decrypted;
    }

    /**
     * Parsed SCIM-group-displayName → ACL-role-id map.
     *
     * Reads the `displayName=role_id` lines; blank lines and `#` comments are
     * ignored, later entries win on duplicate group names. Applied by
     * {@see \DmLab\AdminScim\Model\Group\GroupRoleSynchronizer}.
     *
     * @return array<string,string> group displayName → ACL role id
     */
    public function getGroupRoleMap(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_GROUP_ROLE_MAP);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$group, $roleId] = explode('=', $line, 2);
            $group = trim($group);
            $roleId = trim($roleId);
            if ($group !== '' && $roleId !== '') {
                $map[$group] = $roleId;
            }
        }

        return $map;
    }

    /**
     * ACL role id used as the fallback when a member's groups match no rule.
     *
     * Null when unset — in which case such a member gets no role (deny).
     */
    public function getDefaultRoleId(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_DEFAULT_ROLE);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
