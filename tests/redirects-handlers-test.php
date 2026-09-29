<?php

declare(strict_types=1);

/**
 * Authorization of every Redirects admin-post handler (invariant 2).
 *
 * For each of the seven actions: a failed nonce and a failed capability each
 * stop the handler before $wpdb sees a single call or any option/transient is
 * written. A positive baseline proves the stops are the guard's doing — a
 * handler that always died would otherwise pass every negative case. The
 * baseline runs with the schema NOT ready, so each handler ends at the
 * throwing wp_safe_redirect stub (export included: it redirects with an
 * error instead of streaming and calling exit).
 */

namespace SFX {
    /** The theme-settings gate, reduced to a switch. */
    class AccessControl
    {
        public static function can_access_theme_settings(): bool
        {
            return !empty($GLOBALS['test_theme_access']);
        }
    }
}

namespace {
    // Every handler under test is a form POST; the settings save refuses anything else.
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $test_options   = [];
    $test_actions   = [];
    $test_writes    = [];
    $test_nonce_ok  = true;
    $test_can       = true;
    $test_theme_access = true;
    $test_nonce_seen = [];
    $test_caps_seen  = [];
    $test_redirect   = '';
    $test_settings_errors = [];

    /** Records every call; any use at all is what the negative cases forbid. */
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public array $calls = [];

        public function __call($name, $args)
        {
            $this->calls[] = $name;

            return false;
        }
    }
    $wpdb = new wpdb();

    function check_admin_referer($action = -1, $query_arg = '_wpnonce')
    {
        global $test_nonce_ok, $test_nonce_seen;
        $test_nonce_seen[] = $action;
        if (!$test_nonce_ok) {
            throw new RuntimeException('nonce');
        }

        return 1;
    }

    function current_user_can($cap, ...$args)
    {
        global $test_can, $test_caps_seen;
        $test_caps_seen[] = $cap;

        return $test_can;
    }

    function wp_die($message = '', $title = '', $args = [])
    {
        throw new RuntimeException('die');
    }

    function wp_safe_redirect($location, $status = 302, $by = 'WordPress')
    {
        global $test_redirect;
        $test_redirect = (string) $location;
        throw new RuntimeException('redirect');
    }

    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
        global $test_actions;
        $test_actions[] = [$hook, $callback];

        return true;
    }

    function get_option($name, $default = false)
    {
        global $test_options;

        return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
    }

    function update_option($name, $value, $autoload = null)
    {
        global $test_options, $test_writes;
        $test_writes[] = 'update_option:' . $name;
        $test_options[$name] = $value;

        return true;
    }

    function set_transient($name, $value, $ttl = 0)
    {
        global $test_writes;
        $test_writes[] = 'set_transient:' . $name;

        return true;
    }

    function get_transient($name)
    {
        return false;
    }

    function delete_transient($name)
    {
        global $test_writes;
        $test_writes[] = 'delete_transient:' . $name;

        return true;
    }

    function add_settings_error($setting, $code, $message, $type = 'error')
    {
        global $test_settings_errors;
        $test_settings_errors[] = compact('setting', 'code', 'message', 'type');
    }

    function get_settings_errors($setting = '', $sanitize = false)
    {
        global $test_settings_errors;

        return $test_settings_errors;
    }

    function add_query_arg(...$args)
    {
        if (is_array($args[0])) {
            [$params, $url] = [$args[0], $args[1] ?? ''];
        } else {
            [$params, $url] = [[$args[0] => $args[1]], $args[2] ?? ''];
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }

    function home_url($path = '')
    {
        return 'https://example.test' . $path;
    }

    function wp_validate_redirect($location, $fallback = '')
    {
        return $location;
    }

    function wp_unslash($value)
    {
        return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
    }

    function get_current_user_id()
    {
        return 7;
    }

    function __($text, $domain = null)
    {
        return $text;
    }

    function esc_html__($text, $domain = null)
    {
        return $text;
    }

    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }

    function _n($single, $plural, $number, $domain = null)
    {
        return $number === 1 ? $single : $plural;
    }

    require_once __DIR__ . '/../inc/Redirects/Rule.php';
    require_once __DIR__ . '/../inc/Redirects/Settings.php';
    require_once __DIR__ . '/../inc/Redirects/Repository.php';
    require_once __DIR__ . '/../inc/Redirects/AdminPage.php';

    use SFX\Redirects\AdminPage;
    use SFX\Redirects\Settings;

    function assert_true($condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * Run one handler under one gate configuration.
     *
     * @return array{stopped:string, db_calls:int, writes:int}
     */
    function run_case(callable $handler, bool $nonce_ok, bool $can, bool $theme): array
    {
        global $wpdb, $test_writes, $test_nonce_ok, $test_can, $test_theme_access,
            $test_nonce_seen, $test_caps_seen, $test_redirect, $test_settings_errors, $test_options;

        $wpdb->calls = [];
        $test_writes = [];
        $test_nonce_seen = [];
        $test_caps_seen = [];
        $test_redirect = '';
        $test_settings_errors = [];
        $test_options = []; // no sfx_redirects_db_version: schema not ready
        $test_nonce_ok = $nonce_ok;
        $test_can = $can;
        $test_theme_access = $theme;

        // Junk input the handlers must not get to act on before the guard.
        $_POST = ['id' => '3', 'op' => 'delete', 'ids' => ['1', '2'], 'source' => '/a', 'target' => '/b'];
        $_GET = ['id' => '3', 'op' => 'delete'];
        $_FILES = [];

        try {
            $handler();
        } catch (RuntimeException $e) {
            return ['stopped' => $e->getMessage(), 'db_calls' => count($wpdb->calls), 'writes' => count($test_writes)];
        }

        return ['stopped' => 'nothing', 'db_calls' => count($wpdb->calls), 'writes' => count($test_writes)];
    }

    $actions = [
        'sfx_redirects_save_rule'     => [AdminPage::class, 'handle_save_rule'],
        'sfx_redirects_rule_action'   => [AdminPage::class, 'handle_rule_action'],
        'sfx_redirects_bulk'          => [AdminPage::class, 'handle_bulk'],
        'sfx_redirects_404_action'    => [AdminPage::class, 'handle_404_action'],
        'sfx_redirects_import'        => [AdminPage::class, 'handle_import'],
        'sfx_redirects_export'        => [AdminPage::class, 'handle_export'],
        'sfx_redirects_save_settings' => [Settings::class, 'save_from_request'],
    ];

    assert_true(AdminPage::CAPABILITY === 'edit_others_posts', '0: module capability');
    assert_true(AdminPage::$menu_slug === 'sfx-redirects', '0: menu slug');
    assert_true(
        AdminPage::page_url('404') === 'https://example.test/wp-admin/tools.php?page=sfx-redirects&tab=404',
        '0: page_url builds the Tools URL with the tab'
    );

    $ran = 0;

    foreach ($actions as $action => $handler) {
        // 1. Nonce fails → stops at the nonce, nothing touched.
        $r = run_case($handler, false, true, true);
        assert_true($r['stopped'] === 'nonce', "{$action}: a failed nonce stops the handler (got {$r['stopped']})");
        assert_true($r['db_calls'] === 0, "{$action}: a failed nonce makes no \$wpdb call");
        assert_true($r['writes'] === 0, "{$action}: a failed nonce writes nothing");
        $ran++;

        // 2. Nonce ok, capability fails → wp_die, nothing touched.
        $r = run_case($handler, true, false, true);
        assert_true($r['stopped'] === 'die', "{$action}: a missing capability dies (got {$r['stopped']})");
        assert_true($r['db_calls'] === 0, "{$action}: a missing capability makes no \$wpdb call");
        assert_true($r['writes'] === 0, "{$action}: a missing capability writes nothing");
        assert_true(in_array('edit_others_posts', $test_caps_seen, true), "{$action}: the module capability was checked");
        $ran++;

        // 3. Baseline: both pass → the handler gets past the guard to its redirect.
        $r = run_case($handler, true, true, true);
        assert_true($r['stopped'] === 'redirect', "{$action}: baseline passes the guard and redirects (got {$r['stopped']})");
        assert_true($test_nonce_seen === [$action], "{$action}: the nonce was checked for exactly this action");
        assert_true(str_contains($test_redirect, 'page=sfx-redirects'), "{$action}: baseline redirects back to the page");
        // Notices go to the module's per-user transient, never core's site-global
        // 'settings_errors' one (which other screens print unescaped).
        assert_true(in_array('set_transient:sfx_redirects_notices_7', $test_writes, true), "{$action}: notices are parked in the per-user module transient");
        assert_true(!in_array('set_transient:settings_errors', $test_writes, true), "{$action}: core's settings_errors transient is never written");
        assert_true($r['db_calls'] === 0, "{$action}: with the schema not ready no query runs");
        $ran++;
    }

    // 4. Settings: the module capability alone is not enough.
    $r = run_case($actions['sfx_redirects_save_settings'], true, true, false);
    assert_true($r['stopped'] === 'die', 'settings: edit_others_posts without theme-settings access dies');
    assert_true($r['writes'] === 0, 'settings: and writes nothing');

    // 5. The rule handlers do not ask the settings gate: an editor may use them.
    $r = run_case($actions['sfx_redirects_bulk'], true, true, false);
    assert_true($r['stopped'] === 'redirect', 'bulk: an editor without theme-settings access passes the guard');

    // 6. register() hooks every handler (Settings::register() owns the settings save).
    $test_actions = [];
    AdminPage::register();
    Settings::register();
    $hooked = [];
    foreach ($test_actions as [$hook, $callback]) {
        $hooked[$hook] = $callback;
    }
    assert_true(isset($hooked['admin_menu']), '6: admin_menu is hooked');
    foreach ($actions as $action => $handler) {
        assert_true(isset($hooked['admin_post_' . $action]), "6: admin_post_{$action} is hooked");
        assert_true($hooked['admin_post_' . $action] === $handler, "6: admin_post_{$action} calls its handler");
    }

    // Every action × (nonce-fail, cap-fail, baseline) ran.
    assert_true($ran === 21, "counter: expected 21 cases, ran {$ran}");

    echo "OK\n";
}
