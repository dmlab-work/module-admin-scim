<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PackagingTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    public function testComposerVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->composer()['version'] ?? '');
    }

    #[DataProvider('requiredPackages')]
    public function testComposerJsonRequiresPackage(string $package): void
    {
        self::assertArrayHasKey($package, $this->composer()['require'] ?? []);
    }

    public static function requiredPackages(): array
    {
        return array_map(static fn (string $p): array => [$p], [
            'php',
            'magento/framework',
            'magento/module-user',
            'magento/module-authorization',
            'magento/module-store',
            'magento/module-config',
            'psr/log',
        ]);
    }

    public function testModuleXmlSequencesCoreModulesAndVersion(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(self::MODULE_ROOT . '/etc/module.xml'));

        $module = $dom->getElementsByTagName('module')->item(0);
        self::assertSame('0.2.0', $module->getAttribute('setup_version'));

        $sequenced = [];
        foreach ($dom->getElementsByTagName('sequence') as $sequence) {
            foreach ($sequence->getElementsByTagName('module') as $item) {
                $sequenced[] = $item->getAttribute('name');
            }
        }
        self::assertSame(['Magento_User', 'Magento_Authorization', 'Magento_Config'], $sequenced);
    }

    public function testEveryTranslatableConfigTextHasAnI18nPhrase(): void
    {
        $phrases = [];
        $handle = fopen(self::MODULE_ROOT . '/i18n/en_US.csv', 'rb');
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $phrases[] = $row[0];
        }
        fclose($handle);

        $texts = [];
        $system = new \DOMXPath($this->load('/etc/adminhtml/system.xml'));
        foreach ($system->query('//*[@translate]') as $node) {
            foreach (explode(' ', $node->getAttribute('translate')) as $child) {
                foreach ($system->query($child, $node) as $text) {
                    $texts[] = $text->textContent;
                }
            }
        }
        $acl = new \DOMXPath($this->load('/etc/acl.xml'));
        foreach ($acl->query('//resource[starts-with(@id, "DmLab_")]') as $resource) {
            $texts[] = $resource->getAttribute('title');
        }

        self::assertNotEmpty($texts);
        foreach ($texts as $text) {
            self::assertContains($text, $phrases);
        }
    }

    private function composer(): array
    {
        $json = (string)file_get_contents(self::MODULE_ROOT . '/composer.json');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function load(string $relative): \DOMDocument
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(self::MODULE_ROOT . $relative));

        return $dom;
    }
}
