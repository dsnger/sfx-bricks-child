<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

class Ajax
{
    public static function register(): void
    {
        add_action('wp_ajax_webp_add_excluded_image', [self::class, 'add_excluded_image']);
        add_action('wp_ajax_webp_remove_excluded_image', [self::class, 'remove_excluded_image']);
        add_action('wp_ajax_webp_convert_single', [self::class, 'convert_single_image']);
        add_action('wp_ajax_webp_export_media_zip', [self::class, 'export_media_zip']);
        add_action('wp_ajax_webp_cleanup_originals', [self::class, 'cleanup_originals']);
        add_action('wp_ajax_webp_cleanup_optimized', [self::class, 'cleanup_optimized']);
        add_action('wp_ajax_webp_fix_post_image_urls', [self::class, 'fix_post_image_urls']);
        add_action('wp_ajax_webp_set_max_widths', [self::class, 'set_max_widths']);
        add_action('wp_ajax_webp_set_max_heights', [self::class, 'set_max_heights']);
        add_action('wp_ajax_webp_clear_log', [self::class, 'clear_log']);
        add_action('wp_ajax_webp_reset_defaults', [self::class, 'reset_defaults']);
        add_action('wp_ajax_webp_get_excluded_images', [self::class, 'get_excluded_images']);
        add_action('wp_ajax_webp_set_min_size_kb', [self::class, 'set_min_size_kb']);
        add_action('wp_ajax_webp_set_quality', [self::class, 'set_quality']);
        add_action('wp_ajax_webp_set_batch_size', [self::class, 'set_batch_size']);
        add_action('wp_ajax_webp_set_use_avif', [self::class, 'set_use_avif']);
        add_action('wp_ajax_webp_set_preserve_originals', [self::class, 'set_preserve_originals']);
        add_action('wp_ajax_webp_set_disable_auto_conversion', [self::class, 'set_disable_auto_conversion']);
        add_action('wp_ajax_webp_revert_to_original', [self::class, 'revert_to_original']);
        // Add other AJAX handlers as needed
    }

    public static function add_excluded_image(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
            wp_send_json_error(__('Permission denied or invalid attachment ID', 'sfxtheme'));
        }
        $attachment_id = absint($_POST['attachment_id']);
        if (Settings::add_excluded_image($attachment_id)) {
            wp_send_json_success(['message' => __('Image excluded successfully', 'sfxtheme')]);
        } else {
            wp_send_json_error(__('Image already excluded or invalid ID', 'sfxtheme'));
        }
    }

    public static function remove_excluded_image(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
            wp_send_json_error(__('Permission denied or invalid attachment ID', 'sfxtheme'));
        }
        $attachment_id = absint($_POST['attachment_id']);
        if (Settings::remove_excluded_image($attachment_id)) {
            wp_send_json_success(['message' => __('Image removed from exclusion list', 'sfxtheme')]);
        } else {
            wp_send_json_error(__('Image not in exclusion list', 'sfxtheme'));
        }
    }

    /**
     * Convert images in batches via AJAX.
     * 
     * Uses ImageConversionService for core conversion logic while handling
     * batch-specific concerns like optimization stamps and force reconvert.
     */
    public static function convert_single_image(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['offset'])) {
            wp_send_json_error(__('Permission denied or invalid offset', 'sfxtheme'));
        }
        
        $offset = absint($_POST['offset']);
        $batch_size = Settings::get_batch_size();
        wp_raise_memory_limit('image');
        set_time_limit(max(30, 10 * $batch_size));
        
        // Get current settings
        $mode = Settings::get_resize_mode();
        $max_values = Settings::get_max_values();
        $current_quality = Settings::get_quality();
        $min_size_kb = Settings::get_min_size_kb();
        $use_avif = Settings::get_use_avif();
        $extension = Settings::get_extension();
        $format = Settings::get_format();
        
        // Query attachments
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids',
            'post__not_in' => Settings::get_excluded_images(),
        ];
        $attachments = get_posts($args);
        $log = [];
        
        if (empty($attachments)) {
            update_option('sfx_webp_conversion_complete', true);
            Settings::append_log(__('Conversion Complete', 'sfxtheme') . ': ' . __('No more images to process', 'sfxtheme'));
            wp_send_json_success(['complete' => true]);
        }
        
        // Check for force reconvert flag
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified at method start
        $force_reconvert = isset($_GET['force_reconvert']) && filter_var($_GET['force_reconvert'], FILTER_VALIDATE_BOOLEAN);
        
        // Build expected optimization stamp
        $expected_stamp = [
            'format' => $use_avif ? 'avif' : 'webp',
            'quality' => $current_quality,
            'resize_mode' => $mode,
            'max_values' => $max_values,
        ];
        
        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            
            // Validate file exists
            if (!file_exists($file_path)) {
                $log[] = sprintf(__('Skipped: File not found for Attachment ID %d', 'sfxtheme'), $attachment_id);
                continue;
            }
            
            // Validate directory is writable
            $uploads_dir = dirname($file_path);
            if (!is_writable($uploads_dir)) {
                $log[] = sprintf(__('Error: Uploads directory %s is not writable for Attachment ID %d', 'sfxtheme'), $uploads_dir, $attachment_id);
                continue;
            }
            
            // Check minimum size threshold
            $file_size_kb = filesize($file_path) / 1024;
            if ($min_size_kb > 0 && $file_size_kb < $min_size_kb) {
                $log[] = sprintf(__('Skipped: %s (size %s KB < %d KB)', 'sfxtheme'), basename($file_path), round($file_size_kb, 2), $min_size_kb);
                continue;
            }
            
            // Check optimization stamp (skip if already optimized with current settings)
            if (!$force_reconvert && !ImageConversionService::needsReprocessing($attachment_id, [
                'use_avif' => $use_avif,
                'quality' => $current_quality,
                'mode' => $mode,
                'max_values' => $max_values,
            ])) {
                $log[] = sprintf(__('Skipped: Already optimized with current settings - Attachment ID %d', 'sfxtheme'), $attachment_id);
                continue;
            }
            
            // Check if current format matches target
            $current_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $target_extension = $use_avif ? 'avif' : 'webp';
            $is_current_format = $current_extension === $target_extension;
            
            // Additional reprocessing checks
            $metadata = wp_get_attachment_metadata($attachment_id);
            $existing_quality = isset($metadata['webp_quality']) ? (int) $metadata['webp_quality'] : null;
            $reprocess = !$is_current_format || $existing_quality !== $current_quality;
            
            if ($is_current_format && !$reprocess) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $current_size = $editor->get_size();
                    $current_dimension = ($mode === 'width') ? $current_size['width'] : $current_size['height'];
                    $reprocess = !in_array($current_dimension, $max_values, true);
                }
            }
            
            if (!$reprocess && !$force_reconvert) {
                continue;
            }
            
            $dirname = dirname($file_path);
            $base_name = pathinfo($file_path, PATHINFO_FILENAME);
            
            // Check if backup exists before conversion
            $backup_existed_before = !$is_current_format && ImageConversionService::backupExists($file_path);
            
            // Clean up old sizes not in current max_values
            if ($is_current_format) {
                self::cleanupOutdatedSizes($attachment_id, $dirname, $base_name, $extension, $max_values, $log);
            }
            
            // Perform conversion using service
            $result = ImageConversionService::convertImage($file_path, $attachment_id, [
                'use_avif' => $use_avif,
                'quality' => $current_quality,
                'mode' => $mode,
                'max_values' => $max_values,
            ]);
            
            // Merge service log with local log
            $log = array_merge($log, $result['log']);
            
            // Handle conversion failure
            if ($result['status'] !== ImageConversionService::STATUS_SUCCESS) {
                    continue;
                }

            $main_converted_file = $result['main_file'];
            
            // Update metadata
            if ($attachment_id && !empty($result['files'])) {
                $metadata_updated = ImageConversionService::updateAttachmentMetadata(
                    $attachment_id,
                    $main_converted_file,
                    [
                        'format' => $format,
                        'extension' => $extension,
                        'quality' => $current_quality,
                        'max_values' => $max_values,
                        'mode' => $mode,
                        'use_avif' => $use_avif,
                    ],
                    $log
                );
                
                if (!$metadata_updated) {
                    continue;
                }
            }
            
            // Handle original file deletion
            if (!$is_current_format && file_exists($file_path)) {
                $was_restored = (bool) get_post_meta($attachment_id, '_sfx_was_restored', true);
                
                ImageConversionService::handleOriginalDeletion(
                    $file_path,
                    $attachment_id,
                    Settings::get_preserve_originals(),
                    $was_restored,
                    $backup_existed_before,
                    $log
                );
            }
        }
        
        Settings::append_log($log);
        wp_send_json_success(['complete' => false, 'offset' => $offset + $batch_size]);
    }

    /**
     * Clean up outdated custom sizes that are not in current max_values.
     * 
     * @param int    $attachment_id Attachment ID
     * @param string $dirname       Directory path
     * @param string $base_name     Base filename without extension
     * @param string $extension     File extension (e.g., '.webp')
     * @param array  $max_values    Current max dimension values
     * @param array  $log           Reference to log array
     * @return void
     */
    private static function cleanupOutdatedSizes(
        int $attachment_id,
        string $dirname,
        string $base_name,
        string $extension,
        array $max_values,
        array &$log
    ): void {
        $old_metadata = wp_get_attachment_metadata($attachment_id);
        if (!isset($old_metadata['sizes'])) {
            return;
        }
        
        foreach ($old_metadata['sizes'] as $size_name => $size_data) {
            if (preg_match('/custom-(\d+)/', $size_name, $matches)) {
                $old_dimension = (int) $matches[1];
                if (!in_array($old_dimension, $max_values, true)) {
                    $old_file = "{$dirname}/{$base_name}-{$old_dimension}{$extension}";
                    if (file_exists($old_file)) {
                        @unlink($old_file);
                        $log[] = sprintf(__('Deleted outdated size: %s', 'sfxtheme'), basename($old_file));
                    }
                }
            }
        }
    }

    public static function export_media_zip(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        wp_raise_memory_limit('admin');
        set_time_limit(0);
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];
        $attachments = get_posts($args);
        if (empty($attachments)) {
            wp_send_json_error(__('No media files found', 'sfxtheme'));
        }
        $temp_file = tempnam(sys_get_temp_dir(), 'webp_media_export_');
        if (!$temp_file) {
            wp_send_json_error(__('Failed to create temporary file', 'sfxtheme'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($temp_file);
            wp_send_json_error(__('Failed to create ZIP archive', 'sfxtheme'));
        }
        $upload_dir = wp_upload_dir()['basedir'];
        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if ($file_path && file_exists($file_path)) {
                $relative_path = str_replace($upload_dir . '/', '', $file_path);
                $zip->addFile($file_path, $relative_path);
            }
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata && isset($metadata['sizes'])) {
                $dirname = dirname($file_path);
                foreach ($metadata['sizes'] as $size => $size_data) {
                    $size_file = $dirname . '/' . $size_data['file'];
                    if (file_exists($size_file)) {
                        $relative_size_path = str_replace($upload_dir . '/', '', $size_file);
                        $zip->addFile($size_file, $relative_size_path);
                    }
                }
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="media_export_' . date('Y-m-d_H-i-s') . '.zip"');
        header('Content-Length: ' . filesize($temp_file));
        readfile($temp_file);
        flush();
        @unlink($temp_file);
        exit;
    }

    public static function cleanup_originals(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $log = [];
        $max_values = Settings::get_max_values();
        $extension = Settings::get_extension();
        $preserve_originals = Settings::get_preserve_originals();
        $thumb_size = Constants::THUMBNAIL_SIZE;
        
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post__not_in' => Settings::get_excluded_images(),
        ];
        $attachments = get_posts($args);
        $deleted = 0;
        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) continue;
            $dirname = dirname($file_path);
            $base_name = pathinfo($file_path, PATHINFO_FILENAME);
            $all_files = glob($dirname . '/' . $base_name . '*');
            foreach ($all_files as $candidate) {
                $is_current = false;
                $candidate_ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                
                // Keep current format and sizes
                foreach ($max_values as $index => $dimension) {
                    $suffix = ($index === 0) ? '' : "-{$dimension}";
                    $expected = $dirname . '/' . $base_name . $suffix . $extension;
                    if ($candidate === $expected) {
                        $is_current = true;
                        break;
                    }
                }
                // Keep thumbnail
                $thumb = $dirname . '/' . $base_name . "-{$thumb_size}x{$thumb_size}" . $extension;
                if ($candidate === $thumb) {
                    $is_current = true;
                }
                // Keep the attached file itself
                if ($candidate === $file_path) {
                    $is_current = true;
                }
                // CRITICAL FIX: If preserve_originals is ON, keep ALL original format files (jpg/png)
                // This ensures originals are kept even when the attachment now points to webp/avif
                if ($preserve_originals && in_array($candidate_ext, Constants::ORIGINAL_EXTENSIONS, true)) {
                    $is_current = true;
                }
                
                if (!$is_current && file_exists($candidate)) {
                    @unlink($candidate);
                    $deleted++;
                    $log[] = sprintf(__('Deleted old file: %s', 'sfxtheme'), basename($candidate));
                }
            }
        }
        $log[] = sprintf(__('Cleanup complete. %d files deleted.', 'sfxtheme'), $deleted);
        Settings::append_log($log);
        wp_send_json_success(['message' => sprintf(__('Cleanup complete. %d files deleted.', 'sfxtheme'), $deleted)]);
    }

    public static function fix_post_image_urls(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }

        $log = [];
        $use_avif = Settings::get_use_avif();
        $extension = Settings::get_extension_name();
        
        // Cache excluded images for performance (used in fix_post_thumbnail loop)
        $excluded_images = Settings::get_excluded_images();
        
        // Get all public post types + FSE templates
        $public_post_types = get_post_types(['public' => true], 'names');
        $fse_post_types = ['wp_template', 'wp_template_part', 'wp_block'];
        $post_types = array_unique(array_merge($public_post_types, $fse_post_types));
        
        $args = [
            'post_type' => $post_types,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];
        $posts = get_posts($args);
        
        if (!$posts) {
            Settings::append_log(__('No posts/pages/templates found', 'sfxtheme'));
            wp_send_json_success(['message' => __('No posts found', 'sfxtheme')]);
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $upload_baseurl = $upload_dir['baseurl'];
        $upload_basedir = $upload_dir['basedir'];
        $updated_count = 0;
        $checked_images = 0;
        $changed_links = 0;
        
        foreach ($posts as $post_id) {
            $type = get_post_type($post_id);
            
            // Skip Bricks Builder internal types
            if (strpos($type, 'bricks_') === 0) {
                continue;
            }
            
            // Get clean title (strip HTML br tags properly)
            $title_raw = get_the_title($post_id);
            $clean_title_html = preg_replace('/<\/?br\s*\/?>/i', ' ', $title_raw);
            $title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($clean_title_html)));
            
            $original_content = get_post_field('post_content', $post_id);
            $content = $original_content;
            
            // Replace image URLs in <img> tags
            $content = preg_replace_callback(
                '/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
                function ($matches) use (&$checked_images, $upload_baseurl, $upload_basedir, $extension) {
                    return self::replace_image_url_in_tag($matches, $checked_images, $upload_baseurl, $upload_basedir, $extension);
                },
                $content
            );
            
            // Replace image URLs in <a> tags
            $content = preg_replace_callback(
                '/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
                function ($matches) use (&$checked_images, &$changed_links, $upload_baseurl, $upload_basedir, $extension) {
                    return self::replace_image_url_in_link($matches, $checked_images, $changed_links, $upload_baseurl, $upload_basedir, $extension);
                },
                $content
            );
            
            // Save if changed
            if ($content !== $original_content) {
                wp_update_post(['ID' => $post_id, 'post_content' => $content]);
                $updated_count++;
                $log[] = sprintf(__('Updated: %s - %s', 'sfxtheme'), esc_html($type), esc_html($title));
            }
            
            // Process custom post type meta fields
            if (!in_array($type, ['post', 'page'], true)) {
                $updated_count += self::fix_meta_field_urls($post_id, $upload_baseurl, $upload_basedir, $extension, $log, $checked_images, $changed_links);
            }
            
            // Update post thumbnail if needed
            self::fix_post_thumbnail($post_id, $extension, $use_avif, $log, $excluded_images);
        }
        
        $log[] = sprintf(__('Checked %d images, updated %d items, changed %d links', 'sfxtheme'), $checked_images, $updated_count, $changed_links);
        Settings::append_log($log);
        wp_send_json_success(['message' => sprintf(__('Checked %d images, updated %d items', 'sfxtheme'), $checked_images, $updated_count)]);
    }

    public static function set_max_widths(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $widths = isset($_POST['widths']) ? sanitize_text_field($_POST['widths']) : '';
        $widths_arr = array_map('absint', array_filter(explode(',', $widths)));
        $widths_arr = array_filter($widths_arr, fn($w) => $w > 0 && $w <= Constants::MAX_DIMENSION);
        if (empty($widths_arr)) {
            wp_send_json_error(__('Invalid widths', 'sfxtheme'));
        }
        $final_widths = array_slice($widths_arr, 0, Constants::MAX_CUSTOM_SIZES);
        update_option('sfx_webp_max_widths', implode(',', $final_widths));
        $log_message = sprintf(__('Max widths set to: %spx', 'sfxtheme'), implode(', ', $final_widths));
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_max_heights(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $heights = isset($_POST['heights']) ? sanitize_text_field($_POST['heights']) : '';
        $heights_arr = array_map('absint', array_filter(explode(',', $heights)));
        $heights_arr = array_filter($heights_arr, fn($h) => $h > 0 && $h <= Constants::MAX_DIMENSION);
        if (empty($heights_arr)) {
            wp_send_json_error(__('Invalid heights', 'sfxtheme'));
        }
        $final_heights = array_slice($heights_arr, 0, Constants::MAX_CUSTOM_SIZES);
        update_option('sfx_webp_max_heights', implode(',', $final_heights));
        $log_message = sprintf(__('Max heights set to: %spx', 'sfxtheme'), implode(', ', $final_heights));
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function clear_log(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        Settings::clear_log();
        wp_send_json_success(['message' => __('Log cleared.', 'sfxtheme')]);
    }

    public static function reset_defaults(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        update_option('sfx_webp_max_widths', Constants::DEFAULT_MAX_WIDTHS);
        update_option('sfx_webp_max_heights', Constants::DEFAULT_MAX_HEIGHTS);
        update_option('sfx_webp_resize_mode', Constants::DEFAULT_RESIZE_MODE);
        update_option('sfx_webp_quality', Constants::DEFAULT_QUALITY);
        update_option('sfx_webp_batch_size', Constants::DEFAULT_BATCH_SIZE);
        update_option('sfx_webp_preserve_originals', false);
        update_option('sfx_webp_disable_auto_conversion', false);
        update_option('sfx_webp_min_size_kb', 0);
        update_option('sfx_webp_use_avif', false);
        update_option('sfx_webp_excluded_images', []);
        Settings::append_log(__('ImageOptimizer settings reset to defaults.', 'sfxtheme'));
        wp_send_json_success(['message' => __('ImageOptimizer settings reset to defaults.', 'sfxtheme')]);
    }

    public static function get_excluded_images(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $ids = Settings::get_excluded_images();
        wp_send_json_success($ids);
    }

    public static function set_min_size_kb(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $min_size = isset($_POST['min_size_kb']) ? absint($_POST['min_size_kb']) : 0;
        update_option('sfx_webp_min_size_kb', $min_size);
        $log_message = sprintf(__('Min size set to: %dKB', 'sfxtheme'), $min_size);
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_quality(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $quality = isset($_POST['quality']) ? absint($_POST['quality']) : Constants::DEFAULT_QUALITY;
        $quality = max(Constants::MIN_QUALITY, min(Constants::MAX_QUALITY, $quality));
        update_option('sfx_webp_quality', $quality);
        $log_message = sprintf(__('Quality set to: %d', 'sfxtheme'), $quality);
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_batch_size(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : Constants::DEFAULT_BATCH_SIZE;
        $batch_size = max(Constants::MIN_BATCH_SIZE, min(Constants::MAX_BATCH_SIZE, $batch_size));
        update_option('sfx_webp_batch_size', $batch_size);
        $log_message = sprintf(__('Batch size set to: %d', 'sfxtheme'), $batch_size);
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_use_avif(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $use_avif = isset($_POST['use_avif']) ? (bool)$_POST['use_avif'] : false;
        update_option('sfx_webp_use_avif', $use_avif);
        $log_message = sprintf(__('Use AVIF set to: %s', 'sfxtheme'), $use_avif ? 'Yes' : 'No');
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_preserve_originals(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $preserve_originals = isset($_POST['preserve_originals']) ? (bool)$_POST['preserve_originals'] : false;
        update_option('sfx_webp_preserve_originals', $preserve_originals);
        $log_message = sprintf(__('Preserve originals set to: %s', 'sfxtheme'), $preserve_originals ? 'Yes' : 'No');
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_disable_auto_conversion(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
        }
        $disable_auto_conversion = isset($_POST['disable_auto_conversion']) ? (bool)$_POST['disable_auto_conversion'] : false;
        update_option('sfx_webp_disable_auto_conversion', $disable_auto_conversion);
        $log_message = sprintf(__('Auto-conversion on upload set to: %s', 'sfxtheme'), $disable_auto_conversion ? 'Disabled' : 'Enabled');
        Settings::append_log($log_message);
        wp_send_json_success(['message' => $log_message]);
    }

    /**
     * Memory-optimized cleanup of leftover files with batch processing
     */
    public static function cleanup_optimized(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'sfxtheme'));
            return;
        }
        
        wp_raise_memory_limit('admin');
        set_time_limit(Constants::CLEANUP_TIMEOUT_SECONDS);
        
        // Start time to track execution
        $start_time = microtime(true);
        
        // Log start message
        Settings::append_log(sprintf(__('Starting optimized cleanup (memory-aware) at %s', 'sfxtheme'), date('Y-m-d H:i:s')));
        
        // Default batch size - can be adjusted via POST
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : Constants::DEFAULT_CLEANUP_BATCH_SIZE;
        $batch_size = min(Constants::MAX_CLEANUP_BATCH_SIZE, max(Constants::MIN_CLEANUP_BATCH_SIZE, $batch_size));
        
        try {
            // Run the optimized cleanup
            $results = Controller::cleanup_leftover_originals($batch_size);
            
            // Calculate execution time
            $execution_time = round(microtime(true) - $start_time, 2);
            
            // Format response
            $response = [
                'deleted' => $results['deleted'],
                'failed' => $results['failed'],
                'processed' => $results['processed'],
                'file_count' => $results['file_count'] ?? 0,
                'memory_warnings' => $results['memory_warnings'],
                'completed' => $results['completed'],
                'execution_time' => $execution_time,
                'message' => sprintf(
                    __('Cleanup completed in %s seconds. Deleted: %d, Failed: %d, Processed: %d files, Memory warnings: %d', 'sfxtheme'),
                    $execution_time,
                    $results['deleted'],
                    $results['failed'],
                    $results['file_count'] ?? 0,
                    $results['memory_warnings']
                )
            ];
            
            // Add next batch info if needed
            if (!$results['completed']) {
                $response['need_next_batch'] = true;
                $response['message'] .= ' ' . __('More files need processing. Please run again.', 'sfxtheme');
            }
            
            wp_send_json_success($response);
        } catch (\Throwable $e) {
            // Log and handle any exceptions
            Settings::append_log(sprintf(__('Error during optimized cleanup: %s', 'sfxtheme'), $e->getMessage()));
            
            wp_send_json_error([
                'message' => sprintf(__('Error: %s', 'sfxtheme'), $e->getMessage()),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Replace image URL in <img> tag, checking for -scaled variants
     *
     * @param array  $matches         Regex matches
     * @param int    $checked_images  Counter for checked images (passed by reference)
     * @param string $upload_baseurl  Base URL for uploads directory
     * @param string $upload_basedir  Base directory for uploads
     * @param string $extension       Target file extension (webp or avif)
     * @return string Modified tag or original if no replacement found
     */
    private static function replace_image_url_in_tag(array $matches, int &$checked_images, string $upload_baseurl, string $upload_basedir, string $extension): string
    {
        $original_url = $matches[1];
        
        // Skip external images
        if (strpos($original_url, $upload_baseurl) !== 0) {
            return $matches[0];
        }
        
        $checked_images++;
        
        $dirname = pathinfo($original_url, PATHINFO_DIRNAME);
        $filename = pathinfo($original_url, PATHINFO_FILENAME);
        
        // Try direct replacement
        $new_url = $dirname . '/' . $filename . '.' . $extension;
        $scaled_url = $dirname . '/' . $filename . '-scaled.' . $extension;
        
        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $scaled_url))) {
            return str_replace($original_url, $scaled_url, $matches[0]);
        }
        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $new_url))) {
            return str_replace($original_url, $new_url, $matches[0]);
        }
        
        // Try base name fallback (remove size suffix like -1920x1080)
        $base_name = preg_replace('/(-\d+x\d+|-scaled)$/', '', $filename);
        $fallback_url = $dirname . '/' . $base_name . '.' . $extension;
        $fallback_scaled_url = $dirname . '/' . $base_name . '-scaled.' . $extension;
        
        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $fallback_scaled_url))) {
            return str_replace($original_url, $fallback_scaled_url, $matches[0]);
        }
        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $fallback_url))) {
            return str_replace($original_url, $fallback_url, $matches[0]);
        }
        
        return $matches[0];
    }

    /**
     * Replace image URL in <a> tag
     *
     * @param array  $matches         Regex matches
     * @param int    $checked_images  Counter for checked images (passed by reference)
     * @param int    $changed_links   Counter for changed links (passed by reference)
     * @param string $upload_baseurl  Base URL for uploads directory
     * @param string $upload_basedir  Base directory for uploads
     * @param string $extension       Target file extension (webp or avif)
     * @return string Modified tag or original if no replacement found
     */
    private static function replace_image_url_in_link(array $matches, int &$checked_images, int &$changed_links, string $upload_baseurl, string $upload_basedir, string $extension): string
    {
        $result = self::replace_image_url_in_tag($matches, $checked_images, $upload_baseurl, $upload_basedir, $extension);
        if ($result !== $matches[0]) {
            $changed_links++;
        }
        return $result;
    }

    /**
     * Fix image URLs in custom post type meta fields
     *
     * @param int    $post_id         Post ID
     * @param string $upload_baseurl  Base URL for uploads directory
     * @param string $upload_basedir  Base directory for uploads
     * @param string $extension       Target file extension (webp or avif)
     * @param array  $log             Log array (passed by reference)
     * @param int    $checked_images  Counter for checked images (passed by reference)
     * @param int    $changed_links   Counter for changed links (passed by reference)
     * @return int Number of updated meta fields
     */
    private static function fix_meta_field_urls(int $post_id, string $upload_baseurl, string $upload_basedir, string $extension, array &$log, int &$checked_images, int &$changed_links): int
    {
        $updated = 0;
        $meta_fields = get_post_meta($post_id);
        
        foreach ($meta_fields as $meta_key => $meta_values) {
            // Skip internal WordPress and Bricks meta keys
            if (strpos($meta_key, '_') === 0 && strpos($meta_key, '_bricks_') !== 0) {
                continue;
            }
            
            foreach ($meta_values as $meta_value) {
                // Only process strings with potential image URLs
                if (!is_string($meta_value) || (stripos($meta_value, '.jpg') === false && stripos($meta_value, '.jpeg') === false && stripos($meta_value, '.png') === false)) {
                    continue;
                }
                
                $original_meta = $meta_value;
                
                // Replace URLs in meta value
                $meta_value = preg_replace_callback(
                    '/(https?:\/\/[^\s"\'<>]+\.(?:jpg|jpeg|png))/i',
                    function ($matches) use (&$checked_images, &$changed_links, $upload_baseurl, $upload_basedir, $extension) {
                        $original_url = $matches[1];
                        
                        // Skip external images
                        if (strpos($original_url, $upload_baseurl) !== 0) {
                            return $matches[0];
                        }
                        
                        $checked_images++;
                        
                        $dirname = pathinfo($original_url, PATHINFO_DIRNAME);
                        $filename = pathinfo($original_url, PATHINFO_FILENAME);
                        $new_url = $dirname . '/' . $filename . '.' . $extension;
                        $scaled_url = $dirname . '/' . $filename . '-scaled.' . $extension;
                        
                        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $scaled_url))) {
                            $changed_links++;
                            return $scaled_url;
                        }
                        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $new_url))) {
                            $changed_links++;
                            return $new_url;
                        }
                        
                        // Try base name fallback
                        $base_name = preg_replace('/(-\d+x\d+|-scaled)$/', '', $filename);
                        $fallback_url = $dirname . '/' . $base_name . '.' . $extension;
                        $fallback_scaled_url = $dirname . '/' . $base_name . '-scaled.' . $extension;
                        
                        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $fallback_scaled_url))) {
                            $changed_links++;
                            return $fallback_scaled_url;
                        }
                        if (file_exists(str_replace($upload_baseurl, $upload_basedir, $fallback_url))) {
                            $changed_links++;
                            return $fallback_url;
                        }
                        
                        return $matches[0];
                    },
                    $meta_value
                );
                
                if ($meta_value !== $original_meta) {
                    update_post_meta($post_id, $meta_key, $meta_value);
                    $updated++;
                    $log[] = sprintf(__('Updated meta field "%s" in post ID %d', 'sfxtheme'), sanitize_key($meta_key), $post_id);
                }
            }
        }
        
        return $updated;
    }

    /**
     * Revert an excluded image back to its original format (PNG/JPG)
     * Only works if original was preserved on disk
     */
    public static function revert_to_original(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        
        if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
            wp_send_json_error(__('Permission denied or invalid attachment ID', 'sfxtheme'));
            return;
        }

        $attachment_id = absint($_POST['attachment_id']);
        $log = [];

        $converted_file = get_attached_file($attachment_id);
        
        if (!$converted_file || !file_exists($converted_file)) {
            wp_send_json_error(__('Attachment file not found', 'sfxtheme'));
            return;
        }

        $path_info = pathinfo($converted_file);
        $dirname = $path_info['dirname'];
        $basename = $path_info['filename'];
        $current_ext = $path_info['extension'];

        // Check if it's already in original format
        if (!in_array($current_ext, ['webp', 'avif'], true)) {
            wp_send_json_error(sprintf(
                __('Image is already in original format (%s)', 'sfxtheme'),
                strtoupper($current_ext)
            ));
            return;
        }

        // Try to find original file
        $original_extensions = ['png', 'PNG', 'jpg', 'JPG', 'jpeg', 'JPEG'];
        $original_file = null;
        $original_ext = null;

        foreach ($original_extensions as $ext) {
            $potential_original = "$dirname/$basename.$ext";
            if (file_exists($potential_original)) {
                $original_file = $potential_original;
                $original_ext = $ext;
                break;
            }
        }

        if (!$original_file) {
            wp_send_json_error(sprintf(
                __('Original file not found. It may have been deleted. Preserve Originals must be enabled before conversion to use this feature.', 'sfxtheme')
            ));
            return;
        }

        // Get file sizes for logging
        $original_size = filesize($original_file) / 1024;
        $converted_size = filesize($converted_file) / 1024;

        // Update attachment to point to original
        update_attached_file($attachment_id, $original_file);
        
        // Update MIME type
        $mime_type = wp_check_filetype($original_file)['type'];
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $mime_type
        ]);

        // Regenerate metadata for the original
        $metadata = wp_generate_attachment_metadata($attachment_id, $original_file);
        if (!is_wp_error($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        // Delete the converted WebP/AVIF files
        $files_deleted = [];
        
        // Delete main converted file
        if (file_exists($converted_file)) {
            @unlink($converted_file);
            $files_deleted[] = basename($converted_file);
        }

        // Delete converted size variations
        $mode = Settings::get_resize_mode();
        $max_values = Settings::get_max_values();
        $extension = Settings::get_extension();

        foreach ($max_values as $index => $dimension) {
            if ($index === 0) continue;
            $size_file = "$dirname/$basename-$dimension$extension";
            if (file_exists($size_file)) {
                @unlink($size_file);
                $files_deleted[] = basename($size_file);
            }
        }

        // Delete thumbnail
        $thumbnail_file = "$dirname/$basename-150x150$extension";
        if (file_exists($thumbnail_file)) {
            @unlink($thumbnail_file);
            $files_deleted[] = basename($thumbnail_file);
        }

        // Ensure it's in exclusion list
        Settings::add_excluded_image($attachment_id);
        
        // CRITICAL: Mark this image as "restored" so future optimizations preserve the original
        // Store this in attachment metadata to persist across exclusion changes
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['_sfx_was_restored'] = true;
        wp_update_attachment_metadata($attachment_id, $meta);

        // Log the reversion
        $log[] = sprintf(
            __('Reverted: %s (ID %d) from %s back to %s (%.1f KB → %.1f KB)', 'sfxtheme'),
            basename($original_file),
            $attachment_id,
            strtoupper($current_ext),
            strtoupper($original_ext),
            $converted_size,
            $original_size
        );
        
        if (!empty($files_deleted)) {
            $log[] = sprintf(
                __('Deleted converted files: %s', 'sfxtheme'),
                implode(', ', $files_deleted)
            );
        }

        Settings::append_log($log);

        wp_send_json_success([
            'message' => sprintf(
                __('Successfully reverted to original %s format. Converted files deleted.', 'sfxtheme'),
                strtoupper($original_ext)
            ),
            'original_format' => strtoupper($original_ext),
            'original_size' => round($original_size, 1),
            'converted_size' => round($converted_size, 1),
            'files_deleted' => count($files_deleted)
        ]);
    }

    private static function fix_post_thumbnail(int $post_id, string $extension, bool $use_avif, array &$log, array $excluded_images = []): void
    {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        
        if (!$thumbnail_id || in_array($thumbnail_id, $excluded_images, true)) {
            return;
        }
        
        $thumbnail_path = get_attached_file($thumbnail_id);
        
        if (!$thumbnail_path || str_ends_with($thumbnail_path, '.' . $extension)) {
            return;
        }
        
        $new_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.' . $extension, $thumbnail_path);
        
        if (file_exists($new_path)) {
            update_attached_file($thumbnail_id, $new_path);
            wp_update_post(['ID' => $thumbnail_id, 'post_mime_type' => $use_avif ? 'image/avif' : 'image/webp']);
            $metadata = wp_generate_attachment_metadata($thumbnail_id, $new_path);
            wp_update_attachment_metadata($thumbnail_id, $metadata);
            $log[] = sprintf(__('Updated thumbnail: %s → %s', 'sfxtheme'), basename($thumbnail_path), basename($new_path));
        }
    }
} 