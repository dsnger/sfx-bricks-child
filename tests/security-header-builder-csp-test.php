<?php

declare(strict_types=1);

/**
 * SecurityHeader: no CSP while the Bricks builder is open, and only for a
 * user Bricks lets use the builder — the ?bricks=run parameter alone
 * (bricks_is_builder() reads only that) must not drop the CSP.
 */

$test_options = ['sfx_csp' => "default-src 'self'", 'sfx_csp_report_uri' => 'https://ex.test/csp'];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function esc_url_raw($url)
{
    return $url;
}

require_once __DIR__ . '/../inc/SecurityHeader/Controller.php';

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

$headers = build_security_headers();
assert_true(isset($headers['Content-Security-Policy']), 'without Bricks the CSP is sent');
assert_true(!isset($headers['X-Content-Security-Policy']), 'the legacy X-Content-Security-Policy header is gone');

// Bricks loaded from here on; both answers come from globals.
if (!function_exists('bricks_is_builder')) {
    function bricks_is_builder()
    {
        return (bool) ($GLOBALS['test_is_builder'] ?? false);
    }
}
require_once __DIR__ . '/support/bricks-capabilities-stub.php';

foreach ([
    'builder open, user may use it'      => [true, true, false],
    'builder param, user may not use it' => [true, false, true],
    'normal page, builder user'          => [false, true, true],
] as $case => [$builder, $allowed, $csp]) {
    $GLOBALS['test_is_builder'] = $builder;
    $GLOBALS['test_can_use_builder'] = $allowed;
    $headers = build_security_headers();
    assert_true(isset($headers['Content-Security-Policy']) === $csp, "{$case}: CSP " . ($csp ? 'sent' : 'omitted'));
    assert_true(isset($headers['Content-Security-Policy-Report-Only']) === $csp, "{$case}: Report-Only CSP " . ($csp ? 'sent' : 'omitted'));
    assert_true(isset($headers['X-Frame-Options']), "{$case}: the other headers stay");
}

echo "OK\n";
