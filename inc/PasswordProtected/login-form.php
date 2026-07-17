<?php

/**
 * Frontend password prompt, styled as wp-login.php and compatible with the
 * WordPress / password-protected login hook + DOM contract.
 *
 * Deliberately does NOT call wp_head(). That would drag the whole frontend head
 * pipeline onto this page — theme CSS, Bricks assets, analytics, canonical and
 * feed links, every plugin's callbacks — which is both the opposite of the
 * wp-login.php look and a way to leak the protected site's metadata to an
 * unauthenticated visitor.
 *
 * Because there is no wp_head(), the template runs the login enqueue hooks and
 * then prints the enqueued styles itself (wp_print_styles). Enqueuing alone is
 * not enough here: nothing else flushes the queue, so a bare wp_enqueue_style()
 * on this screen would never emit a <link>. See sfx_pp_render_login_page().
 *
 * A template, not a class: no namespace here on purpose.
 * Override by placing sfx-password-protected-login.php in the theme root.
 */

declare(strict_types=1);

use SFX\PasswordProtected\Controller;
use SFX\PasswordProtected\Settings;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sfx_pp_render_login_page')) {
    /**
     * Echo the full login document. Does not exit — the auto-run tail below does,
     * so the function stays callable (and testable) without killing the process.
     */
    function sfx_pp_render_login_page(): void
    {
        $sfx_pp_settings = Settings::get();
        $sfx_pp_errors = Controller::$errors instanceof \WP_Error ? Controller::$errors : new \WP_Error();
        $sfx_pp_redirect = Settings::request_string($_REQUEST, 'redirect_to');

        // Logo link + text go through the standard WordPress login filters so
        // existing login snippets (custom logo URL / title) apply here too.
        $sfx_pp_header_url = apply_filters('login_headerurl', home_url('/'));
        $sfx_pp_header_text = apply_filters('login_headertext', get_bloginfo('name', 'display'));

        nocache_headers();
        header('Content-Type: ' . get_bloginfo('html_type') . '; charset=' . get_bloginfo('charset'));

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html(get_bloginfo('name', 'display')); ?></title>
    <?php
    // The WP Optimizer feature deregisters 'dashicons' on the frontend for
    // logged-out visitors. The 'login' style hard-depends on it, so once it is
    // gone the dependency graph excludes 'login' too and the page renders
    // unstyled. Put it back so 'login' resolves.
    if (!wp_styles()->query('dashicons')) {
        $sfx_pp_suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
        wp_register_style('dashicons', includes_url("css/dashicons$sfx_pp_suffix.css"), [], get_bloginfo('version'));
    }

    // Snapshot what other code has already enqueued so we print ONLY the login
    // styles and whatever the login hooks add — never a pre-existing frontend or
    // protected asset that some plugin queued on init/wp before this point.
    $sfx_pp_queued_before = wp_styles()->queue;

    // Enqueue core login CSS (pulls dashicons/buttons/forms as dependencies),
    // then let snippets and plugins register + enqueue their own login styles.
    // login_enqueue_scripts is the hook the client gate snippet uses.
    wp_enqueue_style('login');
    do_action('login_enqueue_scripts');

    // Emit core login CSS and its deps plus every style the login hooks added —
    // including their wp_add_inline_style() CSS — in dependency order, once.
    // Passing an explicit handle list (rather than a bare wp_print_styles()) is
    // deliberate: it prints only these handles, skips the global wp_print_styles
    // action, and so never flushes the wider frontend queue onto the gate screen.
    //
    // Boundary: only styles enqueued *within* the login hooks are printed. A handle
    // some plugin already queued on init/wp is treated as a frontend asset and left
    // out — the login snippet contract enqueues on login_enqueue_scripts, which runs
    // after this snapshot, so it is captured. Erring toward "don't leak" is the point.
    $sfx_pp_login_handles = array_values(array_diff(wp_styles()->queue, $sfx_pp_queued_before));
    if (!in_array('login', $sfx_pp_login_handles, true)) {
        array_unshift($sfx_pp_login_handles, 'login');
    }
    wp_print_styles($sfx_pp_login_handles);

    // Head hook fires AFTER the stylesheets, matching its conventional use in the
    // original plugin: callbacks here echo head markup (meta, site icon, inline
    // overrides) and any override <style> must come after the login CSS to win.
    do_action('password_protected_login_head');

    if (function_exists('wp_site_icon')) {
        wp_site_icon();
    }
    ?>
</head>
<?php // The login CSS is written against these classes; a bare #login does not get the look. ?>
<body class="login wp-core-ui">
<div id="login">
    <h1 role="presentation">
        <a href="<?php echo esc_url($sfx_pp_header_url); ?>">
            <?php echo esc_html($sfx_pp_header_text); ?>
        </a>
    </h1>

    <?php if ($sfx_pp_errors->has_errors()) : ?>
        <div id="login_error" role="alert">
            <?php foreach ($sfx_pp_errors->get_error_messages() as $sfx_pp_message) : ?>
                <p><?php echo esc_html($sfx_pp_message); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php do_action('password_protected_before_login_form'); ?>

    <form name="loginform" id="loginform" action="<?php echo esc_url(Controller::login_url()); ?>" method="post">
        <p>
            <label for="sfx_pp_pwd"><?php esc_html_e('Password', 'sfxtheme'); ?></label>
            <input type="password"
                   name="sfx_pp_pwd"
                   id="sfx_pp_pwd"
                   class="input"
                   value=""
                   size="20"
                   autocomplete="current-password"
                   autofocus />
        </p>

        <?php if ($sfx_pp_settings['allow_remember_me']) : ?>
            <p class="forgetmenot">
                <input name="sfx_pp_rememberme" type="checkbox" id="sfx_pp_rememberme" value="1" />
                <label for="sfx_pp_rememberme"><?php esc_html_e('Stay logged in', 'sfxtheme'); ?></label>
            </p>
        <?php endif; ?>

        <p class="submit">
            <button type="submit" name="sfx_pp_submit" id="wp-submit" class="button button-primary button-large">
                <?php esc_html_e('Enter', 'sfxtheme'); ?>
            </button>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($sfx_pp_redirect); ?>" />
            <?php wp_nonce_field('sfx_pp_login'); ?>
        </p>
    </form>

    <?php do_action('password_protected_after_login_form'); ?>
</div>
</body>
</html>
        <?php
    }
}

// Auto-run when loaded as the login template via Controller::maybe_show_login().
// A test can require this file for the function alone by defining the guard.
if (!defined('SFX_PP_LOGIN_FORM_TEST')) {
    sfx_pp_render_login_page();
    exit;
}
