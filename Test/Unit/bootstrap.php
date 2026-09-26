<?php
/**
 * Copyright © DMLab. All rights reserved.
 *
 * Standalone unit-test bootstrap: loads Magento's Composer autoloader (for the
 * framework classes), registers a PSR-4 map for this module so classes resolve
 * without a full `composer install`, then runs the module registration.
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);

$candidates = [
    getenv('MAGENTO_ROOT') ? rtrim((string)getenv('MAGENTO_ROOT'), '/') . '/vendor/autoload.php' : null,
    '/var/www/html/vendor/autoload.php',
    $moduleRoot . '/vendor/autoload.php',
    $moduleRoot . '/../../src/vendor/autoload.php',
];

$autoloaderLoaded = false;
foreach ($candidates as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        require $candidate;
        $autoloaderLoaded = true;
        break;
    }
}

if (!$autoloaderLoaded) {
    fwrite(STDERR, "Unable to locate a Composer autoloader (set MAGENTO_ROOT).\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

$psr4 = [
    'DmLab\\AdminScim\\' => $moduleRoot,
];

spl_autoload_register(static function (string $class) use ($psr4): void {
    foreach ($psr4 as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $base . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

require $moduleRoot . '/registration.php';
