<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class AdminPage
{
    public static $menu_slug = 'sfx-wp-optimizer';
    public static $page_title = 'WP Optimizer';
    public static $description = 'Toggle a wide range of WordPress optimizations (disable search, comments, REST API, feeds, version numbers, etc.) for performance and security.';

    /**
     * Evaluate conditional logic for field display
     */
    private static function evaluate_condition($value, $operator, $expected_value = null): bool
    {
        switch ($operator) {
            case 'checked':
                return (bool)$value;
            case '!checked':
                return !(bool)$value;
            case 'equals':
                return $value == $expected_value;
            case '!equals':
                return $value != $expected_value;
            case 'in_array':
                return is_array($expected_value) && in_array($value, $expected_value);
            case '!in_array':
                return is_array($expected_value) && !in_array($value, $expected_value);
            default:
                return true;
        }
    }

    /**
     * Get all conditional field configurations
     */
    private static function get_conditional_fields(): array
    {
        $fields = Settings::get_fields();
        $conditionals = [];

        foreach ($fields as $field) {
            if (isset($field['conditional'])) {
                $conditionals[] = [
                    'target' => $field['id'],
                    'dependency' => $field['conditional']['field'],
                    'operator' => $field['conditional']['operator'],
                    'value' => $field['conditional']['value'] ?? null
                ];
            }
        }

        return $conditionals;
    }

    /**
     * Render post types selection UI with accordion support
     */
    private static function render_post_types_selection($field_id, $value, $options = []): string
    {
        // Filter post types based on field ID
        if ($field_id === 'enable_content_order_post_types') {
            // For Content Order: ONLY hierarchical post types OR those supporting page-attributes
            // Taxonomies won't work because ContentOrder uses wp_posts.menu_order field
            $post_types = get_post_types(['public' => true], 'objects');

            $filtered_items = [];

            foreach ($post_types as $post_type => $post_type_obj) {
                // Only include post types that actually work with ContentOrder
                if ($post_type_obj->hierarchical || post_type_supports($post_type, 'page-attributes')) {
                    $filtered_items[$post_type] = (object) [
                        'labels' => $post_type_obj->labels,
                        'type' => 'post_type',
                        'hierarchical' => $post_type_obj->hierarchical,
                        'supports_page_attributes' => post_type_supports($post_type, 'page-attributes')
                    ];
                }
            }

            $items = $filtered_items;
            $help_text = __('Leave all unchecked to apply to all supported post types. Only hierarchical post types and those supporting page attributes can be ordered.', 'sfx');
        } else {
            // For other post type fields (like revisions): all public post types
            $items = get_post_types(['public' => true], 'objects');
            $help_text = __('Leave all unchecked to apply to all post types.', 'sfx');
        }

        $selected_items = [];
        if (is_array($value)) {
            $selected_items = $value;
        }

        ob_start();
?>
        <div class="post-types-selection">
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                <?php foreach ($items as $item_key => $item_obj): ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox"
                            name="sfx_wpoptimizer_options[<?php echo esc_attr($field_id); ?>][]"
                            value="<?php echo esc_attr($item_key); ?>"
                            <?php checked(in_array($item_key, $selected_items)); ?> />
                        <strong><?php echo esc_html($item_obj->labels->name); ?></strong>
                        <small style="color: #666;">(<?php echo esc_html($item_key); ?>)</small>
                        <?php if (isset($item_obj->hierarchical) && $item_obj->hierarchical): ?>
                            <span style="color: #0073aa; font-size: 0.9em;"> - Hierarchical</span>
                        <?php elseif (isset($item_obj->supports_page_attributes) && $item_obj->supports_page_attributes): ?>
                            <span style="color: #0073aa; font-size: 0.9em;"> - Page Attributes</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p><small><?php echo $help_text; ?></small></p>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Render post types selection with accordion wrapper
     */
    private static function render_post_types_accordion($field_id, $value, $options = []): string
    {
        ob_start();
    ?>
        <details class="post-types-accordion" style="margin-top: 10px;">
            <summary>
                <?php _e('Select Post Types', 'sfx'); ?>
                <span>▼</span>
            </summary>
            <div style="border: 1px solid #ddd; border-top: none; padding: 10px; background: #f9f9f9;">
                <?php echo self::render_post_types_selection($field_id, $value, $options); ?>
            </div>
        </details>
    <?php
        return ob_get_clean();
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
        // Only register menu if user has theme settings access
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            \SFX\SFXBricksChildAdmin::$menu_slug,
            self::$page_title,
            self::$page_title,
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        // Block direct URL access for unauthorized users
        \SFX\AccessControl::die_if_unauthorized_theme();
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Optimizer Options', 'sfxtheme'); ?></h1>
            <?php
            $groups = [
                'performance' => __('Performance', 'sfx'),
                'admin'       => __('Admin Enhancements', 'sfx'),
                'security'    => __('Security & Privacy', 'sfx'),
                'frontend'    => __('Frontend Cleanup', 'sfx'),
                'media'       => __('Media & Uploads', 'sfx'),
            ];
            $fields = \SFX\WPOptimizer\Settings::get_fields();
            $options = get_option('sfx_wpoptimizer_options', []);
            ?>
            <div id="sfx-wpoptimizer-tabs">
                <div class="sfx-tabs-nav">
                    <?php $first = true;
                    foreach ($groups as $group_key => $group_label): ?>
                        <button type="button" class="sfx-tab-btn<?php if ($first) echo ' active'; ?>" data-tab="<?php echo esc_attr($group_key); ?>">
                            <?php echo esc_html($group_label); ?>
                        </button>
                    <?php $first = false;
                    endforeach; ?>
                </div>
                <form method="post" action="options.php">
                    <?php settings_fields(\SFX\WPOptimizer\Settings::$OPTION_GROUP); ?>
                    <div class="sfx-tabs-content">
                        <?php $first = true;
                        foreach ($groups as $group_key => $group_label):
                            $group_fields = array_filter($fields, fn($f) => ($f['group'] ?? '') === $group_key);
                            if (empty($group_fields)) continue;
                        ?>
                            <div id="<?php echo esc_attr($group_key); ?>" class="sfx-tab-content" <?php if (!$first) echo ' style="display:none;"'; ?>>
                                <div style="display: flex; flex-wrap: wrap; gap: 24px;">
                                    <?php
                                    $i = 0;
                                    while ($i < count($group_fields)):
                                        $field = array_values($group_fields)[$i];
                                        $id = esc_attr($field['id']);
                                        $type = $field['type'] ?? 'checkbox';
                                        $value = $options[$id] ?? $field['default'];

                                        // Check if this is a conditional field
                                        $is_conditional = isset($field['conditional']);
                                        $should_show = true;

                                        if ($is_conditional) {
                                            $dep_field = $field['conditional']['field'];
                                            $operator = $field['conditional']['operator'];
                                            $dep_value = $field['conditional']['value'] ?? null;
                                            $dep_field_value = $options[$dep_field] ?? 0;

                                            $should_show = self::evaluate_condition($dep_field_value, $operator, $dep_value);
                                            $display_style = $should_show ? 'flex' : 'none';

                                            echo '<div id="' . $id . '_container" style="display: ' . $display_style . ';">';
                                        }

                                        // Check if next field is also conditional (for combining display)
                                        $next_field = null;
                                        $next_is_conditional = false;
                                        if ($i + 1 < count($group_fields)) {
                                            $next_field = array_values($group_fields)[$i + 1];
                                            $next_is_conditional = isset($next_field['conditional']);
                                        }

                                        // If current and next are both conditional and depend on the same field, combine them
                                        $combine_with_next = $is_conditional && $next_is_conditional &&
                                            $field['conditional']['field'] === $next_field['conditional']['field'];
                                    ?>
                                        <div style="flex: 1 1 33%; min-width: 220px; max-width: 350px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between;">
                                            <h2 style="margin-top:0; font-size: 1.1em;"><?php echo esc_html($field['label']); ?></h2>
                                            <p style="font-size: 0.97em; color: #555;margin-top: 0; margin-bottom: auto;"><?php echo esc_html($field['description']); ?></p>
                                            <?php if ($type === 'checkbox'): ?>
                                                <input type="checkbox" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="1" <?php checked((int)$value, 1); ?> style="margin-top: 32px;" />
                                            <?php elseif ($type === 'number'):
                                                $min = isset($field['min']) ? (int)$field['min'] : 0;
                                                $max = isset($field['max']) ? (int)$field['max'] : 10;
                                            ?>
                                                <input type="number" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="<?php echo esc_attr($value); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" style="margin-top: 16px;" />

                                            <?php elseif ($type === 'post_types'):
                                                echo self::render_post_types_accordion($id, $value, $options);
                                            endif; ?>

                                            <?php if ($combine_with_next && $next_field): ?>
                                                <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e5e5;">
                                                <h3 style="margin-top: 0; font-size: 1em;"><?php echo esc_html($next_field['label']); ?></h3>
                                                <p style="font-size: 0.97em; color: #555; margin-bottom: 16px;"><?php echo esc_html($next_field['description']); ?></p>
                                                <?php if ($next_field['type'] === 'post_types'): ?>
                                                    <?php echo self::render_post_types_accordion($next_field['id'], $options[$next_field['id']] ?? [], $options); ?>
                                                <?php endif; ?>
                                                <?php $i++; // Skip the next field since we've already rendered it 
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php
                                        if ($is_conditional) {
                                            echo '</div>';
                                        }
                                        $i++;
                                    endwhile;
                                    ?>
                                </div>
                            </div>
                        <?php $first = false;
                        endforeach; ?>
                    </div>
                    <?php submit_button(); ?>
                </form>
            </div>



            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Generic conditional field handler
                    const conditionalFields = <?php echo json_encode(self::get_conditional_fields()); ?>;

                    function evaluateCondition(value, operator, expectedValue = null) {
                        switch (operator) {
                            case 'checked':
                                return Boolean(value);
                            case '!checked':
                                return !Boolean(value);
                            case 'equals':
                                return value == expectedValue;
                            case '!equals':
                                return value != expectedValue;
                            case 'in_array':
                                return Array.isArray(expectedValue) && expectedValue.includes(value);
                            case '!in_array':
                                return Array.isArray(expectedValue) && !expectedValue.includes(value);
                            default:
                                return true;
                        }
                    }

                    function initializeConditionalFields() {
                        conditionalFields.forEach(config => {
                            const depCheckbox = document.getElementById(config.dependency);
                            const targetContainer = document.getElementById(config.target + '_container');

                            if (depCheckbox && targetContainer) {
                                const toggleFunction = () => {
                                    const shouldShow = evaluateCondition(depCheckbox.checked, config.operator, config.value);
                                    targetContainer.style.display = shouldShow ? 'flex' : 'none';
                                };

                                depCheckbox.addEventListener('change', toggleFunction);
                                toggleFunction(); // Set initial state
                            }
                        });
                    }

                    // Initialize all conditional fields
                    initializeConditionalFields();
                });
            </script>
        </div>
<?php
    }
}
