<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit;

use PHPUnit\Framework\TestCase;

class DiConfigTest extends TestCase
{
    public function testBearerTokenIsMarkedSensitive(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 2) . '/etc/di.xml'));

        $items = (new \DOMXPath($dom))->query(
            '/config/type[@name="Magento\Config\Model\Config\TypePool"]'
            . '/arguments/argument[@name="sensitive"]/item[@name="dmlab_admin_scim/general/bearer_token"]'
        );

        self::assertSame(1, $items->length);
        self::assertSame('1', trim($items->item(0)->textContent));
    }

    public function testAuditLoggerWritesToDedicatedFile(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 2) . '/etc/di.xml'));
        $xpath = new \DOMXPath($dom);

        $logger = $xpath->query(
            '/config/type[@name="DmLab\AdminScim\Model\Audit\AuditLogger"]/arguments/argument[@name="logger"]'
        );
        self::assertSame('DmLab\AdminScim\Logger\Audit', trim($logger->item(0)->textContent));

        $handler = $xpath->query(
            '/config/virtualType[@name="DmLab\AdminScim\Logger\Audit"]/arguments/argument[@name="handlers"]/item'
        );
        self::assertSame('DmLab\AdminScim\Logger\AuditHandler', trim($handler->item(0)->textContent));

        $fileName = $xpath->query(
            '/config/virtualType[@name="DmLab\AdminScim\Logger\AuditHandler"]/arguments/argument[@name="fileName"]'
        );
        self::assertSame('/var/log/dmlab_admin_scim_audit.log', trim($fileName->item(0)->textContent));
    }
}
