<?php

declare(strict_types=1);

namespace SFX\Markdown;

class AdminPage
{
    public static $menu_slug = 'sfx-markdown';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            'sfx-theme-settings',
            __('Markdown for AI agents', 'sfxtheme'),
            __('Markdown', 'sfxtheme'),
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        $o = Settings::get();
        $name = Settings::OPTION_NAME;
        $parser = Request::parser_available();
        $on = \SFX\SFXBricksChildTheme::is_general_option_enabled('enable_markdown');
        $run = $on && $parser;
        $newest = LlmsTxt::newest_listed_url();
        $llms_url = home_url('/llms.txt');
        ?>
        <div class="wrap sfx-markdown" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Markdown for AI agents', 'sfxtheme'); ?></h1>

                        <?php if (!$parser) : ?>
                            <div class="notice notice-warning inline"><p><?php esc_html_e('The Markdown front end needs WordPress 6.7 or newer. It stays off until then.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>
                        <?php if (!$on) : ?>
                            <div class="notice notice-info inline"><p><?php esc_html_e('The module is switched off in the general theme options. The checks run once it is on.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>

                        <form method="post" action="options.php">
                            <?php settings_fields(Settings::OPTION_GROUP); ?>
                            <?php // Always send a field, so an empty selection saves [] instead of null (which sanitizes to the defaults). ?>
                            <input type="hidden" name="<?php echo esc_attr($name); ?>[_present]" value="1" />
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Included post types', 'sfxtheme'); ?></th>
                                    <td>
                                        <fieldset>
                                            <?php foreach (Settings::offered_post_types() as $type => $label) : ?>
                                                <label style="display: block;"><input type="checkbox" name="<?php echo esc_attr($name); ?>[post_types][]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $o['post_types'], true)); ?> /> <?php echo esc_html($label); ?></label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description"><?php esc_html_e('Only published pages without a password are served and listed in llms.txt.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(); ?>
                        </form>

                        <h2 class="sfx-section-title"><?php esc_html_e('Addresses for this site', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <?php if ($newest !== null) : ?>
                                <li><?php esc_html_e('Markdown of a page, by address:', 'sfxtheme'); ?> <code><?php echo esc_url(add_query_arg('markdown', '1', $newest)); ?></code></li>
                            <?php endif; ?>
                            <li><?php esc_html_e('Markdown of any page, by request header:', 'sfxtheme'); ?> <code>Accept: text/markdown</code></li>
                            <li><?php esc_html_e('Index of all pages:', 'sfxtheme'); ?> <code><?php echo esc_url($llms_url); ?></code></li>
                        </ul>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('Check: /llms.txt', 'sfxtheme'); ?></h2>
                        <?php if ($run) : self::print_llms(LoopbackCheck::run_llms($llms_url)); endif; ?>

                        <h2 class="sfx-section-title"><?php esc_html_e('Check: page cache', 'sfxtheme'); ?></h2>
                        <?php if ($run) : ?>
                            <?php if ($newest === null) : ?>
                                <p><?php esc_html_e('No page to check.', 'sfxtheme'); ?></p>
                            <?php else : self::print_cache(LoopbackCheck::run_cache($newest)); endif; ?>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('A CDN in front of the site often never sees a loopback request, so a clean result proves nothing about the CDN.', 'sfxtheme'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /** @param array{outcome:string, detail:string} $r */
    private static function print_llms(array $r): void
    {
        switch ($r['outcome']) {
            case 'theme':
                echo '<p>' . esc_html__('The theme serves /llms.txt.', 'sfxtheme') . '</p>';
                break;
            case 'redirected':
                /* translators: %s: redirect target */
                echo '<p>' . esc_html(sprintf(__('/llms.txt redirects to %s.', 'sfxtheme'), $r['detail'])) . '</p>';
                break;
            case 'none':
                echo '<p>' . esc_html__('No llms.txt is served; check the Redirects rules for /llms.txt.', 'sfxtheme') . '</p>';
                break;
            case 'html':
                echo '<p>' . esc_html__('An HTML page answers at /llms.txt.', 'sfxtheme') . '</p>';
                break;
            case 'foreign':
                echo '<p>' . esc_html__('Another llms.txt answers, not the theme\'s. Switch it off so the theme\'s index can be served:', 'sfxtheme') . '</p><ul class="sfx-tips-list">';
                foreach (LoopbackCheck::guidance() as $line) {
                    echo '<li>' . esc_html($line) . '</li>';
                }
                echo '</ul>';
                break;
            default:
                /* translators: %s: what came back */
                echo '<p>' . esc_html(sprintf(__('Could not check /llms.txt (%s).', 'sfxtheme'), $r['detail'])) . '</p>';
        }
    }

    private static function print_cache(string $outcome): void
    {
        $messages = [
            'clean' => __('No page cache problem found.', 'sfxtheme'),
            'cache_html' => __('Your page cache answers Markdown requests by Accept header with HTML; the ?markdown=1 address still works.', 'sfxtheme'),
            'no_convert' => __('This page does not convert, so the check cannot run.', 'sfxtheme'),
            'failed' => __('Could not check.', 'sfxtheme'),
        ];
        echo '<p>' . esc_html($messages[$outcome] ?? $messages['failed']) . '</p>';
    }
}
