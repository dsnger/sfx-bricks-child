<?php

declare(strict_types=1);

namespace SFX\TextSnippets;

/**
 * Registers the 'cpt_text_snippet' post type and 'cpt_text_snippet_cat' taxonomy.
 */
class PostType
{
    /**
     * Custom post type slug.
     *
     * @var string
     */
    public static string $post_type = 'cpt_text_snippet';

    /**
     * Custom taxonomy slug.
     *
     * @var string
     */
    public static string $taxonomy = 'cpt_text_snippet_cat';

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
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_snippet_type_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_snippet_type_column'], 10, 2);
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
            'name'                  => __('Text Snippets', 'sfx-bricks-child'),
            'singular_name'         => __('Text Snippet', 'sfx-bricks-child'),
            'add_new'               => __('Add New', 'sfx-bricks-child'),
            'add_new_item'          => __('Add New Text Snippet', 'sfx-bricks-child'),
            'edit_item'             => __('Edit Text Snippet', 'sfx-bricks-child'),
            'new_item'              => __('New Text Snippet', 'sfx-bricks-child'),
            'view_item'             => __('View Text Snippet', 'sfx-bricks-child'),
            'search_items'          => __('Search Text Snippets', 'sfx-bricks-child'),
            'not_found'             => __('No text snippets found', 'sfx-bricks-child'),
            'not_found_in_trash'    => __('No text snippets found in Trash', 'sfx-bricks-child'),
            'all_items'             => __('All Text Snippets', 'sfx-bricks-child'),
            'menu_name'             => __('Text Snippets', 'sfx-bricks-child'),
            'name_admin_bar'        => __('Text Snippet', 'sfx-bricks-child'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'supports'           => ['title', 'editor', 'excerpt'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'text-snippet'],
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-media-text',
            'capability_type'    => 'post',
        ];

        register_post_type(self::$post_type, $args);
    }

    /**
     * Register the custom taxonomy for the post type.
     */
    public static function register_taxonomy(): void
    {
        $labels = [
            'name'              => __('Snippet Categories', 'sfx-bricks-child'),
            'singular_name'     => __('Snippet Category', 'sfx-bricks-child'),
            'search_items'      => __('Search Snippet Categories', 'sfx-bricks-child'),
            'all_items'         => __('All Snippet Categories', 'sfx-bricks-child'),
            'parent_item'       => __('Parent Category', 'sfx-bricks-child'),
            'parent_item_colon' => __('Parent Category:', 'sfx-bricks-child'),
            'edit_item'         => __('Edit Category', 'sfx-bricks-child'),
            'update_item'       => __('Update Category', 'sfx-bricks-child'),
            'add_new_item'      => __('Add New Category', 'sfx-bricks-child'),
            'new_item_name'     => __('New Category Name', 'sfx-bricks-child'),
            'menu_name'         => __('Snippet Categories', 'sfx-bricks-child'),
        ];

        $args = [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'text-snippet-category'],
        ];

        register_taxonomy(
            self::$taxonomy,
            [self::$post_type],
            $args
        );
    }

    /**
     * Register the meta box for dynamic custom fields.
     */
    public static function register_meta_box(): void
    {
        add_meta_box(
            'sfx_text_snippet_fields',
            __('Text Snippet Custom Fields', 'sfx-bricks-child'),
            [self::class, 'render_meta_box'],
            self::$post_type,
            'normal',
            'default'
        );
    }

    /**
     * Render the meta box UI for dynamic custom fields.
     *
     * @param \WP_Post $post
     */
    public static function render_meta_box($post): void
    {
        wp_nonce_field('sfx_text_snippet_fields_nonce', 'sfx_text_snippet_fields_nonce');
        $fields = get_post_meta($post->ID, '_sfx_text_snippet_fields', true);
        if (!is_array($fields)) {
            $fields = [];
        }
        ?>
        <div id="sfx-text-snippet-fields-wrapper">
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field Slug', 'sfx-bricks-child'); ?></th>
                        <th><?php esc_html_e('Value', 'sfx-bricks-child'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="sfx-text-snippet-fields-rows">
                <?php if (!empty($fields)) :
                    foreach ($fields as $i => $field) :
                        $slug = isset($field['slug']) ? esc_attr($field['slug']) : '';
                        $value = isset($field['value']) ? esc_attr($field['value']) : '';
                        ?>
                        <tr>
                            <td><input type="text" name="sfx_text_snippet_fields[<?php echo $i; ?>][slug]" value="<?php echo $slug; ?>" class="widefat" /></td>
                            <td><input type="text" name="sfx_text_snippet_fields[<?php echo $i; ?>][value]" value="<?php echo $value; ?>" class="widefat" /></td>
                            <td><button type="button" class="button sfx-remove-field">&times;</button></td>
                        </tr>
                    <?php endforeach;
                endif; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button" id="sfx-add-field"><?php esc_html_e('Add Field', 'sfx-bricks-child'); ?></button>
            </p>
        </div>
        <script>
        (function($){
            $(document).on('click', '#sfx-add-field', function(e){
                e.preventDefault();
                var rowCount = $('#sfx-text-snippet-fields-rows tr').length;
                var row = '<tr>' +
                    '<td><input type="text" name="sfx_text_snippet_fields['+rowCount+'][slug]" class="widefat" /></td>' +
                    '<td><input type="text" name="sfx_text_snippet_fields['+rowCount+'][value]" class="widefat" /></td>' +
                    '<td><button type="button" class="button sfx-remove-field">&times;</button></td>' +
                    '</tr>';
                $('#sfx-text-snippet-fields-rows').append(row);
            });
            $(document).on('click', '.sfx-remove-field', function(){
                $(this).closest('tr').remove();
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Save the dynamic custom fields for the text snippet post type.
     *
     * @param int $post_id
     */
    public static function save_custom_fields(int $post_id): void
    {
        if (!isset($_POST['sfx_text_snippet_fields_nonce']) || !wp_verify_nonce($_POST['sfx_text_snippet_fields_nonce'], 'sfx_text_snippet_fields_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $fields = $_POST['sfx_text_snippet_fields'] ?? [];
        $clean_fields = [];
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $slug = isset($field['slug']) ? sanitize_key($field['slug']) : '';
                $value = isset($field['value']) ? sanitize_text_field($field['value']) : '';
                if ($slug !== '') {
                    $clean_fields[] = [
                        'slug' => $slug,
                        'value' => $value,
                    ];
                }
            }
        }
        update_post_meta($post_id, '_sfx_text_snippet_fields', $clean_fields);
    }

    /**
     * Add a custom column for the shortcode.
     *
     * @param array $columns
     * @return array
     */
    public static function add_snippet_type_column(array $columns): array
    {
        $columns['snippet_type'] = __('Type', 'sfx-bricks-child');
        return $columns;
    }

    /**
     * Render the shortcode column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_snippet_type_column(string $column, int $post_id): void
    {
        if ($column === 'snippet_type') {
            $snippet_type = get_post_meta($post_id, '_sfx_text_snippet_type', true);
            echo esc_html($snippet_type ?: __('Text Snippet', 'sfx-bricks-child'));
        }
    }

    /**
     * Add a custom column for the status.
     *
     * @param array $columns
     * @return array
     */
    public static function add_status_column(array $columns): array
    {
        $columns['snippet_status'] = __('Status', 'sfx-bricks-child');
        return $columns;
    }

    /**
     * Render the status column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_status_column(string $column, int $post_id): void
    {
        if ($column === 'snippet_status') {
            $snippet_status = get_post_meta($post_id, '_sfx_text_snippet_status', true);
            echo esc_html($snippet_status ?: __('Active', 'sfx-bricks-child'));
        }
    }

    /**
     * Remove the default 'Date' column.
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

/**
 * Get a custom field value by slug for a Text Snippet post.
 *
 * @param int    $post_id The post ID.
 * @param string $slug    The field slug.
 * @return string|null    The field value, or null if not found.
 */
function sfx_get_text_snippet_field(int $post_id, string $slug): ?string {
    $fields = get_post_meta($post_id, '_sfx_text_snippet_fields', true);
    if (is_array($fields)) {
        foreach ($fields as $field) {
            if (isset($field['slug'], $field['value']) && $field['slug'] === $slug) {
                return $field['value'];
            }
        }
    }
    return null;
}