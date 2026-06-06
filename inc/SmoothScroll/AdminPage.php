<?php

declare(strict_types=1);

namespace SFX\SmoothScroll;

class AdminPage
{
    public static $menu_slug = 'sfx-smooth-scroll';
    public static $page_title = 'Scroll Smoother';
    public static $description = 'Configure Lenis-powered smooth scrolling (duration, easing, mouse/touch multipliers, direction, and smooth anchor scrolling). Replaces the Bricksforge Scroll Smoother so the theme no longer depends on that plugin for smooth scroll.';

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
            self::$page_title,
            self::$page_title,
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        $options = get_option(Settings::OPTION_NAME, Settings::get_defaults());
        $fields_by_col = self::group_fields_by_col();
        ?>
        <div class="wrap sfx-smooth-scroll" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Scroll Smoother Settings', 'sfxtheme'); ?></h1>
                        <form method="post" action="options.php">
                            <?php settings_fields(Settings::OPTION_GROUP); ?>

                            <div class="sfx-ss-field" style="margin-bottom: 20px;">
                                <label for="sfx_ss_provider"><?php esc_html_e('Smooth Scrolling Provider', 'sfxtheme'); ?></label>
                                <select id="sfx_ss_provider" disabled>
                                    <option selected><?php esc_html_e('Lenis', 'sfxtheme'); ?></option>
                                </select>
                            </div>

                            <?php self::render_row('metrics', $fields_by_col['metrics'] ?? [], $options); ?>
                            <?php self::render_row('easing', $fields_by_col['easing'] ?? [], $options); ?>
                            <?php self::render_row('toggles', $fields_by_col['toggles'] ?? [], $options); ?>
                            <?php self::render_row('anchor', $fields_by_col['anchor'] ?? [], $options); ?>

                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%; min-height: 100vh;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('Smooth Scroll Tips', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Use the data-lenis-prevent attribute on nested scroll elements. We also advise adding overscroll-behavior: contain on those elements.', 'sfxtheme'); ?></li>
                            <li><?php printf(esc_html__('You can use easing values from %1$seasings.net%2$s (Math functions).', 'sfxtheme'), '<a href="https://easings.net/" target="_blank" rel="noopener noreferrer">', '</a>'); ?></li>
                            <li><?php esc_html_e('Custom easing expressions require script-src unsafe-eval. A strict Content-Security-Policy set in Security Headers may block them; the default easing is used instead.', 'sfxtheme'); ?></li>
                            <li><?php printf(esc_html__('%1$sLenis documentation%2$s', 'sfxtheme'), '<a href="https://lenis.darkroom.engineering/" target="_blank" rel="noopener noreferrer">', '</a>'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function group_fields_by_col(): array
    {
        $grouped = [];
        foreach (Settings::get_fields() as $field) {
            $col = $field['col'] ?? 'default';
            $grouped[$col][] = $field;
        }
        return $grouped;
    }

    private static function render_row(string $col, array $fields, array $options): void
    {
        if ($fields === []) {
            return;
        }

        $row_class = 'sfx-ss-row sfx-ss-row--' . esc_attr($col);
        echo '<div class="' . $row_class . '">';

        foreach ($fields as $field) {
            self::render_field($field, $options);
        }

        echo '</div>';
    }

    private static function render_field(array $field, array $options): void
    {
        $id = $field['id'];
        $name = Settings::OPTION_NAME . '[' . $id . ']';
        $value = $options[$id] ?? $field['default'];
        $toggle_class = $field['type'] === 'checkbox' ? ' sfx-ss-field--toggle' : '';

        echo '<div class="sfx-ss-field' . esc_attr($toggle_class) . '">';
        echo '<label for="sfx_ss_' . esc_attr($id) . '">' . esc_html($field['label']) . '</label>';

        switch ($field['type']) {
            case 'number':
                printf(
                    '<input type="number" id="sfx_ss_%1$s" name="%2$s" value="%3$s" step="%4$s" min="%5$s" max="%6$s" />',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr((string) $value),
                    esc_attr($field['step'] ?? '1'),
                    esc_attr($field['min'] ?? ''),
                    esc_attr($field['max'] ?? '')
                );
                if ($id === 'anchor_scroll_offset') {
                    echo '<p class="sfx-ss-note">' . esc_html__('Space (in pixels) to leave above the target when scrolling to an anchor, e.g. to clear a sticky header. Positive values create a gap below the top.', 'sfxtheme') . '</p>';
                }
                break;

            case 'select':
                echo '<select id="sfx_ss_' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                foreach ($field['choices'] as $choice_value => $choice_label) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr($choice_value),
                        selected($value, $choice_value, false),
                        esc_html($choice_label)
                    );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" class="sfx-toggle" id="sfx_ss_%1$s" name="%2$s" value="1" %3$s />',
                    esc_attr($id),
                    esc_attr($name),
                    checked((int) $value, 1, false)
                );
                break;

            case 'text':
            default:
                if ($id === 'easing') {
                    echo '<p class="sfx-ss-note">' . esc_html__('Expression in t (0 to 1). Default uses no eval.', 'sfxtheme') . '</p>';
                }
                printf(
                    '<input type="text" id="sfx_ss_%1$s" name="%2$s" value="%3$s" />',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr((string) $value)
                );
                break;
        }

        echo '</div>';
    }
}
