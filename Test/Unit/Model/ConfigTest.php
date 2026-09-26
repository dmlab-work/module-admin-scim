<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Test\Unit\Model;

use DmLab\AdminScim\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values, array $decrypt = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path) => (bool)($values[$path] ?? false));

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')
            ->willReturnCallback(static fn (string $value) => $decrypt[$value] ?? '');

        return new Config($scopeConfig, $encryptor);
    }

    public function testIsEnabledReadsThroughIsSetFlag(): void
    {
        self::assertTrue($this->config([Config::XML_PATH_ENABLED => '1'])->isEnabled());
        self::assertFalse($this->config([Config::XML_PATH_ENABLED => '0'])->isEnabled());
        self::assertFalse($this->config([])->isEnabled());
    }

    public function testGetBearerTokenDecryptsStoredValue(): void
    {
        $config = $this->config(
            [Config::XML_PATH_BEARER_TOKEN => 'enc:blob'],
            ['enc:blob' => 'super-secret-token']
        );

        self::assertSame('super-secret-token', $config->getBearerToken());
    }

    public function testGetBearerTokenReturnsNullWhenUnsetOrEmptyAfterDecrypt(): void
    {
        self::assertNull($this->config([])->getBearerToken());
        self::assertNull($this->config([Config::XML_PATH_BEARER_TOKEN => ''])->getBearerToken());
        // Stored but decrypts to empty (e.g. key rotation) → null, not a stray value.
        self::assertNull($this->config([Config::XML_PATH_BEARER_TOKEN => 'stale'])->getBearerToken());
    }

    public function testGetGroupRoleMapParsesLines(): void
    {
        $config = $this->config([
            Config::XML_PATH_GROUP_ROLE_MAP => "# comment\nAdmins = 5\nEditors=3\n\nbad-line\nAdmins=7",
        ]);

        // Comments/blank/invalid lines are skipped; a later duplicate wins.
        self::assertSame(['Admins' => '7', 'Editors' => '3'], $config->getGroupRoleMap());
    }

    public function testGetGroupRoleMapEmptyWhenUnset(): void
    {
        self::assertSame([], $this->config([])->getGroupRoleMap());
        self::assertSame([], $this->config([Config::XML_PATH_GROUP_ROLE_MAP => "  \n  "])->getGroupRoleMap());
    }

    public function testGetDefaultRoleId(): void
    {
        self::assertSame('2', $this->config([Config::XML_PATH_DEFAULT_ROLE => ' 2 '])->getDefaultRoleId());
        self::assertNull($this->config([])->getDefaultRoleId());
        self::assertNull($this->config([Config::XML_PATH_DEFAULT_ROLE => '   '])->getDefaultRoleId());
    }
}
