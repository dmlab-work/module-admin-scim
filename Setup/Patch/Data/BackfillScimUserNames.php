<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Setup\Patch\Data;

use DmLab\AdminScim\Model\User\ScimUserMapper;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Copies the login of every SCIM-managed admin into the SCIM userName column, so
 * `userName` filters keep matching admins provisioned before the column existed.
 */
class BackfillScimUserNames implements DataPatchInterface
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
            [ScimUserMapper::USER_NAME_FIELD => new Expression('username')],
            [
                ScimUserMapper::MANAGED_FIELD . ' = ?' => 1,
                ScimUserMapper::USER_NAME_FIELD . ' IS NULL',
            ]
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [MarkExistingScimAdmins::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
