<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class AdminPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
        add_submenu_page(
            \SFX\Options\AdminOptionPages::$menu_slug,
            __('WP Optimizer', 'sfxtheme'),
            __('WP Optimizer', 'sfxtheme'),
            'manage_options',
            'wp-optimizer-options',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Optimizer Options', 'sfxtheme'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(\SFX\WPOptimizer\Settings::$OPTION_GROUP);
                // Custom grid layout for fields
                $fields = \SFX\WPOptimizer\Settings::get_fields();
                $options = get_option('sfx_wpoptimizer_options', []);
                echo '<div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 32px;">';
                foreach ($fields as $field) {
                    $id = esc_attr($field['id']);
                    $type = $field['type'] ?? 'checkbox';
                    $value = $options[$id] ?? $field['default'];
                    echo '<div style="flex: 1 1 33%; min-width: 220px; max-width: 350px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between;">';
                    echo '<h2 style="margin-top:0; font-size: 1.1em;">' . esc_html($field['label']) . '</h2>';
                    echo '<p style="font-size: 0.97em; color: #555; margin-bottom: 16px;">' . esc_html($field['description']) . '</p>';
                    if ($type === 'checkbox') {
                        echo '<input type="checkbox" id="' . $id . '" name="sfx_wpoptimizer_options[' . $id . ']" value="1" ' . checked((int)$value, 1, false) . ' />';
                    } elseif ($type === 'number') {
                        $min = isset($field['min']) ? (int)$field['min'] : 0;
                        $max = isset($field['max']) ? (int)$field['max'] : 10;
                        echo '<input type="number" id="' . $id . '" name="sfx_wpoptimizer_options[' . $id . ']" value="' . esc_attr($value) . '" min="' . $min . '" max="' . $max . '" />';
                    }
                    echo '</div>';
                }
                echo '</div>';
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
} 