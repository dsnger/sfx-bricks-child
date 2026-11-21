<?php

declare(strict_types=1);

/**
 * Front script that tests/markdown-fpm-test.php runs through PHP-FPM: a stand-in for WordPress'
 * request (hooks, the eligible-page answers, core's enhancement buffer, template-loader's
 * template_include → wp_before_include_template → include, and the shutdown drain) around the
 * Markdown module. ?scenario= picks what the template and the site do; ?module=on loads the
 * module, otherwise the same request runs without it (the pass-through baseline).
 */

$scenario = (string) ($_GET['scenario'] ?? '');
$module = ($_GET['module'] ?? '') === 'on';

// --- Hooks: priority order, then insertion order, like WP_Hook. ---
$GLOBALS['sfx_hooks'] = [];
function add_filter($hook, $cb, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['sfx_hooks'][$hook][$priority][] = [$cb, $accepted_args];
    return true;
}
function add_action($hook, $cb, $priority = 10, $accepted_args = 1)
{
    return add_filter($hook, $cb, $priority, $accepted_args);
}
function has_filter($hook)
{
    return !empty($GLOBALS['sfx_hooks'][$hook]);
}
function has_action($hook)
{
    return has_filter($hook);
}
function apply_filters($hook, $value, ...$args)
{
    $by_priority = $GLOBALS['sfx_hooks'][$hook] ?? [];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as [$cb, $accepted]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, $accepted));
        }
    }
    return $value;
}
function do_action($hook, ...$args)
{
    $by_priority = $GLOBALS['sfx_hooks'][$hook] ?? [];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as [$cb, $accepted]) {
            $cb(...array_slice($args, 0, $accepted));
        }
    }
}

// --- Answers for Request::is_eligible(): a published page, nothing special about the request. ---
final class WP_Post
{
    public $ID = 5;
    public $post_type = 'page';
    public $post_status = 'publish';
}
function __($text, $domain = 'default') { return $text; }
function _doing_it_wrong($function, $message, $version) {}
function wp_trigger_error($function, $message, $level = E_USER_NOTICE) {}
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_is_serving_rest_request() { return false; }
function is_singular($types = '') { return true; }
function is_preview() { return false; }
function is_feed($feeds = '') { return false; }
function is_embed() { return false; }
function is_trackback() { return false; }
function is_robots() { return false; }
function is_favicon() { return false; }
function get_query_var($var, $default = '') { return $default; }
function get_queried_object() { return new WP_Post(); }
function get_post_type($post = null) { return $post->post_type; }
function get_post_status($post = null) { return $post->post_status; }
function post_password_required($post = null) { return false; }
function get_option($name, $default = false) { return $name === 'page_for_posts' ? '0' : $default; }
function get_post_types($args = [], $output = 'names') { return ['page' => (object) ['name' => 'page', 'label' => 'Pages']]; }
function wp_get_canonical_url($post = null) { return $post instanceof WP_Post ? 'https://ex.test/sample/' : false; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function esc_url($url) { return $url; }
function register_setting($group, $name, $args = []) { return true; }

require __DIR__ . '/markdown-http-stubs.php';
require __DIR__ . '/html-api.php';
require __DIR__ . '/wp-template-enhancement.php';
foreach (['Url', 'Escape', 'Converter', 'Headers', 'Settings', 'Request', 'Response', 'AdminPage', 'Controller'] as $class) {
    require __DIR__ . '/../../inc/Markdown/' . $class . '.php';
}

// --- What wp-settings.php and default-filters.php set up. ---
function shutdown_action_hook()
{
    do_action('shutdown');
}
register_shutdown_function('shutdown_action_hook');
add_action('wp_before_include_template', 'wp_start_template_enhancement_output_buffer', 1000);
add_action('shutdown', 'wp_ob_end_flush_all', 1);

if ($scenario === 'env') {
    header('Content-Type: application/json');
    echo json_encode([
        'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
        'litespeed_finish_request' => function_exists('litespeed_finish_request'),
        'output_buffering' => ini_get('output_buffering'),
        'level' => ob_get_level(),
    ]);
    return;
}

header('Vary: Accept-Encoding');
if ($module) {
    new \SFX\Markdown\Controller();
}

// --- What other code on the site does before the template. ---
switch ($scenario) {
    case 'convert':
        header('Content-Length: 12345');
        header('ETag: "abc"');
        header('Last-Modified: Sat, 21 Nov 2026 10:00:00 GMT');
        break;
    case 'late-output':
        add_action('shutdown', static function () { echo '<!--shutdown-0-->'; }, 0);
        add_action('shutdown', static function () { echo '<!--shutdown-1-->'; }, 1);
        add_action('shutdown', static function () { echo '<!--shutdown-10-->'; }, 10);
        register_shutdown_function(static function () { echo '<!--shutdown-function-->'; });
        ob_start(static function (string $buffer, int $phase): string {
            if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
                // A page cache decides here (WP Super Cache's wp_cache_ob_callback, say).
                header('X-Cache-Flag: ' . (defined('DONOTCACHEPAGE') ? 'set' : 'unset'));
                return $buffer . '<!--outer-->';
            }
            return $buffer;
        });
        break;
    case 'close-own-buffer':
    case 'ob-flush':
    case 'close-and-reopen':
        ob_start(); // A plain outer buffer (a cache plugin's, say): the template's flush sends no headers, so only P3 can tell.
        break;
    case 'non-removable-outer':
        ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
        break;
    case 'status-404':
        header('HTTP/1.1 404 Not Found', true, 404);
        break;
    case 'location':
        header('Location: https://ex.test/other/', true, 200); // status stays 200: the Location check alone
        break;
    case 'json':
        header('Content-Type: application/json');
        break;
    case 'enhance-convert':
    case 'enhance-passthrough':
        add_filter('wp_template_enhancement_output_buffer', static function (string $html): string {
            static $calls = 0;
            header('X-Filter-Calls: ' . ++$calls);
            return str_replace('</body>', '<p>ENHANCED</p></body>', str_replace('</main>', '<p>ENHANCED</p></main>', $html));
        });
        add_action('wp_finalized_template_enhancement_output_buffer', static function (string $output): void {
            header('X-Finalized: ' . md5($output), false);
        });
        break;
}

// --- template-loader.php ---
$template = apply_filters('template_include', __DIR__ . '/markdown-fpm-template.php');
do_action('wp_before_include_template', $template);
include $template;
