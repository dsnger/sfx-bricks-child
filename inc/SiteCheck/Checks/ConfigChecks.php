<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * Checks of settings and constants: debug_display, allow_url_include, the
 * server half of https, php_version, file_editor, auto_updates,
 * search_visibility, admin_email, permalinks, table_prefix. Each finding's
 * target is the setting name.
 */
final class ConfigChecks
{
    /** Risky-period before the end of security support (php_version). */
    private const PHP_WARN_MONTHS = 6;

    public static function observe(string $id, RunContext $ctx): array
    {
        switch ($id) {
            case 'debug_display':
                return [
                    'wp_debug'              => defined('WP_DEBUG') && WP_DEBUG,
                    // wp_initial_constants() defines it as true when wp-config.php does not.
                    'wp_debug_display'      => defined('WP_DEBUG_DISPLAY') ? self::scalar(WP_DEBUG_DISPLAY) : true,
                    'wp_debug_log'          => defined('WP_DEBUG_LOG') ? self::scalar(WP_DEBUG_LOG) : false,
                    'display_errors_master' => self::display_errors_master(),
                ];
            case 'allow_url_include':
                return ['value' => (string) ini_get('allow_url_include')];
            case 'https':
                return ['home' => (string) get_option('home'), 'siteurl' => (string) get_option('siteurl')];
            case 'php_version':
                return ['version' => PHP_VERSION, 'today' => gmdate('Y-m-d')];
            case 'file_editor':
                return [
                    'disallow_file_edit' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
                    'disallow_file_mods' => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
                ];
            case 'auto_updates':
                return self::observe_auto_updates();
            case 'search_visibility':
                return ['blog_public' => (string) get_option('blog_public')];
            case 'admin_email':
                return ['email' => (string) get_option('admin_email'), 'pending' => (string) get_option('new_admin_email')];
            case 'permalinks':
                return ['structure' => (string) get_option('permalink_structure')];
            case 'table_prefix':
                return ['prefix' => (string) $GLOBALS['wpdb']->base_prefix];
        }
        throw new \InvalidArgumentException('Not a configuration check: ' . $id);
    }

    public static function grade(string $id, array $obs, RunContext $ctx): array
    {
        switch ($id) {
            case 'debug_display':
                return self::grade_debug_display($obs);
            case 'allow_url_include':
                $on = self::ini_on((string) ($obs['value'] ?? ''));
                return self::one($id, 'allow_url_include', $on ? Status::RED : Status::GREEN, $on
                    ? __('allow_url_include is on — dangerous configuration', 'sfxtheme')
                    : __('allow_url_include is off', 'sfxtheme'));
            case 'https':
                return self::grade_https($obs);
            case 'php_version':
                return self::grade_php_version($obs);
            case 'file_editor':
                $blocked = !empty($obs['disallow_file_edit']) || !empty($obs['disallow_file_mods']);
                return self::one($id, 'DISALLOW_FILE_EDIT', $blocked ? Status::GREEN : Status::YELLOW, $blocked
                    ? __('The file editor in wp-admin is blocked.', 'sfxtheme')
                    : __('The file editor in wp-admin is available to administrators.', 'sfxtheme'));
            case 'auto_updates':
                $on = !empty($obs['minor_enabled']);
                $off = ($obs['reason'] ?? '') === 'vcs'
                    ? __('Automatic updates of WordPress are off: this installation is a version control checkout, which WordPress never updates itself — fine only if an external update process covers them.', 'sfxtheme')
                    : __('Automatic minor and security updates of WordPress are off — fine only if an external update process covers them.', 'sfxtheme');
                return self::one($id, 'auto_update_core_minor', $on ? Status::GREEN : Status::YELLOW, $on
                    ? __('Automatic minor and security updates of WordPress are on.', 'sfxtheme')
                    : $off,
                    __('Seen: the updater switches, WP_AUTO_UPDATE_CORE, the auto-update option and the allow_minor_auto_core_updates filter. Per-update filters are not evaluated.', 'sfxtheme'));
            case 'search_visibility':
                return self::grade_search_visibility($obs, $ctx);
            case 'admin_email':
                $label = sprintf(
                    /* translators: %s: e-mail address */
                    __('%s — is this the right address? Warning e-mails go to it.', 'sfxtheme'),
                    (string) ($obs['email'] ?? '')
                );
                if (($obs['pending'] ?? '') !== '' && $obs['pending'] !== ($obs['email'] ?? '')) {
                    /* translators: %s: e-mail address */
                    $label .= ' ' . sprintf(__('A change to %s is waiting for confirmation.', 'sfxtheme'), (string) $obs['pending']);
                }
                return self::one($id, 'admin_email', Status::HINT, $label);
            case 'permalinks':
                $plain = ($obs['structure'] ?? '') === '';
                return self::one($id, 'permalink_structure', $plain ? Status::YELLOW : Status::GREEN, $plain
                    ? __('Plain permalinks (?p=123). Choose a structure before launch; changing it later can break existing links.', 'sfxtheme')
                    : __('A permalink structure is set.', 'sfxtheme'));
            case 'table_prefix':
                $default = ($obs['prefix'] ?? '') === 'wp_';
                return self::one($id, 'table_prefix', Status::HINT, $default
                    ? __('The database uses the default table prefix wp_.', 'sfxtheme')
                    : __('The database uses a custom table prefix.', 'sfxtheme'));
        }
        throw new \InvalidArgumentException('Not a configuration check: ' . $id);
    }

    /**
     * Follows wp_debug_mode() (wp-includes/load.php): WordPress decides when
     * WP_DEBUG is truthy and WP_DEBUG_DISPLAY is not null; PHP's master value
     * decides otherwise. Never Grün — configuration is not proof.
     */
    private static function grade_debug_display(array $obs): array
    {
        $note = __('According to the configuration. The enable_wp_debug_mode_checks filter and changes plugins make at runtime are not seen; no error is triggered on purpose.', 'sfxtheme');
        $display = $obs['wp_debug_display'] ?? null;

        if (!empty($obs['wp_debug']) && $display !== null) {
            if ($display) {
                return self::one('debug_display', 'display_errors', Status::RED, __('WP_DEBUG and WP_DEBUG_DISPLAY are on: errors are shown to visitors.', 'sfxtheme'), $note);
            }
            return self::one('debug_display', 'display_errors', Status::YELLOW, __('Debug mode is on; errors are not shown to visitors.', 'sfxtheme'), $note);
        }

        $master = $obs['display_errors_master'] ?? null;
        if ($master === null) {
            return self::one('debug_display', 'display_errors', Status::UNKNOWN, __('PHP\'s display_errors setting could not be read.', 'sfxtheme'), $note);
        }
        if ($master) {
            return self::one('debug_display', 'display_errors', Status::RED, __('PHP\'s display_errors is on: errors are shown to visitors.', 'sfxtheme'), $note);
        }
        return self::one('debug_display', 'display_errors', Status::HINT, __('Off according to PHP\'s base setting — a folder setting (.user.ini, .htaccess) is not visible to the check.', 'sfxtheme'), $note);
    }

    private static function grade_https(array $obs): array
    {
        $findings = [];
        foreach (['home' => __('Site address (home)', 'sfxtheme'), 'siteurl' => __('WordPress address (siteurl)', 'sfxtheme')] as $key => $name) {
            $url = (string) ($obs[$key] ?? '');
            $https = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'https';
            $findings[] = ServerChecks::finding('https', $key, $https ? Status::GREEN : Status::RED, $name . ': ' . ($https
                ? __('uses https://', 'sfxtheme')
                : __('does not use https://', 'sfxtheme')));
        }

        // The outside half (OutsideChecks, Loopback). Missing → not measured, never Grün.
        $tls = $obs['tls'] ?? null;
        if ($tls === 'ok') {
            $findings[] = ServerChecks::finding('https', 'tls', Status::GREEN, __('TLS: the site answers over https:// with a valid certificate', 'sfxtheme'));
        } elseif ($tls === 'fail') {
            $findings[] = ServerChecks::finding('https', 'tls', Status::RED, __('TLS: the https:// connection fails (certificate or handshake)', 'sfxtheme'));
        } elseif ($tls === 'skipped') {
            $findings[] = ServerChecks::finding('https', 'tls', Status::UNKNOWN, __('TLS: not measured, the site address does not use https://', 'sfxtheme'));
        } else {
            $findings[] = ServerChecks::finding('https', 'tls', Status::UNKNOWN, __('TLS: not measured from outside', 'sfxtheme'));
        }
        $redirect = $obs['http_redirect'] ?? null;
        if ($redirect === 'ok') {
            $findings[] = ServerChecks::finding('https', 'http_redirect', Status::GREEN, __('http:// redirects to https://', 'sfxtheme'));
        } elseif ($redirect === 'fail') {
            $findings[] = ServerChecks::finding('https', 'http_redirect', Status::RED, __('http:// does not redirect to https://', 'sfxtheme'));
        } else {
            $findings[] = ServerChecks::finding('https', 'http_redirect', Status::UNKNOWN, __('http:// redirect: not measured from outside', 'sfxtheme'));
        }

        return ServerChecks::result($findings, Status::GREEN, __('Addresses: Server. TLS and the http:// redirect: Loopback, the server fetching its own address without login.', 'sfxtheme'), 'loopback');
    }

    private static function grade_php_version(array $obs): array
    {
        $table = require dirname(__DIR__) . '/Data/php-support.php';
        $note = sprintf(
            /* translators: 1: source URL, 2: date */
            __('Source: %1$s, checked %2$s. Host backports are not visible here.', 'sfxtheme'),
            $table['source'],
            self::calendar_date($table['checked'])
        );
        $version = (string) ($obs['version'] ?? '');
        $branch = preg_match('/^(\d+\.\d+)/', $version, $m) === 1 ? $m[1] : '';
        $until = $table['security_until'][$branch] ?? null;
        $today = (string) ($obs['today'] ?? '');

        if ($until === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            /* translators: %s: PHP version */
            return self::one('php_version', 'php_version', Status::UNKNOWN, sprintf(__('PHP %s is not in the support table.', 'sfxtheme'), $version), $note);
        }
        if ($today > $until) {
            /* translators: 1: PHP version, 2: date */
            return self::one('php_version', 'php_version', Status::RED, sprintf(__('PHP %1$s: upstream security support ended on %2$s (clarify host backports separately).', 'sfxtheme'), $version, self::calendar_date($until)), $note);
        }
        $warn_from = gmdate('Y-m-d', (int) strtotime($until . ' -' . self::PHP_WARN_MONTHS . ' months'));
        if ($today >= $warn_from) {
            /* translators: 1: PHP version, 2: date */
            return self::one('php_version', 'php_version', Status::YELLOW, sprintf(__('PHP %1$s: security support ends on %2$s.', 'sfxtheme'), $version, self::calendar_date($until)), $note);
        }
        /* translators: 1: PHP version, 2: date */
        return self::one('php_version', 'php_version', Status::GREEN, sprintf(__('PHP %1$s receives security support until %2$s.', 'sfxtheme'), $version, self::calendar_date($until)), $note);
    }

    /** A calendar date (Y-m-d) in the site's date format, without a time-zone shift. */
    private static function calendar_date(string $date): string
    {
        $timestamp = strtotime($date . ' 00:00:00 UTC');

        return $timestamp === false ? $date : (string) wp_date(get_option('date_format'), $timestamp, new \DateTimeZone('UTC'));
    }

    private static function grade_search_visibility(array $obs, RunContext $ctx): array
    {
        $discouraged = ($obs['blog_public'] ?? '1') === '0';
        if ($ctx->profile() === 'live') {
            return self::one('search_visibility', 'blog_public', $discouraged ? Status::RED : Status::GREEN, $discouraged
                ? __('"Discourage search engines" is on — the live site asks search engines to stay away.', 'sfxtheme')
                : __('Search engines are not discouraged.', 'sfxtheme'));
        }
        return self::one('search_visibility', 'blog_public', $discouraged ? Status::GREEN : Status::YELLOW, $discouraged
            ? __('"Discourage search engines" is on, as expected for this profile.', 'sfxtheme')
            : __('The site can end up in search engines.', 'sfxtheme'));
    }

    /**
     * Mirrors Core_Upgrader::should_update_to_version() for minor releases and
     * WP_Automatic_Updater::is_disabled(). Facts only: the effective switch
     * and which layer turned it off.
     */
    private static function observe_auto_updates(): array
    {
        $file_mods = function_exists('wp_is_file_mod_allowed')
            ? wp_is_file_mod_allowed('automatic_updater')
            : !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);
        if (!$file_mods) {
            return ['minor_enabled' => false, 'reason' => 'file_mods'];
        }
        $disabled = defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED;
        if (apply_filters('automatic_updater_disabled', $disabled)) {
            return ['minor_enabled' => false, 'reason' => $disabled ? 'constant' : 'filter'];
        }
        // WordPress refuses to update a version-control checkout (WP_Automatic_Updater::should_update()).
        if (!class_exists('WP_Automatic_Updater') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }
        if (class_exists('WP_Automatic_Updater') && (new \WP_Automatic_Updater())->is_vcs_checkout(ABSPATH)) {
            return ['minor_enabled' => false, 'reason' => 'vcs'];
        }

        $minor = get_site_option('auto_update_core_minor', 'enabled') === 'enabled';
        $reason = $minor ? '' : 'option';
        if (defined('WP_AUTO_UPDATE_CORE')) {
            if (WP_AUTO_UPDATE_CORE === false) {
                $minor = false;
                $reason = 'constant';
            } elseif (WP_AUTO_UPDATE_CORE === true || in_array(WP_AUTO_UPDATE_CORE, ['beta', 'rc', 'development', 'branch-development', 'minor'], true)) {
                $minor = true;
                $reason = '';
            }
        }
        $filtered = (bool) apply_filters('allow_minor_auto_core_updates', $minor);
        if ($filtered !== $minor) {
            $reason = $filtered ? '' : 'filter';
        }
        return ['minor_enabled' => $filtered, 'reason' => $reason];
    }

    /** PHP's master value of display_errors, or null when it cannot be read. */
    private static function display_errors_master(): ?bool
    {
        if (!function_exists('ini_get_all')) {
            return null;
        }
        $all = @ini_get_all(null, true);
        if (!is_array($all) || !isset($all['display_errors']['global_value'])) {
            return null;
        }
        return self::display_errors_on((string) $all['display_errors']['global_value']);
    }

    /** display_errors shows errors to visitors only for on-values and `stdout`; `stderr` does not reach them. */
    public static function display_errors_on(string $value): bool
    {
        $value = strtolower(trim($value));
        return $value !== 'stderr' && ($value === 'stdout' || self::ini_on($value));
    }

    private static function ini_on(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'on', 'true', 'yes'], true);
    }

    /** @param mixed $value @return bool|int|float|string|null */
    private static function scalar($value)
    {
        return is_scalar($value) || $value === null ? $value : (bool) $value;
    }

    private static function one(string $check, string $target, string $status, string $label, string $note = ''): array
    {
        return ServerChecks::result([ServerChecks::finding($check, $target, $status, $label)], $status, $note);
    }
}
