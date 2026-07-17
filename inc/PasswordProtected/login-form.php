<?php

/**
 * Frontend password prompt, styled as wp-login.php.
 *
 * Deliberately does NOT call wp_head(). That would drag the whole frontend head
 * pipeline onto this page — theme CSS, Bricks assets, analytics, canonical and
 * feed links, every plugin's callbacks — which is both the opposite of the
 * wp-login.php look and a way to leak the protected site's metadata to an
 * unauthenticated visitor.
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

$sfx_pp_settings = Settings::get();
$sfx_pp_errors = Controller::$errors instanceof \WP_Error ? Controller::$errors : new \WP_Error();
$sfx_pp_redirect = Settings::request_string($_REQUEST, 'redirect_to');

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
    // The `true` is mandatory. Without $force_echo this only ENQUEUES, and
    // since this document calls neither wp_head() nor print_admin_styles(),
    // nothing would ever print: an unstyled login page. $force_echo prints the
    // handle and its dependencies (dashicons, buttons, forms, base styles).
    wp_admin_css('login', true);

    if (function_exists('wp_site_icon')) {
        wp_site_icon();
    }
    ?>
</head>
<?php // The login CSS is written against these classes; a bare #login does not get the look. ?>
<body class="login wp-core-ui">
<div id="login">
    <h1>
        <a href="<?php echo esc_url(home_url('/')); ?>">
            <?php echo esc_html(get_bloginfo('name', 'display')); ?>
        </a>
    </h1>

    <?php if ($sfx_pp_errors->has_errors()) : ?>
        <div id="login_error" role="alert">
            <?php foreach ($sfx_pp_errors->get_error_messages() as $sfx_pp_message) : ?>
                <p><?php echo esc_html($sfx_pp_message); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form name="sfx_pp_form" id="sfx_pp_form" action="<?php echo esc_url(Controller::login_url()); ?>" method="post">
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
            <button type="submit" name="sfx_pp_submit" id="sfx_pp_submit" class="button button-primary button-large">
                <?php esc_html_e('Enter', 'sfxtheme'); ?>
            </button>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($sfx_pp_redirect); ?>" />
            <?php wp_nonce_field('sfx_pp_login'); ?>
        </p>
    </form>
</div>
</body>
</html>
<?php
exit;
