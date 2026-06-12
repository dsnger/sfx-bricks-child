<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/../../../../');

$test_options = [];
$test_filters = [];

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
