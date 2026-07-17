<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Three predicates, split by what each one depends on:
 *
 *   is_protection_enabled()  one option        callable anywhere, incl. init
 *   is_visitor_exempt()      options + user    callable after init
 *   is_active()              + query state     callable only after `wp`
 *
 * Collapsing them into one predicate is what an earlier draft did, and it was
 * wrong twice: it let a per-visitor exemption disable cache protection, and it
 * called is_feed() before the query existed. See the spec.
 */
class Controller
{
    /**
     * Populated at `init`, read by login-form.php at `template_redirect`.
     * Same request, so a static property is enough: no transient, no session.
     */
    public static ?\WP_Error $errors = null;

    public function __construct()
    {
        self::$errors = new \WP_Error();

        AdminPage::register();

        // Priority 1, ahead of the handlers at 2: a login or bypass response
        // exits before the rest of the request runs, and must still have
        // DONOTCACHEPAGE defined.
        add_action('init', [self::class, 'disable_caching'], 1);
        // Ungated on purpose: a visitor must be able to clear a stale cookie
        // even while protection is switched off.
        add_action('init', [self::class, 'maybe_process_logout'], 1);
        add_action('init', [self::class, 'maybe_process_bypass'], 2);
        add_action('init', [self::class, 'maybe_process_login'], 2);

        add_action('template_redirect', [self::class, 'maybe_show_login'], -10);
        add_action('wp', [self::class, 'maybe_disable_feeds'], 10);
        add_filter('rest_authentication_errors', [self::class, 'filter_rest_access'], 10);

        add_action('admin_post_sfx_pp_save', [Settings::class, 'save_from_request'], 10);
        add_action('admin_notices', [self::class, 'maybe_render_broken_state_notice'], 10);
    }

    /**
     * `status` and nothing else.
     *
     * It deliberately does NOT also require a non-empty hash. Requiring one
     * inverts the failure mode: a corrupted status=on/password='' would make
     * this false, switching protection OFF and serving the site to the world.
     * The broken state is handled by is_configuration_broken() + behaviour.
     */
    public static function is_protection_enabled(): bool
    {
        return Settings::get()['status'];
    }

    /**
     * Status on with a normalized-empty hash. Settings::get() forces every
     * non-string to '', so this one comparison catches null, arrays and a
     * missing key alike.
     *
     * It does not judge whether a non-empty string is a "valid" hash: sniffing
     * for $P$/$wp$/$2y$ would couple this to core's hashing internals. A
     * garbage non-empty hash fails closed silently — wp_check_password() never
     * matches it — it just gets no notice.
     */
    public static function is_configuration_broken(): bool
    {
        $settings = Settings::get();

        return $settings['status'] && $settings['password'] === '';
    }

    public static function is_visitor_exempt(): bool
    {
        $settings = Settings::get();

        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        if ($ip !== '' && in_array($ip, Settings::allowed_ips(), true)) {
            return true;
        }

        if ($settings['allow_admins'] && current_user_can('manage_options')) {
            return true;
        }

        if ($settings['allow_users'] && is_user_logged_in()) {
            return true;
        }

        return false;
    }

    /**
     * Must not be called before `wp`: is_feed() and is_robots() are only
     * reliable once the main query is parsed. REST must not call this at all —
     * rest_authentication_errors fires at parse_request, before the query.
     */
    public static function is_active(): bool
    {
        $active = self::is_protection_enabled() && !self::is_visitor_exempt();

        if ($active && is_robots()) {
            $active = false;
        }

        if ($active && Settings::get()['allow_feeds'] && is_feed()) {
            $active = false;
        }

        return (bool) apply_filters('sfx_pp_is_active', $active);
    }

    /**
     * @param mixed $submitted Raw request value; may be anything.
     */
    public static function bypass_key_matches($submitted): bool
    {
        $stored = Settings::get()['bypass_key'];

        // The empty-stored guard is not padding: without it an empty stored key
        // plus ?sfx_bypass= compares '' to '' and lets the world in.
        if ($stored === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /**
     * Keyed off is_protection_enabled(), NOT is_active(). If a per-visitor
     * exemption could switch this off, an anonymous visitor from an allowlisted
     * IP would get an exempt AND cacheable response, and a URL-keyed page cache
     * would then serve that protected page to everyone.
     */
    public static function disable_caching(): void
    {
        if (is_admin() || !self::is_protection_enabled()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    public static function maybe_process_logout(): void
    {
        if (Settings::request_string($_REQUEST, 'sfx-protected') !== 'logout') {
            return;
        }

        if (!wp_verify_nonce(Settings::request_string($_REQUEST, '_wpnonce'), 'sfx_pp_logout')) {
            return;
        }

        Auth::clear_cookie();

        self::redirect_to(Settings::request_string($_REQUEST, 'redirect_to'));
    }

    public static function maybe_process_login(): void
    {
        if (!self::is_protection_enabled() || !Settings::has_string($_POST, 'sfx_pp_pwd')) {
            return;
        }

        // Nonce first, and it is a hard stop. Extracted as a scalar before
        // wp_verify_nonce(), which would string-cast an array and warn.
        if (!wp_verify_nonce(Settings::request_string($_POST, '_wpnonce'), 'sfx_pp_login')) {
            self::$errors->add('expired_nonce', __('Your session expired. Please try again.', 'sfxtheme'));

            return;
        }

        if (!wp_check_password(Settings::request_string($_POST, 'sfx_pp_pwd'), Settings::get_password_hash())) {
            Auth::clear_cookie();
            self::$errors->add('incorrect_password', __('Incorrect password.', 'sfxtheme'));

            return;
        }

        $remember = Settings::get()['allow_remember_me'] && Settings::post_bool($_POST, 'sfx_pp_rememberme');

        nocache_headers();
        Auth::set_cookie($remember);

        self::redirect_to(Settings::request_string($_POST, 'redirect_to'));
    }

    public static function maybe_process_bypass(): void
    {
        if (!self::is_protection_enabled() || !Settings::get()['bypass_enabled']) {
            return;
        }

        $submitted = Settings::request_string($_GET, 'sfx_bypass');

        if ($submitted === '' || !self::bypass_key_matches($submitted)) {
            return; // Silently continue to the normal login gate.
        }

        // The bypass is a GET carrying a Set-Cookie. Without this an
        // intermediary may cache a response holding a working auth cookie and
        // keep handing out access after the key is rotated.
        nocache_headers();
        Auth::set_cookie(false);

        self::redirect_to(Settings::get()['bypass_redirect']);
    }

    public static function maybe_show_login(): void
    {
        if (!self::is_active() || Auth::validate_cookie()) {
            return;
        }

        // A gated feed is maybe_disable_feeds()'s job, and it already hooked
        // do_feed at `wp`. Without this early return, template_redirect (-10)
        // fires first and 302s the feed reader to an HTML login form — the
        // do_feed callback would never run and its message would be dead code.
        if (is_feed()) {
            return;
        }

        if (Settings::request_string($_REQUEST, 'sfx-protected') === 'login') {
            // load_template() performs no discovery of its own.
            $file = locate_template(['sfx-password-protected-login.php']);
            if (!$file) {
                $file = __DIR__ . '/login-form.php';
            }

            $file = apply_filters('sfx_pp_login_template', $file);
            if (!is_string($file) || !file_exists($file)) {
                $file = __DIR__ . '/login-form.php';
            }

            load_template($file);
            exit;
        }

        nocache_headers();
        self::redirect_to(self::login_url(self::current_url()));
    }

    public static function maybe_disable_feeds(): void
    {
        if (!self::is_active()) {
            return;
        }

        foreach (['do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom'] as $hook) {
            add_action($hook, [self::class, 'disable_feed'], 1);
        }
    }

    public static function disable_feed(): void
    {
        wp_die(
            sprintf(
                /* translators: %s: a link to the website */
                esc_html__('Feeds are not available for this site. Please visit the %s.', 'sfxtheme'),
                '<a href="' . esc_url(home_url('/')) . '">' . esc_html__('website', 'sfxtheme') . '</a>'
            )
        );
    }

    /**
     * @param mixed $access
     * @return mixed
     */
    public static function filter_rest_access($access)
    {
        // Never mask another plugin's more specific authentication failure.
        if (is_wp_error($access)) {
            return $access;
        }

        // Deliberately NOT is_active(): this fires at parse_request, before the
        // main query, so is_feed()/is_robots() are meaningless here.
        if (!self::is_protection_enabled() || self::is_visitor_exempt()) {
            return $access;
        }

        // For the block editor, which runs in wp-admin and calls /wp-json/.
        if (is_user_logged_in() && (current_user_can('edit_posts') || current_user_can('edit_pages'))) {
            return $access;
        }

        if (Auth::validate_cookie()) {
            return $access;
        }

        if (Settings::get()['allow_rest']) {
            return $access;
        }

        return new \WP_Error(
            'rest_cannot_access',
            __('Only authenticated users can access the REST API.', 'sfxtheme'),
            ['status' => rest_authorization_required_code()]
        );
    }

    public static function maybe_render_broken_state_notice(): void
    {
        if (!self::is_configuration_broken()) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Password Protection is switched on but has no password.', 'sfxtheme'),
            esc_html__('The site is showing the login screen and no password will open it. Set a password on the Password Protection settings page to restore access.', 'sfxtheme')
        );
    }

    public static function login_url(string $redirect_to = ''): string
    {
        $url = add_query_arg('sfx-protected', 'login', home_url('/'));

        if ($redirect_to !== '') {
            // The urlencode() is required, not redundant. add_query_arg() does
            // NOT encode the value you hand it: its urlencode_deep() runs over
            // the pre-existing query string, before `$qs[$args[0]] = $args[1]`,
            // and build_query() calls _http_build_query() with $urlencode=false.
            // Drop it and the target's own ? and & land raw in the query string
            // and tear it apart. (Verified against wp-includes/functions.php.)
            $url = add_query_arg('redirect_to', urlencode($redirect_to), $url);
        }

        return $url;
    }

    /**
     * The one redirect path in this module.
     *
     * wp_safe_redirect() alone is not enough: its fallback for a rejected host
     * is admin_url(), which would bounce a protected visitor into wp-admin.
     * $target is often attacker-controllable — this is a security boundary.
     */
    public static function redirect_to(string $target): void
    {
        // An empty target must become home BEFORE validation, not via the
        // fallback argument. wp_validate_redirect('') does not fall back: ''
        // parses as a relative path, so it is returned unchanged, and
        // wp_redirect('') then bails on `if ( ! $location )` without sending
        // a header — leaving the exit below to serve a blank page. That is the
        // DEFAULT bypass configuration (empty "Redirect To"), not a corner case.
        //
        // trim() first, because core trims too: "   " would slip past a bare
        // === '' check and reach wp_redirect() as '' anyway, same blank page.
        $target = trim($target);
        if ($target === '') {
            $target = home_url('/');
        }

        wp_safe_redirect(wp_validate_redirect($target, home_url('/')));
        exit;
    }

    private static function current_url(): string
    {
        $host = Settings::request_string($_SERVER, 'HTTP_HOST');
        $uri  = Settings::request_string($_SERVER, 'REQUEST_URI');

        if ($host === '' || $uri === '') {
            return home_url('/');
        }

        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_password_protected',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing PasswordProtected Controller class in theme',
        ];
    }
}
