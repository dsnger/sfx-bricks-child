<?php

declare(strict_types=1);

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function esc_url_raw($url)
{
    return $url;
}

require_once dirname(__DIR__) . '/inc/SecurityHeader/Controller.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function build_security_headers(): array
{
    $reflection = new ReflectionMethod(\SFX\SecurityHeader\Controller::class, 'build_headers_array');
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }

    return $reflection->invoke(null);
}

$base_policy = 'accelerometer=(), camera=(self), fullscreen=*, geolocation=(self), microphone=(self), payment=*';

$test_options = [
    'sfx_permissions_policy' => $base_policy,
    'sfx_restrict_sensitive_browser_features' => false,
];

$headers = build_security_headers();
assert_true(
    $headers['Permissions-Policy'] === $base_policy,
    'Permissions-Policy should stay unchanged when sensitive browser feature restriction is disabled'
);

$test_options = [
    'sfx_permissions_policy' => $base_policy,
    'sfx_restrict_sensitive_browser_features' => true,
];

$headers = build_security_headers();
assert_true(
    $headers['Permissions-Policy'] === 'accelerometer=(), camera=(), fullscreen=*, geolocation=(), microphone=(), payment=*',
    'Permissions-Policy should restrict geolocation, camera, and microphone while preserving other directives'
);

$test_options = [
    'sfx_permissions_policy' => '',
    'sfx_restrict_sensitive_browser_features' => true,
];

$headers = build_security_headers();
assert_true(
    $headers['Permissions-Policy'] === 'geolocation=(), camera=(), microphone=()',
    'Permissions-Policy should emit the strict sensitive-feature policy when enabled without a custom policy'
);

echo "OK\n";
