<?php

declare(strict_types=1);

namespace Marko\Testing\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The fixture project the TestClient tests boot: routes for JSON, forms,
 * redirects, cookies, sessions, auth and request-scoped state.
 */
function httpAppPath(): string
{
    return __DIR__ . '/fixtures/http-app';
}

/**
 * Remove the session files the fixture app wrote for this process (see the
 * fixture's config/session.php).
 */
function removeHttpAppSessions(): void
{
    $directory = sys_get_temp_dir() . '/marko-testing-http-app/' . getmypid();

    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}
