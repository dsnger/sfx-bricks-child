<?php

declare(strict_types=1);

/**
 * Disabling Bricks styling must not take the child theme's own CSS with it:
 * bricks-child, sfx-frontend and the style modules depend on `bricks-frontend`,
 * and WordPress drops a handle whose dependency is missing. So after the
 * deregister loop, `bricks-frontend` is re-registered as an empty anchor.
 */

$GLOBALS['calls'] = [];
$GLOBALS['opts'] = ['sfx_general_options' => ['disable_bricks_css' => 1]];

function get_option($name, $default = false) { return $GLOBALS['opts'][$name] ?? $default; }
function wp_dequeue_style($h) { $GLOBALS['calls'][] = ['dequeue', $h]; }
function wp_deregister_style($h) { $GLOBALS['calls'][] = ['deregister', $h]; }
function wp_register_style($h, $src = false) { $GLOBALS['calls'][] = ['register', $h, $src]; }
function add_action() {}

require dirname(__DIR__) . '/inc/GeneralThemeOptions/Controller.php';

function assert_true($cond, string $message): void
{
    if (!$cond) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

assert_true(method_exists(\SFX\GeneralThemeOptions\Controller::class, 'remove_bricks_styles'), 'remove_bricks_styles() exists');

\SFX\GeneralThemeOptions\Controller::remove_bricks_styles();
$calls = $GLOBALS['calls'];

$dereg = null;
$reg = null;
foreach ($calls as $i => $c) {
    if ($c === ['deregister', 'bricks-frontend']) { $dereg = $i; }
    if ($c === ['register', 'bricks-frontend', false]) { $reg = $i; }
}
assert_true($dereg !== null, 'bricks-frontend is deregistered');
assert_true($reg !== null, "wp_register_style('bricks-frontend', false) is called");
assert_true($reg > $dereg, 're-register happens after the deregister');

$GLOBALS['calls'] = [];
$GLOBALS['opts'] = ['sfx_general_options' => []];
\SFX\GeneralThemeOptions\Controller::remove_bricks_styles();
assert_true($GLOBALS['calls'] === [], 'option off: no calls at all');

echo "general-theme-options-disable-bricks-css-test: PASS\n";
