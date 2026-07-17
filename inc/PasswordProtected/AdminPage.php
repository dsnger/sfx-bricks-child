<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

class AdminPage
{
    public static $menu_slug = 'sfx-password-protected';
    public static $page_title = 'Password Protection';
    public static $description = 'Protect the frontend of this site with a single shared password. Includes a shareable bypass URL for clients and reviewers.';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function page_url(): string
    {
        return admin_url('admin.php?page=' . self::$menu_slug);
    }

    public static function add_submenu_page(): void
    {
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            'sfx-theme-settings',
            self::$page_title,
            self::$page_title,
            // Broad cap, matching SFXBricksChildAdmin::register_admin_menu():
            // role-based SFX_THEME_ADMINS users without manage_options must
            // still reach the page AccessControl just authorized them for.
            // Render is guarded by die_if_unauthorized_theme() below.
            'read',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        $settings = Settings::get();
        $own_ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';
        ?>
        <div class="wrap" style="padding: 0; font-size: 14px;">
            <?php settings_errors(Settings::OPTION_NAME); ?>
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Password Protection', 'sfxtheme'); ?></h1>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="sfx_pp_save" />
                            <?php wp_nonce_field('sfx_pp_save'); ?>

                            <table class="sfx-form-table">
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Protection Status', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="checkbox" name="status" value="1" <?php checked($settings['status']); ?> />
                                        <div class="sfx-description"><?php esc_html_e('Switch the password prompt on for the whole frontend. Requires a password to be set.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Permissions', 'sfxtheme'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="allow_admins" value="1" <?php checked($settings['allow_admins']); ?> /> <?php esc_html_e('Allow administrators', 'sfxtheme'); ?></label><br />
                                        <label><input type="checkbox" name="allow_users" value="1" <?php checked($settings['allow_users']); ?> /> <?php esc_html_e('Allow logged-in users', 'sfxtheme'); ?></label><br />
                                        <label><input type="checkbox" name="allow_feeds" value="1" <?php checked($settings['allow_feeds']); ?> /> <?php esc_html_e('Allow RSS feeds', 'sfxtheme'); ?></label><br />
                                        <label><input type="checkbox" name="allow_rest" value="1" <?php checked($settings['allow_rest']); ?> /> <?php esc_html_e('Allow REST API', 'sfxtheme'); ?></label>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('New Password', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="password" name="password_new" value="" autocomplete="new-password" />
                                        <div class="sfx-description"><?php esc_html_e('Leave empty to keep the current password.', 'sfxtheme'); ?></div>
                                        <p>
                                            <input type="password" name="password_confirm" value="" autocomplete="new-password" />
                                        </p>
                                        <div class="sfx-description"><?php esc_html_e('Repeat the new password.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Allowed IP Addresses', 'sfxtheme'); ?></th>
                                    <td>
                                        <textarea name="allowed_ips" rows="4" cols="40"><?php echo esc_textarea($settings['allowed_ips']); ?></textarea>
                                        <div class="sfx-description">
                                            <?php
                                            printf(
                                                /* translators: %s: the visitor's own IP address */
                                                esc_html__('One IP address per line. Invalid lines are dropped on save. Your IP address is %s.', 'sfxtheme'),
                                                '<code>' . esc_html($own_ip) . '</code>'
                                            );
                                            ?>
                                        </div>
                                        <div class="sfx-description"><strong><?php esc_html_e('Behind a proxy or CDN this is the proxy address. Allowlisting it allowlists every visitor and silently disables protection.', 'sfxtheme'); ?></strong></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Stay Logged In', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="checkbox" name="allow_remember_me" value="1" <?php checked($settings['allow_remember_me']); ?> />
                                        <div class="sfx-description"><?php esc_html_e('Show a "Stay logged in" checkbox on the password prompt.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Keep For (days)', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="number" name="remember_me_lifetime" min="1" max="365" value="<?php echo esc_attr((string) $settings['remember_me_lifetime']); ?>" />
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row">&nbsp;</th>
                                    <td><hr class="sfx-hr" /></td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Bypass URL', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="checkbox" name="bypass_enabled" value="1" <?php checked($settings['bypass_enabled']); ?> />
                                        <div class="sfx-description"><?php esc_html_e('A shareable link that grants access without the password. A key is generated automatically when you switch this on.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <?php if ($settings['bypass_key'] !== '') : ?>
                                    <tr valign="top">
                                        <th scope="row"><?php esc_html_e('Bypass Link', 'sfxtheme'); ?></th>
                                        <td>
                                            <?php // No name attribute: the key is never submitted. A stale form
                                                  // would otherwise silently restore a rotated key. ?>
                                            <input type="text" readonly onfocus="this.select();" style="width: 100%;"
                                                   value="<?php echo esc_attr(home_url('/?sfx_bypass=' . rawurlencode($settings['bypass_key']))); ?>" />
                                            <div class="sfx-description"><?php esc_html_e('Anyone with this link gets in. It will end up in browser history and server logs.', 'sfxtheme'); ?></div>
                                        </td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php esc_html_e('Rotate Key', 'sfxtheme'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="bypass_rotate" value="1" />
                                                <?php esc_html_e('Generate a new key on save — the old link stops working immediately.', 'sfxtheme'); ?>
                                            </label>
                                            <div class="sfx-description"><strong><?php esc_html_e('Rotating the key does not log out anyone who already used the old link. Only changing the password does that.', 'sfxtheme'); ?></strong></div>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Redirect To', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="text" name="bypass_redirect" style="width: 100%;" value="<?php echo esc_attr($settings['bypass_redirect']); ?>" placeholder="<?php echo esc_attr(home_url('/')); ?>" />
                                        <div class="sfx-description"><?php esc_html_e('Where the bypass link sends the visitor. Empty means the home page.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>
                            </table>

                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>

                <div class="sfx-col" style="width: 50%; min-height: 100vh;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('What this does not protect', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><strong><?php esc_html_e('Media and uploads are public.', 'sfxtheme'); ?></strong> <?php esc_html_e('Files under /wp-content/uploads/ are served by the webserver without WordPress running. Every image, PDF and video stays readable to anyone with the URL.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('admin-ajax.php and admin-post.php actions registered for logged-out visitors stay reachable.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('xmlrpc.php and wp-cron.php are not covered.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('There is no brute-force throttling. A shared password is guessable given time.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('The bypass key travels in a URL and will end up in browser history, CDN and server logs.', 'sfxtheme'); ?></li>
                        </ul>

                        <h2 class="sfx-section-title"><?php esc_html_e('Caching', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><strong><?php esc_html_e('Purge your page cache after switching protection on.', 'sfxtheme'); ?></strong> <?php esc_html_e('Pages cached beforehand keep being served without this theme ever running.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Server-level caches (Varnish, host full-page caches) may ignore the theme entirely and are out of reach.', 'sfxtheme'); ?></li>
                        </ul>

                        <h2 class="sfx-section-title"><?php esc_html_e('Logging everyone out', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Changing the password is the only thing that invalidates existing access. Switching protection off and on again does not.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
