<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/../../../../');

$test_options = [];
$test_filters = [];
$test_actions = [];
$test_pages_by_path = [];
$test_posts_by_slug = [];
$test_post_type_rewrite_slugs = [];
$test_taxonomy_rewrite_slugs = [];
$test_settings_errors = [];
$test_home_url = 'https://example.test';
$test_logged_in = false;
$test_redirect_location = null;

function __($text, $domain = 'default')
{
    return $text;
}

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_filters;
    $test_filters[$hook_name][] = $callback;

    return true;
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_actions;
    $test_actions[$hook_name][] = [
        'callback' => $callback,
        'priority' => $priority,
    ];

    return true;
}

function update_option($option, $value, $autoload = null): bool
{
    return true;
}

function is_admin(): bool
{
    return false;
}

function status_header($code, $description = ''): void
{
    echo 'status:' . (int) $code . "\n";
}

function nocache_headers(): void
{
    echo "nocache\n";
}

function sanitize_title(string $title): string
{
    $title = strtolower(trim($title));
    $title = preg_replace('/[^a-z0-9\-]+/', '-', $title) ?? '';
    $title = trim($title, '-');

    return $title;
}

function sanitize_text_field($value): string
{
    return trim((string) $value);
}

function get_page_by_path(string $page_path, $output = null, $post_type = 'page')
{
    global $test_pages_by_path;

    return $test_pages_by_path[$page_path] ?? null;
}

function get_posts(array $args = [])
{
    global $test_posts_by_slug;

    $slug = $args['name'] ?? '';

    if ($slug === '' || !isset($test_posts_by_slug[$slug])) {
        return [];
    }

    return $test_posts_by_slug[$slug];
}

function get_post_types($args = [], $output = 'names')
{
    global $test_post_type_rewrite_slugs;

    if ($output !== 'objects') {
        return array_keys($test_post_type_rewrite_slugs);
    }

    $objects = [];
    foreach ($test_post_type_rewrite_slugs as $name => $rewrite_slug) {
        $objects[$name] = (object) [
            'name'    => $name,
            'rewrite' => ['slug' => $rewrite_slug],
        ];
    }

    return $objects;
}

function get_taxonomies($args = [], $output = 'names')
{
    global $test_taxonomy_rewrite_slugs;

    if ($output !== 'objects') {
        return array_keys($test_taxonomy_rewrite_slugs);
    }

    $objects = [];
    foreach ($test_taxonomy_rewrite_slugs as $name => $rewrite_slug) {
        $objects[$name] = (object) [
            'name'    => $name,
            'rewrite' => ['slug' => $rewrite_slug],
        ];
    }

    return $objects;
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    global $test_settings_errors;

    $test_settings_errors[] = [
        'setting' => $setting,
        'code'    => $code,
        'message' => $message,
        'type'    => $type,
    ];
}

function home_url(string $path = '', ?string $scheme = null): string
{
    global $test_home_url;

    if ($path === '' || $path === '/') {
        return $test_home_url . '/';
    }

    return rtrim($test_home_url, '/') . '/' . ltrim($path, '/');
}

function site_url(string $path = '', ?string $scheme = null): string
{
    global $test_home_url;

    if ($path === '') {
        return $test_home_url;
    }

    return rtrim($test_home_url, '/') . '/' . ltrim($path, '/');
}

function wp_parse_url(string $url, int $component = -1)
{
    $parts = parse_url($url);

    if ($component === -1) {
        return $parts;
    }

    return $parts[match ($component) {
        PHP_URL_SCHEME => 'scheme',
        PHP_URL_HOST => 'host',
        PHP_URL_PORT => 'port',
        PHP_URL_USER => 'user',
        PHP_URL_PASS => 'pass',
        PHP_URL_PATH => 'path',
        PHP_URL_QUERY => 'query',
        PHP_URL_FRAGMENT => 'fragment',
        default => 'path',
    }] ?? null;
}

function add_query_arg(array $args, string $url): string
{
    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . http_build_query($args);
}

function is_user_logged_in(): bool
{
    global $test_logged_in;

    return $test_logged_in;
}

function wp_safe_redirect(string $location, int $status = 302): void
{
    global $test_redirect_location;

    $test_redirect_location = $location;
}

// Git tracks WP Optimizer under lowercase inc/wpoptimizer/ for case-sensitive filesystems.
require_once dirname(__DIR__) . '/inc/wpoptimizer/classes/HideLogin.php';
require_once dirname(__DIR__) . '/inc/wpoptimizer/Settings.php';
require_once dirname(__DIR__) . '/inc/wpoptimizer/Controller.php';
require_once dirname(__DIR__) . '/inc/GeneralThemeOptions/Settings.php';
require_once dirname(__DIR__) . '/inc/SFXBricksChildTheme.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function new_controller_without_constructor(): object
{
    $reflection = new ReflectionClass(\SFX\WPOptimizer\Controller::class);

    return $reflection->newInstanceWithoutConstructor();
}

function new_theme_without_constructor(): object
{
    $reflection = new ReflectionClass(\SFX\SFXBricksChildTheme::class);

    return $reflection->newInstanceWithoutConstructor();
}

function invoke_private(object $object, string $method, array $args = [])
{
    $reflection = new ReflectionMethod($object, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }

    return $reflection->invokeArgs($object, $args);
}

if (($argv[1] ?? '') === 'xmlrpc-child') {
    define('XMLRPC_REQUEST', true);
    invoke_private(new_controller_without_constructor(), 'disable_xmlrpc');
    echo "continued\n";
    exit(0);
}

$hideLoginClass = \SFX\WPOptimizer\classes\HideLogin::class;

assert_true(
    $hideLoginClass::normalize_slug(' /My Secret Login/ ') === 'my-secret-login',
    'normalize_slug should sanitize and trim slashes'
);

assert_true(
    $hideLoginClass::is_reserved_slug('wp-login') === true,
    'wp-login should be rejected as a reserved slug'
);

assert_true(
    $hideLoginClass::is_valid_slug('ab') === false,
    'slugs shorter than 3 characters should be invalid'
);

$test_pages_by_path['about'] = (object) ['ID' => 12];
assert_true(
    $hideLoginClass::is_valid_slug('about') === false,
    'slugs matching an existing page path should be invalid'
);
unset($test_pages_by_path['about']);

$test_posts_by_slug['hello-world'] = [42];
assert_true(
    $hideLoginClass::is_valid_slug('hello-world') === false,
    'slugs matching a published post should be invalid'
);
unset($test_posts_by_slug['hello-world']);

$test_post_type_rewrite_slugs['product'] = 'shop';
assert_true(
    $hideLoginClass::is_valid_slug('shop') === false,
    'slugs matching a public post type rewrite base should be invalid'
);
unset($test_post_type_rewrite_slugs['product']);

$test_taxonomy_rewrite_slugs['product_cat'] = 'product-category';
assert_true(
    $hideLoginClass::is_valid_slug('product-category') === false,
    'slugs matching a public taxonomy rewrite base should be invalid'
);
unset($test_taxonomy_rewrite_slugs['product_cat']);

$sanitized = \SFX\WPOptimizer\Settings::sanitize_options([
    'hide_login' => 1,
    'custom_login_slug' => 'wp-login',
]);

assert_true(
    ($sanitized['hide_login'] ?? 1) === 0,
    'enabling hide_login with a reserved slug should force hide_login off'
);

assert_true(
    ($sanitized['custom_login_slug'] ?? 'x') === '',
    'invalid submitted slug should not be saved when no previous valid slug exists'
);

assert_true(
    !empty($test_settings_errors)
    && $test_settings_errors[0]['code'] === 'custom_login_slug',
    'invalid hide_login enable attempts should surface a settings error'
);
$test_settings_errors = [];

$test_options['sfx_wpoptimizer_options'] = [
    'custom_login_slug' => 'my-secret-login',
    'hide_login' => 1,
];

$sanitized = \SFX\WPOptimizer\Settings::sanitize_options([
    'hide_login' => 1,
    'custom_login_slug' => 'wp-admin',
]);

assert_true(
    ($sanitized['hide_login'] ?? 0) === 1,
    'invalid replacement slug should keep hide_login enabled when a previous valid slug exists'
);

assert_true(
    $sanitized['custom_login_slug'] === 'my-secret-login',
    'invalid submitted slug should preserve the last valid saved slug'
);

$sanitized = \SFX\WPOptimizer\Settings::sanitize_options([
    'hide_login' => 0,
    'custom_login_slug' => '  /another-login/ ',
]);

assert_true(
    $sanitized['custom_login_slug'] === 'another-login',
    'valid submitted slug should be normalized and saved while hide_login is off'
);

$hideLoginClass::reset_for_tests();
$hideLoginClass::register('my-secret-login');

assert_true(
    $hideLoginClass::was_handle_request_called(),
    'register() should call handle_request() in the same pass'
);

assert_true(
    empty($test_actions['init'] ?? []),
    'register() should not rely on a nested init callback for the current request'
);

$hideLoginClass::reset_for_tests();
$hideLoginClass::register('my-secret-login');

$login_url = $hideLoginClass::filter_login_url(
    'https://example.test/wp-login.php?redirect_to=https%3A%2F%2Fexample.test%2Fwp-admin%2F',
    '',
    false
);

assert_true(
    str_contains($login_url, '/my-secret-login/'),
    'login_url filter should rewrite to the custom slug'
);

assert_true(
    str_contains($login_url, 'redirect_to='),
    'login_url filter should preserve query args'
);

$logout_url = $hideLoginClass::filter_logout_url(
    'https://example.test/wp-login.php?action=logout&_wpnonce=abc123&redirect_to=https%3A%2F%2Fexample.test',
    'https://example.test'
);

assert_true(
    str_contains($logout_url, '/my-secret-login/')
    && str_contains($logout_url, 'action=logout')
    && str_contains($logout_url, '_wpnonce=abc123'),
    'logout_url filter should preserve action and nonce query args'
);

$lostpassword_url = $hideLoginClass::filter_lostpassword_url(
    'https://example.test/wp-login.php?action=lostpassword',
    ''
);

assert_true(
    str_contains($lostpassword_url, '/my-secret-login/')
    && str_contains($lostpassword_url, 'action=lostpassword'),
    'lostpassword_url filter should preserve action query args'
);

$register_url = $hideLoginClass::filter_register_url(
    'https://example.test/wp-login.php?action=register'
);

assert_true(
    str_contains($register_url, '/my-secret-login/')
    && str_contains($register_url, 'action=register'),
    'register_url filter should preserve action query args'
);

$site_login_url = $hideLoginClass::filter_site_url(
    'https://example.test/wp-login.php',
    'wp-login.php',
    null,
    null
);

assert_true(
    str_contains($site_login_url, '/my-secret-login/'),
    'site_url filter should rewrite wp-login.php paths'
);

$reset_password_url = $hideLoginClass::filter_network_site_url(
    'https://example.test/wp-login.php?login=testuser&key=abc123&action=rp',
    'wp-login.php?login=testuser&key=abc123&action=rp',
    'login'
);

assert_true(
    str_contains($reset_password_url, '/my-secret-login/')
    && str_contains($reset_password_url, 'action=rp')
    && str_contains($reset_password_url, 'key=abc123'),
    'network_site_url filter should rewrite password reset links with action=rp'
);

$resetpass_url = $hideLoginClass::filter_network_site_url(
    'https://example.test/wp-login.php?action=resetpass',
    'wp-login.php?action=resetpass',
    'login'
);

assert_true(
    str_contains($resetpass_url, '/my-secret-login/')
    && str_contains($resetpass_url, 'action=resetpass'),
    'network_site_url filter should rewrite resetpass links'
);

$hideLoginClass::reset_for_tests();
$slug_property = new ReflectionProperty($hideLoginClass, 'slug');
if (PHP_VERSION_ID < 80100) {
    $slug_property->setAccessible(true);
}
$slug_property->setValue(null, 'my-secret-login');
$_SERVER['REQUEST_URI'] = '/wp-admin/';

$blocked_method = new ReflectionMethod($hideLoginClass, 'is_blocked_login_request');
if (PHP_VERSION_ID < 80100) {
    $blocked_method->setAccessible(true);
}

assert_true(
    $blocked_method->invoke(null) === true,
    'unauthenticated wp-admin requests should be blocked instead of redirecting to the custom login slug'
);

$_SERVER['REQUEST_URI'] = '/?redirect_to=https%3A%2F%2Fexample.com%2Fwp-login.php';

assert_true(
    $blocked_method->invoke(null) === false,
    'login endpoint checks should ignore wp-login.php substrings in query parameters'
);

$_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';
$admin_post_method = new ReflectionMethod($hideLoginClass, 'is_wp_admin_request');
if (PHP_VERSION_ID < 80100) {
    $admin_post_method->setAccessible(true);
}

assert_true(
    $admin_post_method->invoke(null) === false,
    'admin-post.php should remain reachable for public admin_post_nopriv handlers'
);

$hideLoginClass::reset_for_tests();
unset($_SERVER['REQUEST_URI']);

$controller = new_controller_without_constructor();
$theme = new_theme_without_constructor();

assert_true(
    invoke_private($theme, 'is_option_enabled', ['sfx_general_options', 'enable_wp_optimizer']) === true,
    'missing saved general options should fall back to enable_wp_optimizer default so WP Optimizer loads'
);

assert_true(
    invoke_private($controller, 'is_option_enabled', ['disable_xmlrpc']) === true,
    'missing saved options should fall back to the disable_xmlrpc field default'
);

assert_true(
    invoke_private($controller, 'is_option_enabled', ['disable_author_archives']) === true,
    'missing saved options should fall back to the disable_author_archives field default'
);

assert_true(
    invoke_private($controller, 'is_option_enabled', ['block_author_query']) === true,
    'missing saved options should default to blocking ?author= user enumeration'
);

assert_true(
    invoke_private($controller, 'is_option_enabled', ['block_rest_users_anonymous']) === true,
    'missing saved options should default to blocking anonymous REST user enumeration'
);

$child_command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' xmlrpc-child';
assert_true(function_exists('proc_open'), 'proc_open() is required for the XML-RPC subprocess test');

$process = proc_open($child_command, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

assert_true(is_resource($process), 'failed to start XML-RPC subprocess test');

$child_output = stream_get_contents($pipes[1]);
$child_error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$child_status = proc_close($process);

assert_true(
    $child_status === 0 && $child_error === '',
    'XML-RPC subprocess failed. Exit code: ' . $child_status . '. STDERR: ' . $child_error . '. STDOUT: ' . $child_output
);

assert_true(
    is_string($child_output) && str_contains($child_output, "status:403\n") && !str_contains($child_output, "continued\n"),
    'disable_xmlrpc should hard-block XML-RPC requests before WordPress exposes xmlrpc.php. Child output: ' . $child_output
);

echo "OK\n";
