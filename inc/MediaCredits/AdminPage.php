<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

class AdminPage
{
    public static string $menu_slug  = 'sfx-media-credits';

    /**
     * Title and description as methods, not static properties: a property
     * initialiser must be a constant expression, so the literals could not sit
     * inside __() there and reached the feature registry and the submenu
     * untranslated. The registry is built after load_textdomains()
     * (SFXBricksChildTheme.php:55,57), so the catalogue is loaded by then.
     */
    public static function page_title(): string
    {
        return __('Media Credits', 'sfxtheme');
    }

    public static function description(): string
    {
        return __('Copyright notices and AI markings on media, with optional automatic output in Bricks image elements.', 'sfxtheme');
    }

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
            self::page_title(),
            self::page_title(),
            // Known gap, deliberately left as is: AccessControl also admits a
            // role-based SFX_THEME_ADMINS user who lacks manage_options, and
            // WordPress then hides this entry from them. Seven sibling modules
            // have the same shape. The parent menu and PasswordProtected solve
            // it with 'read' — but PasswordProtected can, because it writes
            // through its own nonce-checked handler. This module saves via
            // options.php, and lowering the cap there means filtering
            // option_page_capability_*, which core applies before it decides
            // the request is even an update (wp-admin/options.php:47 vs :240).
            // That filter hands the caller the global All Settings screen on a
            // bare GET, and guarding it means enumerating core's pre-update
            // branches. Fixing this properly means moving off options.php.
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
                                    // Cast: core returns string|false, and the markup below tests
                                    // this once strictly and twice for truthiness. Without it an
                                    // unresolvable URL prints src="" and hides its own controls.
                                    $url   = $id > 0 ? (string) wp_get_attachment_image_url($id, 'thumbnail') : '';
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
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('Hooks for developers', 'sfxtheme'); ?></h2>
                        <p class="sfx-description"><?php esc_html_e('Extension points for adjusting copyright and AI-marking behaviour from code, grouped by where each one fires. Signatures are shown as-is and are not translated.', 'sfxtheme'); ?></p>

                        <h3 class="sfx-section-title"><?php esc_html_e('Composition', 'sfxtheme'); ?></h3>
                        <ul class="sfx-tips-list">
                            <li>
                                <code>apply_filters('sfx_media_credits_labels', array $labels): array</code><br>
                                <?php esc_html_e('Fires when the label vocabulary is built. Reword the AI and alteration labels; their keys stay fixed.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_parts', array $parts, int $attachment_id): array</code><br>
                                <?php esc_html_e('Fires after the copyright and AI values are resolved, before composition. The AI key you return is authoritative — its label and seal always follow from it.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_copyright_prefix', string $prefix, string $copyright, int $attachment_id): string</code><br>
                                <?php esc_html_e('Fires only when the copyright text does not already start with ©, (c) or Copyright.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_separator', string $separator, int $attachment_id): string</code><br>
                                <?php esc_html_e('Fires only when both a copyright and an AI part are present, right before they are joined.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_seal_html', string $html, int $icon_id, string $ai_key, int $size, int $attachment_id): string</code><br>
                                <?php esc_html_e('Fires after the seal image markup is built, only when a seal is actually rendered.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_line', string $line, int $attachment_id, array $parts): string</code><br>
                                <?php esc_html_e('The last word before the composed credit line is escaped. Replace it outright when nothing else fits.', 'sfxtheme'); ?>
                            </li>
                        </ul>

                        <h3 class="sfx-section-title"><?php esc_html_e('Output', 'sfxtheme'); ?></h3>
                        <ul class="sfx-tips-list">
                            <li>
                                <code>apply_filters('sfx_media_credits_should_auto_output', bool $should, string $mode, int $attachment_id, $element): bool</code><br>
                                <?php esc_html_e('Decides once per element whether automatic caption or overlay output happens at all.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_caption_auto_html', string $html, int $attachment_id, array $settings): string</code><br>
                                <?php esc_html_e('Covers automatic caption output only. A hand-placed {sfx_media_credit} tag goes through a different path.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_overlay_html', string $html, int $attachment_id, string $root_tag): string</code><br>
                                <?php esc_html_e('Fires right before the overlay markup is spliced into the rendered image element.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <code>apply_filters('sfx_media_credits_overlay_skip_tags', array $tags): array</code><br>
                                <?php esc_html_e('Fires before the root-tag check. The default list is img, picture and a.', 'sfxtheme'); ?>
                                <?php esc_html_e('The list is global, not per attachment: it is the one hook of the twelve with no attachment id, so a site cannot make the skip decision for a single image.', 'sfxtheme'); ?>
                                <?php esc_html_e('Adding figure to the list while "Force a wrapper" is on can leave an empty wrapper: the wrapper is written at settings time whenever a credit exists, and the skip list is only checked later, when the overlay itself is built — the two do not know about each other.', 'sfxtheme'); ?>
                            </li>
                        </ul>

                        <h3 class="sfx-section-title"><?php esc_html_e('Media library', 'sfxtheme'); ?></h3>
                        <ul class="sfx-tips-list">
                            <li>
                                <code>apply_filters('sfx_media_credits_iptc_value', string $value, array $image_meta, int $attachment_id): string</code><br>
                                <?php esc_html_e('Fires once, on first upload, after an IPTC copyright or credit value is read and before it is saved.', 'sfxtheme'); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Action:', 'sfxtheme'); ?></strong>
                                <code>do_action('sfx_media_credits_saved', int $attachment_id, string $copyright, string $ai_key, string $context)</code><br>
                                <?php esc_html_e('The seam for page-cache invalidation — the module deliberately knows nothing about caches.', 'sfxtheme'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
