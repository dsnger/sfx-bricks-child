<?php

declare(strict_types=1);

// The GitHub token may only be attached to this theme's own package, never to any URL containing "github.com".

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');

    $failures = 0;
    $filters = [];

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        global $failures;
        if ($expected !== $actual) {
            $failures++;
            echo "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
        }
    }
    function add_filter(string $hook, $callback, $priority = 10, $accepted_args = 1): bool
    {
        global $filters;
        $filters[$hook][] = $callback;
        return true;
    }

    require dirname(__DIR__) . '/inc/GitHubThemeUpdater.php';

    $ref = new ReflectionClass(\SFX\GitHubThemeUpdater::class);
    $updater = $ref->newInstanceWithoutConstructor();
    foreach (['github_username' => 'dsnger', 'github_repo' => 'sfx-bricks-child', 'authorize_token' => 'ghp_secret', 'debug' => false] as $prop => $value) {
        $property = $ref->getProperty($prop);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true); // required on 8.0, deprecated from 8.5
        }
        $property->setValue($updater, $value);
    }

    $cases = [
        'https://api.github.com/repos/dsnger/sfx-bricks-child/zipball/v0.29.0' => true,
        'https://github.com/dsnger/sfx-bricks-child/releases/download/v0.29.0/sfx-bricks-child.zip' => true,
        'https://api.github.com/repos/DSNGER/sfx-bricks-child/zipball/v0.29.0' => true,
        'https://api.github.com/repos/someone-else/plugin/zipball/v1' => false,
        'https://github.com/dsnger/sfx-bricks-child-evil/archive.zip' => false,
        'https://evil.example/github.com/dsnger/sfx-bricks-child/x.zip' => false,
        'https://github.com.evil.example/dsnger/sfx-bricks-child/x.zip' => false,
        'http://api.github.com/repos/dsnger/sfx-bricks-child/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/../../someone-else/plugin/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/%2e%2e/%2E%2E/someone-else/plugin/zipball/v1' => false,
        'https://github.com/dsnger/sfx-bricks-child/./../x.zip' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/.%2e/.%2e/other/repo/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/%2E./%2E./other/repo/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/%252e%252e/%252e%252e/other/repo/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/..%2f..%2fother/repo/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/.%2e%5c.%2e%5cother/repo/zipball/v1' => false,
        'https://api.github.com/repos/dsnger/sfx-bricks-child/zipball/v0.29.0?x=..' => true,
    ];
    foreach ($cases as $package => $expected) {
        $filters = [];
        assert_same(false, $updater->download_package(false, $package, null), "reply untouched: {$package}");
        $headers = [];
        foreach ($filters['http_request_args'] ?? [] as $cb) {
            $headers = $cb(['headers' => []], $package)['headers'];
        }
        assert_same($expected, isset($headers['Authorization']), "token attached = " . var_export($expected, true) . ": {$package}");
    }

    if ($failures > 0) {
        echo "Tests failed: {$failures}\n";
        exit(1);
    }
    echo "github-updater-token-scope-test: PASS\n";
}
