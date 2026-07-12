<?php

declare(strict_types=1);

// Minimal autoloader for the NusaDB PHP driver (no Composer required). Projects using Composer
// can rely on the PSR-4 mapping in composer.json instead.

spl_autoload_register(static function (string $class): void {
    $prefix = 'NusaDB\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Protocol.php also defines the Reader class in the same namespace; ensure it is loaded.
require_once __DIR__ . '/src/Protocol.php';
