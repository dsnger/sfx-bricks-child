<?php

declare(strict_types=1);

namespace SFX\ImageOptimizer;


/** 
 * ImageOptimizer v3.1
 * Helper function for formatting file sizes
 */


class Controller
{
    /**
     * Static file existence cache to prevent redundant filesystem checks
     * 
     * @var array
     */
    private static $file_cache = [];

    /**
     * Memory usage threshold (percentage of limit)
     * 
     * @var float
     */
    private static $memory_threshold = 0.85; // 85% of memory_limit

    /**
     * Initialize the controller by registering all hooks
     */
    public function __construct()
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
        // Register .htaccess/MIME type update on theme activation and option change
        add_action('after_switch_theme', [self::class, 'ensure_mime_types']);
        add_action('update_option_webp_use_avif', [self::class, 'ensure_mime_types']);
        // Register attachment deletion cleanup
        add_action('delete_attachment', [self::class, 'delete_attachment_files'], 10, 1);
        // Register custom srcset filter
        add_filter('wp_calculate_image_srcset', [self::class, 'custom_srcset'], 10, 5);
        // Disable big image scaling
        add_filter('big_image_size_threshold', '__return_false', 999);
        // Register custom metadata filter
        add_filter('wp_generate_attachment_metadata', [self::class, 'fix_format_metadata'], 10, 2);
    }

    /**
     * Check if a file exists, using cache if available
     * 
     * @param string $file_path Path to check
     * @return bool True if file exists
     */
    private static function file_exists_cached(string $file_path): bool
    {
        if (!isset(self::$file_cache[$file_path])) {
            self::$file_cache[$file_path] = file_exists($file_path);
        }
        return self::$file_cache[$file_path];
    }

    /**
     * Clear the file existence cache
     */
    public static function clear_file_cache(): void
    {
        self::$file_cache = [];
    }

    /**
     * Check if available memory is sufficient for continued processing
     * 
     * @param int|null $batch_size Current batch size to potentially reduce
     * @return bool|int False if adequate memory, or reduced batch size
     */
    public static function check_memory_usage(?int &$batch_size = null)
    {
        // Get memory limits
        $memory_limit = self::get_memory_limit();
        if ($memory_limit === -1) {
            // No memory limit set
            return false;
        }

        // Check current usage against threshold
        $current_usage = memory_get_usage(true);
        $threshold = $memory_limit * self::$memory_threshold;

        if ($current_usage < $threshold) {
            // Memory usage is below threshold
            return false;
        }

        // If batch size is provided, reduce it
        if ($batch_size !== null && $batch_size > 1) {
            $batch_size = max(1, (int)($batch_size / 2));
            return $batch_size;
        }

        return true;
    }

    /**
     * Get PHP memory limit in bytes
     * 
     * @return int Memory limit in bytes (-1 for no limit)
     */
    private static function get_memory_limit(): int
    {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === '-1') {
            return -1; // No limit
        }

        // Convert to bytes
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int)$memory_limit;

        switch ($unit) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
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

        // Clear file cache before starting
        self::clear_file_cache();

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

        // Check memory usage before processing
        self::check_memory_usage();

        foreach ($max_values as $index => $dimension) {
            $suffix = ($index === 0) ? '' : "-{$dimension}";
            $new_file_path = \SFX\ImageOptimizer\FormatConverter::convert_to_format($file_path, $dimension, $log, $attachment_id, $suffix);
            if ($new_file_path) {
                if ($index === 0) {
                    $upload['file'] = $new_file_path;
                    $upload['url'] = str_replace(basename($file_path), basename($new_file_path), $upload['url']);
                    $upload['type'] = $format;
                }
                $new_files[] = $new_file_path;
                // Add to file cache
                self::$file_cache[$new_file_path] = true;
            } else {
                $success = false;
                break;
            }

            // Check memory after each dimension
            if (self::check_memory_usage()) {
                // Force garbage collection if available
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
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
                    // Add to file cache
                    self::$file_cache[$thumbnail_path] = true;
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
                if (self::file_exists_cached($file)) {
                    @unlink($file);
                    // Update cache
                    self::$file_cache[$file] = false;
                }
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
                    if (self::file_exists_cached($size_file)) {
                        $metadata['sizes']["custom-$dimension"] = [
                            'file' => "$base_name-$dimension$extension",
                            'width' => ($mode === 'width') ? $dimension : 0,
                            'height' => ($mode === 'height') ? $dimension : 0,
                            'mime-type' => $format
                        ];
                    }
                }
                $thumbnail_file = "$dirname/$base_name-150x150$extension";
                if (self::file_exists_cached($thumbnail_file)) {
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
                            // Update file cache
                            self::$file_cache[$thumbnail_file] = true;
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
        if ($file_extension !== ($use_avif ? 'avif' : 'webp') && self::file_exists_cached($file_path) && !Settings::get_preserve_originals()) {
            $attempts = 0;
            $chmod_failed = false;
            while ($attempts < 5 && self::file_exists_cached($file_path)) {
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
                    // Update file cache
                    self::$file_cache[$file_path] = false;
                    break;
                }
                $attempts++;
                sleep(1);
            }
            if (self::file_exists_cached($file_path)) {
                $log[] = sprintf(__('Error: Failed to delete original %s after 5 retries', 'wpturbo'), basename($file_path));
            }
        }
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return $upload;
    }

    /**
     * Ensure WebP/AVIF MIME types in .htaccess (Apache only)
     */
    public static function ensure_mime_types(): void
    {
        $htaccess_file = ABSPATH . '.htaccess';
        if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
            return;
        }
        $content = file_get_contents($htaccess_file);
        $webp_mime = "AddType image/webp .webp";
        $avif_mime = "AddType image/avif .avif";
        if (strpos($content, $webp_mime) === false || strpos($content, $avif_mime) === false) {
            $new_content = "# BEGIN WebP Converter MIME Types\n";
            if (strpos($content, $webp_mime) === false) {
                $new_content .= "$webp_mime\n";
            }
            if (strpos($content, $avif_mime) === false) {
                $new_content .= "$avif_mime\n";
            }
            $new_content .= "# END WebP Converter MIME Types\n";
            $content .= "\n" . $new_content;
            file_put_contents($htaccess_file, $content);
        }
    }

    /**
     * Clean up all related files on attachment deletion
     */
    public static function delete_attachment_files(int $attachment_id): void
    {
        $excluded = Settings::get_excluded_images();
        if (in_array($attachment_id, $excluded, true)) return;
        $file = get_attached_file($attachment_id);
        if ($file && file_exists($file)) @unlink($file);
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && isset($metadata['sizes'])) {
            $upload_dir = wp_upload_dir()['basedir'];
            foreach ($metadata['sizes'] as $size) {
                $size_file = $upload_dir . '/' . dirname($metadata['file']) . '/' . $size['file'];
                if (file_exists($size_file)) @unlink($size_file);
            }
        }

        // --- DEBUG LOGGING FOR ORIGINAL FILE DELETION ---
        if ($file) {
            $dir = dirname($file);
            $base = pathinfo($file, PATHINFO_FILENAME);
            $possible_exts = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];
            foreach ($possible_exts as $ext) {
                $original = "$dir/$base.$ext";
                if (file_exists($original)) {
                    error_log('Deleting original: ' . $original);
                    @unlink($original);
                } else {
                    error_log('File does not exist: ' . $original);
                }
            }
        }
    }

    /**
     * Custom srcset to include all sizes in current format
     */
    public static function custom_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (in_array($attachment_id, Settings::get_excluded_images(), true)) {
            return $sources;
        }
        $use_avif = Settings::get_use_avif();
        $extension = $use_avif ? '.avif' : '.webp';
        $mode = Settings::get_resize_mode();
        $max_values = ($mode === 'width') ? Settings::get_max_widths() : Settings::get_max_heights();
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . '/' . dirname($image_meta['file']);
        $base_name = pathinfo($image_meta['file'], PATHINFO_FILENAME);
        $base_url = $upload_dir['baseurl'] . '/' . dirname($image_meta['file']);
        foreach ($max_values as $index => $dimension) {
            if ($index === 0) continue;
            $file = "$base_path/$base_name-$dimension$extension";
            if (file_exists($file)) {
                $size_key = "custom-$dimension";
                $width = ($mode === 'width') ? $dimension : (isset($image_meta['sizes'][$size_key]['width']) ? $image_meta['sizes'][$size_key]['width'] : 0);
                $sources[$width] = [
                    'url' => "$base_url/$base_name-$dimension$extension",
                    'descriptor' => 'w',
                    'value' => $width
                ];
            }
        }
        $thumbnail_file = "$base_path/$base_name-150x150$extension";
        if (file_exists($thumbnail_file)) {
            $sources[150] = [
                'url' => "$base_url/$base_name-150x150$extension",
                'descriptor' => 'w',
                'value' => 150
            ];
        }
        return $sources;
    }

    /**
     * Custom metadata for converted images
     */
    public static function fix_format_metadata($metadata, $attachment_id)
    {
        $use_avif = Settings::get_use_avif();
        $extension = $use_avif ? 'avif' : 'webp';
        $format = $use_avif ? 'image/avif' : 'image/webp';
        $file = get_attached_file($attachment_id);
        if (pathinfo($file, PATHINFO_EXTENSION) !== $extension) {
            return $metadata;
        }
        $uploads = wp_upload_dir();
        $file_path = $file;
        $file_name = basename($file_path);
        $dirname = dirname($file_path);
        $base_name = pathinfo($file_name, PATHINFO_FILENAME);
        $mode = Settings::get_resize_mode();
        $max_values = ($mode === 'width') ? Settings::get_max_widths() : Settings::get_max_heights();
        $metadata['file'] = str_replace($uploads['basedir'] . '/', '', $file_path);
        $metadata['mime_type'] = $format;
        foreach ($max_values as $index => $dimension) {
            if ($index === 0) continue;
            $size_file = "$dirname/$base_name-$dimension.$extension";
            if (file_exists($size_file)) {
                $metadata['sizes']["custom-$dimension"] = [
                    'file' => "$base_name-$dimension.$extension",
                    'width' => ($mode === 'width') ? $dimension : (isset($metadata['sizes']["custom-$dimension"]['width']) ? $metadata['sizes']["custom-$dimension"]['width'] : 0),
                    'height' => ($mode === 'height') ? $dimension : (isset($metadata['sizes']["custom-$dimension"]['height']) ? $metadata['sizes']["custom-$dimension"]['height'] : 0),
                    'mime-type' => $format
                ];
            }
        }
        $thumbnail_file = "$dirname/$base_name-150x150.$extension";
        if (file_exists($thumbnail_file)) {
            $metadata['sizes']['thumbnail'] = [
                'file' => "$base_name-150x150.$extension",
                'width' => 150,
                'height' => 150,
                'mime-type' => $format
            ];
        }
        return $metadata;
    }

    /**
     * Recursively clean up leftover originals and alternate formats
     */
    public static function cleanup_leftover_originals(int $batch_limit = 1000): array
    {
        $log = get_option('webp_conversion_log', []);
        $uploads_dir = wp_upload_dir()['basedir'];

        // Clear file cache before starting
        self::clear_file_cache();

        // Initialize counters
        $deleted = 0;
        $failed = 0;
        $processed = 0;
        $memory_warnings = 0;

        $preserve_originals = Settings::get_preserve_originals();
        $use_avif = Settings::get_use_avif();
        $current_extension = $use_avif ? 'avif' : 'webp';
        $alternate_extension = $use_avif ? 'webp' : 'avif';

        // Build a list of active files first
        $log[] = __('Building list of active files...', 'wpturbo');
        update_option('webp_conversion_log', array_slice((array)$log, -500));

        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        ]);

        $active_files = [];
        $mode = Settings::get_resize_mode();
        $max_values = ($mode === 'width') ? Settings::get_max_widths() : Settings::get_max_heights();
        $excluded_images = Settings::get_excluded_images();

        // Process attachments to build active files list
        foreach ($attachments as $attachment_id) {
            // Check memory usage periodically
            if ($processed % 100 === 0) {
                $memory_status = self::check_memory_usage();
                if ($memory_status) {
                    $memory_warnings++;
                    // Force garbage collection if available
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    $log[] = sprintf(__('Memory usage high, garbage collection triggered (%d times)', 'wpturbo'), $memory_warnings);
                    update_option('webp_conversion_log', array_slice((array)$log, -500));

                    // If warnings are excessive, break to avoid crashes
                    if ($memory_warnings > 5) {
                        $log[] = __('Too many memory warnings, stopping process to avoid crash', 'wpturbo');
                        update_option('webp_conversion_log', array_slice((array)$log, -500));
                        return [
                            'deleted' => $deleted,
                            'failed' => $failed,
                            'processed' => $processed,
                            'memory_warnings' => $memory_warnings,
                            'completed' => false
                        ];
                    }
                }
            }

            $processed++;
            $file = get_attached_file($attachment_id);

            if (!$file || !self::file_exists_cached($file)) {
                continue;
            }

            $metadata = wp_get_attachment_metadata($attachment_id);
            $dirname = dirname($file);
            $base_name = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($attachment_id, $excluded_images, true)) {
                // Add all possible versions of excluded images to active files
                $active_files[$file] = true;

                $possible_extensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
                foreach ($possible_extensions as $ext) {
                    $potential_file = "$dirname/$base_name.$ext";
                    if (self::file_exists_cached($potential_file)) {
                        $active_files[$potential_file] = true;
                    }
                }

                foreach ($max_values as $index => $dimension) {
                    $suffix = ($index === 0) ? '' : "-{$dimension}";
                    foreach (['webp', 'avif'] as $ext) {
                        $file_path = "$dirname/$base_name$suffix.$ext";
                        if (self::file_exists_cached($file_path)) {
                            $active_files[$file_path] = true;
                        }
                    }
                }

                $thumbnail_files = ["$dirname/$base_name-150x150.webp", "$dirname/$base_name-150x150.avif"];
                foreach ($thumbnail_files as $thumbnail_file) {
                    if (self::file_exists_cached($thumbnail_file)) {
                        $active_files[$thumbnail_file] = true;
                    }
                }

                if ($metadata && isset($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size_data) {
                        $size_file = "$dirname/" . $size_data['file'];
                        if (self::file_exists_cached($size_file)) {
                            $active_files[$size_file] = true;
                        }
                    }
                }
                continue;
            }

            // Regular (non-excluded) image - mark current format versions as active
            $active_files[$file] = true;

            foreach ($max_values as $index => $dimension) {
                $suffix = ($index === 0) ? '' : "-{$dimension}";
                $current_file = "$dirname/$base_name$suffix.$current_extension";
                if (self::file_exists_cached($current_file)) {
                    $active_files[$current_file] = true;
                }
            }

            $thumbnail_file = "$dirname/$base_name-150x150.$current_extension";
            if (self::file_exists_cached($thumbnail_file)) {
                $active_files[$thumbnail_file] = true;
            }
        }

        $log[] = sprintf(__('Found %d active files to preserve', 'wpturbo'), count($active_files));
        update_option('webp_conversion_log', array_slice((array)$log, -500));

        // Only proceed with deletion if we're not preserving originals
        if (!$preserve_originals) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploads_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $file_count = 0;
            $max_files = $batch_limit;

            foreach ($files as $file) {
                $file_count++;

                // Process in batches to manage memory
                if ($file_count > $max_files) {
                    $log[] = sprintf(__('Batch limit reached (%d files). Run cleanup again for remaining files.', 'wpturbo'), $max_files);
                    break;
                }

                // Check memory usage periodically
                if ($file_count % 100 === 0) {
                    $memory_status = self::check_memory_usage();
                    if ($memory_status) {
                        $memory_warnings++;
                        // Force garbage collection if available
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }

                        // If warnings are excessive, break to avoid crashes
                        if ($memory_warnings > 5) {
                            $log[] = __('Too many memory warnings, stopping process to avoid crash', 'wpturbo');
                            update_option('webp_conversion_log', array_slice((array)$log, -500));
                            return [
                                'deleted' => $deleted,
                                'failed' => $failed,
                                'processed' => $processed,
                                'file_count' => $file_count,
                                'memory_warnings' => $memory_warnings,
                                'completed' => false
                            ];
                        }
                    }
                }

                if ($file->isDir()) {
                    continue;
                }

                $file_path = $file->getPathname();
                $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

                // Only process image files
                if (!in_array($extension, ['webp', 'avif', 'jpg', 'jpeg', 'png'])) {
                    continue;
                }

                $relative_path = str_replace($uploads_dir . '/', '', $file_path);
                $path_parts = explode('/', $relative_path);

                // Check if this is in a valid uploads path structure
                $is_valid_path = (count($path_parts) === 1) ||
                    (count($path_parts) === 3 && is_numeric($path_parts[0]) && is_numeric($path_parts[1]));

                if (!$is_valid_path || isset($active_files[$file_path])) {
                    continue;
                }

                // Delete if it's original format or alternate format
                if (in_array($extension, ['jpg', 'jpeg', 'png']) || $extension === $alternate_extension) {
                    $attempts = 0;
                    $chmod_failed = false;

                    while ($attempts < 5 && self::file_exists_cached($file_path)) {
                        if (!is_writable($file_path)) {
                            @chmod($file_path, 0644);
                            if (!is_writable($file_path)) {
                                if ($chmod_failed) {
                                    $log[] = sprintf(__('Error: Cannot make %s writable - skipping deletion', 'wpturbo'), basename($file_path));
                                    $failed++;
                                    break;
                                }
                                $chmod_failed = true;
                            }
                        }

                        if (@unlink($file_path)) {
                            $log[] = sprintf(__('Cleanup: Deleted %s', 'wpturbo'), basename($file_path));
                            $deleted++;
                            // Update file cache
                            self::$file_cache[$file_path] = false;
                            break;
                        }

                        $attempts++;
                        sleep(1);
                    }

                    if (self::file_exists_cached($file_path)) {
                        $log[] = sprintf(__('Cleanup: Failed to delete %s', 'wpturbo'), basename($file_path));
                        $failed++;
                    }
                }
            }
        }

        $summary = "<span style='font-weight: bold; color: #281E5D;'>" . __('Cleanup Complete', 'wpturbo') . "</span>: " .
            sprintf(
                __('Deleted %d files, %d failed, %d memory warnings', 'wpturbo'),
                $deleted,
                $failed,
                $memory_warnings
            );

        if ($file_count >= $batch_limit) {
            $summary .= ' ' . __('(batch limit reached, more files may need processing)', 'wpturbo');
        }

        $log[] = $summary;
        update_option('webp_conversion_log', array_slice((array)$log, -500));

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'processed' => $processed,
            'file_count' => $file_count ?? 0,
            'memory_warnings' => $memory_warnings,
            'completed' => ($file_count < $batch_limit)
        ];
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_image_optimizer',
            'hook' => null,
            'error' => 'Missing ImageOptimizerController class in theme',
        ];
    }
}
