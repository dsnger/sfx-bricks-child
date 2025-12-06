<?php

declare(strict_types=1);

namespace SFX\CustomScriptsManager;

/**
 * Registers the 'sfx_custom_script' post type and 'sfx_script_category' taxonomy.
 */
class PostType
{
    /**
     * Custom post type slug.
     *
     * @var string
     */
    public static string $post_type = 'sfx_custom_script';

    /**
     * Custom taxonomy slug.
     *
     * @var string
     */
    public static string $taxonomy = 'sfx_script_category';

    /**
     * Initialize post type, taxonomy, and meta box hooks.
     */
    public static function init(): void
    {
        // Register post type and taxonomy through consolidated system
        add_action('sfx_init_post_types', [self::class, 'register_post_type']);
        add_action('sfx_init_post_types', [self::class, 'register_taxonomy']);

        // Register meta boxes and save operations (these need to be on their specific hooks)
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('save_post_' . self::$post_type, [self::class, 'save_custom_fields']);

        // Register admin columns
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_script_type_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_script_type_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_location_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_location_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_priority_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_priority_column'], 10, 2);
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
            'name'                  => __('Custom Scripts', 'sfxtheme'),
            'singular_name'         => __('Custom Script', 'sfxtheme'),
            'add_new'               => __('Add New', 'sfxtheme'),
            'add_new_item'          => __('Add New Custom Script', 'sfxtheme'),
            'edit_item'             => __('Edit Custom Script', 'sfxtheme'),
            'new_item'              => __('New Custom Script', 'sfxtheme'),
            'view_item'             => __('View Custom Script', 'sfxtheme'),
            'search_items'          => __('Search Custom Scripts', 'sfxtheme'),
            'not_found'             => __('No custom scripts found', 'sfxtheme'),
            'not_found_in_trash'    => __('No custom scripts found in Trash', 'sfxtheme'),
            'all_items'             => __('All Custom Scripts', 'sfxtheme'),
            'menu_name'             => __('Custom Scripts', 'sfxtheme'),
            'name_admin_bar'        => __('Custom Script', 'sfxtheme'),
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
     * Register meta fields for custom scripts
     */
    private static function register_meta_fields(): void
    {
        $fields = [
            'script_type',
            'location',
            'include_type',
            'frontend_only',
            'script_source_type',
            'dependencies',
            'priority',
            'script_file',
            'script_cdn',
            'script_content',
            'include_posts',
            'include_pages',
            'exclude_posts',
            'exclude_pages'
        ];

        // Fields that should allow HTML content
        $html_fields = ['script_content'];

        \SFX\MetaFieldManager::register_fields(self::$post_type, $fields, $html_fields);

        // Add validation and cleanup hooks
        add_action('save_post_' . self::$post_type, [self::class, 'validate_meta_fields']);
        add_action('delete_post_' . self::$post_type, [self::class, 'cleanup_meta_fields']);
    }

    /**
     * Validate custom script meta fields
     * 
     * @param int $post_id
     */
    public static function validate_meta_fields(int $post_id): void
    {
        $validation_rules = [
            '_script_type' => function ($value) {
                return in_array($value, ['javascript', 'css']) ? $value : 'javascript';
            },
            '_location' => function ($value) {
                return in_array($value, ['header', 'footer']) ? $value : 'footer';
            },
            '_priority' => 'absint'
        ];

        \SFX\MetaFieldManager::validate_fields($post_id, self::$post_type, $validation_rules);
    }

    /**
     * Cleanup custom script meta fields
     * 
     * @param int $post_id
     */
    public static function cleanup_meta_fields(int $post_id): void
    {
        $post_type = get_post_type($post_id);
        if ($post_type !== self::$post_type) {
            return;
        }

        $expected_fields = [
            '_script_type',
            '_location',
            '_include_type',
            '_frontend_only',
            '_script_source_type',
            '_dependencies',
            '_priority',
            '_script_file',
            '_script_cdn',
            '_script_content',
            '_include_posts',
            '_include_pages',
            '_exclude_posts',
            '_exclude_pages'
        ];

        \SFX\MetaFieldManager::cleanup_fields($post_id, self::$post_type, $expected_fields);
    }

    /**
     * Register the custom taxonomy for the post type.
     */
    public static function register_taxonomy(): void
    {
        $labels = [
            'name'              => __('Script Categories', 'sfxtheme'),
            'singular_name'     => __('Script Category', 'sfxtheme'),
            'search_items'      => __('Search Script Categories', 'sfxtheme'),
            'all_items'         => __('All Script Categories', 'sfxtheme'),
            'parent_item'       => __('Parent Category', 'sfxtheme'),
            'parent_item_colon' => __('Parent Category:', 'sfxtheme'),
            'edit_item'         => __('Edit Category', 'sfxtheme'),
            'update_item'       => __('Update Category', 'sfxtheme'),
            'add_new_item'      => __('Add New Category', 'sfxtheme'),
            'new_item_name'     => __('New Category Name', 'sfxtheme'),
            'menu_name'         => __('Script Categories', 'sfxtheme'),
        ];

        $args = [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => false,
        ];

        register_taxonomy(
            self::$taxonomy,
            [self::$post_type],
            $args
        );
    }

    /**
     * Register the meta box for script configuration fields.
     */
    public static function register_meta_box(): void
    {
        add_meta_box(
            'sfx_custom_script_config',
            __('Script Configuration', 'sfxtheme'),
            [self::class, 'render_meta_box'],
            self::$post_type,
            'normal',
            'high'
        );
    }

    /**
     * Render the meta box UI for script configuration fields.
     *
     * @param \WP_Post $post
     */
    public static function render_meta_box($post): void
    {
        wp_nonce_field('sfx_custom_script_config_nonce', 'sfx_custom_script_config_nonce');

        // Get saved values
        $script_type = get_post_meta($post->ID, '_script_type', true) ?: 'javascript';
        $location = get_post_meta($post->ID, '_location', true) ?: 'footer';
        $include_type = get_post_meta($post->ID, '_include_type', true) ?: 'enqueue';
        $frontend_only = get_post_meta($post->ID, '_frontend_only', true) ?: '1';
        $script_source_type = get_post_meta($post->ID, '_script_source_type', true) ?: 'file';
        $script_file = get_post_meta($post->ID, '_script_file', true) ?: '';
        $script_cdn = get_post_meta($post->ID, '_script_cdn', true) ?: '';
        $script_content = get_post_meta($post->ID, '_script_content', true) ?: '';
        $dependencies = get_post_meta($post->ID, '_dependencies', true) ?: '';
        $priority = get_post_meta($post->ID, '_priority', true) ?: '10';

        // Conditional loading fields
        $include_posts = get_post_meta($post->ID, '_include_posts', true) ?: [];
        $include_pages = get_post_meta($post->ID, '_include_pages', true) ?: [];
        $exclude_posts = get_post_meta($post->ID, '_exclude_posts', true) ?: [];
        $exclude_pages = get_post_meta($post->ID, '_exclude_pages', true) ?: [];
?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="script_type"><?php esc_html_e('Script Type', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="script_type" id="script_type">
                        <option value="javascript" <?php selected($script_type, 'javascript'); ?>><?php esc_html_e('JavaScript', 'sfxtheme'); ?></option>
                        <option value="css" <?php selected($script_type, 'css'); ?>><?php esc_html_e('CSS', 'sfxtheme'); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="location"><?php esc_html_e('Location', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="location" id="location">
                        <option value="header" <?php selected($location, 'header'); ?>><?php esc_html_e('Header', 'sfxtheme'); ?></option>
                        <option value="footer" <?php selected($location, 'footer'); ?>><?php esc_html_e('Footer', 'sfxtheme'); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="include_type"><?php esc_html_e('Include Type', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="include_type" id="include_type">
                        <option value="enqueue" <?php selected($include_type, 'enqueue'); ?>><?php esc_html_e('Enqueue', 'sfxtheme'); ?></option>
                        <option value="register" <?php selected($include_type, 'register'); ?>><?php esc_html_e('Register', 'sfxtheme'); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="frontend_only"><?php esc_html_e('Frontend Only', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="frontend_only" id="frontend_only" value="1" <?php checked($frontend_only, '1'); ?> class="sfx-toggle" />
                    <span class="description"><?php esc_html_e('Only load on frontend (not in admin)', 'sfxtheme'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="script_source_type"><?php esc_html_e('Script Source Type', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="script_source_type" id="script_source_type">
                        <option value="file" <?php selected($script_source_type, 'file'); ?>><?php esc_html_e('Upload File', 'sfxtheme'); ?></option>
                        <option value="cdn" <?php selected($script_source_type, 'cdn'); ?>><?php esc_html_e('CDN Link', 'sfxtheme'); ?></option>
                        <option value="cdn_file" <?php selected($script_source_type, 'cdn_file'); ?>><?php esc_html_e('Upload from CDN', 'sfxtheme'); ?></option>
                        <option value="inline" <?php selected($script_source_type, 'inline'); ?>><?php esc_html_e('Inline Code', 'sfxtheme'); ?></option>
                    </select>
                </td>
            </tr>

            <tr class="script-file-row" style="<?php echo $script_source_type === 'file' ? '' : 'display: none;'; ?>">
                <th scope="row">
                    <label for="script_file"><?php esc_html_e('Script File', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="text" name="script_file" id="script_file" value="<?php echo esc_attr($script_file); ?>" class="regular-text" />
                    <button type="button" class="button" id="upload_script_file"><?php esc_html_e('Upload File', 'sfxtheme'); ?></button>
                </td>
            </tr>

            <tr class="script-cdn-row" style="<?php echo in_array($script_source_type, ['cdn', 'cdn_file']) ? '' : 'display: none;'; ?>">
                <th scope="row">
                    <label for="script_cdn"><?php esc_html_e('CDN URL', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="url" name="script_cdn" id="script_cdn" value="<?php echo esc_url($script_cdn); ?>" class="regular-text" />
                </td>
            </tr>

            <tr class="script-content-row" style="<?php echo $script_source_type === 'inline' ? '' : 'display: none;'; ?>">
                <th scope="row">
                    <label for="script_content"><?php esc_html_e('Script Content', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <textarea name="script_content" id="script_content" rows="10" class="large-text code"><?php echo esc_textarea($script_content); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="dependencies"><?php esc_html_e('Dependencies', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="text" name="dependencies" id="dependencies" value="<?php echo esc_attr($dependencies); ?>" class="regular-text" /><br>
                    <span class="description"><?php esc_html_e('Comma-separated list of script handles (e.g., jquery, wp-util)', 'sfxtheme'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="priority"><?php esc_html_e('Priority', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <input type="number" name="priority" id="priority" value="<?php echo esc_attr($priority); ?>" class="small-text" min="1" max="999" />
                    <span class="description"><?php esc_html_e('Loading priority (1 = highest, 999 = lowest). Lower numbers load first.', 'sfxtheme'); ?></span>
                </td>
            </tr>



            <tr>
                <th scope="row" colspan="2">
                    <h3><?php esc_html_e('Conditional Loading', 'sfxtheme'); ?></h3>
                    <p class="description"><?php esc_html_e('Control when this script should be loaded based on specific pages or page types.', 'sfxtheme'); ?></p>
                </th>
            </tr>

            <tr>
                <th scope="row">
                    <label for="include_posts"><?php esc_html_e('Include only for specific posts', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="include_posts[]" id="include_posts" class="sfx-select2" multiple="multiple" style="width: 100%;">
                        <?php
                        $posts = get_posts([
                            'post_type' => ['post', 'page'],
                            'post_status' => 'publish',
                            'numberposts' => -1,
                            'orderby' => 'title',
                            'order' => 'ASC'
                        ]);

                        // Group posts by post type
                        $grouped_posts = [];
                        foreach ($posts as $post_item) {
                            $post_type_obj = get_post_type_object($post_item->post_type);
                            $post_type_label = $post_type_obj ? $post_type_obj->labels->name : ucfirst($post_item->post_type);
                            $grouped_posts[$post_type_label][] = $post_item;
                        }

                        foreach ($grouped_posts as $group_label => $group_posts) {
                            echo '<optgroup label="' . esc_attr($group_label) . '">';
                            foreach ($group_posts as $post_item) {
                                $selected = in_array($post_item->ID, $include_posts) ? 'selected' : '';
                                echo '<option value="' . esc_attr($post_item->ID) . '" ' . $selected . '>' . esc_html($post_item->post_title) . '</option>';
                            }
                            echo '</optgroup>';
                        }
                        ?>
                    </select>
                    <span class="description"><?php esc_html_e('Select specific posts/pages where this script should be loaded. Leave empty to load on all pages.', 'sfxtheme'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="include_pages"><?php esc_html_e('Include only for specific page types', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="include_pages[]" id="include_pages" class="sfx-select2" multiple="multiple" style="width: 100%;">
                        <?php
                        // Standard page types
                        $standard_page_types = [
                            'front_page' => __('Front Page', 'sfxtheme'),
                            'home' => __('Blog Page', 'sfxtheme'),
                            'single' => __('Single Posts', 'sfxtheme'),
                            'page' => __('Pages', 'sfxtheme'),
                            'archive' => __('All Archives', 'sfxtheme'),
                            'search' => __('Search Results', 'sfxtheme'),
                            '404' => __('404 Page', 'sfxtheme'),
                        ];

                        // Group by category
                        echo '<optgroup label="' . esc_attr__('Standard Pages', 'sfxtheme') . '">';
                        foreach ($standard_page_types as $value => $label) {
                            $selected = in_array($value, $include_pages) ? 'selected' : '';
                            echo '<option value="' . esc_attr($value) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                        }
                        echo '</optgroup>';

                        // Custom post types - create separate group for each post type
                        $post_types = get_post_types(['public' => true], 'objects');
                        foreach ($post_types as $post_type) {
                            if ($post_type->name !== 'post' && $post_type->name !== 'page') {
                                echo '<optgroup label="' . esc_attr($post_type->labels->name) . '">';
                                echo '<option value="single_' . esc_attr($post_type->name) . '" ' . (in_array('single_' . $post_type->name, $include_pages) ? 'selected' : '') . '>' . sprintf(__('Single %s', 'sfxtheme'), $post_type->labels->singular_name) . '</option>';
                                echo '<option value="archive_' . esc_attr($post_type->name) . '" ' . (in_array('archive_' . $post_type->name, $include_pages) ? 'selected' : '') . '>' . sprintf(__('%s Archive', 'sfxtheme'), $post_type->labels->name) . '</option>';
                                echo '</optgroup>';
                            }
                        }
                        ?>
                    </select>
                    <span class="description"><?php esc_html_e('Select page types where this script should be loaded. Leave empty to load on all page types.', 'sfxtheme'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="exclude_posts"><?php esc_html_e('Exclude for specific posts', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="exclude_posts[]" id="exclude_posts" class="sfx-select2" multiple="multiple" style="width: 100%;">
                        <?php
                        // Group posts by post type
                        $grouped_posts = [];
                        foreach ($posts as $post_item) {
                            $post_type_obj = get_post_type_object($post_item->post_type);
                            $post_type_label = $post_type_obj ? $post_type_obj->labels->name : ucfirst($post_item->post_type);
                            $grouped_posts[$post_type_label][] = $post_item;
                        }

                        foreach ($grouped_posts as $group_label => $group_posts) {
                            echo '<optgroup label="' . esc_attr($group_label) . '">';
                            foreach ($group_posts as $post_item) {
                                $selected = in_array($post_item->ID, $exclude_posts) ? 'selected' : '';
                                echo '<option value="' . esc_attr($post_item->ID) . '" ' . $selected . '>' . esc_html($post_item->post_title) . '</option>';
                            }
                            echo '</optgroup>';
                        }
                        ?>
                    </select>
                    <span class="description"><?php esc_html_e('Select specific posts/pages where this script should NOT be loaded.', 'sfxtheme'); ?></span>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="exclude_pages"><?php esc_html_e('Exclude for specific page types', 'sfxtheme'); ?></label>
                </th>
                <td>
                    <select name="exclude_pages[]" id="exclude_pages" class="sfx-select2" multiple="multiple" style="width: 100%;">
                        <?php
                        // Standard page types
                        $standard_page_types = [
                            'front_page' => __('Front Page', 'sfxtheme'),
                            'home' => __('Blog Page', 'sfxtheme'),
                            'single' => __('Single Posts', 'sfxtheme'),
                            'page' => __('Pages', 'sfxtheme'),
                            'archive' => __('All Archives', 'sfxtheme'),
                            'search' => __('Search Results', 'sfxtheme'),
                            '404' => __('404 Page', 'sfxtheme'),
                        ];

                        // Group by category
                        echo '<optgroup label="' . esc_attr__('Standard Pages', 'sfxtheme') . '">';
                        foreach ($standard_page_types as $value => $label) {
                            $selected = in_array($value, $exclude_pages) ? 'selected' : '';
                            echo '<option value="' . esc_attr($value) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                        }
                        echo '</optgroup>';

                        // Custom post types - create separate group for each post type
                        $post_types = get_post_types(['public' => true], 'objects');
                        foreach ($post_types as $post_type) {
                            if ($post_type->name !== 'post' && $post_type->name !== 'page') {
                                echo '<optgroup label="' . esc_attr($post_type->labels->name) . '">';
                                echo '<option value="single_' . esc_attr($post_type->name) . '" ' . (in_array('single_' . $post_type->name, $exclude_pages) ? 'selected' : '') . '>' . sprintf(__('Single %s', 'sfxtheme'), $post_type->labels->singular_name) . '</option>';
                                echo '<option value="archive_' . esc_attr($post_type->name) . '" ' . (in_array('archive_' . $post_type->name, $exclude_pages) ? 'selected' : '') . '>' . sprintf(__('%s Archive', 'sfxtheme'), $post_type->labels->name) . '</option>';
                                echo '</optgroup>';
                            }
                        }
                        ?>
                    </select>
                    <span class="description"><?php esc_html_e('Select page types where this script should NOT be loaded.', 'sfxtheme'); ?></span>
                </td>
            </tr>
        </table>

        <script>
            jQuery(document).ready(function($) {
                // Initialize Select2 for conditional loading fields
                $('.sfx-select2').select2({
                    placeholder: '<?php esc_html_e('Select options...', 'sfxtheme'); ?>',
                    allowClear: true,
                    width: '100%'
                });

                $('#script_source_type').on('change', function() {
                    var sourceType = $(this).val();
                    $('.script-file-row, .script-cdn-row, .script-content-row').hide();

                    if (sourceType === 'file') {
                        $('.script-file-row').show();
                    } else if (sourceType === 'cdn' || sourceType === 'cdn_file') {
                        $('.script-cdn-row').show();
                    } else if (sourceType === 'inline') {
                        $('.script-content-row').show();
                    }
                });

                $('#upload_script_file').on('click', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var fileInput = $('#script_file');

                    var frame = wp.media({
                        title: '<?php esc_html_e('Select Script File', 'sfxtheme'); ?>',
                        button: {
                            text: '<?php esc_html_e('Use this file', 'sfxtheme'); ?>'
                        },
                        multiple: false
                    });

                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        fileInput.val(attachment.url);
                    });

                    frame.open();
                });
            });
        </script>
<?php
    }

    /**
     * Save the custom fields for the custom script post type.
     *
     * @param int $post_id
     */
    public static function save_custom_fields(int $post_id): void
    {
        if (!isset($_POST['sfx_custom_script_config_nonce']) || !wp_verify_nonce($_POST['sfx_custom_script_config_nonce'], 'sfx_custom_script_config_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save script configuration fields
        $fields = [
            'script_type' => sanitize_text_field($_POST['script_type'] ?? 'javascript'),
            'location' => sanitize_text_field($_POST['location'] ?? 'footer'),
            'include_type' => sanitize_text_field($_POST['include_type'] ?? 'enqueue'),
            'frontend_only' => isset($_POST['frontend_only']) ? '1' : '0',
            'script_source_type' => sanitize_text_field($_POST['script_source_type'] ?? 'file'),
            'script_file' => esc_url_raw($_POST['script_file'] ?? ''),
            'script_cdn' => esc_url_raw($_POST['script_cdn'] ?? ''),
            'script_content' => wp_kses_post($_POST['script_content'] ?? ''),
            'dependencies' => sanitize_text_field($_POST['dependencies'] ?? ''),
            'priority' => absint($_POST['priority'] ?? 10),
        ];

        // Save conditional loading fields
        $conditional_fields = [
            'include_posts' => $_POST['include_posts'] ?? [],
            'include_pages' => $_POST['include_pages'] ?? [],
            'exclude_posts' => $_POST['exclude_posts'] ?? [],
            'exclude_pages' => $_POST['exclude_pages'] ?? [],
        ];

        foreach ($conditional_fields as $key => $value) {
            if (is_array($value)) {
                $sanitized_value = array_map('intval', array_filter($value, 'is_numeric'));
                update_post_meta($post_id, '_' . $key, $sanitized_value);
            }
        }

        foreach ($fields as $key => $value) {
            update_post_meta($post_id, '_' . $key, $value);
        }
    }

    /**
     * Add a custom column for script type.
     *
     * @param array $columns
     * @return array
     */
    public static function add_script_type_column(array $columns): array
    {
        $date = $columns['date'] ?? null;
        unset($columns['date']);
        $columns['script_type'] = __('Type', 'sfxtheme');
        if ($date !== null) {
            $columns['date'] = $date;
        }
        return $columns;
    }

    /**
     * Add a custom column for location.
     *
     * @param array $columns
     * @return array
     */
    public static function add_location_column(array $columns): array
    {
        $columns['location'] = __('Location', 'sfxtheme');
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
     * Add a custom column for priority.
     *
     * @param array $columns
     * @return array
     */
    public static function add_priority_column(array $columns): array
    {
        $columns['priority'] = __('Priority', 'sfxtheme');
        return $columns;
    }

    /**
     * Render the script type column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_script_type_column(string $column, int $post_id): void
    {
        if ($column === 'script_type') {
            $script_type = get_post_meta($post_id, '_script_type', true) ?: 'javascript';
            $type_labels = [
                'javascript' => __('JavaScript', 'sfxtheme'),
                'css' => __('CSS', 'sfxtheme'),
            ];
            echo '<span class="script-type-' . esc_attr($script_type) . '">' . esc_html($type_labels[$script_type] ?? $script_type) . '</span>';
        }
    }

    /**
     * Render the location column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_location_column(string $column, int $post_id): void
    {
        if ($column === 'location') {
            $location = get_post_meta($post_id, '_location', true) ?: 'footer';
            $location_labels = [
                'header' => __('Header', 'sfxtheme'),
                'footer' => __('Footer', 'sfxtheme'),
            ];
            echo '<span class="location-' . esc_attr($location) . '">' . esc_html($location_labels[$location] ?? $location) . '</span>';
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
     * Render the priority column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_priority_column(string $column, int $post_id): void
    {
        if ($column === 'priority') {
            $priority = get_post_meta($post_id, '_priority', true) ?: '10';
            $priority_class = 'priority-' . $priority;

            // Color coding based on priority
            if ($priority <= 5) {
                $priority_class .= ' priority-high';
            } elseif ($priority <= 15) {
                $priority_class .= ' priority-medium';
            } else {
                $priority_class .= ' priority-low';
            }

            echo '<span class="' . esc_attr($priority_class) . '">' . esc_html($priority) . '</span>';
        }
    }

    /**
     * Add a custom column for conditions.
     *
     * @param array $columns
     * @return array
     */
    public static function add_conditions_column(array $columns): array
    {
        $columns['conditions'] = __('Conditions', 'sfxtheme');
        return $columns;
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

    /**
     * Render the conditions column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_conditions_column(string $column, int $post_id): void
    {
        if ($column === 'conditions') {
            $include_posts = get_post_meta($post_id, '_include_posts', true) ?: [];
            $include_pages = get_post_meta($post_id, '_include_pages', true) ?: [];
            $exclude_posts = get_post_meta($post_id, '_exclude_posts', true) ?: [];
            $exclude_pages = get_post_meta($post_id, '_exclude_pages', true) ?: [];

            $conditions = [];

            // Check if any conditions are set
            if (empty($include_posts) && empty($include_pages) && empty($exclude_posts) && empty($exclude_pages)) {
                echo '<span class="conditions-none">' . esc_html__('All Pages', 'sfxtheme') . '</span>';
                return;
            }

            // Include conditions
            if (!empty($include_posts)) {
                $conditions[] = sprintf(
                    __('Include: %d posts/pages', 'sfxtheme'),
                    count($include_posts)
                );
            }

            if (!empty($include_pages)) {
                $conditions[] = sprintf(
                    __('Include: %d page types', 'sfxtheme'),
                    count($include_pages)
                );
            }

            // Exclude conditions
            if (!empty($exclude_posts)) {
                $conditions[] = sprintf(
                    __('Exclude: %d posts/pages', 'sfxtheme'),
                    count($exclude_posts)
                );
            }

            if (!empty($exclude_pages)) {
                $conditions[] = sprintf(
                    __('Exclude: %d page types', 'sfxtheme'),
                    count($exclude_pages)
                );
            }

            if (!empty($conditions)) {
                echo '<div class="conditions-summary">';
                foreach ($conditions as $condition) {
                    echo '<span class="condition-item">' . esc_html($condition) . '</span><br>';
                }
                echo '</div>';
            }
        }
    }
}
