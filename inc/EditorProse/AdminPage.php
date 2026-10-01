<?php

declare(strict_types=1);

namespace SFX\EditorProse;

class AdminPage
{
    public static $menu_slug = 'sfx-editor-prose';

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
            __('Editor Prose', 'sfxtheme'),
            __('Editor Prose', 'sfxtheme'),
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
        $post_types = array_filter(
            get_post_types(['show_ui' => true], 'objects'),
            static fn($pt) => !function_exists('use_block_editor_for_post_type') || use_block_editor_for_post_type($pt->name)
        );
        $bricks = defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : null;
        $missing = Payload::missing_classes($o['classes']);
        // Same parsing as Bricks (admin.php should_enqueue_gutenberg_theme_styles).
        $theme_styles_off = class_exists('Bricks\Database') && filter_var(\Bricks\Database::get_setting('disableThemeStylesInBlockEditor'), FILTER_VALIDATE_BOOLEAN);
        ?>
        <div class="wrap sfx-editor-prose" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Editor Prose', 'sfxtheme'); ?></h1>

                        <?php if (!Settings::bricks_ok($bricks)) : ?>
                            <div class="notice notice-warning inline"><p><?php esc_html_e('Bricks 2.4 or newer is required. The editor is left unchanged until then.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>
                        <?php if ($missing !== []) : ?>
                            <div class="notice notice-warning inline"><p><?php
                                /* translators: %s: comma-separated class names */
                                printf(esc_html__('No Bricks global class with this name: %s', 'sfxtheme'), esc_html(implode(', ', $missing)));
                            ?></p></div>
                        <?php endif; ?>
                        <?php if ($theme_styles_off) : ?>
                            <div class="notice notice-warning inline"><p><?php esc_html_e('Bricks theme styles are disabled in the block editor (Bricks settings). Spacing and root font size cannot match the frontend.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>
                        <?php if ($o['baseline']) : ?>
                            <div class="notice notice-info inline"><p><?php esc_html_e('Baseline is on: add the class sfx-prose to the Bricks element that wraps the content.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>

                        <form method="post" action="options.php">
                            <?php settings_fields(Settings::OPTION_GROUP); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="sfx_ep_classes"><?php esc_html_e('Prose classes', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="text" class="regular-text" id="sfx_ep_classes" name="<?php echo esc_attr($name); ?>[classes]" value="<?php echo esc_attr(implode(' ', $o['classes'])); ?>" />
                                        <p class="description"><?php esc_html_e('Bricks global class names, separated by spaces or commas, in the order they appear on the frontend element.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx_ep_element"><?php esc_html_e('Wrapper element', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <select id="sfx_ep_element" name="<?php echo esc_attr($name); ?>[element]">
                                            <option value="text" <?php selected($o['element'], 'text'); ?>><?php esc_html_e('Rich Text', 'sfxtheme'); ?></option>
                                            <option value="post-content" <?php selected($o['element'], 'post-content'); ?>><?php esc_html_e('Post Content', 'sfxtheme'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Post types', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="hidden" name="<?php echo esc_attr($name); ?>[all_post_types]" value="0" />
                                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[all_post_types]" value="1" <?php checked($o['all_post_types']); ?> /> <?php esc_html_e('All post types using the block editor', 'sfxtheme'); ?></label>
                                        <fieldset style="margin-top: 8px;">
                                            <?php foreach ($post_types as $pt) : ?>
                                                <label style="display: block;"><input type="checkbox" name="<?php echo esc_attr($name); ?>[post_types][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $o['post_types'], true)); ?> /> <?php echo esc_html($pt->labels->singular_name); ?></label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description"><?php esc_html_e('The list is used only when "All post types" is off.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Baseline', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="hidden" name="<?php echo esc_attr($name); ?>[baseline]" value="0" />
                                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[baseline]" value="1" <?php checked($o['baseline']); ?> /> <?php esc_html_e('Load the token-based prose baseline (frontend and editor)', 'sfxtheme'); ?></label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx_ep_title_gap"><?php esc_html_e('Gap below the title', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="text" id="sfx_ep_title_gap" name="<?php echo esc_attr($name); ?>[title_gap]" value="<?php echo esc_attr($o['title_gap']); ?>" placeholder="var(--space-m)" />
                                        <p class="description"><?php esc_html_e('Optional. A CSS value, e.g. 2rem or var(--space-m).', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(); ?>
                        </form>
                        <h2 class="sfx-section-title" id="sfx_ep_starter_title" style="margin-top: 24px;"><?php esc_html_e('Starter for your own prose class', 'sfxtheme'); ?></h2>
                        <p class="description"><?php esc_html_e('The baseline as plain class CSS. Copy it into the custom CSS of a new Bricks global class and adjust it there; you then do not need the baseline checkbox.', 'sfxtheme'); ?></p>
                        <textarea id="sfx_ep_starter" aria-labelledby="sfx_ep_starter_title" class="large-text code" rows="14" readonly><?php echo esc_textarea(Starter::from_file()); ?></textarea>
                        <p><button type="button" class="button" id="sfx_ep_starter_copy" data-label-copied="<?php echo esc_attr__('Copied', 'sfxtheme'); ?>"><?php esc_html_e('Copy to clipboard', 'sfxtheme'); ?></button></p>
                        <script>
                        (function () {
                            var btn = document.getElementById('sfx_ep_starter_copy');
                            var field = document.getElementById('sfx_ep_starter');
                            if (!btn || !field) { return; }
                            var label = btn.textContent;
                            btn.addEventListener('click', function () {
                                // Only confirm on an actual copy (same pattern as PasswordProtected).
                                var flash = function () {
                                    btn.textContent = btn.getAttribute('data-label-copied');
                                    setTimeout(function () { btn.textContent = label; }, 1500);
                                };
                                var fallback = function () {
                                    field.focus();
                                    field.select();
                                    var ok = false;
                                    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                                    if (ok) { flash(); }
                                };
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(field.value).then(flash, fallback);
                                } else {
                                    fallback();
                                }
                            });
                        })();
                        </script>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('How it works', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Everything in the prose class — typography, lists, links, figures, tables — is mirrored into the editor. Design it in Bricks, in the class, not on the element.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Spacing between blocks comes from the Bricks theme style (contextual spacing).', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Not mirrored: rules that depend on elements outside the content, structural selectors where the editor adds its own nodes (e.g. :last-child), settings on the wrapper element itself, wrapper attributes, a class reused on other element types while class chaining is off, relative url() paths. Breakpoints follow the editor width; use the device preview.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Where a prose rule competes with a WordPress block style, a Bricks theme-style link colour or a component class, the editor can resolve it differently. Make such prose rules one class more specific. Details: theme README, "Editor Prose: authoring notes".', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Anyone who can edit Bricks global classes can put CSS into the editor of every post shown here. Only give that permission to people trusted with all drafts.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
