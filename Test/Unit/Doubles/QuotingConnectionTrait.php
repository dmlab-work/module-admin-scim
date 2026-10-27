<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Doubles;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * A {@see ResourceConnection} whose adapter quotes like MySQL closely enough to assert SQL text.
 * Consumed by a PHPUnit `TestCase`.
 */
trait QuotingConnectionTrait
{
    /**
     * Resource connection with `quoteInto`/`quoteIdentifier` and an unprefixed `getTableName`.
     */
    private function quotingResource(): ResourceConnection
    {
        $quote = static fn ($value): string => is_int($value)
            ? (string)$value
            : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value) . "'";
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace('?', $quote($value), $text)
        );
        $adapter->method('quoteIdentifier')->willReturnCallback(static fn ($name): string => '`' . $name . '`');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }
}
