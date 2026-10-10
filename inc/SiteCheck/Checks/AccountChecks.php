<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * Checks of accounts and grants: registration, admin_accounts (plan 1: list
 * and login grading only, no baseline), bricks_permissions, app_passwords.
 *
 * Bricks grants are resolved per user the way Bricks 2.4.2 resolves them for
 * the current user (../bricks/includes/capabilities.php:229-264): an entry on
 * the user (`caps` keys) decides before the role (`allcaps` keys);
 * `<grant>_off` on the user denies even when the role grants.
 */
final class AccountChecks
{
    public const BRICKS_VERIFIED = '2.4.2';
    public const EXECUTE_CODE = 'bricks_execute_code';
    public const UPLOAD_SVG = 'bricks_upload_svg';

    /** A default role holding any of these makes open registration Rot. */
    private const RISKY_CAPS = ['edit_posts', 'upload_files', 'unfiltered_html', 'manage_options', self::EXECUTE_CODE, self::UPLOAD_SVG];
    private const READ_ONLY_CAPS = ['read', 'level_0'];
    private const GUESSABLE_LOGINS = ['admin', 'administrator'];

    public static function observe(string $id, RunContext $ctx): array
    {
        switch ($id) {
            case 'registration':
                $role_name = (string) get_option('default_role');
                $role = get_role($role_name);
                return [
                    'open' => (bool) get_option('users_can_register'),
                    'role' => $role_name,
                    'caps' => $role === null ? null : self::role_caps((array) $role->capabilities),
                ];
            case 'admin_accounts':
                return self::observe_admin_accounts();
            case 'bricks_permissions':
                return self::observe_bricks_permissions();
            case 'app_passwords':
                return self::observe_app_passwords();
        }
        throw new \InvalidArgumentException('Not an account check: ' . $id);
    }

    /**
     * Capabilities a role grants: WordPress capabilities by their value, the
     * two Bricks grants by key presence (as Bricks 2.4.2 resolves them,
     * ../bricks/includes/capabilities.php).
     *
     * @return list<string>
     */
    private static function role_caps(array $caps): array
    {
        $out = [];
        foreach ($caps as $cap => $granted) {
            if (in_array($cap, [self::EXECUTE_CODE, self::UPLOAD_SVG], true) || $granted) {
                $out[] = (string) $cap;
            }
        }

        return $out;
    }

    public static function grade(string $id, array $obs, RunContext $ctx): array
    {
        switch ($id) {
            case 'registration':
                return self::grade_registration($obs);
            case 'admin_accounts':
                return self::grade_admin_accounts($obs);
            case 'bricks_permissions':
                return self::grade_bricks_permissions($obs);
            case 'app_passwords':
                return self::grade_app_passwords($obs);
        }
        throw new \InvalidArgumentException('Not an account check: ' . $id);
    }

    // ------------------------------------------------------------ registration

    private static function grade_registration(array $obs): array
    {
        $target = 'users_can_register';
        if (empty($obs['open'])) {
            return self::one('registration', $target, Status::GREEN, __('Registration is closed.', 'sfxtheme'));
        }
        if (!is_array($obs['caps'] ?? null)) {
            /* translators: %s: role slug */
            return self::one('registration', $target, Status::UNKNOWN, sprintf(__('Registration is open, but the default role "%s" does not exist.', 'sfxtheme'), (string) ($obs['role'] ?? '')));
        }
        $caps = $obs['caps'];
        $risky = array_values(array_intersect(self::RISKY_CAPS, $caps));
        if ($risky !== []) {
            return self::one('registration', $target, Status::RED, sprintf(
                /* translators: 1: role slug, 2: capability list */
                __('Registration is open and new users get the role "%1$s" with: %2$s', 'sfxtheme'),
                (string) $obs['role'],
                implode(', ', $risky)
            ));
        }
        $extra = array_values(array_diff($caps, self::READ_ONLY_CAPS));
        if ($extra === []) {
            /* translators: %s: role slug */
            return self::one('registration', $target, Status::HINT, sprintf(__('Registration is open with the read-only role "%s" — intended?', 'sfxtheme'), (string) $obs['role']));
        }
        return self::one('registration', $target, Status::YELLOW, sprintf(
            /* translators: 1: role slug, 2: capability list */
            __('Registration is open and new users get the role "%1$s" with: %2$s', 'sfxtheme'),
            (string) $obs['role'],
            implode(', ', $extra)
        ));
    }

    // ------------------------------------------------------------ admin_accounts

    private static function observe_admin_accounts(): array
    {
        $bricks = self::bricks_available();
        $users = [];
        foreach (self::candidate_users() as $user) {
            $grants = [];
            if ($user->has_cap('manage_options')) {
                $grants[] = 'manage_options';
            }
            if ($bricks && self::bricks_grant($user, self::EXECUTE_CODE)) {
                $grants[] = self::EXECUTE_CODE;
            }
            if ($grants !== []) {
                $users[] = ['id' => (int) $user->ID, 'login' => (string) $user->user_login, 'grants' => $grants];
            }
        }
        return ['bricks' => $bricks, 'users' => $users];
    }

    private static function grade_admin_accounts(array $obs): array
    {
        $findings = [];
        foreach ($obs['users'] ?? [] as $u) {
            $guessable = in_array(strtolower($u['login']), self::GUESSABLE_LOGINS, true);
            foreach ($u['grants'] as $grant) {
                /* translators: 1: login name, 2: user ID, 3: capability */
                $label = sprintf(__('%1$s (ID %2$d): %3$s', 'sfxtheme'), $u['login'], $u['id'], $grant);
                if ($guessable) {
                    $label .= ' — ' . __('guessable login name', 'sfxtheme');
                }
                $findings[] = ServerChecks::finding('admin_accounts', 'user-' . $u['id'] . ':' . $grant, $guessable ? Status::YELLOW : Status::HINT, $label);
            }
        }
        $note = empty($obs['bricks'])
            ? __('Accounts with manage_options. Bricks is not active, so code execution grants are not listed.', 'sfxtheme')
            : __('Accounts with manage_options or Bricks code execution.', 'sfxtheme');
        return ServerChecks::result($findings, Status::HINT, $note);
    }

    // ------------------------------------------------------------ bricks_permissions

    private static function observe_bricks_permissions(): array
    {
        if (!self::bricks_available()) {
            return ['available' => false, 'execution_enabled' => false, 'users' => []];
        }
        $users = [];
        foreach (self::candidate_users() as $user) {
            $execute = self::bricks_grant($user, self::EXECUTE_CODE);
            $svg = self::bricks_grant($user, self::UPLOAD_SVG);
            if ($execute || $svg) {
                $users[] = [
                    'id' => (int) $user->ID,
                    'login' => (string) $user->user_login,
                    'admin' => (bool) $user->has_cap('manage_options'),
                    'execute_code' => $execute,
                    'upload_svg' => $svg,
                ];
            }
        }
        return ['available' => true, 'execution_enabled' => (bool) \Bricks\Helpers::code_execution_enabled(), 'users' => $users];
    }

    private static function grade_bricks_permissions(array $obs): array
    {
        /* translators: %s: Bricks version */
        $note = sprintf(__('Grants resolved as Bricks %s does (user entry before role). Builder access is not checked. A later Bricks version may resolve them differently.', 'sfxtheme'), self::BRICKS_VERIFIED);
        if (empty($obs['available'])) {
            $f = ServerChecks::finding('bricks_permissions', 'bricks', Status::UNKNOWN, __('Bricks is not active or its helper is missing.', 'sfxtheme'));
            return ServerChecks::result([$f], Status::UNKNOWN, $note);
        }

        $enabled = !empty($obs['execution_enabled']);
        $findings = [ServerChecks::finding('bricks_permissions', 'executeCodeEnabled', $enabled ? Status::HINT : Status::GREEN, $enabled
            ? __('Code execution in Bricks is on.', 'sfxtheme')
            : __('Code execution in Bricks is off.', 'sfxtheme'))];

        foreach ($obs['users'] ?? [] as $u) {
            /* translators: 1: login name, 2: user ID */
            $who = sprintf(__('%1$s (ID %2$d)', 'sfxtheme'), $u['login'], $u['id']);
            if ($u['execute_code']) {
                $status = $u['admin'] ? Status::HINT : ($enabled ? Status::RED : Status::HINT);
                $label = $who . ': ' . __('may execute code', 'sfxtheme');
                if (!$u['admin']) {
                    $label .= ' — ' . ($enabled ? __('without being an administrator', 'sfxtheme') : __('applies as soon as code execution is turned on', 'sfxtheme'));
                }
                $findings[] = ServerChecks::finding('bricks_permissions', 'user-' . $u['id'] . ':' . self::EXECUTE_CODE, $status, $label);
            }
            if ($u['upload_svg']) {
                $findings[] = ServerChecks::finding('bricks_permissions', 'user-' . $u['id'] . ':' . self::UPLOAD_SVG, $u['admin'] ? Status::HINT : Status::YELLOW, $who . ': ' . __('may upload SVG files', 'sfxtheme'));
            }
        }
        return ServerChecks::result($findings, Status::HINT, $note);
    }

    // ------------------------------------------------------------ app_passwords

    private static function observe_app_passwords(): array
    {
        if (!class_exists('WP_Application_Passwords')) {
            return ['available' => false, 'passwords' => []];
        }
        $passwords = [];
        $users = get_users(['meta_key' => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, 'meta_compare' => 'EXISTS', 'fields' => 'all']);
        foreach ($users as $user) {
            foreach (\WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
                // The hash is never copied.
                $passwords[] = [
                    'user' => (int) $user->ID,
                    'login' => (string) $user->user_login,
                    'name' => (string) ($item['name'] ?? ''),
                    'uuid' => (string) ($item['uuid'] ?? ''),
                    'last_used' => isset($item['last_used']) ? (int) $item['last_used'] : null,
                ];
            }
        }
        $available = function_exists('wp_is_application_passwords_available') ? (bool) wp_is_application_passwords_available() : true;
        return ['available' => $available, 'passwords' => $passwords];
    }

    private static function grade_app_passwords(array $obs): array
    {
        $findings = [];
        foreach ($obs['passwords'] ?? [] as $p) {
            $used = $p['last_used'] === null ? __('never used', 'sfxtheme') : sprintf(
                /* translators: %s: date */
                __('last used %s', 'sfxtheme'),
                wp_date(get_option('date_format'), (int) $p['last_used'])
            );
            $findings[] = ServerChecks::finding('app_passwords', 'user-' . $p['user'] . ':' . $p['uuid'], Status::HINT, sprintf(
                /* translators: 1: login name, 2: user ID, 3: application password name, 4: last use */
                __('%1$s (ID %2$d): "%3$s", %4$s', 'sfxtheme'),
                $p['login'],
                $p['user'],
                $p['name'],
                $used
            ));
        }
        if ($findings === []) {
            $findings[] = ServerChecks::finding('app_passwords', 'application_passwords', Status::HINT, empty($obs['available'])
                ? __('Application passwords are not available on this site.', 'sfxtheme')
                : __('No application passwords exist.', 'sfxtheme'));
        }
        return ServerChecks::result($findings, Status::HINT);
    }

    // ------------------------------------------------------------ helpers

    private static function bricks_available(): bool
    {
        return class_exists('\Bricks\Helpers') && method_exists('\Bricks\Helpers', 'code_execution_enabled');
    }

    /** Mirrors Bricks: key presence on the user decides before key presence on the role. */
    private static function bricks_grant(object $user, string $grant): bool
    {
        $user_can = array_keys((array) $user->caps);
        if (in_array($grant, $user_can, true)) {
            return true;
        }
        if (in_array($grant . '_off', $user_can, true)) {
            return false;
        }
        return in_array($grant, array_keys((array) $user->allcaps), true);
    }

    /**
     * Users who can hold a privileged grant: members of a role that carries
     * one, users with a matching entry of their own, and super admins.
     *
     * @return list<object> WP_User
     */
    private static function candidate_users(): array
    {
        $grants = ['manage_options', self::EXECUTE_CODE, self::UPLOAD_SVG];
        $roles = [];
        foreach (wp_roles()->roles as $name => $role) {
            if (array_intersect($grants, array_keys((array) ($role['capabilities'] ?? []))) !== []) {
                $roles[] = $name;
            }
        }

        $found = [];
        if ($roles !== []) {
            foreach (get_users(['role__in' => $roles, 'fields' => 'all']) as $user) {
                $found[(int) $user->ID] = $user;
            }
        }
        $key = $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities';
        $meta_query = ['relation' => 'OR'];
        foreach ($grants as $grant) {
            $meta_query[] = ['key' => $key, 'value' => '"' . $grant, 'compare' => 'LIKE'];
        }
        foreach (get_users(['meta_query' => $meta_query, 'fields' => 'all']) as $user) {
            $found[(int) $user->ID] = $user;
        }
        if (is_multisite()) {
            foreach (get_super_admins() as $login) {
                $user = get_user_by('login', $login);
                if ($user) {
                    $found[(int) $user->ID] = $user;
                }
            }
        }
        ksort($found);
        return array_values($found);
    }

    private static function one(string $check, string $target, string $status, string $label): array
    {
        return ServerChecks::result([ServerChecks::finding($check, $target, $status, $label)], $status);
    }
}
