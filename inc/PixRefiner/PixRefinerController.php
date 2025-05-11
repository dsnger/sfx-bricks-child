<?php
declare(strict_types=1);

namespace SFX\PixRefiner;


/** 
 * PixRefiner v3.1
 * Helper function for formatting file sizes
 */


class PixRefinerController
{
    public static function init(): void
    {
        // Register image size and conversion hooks
        add_filter('intermediate_image_sizes_advanced', [Settings::class, 'limit_image_sizes']);
        add_action('admin_init', [Settings::class, 'set_thumbnail_size']);
        add_action('after_setup_theme', [Settings::class, 'register_custom_sizes']);
        // Register admin page, AJAX, and asset hooks
        AdminPage::register();
        Ajax::register();
        AssetManager::register();
        // Register upload conversion filter
        add_filter('wp_handle_upload', [self::class, 'handle_upload_convert_to_format'], 10, 1);
    }

    /**
     * Convert uploaded images to WebP/AVIF on upload
     */
    public static function handle_upload_convert_to_format(array $upload): array
    {
        if (Settings::get_disable_auto_conversion()) {
            return $upload;
        }
        $file_extension = strtolower(pathinfo($upload['file'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
        if (!in_array($file_extension, $allowed_extensions, true)) {
            return $upload;
        }
        $use_avif = Settings::get_use_avif();
        $format = $use_avif ? 'image/avif' : 'image/webp';
        $extension = $use_avif ? '.avif' : '.webp';
        $file_path = $upload['file'];
        $uploads_dir = dirname($file_path);
        $log = get_option('webp_conversion_log', []);
        if (!is_writable($uploads_dir)) {
            $log[] = sprintf(__('Error: Uploads directory %s is not writable', 'wpturbo'), $uploads_dir);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
            return $upload;
        }
        $file_size_kb = filesize($file_path) / 1024;
        $min_size_kb = Settings::get_min_size_kb();
        if ($min_size_kb > 0 && $file_size_kb < $min_size_kb) {
            $log[] = sprintf(__('Skipped: %s (size %s KB < %d KB)', 'wpturbo'), basename($file_path), round($file_size_kb, 2), $min_size_kb);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
            return $upload;
        }
        $mode = Settings::get_resize_mode();
        $max_values = ($mode === 'width') ? Settings::get_max_widths() : Settings::get_max_heights();
        $attachment_id = function_exists('attachment_url_to_postid') ? attachment_url_to_postid($upload['url']) : null;
        $new_files = [];
        $success = true;
        foreach ($max_values as $index => $dimension) {
            $suffix = ($index === 0) ? '' : "-{$dimension}";
            $new_file_path = \SFX\PixRefiner\FormatConverter::convert_to_format($file_path, $dimension, $log, $attachment_id, $suffix);
            if ($new_file_path) {
                if ($index === 0) {
                    $upload['file'] = $new_file_path;
                    $upload['url'] = str_replace(basename($file_path), basename($new_file_path), $upload['url']);
                    $upload['type'] = $format;
                }
                $new_files[] = $new_file_path;
            } else {
                $success = false;
                break;
            }
        }
        // Generate thumbnail
        if ($success) {
            $editor = wp_get_image_editor($file_path);
            if (!is_wp_error($editor)) {
                $editor->resize(150, 150, true);
                $thumbnail_path = dirname($file_path) . '/' . pathinfo($file_path, PATHINFO_FILENAME) . '-150x150' . $extension;
                $saved = $editor->save($thumbnail_path, $format, ['quality' => Settings::get_quality()]);
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
            update_option('webp_conversion_log', array_slice((array)$log, -500));
            return $upload;
        }
        // Update metadata only if all conversions succeeded
        if ($attachment_id && !empty($new_files)) {
            $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            if (!is_wp_error($metadata)) {
                $base_name = pathinfo($file_path, PATHINFO_FILENAME);
                $dirname = dirname($file_path);
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
                $thumbnail_file = "$dirname/$base_name-150x150$extension";
                if (file_exists($thumbnail_file)) {
                    $metadata['sizes']['thumbnail'] = [
                        'file' => "$base_name-150x150$extension",
                        'width' => 150,
                        'height' => 150,
                        'mime-type' => $format
                    ];
                } else {
                    $editor = wp_get_image_editor($upload['file']);
                    if (!is_wp_error($editor)) {
                        $editor->resize(150, 150, true);
                        $saved = $editor->save($thumbnail_file, $format, ['quality' => Settings::get_quality()]);
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
                $metadata['webp_quality'] = Settings::get_quality();
                update_attached_file($attachment_id, $upload['file']);
                wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $format]);
                wp_update_attachment_metadata($attachment_id, $metadata);
            } else {
                $log[] = sprintf(__('Error: Metadata regeneration failed for %s - %s', 'wpturbo'), basename($file_path), $metadata->get_error_message());
            }
        }
        // Delete original only if all conversions succeeded and not preserved
        if ($file_extension !== ($use_avif ? 'avif' : 'webp') && file_exists($file_path) && !Settings::get_preserve_originals()) {
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
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return $upload;
    }
}