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
            'sfx-wp-optimizer',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Optimizer Options', 'sfxtheme'); ?></h1>
            <?php
            $groups = [
                'performance' => __('Performance', 'sfx'),
                'security'    => __('Security & Privacy', 'sfx'),
                'frontend'    => __('Frontend Cleanup', 'sfx'),
                'media'       => __('Media & Uploads', 'sfx'),
                'comments'    => __('Comments & Feeds', 'sfx'),
            ];
            $fields = \SFX\WPOptimizer\Settings::get_fields();
            $options = get_option('sfx_wpoptimizer_options', []);
            ?>
            <div id="sfx-wpoptimizer-tabs">
                <div class="sfx-tabs-nav">
                    <?php $first = true; foreach ($groups as $group_key => $group_label): ?>
                        <button type="button" class="sfx-tab-btn<?php if ($first) echo ' active'; ?>" data-tab="<?php echo esc_attr($group_key); ?>">
                            <?php echo esc_html($group_label); ?>
                        </button>
                    <?php $first = false; endforeach; ?>
                </div>
                <form method="post" action="options.php">
                    <?php settings_fields(\SFX\WPOptimizer\Settings::$OPTION_GROUP); ?>
                    <div class="sfx-tabs-content">
                        <?php $first = true; foreach ($groups as $group_key => $group_label):
                            $group_fields = array_filter($fields, fn($f) => ($f['group'] ?? '') === $group_key);
                            if (empty($group_fields)) continue;
                        ?>
                        <div id="<?php echo esc_attr($group_key); ?>" class="sfx-tab-content"<?php if (!$first) echo ' style="display:none;"'; ?>>
                            <div style="display: flex; flex-wrap: wrap; gap: 24px;">
                                <?php foreach ($group_fields as $field):
                                    $id = esc_attr($field['id']);
                                    $type = $field['type'] ?? 'checkbox';
                                    $value = $options[$id] ?? $field['default'];
                                ?>
                                <div style="flex: 1 1 33%; min-width: 220px; max-width: 350px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between;">
                                    <h2 style="margin-top:0; font-size: 1.1em;"><?php echo esc_html($field['label']); ?></h2>
                                    <p style="font-size: 0.97em; color: #555; margin-bottom: 16px;"><?php echo esc_html($field['description']); ?></p>
                                    <?php if ($type === 'checkbox'): ?>
                                        <input type="checkbox" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="1" <?php checked((int)$value, 1); ?> />
                                    <?php elseif ($type === 'number'):
                                        $min = isset($field['min']) ? (int)$field['min'] : 0;
                                        $max = isset($field['max']) ? (int)$field['max'] : 10;
                                    ?>
                                        <input type="number" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="<?php echo esc_attr($value); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" />
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php $first = false; endforeach; ?>
                    </div>
                    <?php submit_button(); ?>
                </form>
            </div>
        </div>
        <?php
    }
} 