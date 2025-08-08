<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class AdminPage
{
    public static $menu_slug = 'sfx-wp-optimizer';
    public static $page_title = 'WP Optimizer';
    public static $description = 'Toggle a wide range of WordPress optimizations (disable search, comments, REST API, feeds, version numbers, etc.) for performance and security.';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
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
                                <?php 
                                $i = 0;
                                while ($i < count($group_fields)):
                                    $field = array_values($group_fields)[$i];
                                    $id = esc_attr($field['id']);
                                    $type = $field['type'] ?? 'checkbox';
                                    $value = $options[$id] ?? $field['default'];
                                    
                                    // Check if this is a conditional field that depends on limit_revisions
                                    $is_conditional = in_array($id, ['limit_revisions_number', 'limit_revisions_post_types']);
                                    $limit_revisions_enabled = $options['limit_revisions'] ?? 1;
                                    
                                    // Check if next field is also conditional
                                    $next_field = null;
                                    $next_is_conditional = false;
                                    if ($i + 1 < count($group_fields)) {
                                        $next_field = array_values($group_fields)[$i + 1];
                                        $next_is_conditional = in_array($next_field['id'], ['limit_revisions_number', 'limit_revisions_post_types']);
                                    }
                                    
                                    // If current and next are both conditional, combine them
                                    $combine_with_next = $is_conditional && $next_is_conditional;
                                    
                                    if ($is_conditional) {
                                        $display_style = $limit_revisions_enabled ? 'block' : 'none';
                                        echo '<div id="' . $id . '_container" style="display: ' . $display_style . ';">';
                                    }
                                ?>
                                <div style="flex: 1 1 33%; min-width: 220px; max-width: 350px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between;">
                                    <h2 style="margin-top:0; font-size: 1.1em;"><?php echo esc_html($field['label']); ?></h2>
                                    <p style="font-size: 0.97em; color: #555;margin-top: 0; margin-bottom: auto;"><?php echo esc_html($field['description']); ?></p>
                                    <?php if ($type === 'checkbox'): ?>
                                        <input type="checkbox" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="1" <?php checked((int)$value, 1); ?> style="margin-top: 32px;"/>
                                    <?php elseif ($type === 'number'):
                                        $min = isset($field['min']) ? (int)$field['min'] : 0;
                                        $max = isset($field['max']) ? (int)$field['max'] : 10;
                                    ?>
                                        <input type="number" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="<?php echo esc_attr($value); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" style="margin-top: 16px;"/>
                                    <?php elseif ($type === 'post_types'):
                                        $post_types = get_post_types(['public' => true], 'objects');
                                        $selected_post_types = [];
                                        if (is_array($value)) {
                                            $selected_post_types = $value;
                                        }
                                    ?>
                                        <div class="post-types-selection">
                                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                                                <?php foreach ($post_types as $post_type => $post_type_obj): ?>
                                                    <label style="display: block; margin-bottom: 5px;">
                                                        <input type="checkbox" 
                                                               name="sfx_wpoptimizer_options[<?php echo $id; ?>][]" 
                                                               value="<?php echo esc_attr($post_type); ?>"
                                                               <?php checked(in_array($post_type, $selected_post_types)); ?> />
                                                        <strong><?php echo esc_html($post_type_obj->labels->name); ?></strong>
                                                        <small>(<?php echo esc_html($post_type); ?>)</small>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <p><small><?php _e('Leave all unchecked to apply to all post types.', 'sfx'); ?></small></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($combine_with_next && $next_field): ?>
                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e5e5;">
                                        <h3 style="margin-top: 0; font-size: 1em;"><?php echo esc_html($next_field['label']); ?></h3>
                                        <p style="font-size: 0.97em; color: #555; margin-bottom: 16px;"><?php echo esc_html($next_field['description']); ?></p>
                                        <?php if ($next_field['type'] === 'post_types'):
                                            $next_post_types = get_post_types(['public' => true], 'objects');
                                            $next_selected_post_types = [];
                                            if (isset($options[$next_field['id']]) && is_array($options[$next_field['id']])) {
                                                $next_selected_post_types = $options[$next_field['id']];
                                            }
                                        ?>
                                            <details class="post-types-accordion" style="margin-top: 10px;">
                                                <summary>
                                                    <?php _e('Select Post Types', 'sfx'); ?> 
                                                    <span>▼</span>
                                                </summary>
                                                <div style="border: 1px solid #ddd; border-top: none; padding: 10px; background: #f9f9f9; max-height: 200px; overflow-y: auto;">
                                                    <?php foreach ($next_post_types as $post_type => $post_type_obj): ?>
                                                        <label style="display: block; margin-bottom: 8px; padding: 4px 0;">
                                                            <input type="checkbox" 
                                                                   name="sfx_wpoptimizer_options[<?php echo esc_attr($next_field['id']); ?>][]" 
                                                                   value="<?php echo esc_attr($post_type); ?>"
                                                                   <?php checked(in_array($post_type, $next_selected_post_types)); ?> />
                                                            <strong><?php echo esc_html($post_type_obj->labels->name); ?></strong>
                                                            <small style="color: #666;">(<?php echo esc_html($post_type); ?>)</small>
                                                        </label>
                                                    <?php endforeach; ?>
                                                    <p style="margin: 10px 0 0 0; font-size: 0.9em; color: #666; border-top: 1px solid #ddd; padding-top: 8px;">
                                                        <small><?php _e('Leave all unchecked to apply to all post types.', 'sfx'); ?></small>
                                                    </p>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                        <?php $i++; // Skip the next field since we've already rendered it ?>
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
                        <?php $first = false; endforeach; ?>
                    </div>
                    <?php submit_button(); ?>
                </form>
            </div>
            

            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const limitRevisionsCheckbox = document.getElementById('limit_revisions');
                const revisionNumberContainer = document.getElementById('limit_revisions_number_container');
                const revisionPostTypesContainer = document.getElementById('limit_revisions_post_types_container');
                
                function toggleRevisionFields() {
                    const isEnabled = limitRevisionsCheckbox && limitRevisionsCheckbox.checked;
                    
                    if (revisionNumberContainer) {
                        revisionNumberContainer.style.display = isEnabled ? 'block' : 'none';
                    }
                    if (revisionPostTypesContainer) {
                        revisionPostTypesContainer.style.display = isEnabled ? 'block' : 'none';
                    }
                }
                
                if (limitRevisionsCheckbox) {
                    limitRevisionsCheckbox.addEventListener('change', toggleRevisionFields);
                    // Set initial state
                    toggleRevisionFields();
                }
            });
            </script>
        </div>
        <?php
    }
} 