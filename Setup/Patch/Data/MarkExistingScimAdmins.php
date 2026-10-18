<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Setup\Patch\Data;

use DmLab\AdminScim\Model\User\ScimUserMapper;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Flags admins provisioned before the managed-only scope existed (those carrying
 * an externalId or a SCIM group membership) as SCIM-managed, so they stay visible to their IdP.
 */
class MarkExistingScimAdmins implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->update(
            $this->moduleDataSetup->getTable('admin_user'),
            [ScimUserMapper::MANAGED_FIELD => 1],
            sprintf(
                '%s IS NOT NULL OR user_id IN (SELECT user_id FROM %s)',
                ScimUserMapper::EXTERNAL_ID_FIELD,
                $connection->quoteIdentifier($this->moduleDataSetup->getTable('dmlab_scim_group_member'))
            )
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
