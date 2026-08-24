<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

class AdminPage
{
    public static string $menu_slug  = 'sfx-media-credits';
    public static string $page_title = 'Media Credits';
    public static string $description = 'Copyright notices and AI markings on media, with optional automatic output in Bricks image elements.';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
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
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (strpos($hook, self::$menu_slug) === false) {
            return;
        }

        wp_enqueue_media();

        $path = get_stylesheet_directory() . '/inc/MediaCredits/assets/media-credits-admin.js';

        if (!file_exists($path)) {
            return;
        }

        wp_enqueue_script(
            'sfx-media-credits-admin',
            get_stylesheet_directory_uri() . '/inc/MediaCredits/assets/media-credits-admin.js',
            ['jquery'],
            (string) filemtime($path),
            true
        );

        wp_localize_script('sfx-media-credits-admin', 'sfxMediaCredits', [
            'frameTitle' => __('Choose a seal image', 'sfxtheme'),
            'frameButton' => __('Use this image', 'sfxtheme'),
        ]);
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        // Normalised, not raw: an import performed while the feature was off
        // never reached the registered sanitizer, and the page must not offer
        // an invalid mode or an out-of-range size back to the editor as if it
        // were a real setting.
        $stored  = get_option(Settings::OPTION_NAME, []);
        $options = Settings::normalize(is_array($stored) ? $stored : []);
        $labels  = Settings::get_labels();
        ?>
        <div class="wrap sfx-media-credits" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Media Credits', 'sfxtheme'); ?></h1>
                        <form method="post" action="options.php">
                            <?php settings_fields(Settings::OPTION_GROUP); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="sfx-mc-output-mode"><?php esc_html_e('Automatic output', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <select id="sfx-mc-output-mode" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[output_mode]">
                                            <?php
                                            $mode_labels = [
                                                'off'     => __('Off — place {sfx_media_credit} yourself', 'sfxtheme'),
                                                'caption' => __('Caption (recommended)', 'sfxtheme'),
                                                'overlay' => __('Overlay on the image', 'sfxtheme'),
                                            ];
                                            foreach (Settings::OUTPUT_MODES as $mode) {
                                                printf(
                                                    '<option value="%s"%s>%s</option>',
                                                    esc_attr($mode),
                                                    selected($options['output_mode'], $mode, false),
                                                    esc_html($mode_labels[$mode])
                                                );
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Force a wrapper', 'sfxtheme'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[force_wrapper]" value="1" <?php checked(!empty($options['force_wrapper'])); ?>>
                                            <?php esc_html_e('Overlay mode only: give image elements that have no HTML tag a figure wrapper to attach the credit to.', 'sfxtheme'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx-mc-display"><?php esc_html_e('AI marking style', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <select id="sfx-mc-display" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[credit_display]">
                                            <?php
                                            $display_labels = [
                                                'text'      => __('Text only', 'sfxtheme'),
                                                'icon'      => __('Seal only', 'sfxtheme'),
                                                'icon_text' => __('Seal and text', 'sfxtheme'),
                                            ];
                                            foreach (Settings::CREDIT_DISPLAYS as $display) {
                                                printf(
                                                    '<option value="%s"%s>%s</option>',
                                                    esc_attr($display),
                                                    selected($options['credit_display'], $display, false),
                                                    esc_html($display_labels[$display])
                                                );
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx-mc-icon-size"><?php esc_html_e('Seal size (px)', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="number" id="sfx-mc-icon-size" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[icon_size]"
                                               value="<?php echo esc_attr((string) $options['icon_size']); ?>"
                                               min="<?php echo esc_attr((string) Settings::ICON_SIZE_MIN); ?>"
                                               max="<?php echo esc_attr((string) Settings::ICON_SIZE_MAX); ?>" step="1">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx-mc-fallback"><?php esc_html_e('Fallback copyright', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="text" class="regular-text" id="sfx-mc-fallback"
                                               name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[fallback_copyright]"
                                               value="<?php echo esc_attr((string) $options['fallback_copyright']); ?>">
                                        <p class="description"><?php esc_html_e('Used when an attachment has no copyright of its own. Careful: this gives EVERY image a credit, logos and icons included. Leave empty unless the site owns its imagery.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <h2 class="sfx-section-title"><?php esc_html_e('AI seals', 'sfxtheme'); ?></h2>
                            <table class="form-table" role="presentation">
                                <?php foreach ($labels as $slug => $label) :
                                    $field = 'seal_' . $slug;
                                    $id    = (int) ($options[$field] ?? 0);
                                    $url   = $id > 0 ? wp_get_attachment_image_url($id, 'thumbnail') : '';
                                    ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html($label); ?></th>
                                        <td class="sfx-mc-seal" data-field="<?php echo esc_attr($field); ?>">
                                            <input type="hidden" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[<?php echo esc_attr($field); ?>]"
                                                   value="<?php echo esc_attr((string) $id); ?>" class="sfx-mc-seal-input">
                                            <img<?php echo $url !== '' ? ' src="' . esc_url($url) . '"' : ''; ?> alt="<?php echo esc_attr($label); ?>"
                                                 class="sfx-mc-seal-preview" style="max-width:48px;height:auto;vertical-align:middle;<?php echo $url ? '' : 'display:none;'; ?>">
                                            <button type="button" class="button sfx-mc-seal-choose"><?php esc_html_e('Choose image', 'sfxtheme'); ?></button>
                                            <button type="button" class="button-link sfx-mc-seal-remove" <?php echo $url ? '' : 'style="display:none;"'; ?>><?php esc_html_e('Remove', 'sfxtheme'); ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>

                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%; min-height: 100vh;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('How output works', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Place {sfx_media_credit} in an image element\'s Custom caption to control exactly where the credit appears. This works everywhere and is unaffected by the limits below.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Caption mode is the reliable automatic mode: it writes the credit into the element\'s own caption, so Bricks renders valid markup and styles it with its own caption controls.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Overlay mode needs something to attach to. Set the image element\'s HTML tag to figure, or switch on "Force a wrapper" above.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Overlay cannot attach to a responsive image using Sources that has neither a link nor a caption — Bricks makes that a picture element. Use caption mode for those.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('While editing, an overlay may only appear after the canvas reloads. Bricks does not run the output filter when it re-renders a single element.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Add no-credit in the image element\'s CSS classes field (Style tab) — not the global class picker — to exclude it from automatic output.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
