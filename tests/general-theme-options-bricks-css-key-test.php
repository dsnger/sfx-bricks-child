<?php

declare(strict_types=1);

/**
 * The "Disable Bricks Styling" checkbox must save under the key the controller reads.
 * They drifted apart once (field `disable_bricks_styles`, controller `disable_bricks_css`),
 * which made the toggle inert.
 */

$settings = (string) file_get_contents(dirname(__DIR__) . '/inc/GeneralThemeOptions/Settings.php');
$controller = (string) file_get_contents(dirname(__DIR__) . '/inc/GeneralThemeOptions/Controller.php');

function assert_true($cond, string $message): void
{
    if (!$cond) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

assert_true(preg_match("/is_option_enabled\\('disable_bricks_css'\\)/", $controller) === 1, 'controller reads disable_bricks_css');
assert_true(preg_match("/\\\$options\\['disable_bricks_css'\\]/", $controller) === 1, 'controller checks $options[disable_bricks_css]');
assert_true(preg_match("/'id'\\s*=>\\s*'disable_bricks_css'/", $settings) === 1, 'settings field id is disable_bricks_css');
assert_true(strpos($settings, "'disable_bricks_styles'") === false, 'old field id gone');

echo "general-theme-options-bricks-css-key-test: PASS\n";
