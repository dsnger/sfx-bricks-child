<?php

declare(strict_types=1);

// The updater exposes no transient-clearing request outside the nonce-checked debug page,
// and only a user with manage_options can switch its debug logging on.

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');

    $failures = 0;
    $actions = [];
    $can_manage = false;
    $options = [];

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        global $failures;
        if ($expected !== $actual) {
            $failures++;
            echo "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
        }
    }

    class WP_Theme
    {
        public function get(string $key): string
        {
            return $key === 'Version' ? '1.0.0' : 'SFX Bricks Child';
        }
    }
    function wp_get_theme($slug = null): WP_Theme { return new WP_Theme(); }
    function get_stylesheet(): string { return 'sfx-bricks-child'; }
    function get_stylesheet_directory(): string { return sys_get_temp_dir() . '/sfx-updater-test-no-theme'; }
    function current_user_can(string $cap): bool { global $can_manage; return $cap === 'manage_options' && $can_manage; }
    function is_admin(): bool { return false; }
    function current_time(string $type): string { return '2026-10-21 00:00:00'; }
    function get_option(string $name, $default = false) { global $options; return $options[$name] ?? $default; }
    function update_option(string $name, $value): bool { global $options; $options[$name] = $value; return true; }
    function get_site_transient(string $name) { return false; }
    function add_action(string $hook, $callback, $priority = 10, $accepted_args = 1): bool
    {
        global $actions;
        $actions[$hook][] = $callback;
        return true;
    }
    function add_filter(string $hook, $callback, $priority = 10, $accepted_args = 1): bool { return true; }

    require dirname(__DIR__) . '/inc/GitHubThemeUpdater.php';

    $debug_of = static function (\SFX\GitHubThemeUpdater $updater): bool {
        $property = new ReflectionProperty(\SFX\GitHubThemeUpdater::class, 'debug');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true); // required on 8.0, deprecated from 8.5
        }
        return $property->getValue($updater);
    };

    // Debug switch: the request parameter alone does not turn logging on.
    $_GET = ['debug_updater' => '1'];
    $can_manage = false;
    $options = [];
    assert_same(false, $debug_of(new \SFX\GitHubThemeUpdater()), 'debug stays off for a user without manage_options');
    assert_same(false, isset($options['github_theme_updater_debug']), 'no debug log is written for a user without manage_options');

    $can_manage = true;
    assert_same(true, $debug_of(new \SFX\GitHubThemeUpdater()), 'debug turns on for a user with manage_options');

    $_GET = [];
    assert_same(false, $debug_of(new \SFX\GitHubThemeUpdater()), 'debug stays off without the parameter');

    // Maintenance trigger: nothing registered on admin_init clears the update transient by request.
    $can_manage = false;
    $actions = [];
    $updater = new \SFX\GitHubThemeUpdater();
    $updater->initialize();
    $admin_init = array_map(static fn($cb) => is_array($cb) ? $cb[1] : 'closure', $actions['admin_init'] ?? []);
    assert_same(['check_version_change'], $admin_init, 'admin_init carries only the version-change check');
    assert_same(false, method_exists($updater, 'force_check'), 'no force_check request handler exists');

    // The notice that announced the removed trigger is gone too.
    $can_manage = true;
    $_GET = ['updated' => 'force-check'];
    ob_start();
    (new \SFX\GitHubThemeUpdater())->update_admin_notice();
    assert_same('', ob_get_clean(), 'no force-check notice is printed');
    $_GET = [];

    if ($failures > 0) {
        echo "Tests failed: {$failures}\n";
        exit(1);
    }
    echo "github-updater-admin-checks-test: PASS\n";
}
