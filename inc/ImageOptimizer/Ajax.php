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
        add_action('wp_ajax_webp_set_use_avif', [self::class, 'set_use_avif']);
        add_action('wp_ajax_webp_set_preserve_originals', [self::class, 'set_preserve_originals']);
        add_action('wp_ajax_webp_set_disable_auto_conversion', [self::class, 'set_disable_auto_conversion']);
        // Add other AJAX handlers as needed
    }

    public static function add_excluded_image(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
            wp_send_json_error(__('Permission denied or invalid attachment ID', 'wpturbo'));
        }
        $attachment_id = absint($_POST['attachment_id']);
        if (Settings::add_excluded_image($attachment_id)) {
            wp_send_json_success(['message' => __('Image excluded successfully', 'wpturbo')]);
        } else {
            wp_send_json_error(__('Image already excluded or invalid ID', 'wpturbo'));
        }
    }

    public static function remove_excluded_image(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
            wp_send_json_error(__('Permission denied or invalid attachment ID', 'wpturbo'));
        }
        $attachment_id = absint($_POST['attachment_id']);
        if (Settings::remove_excluded_image($attachment_id)) {
            wp_send_json_success(['message' => __('Image removed from exclusion list', 'wpturbo')]);
        } else {
            wp_send_json_error(__('Image not in exclusion list', 'wpturbo'));
        }
    }

    public static function convert_single_image(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['offset'])) {
            wp_send_json_error(__('Permission denied or invalid offset', 'wpturbo'));
        }
        $offset = absint($_POST['offset']);
        $batch_size = Settings::get_batch_size();
        wp_raise_memory_limit('image');
        set_time_limit(max(30, 10 * $batch_size));
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'fields' => 'ids',
            'post__not_in' => Settings::get_excluded_images(),
        ];
        $attachments = get_posts($args);
        $log = get_option('webp_conversion_log', []);
        $mode = Settings::get_resize_mode();
        $max_values = ($mode === 'width') ? Settings::get_max_widths() : Settings::get_max_heights();
        $current_quality = Settings::get_quality();
        $min_size_kb = Settings::get_min_size_kb();
        $use_avif = Settings::get_use_avif();
        $extension = $use_avif ? '.avif' : '.webp';
        $format = $use_avif ? 'image/avif' : 'image/webp';
        if (empty($attachments)) {
            update_option('webp_conversion_complete', true);
            $log[] = "<span style='font-weight: bold; color: #281E5D;'>" . __('Conversion Complete', 'wpturbo') . "</span>: " . __('No more images to process', 'wpturbo');
            update_option('webp_conversion_log', array_slice((array)$log, -500));
            wp_send_json_success(['complete' => true]);
        }
        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!file_exists($file_path)) {
                $log[] = sprintf(__('Skipped: File not found for Attachment ID %d', 'wpturbo'), $attachment_id);
                continue;
            }
            $uploads_dir = dirname($file_path);
            if (!is_writable($uploads_dir)) {
                $log[] = sprintf(__('Error: Uploads directory %s is not writable for Attachment ID %d', 'wpturbo'), $uploads_dir, $attachment_id);
                continue;
            }
            $file_size_kb = filesize($file_path) / 1024;
            if ($min_size_kb > 0 && $file_size_kb < $min_size_kb) {
                $log[] = sprintf(__('Skipped: %s (size %s KB < %d KB)', 'wpturbo'), basename($file_path), round($file_size_kb, 2), $min_size_kb);
                continue;
            }
            $metadata = wp_get_attachment_metadata($attachment_id);
            $existing_quality = isset($metadata['webp_quality']) ? (int) $metadata['webp_quality'] : null;
            $current_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $is_current_format = $current_extension === ($use_avif ? 'avif' : 'webp');
            $reprocess = !$is_current_format || $existing_quality !== $current_quality;
            if ($is_current_format && !$reprocess) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $current_size = $editor->get_size();
                    $current_dimension = ($mode === 'width') ? $current_size['width'] : $current_size['height'];
                    $reprocess = !in_array($current_dimension, $max_values);
                }
            }
            if (!$reprocess) continue;
            $dirname = dirname($file_path);
            $base_name = pathinfo($file_path, PATHINFO_FILENAME);
            // Step 1: Delete old additional sizes not in current max_values
            if ($is_current_format) {
                $old_metadata = wp_get_attachment_metadata($attachment_id);
                if (isset($old_metadata['sizes'])) {
                    foreach ($old_metadata['sizes'] as $size_name => $size_data) {
                        if (preg_match('/custom-(\d+)/', $size_name, $matches)) {
                            $old_dimension = (int) $matches[1];
                            if (!in_array($old_dimension, $max_values)) {
                                $old_file = "$dirname/$base_name-$old_dimension$extension";
                                if (file_exists($old_file)) {
                                    @unlink($old_file);
                                    $log[] = sprintf(__('Deleted outdated size: %s', 'wpturbo'), basename($old_file));
                                }
                            }
                        }
                    }
                }
            }
            // Step 2: Generate new sizes with rollback on failure
            $new_files = [];
            $success = true;
            foreach ($max_values as $index => $dimension) {
                $suffix = ($index === 0) ? '' : "-{$dimension}";
                $new_file_path = FormatConverter::convert_to_format($file_path, $dimension, $log, $attachment_id, $suffix);
                if ($new_file_path) {
                    if ($index === 0) {
                        update_attached_file($attachment_id, $new_file_path);
                        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $format]);
                    }
                    $new_files[] = $new_file_path;
                } else {
                    $success = false;
                    break;
                }
            }
            // Step 3: Generate thumbnail
            if ($success) {
                $editor = wp_get_image_editor($file_path);
                if (!is_wp_error($editor)) {
                    $editor->resize(150, 150, true);
                    $thumbnail_path = "$dirname/$base_name-150x150$extension";
                    $saved = $editor->save($thumbnail_path, $format, ['quality' => $current_quality]);
                    if (!is_wp_error($saved)) {
                        $log[] = sprintf(__('Generated thumbnail: %s', 'wpturbo'), basename($thumbnail_path));
                        $new_files[] = $thumbnail_path;
                    } else {
                        $success = false;
                    }
                } else {
                    $success = false;
                }
            }
            // Rollback if any conversion failed
            if (!$success) {
                foreach ($new_files as $file) {
                    if (file_exists($file)) @unlink($file);
                }
                $log[] = sprintf(__('Error: Conversion failed for %s, rolling back', 'wpturbo'), basename($file_path));
                $log[] = sprintf(__('Original preserved: %s', 'wpturbo'), basename($file_path));
                continue;
            }
            // Step 4: Regenerate metadata with only current sizes
            if ($attachment_id && !empty($new_files)) {
                $metadata = wp_generate_attachment_metadata($attachment_id, $new_files[0]);
                if (!is_wp_error($metadata)) {
                    $metadata['sizes'] = [];
                    foreach ($max_values as $index => $dimension) {
                        if ($index === 0) continue;
                        $size_file = "$dirname/$base_name-$dimension$extension";
                        if (file_exists($size_file)) {
                            $metadata['sizes']["custom-$dimension"] = [
                                'file' => "$base_name-$dimension$extension",
                                'width' => ($mode === 'width') ? $dimension : 0,
                                'height' => ($mode === 'height') ? $dimension : 0,
                                'mime-type' => $format
                            ];
                        }
                    }
                    // Ensure thumbnail metadata is always updated
                    $thumbnail_file = "$dirname/$base_name-150x150$extension";
                    if (file_exists($thumbnail_file)) {
                        $metadata['sizes']['thumbnail'] = [
                            'file' => "$base_name-150x150$extension",
                            'width' => 150,
                            'height' => 150,
                            'mime-type' => $format
                        ];
                    } else {
                        $editor = wp_get_image_editor($new_files[0]);
                        if (!is_wp_error($editor)) {
                            $editor->resize(150, 150, true);
                            $saved = $editor->save($thumbnail_file, $format, ['quality' => $current_quality]);
                            if (!is_wp_error($saved)) {
                                $metadata['sizes']['thumbnail'] = [
                                    'file' => "$base_name-150x150$extension",
                                    'width' => 150,
                                    'height' => 150,
                                    'mime-type' => $format
                                ];
                                $log[] = sprintf(__('Regenerated missing thumbnail: %s', 'wpturbo'), basename($thumbnail_file));
                            }
                        }
                    }
                    $metadata['webp_quality'] = $current_quality;
                    wp_update_attachment_metadata($attachment_id, $metadata);
                } else {
                    $log[] = sprintf(__('Error: Metadata regeneration failed for %s', 'wpturbo'), basename($file_path));
                }
            }
            // Step 5: Delete original if not preserved
            if (!$is_current_format && file_exists($file_path) && !Settings::get_preserve_originals()) {
                $attempts = 0;
                $chmod_failed = false;
                while ($attempts < 5 && file_exists($file_path)) {
                    if (!is_writable($file_path)) {
                        @chmod($file_path, 0644);
                        if (!is_writable($file_path)) {
                            if ($chmod_failed) {
                                $log[] = sprintf(__('Error: Cannot make %s writable after retry - skipping deletion', 'wpturbo'), basename($file_path));
                                break;
                            }
                            $chmod_failed = true;
                        }
                    }
                    if (@unlink($file_path)) {
                        $log[] = sprintf(__('Deleted original: %s', 'wpturbo'), basename($file_path));
                        break;
                    }
                    $attempts++;
                    sleep(1);
                }
                if (file_exists($file_path)) {
                    $log[] = sprintf(__('Error: Failed to delete original %s after 5 retries', 'wpturbo'), basename($file_path));
                }
            }
        }
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['complete' => false, 'offset' => $offset + $batch_size]);
    }

    public static function export_media_zip(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
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
            wp_send_json_error(__('No media files found', 'wpturbo'));
        }
        $temp_file = tempnam(sys_get_temp_dir(), 'webp_media_export_');
        if (!$temp_file) {
            wp_send_json_error(__('Failed to create temporary file', 'wpturbo'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($temp_file);
            wp_send_json_error(__('Failed to create ZIP archive', 'wpturbo'));
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
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $log = get_option('webp_conversion_log', []);
        $mode = Settings::get_resize_mode();
        $max_values = ($mode === 'width') ? Settings::get_max_widths() : Settings::get_max_heights();
        $use_avif = Settings::get_use_avif();
        $extension = $use_avif ? '.avif' : '.webp';
        $format = $use_avif ? 'image/avif' : 'image/webp';
        $preserve_originals = Settings::get_preserve_originals();
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
                $thumb = $dirname . '/' . $base_name . '-150x150' . $extension;
                if ($candidate === $thumb) {
                    $is_current = true;
                }
                // Optionally keep original
                if ($preserve_originals && $candidate === $file_path) {
                    $is_current = true;
                }
                if (!$is_current && file_exists($candidate)) {
                    @unlink($candidate);
                    $deleted++;
                    $log[] = sprintf(__('Deleted old file: %s', 'wpturbo'), basename($candidate));
                }
            }
        }
        $log[] = sprintf(__('Cleanup complete. %d files deleted.', 'wpturbo'), $deleted);
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => sprintf(__('Cleanup complete. %d files deleted.', 'wpturbo'), $deleted)]);
    }

    public static function fix_post_image_urls(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $log = get_option('webp_conversion_log', []);
        $use_avif = Settings::get_use_avif();
        $extension = $use_avif ? 'avif' : 'webp';
        $format = $use_avif ? 'image/avif' : 'image/webp';
        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];
        $posts = get_posts($args);
        $updated = 0;
        foreach ($posts as $post_id) {
            $content = get_post_field('post_content', $post_id);
            $new_content = preg_replace_callback(
                '/(wp-content\\/uploads\\/[^"\'>]+)\\.(jpg|jpeg|png|webp|avif)/i',
                function ($matches) use ($extension) {
                    return $matches[1] . '.' . $extension;
                },
                $content
            );
            if ($new_content !== $content) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $new_content,
                ]);
                $updated++;
                $log[] = sprintf(__('Updated image URLs in post ID %d', 'wpturbo'), $post_id);
            }
        }
        $log[] = sprintf(__('Fix URLs complete. %d posts updated.', 'wpturbo'), $updated);
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => sprintf(__('Fix URLs complete. %d posts updated.', 'wpturbo'), $updated)]);
    }

    public static function set_max_widths(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $widths = isset($_POST['widths']) ? sanitize_text_field($_POST['widths']) : '';
        $widths_arr = array_map('absint', array_filter(explode(',', $widths)));
        $widths_arr = array_filter($widths_arr, function($w) { return $w > 0 && $w <= 9999; });
        if (empty($widths_arr)) {
            wp_send_json_error(__('Invalid widths', 'wpturbo'));
        }
        $final_widths = array_slice($widths_arr, 0, 4);
        update_option('sfx_webp_max_widths', implode(',', $final_widths));
        $log = get_option('sfx_webp_conversion_log', []);
        $log_message = sprintf(__('Max widths set to: %spx', 'wpturbo'), implode(', ', $final_widths));
        $log[] = $log_message;
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_max_heights(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $heights = isset($_POST['heights']) ? sanitize_text_field($_POST['heights']) : '';
        $heights_arr = array_map('absint', array_filter(explode(',', $heights)));
        $heights_arr = array_filter($heights_arr, function($h) { return $h > 0 && $h <= 9999; });
        if (empty($heights_arr)) {
            wp_send_json_error(__('Invalid heights', 'wpturbo'));
        }
        $final_heights = array_slice($heights_arr, 0, 4);
        update_option('sfx_webp_max_heights', implode(',', $final_heights));
        $log = get_option('sfx_webp_conversion_log', []);
        $log_message = sprintf(__('Max heights set to: %spx', 'wpturbo'), implode(', ', $final_heights));
        $log[] = $log_message;
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => $log_message]);
    }

    public static function clear_log(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        update_option('webp_conversion_log', []);
        wp_send_json_success(['message' => __('Log cleared.', 'wpturbo')]);
    }

    public static function reset_defaults(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        update_option('sfx_webp_max_widths', '1920,1200,600,300');
        update_option('sfx_webp_max_heights', '1080,720,480,360');
        update_option('sfx_webp_resize_mode', 'width');
        update_option('sfx_webp_quality', 80);
        update_option('sfx_webp_batch_size', 5);
        update_option('sfx_webp_preserve_originals', false);
        update_option('sfx_webp_disable_auto_conversion', false);
        update_option('sfx_webp_min_size_kb', 0);
        update_option('sfx_webp_use_avif', false);
        update_option('sfx_webp_excluded_images', []);
        $log = get_option('sfx_webp_conversion_log', []);
        $log[] = __('ImageOptimizer settings reset to defaults.', 'wpturbo');
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => __('ImageOptimizer settings reset to defaults.', 'wpturbo')]);
    }

    public static function get_excluded_images(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $ids = Settings::get_excluded_images();
        wp_send_json_success($ids);
    }

    public static function set_min_size_kb(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $min_size = isset($_POST['min_size_kb']) ? absint($_POST['min_size_kb']) : 0;
        update_option('sfx_webp_min_size_kb', $min_size);
        $log = get_option('sfx_webp_conversion_log', []);
        $log_message = sprintf(__('Min size set to: %dKB', 'wpturbo'), $min_size);
        $log[] = $log_message;
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_use_avif(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $use_avif = isset($_POST['use_avif']) ? (bool)$_POST['use_avif'] : false;
        update_option('sfx_webp_use_avif', $use_avif);
        $log = get_option('sfx_webp_conversion_log', []);
        $log_message = sprintf(__('Use AVIF set to: %s', 'wpturbo'), $use_avif ? 'Yes' : 'No');
        $log[] = $log_message;
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_preserve_originals(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $preserve_originals = isset($_POST['preserve_originals']) ? (bool)$_POST['preserve_originals'] : false;
        update_option('sfx_webp_preserve_originals', $preserve_originals);
        $log = get_option('sfx_webp_conversion_log', []);
        $log_message = sprintf(__('Preserve originals set to: %s', 'wpturbo'), $preserve_originals ? 'Yes' : 'No');
        $log[] = $log_message;
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => $log_message]);
    }

    public static function set_disable_auto_conversion(): void
    {
        check_ajax_referer('webp_converter_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
        }
        $disable_auto_conversion = isset($_POST['disable_auto_conversion']) ? (bool)$_POST['disable_auto_conversion'] : false;
        update_option('sfx_webp_disable_auto_conversion', $disable_auto_conversion);
        $log = get_option('sfx_webp_conversion_log', []);
        $log_message = sprintf(__('Auto-conversion on upload set to: %s', 'wpturbo'), $disable_auto_conversion ? 'Disabled' : 'Enabled');
        $log[] = $log_message;
        update_option('sfx_webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => $log_message]);
    }

    /**
     * Memory-optimized cleanup of leftover files with batch processing
     */
    public static function cleanup_optimized(): void
    {
        // Debug info
        $debug_info = [];
        $debug_info['ajax_called'] = true;
        
        // Verify nonce with detailed error if it fails
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'webp_converter_nonce')) {
            $debug_info['nonce_error'] = true;
            $debug_info['provided_nonce'] = isset($_POST['nonce']) ? substr($_POST['nonce'], 0, 5) . '...' : 'missing';
            wp_send_json_error([
                'message' => __('Security verification failed. Try refreshing the page.', 'wpturbo'),
                'debug' => $debug_info
            ]);
            return;
        }
        
        // Verify capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wpturbo'));
            return;
        }
        
        wp_raise_memory_limit('admin');
        set_time_limit(300); // 5 minutes timeout
        
        // Start time to track execution
        $start_time = microtime(true);
        
        // Log start message
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Starting optimized cleanup (memory-aware) at %s', 'wpturbo'), date('Y-m-d H:i:s'));
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        
        // Default batch size - can be adjusted via POST
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 1000;
        $batch_size = min(5000, max(100, $batch_size)); // Between 100 and 5000
        
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
                    __('Cleanup completed in %s seconds. Deleted: %d, Failed: %d, Processed: %d files, Memory warnings: %d', 'wpturbo'),
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
                $response['message'] .= ' ' . __('More files need processing. Please run again.', 'wpturbo');
            }
            
            wp_send_json_success($response);
        } catch (\Throwable $e) {
            // Log and handle any exceptions
            $log[] = sprintf(__('Error during optimized cleanup: %s', 'wpturbo'), $e->getMessage());
            update_option('webp_conversion_log', array_slice((array)$log, -500));
            
            wp_send_json_error([
                'message' => sprintf(__('Error: %s', 'wpturbo'), $e->getMessage()),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
} 