<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model;

use DmLab\AdminScim\Exception\ScimException;

/**
 * Rejects string attributes longer than their column instead of letting the DB truncate or fail.
 */
trait AttributeLength
{
    /**
     * The value, when it fits the column.
     *
     * @param string $value
     * @param string $attribute SCIM attribute name for the error detail
     * @param int $max column length in characters
     * @throws ScimException 400 `invalidValue` when longer than `$max`
     */
    private function assertLength(string $value, string $attribute, int $max = 255): string
    {
        if (mb_strlen($value) > $max) {
            throw ScimException::badRequest(
                sprintf('Attribute "%s" exceeds the maximum length of %d.', $attribute, $max),
                'invalidValue'
            );
        }

        return $value;
    }
}
