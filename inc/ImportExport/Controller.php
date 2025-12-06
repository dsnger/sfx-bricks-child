<?php

declare(strict_types=1);

namespace SFX\ImportExport;

use SFX\AccessControl;

/**
 * Controller for Import/Export feature.
 * 
 * Handles the core logic for exporting and importing theme settings
 * and custom post type data.
 * 
 * @package SFX\ImportExport
 */
class Controller
{
    public const EXPORT_VERSION = '1.0.0';
    public const MAX_FILE_SIZE = 2097152; // 2MB in bytes

    /**
     * Initialize the controller.
     */
    public function __construct()
    {
        // Register components
        AdminPage::register();
        AssetManager::register();
        Settings::register();

        // Register AJAX handlers
        add_action('wp_ajax_sfx_export_settings', [$this, 'handle_export']);
        add_action('wp_ajax_sfx_import_settings', [$this, 'handle_import']);
        add_action('wp_ajax_sfx_preview_import', [$this, 'handle_preview_import']);
    }

    /**
     * Get feature configuration for the feature registry.
     * 
     * @return array
     */
    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'error' => 'Missing ImportExport Controller class in theme',
            'hook' => null,
        ];
    }

    /**
     * Get all exportable settings groups.
     * 
     * @return array
     */
    public static function get_settings_groups(): array
    {
        return [
            'general_options' => [
                'label' => __('General Theme Options', 'sfxtheme'),
                'description' => __('Main theme feature toggles and settings', 'sfxtheme'),
                'option_key' => 'sfx_general_options',
                'type' => 'single',
            ],
            'wpoptimizer_options' => [
                'label' => __('WP Optimizer Settings', 'sfxtheme'),
                'description' => __('WordPress performance and optimization settings', 'sfxtheme'),
                'option_key' => 'sfx_wpoptimizer_options',
                'type' => 'single',
            ],
            'custom_dashboard' => [
                'label' => __('Custom Dashboard Settings', 'sfxtheme'),
                'description' => __('Dashboard layout, branding, sections and styling', 'sfxtheme'),
                'option_key' => 'sfx_custom_dashboard',
                'type' => 'single',
            ],
            'security_header' => [
                'label' => __('Security Header Settings', 'sfxtheme'),
                'description' => __('HTTP security headers configuration', 'sfxtheme'),
                'option_keys' => [
                    'sfx_hsts_max_age',
                    'sfx_hsts_include_subdomains',
                    'sfx_hsts_preload',
                    'sfx_csp',
                    'sfx_csp_report_uri',
                    'sfx_permissions_policy',
                    'sfx_x_frame_options',
                    'sfx_x_frame_options_allow_from_url',
                    'sfx_disable_hsts_header',
                    'sfx_disable_csp_header',
                    'sfx_disable_x_content_type_options_header',
                    'sfx_disable_x_frame_options_header',
                ],
                'type' => 'multiple',
            ],
            'image_optimizer' => [
                'label' => __('Image Optimizer Settings', 'sfxtheme'),
                'description' => __('Image conversion and optimization settings', 'sfxtheme'),
                'option_keys' => [
                    'sfx_webp_max_widths',
                    'sfx_webp_max_heights',
                    'sfx_webp_resize_mode',
                    'sfx_webp_quality',
                    'sfx_webp_batch_size',
                    'sfx_webp_preserve_originals',
                    'sfx_webp_disable_auto_conversion',
                    'sfx_webp_min_size_kb',
                    'sfx_webp_use_avif',
                    // Note: sfx_webp_excluded_images and sfx_webp_conversion_log are NOT exported
                ],
                'type' => 'multiple',
            ],
            'html_copy_paste' => [
                'label' => __('HTML Copy/Paste Settings', 'sfxtheme'),
                'description' => __('Bricks Builder HTML conversion settings', 'sfxtheme'),
                'option_key' => 'sfx_html_copy_paste_options',
                'type' => 'single',
            ],
            'text_snippets' => [
                'label' => __('Text Snippets Settings', 'sfxtheme'),
                'description' => __('Text snippets feature settings', 'sfxtheme'),
                'option_key' => 'sfx_text_snippets_options',
                'type' => 'single',
            ],
        ];
    }

    /**
     * Get all exportable post types.
     * 
     * @return array
     */
    public static function get_exportable_post_types(): array
    {
        return [
            'sfx_custom_script' => __('Custom Scripts', 'sfxtheme'),
            'sfx_contact_info' => __('Contact Information', 'sfxtheme'),
            'sfx_social_account' => __('Social Media Accounts', 'sfxtheme'),
            'cpt_text_snippet' => __('Text Snippets', 'sfxtheme'),
        ];
    }

    /**
     * Handle export AJAX request.
     */
    public function handle_export(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'sfx_export_settings_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'sfxtheme')], 403);
        }

        // Check capabilities
        if (!current_user_can('manage_options') || !AccessControl::can_access_theme_settings()) {
            wp_send_json_error(['message' => __('You do not have permission to export settings.', 'sfxtheme')], 403);
        }

        // Get selected items
        $selected_settings = isset($_POST['settings']) && is_array($_POST['settings']) 
            ? array_map('sanitize_text_field', $_POST['settings']) 
            : [];
        $selected_posttypes = isset($_POST['posttypes']) && is_array($_POST['posttypes']) 
            ? array_map('sanitize_text_field', $_POST['posttypes']) 
            : [];

        if (empty($selected_settings) && empty($selected_posttypes)) {
            wp_send_json_error(['message' => __('Please select at least one item to export.', 'sfxtheme')], 400);
        }

        // Build export data
        $export_data = $this->build_export_data($selected_settings, $selected_posttypes);

        // Get theme data
        $theme = wp_get_theme();
        $current_user = wp_get_current_user();

        // Build final export structure
        $export = [
            'version' => self::EXPORT_VERSION,
            'theme_version' => $theme->get('Version'),
            'exported_at' => gmdate('c'),
            'exported_by' => $current_user->user_login,
            'data' => $export_data,
        ];

        // Sanitize export data to remove problematic Unicode characters
        $export = $this->sanitize_export_data($export);

        wp_send_json_success([
            'data' => $export,
            'filename' => $this->generate_export_filename(),
        ]);
    }

    /**
     * Build export data array.
     * 
     * @param array $selected_settings Selected settings groups.
     * @param array $selected_posttypes Selected post types.
     * @return array
     */
    private function build_export_data(array $selected_settings, array $selected_posttypes): array
    {
        $data = [
            'settings' => [],
            'post_types' => [],
        ];

        // Collect settings data
        if (!empty($selected_settings)) {
            $data['settings'] = $this->collect_settings_data($selected_settings);
        }

        // Collect post type data
        if (!empty($selected_posttypes)) {
            $data['post_types'] = $this->collect_post_type_data($selected_posttypes);
        }

        return $data;
    }

    /**
     * Collect settings data for export.
     * 
     * @param array $selected_groups Selected settings group keys.
     * @return array
     */
    private function collect_settings_data(array $selected_groups): array
    {
        $settings_groups = self::get_settings_groups();
        $data = [];

        foreach ($selected_groups as $group_key) {
            if (!isset($settings_groups[$group_key])) {
                continue;
            }

            $group = $settings_groups[$group_key];

            if ($group['type'] === 'single') {
                // Single option key
                $value = get_option($group['option_key'], []);
                $data[$group_key] = $value;
            } else {
                // Multiple option keys
                $group_data = [];
                foreach ($group['option_keys'] as $option_key) {
                    $group_data[$option_key] = get_option($option_key);
                }
                $data[$group_key] = $group_data;
            }
        }

        return $data;
    }

    /**
     * Collect post type data for export.
     * 
     * @param array $selected_types Selected post type slugs.
     * @return array
     */
    private function collect_post_type_data(array $selected_types): array
    {
        $exportable_types = self::get_exportable_post_types();
        $data = [];

        foreach ($selected_types as $post_type) {
            if (!isset($exportable_types[$post_type])) {
                continue;
            }

            // Get all posts of this type
            $posts = get_posts([
                'post_type' => $post_type,
                'post_status' => ['publish', 'draft', 'private'],
                'numberposts' => -1,
                'orderby' => 'menu_order title',
                'order' => 'ASC',
            ]);

            $posts_data = [];
            foreach ($posts as $post) {
                $post_data = [
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_excerpt' => $post->post_excerpt,
                    'post_status' => $post->post_status,
                    'post_name' => $post->post_name,
                    'menu_order' => $post->menu_order,
                    'post_meta' => [],
                ];

                // Get all post meta
                $meta = get_post_meta($post->ID);
                foreach ($meta as $meta_key => $meta_values) {
                    // Skip internal WordPress meta
                    if (strpos($meta_key, '_wp_') === 0 || $meta_key === '_edit_lock' || $meta_key === '_edit_last') {
                        continue;
                    }
                    // Store single value if only one, otherwise array
                    $post_data['post_meta'][$meta_key] = count($meta_values) === 1 
                        ? maybe_unserialize($meta_values[0]) 
                        : array_map('maybe_unserialize', $meta_values);
                }

                $posts_data[] = $post_data;
            }

            $data[$post_type] = $posts_data;
        }

        return $data;
    }

    /**
     * Handle preview import AJAX request.
     */
    public function handle_preview_import(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'sfx_import_settings_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'sfxtheme')], 403);
        }

        // Check capabilities
        if (!current_user_can('manage_options') || !AccessControl::can_access_theme_settings()) {
            wp_send_json_error(['message' => __('You do not have permission to import settings.', 'sfxtheme')], 403);
        }

        // Check if file was uploaded
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('No file uploaded or upload error.', 'sfxtheme')], 400);
        }

        $file = $_FILES['import_file'];

        // Validate file
        $validation = $this->validate_import_file($file);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()], 400);
        }

        // Parse JSON
        $json_content = file_get_contents($file['tmp_name']);
        $import_data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON file.', 'sfxtheme')], 400);
        }

        // Validate structure
        $structure_validation = $this->validate_import_structure($import_data);
        if (is_wp_error($structure_validation)) {
            wp_send_json_error(['message' => $structure_validation->get_error_message()], 400);
        }

        // Build preview response
        $preview = $this->build_import_preview($import_data);

        wp_send_json_success($preview);
    }

    /**
     * Handle import AJAX request.
     */
    public function handle_import(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'sfx_import_settings_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'sfxtheme')], 403);
        }

        // Check capabilities
        if (!current_user_can('manage_options') || !AccessControl::can_access_theme_settings()) {
            wp_send_json_error(['message' => __('You do not have permission to import settings.', 'sfxtheme')], 403);
        }

        // Get import data from POST (already parsed by JavaScript)
        $import_data = isset($_POST['import_data']) ? json_decode(stripslashes($_POST['import_data']), true) : null;

        if (!$import_data) {
            wp_send_json_error(['message' => __('Invalid import data.', 'sfxtheme')], 400);
        }

        // Get selected items and mode
        $selected_settings = isset($_POST['settings']) && is_array($_POST['settings']) 
            ? array_map('sanitize_text_field', $_POST['settings']) 
            : [];
        $selected_posttypes = isset($_POST['posttypes']) && is_array($_POST['posttypes']) 
            ? array_map('sanitize_text_field', $_POST['posttypes']) 
            : [];
        $mode = sanitize_text_field($_POST['mode'] ?? 'merge');

        if (empty($selected_settings) && empty($selected_posttypes)) {
            wp_send_json_error(['message' => __('Please select at least one item to import.', 'sfxtheme')], 400);
        }

        // Process import
        $results = [
            'settings' => [],
            'post_types' => [],
        ];

        // Import settings
        if (!empty($selected_settings) && !empty($import_data['data']['settings'])) {
            $results['settings'] = $this->import_settings_data(
                $import_data['data']['settings'],
                $selected_settings,
                $mode
            );
        }

        // Import post types
        if (!empty($selected_posttypes) && !empty($import_data['data']['post_types'])) {
            $results['post_types'] = $this->import_post_type_data(
                $import_data['data']['post_types'],
                $selected_posttypes,
                $mode
            );
        }

        wp_send_json_success([
            'message' => __('Import completed successfully!', 'sfxtheme'),
            'results' => $results,
        ]);
    }

    /**
     * Validate import file.
     * 
     * @param array $file $_FILES array entry.
     * @return true|\WP_Error
     */
    private function validate_import_file(array $file)
    {
        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new \WP_Error('file_too_large', __('File is too large. Maximum size is 2MB.', 'sfxtheme'));
        }

        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_types = ['application/json', 'text/plain', 'text/json'];
        if (!in_array($mime_type, $allowed_types, true)) {
            return new \WP_Error('invalid_type', __('Invalid file type. Please upload a JSON file.', 'sfxtheme'));
        }

        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            return new \WP_Error('invalid_extension', __('Invalid file extension. Please upload a .json file.', 'sfxtheme'));
        }

        return true;
    }

    /**
     * Validate import data structure.
     * 
     * @param array $data Import data.
     * @return true|\WP_Error
     */
    private function validate_import_structure(array $data)
    {
        // Check required fields
        if (!isset($data['version'])) {
            return new \WP_Error('missing_version', __('Invalid export file: missing version information.', 'sfxtheme'));
        }

        if (!isset($data['data'])) {
            return new \WP_Error('missing_data', __('Invalid export file: missing data section.', 'sfxtheme'));
        }

        if (!isset($data['data']['settings']) && !isset($data['data']['post_types'])) {
            return new \WP_Error('empty_data', __('Invalid export file: no settings or post type data found.', 'sfxtheme'));
        }

        return true;
    }

    /**
     * Build import preview data.
     * 
     * @param array $import_data Import data.
     * @return array
     */
    private function build_import_preview(array $import_data): array
    {
        $preview = [
            'version' => $import_data['version'] ?? 'unknown',
            'theme_version' => $import_data['theme_version'] ?? 'unknown',
            'exported_at' => $import_data['exported_at'] ?? 'unknown',
            'exported_by' => $import_data['exported_by'] ?? 'unknown',
            'available_settings' => [],
            'available_posttypes' => [],
        ];

        $settings_groups = self::get_settings_groups();
        $exportable_types = self::get_exportable_post_types();

        // Check which settings are available
        if (!empty($import_data['data']['settings'])) {
            foreach ($import_data['data']['settings'] as $group_key => $group_data) {
                if (isset($settings_groups[$group_key])) {
                    $preview['available_settings'][$group_key] = [
                        'key' => $group_key,
                        'label' => $settings_groups[$group_key]['label'],
                        'description' => $settings_groups[$group_key]['description'],
                    ];
                }
            }
        }

        // Check which post types are available
        if (!empty($import_data['data']['post_types'])) {
            foreach ($import_data['data']['post_types'] as $post_type => $posts) {
                if (isset($exportable_types[$post_type])) {
                    $preview['available_posttypes'][$post_type] = [
                        'key' => $post_type,
                        'label' => $exportable_types[$post_type],
                        'count' => count($posts),
                    ];
                }
            }
        }

        return $preview;
    }

    /**
     * Import settings data.
     * 
     * @param array $data Settings data from import.
     * @param array $selected_groups Selected groups to import.
     * @param string $mode Import mode ('merge' or 'replace').
     * @return array Results.
     */
    private function import_settings_data(array $data, array $selected_groups, string $mode): array
    {
        $settings_groups = self::get_settings_groups();
        $results = [];

        foreach ($selected_groups as $group_key) {
            if (!isset($data[$group_key]) || !isset($settings_groups[$group_key])) {
                continue;
            }

            $group = $settings_groups[$group_key];
            $import_value = $data[$group_key];

            try {
                if ($group['type'] === 'single') {
                    // Single option key
                    if ($mode === 'merge') {
                        $existing = get_option($group['option_key'], []);
                        if (is_array($existing) && is_array($import_value)) {
                            $import_value = array_merge($existing, $import_value);
                        }
                    }
                    
                    // Sanitize based on option type
                    $sanitized = $this->sanitize_option_value($group['option_key'], $import_value);
                    update_option($group['option_key'], $sanitized);
                    
                    $results[$group_key] = [
                        'status' => 'success',
                        'message' => sprintf(__('%s imported successfully.', 'sfxtheme'), $group['label']),
                    ];
                } else {
                    // Multiple option keys
                    $imported_count = 0;
                    foreach ($group['option_keys'] as $option_key) {
                        if (isset($import_value[$option_key])) {
                            $value = $import_value[$option_key];
                            $sanitized = $this->sanitize_option_value($option_key, $value);
                            update_option($option_key, $sanitized);
                            $imported_count++;
                        }
                    }
                    
                    $results[$group_key] = [
                        'status' => 'success',
                        'message' => sprintf(
                            /* translators: 1: group label, 2: number of options imported */
                            __('%1$s: %2$d options imported.', 'sfxtheme'),
                            $group['label'],
                            $imported_count
                        ),
                    ];
                }
            } catch (\Exception $e) {
                $results[$group_key] = [
                    'status' => 'error',
                    'message' => sprintf(
                        /* translators: 1: group label, 2: error message */
                        __('%1$s: Import failed - %2$s', 'sfxtheme'),
                        $group['label'],
                        $e->getMessage()
                    ),
                ];
            }
        }

        return $results;
    }

    /**
     * Import post type data.
     * 
     * @param array $data Post type data from import.
     * @param array $selected_types Selected post types to import.
     * @param string $mode Import mode ('merge' or 'replace').
     * @return array Results.
     */
    private function import_post_type_data(array $data, array $selected_types, string $mode): array
    {
        $exportable_types = self::get_exportable_post_types();
        $results = [];

        foreach ($selected_types as $post_type) {
            if (!isset($data[$post_type]) || !isset($exportable_types[$post_type])) {
                continue;
            }

            // Verify post type exists
            if (!post_type_exists($post_type)) {
                $results[$post_type] = [
                    'status' => 'error',
                    'message' => sprintf(__('Post type %s does not exist.', 'sfxtheme'), $post_type),
                ];
                continue;
            }

            try {
                $imported = 0;
                $skipped = 0;
                $errors = 0;

                // Delete existing posts if in replace mode
                if ($mode === 'replace') {
                    $this->delete_all_posts_of_type($post_type);
                }

                // Import posts
                foreach ($data[$post_type] as $post_data) {
                    // Check for duplicate by slug in merge mode
                    if ($mode === 'merge' && !empty($post_data['post_name'])) {
                        $existing = get_page_by_path($post_data['post_name'], OBJECT, $post_type);
                        if ($existing) {
                            $skipped++;
                            continue;
                        }
                    }

                    $result = $this->import_single_post($post_type, $post_data);
                    if ($result) {
                        $imported++;
                    } else {
                        $errors++;
                    }
                }

                $message_parts = [];
                if ($imported > 0) {
                    /* translators: %d: number of items imported */
                    $message_parts[] = sprintf(__('%d imported', 'sfxtheme'), $imported);
                }
                if ($skipped > 0) {
                    /* translators: %d: number of items skipped */
                    $message_parts[] = sprintf(__('%d skipped (already exist)', 'sfxtheme'), $skipped);
                }
                if ($errors > 0) {
                    /* translators: %d: number of errors */
                    $message_parts[] = sprintf(__('%d errors', 'sfxtheme'), $errors);
                }

                $results[$post_type] = [
                    'status' => $errors === 0 ? 'success' : 'partial',
                    'message' => $exportable_types[$post_type] . ': ' . implode(', ', $message_parts),
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ];

            } catch (\Exception $e) {
                $results[$post_type] = [
                    'status' => 'error',
                    'message' => sprintf(
                        /* translators: 1: post type label, 2: error message */
                        __('%1$s: Import failed - %2$s', 'sfxtheme'),
                        $exportable_types[$post_type],
                        $e->getMessage()
                    ),
                ];
            }
        }

        return $results;
    }

    /**
     * Import a single post.
     * 
     * @param string $post_type Post type.
     * @param array $post_data Post data.
     * @return int|false Post ID on success, false on failure.
     */
    private function import_single_post(string $post_type, array $post_data)
    {
        // Sanitize post data
        $insert_data = [
            'post_type' => $post_type,
            'post_title' => sanitize_text_field($post_data['post_title'] ?? ''),
            'post_content' => wp_kses_post($post_data['post_content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($post_data['post_excerpt'] ?? ''),
            'post_status' => in_array($post_data['post_status'] ?? '', ['publish', 'draft', 'private'], true) 
                ? $post_data['post_status'] 
                : 'draft',
            'post_name' => sanitize_title($post_data['post_name'] ?? ''),
            'menu_order' => absint($post_data['menu_order'] ?? 0),
        ];

        // Insert post
        $post_id = wp_insert_post($insert_data, true);

        if (is_wp_error($post_id)) {
            return false;
        }

        // Import post meta
        if (!empty($post_data['post_meta']) && is_array($post_data['post_meta'])) {
            foreach ($post_data['post_meta'] as $meta_key => $meta_value) {
                // Skip certain meta keys for security
                if (in_array($meta_key, ['_wp_attached_file', '_wp_attachment_metadata'], true)) {
                    continue;
                }
                
                // Sanitize meta key
                $meta_key = sanitize_key($meta_key);
                
                // Update meta
                if (is_array($meta_value) && !$this->is_associative_array($meta_value)) {
                    // Multiple values for same key
                    delete_post_meta($post_id, $meta_key);
                    foreach ($meta_value as $single_value) {
                        add_post_meta($post_id, $meta_key, $this->sanitize_meta_value($single_value));
                    }
                } else {
                    update_post_meta($post_id, $meta_key, $this->sanitize_meta_value($meta_value));
                }
            }
        }

        return $post_id;
    }

    /**
     * Delete all posts of a given type.
     * 
     * @param string $post_type Post type.
     */
    private function delete_all_posts_of_type(string $post_type): void
    {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true); // Force delete, bypass trash
        }
    }

    /**
     * Sanitize option value based on option key.
     * 
     * @param string $option_key Option key.
     * @param mixed $value Value to sanitize.
     * @return mixed Sanitized value.
     */
    private function sanitize_option_value(string $option_key, $value)
    {
        // Array options
        if (is_array($value)) {
            return array_map(function ($v) {
                if (is_array($v)) {
                    return array_map('sanitize_text_field', $v);
                }
                return is_string($v) ? sanitize_text_field($v) : $v;
            }, $value);
        }

        // Boolean options
        if (in_array($option_key, [
            'sfx_hsts_include_subdomains',
            'sfx_hsts_preload',
            'sfx_disable_hsts_header',
            'sfx_disable_csp_header',
            'sfx_disable_x_content_type_options_header',
            'sfx_disable_x_frame_options_header',
            'sfx_webp_preserve_originals',
            'sfx_webp_disable_auto_conversion',
            'sfx_webp_use_avif',
        ], true)) {
            return (bool) $value;
        }

        // Integer options
        if (in_array($option_key, [
            'sfx_webp_quality',
            'sfx_webp_batch_size',
            'sfx_webp_min_size_kb',
        ], true)) {
            return absint($value);
        }

        // Textarea options (allow more content)
        if (in_array($option_key, [
            'sfx_csp',
            'sfx_permissions_policy',
        ], true)) {
            return sanitize_textarea_field($value);
        }

        // Default: sanitize as text
        return is_string($value) ? sanitize_text_field($value) : $value;
    }

    /**
     * Sanitize meta value.
     * 
     * @param mixed $value Value to sanitize.
     * @return mixed Sanitized value.
     */
    private function sanitize_meta_value($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitize_meta_value'], $value);
        }

        if (is_string($value)) {
            // Check if it looks like HTML
            if (preg_match('/<[^>]+>/', $value)) {
                return wp_kses_post($value);
            }
            return sanitize_text_field($value);
        }

        return $value;
    }

    /**
     * Check if array is associative.
     * 
     * @param array $arr Array to check.
     * @return bool
     */
    private function is_associative_array(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Sanitize export data to remove problematic Unicode characters.
     * 
     * Removes Line Separator (U+2028) and Paragraph Separator (U+2029)
     * which can cause issues in some editors and JSON parsers.
     * 
     * @param mixed $data Data to sanitize.
     * @return mixed Sanitized data.
     */
    private function sanitize_export_data($data)
    {
        if (is_string($data)) {
            // Remove Line Separator (U+2028) and Paragraph Separator (U+2029)
            // Also normalize other problematic whitespace characters
            $data = str_replace(
                ["\u{2028}", "\u{2029}", "\u{0085}", "\u{000B}", "\u{000C}"],
                ["\n", "\n", "\n", " ", " "],
                $data
            );
            // Normalize line endings to \n
            $data = str_replace(["\r\n", "\r"], "\n", $data);
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize_export_data($value);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Generate export filename.
     * 
     * @return string
     */
    private function generate_export_filename(): string
    {
        return 'sfx-theme-export-' . gmdate('Y-m-d-His') . '.json';
    }
}

