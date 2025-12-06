<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

/**
 * Registers the 'sfx_social_account' post type.
 */
class PostType
{
    /**
     * Custom post type slug.
     *
     * @var string
     */
    public static string $post_type = 'sfx_social_account';

    /**
     * Initialize post type and meta box hooks.
     */
    public static function init(): void
    {
        // Register post type through consolidated system
        add_action('sfx_init_post_types', [self::class, 'register_post_type']);
        
        // Register meta boxes and save operations (these need to be on their specific hooks)
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('save_post_' . self::$post_type, [self::class, 'save_custom_fields']);
        
        // Register admin columns
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_icon_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_icon_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_link_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_link_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_status_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_status_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'remove_date_column']);
    }

    /**
     * Register the custom post type.
     */
    public static function register_post_type(): void
    {
        $labels = [
            'name'                  => __('Social Media Accounts', 'sfxtheme'),
            'singular_name'         => __('Social Account', 'sfxtheme'),
            'add_new'               => __('Add New', 'sfxtheme'),
            'add_new_item'          => __('Add New Social Account', 'sfxtheme'),
            'edit_item'             => __('Edit Social Account', 'sfxtheme'),
            'new_item'              => __('New Social Account', 'sfxtheme'),
            'view_item'             => __('View Social Account', 'sfxtheme'),
            'search_items'          => __('Search Social Accounts', 'sfxtheme'),
            'not_found'             => __('No social accounts found', 'sfxtheme'),
            'not_found_in_trash'    => __('No social accounts found in Trash', 'sfxtheme'),
            'all_items'             => __('All Social Accounts', 'sfxtheme'),
            'menu_name'             => __('Social Accounts', 'sfxtheme'),
            'name_admin_bar'        => __('Social Account', 'sfxtheme'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_in_menu'       => false, // Will be added as submenu under theme admin
            'show_in_rest'       => true,
            'supports'           => ['title'],
            'has_archive'        => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'show_ui'            => true,
            // Additional privacy settings
            'publicly_queryable' => false,
            'query_var'          => false,
            'exclude_from_search' => true,
            'show_in_nav_menus' => false,
        ];

        register_post_type(self::$post_type, $args);
        
        // Register meta fields
        self::register_meta_fields();
    }

    /**
     * Register meta fields for social accounts
     */
    private static function register_meta_fields(): void
    {
        $fields = ['icon_image', 'link_url', 'link_title', 'link_target'];
        
        \SFX\MetaFieldManager::register_fields(self::$post_type, $fields);
        
        // Add validation and cleanup hooks
        add_action('save_post_' . self::$post_type, [self::class, 'validate_meta_fields']);
        add_action('delete_post_' . self::$post_type, [self::class, 'cleanup_meta_fields']);
    }

    /**
     * Validate social account meta fields
     * 
     * @param int $post_id
     */
    public static function validate_meta_fields(int $post_id): void
    {
        $validation_rules = [
            '_link_url' => 'esc_url_raw',
            '_link_target' => function($value) {
                return in_array($value, ['_blank', '_self']) ? $value : '_blank';
            }
        ];
        
        \SFX\MetaFieldManager::validate_fields($post_id, self::$post_type, $validation_rules);
    }

    /**
     * Cleanup social account meta fields
     * 
     * @param int $post_id
     */
    public static function cleanup_meta_fields(int $post_id): void
    {
        $expected_fields = ['_icon_image', '_link_url', '_link_title', '_link_target'];
        
        \SFX\MetaFieldManager::cleanup_fields($post_id, self::$post_type, $expected_fields);
    }

    /**
     * Register the meta box for social account configuration fields.
     */
    public static function register_meta_box(): void
    {
        add_meta_box(
            'sfx_social_account_config',
            __('Social Account Configuration', 'sfxtheme'),
            [self::class, 'render_meta_box'],
            self::$post_type,
            'normal',
            'high'
        );
    }

    /**
     * Render the meta box UI for social account configuration fields.
     *
     * @param \WP_Post $post
     */
    public static function render_meta_box($post): void
    {
        wp_nonce_field('sfx_social_account_config_nonce', 'sfx_social_account_config_nonce');
        
        // Get saved values
        $icon_image = get_post_meta($post->ID, '_icon_image', true) ?: '';
        $link_url = get_post_meta($post->ID, '_link_url', true) ?: '';
        $link_title = get_post_meta($post->ID, '_link_title', true) ?: '';
        $link_target = get_post_meta($post->ID, '_link_target', true) ?: '_blank';
        ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="icon_image"><?php esc_html_e('Icon Image', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <div class="sfx-file-input-group">
                        <input type="text" name="icon_image" id="icon_image" value="<?php echo esc_attr($icon_image); ?>" class="regular-text" placeholder="<?php esc_attr_e('Image URL', 'sfxtheme'); ?>" />
                        <button type="button" class="button" id="upload_icon_image"><?php esc_html_e('Upload Image', 'sfxtheme'); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e('Upload an icon image (preferably SVG) for this social media account.', 'sfxtheme'); ?></p>
                    <?php if (!empty($icon_image)) : ?>
                        <div class="sfx-image-preview">
                            <img src="<?php echo esc_url($icon_image); ?>" alt="<?php esc_attr_e('Icon Preview', 'sfxtheme'); ?>" style="max-width: 50px; max-height: 50px; margin-top: 10px;" />
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="link_url"><?php esc_html_e('Link URL', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="url" name="link_url" id="link_url" value="<?php echo esc_url($link_url); ?>" class="regular-text" placeholder="https://example.com" />
                    <p class="description"><?php esc_html_e('The URL to the social media profile.', 'sfxtheme'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="link_title"><?php esc_html_e('Link Title', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="text" name="link_title" id="link_title" value="<?php echo esc_attr($link_title); ?>" class="regular-text" placeholder="<?php esc_attr_e('Follow us on...', 'sfxtheme'); ?>" />
                    <p class="description"><?php esc_html_e('Optional title attribute for the link.', 'sfxtheme'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="link_target"><?php esc_html_e('Link Target', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="link_target" id="link_target">
                        <option value="_blank" <?php selected($link_target, '_blank'); ?>><?php esc_html_e('Open in new tab', 'sfxtheme'); ?></option>
                        <option value="_self" <?php selected($link_target, '_self'); ?>><?php esc_html_e('Open in same tab', 'sfxtheme'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('How the link should open when clicked.', 'sfxtheme'); ?></p>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            $('#upload_icon_image').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var fileInput = $('#icon_image');
                
                var frame = wp.media({
                    title: '<?php esc_html_e('Select Icon Image', 'sfxtheme'); ?>',
                    button: {
                        text: '<?php esc_html_e('Use this image', 'sfxtheme'); ?>'
                    },
                    multiple: false
                });
                
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    fileInput.val(attachment.url);
                    
                    // Update preview
                    var preview = $('.sfx-image-preview');
                    if (preview.length === 0) {
                        preview = $('<div class="sfx-image-preview"></div>');
                        fileInput.parent().after(preview);
                    }
                    preview.html('<img src="' + attachment.url + '" alt="<?php esc_attr_e('Icon Preview', 'sfxtheme'); ?>" style="max-width: 50px; max-height: 50px; margin-top: 10px;" />');
                });
                
                frame.open();
            });
        });
        </script>
        <?php
    }

    /**
     * Save the custom fields for the social account post type.
     *
     * @param int $post_id
     */
    public static function save_custom_fields(int $post_id): void
    {
        if (!isset($_POST['sfx_social_account_config_nonce']) || !wp_verify_nonce($_POST['sfx_social_account_config_nonce'], 'sfx_social_account_config_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save social account configuration fields
        $fields = [
            'icon_image' => esc_url_raw($_POST['icon_image'] ?? ''),
            'link_url' => esc_url_raw($_POST['link_url'] ?? ''),
            'link_title' => sanitize_text_field($_POST['link_title'] ?? ''),
            'link_target' => sanitize_text_field($_POST['link_target'] ?? '_blank'),
        ];

        foreach ($fields as $key => $value) {
            update_post_meta($post_id, '_' . $key, $value);
        }
    }

    /**
     * Add a custom column for icon.
     *
     * @param array $columns
     * @return array
     */
    public static function add_icon_column(array $columns): array
    {
        $date = $columns['date'] ?? null;
        unset($columns['date']);
        $columns['icon'] = __('Icon', 'sfxtheme');
        if ($date !== null) {
            $columns['date'] = $date;
        }
        return $columns;
    }

    /**
     * Add a custom column for link.
     *
     * @param array $columns
     * @return array
     */
    public static function add_link_column(array $columns): array
    {
        $columns['link'] = __('Link', 'sfxtheme');
        return $columns;
    }

    /**
     * Add a custom column for status.
     *
     * @param array $columns
     * @return array
     */
    public static function add_status_column(array $columns): array
    {
        $columns['status'] = __('Status', 'sfxtheme');
        return $columns;
    }

    /**
     * Render the icon column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_icon_column(string $column, int $post_id): void
    {
        if ($column === 'icon') {
            $icon_image = get_post_meta($post_id, '_icon_image', true);
            if (!empty($icon_image)) {
                echo '<img src="' . esc_url($icon_image) . '" alt="Icon" style="max-width: 30px; max-height: 30px;" />';
            } else {
                echo '<span class="no-icon">' . esc_html__('No icon', 'sfxtheme') . '</span>';
            }
        }
    }

    /**
     * Render the link column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_link_column(string $column, int $post_id): void
    {
        if ($column === 'link') {
            $link_url = get_post_meta($post_id, '_link_url', true);
            if (!empty($link_url)) {
                echo '<a href="' . esc_url($link_url) . '" target="_blank" rel="noopener noreferrer">' . esc_url($link_url) . '</a>';
            } else {
                echo '<span class="no-link">' . esc_html__('No link', 'sfxtheme') . '</span>';
            }
        }
    }

    /**
     * Render the status column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_status_column(string $column, int $post_id): void
    {
        if ($column === 'status') {
            $post = get_post($post_id);
            $status = $post->post_status;
            $status_labels = [
                'publish' => __('Active', 'sfxtheme'),
                'draft' => __('Draft', 'sfxtheme'),
                'private' => __('Private', 'sfxtheme'),
            ];
            echo '<span class="status-' . esc_attr($status) . '">' . esc_html($status_labels[$status] ?? $status) . '</span>';
        }
    }

    /**
     * Remove the date column.
     *
     * @param array $columns
     * @return array
     */
    public static function remove_date_column(array $columns): array
    {
        unset($columns['date']);
        return $columns;
    }
} 