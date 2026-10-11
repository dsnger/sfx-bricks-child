<?php
/**
 * Pin SiteCheck\ThemeSwitches against WP Optimizer's real Settings (option
 * name, field defaults, the master pause) without the test seam: the switch
 * reads as on only with the module enabled, the optimizer not paused and the
 * switch on, its field default included. The theme bootstrap is a stub that
 * answers the module gate from a global.
 */

declare(strict_types=1);

namespace SFX {
    final class SFXBricksChildTheme
    {
        public static function is_general_option_enabled(string $key): bool
        {
            return $key === 'enable_wp_optimizer' && !empty($GLOBALS['module_on']);
        }
    }
}

namespace {
    $GLOBALS['options'] = [];
    function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
    function __($s, $d = null) { return $s; }
    function _x($s, $c, $d = null) { return $s; }
    function esc_html__($s, $d = null) { return $s; }
    function apply_filters($hook, $value, ...$args) { return $value; }

    require __DIR__ . '/../inc/WPOptimizer/Settings.php';
    require __DIR__ . '/../inc/SiteCheck/ThemeSwitches.php';

    use SFX\SiteCheck\ThemeSwitches;

    $fail = 0;
    $check = static function (bool $want, string $key, string $label) use (&$fail): void {
        if (ThemeSwitches::wp_optimizer($key) !== $want) {
            echo "FAIL: {$label}\n";
            $fail++;
        }
    };
    $opt = 'sfx_wpoptimizer_options';

    $GLOBALS['module_on'] = true;
    $check(true, 'remove_users_sitemap', 'a switch whose field default is on reads as on with nothing saved');
    $GLOBALS['options'][$opt] = ['remove_users_sitemap' => 0, 'disable_xmlrpc' => 1];
    $check(false, 'remove_users_sitemap', 'a switch saved off reads as off');
    $check(true, 'disable_xmlrpc', 'a switch saved on reads as on');
    $GLOBALS['options'][$opt]['disable_wp_optimizer'] = 1;
    $check(false, 'disable_xmlrpc', 'the optimizer paused: no switch is in force');
    unset($GLOBALS['options'][$opt]['disable_wp_optimizer']);
    $GLOBALS['module_on'] = false;
    $check(false, 'disable_xmlrpc', 'the module off: no switch is in force');

    echo $fail === 0 ? "site-check theme switches: ok\n" : '';
    exit($fail === 0 ? 0 : 1);
}
