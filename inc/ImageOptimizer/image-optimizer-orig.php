<?php
//PixRefiner v3.6
// Helper function for formatting file sizes
// Changes by Martin: optimised ‘Fix Links’ for CPTs, Elementor and ‘WYSIWYG’ Editor
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = ($bytes > 0) ? floor(log($bytes) / log(1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Limit default WordPress sizes to thumbnail only when auto-conversion is enabled
function wpturbo_limit_image_sizes($sizes) {
    if (wpturbo_get_disable_auto_conversion()) {
        return $sizes;
    }
    return isset($sizes['thumbnail']) ? ['thumbnail' => $sizes['thumbnail']] : $sizes;
}
//Global override for default sizes
add_filter('intermediate_image_sizes_advanced', function($sizes) {
    if (wpturbo_get_disable_auto_conversion()) {
        return $sizes;
    }

    // Limit to thumbnail only, 150x150 crop
    return [
        'thumbnail' => [
            'width' => 150,
            'height' => 150,
            'crop' => true
        ]
    ];
}, 99);


// Set thumbnail size to 150x150
function wpturbo_set_thumbnail_size() {
    update_option('thumbnail_size_w', 150);
    update_option('thumbnail_size_h', 150);
    update_option('thumbnail_crop', 1);
}
add_action('admin_init', 'wpturbo_set_thumbnail_size');

// Register custom sizes (up to 3 additional sizes beyond the main one)
add_action('after_setup_theme', 'wpturbo_register_custom_sizes');
function wpturbo_register_custom_sizes() {
    $mode = wpturbo_get_resize_mode();
    if ($mode === 'width') {
        $max_values = wpturbo_get_max_widths();
        $additional_values = array_slice($max_values, 1, 3);
        foreach ($additional_values as $width) {
            add_image_size("custom-$width", $width, 0, false);
        }
    } else {
        $max_values = wpturbo_get_max_heights();
        $additional_values = array_slice($max_values, 1, 3);
        foreach ($additional_values as $height) {
            add_image_size("custom-$height", 0, $height, false);
        }
    }
}

// Get or set max widths (default to mobile-friendly set, limit to 4)
function wpturbo_get_max_widths() {
    $value = get_option('webp_max_widths', '1920,1200,600,300');
    $widths = array_map('absint', array_filter(explode(',', $value)));
    $widths = array_filter($widths, function($w) { return $w > 0 && $w <= 9999; });
    return array_slice($widths, 0, 4);
}

// Get or set max heights (default to mobile-friendly set, limit to 4)
function wpturbo_get_max_heights() {
    $value = get_option('webp_max_heights', '1080,720,480,360');
    $heights = array_map('absint', array_filter(explode(',', $value)));
    $heights = array_filter($heights, function($h) { return $h > 0 && $h <= 9999; });
    return array_slice($heights, 0, 4);
}

// Get or set resize mode
function wpturbo_get_resize_mode() {
    return get_option('webp_resize_mode', 'width');
}

// Get or set quality (0-100)
function wpturbo_get_quality() {
    return (int) get_option('webp_quality', 80);
}

// Get or set batch size
function wpturbo_get_batch_size() {
    return (int) get_option('webp_batch_size', 5);
}

// Get or set preserve originals
function wpturbo_get_preserve_originals() {
    return (bool) get_option('webp_preserve_originals', false);
}

// Get or set disable auto-conversion on upload
function wpturbo_get_disable_auto_conversion() {
    return (bool) get_option('webp_disable_auto_conversion', false);
}

// Get or set minimum size threshold in KB (default to 0, meaning no minimum)
function wpturbo_get_min_size_kb() {
    return (int) get_option('webp_min_size_kb', 0);
}

// Get or set whether to use AVIF instead of WebP
function wpturbo_get_use_avif() {
    return (bool) get_option('webp_use_avif', false);
}

// Get excluded image IDs
function wpturbo_get_excluded_images() {
    $excluded = get_option('webp_excluded_images', []);
    return is_array($excluded) ? array_map('absint', $excluded) : [];
}

// Add an image to the excluded list
function wpturbo_add_excluded_image($attachment_id) {
    $attachment_id = absint($attachment_id);
    $excluded = wpturbo_get_excluded_images();
    if (!in_array($attachment_id, $excluded)) {
        $excluded[] = $attachment_id;
        update_option('webp_excluded_images', array_unique($excluded));
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Excluded image added: Attachment ID %d', 'wpturbo'), $attachment_id);
        update_option('webp_conversion_log', array_slice((array)$log, -500));

        return true;
    }
    return false;
}

// Remove an image from the excluded list
function wpturbo_remove_excluded_image($attachment_id) {
    $attachment_id = absint($attachment_id);
    $excluded = wpturbo_get_excluded_images();
    $index = array_search($attachment_id, $excluded);
    if ($index !== false) {
        unset($excluded[$index]);
        update_option('webp_excluded_images', array_values($excluded));
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Excluded image removed: Attachment ID %d', 'wpturbo'), $attachment_id);
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Ensure MIME types are supported in .htaccess (Apache only)
function wpturbo_ensure_mime_types() {
    $htaccess_file = ABSPATH . '.htaccess';
    if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
        return false;
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
        return true;
    }
    return true;
}

// Core conversion function (supports WebP or AVIF)
function wpturbo_convert_to_format($file_path, $dimension, &$log = null, $attachment_id = null, $suffix = '') {
    $use_avif = wpturbo_get_use_avif();
    $format = $use_avif ? 'image/avif' : 'image/webp';
    $extension = $use_avif ? '.avif' : '.webp';
    $path_info = pathinfo($file_path);
    $new_file_path = $path_info['dirname'] . '/' . $path_info['filename'] . $suffix . $extension;
    $quality = wpturbo_get_quality();
    $mode = wpturbo_get_resize_mode();

    if (!(extension_loaded('imagick') || extension_loaded('gd'))) {
        if ($log !== null) $log[] = sprintf(__('Error: No image library (Imagick/GD) available for %s', 'wpturbo'), basename($file_path));
        return false;
    }

    $has_avif_support = (extension_loaded('imagick') && in_array('AVIF', Imagick::queryFormats())) || (extension_loaded('gd') && function_exists('imageavif'));
    if ($use_avif && !$has_avif_support) {
        if ($log !== null) $log[] = sprintf(__('Error: AVIF not supported on this server for %s', 'wpturbo'), basename($file_path));
        return false;
    }

    $editor = wp_get_image_editor($file_path);
    if (is_wp_error($editor)) {
        if ($log !== null) $log[] = sprintf(__('Error: Image editor failed for %s - %s', 'wpturbo'), basename($file_path), $editor->get_error_message());
        return false;
    }

    $dimensions = $editor->get_size();
    $resized = false;
    if ($mode === 'width' && $dimensions['width'] > $dimension) {
        $editor->resize($dimension, null, false);
        $resized = true;
    } elseif ($mode === 'height' && $dimensions['height'] > $dimension) {
        $editor->resize(null, $dimension, false);
        $resized = true;
    }

    $result = $editor->save($new_file_path, $format, ['quality' => $quality]);
    if (is_wp_error($result)) {
        if ($log !== null) $log[] = sprintf(__('Error: Conversion failed for %s - %s', 'wpturbo'), basename($file_path), $result->get_error_message());
        return false;
    }

    if ($log !== null) {
        $log[] = sprintf(
            __('Converted: %s → %s %s', 'wpturbo'),
            basename($file_path),
            basename($new_file_path),
            $resized ? sprintf(__('(resized to %dpx %s, quality %d)', 'wpturbo'), $dimension, $mode, $quality) : sprintf(__('(quality %d)', 'wpturbo'), $quality)
        );
    }

    return $new_file_path;
}

// Handle new uploads with format conversion
add_filter('wp_handle_upload', 'wpturbo_handle_upload_convert_to_format', 10, 1);
function wpturbo_handle_upload_convert_to_format($upload) {
    if (wpturbo_get_disable_auto_conversion()) {
        return $upload;
    }

    $file_extension = strtolower(pathinfo($upload['file'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    if (!in_array($file_extension, $allowed_extensions)) {
        return $upload;
    }

    $use_avif = wpturbo_get_use_avif();
    $format = $use_avif ? 'image/avif' : 'image/webp';
    $extension = $use_avif ? '.avif' : '.webp';

    $file_path = $upload['file'];
    $uploads_dir = dirname($file_path);
    $log = get_option('webp_conversion_log', []);

    // Check if uploads directory is writable
    if (!is_writable($uploads_dir)) {
        $log[] = sprintf(__('Error: Uploads directory %s is not writable', 'wpturbo'), $uploads_dir);
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return $upload;
    }

    $file_size_kb = filesize($file_path) / 1024;
    $min_size_kb = wpturbo_get_min_size_kb();

    if ($min_size_kb > 0 && $file_size_kb < $min_size_kb) {
        $log[] = sprintf(__('Skipped: %s (size %s KB < %d KB)', 'wpturbo'), basename($file_path), round($file_size_kb, 2), $min_size_kb);
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return $upload;
    }

    $mode = wpturbo_get_resize_mode();
    $max_values = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $attachment_id = attachment_url_to_postid($upload['url']);
    $new_files = [];
    $success = true;

    // Get original image dimensions
    $editor = wp_get_image_editor($file_path);
    if (is_wp_error($editor)) {
        $log[] = sprintf(__('Error: Image editor failed for %s - %s', 'wpturbo'), basename($file_path), $editor->get_error_message());
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return $upload;
    }
    $dimensions = $editor->get_size();
    $original_width = $dimensions['width'];

    // Convert only for sizes smaller than or equal to original width (in width mode)
    $valid_max_values = $max_values;
    if ($mode === 'width') {
        $valid_max_values = array_filter($max_values, function($width, $index) use ($original_width) {
            // Always include the first size (original) or sizes smaller than original width
            return $index === 0 || $width <= $original_width;
        }, ARRAY_FILTER_USE_BOTH);
    }

    // Convert all valid sizes and rollback if any fail
    foreach ($valid_max_values as $index => $dimension) {
        $suffix = ($index === 0) ? '' : "-{$dimension}";
        $new_file_path = wpturbo_convert_to_format($file_path, $dimension, $log, $attachment_id, $suffix);
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
            $saved = $editor->save($thumbnail_path, $format, ['quality' => wpturbo_get_quality()]);
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
            // Add custom sizes
            foreach ($valid_max_values as $index => $dimension) {
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
                // Regenerate thumbnail if missing
                $editor = wp_get_image_editor($upload['file']);
                if (!is_wp_error($editor)) {
                    $editor->resize(150, 150, true);
                    $saved = $editor->save($thumbnail_file, $format, ['quality' => wpturbo_get_quality()]);
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
            $metadata['webp_quality'] = wpturbo_get_quality();
            $metadata['pixrefiner_stamp'] = [
                'format' => wpturbo_get_use_avif() ? 'avif' : 'webp',
                'quality' => wpturbo_get_quality(),
                'resize_mode' => wpturbo_get_resize_mode(),
                'max_values' => array_values($valid_max_values), // Use valid sizes in stamp
            ];
            update_attached_file($attachment_id, $upload['file']);
            wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $format]);
            wp_update_attachment_metadata($attachment_id, $metadata);
            if (!empty($metadata['pixrefiner_stamp'])) {
                $log[] = "Stamp check for Attachment ID {$attachment_id}:";
                $log[] = "Expected stamp: " . json_encode($metadata['pixrefiner_stamp']);
                $log[] = "Existing stamp: none (new upload)";
            }
        } else {
            $log[] = sprintf(__('Error: Metadata regeneration failed for %s - %s', 'wpturbo'), basename($file_path), $metadata->get_error_message());
        }
    }

    // Delete original only if all conversions succeeded and not preserved
    if ($file_extension !== ($use_avif ? 'avif' : 'webp') && file_exists($file_path) && !wpturbo_get_preserve_originals()) {
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

// Fix metadata for converted images
add_filter('wp_generate_attachment_metadata', 'wpturbo_fix_format_metadata', 10, 2);
function wpturbo_fix_format_metadata($metadata, $attachment_id) {
    $use_avif  = wpturbo_get_use_avif();
    $extension = $use_avif ? 'avif' : 'webp';
    $format    = $use_avif ? 'image/avif' : 'image/webp';

    $file = get_attached_file($attachment_id);
    if (pathinfo($file, PATHINFO_EXTENSION) !== $extension) {
        return $metadata;
    }

    $uploads    = wp_upload_dir();
    $file_path  = $file;
    $file_name  = basename($file_path);
    $dirname    = dirname($file_path);
    $base_name  = pathinfo($file_name, PATHINFO_FILENAME);
    $mode       = wpturbo_get_resize_mode();
    $max_values = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();

    $metadata['file']      = str_replace($uploads['basedir'] . '/', '', $file_path);
    $metadata['mime_type'] = $format;

    foreach ($max_values as $index => $dimension) {
        if ($index === 0) continue;
        $size_file = "$dirname/$base_name-$dimension.$extension";
        if (file_exists($size_file)) {
            $metadata['sizes']["custom-$dimension"] = [
                'file'      => "$base_name-$dimension.$extension",
                'width'     => ($mode === 'width') ? $dimension : 0,
                'height'    => ($mode === 'height') ? $dimension : 0,
                'mime-type' => $format
            ];
        }
    }

    $thumbnail_file = "$dirname/$base_name-150x150.$extension";
    if (file_exists($thumbnail_file)) {
        $metadata['sizes']['thumbnail'] = [
            'file'      => "$base_name-150x150.$extension",
            'width'     => 150,
            'height'    => 150,
            'mime-type' => $format
        ];
    }

    //  Add stamp
    $metadata['pixrefiner_stamp'] = [
        'format'      => $use_avif ? 'avif' : 'webp',
        'quality'     => wpturbo_get_quality(),
        'resize_mode' => $mode,
        'max_values'  => $max_values,
    ];

    //  Log stamp
    $log = get_option('webp_conversion_log', []);
    $log[] = "Stamp set via metadata hook for Attachment ID {$attachment_id}:";
    $log[] = "Stamp: " . json_encode($metadata['pixrefiner_stamp']);
    update_option('webp_conversion_log', array_slice((array)$log, -500));

    return $metadata;
}


//
function wpturbo_convert_single_image_checker($attachments) {
    if (empty($attachments) || !is_array($attachments)) {
        return;
    }

    $log = [];

    foreach ($attachments as $attachment_id) {
        $file_path = get_attached_file($attachment_id);
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!file_exists($file_path) || in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $log[] = sprintf(__('Skipped: Missing or original format (%s) for Attachment ID %d', 'wpturbo'), $ext, $attachment_id);
            continue;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);

        // Build the expected stamp from current settings
        $expected_stamp = [
            'format'      => wpturbo_get_use_avif() ? 'avif' : 'webp',
            'quality'     => wpturbo_get_quality(),
            'resize_mode' => wpturbo_get_resize_mode(),
            'max_values'  => (wpturbo_get_resize_mode() === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights(),
        ];

        $existing_stamp = isset($metadata['pixrefiner_stamp']) ? $metadata['pixrefiner_stamp'] : null;

        //  Stamp comparison log
        $log[] = "Stamp check for Attachment ID {$attachment_id}:";
        $log[] = "Expected stamp: " . json_encode($expected_stamp);
        $log[] = "Existing stamp: " . ($existing_stamp ? json_encode($existing_stamp) : 'none');

        if (!empty($existing_stamp) && $existing_stamp !== $expected_stamp && empty($_GET['force_reconvert'])) {
            foreach ($expected_stamp as $key => $value) {
                if (!isset($existing_stamp[$key]) || $existing_stamp[$key] !== $value) {
                    $log[] = "Mismatch in stamp key '$key': expected " . json_encode($value) . " but got " . json_encode($existing_stamp[$key] ?? null);
                }
            }
        }

        //  If already optimized and no force reconvert
        if (!empty($existing_stamp) && $existing_stamp === $expected_stamp && empty($_GET['force_reconvert'])) {
            $log[] = sprintf(__('Skipped: Already optimized Attachment ID %d', 'wpturbo'), $attachment_id);
            continue;
        }

        //  Proceed with conversion
        $conversion_result = wpturbo_convert_image($file_path, $attachment_id); // <-- your custom image converter

        if (!$conversion_result) {
            $log[] = sprintf(__('Failed to convert Attachment ID %d.', 'wpturbo'), $attachment_id);
            continue;
        }

        //  If successful, update metadata with the new stamp
        if (!empty($metadata)) {
            $metadata['pixrefiner_stamp'] = $expected_stamp;
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $log[] = sprintf(__('Converted Attachment ID %d successfully.', 'wpturbo'), $attachment_id);
    }

    update_option('webp_conversion_log', array_slice((array)$log, -500));
    return $log;
}

function wpturbo_convert_single_image() {
    check_ajax_referer('webp_converter_nonce', 'nonce');
    if (!current_user_can('manage_options') || !isset($_POST['offset'])) {
        wp_send_json_error(__('Permission denied or invalid offset', 'wpturbo'));
    }

    $offset       = absint($_POST['offset']);
    $batch_size   = wpturbo_get_batch_size();
    $mode         = wpturbo_get_resize_mode();
    $max_values   = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $current_ext  = wpturbo_get_use_avif() ? 'avif' : 'webp';
    $format       = wpturbo_get_use_avif() ? 'image/avif' : 'image/webp';
    $current_qual = wpturbo_get_quality();

    wp_raise_memory_limit('image');
    set_time_limit(max(30, 10 * $batch_size));

    $attachments = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'fields'         => 'ids',
        'post__not_in'   => wpturbo_get_excluded_images(),
    ]);

    $log = get_option('webp_conversion_log', []);

    if (empty($attachments)) {
        update_option('webp_conversion_complete', true);
        $log[] = "<strong style='color:#281E5D;'>" . __('Conversion Complete', 'wpturbo') . '</strong>: ' . __('No more images to process', 'wpturbo');
        update_option('webp_conversion_log', array_slice((array) $log, -500));
        wp_send_json_success(['complete' => true]);
    }

    foreach ($attachments as $attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!file_exists($file_path)) continue;

        // Skip if already optimized and settings match
        $meta = wp_get_attachment_metadata($attachment_id);
        $expected_stamp = [
            'format'      => $current_ext,
            'quality'     => $current_qual,
            'resize_mode' => $mode,
            'max_values'  => $max_values,
        ];
        $existing_stamp = isset($meta['pixrefiner_stamp']) ? $meta['pixrefiner_stamp'] : null;
        if (!empty($existing_stamp) && $existing_stamp === $expected_stamp && empty($_GET['force_reconvert'])) {
            $log[] = sprintf(__('Skipped: Already optimized Attachment ID %d', 'wpturbo'), $attachment_id);
            continue;
        }

        $dirname   = dirname($file_path);
        $base_name = pathinfo($file_path, PATHINFO_FILENAME);
        $ext       = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $new_files = [];
        $success   = true;

        // DELETE OUTDATED or DUPLICATE SIZES FROM FILESYSTEM AND METADATA
        $old_sizes = isset($meta['sizes']) ? $meta['sizes'] : [];
        $main_size = $max_values[0] ?? null;
        $meta['sizes'] = []; // Initialize sizes to empty to clear outdated entries

        foreach ($old_sizes as $size_key => $size_data) {
            if (preg_match('/custom-(\d+)/', $size_key, $m)) {
                $old_dim = (int) $m[1];
                $is_redundant = (
                    !in_array($old_dim, $max_values)
                    || ($main_size && $old_dim === $main_size)
                );
                if ($is_redundant) {
                    $old_file = "$dirname/$base_name-$old_dim.$current_ext";
                    if (file_exists($old_file)) {
                        @unlink($old_file);
                        $log[] = sprintf(__('Deleted duplicate or outdated size: %s', 'wpturbo'), basename($old_file));
                    }
                } else {
                    // Preserve valid sizes that still exist
                    $meta['sizes'][$size_key] = $size_data;
                }
            } else if ($size_key === 'thumbnail') {
                // Always preserve thumbnail if it exists
                $meta['sizes']['thumbnail'] = $size_data;
            }
        }

        // Get original image dimensions
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            $log[] = sprintf(__('Error: Image editor failed for %s - %s', 'wpturbo'), basename($file_path), $editor->get_error_message());
            continue;
        }
        $dimensions = $editor->get_size();
        $original_width = $dimensions['width'];

        // Filter max_values to include only sizes less than or equal to original width
        $valid_max_values = $max_values;
        if ($mode === 'width') {
            $valid_max_values = array_filter($max_values, function($width, $index) use ($original_width) {
                return $index === 0 || $width <= $original_width;
            }, ARRAY_FILTER_USE_BOTH);
            // Log skipped sizes for debugging
            if (count($valid_max_values) < count($max_values)) {
                $skipped = array_diff($max_values, $valid_max_values);
                $log[] = sprintf(__('Skipped sizes %s for %s (original width %dpx)', 'wpturbo'), implode(', ', $skipped), basename($file_path), $original_width);
            }
        }

        // GENERATE NEW SIZES
        foreach ($valid_max_values as $index => $dimension) {
            $suffix = ($index === 0) ? '' : "-$dimension";
            $output = wpturbo_convert_to_format($file_path, $dimension, $log, $attachment_id, $suffix);
            if ($output) {
                if ($index === 0) {
                    update_attached_file($attachment_id, $output);
                    wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $format]);
                }
                $new_files[] = $output;
            } else {
                $success = false;
                break;
            }
        }

        // GENERATE THUMBNAIL
        $thumb_path = "$dirname/$base_name-150x150.$current_ext";
        if (!file_exists($thumb_path)) {
            $editor = wp_get_image_editor($file_path);
            if (!is_wp_error($editor)) {
                $editor->resize(150, 150, true);
                $saved = $editor->save($thumb_path, $format, ['quality' => $current_qual]);
                if (!is_wp_error($saved)) {
                    $new_files[] = $thumb_path;
                    $log[] = sprintf(__('Generated thumbnail: %s', 'wpturbo'), basename($thumb_path));
                } else {
                    $success = false;
                }
            } else {
                $success = false;
            }
        }

        if (!$success) {
            foreach ($new_files as $f) if (file_exists($f)) @unlink($f);
            $log[] = sprintf(__('Error: Conversion failed for %s, rolled back.', 'wpturbo'), basename($file_path));
            continue;
        }

        // UPDATE METADATA & STAMP
        if (!empty($new_files)) {
            $meta = wp_generate_attachment_metadata($attachment_id, $new_files[0]);
            if (!is_wp_error($meta)) {
                $meta['sizes'] = []; // Clear sizes again to ensure only new sizes are included
                foreach ($valid_max_values as $index => $dimension) {
                    if ($index === 0) continue;
                    $size_file = "$dirname/$base_name-$dimension.$current_ext";
                    if (file_exists($size_file)) {
                        $meta['sizes']["custom-$dimension"] = [
                            'file'      => "$base_name-$dimension.$current_ext",
                            'width'     => ($mode === 'width') ? $dimension : 0,
                            'height'    => ($mode === 'height') ? $dimension : 0,
                            'mime-type' => $format,
                        ];
                    }
                }
                if (file_exists($thumb_path)) {
                    $meta['sizes']['thumbnail'] = [
                        'file'      => "$base_name-150x150.$current_ext",
                        'width'     => 150,
                        'height'    => 150,
                        'mime-type' => $format,
                    ];
                }
                $meta['webp_quality']      = $current_qual;
                $meta['pixrefiner_stamp'] = $expected_stamp;
                wp_update_attachment_metadata($attachment_id, $meta);
            }
        }

        // DELETE ORIGINAL IF NOT PRESERVED
        if (!wpturbo_get_preserve_originals() && file_exists($file_path) && $ext !== $current_ext) {
            @unlink($file_path);
            $log[] = sprintf(__('Deleted original: %s', 'wpturbo'), basename($file_path));
        }

        $log[] = sprintf(__('Converted Attachment ID %d successfully.', 'wpturbo'), $attachment_id);
    }

    update_option('webp_conversion_log', array_slice((array)$log, -500));
    wp_send_json_success([
        'complete' => false,
        'offset'   => $offset + $batch_size
    ]);
}

// Progress tracking via AJAX
function wpturbo_webp_conversion_status() {
    check_ajax_referer('webp_converter_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'wpturbo'));
    }

    $total = wp_count_posts('attachment')->inherit;
    $per_page = 50; // Chunk size for pagination
    $converted = 0;
    $skipped = 0;

    // Validate total count
    if ($total < 0) {
        $log = get_option('webp_conversion_log', []);
        $log[] = __('Error: Invalid attachment count detected', 'wpturbo');
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        wp_send_json_error(__('Invalid attachment count', 'wpturbo'));
    }

    // Count converted images in chunks
    for ($offset = 0; $offset < $total; $offset += $per_page) {
        $chunk_result = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => wpturbo_get_use_avif() ? 'image/avif' : 'image/webp',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'fields' => 'ids',
        ]);
        $converted += count($chunk_result);
    }

    // Count skipped images in chunks
    for ($offset = 0; $offset < $total; $offset += $per_page) {
        $chunk_result = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'fields' => 'ids',
        ]);
        $skipped += count($chunk_result);
    }

    $remaining = $total - $converted - $skipped;
    $excluded_images = wpturbo_get_excluded_images();
    $excluded_data = [];
    foreach ($excluded_images as $id) {
        $thumbnail = wp_get_attachment_image_src($id, 'thumbnail');
        $excluded_data[] = [
            'id' => $id,
            'title' => get_the_title($id),
            'thumbnail' => $thumbnail ? $thumbnail[0] : ''
        ];
    }

    $mode = wpturbo_get_resize_mode();
    $max_values = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();

    wp_send_json([
        'total' => $total,
        'converted' => $converted,
        'skipped' => $skipped,
        'remaining' => $remaining,
        'excluded' => count($excluded_images),
        'excluded_images' => $excluded_data,
        'log' => get_option('webp_conversion_log', []),
        'complete' => get_option('webp_conversion_complete', false),
        'resize_mode' => $mode,
        'max_values' => implode(', ', $max_values),
        'max_widths' => implode(', ', wpturbo_get_max_widths()),
        'max_heights' => implode(', ', wpturbo_get_max_heights()),
        'quality' => wpturbo_get_quality(),
        'preserve_originals' => wpturbo_get_preserve_originals(),
        'disable_auto_conversion' => wpturbo_get_disable_auto_conversion(),
        'min_size_kb' => wpturbo_get_min_size_kb(),
        'use_avif' => wpturbo_get_use_avif()
    ]);
}

// Clear log
function wpturbo_clear_log() {
    if (!isset($_GET['clear_log']) || !current_user_can('manage_options')) {
        return false;
    }
    update_option('webp_conversion_log', [__('Log cleared', 'wpturbo')]);
    return true;
}

// Reset to defaults
function wpturbo_reset_defaults() {
    if (!isset($_GET['reset_defaults']) || !current_user_can('manage_options')) {
        return false;
    }
    update_option('webp_max_widths', '1920,1200,600,300');
    update_option('webp_max_heights', '1080,720,480,360');
    update_option('webp_resize_mode', 'width');
    update_option('webp_quality', 80);
    update_option('webp_batch_size', 5);
    update_option('webp_preserve_originals', false);
    update_option('webp_disable_auto_conversion', false);
    update_option('webp_min_size_kb', 0);
    update_option('webp_use_avif', false);
    $log = get_option('webp_conversion_log', []);
    $log[] = __('Settings reset to defaults', 'wpturbo');
    update_option('webp_conversion_log', array_slice((array)$log, -500));
    return true;
}

// Set max widths
function wpturbo_set_max_widths() {
    if (!isset($_GET['set_max_width']) || !current_user_can('manage_options') || !isset($_GET['max_width'])) {
        return false;
    }
    $max_widths = sanitize_text_field($_GET['max_width']);
    $width_array = array_filter(array_map('absint', explode(',', $max_widths)));
    $width_array = array_filter($width_array, function($w) { return $w > 0 && $w <= 9999; });
    $width_array = array_slice($width_array, 0, 4);
    if (!empty($width_array)) {
        update_option('webp_max_widths', implode(',', $width_array));
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Max widths set to: %spx', 'wpturbo'), implode(', ', $width_array));
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Set max heights
function wpturbo_set_max_heights() {
    if (!isset($_GET['set_max_height']) || !current_user_can('manage_options') || !isset($_GET['max_height'])) {
        return false;
    }
    $max_heights = sanitize_text_field($_GET['max_height']);
    $height_array = array_filter(array_map('absint', explode(',', $max_heights)));
    $height_array = array_filter($height_array, function($h) { return $h > 0 && $h <= 9999; });
    $height_array = array_slice($height_array, 0, 4);
    if (!empty($height_array)) {
        update_option('webp_max_heights', implode(',', $height_array));
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Max heights set to: %spx', 'wpturbo'), implode(', ', $height_array));
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Set resize mode
function wpturbo_set_resize_mode() {
    if (!isset($_GET['set_resize_mode']) || !current_user_can('manage_options') || !isset($_GET['resize_mode'])) {
        return false;
    }
    $mode = sanitize_text_field($_GET['resize_mode']);
    if (in_array($mode, ['width', 'height'])) {
        $current_mode = get_option('webp_resize_mode', 'width');
        if ($current_mode !== $mode) {
            update_option('webp_resize_mode', $mode);
            $log = get_option('webp_conversion_log', []);
            $log[] = sprintf(__('Resize mode set to: %s', 'wpturbo'), $mode);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
        }
        return true;
    }
    return false;
}

// Set quality
function wpturbo_set_quality() {
    if (!isset($_GET['set_quality']) || !current_user_can('manage_options') || !isset($_GET['quality'])) {
        return false;
    }
    $quality = absint($_GET['quality']);
    if ($quality >= 0 && $quality <= 100) {
        $current_quality = (int) get_option('webp_quality', 80);
        if ($current_quality !== $quality) {
            update_option('webp_quality', $quality);
            $log = get_option('webp_conversion_log', []);
            $log[] = sprintf(__('Quality set to: %d', 'wpturbo'), $quality);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
        }
        return true;
    }
    return false;
}

// Set batch size
function wpturbo_set_batch_size() {
    if (!isset($_GET['set_batch_size']) || !current_user_can('manage_options') || !isset($_GET['batch_size'])) {
        return false;
    }
    $batch_size = absint($_GET['batch_size']);
    if ($batch_size > 0 && $batch_size <= 50) {
        update_option('webp_batch_size', $batch_size);
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Batch size set to: %d', 'wpturbo'), $batch_size);
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Set preserve originals
function wpturbo_set_preserve_originals() {
    if (!isset($_GET['set_preserve_originals']) || !current_user_can('manage_options') || !isset($_GET['preserve_originals'])) {
        return false;
    }
    $preserve = rest_sanitize_boolean($_GET['preserve_originals']);
    $current_preserve = wpturbo_get_preserve_originals();
    if ($current_preserve !== $preserve) {
        update_option('webp_preserve_originals', $preserve);
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Preserve originals set to: %s', 'wpturbo'), $preserve ? __('Yes', 'wpturbo') : __('No', 'wpturbo'));
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Set disable auto-conversion on upload
function wpturbo_set_disable_auto_conversion() {
    if (!isset($_GET['set_disable_auto_conversion']) || !current_user_can('manage_options') || !isset($_GET['disable_auto_conversion'])) {
        return false;
    }
    $disable = rest_sanitize_boolean($_GET['disable_auto_conversion']);
    $current_disable = wpturbo_get_disable_auto_conversion();
    if ($current_disable !== $disable) {
        update_option('webp_disable_auto_conversion', $disable);
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Auto-conversion on upload set to: %s', 'wpturbo'), $disable ? __('Disabled', 'wpturbo') : __('Enabled', 'wpturbo'));
       update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Set minimum size threshold
function wpturbo_set_min_size_kb() {
    if (!isset($_GET['set_min_size_kb']) || !current_user_can('manage_options') || !isset($_GET['min_size_kb'])) {
        return false;
    }
    $min_size = absint($_GET['min_size_kb']);
    if ($min_size >= 0) {
        $current_min_size = wpturbo_get_min_size_kb();
        if ($current_min_size !== $min_size) {
            update_option('webp_min_size_kb', $min_size);
            $log = get_option('webp_conversion_log', []);
            $log[] = sprintf(__('Minimum size threshold set to: %d KB', 'wpturbo'), $min_size);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
        }
        return true;
    }
    return false;
}

// Set use AVIF option and ensure MIME types
function wpturbo_set_use_avif() {
    if (!isset($_GET['set_use_avif']) || !current_user_can('manage_options') || !isset($_GET['use_avif'])) {
        return false;
    }
    $use_avif = rest_sanitize_boolean($_GET['use_avif']);
    $current_use_avif = wpturbo_get_use_avif();
    if ($current_use_avif !== $use_avif) {
        update_option('webp_use_avif', $use_avif);
        wpturbo_ensure_mime_types(); // Ensure MIME types are updated
        $log = get_option('webp_conversion_log', []);
        $log[] = sprintf(__('Conversion format set to: %s', 'wpturbo'), $use_avif ? 'AVIF' : 'WebP');
        $log[] = __('Please reconvert all images to ensure consistency after changing formats.', 'wpturbo');
        update_option('webp_conversion_log', array_slice((array)$log, -500));
        return true;
    }
    return false;
}

// Enhanced cleanup of leftover originals and alternate formats
function wpturbo_cleanup_leftover_originals() {
    if (!isset($_GET['cleanup_leftover_originals']) || !current_user_can('manage_options')) {
        return false;
    }

    $log = get_option('webp_conversion_log', []);
    $uploads_dir = wp_upload_dir()['basedir'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads_dir));
    $deleted = 0;
    $failed = 0;
    $preserve_originals = wpturbo_get_preserve_originals();
    $use_avif = wpturbo_get_use_avif();
    $current_extension = $use_avif ? 'avif' : 'webp';
    $alternate_extension = $use_avif ? 'webp' : 'avif';

    $attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
    ]);
    $active_files = [];
    $mode = wpturbo_get_resize_mode();
    $max_values = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $excluded_images = wpturbo_get_excluded_images();

    foreach ($attachments as $attachment_id) {
        $file = get_attached_file($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);
        $dirname = dirname($file);
        $base_name = pathinfo($file, PATHINFO_FILENAME);

        if (in_array($attachment_id, $excluded_images)) {
            if ($file && file_exists($file)) $active_files[$file] = true;
            $possible_extensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
            foreach ($possible_extensions as $ext) {
                $potential_file = "$dirname/$base_name.$ext";
                if (file_exists($potential_file)) $active_files[$potential_file] = true;
            }
            foreach ($max_values as $index => $dimension) {
                $suffix = ($index === 0) ? '' : "-{$dimension}";
                foreach (['webp', 'avif'] as $ext) {
                    $file_path = "$dirname/$base_name$suffix.$ext";
                    if (file_exists($file_path)) $active_files[$file_path] = true;
                }
            }
            $thumbnail_files = ["$dirname/$base_name-150x150.webp", "$dirname/$base_name-150x150.avif"];
            foreach ($thumbnail_files as $thumbnail_file) {
                if (file_exists($thumbnail_file)) $active_files[$thumbnail_file] = true;
            }
            if ($metadata && isset($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_data) {
                    $size_file = "$dirname/" . $size_data['file'];
                    if (file_exists($size_file)) $active_files[$size_file] = true;
                }
            }
            continue;
        }

        if ($file && file_exists($file)) {
            $active_files[$file] = true;
            foreach ($max_values as $index => $dimension) {
                $suffix = ($index === 0) ? '' : "-{$dimension}";
                $current_file = "$dirname/$base_name$suffix.$current_extension";
                if (file_exists($current_file)) $active_files[$current_file] = true;
            }
            $thumbnail_file = "$dirname/$base_name-150x150.$current_extension";
            if (file_exists($thumbnail_file)) $active_files[$thumbnail_file] = true;
        }
    }

    if (!$preserve_originals) {
        foreach ($files as $file) {
            if ($file->isDir()) continue;

            $file_path = $file->getPathname();
            $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if (!in_array($extension, ['webp', 'avif', 'jpg', 'jpeg', 'png'])) continue;

            $relative_path = str_replace($uploads_dir . '/', '', $file_path);
            $path_parts = explode('/', $relative_path);
            $is_valid_path = (count($path_parts) === 1) || (count($path_parts) === 3 && is_numeric($path_parts[0]) && is_numeric($path_parts[1]));

            if (!$is_valid_path || isset($active_files[$file_path])) continue;

            if (in_array($extension, ['jpg', 'jpeg', 'png']) || $extension === $alternate_extension) {
                $attempts = 0;
                while ($attempts < 5 && file_exists($file_path)) {
                    if (!is_writable($file_path)) {
                        @chmod($file_path, 0644);
                        if (!is_writable($file_path)) {
                            $log[] = sprintf(__('Error: Cannot make %s writable - skipping deletion', 'wpturbo'), basename($file_path));
                            $failed++;
                            break;
                        }
                    }
                    if (@unlink($file_path)) {
                        $log[] = sprintf(__('Cleanup: Deleted %s', 'wpturbo'), basename($file_path));
                        $deleted++;
                        break;
                    }
                    $attempts++;
                    sleep(1);
                }
                if (file_exists($file_path)) {
                    $log[] = sprintf(__('Cleanup: Failed to delete %s', 'wpturbo'), basename($file_path));
                    $failed++;
                }
            }
        }
    }

    $log[] = "<span style='font-weight: bold; color: #281E5D;'>" . __('Cleanup Complete', 'wpturbo') . "</span>: " . sprintf(__('Deleted %d files, %d failed', 'wpturbo'), $deleted, $failed);
    update_option('webp_conversion_log', array_slice((array)$log, -500));

    foreach ($attachments as $attachment_id) {
        if (in_array($attachment_id, $excluded_images)) continue;

        $file_path = get_attached_file($attachment_id);
        if (file_exists($file_path) && strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === $current_extension) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            $thumbnail_file = $uploads_dir . '/' . dirname($metadata['file']) . '/' . pathinfo($file_path, PATHINFO_FILENAME) . '-150x150.' . $current_extension;
            if (!file_exists($thumbnail_file)) {
                $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
                if (!is_wp_error($metadata)) {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                    $log[] = sprintf(__('Regenerated thumbnail for %s', 'wpturbo'), basename($file_path));
                }
            }
        }
    }

    $log[] = "<span style='font-weight: bold; color: #281E5D;'>" . __('Thumbnail Regeneration Complete', 'wpturbo') . "</span>";
    update_option('webp_conversion_log', array_slice((array)$log, -500));
    return true;
}

// AJAX handlers for exclusion
add_action('wp_ajax_webp_add_excluded_image', 'wpturbo_add_excluded_image_ajax');
function wpturbo_add_excluded_image_ajax() {
    check_ajax_referer('webp_converter_nonce', 'nonce');
    if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
        wp_send_json_error(__('Permission denied or invalid attachment ID', 'wpturbo'));
    }
    $attachment_id = absint($_POST['attachment_id']);
    if (wpturbo_add_excluded_image($attachment_id)) {
        wp_send_json_success(['message' => __('Image excluded successfully', 'wpturbo')]);
    } else {
        wp_send_json_error(__('Image already excluded or invalid ID', 'wpturbo'));
    }
}

add_action('wp_ajax_webp_remove_excluded_image', 'wpturbo_remove_excluded_image_ajax');
function wpturbo_remove_excluded_image_ajax() {
    check_ajax_referer('webp_converter_nonce', 'nonce');
    if (!current_user_can('manage_options') || !isset($_POST['attachment_id'])) {
        wp_send_json_error(__('Permission denied or invalid attachment ID', 'wpturbo'));
    }
    $attachment_id = absint($_POST['attachment_id']);
    if (wpturbo_remove_excluded_image($attachment_id)) {
        wp_send_json_success(['message' => __('Image removed from exclusion list', 'wpturbo')]);
    } else {
        wp_send_json_error(__('Image not in exclusion list', 'wpturbo'));
    }
}

// Convert post content image URLs to current format
add_action('wp_ajax_convert_post_images_to_webp', 'wpturbo_convert_post_images_to_format');
function wpturbo_convert_post_images_to_format() {
    check_ajax_referer('webp_converter_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'wpturbo'));
    }

    $log = get_option('webp_conversion_log', []);
    function add_log_entry($message) {
        global $log;
        $log[] = "[" . date("Y-m-d H:i:s") . "] " . $message;
        update_option('webp_conversion_log', array_slice((array)$log, -500));
    }

    $use_avif = wpturbo_get_use_avif();
    $extension = $use_avif ? 'avif' : 'webp';
    add_log_entry(sprintf(__('Starting post/page/FSE-template image conversion to %s...', 'wpturbo'), $use_avif ? 'AVIF' : 'WebP'));

    $public_post_types = get_post_types(['public' => true], 'names');
    $fse_post_types = ['wp_template', 'wp_template_part', 'wp_block'];

    /* Final list: public + FSE + your CPTs */
	$post_types = array_unique( array_merge(
		$public_post_types,
		$fse_post_types,
	) );



    $args = [
        'post_type' => $post_types,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ];
    $posts = get_posts($args);

    if (!$posts) {
        add_log_entry(__('No posts/pages/FSE-templates found', 'wpturbo'));
        wp_send_json_success(['message' => __('No posts/pages/FSE-templates found', 'wpturbo')]);
    }

    $upload_dir = wp_upload_dir();
    $upload_baseurl = $upload_dir['baseurl'];
    $upload_basedir = $upload_dir['basedir'];
    $updated_count = 0;
    $checked_images = 0;
	$changed_links = 0;

    foreach ( $posts as $post_id ) {
		$type  = get_post_type($post_id);
		// $title = get_the_title($post_id);
		$title_raw = get_the_title($post_id);

		// Replace <br>, <br/>, </br> with space BEFORE stripping tags
		$clean_title_html = preg_replace('/<\/?br\s*\/?>/i', ' ', $title_raw);

		// Now remove all tags and normalize whitespace
		$title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($clean_title_html)));

		//  Skip elementor_* post types
		if (strpos($type, 'elementor_') === 0) {
			add_log_entry(" Skipped Elementor-type post: {$type} (ID: {$post_id})");
			continue;
   		 }

		$original_content = get_post_field('post_content', $post_id);
		$content = $original_content;

		add_log_entry(" {$type}: {$title} (ID: {$post_id})");

		//  1. Check for Elementor JSON-based content
		$content_array = json_decode($content, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($content_array)) {
			// Elementor: recursively replace image URLs
			$content_array = replace_urls_in_elementor_urls(
				$content_array,
				$upload_baseurl,
				$upload_basedir,
				$extension,
				$checked_images
			);
			$content = json_encode($content_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} else {
			//  2. Replace inside regular HTML content

			// <img decoding="async" src="..." alt="Elementor Website Course .." title="Elementor Website Course Codes 1">
			$content = preg_replace_callback(
				'/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
				function ($matches) use (&$checked_images, $upload_baseurl, $upload_basedir, $extension) {
					$original_url = $matches[1];
					if (strpos($original_url, $upload_baseurl) !== 0) {
						add_log_entry("Skipping <img> (external): {$original_url}");
						return $matches[0];
					}
					$checked_images++;

					$dirname = pathinfo($original_url, PATHINFO_DIRNAME);
					$filename = pathinfo($original_url, PATHINFO_FILENAME);
					$new_url = $dirname . '/' . $filename . '.' . $extension;
					$scaled_url = $dirname . '/' . $filename . '-scaled.' . $extension;
					$new_path = str_replace($upload_baseurl, $upload_basedir, $new_url);
					$scaled_path = str_replace($upload_baseurl, $upload_basedir, $scaled_url);

					if (file_exists($scaled_path)) return str_replace($original_url, $scaled_url, $matches[0]);
					if (file_exists($new_path)) return str_replace($original_url, $new_url, $matches[0]);

					$base_name = preg_replace('/(-\d+x\d+|-scaled)$/', '', $filename);
					$fallback_url = $dirname . '/' . $base_name . '.' . $extension;
					$fallback_scaled_url = $dirname . '/' . $base_name . '-scaled.' . $extension;
					$fallback_path = str_replace($upload_baseurl, $upload_basedir, $fallback_url);
					$fallback_scaled_path = str_replace($upload_baseurl, $upload_basedir, $fallback_scaled_url);

					if (file_exists($fallback_scaled_path)) return str_replace($original_url, $fallback_scaled_url, $matches[0]);
					if (file_exists($fallback_path)) return str_replace($original_url, $fallback_url, $matches[0]);

					return $matches[0];
				},
				$content
			);

			// <a href="...">
			$content = preg_replace_callback(
				'/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
				function ($matches) use (&$checked_images, &$changed_links, $upload_baseurl, $upload_basedir, $extension) {
					$original_url = $matches[1];
					if (strpos($original_url, $upload_baseurl) !== 0) return $matches[0];

					$checked_images++;

					$dirname = pathinfo($original_url, PATHINFO_DIRNAME);
					$filename = pathinfo($original_url, PATHINFO_FILENAME);
					$new_url = $dirname . '/' . $filename . '.' . $extension;
					$scaled_url = $dirname . '/' . $filename . '-scaled.' . $extension;
					$new_path = str_replace($upload_baseurl, $upload_basedir, $new_url);
					$scaled_path = str_replace($upload_baseurl, $upload_basedir, $scaled_url);

					if (file_exists($scaled_path)) {
						$changed_links++;
						return str_replace($original_url, $scaled_url, $matches[0]);
					}
					if (file_exists($new_path)) {
						$changed_links++;
						return str_replace($original_url, $new_url, $matches[0]);
					}

					$base_name = preg_replace('/(-\d+x\d+|-scaled)$/', '', $filename);
					$fallback_url = $dirname . '/' . $base_name . '.' . $extension;
					$fallback_scaled_url = $dirname . '/' . $base_name . '-scaled.' . $extension;
					$fallback_path = str_replace($upload_baseurl, $upload_basedir, $fallback_url);
					$fallback_scaled_path = str_replace($upload_baseurl, $upload_basedir, $fallback_scaled_url);

					if (file_exists($fallback_scaled_path)) {
						$changed_links++;
						return str_replace($original_url, $fallback_scaled_url, $matches[0]);
					}
					if (file_exists($fallback_path)) {
						$changed_links++;
						return str_replace($original_url, $fallback_url, $matches[0]);
					}

					return $matches[0];
				},
				$content
			);
		}

		//  Save changes if content was modified
		if ($content !== $original_content) {
			wp_update_post(['ID' => $post_id, 'post_content' => $content]);
			$updated_count++;
			add_log_entry(" Rewritten: {$type} – {$title}");
		}

		//  3. Check custom fields (meta fields) for CPTs
		if (!in_array($type, ['post', 'page'])) {
			$meta_fields = get_post_meta($post_id);
			foreach ($meta_fields as $meta_key => $meta_values) {
				foreach ($meta_values as $meta_value) {
					if (!is_string($meta_value) || stripos($meta_value, '.jpg') === false && stripos($meta_value, '.png') === false) {
						continue;
					}

					$original_meta = $meta_value;

					$meta_value = preg_replace_callback(
						'/(https?:\/\/[^\s"\']+\.(?:jpg|jpeg|png))/i',
						function ($matches) use (&$checked_images, &$changed_links, $upload_baseurl, $upload_basedir, $extension) {
							$original_url = $matches[1];
							if (strpos($original_url, $upload_baseurl) !== 0) return $matches[0];

							$checked_images++;

							$dirname = pathinfo($original_url, PATHINFO_DIRNAME);
							$filename = pathinfo($original_url, PATHINFO_FILENAME);
							$new_url = $dirname . '/' . $filename . '.' . $extension;
							$scaled_url = $dirname . '/' . $filename . '-scaled.' . $extension;
							$new_path = str_replace($upload_baseurl, $upload_basedir, $new_url);
							$scaled_path = str_replace($upload_baseurl, $upload_basedir, $scaled_url);

							if (file_exists($scaled_path)) {
								$changed_links++;
								return $scaled_url;
							}
							if (file_exists($new_path)) {
								$changed_links++;
								return $new_url;
							}

							return $matches[0];
						},
						$meta_value
					);

					if ($meta_value !== $original_meta) {
						update_post_meta($post_id, $meta_key, $meta_value);
						$updated_count++;
						add_log_entry(" Updated meta field '{$meta_key}' in post ID {$post_id}");
					}
				}
			}
		}

		//  4. Replace post thumbnail if needed
		$thumbnail_id = get_post_thumbnail_id($post_id);
		if ($thumbnail_id && !in_array($thumbnail_id, wpturbo_get_excluded_images())) {
			$thumbnail_path = get_attached_file($thumbnail_id);
			if ($thumbnail_path && !str_ends_with($thumbnail_path, '.' . $extension)) {
				$new_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.' . $extension, $thumbnail_path);
				if (file_exists($new_path)) {
					update_attached_file($thumbnail_id, $new_path);
					wp_update_post(['ID' => $thumbnail_id, 'post_mime_type' => $use_avif ? 'image/avif' : 'image/webp']);
					$metadata = wp_generate_attachment_metadata($thumbnail_id, $new_path);
					wp_update_attachment_metadata($thumbnail_id, $metadata);
					add_log_entry(" Updated thumbnail: " . basename($thumbnail_path) . " → " . basename($new_path));
				}
			}
		}
	}


    add_log_entry(sprintf(__('Checked %d images (including BG), updated %d items', 'wpturbo'), $checked_images, $updated_count));
	add_log_entry(sprintf(__('Checked %d images (including BG and links), updated %d items, changed %d links', 'wpturbo'), $checked_images, $updated_count, $changed_links));
    wp_send_json_success(['message' => sprintf(__('Checked %d images (including BG), updated %d items', 'wpturbo'), $checked_images, $updated_count)]);
}

// Export all media as a ZIP file
add_action('wp_ajax_webp_export_media_zip', 'wpturbo_export_media_zip');
function wpturbo_export_media_zip() {
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

    $zip = new ZipArchive();
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temp_file);
        wp_send_json_error(__('Failed to create ZIP archive', 'wpturbo'));
    }

    $upload_dir = wp_upload_dir()['basedir'];
    $log = get_option('webp_conversion_log', []);
    $possible_extensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    foreach ($attachments as $attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            $log[] = sprintf(__('Skipped: Main file not found for Attachment ID %d', 'wpturbo'), $attachment_id);
            continue;
        }

        $dirname = dirname($file_path);
        $base_name = pathinfo($file_path, PATHINFO_FILENAME);
        $relative_dir = str_replace($upload_dir . '/', '', $dirname);

        // Add main file
        $relative_path = $relative_dir . '/' . basename($file_path);
        $zip->addFile($file_path, $relative_path);
        $log[] = sprintf(__('Added to ZIP: %s (Attachment ID %d)', 'wpturbo'), basename($file_path), $attachment_id);

        // Add metadata sizes
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_data) {
                $size_file = $dirname . '/' . $size_data['file'];
                if (file_exists($size_file)) {
                    $relative_size_path = $relative_dir . '/' . $size_data['file'];
                    $zip->addFile($size_file, $relative_size_path);
                    $log[] = sprintf(__('Added to ZIP: %s (size: %s, Attachment ID %d)', 'wpturbo'), $size_data['file'], $size, $attachment_id);
                }
            }
        }

        // Add all related files with the same base name
        foreach ($possible_extensions as $ext) {
            // Check for base file with different extensions (e.g., image.jpg, image.png)
            $related_file = "$dirname/$base_name.$ext";
            if (file_exists($related_file) && $related_file !== $file_path) {
                $relative_related_path = $relative_dir . '/' . "$base_name.$ext";
                $zip->addFile($related_file, $relative_related_path);
                $log[] = sprintf(__('Added to ZIP: Related file %s (Attachment ID %d)', 'wpturbo'), "$base_name.$ext", $attachment_id);
            }

            // Check for sized versions (e.g., image-500.jpg, image-150x150.png)
            $pattern = "$dirname/$base_name-*.$ext";
            $related_files = glob($pattern);
            foreach ($related_files as $related_file) {
                // Skip if already added as main file or metadata size
                if ($related_file === $file_path || in_array(basename($related_file), array_column($metadata['sizes'] ?? [], 'file'))) {
                    continue;
                }
                $relative_related_path = $relative_dir . '/' . basename($related_file);
                $zip->addFile($related_file, $relative_related_path);
                $log[] = sprintf(__('Added to ZIP: Related size %s (Attachment ID %d)', 'wpturbo'), basename($related_file), $attachment_id);
            }
        }
    }

    $zip->close();
    update_option('webp_conversion_log', array_slice((array)$log, -500));

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="media_export_' . date('Y-m-d_H-i-s') . '.zip"');
    header('Content-Length: ' . filesize($temp_file));

    readfile($temp_file);
    flush();

    @unlink($temp_file);
    exit;
}

// Custom srcset to include all sizes in current format
add_filter('wp_calculate_image_srcset', 'wpturbo_custom_srcset', 10, 5);
function wpturbo_custom_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (in_array($attachment_id, wpturbo_get_excluded_images())) {
        return $sources;
    }

    $use_avif = wpturbo_get_use_avif();
    $extension = $use_avif ? '.avif' : '.webp';

    $mode = wpturbo_get_resize_mode();
    $max_values = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
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


function wpturbo_convert_single_image_stamp_check($attachment_id) {
    // Your existing settings - you might already have these defined elsewhere
    $use_avif = false; // or however you detect AVIF
    $current_quality = 80; // your WebP/AVIF quality setting
    $mode = 'width'; // resize mode: width, height, or auto
    $max_values = [1920, 1200, 600, 300]; // your target sizes

    //  1. Load the current metadata
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!$metadata) {
        return; // Can't process without metadata
    }

    //  2. Build the expected stamp
    $expected_stamp = [
        'format' => $use_avif ? 'avif' : 'webp',
        'quality' => $current_quality,
        'resize_mode' => $mode,
        'max_values' => $max_values,
    ];

    //  3. Check existing stamp
    $stamp = isset($metadata['pixrefiner_stamp']) ? $metadata['pixrefiner_stamp'] : null;

    //  4. Compare — if matches and no force, skip
    if ($stamp === $expected_stamp && empty($_GET['force_reconvert'])) {
        return; // Already converted correctly, no need to reprocess
    }

    //  5. If no match — continue normal conversion
    //  Now here you do your actual conversion logic like:
    //     - Resize images
    //     - Generate WebP / AVIF
    //     - Compress
    // etc.

    // (This part is your existing code that does the heavy work.)

    //  6. After conversion, update the stamp
    $metadata['pixrefiner_stamp'] = $expected_stamp;
    wp_update_attachment_metadata($attachment_id, $metadata);
}


// Admin interface
add_action('admin_menu', function() {
    add_media_page(
        __('PixRefiner', 'wpturbo'),
        __('PixRefiner', 'wpturbo'),
        'manage_options',
        'webp-converter',
        'wpturbo_webp_converter_page'
    );
});

function wpturbo_webp_converter_page() {
    wp_enqueue_media();
    wp_enqueue_script('media-upload');
    wp_enqueue_style('media');

    if (isset($_GET['set_max_width'])) wpturbo_set_max_widths();
    if (isset($_GET['set_max_height'])) wpturbo_set_max_heights();
    if (isset($_GET['set_resize_mode'])) wpturbo_set_resize_mode();
    if (isset($_GET['set_quality'])) wpturbo_set_quality();
    if (isset($_GET['set_batch_size'])) wpturbo_set_batch_size();
    if (isset($_GET['set_preserve_originals'])) wpturbo_set_preserve_originals();
    if (isset($_GET['set_disable_auto_conversion'])) wpturbo_set_disable_auto_conversion();
    if (isset($_GET['set_min_size_kb'])) wpturbo_set_min_size_kb();
    if (isset($_GET['set_use_avif'])) wpturbo_set_use_avif();
    if (isset($_GET['cleanup_leftover_originals'])) wpturbo_cleanup_leftover_originals();
    if (isset($_GET['clear_log'])) wpturbo_clear_log();
    if (isset($_GET['reset_defaults'])) wpturbo_reset_defaults();

    $has_image_library = extension_loaded('imagick') || extension_loaded('gd');
    $has_avif_support = (extension_loaded('imagick') && in_array('AVIF', Imagick::queryFormats())) || (extension_loaded('gd') && function_exists('imageavif'));
    $htaccess_file = ABSPATH . '.htaccess';
    // Original check kept as comment for reference:
    // $mime_configured = file_exists($htaccess_file) && strpos(file_get_contents($htaccess_file), 'AddType image/webp .webp') !== false;
    $mime_configured = true; // Force to true to suppress the MIME type warning
    ?>
    <div class="wrap" style="padding: 0; font-size: 14px;">
        <div style="display: flex; gap: 10px; align-items: flex-start;">
            <!-- Column 1: Controls, Excluded Images, How It Works -->
            <div style="width: 38%; display: flex; flex-direction: column; gap: 10px;">
                <!-- Pane 1: Controls -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h1 style="font-size: 20px; font-weight: bold; color: #333; margin: -5px 0 15px 0;"><?php _e('PixRefiner - Image Optimization - v3.6', 'wpturbo'); ?></h1>

                    <?php if (!$has_image_library): ?>
                        <div class="notice notice-error" style="margin-bottom: 20px;">
                            <p><?php _e('Warning: No image processing libraries (Imagick or GD) available. Conversion requires one of these.', 'wpturbo'); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (wpturbo_get_use_avif() && !$has_avif_support): ?>
                        <div class="notice notice-warning" style="margin-bottom: 20px;">
                            <p><?php _e('Warning: AVIF support is not detected on this server. Enable Imagick with AVIF or GD with AVIF support to use this option.', 'wpturbo'); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!$mime_configured): ?>
                        <div class="notice notice-warning" style="margin-bottom: 20px;">
                            <p><?php _e('Warning: WebP/AVIF MIME types may not be configured on your server. Images might not display correctly. Check your server settings (e.g., .htaccess for Apache or MIME types for Nginx).', 'wpturbo'); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (current_user_can('manage_options')): ?>
                        <div style="margin-bottom: 20px;">
                            <label for="resize-mode" style="font-weight: bold;"><?php _e('Resize Mode:', 'wpturbo'); ?></label><br>
                            <select id="resize-mode" style="width: 100px; margin-right: 10px; padding: 0px 0px 0px 5px;">
                                <option value="width" <?php echo wpturbo_get_resize_mode() === 'width' ? 'selected' : ''; ?>><?php _e('Width', 'wpturbo'); ?></option>
                                <option value="height" <?php echo wpturbo_get_resize_mode() === 'height' ? 'selected' : ''; ?>><?php _e('Height', 'wpturbo'); ?></option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-width-input" style="font-weight: bold;"><?php _e('Max Widths (up to 4, e.g., 1920, 1200, 600, 300) - 150 is set automatically:', 'wpturbo'); ?></label><br>
                            <input type="text" id="max-width-input" value="<?php echo esc_attr(implode(', ', wpturbo_get_max_widths())); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1920,1200,600,300">
                            <button id="set-max-width" class="button"><?php _e('Set Widths', 'wpturbo'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-height-input" style="font-weight: bold;"><?php _e('Max Heights (up to 4, e.g., 1080, 720, 480, 360) - 150 is set automatically:', 'wpturbo'); ?></label><br>
                            <input type="text" id="max-height-input" value="<?php echo esc_attr(implode(', ', wpturbo_get_max_heights())); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1080,720,480,360">
                            <button id="set-max-height" class="button"><?php _e('Set Heights', 'wpturbo'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="min-size-kb" style="font-weight: bold;"><?php _e('Min Size for Conversion (KB, Set to 0 to disable):', 'wpturbo'); ?></label><br>
                            <input type="number" id="min-size-kb" value="<?php echo esc_attr(wpturbo_get_min_size_kb()); ?>" min="0" style="width: 50px; margin-right: 10px; padding: 5px;" placeholder="0">
                            <button id="set-min-size-kb" class="button"><?php _e('Set Min Size', 'wpturbo'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="use-avif" <?php echo wpturbo_get_use_avif() ? 'checked' : ''; ?>> <?php _e('Set to AVIF Conversion (not WebP)', 'wpturbo'); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="preserve-originals" <?php echo wpturbo_get_preserve_originals() ? 'checked' : ''; ?>> <?php _e('Preserve Original Files', 'wpturbo'); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="disable-auto-conversion" <?php echo wpturbo_get_disable_auto_conversion() ? 'checked' : ''; ?>> <?php _e('Disable Auto-Conversion on Upload', 'wpturbo'); ?></label>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="start-conversion" class="button"><?php _e('1. Convert/Scale', 'wpturbo'); ?></button>
                            <button id="cleanup-originals" class="button"><?php _e('2. Cleanup Images', 'wpturbo'); ?></button>
                            <button id="convert-post-images" class="button"><?php _e('3. Fix URLs', 'wpturbo'); ?></button>
                            <button id="run-all" class="button button-primary"><?php _e('Run All (1-3)', 'wpturbo'); ?></button>
                            <button id="stop-conversion" class="button" style="display: none;"><?php _e('Stop', 'wpturbo'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="clear-log" class="button"><?php _e('Clear Log', 'wpturbo'); ?></button>
                            <button id="reset-defaults" class="button"><?php _e('Reset Defaults', 'wpturbo'); ?></button>
                            <button id="export-media-zip" class="button"><?php _e('Export Media as ZIP', 'wpturbo'); ?></button>
                        </div>
                    <?php else: ?>
                        <p><?php _e('You need manage_options permission to use this tool.', 'wpturbo'); ?></p>
                    <?php endif; ?>
                </div>

				                <!-- Pane 2: Exclude Images -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h2 style="font-size: 16px; margin: 0 0 15px 0;"><?php _e('Exclude Images', 'wpturbo'); ?></h2>
                    <button id="open-media-library" class="button" style="margin-bottom: 20px;"><?php _e('Add from Media Library', 'wpturbo'); ?></button>
                    <div id="excluded-images">
                        <h3 style="font-size: 14px; margin: 0 0 10px 0;"><?php _e('Excluded Images', 'wpturbo'); ?></h3>
                        <ul id="excluded-images-list" style="list-style: none; padding: 0; max-height: 300px; overflow-y: auto;"></ul>
                    </div>
                </div>

                <!-- Pane 3: How It Works -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h2 style="font-size: 16px; margin: 0 0 15px 0;"><?php _e('How It Works', 'wpturbo'); ?></h2>
                    <p style="line-height: 1.5;">
                        <?php _e('Refine images to WebP or AVIF, and remove excess files to save space.', 'wpturbo'); ?><br><br>
                        <b><?php _e('Set Auto-Conversion for New Uploads:', 'wpturbo'); ?></b><br>
                        <b>1. Resize Mode:</b> <?php _e('Pick if images shrink by width or height.', 'wpturbo'); ?><br>
                        <b>2. Set Max Sizes:</b> <?php _e('Choose up to 4 sizes (150x150 thumbnail is automatic).', 'wpturbo'); ?><br>
                        <b>3. Min Size for Conversion:</b> <?php _e('Sizes below the min are not affected. Default is 0.', 'wpturbo'); ?><br>
                        <b>4. Conversion Format:</b> <?php _e('Check to use AVIF. WebP is default.', 'wpturbo'); ?><br>
                        <b>5. Preserve Originals:</b> <?php _e('Check to stop original files from converting/deleting.', 'wpturbo'); ?><br>
                        <b>6. Disable Auto-Conversion:</b> <?php _e('Images will convert on upload unless this is ticked.', 'wpturbo'); ?><br>
                        <b>7. Upload:</b> <?php _e('Upload to Media Library or via elements/widgets.', 'wpturbo'); ?><br><br>
                        <b><?php _e('Apply for Existing Images:', 'wpturbo'); ?></b><br>
                        <b>1. Repeat:</b> <?php _e('Set up steps 1-6 above.', 'wpturbo'); ?><br>
                        <b>2. Run All:</b> <?php _e('Hit "Run All" to do everything at once.', 'wpturbo'); ?><br><br>
                        <b><?php _e('Apply Manually for Existing Images:', 'wpturbo'); ?></b><br>
                        <b>1. Repeat:</b> <?php _e('Set up steps 1-6 above.', 'wpturbo'); ?><br>
                        <b>2. Convert:</b> <?php _e('Change image sizes and format.', 'wpturbo'); ?><br>
                        <b>3. Cleanup:</b> <?php _e('Delete old formats/sizes (if not preserved).', 'wpturbo'); ?><br>
                        <b>4. Fix Links:</b> <?php _e('Update image links to the new format.', 'wpturbo'); ?><br><br>
                        <b><?php _e('IMPORTANT:', 'wpturbo'); ?></b><br>
                        <b>a) Usability:</b> <?php _e('This tool is ideal for New Sites. Using with Legacy Sites must be done with care as variation due to methods, systems, sizes, can affect the outcome. Please use this tool carefully and at your own risk, as I cannot be held responsible for any issues that may arise from its use.', 'wpturbo'); ?><br>
                        <b>b) Backups:</b> <?php _e('Use a strong backup tool like All-in-One WP Migration before using this tool. Check if your host saves backups - as some charge a fee to restore.', 'wpturbo'); ?><br>
                        <b>c) Export Media:</b> <?php _e('Export images as a Zipped Folder prior to running.', 'wpturbo'); ?><br>
                        <b>d) Reset Defaults:</b> <?php _e('Resets all Settings 1-6.', 'wpturbo'); ?><br>
                        <b>e) Speed:</b> <?php _e('Bigger sites take longer to run. This depends on your server.', 'wpturbo'); ?><br>
                        <b>f) Log Wait:</b> <?php _e('Updates show every 50 images.', 'wpturbo'); ?><br>
                        <b>g) Stop Anytime:</b> <?php _e('Click "Stop" to pause.', 'wpturbo'); ?><br>
                        <b>h) AVIF Needs:</b> <?php _e('Your server must support AVIF. Check logs if it fails.', 'wpturbo'); ?><br>
                        <b>i) Old Browsers:</b> <?php _e('AVIF might not work on older browsers, WebP is safer.', 'wpturbo'); ?><br>
                        <b>j) MIME Types:</b> <?php _e('Server must support WebP/AVIF MIME (check with host).', 'wpturbo'); ?><br>
                        <b>k) Rollback:</b> <?php _e('If conversion fails, then rollback occurs, and prevents deletion of the original, regardless of whether the Preserve Originals is checked or not.', 'wpturbo'); ?>
                    </p>
                    <!-- Donate Button -->
                    <div style="margin-top: 20px; display: flex; justify-content: flex-start;">
                        <a href="https://www.paypal.com/paypalme/iamimransiddiq" target="_blank" class="button" style="border: none;" rel="noopener"><?php _e('Support Imran', 'wpturbo'); ?></a>
                    </div>
                </div>
            </div>

            <!-- Column 2: Log -->
            <div style="width: 62%; min-height: 100vh; background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column;">
                <h3 style="font-size: 16px; margin: 0 0 10px 0;"><?php _e('Log (Last 500 Entries)', 'wpturbo'); ?></h3>
                <pre id="log" style="background: #f9f9f9; padding: 15px; flex: 1; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; font-size: 13px;"></pre>
            </div>
        </div>
    </div>

    <style>
    #quality-slider {
        -webkit-appearance: none;
        height: 6px;
        border-radius: 3px;
        background: #ddd;
    }
    #quality-slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 16px;
        height: 16px;
        background: var(--primary-color);
        border-radius: 50%;
        cursor: pointer;
    }
    .button.button-primary {
        background: #FF0050;
        color: #fff;
        padding: 2px 10px;
        height: 30px;
        line-height: 26px;
        transition: all 0.2s;
        font-size: 14px;
        font-weight: 600;
        border: none;
    }
    .button.button-primary:hover {
        background: #444444;
    }
    .button:not(.button-primary) {
        background: #dbe2e9;
        color: #444444;
        padding: 2px 10px;
        height: 30px;
        line-height: 26px;
        transition: all 0.2s;
        border: none;
    }
    .button:not(.button-primary):hover {
        background: #444444;
        color: #FFF;
    }
    #excluded-images-list li {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
    }
    #excluded-images-list img {
        max-width: 50px;
        margin-right: 10px;
    }
    input[type="text"],
    input[type="number"],
    select {
        padding: 2px;
        height: 30px;
        box-sizing: border-box;
    }
    @media screen and (max-width: 782px) {
        div[style*="width: 55%"] {
            height: calc(100vh - 46px) !important;
        }
    }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let isConverting = false;

            function updateStatus() {
                fetch('<?php echo admin_url('admin-ajax.php?action=webp_status&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>')
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response.json();
                    })
                    .then(data => {
                        document.getElementById('log').innerHTML = data.log.reverse().join('<br>');
                        document.getElementById('resize-mode').value = data.resize_mode;
                        document.getElementById('max-width-input').value = data.max_widths;
                        document.getElementById('max-height-input').value = data.max_heights;
                        document.getElementById('preserve-originals').checked = data.preserve_originals;
                        document.getElementById('disable-auto-conversion').checked = data.disable_auto_conversion;
                        document.getElementById('min-size-kb').value = data.min_size_kb;
                        document.getElementById('use-avif').checked = data.use_avif;
                        updateExcludedImages(data.excluded_images);
                    })
                    .catch(error => {
                        console.error('Error in updateStatus:', error);
                        alert('Failed to update status: ' + error.message);
                    });
            }

            function updateExcludedImages(excludedImages) {
                const list = document.getElementById('excluded-images-list');
                list.innerHTML = '';
                excludedImages.forEach(image => {
                    const li = document.createElement('li');
                    li.innerHTML = `<img decoding="async" src="${image.thumbnail}" alt="${image.title}"><span>${image.title} (ID: ${image.id})</span><button class="remove-excluded button" data-id="${image.id}"><?php echo esc_html__('Remove', 'wpturbo'); ?></button>`;
                    list.appendChild(li);
                });
                document.querySelectorAll('.remove-excluded').forEach(button => {
                    button.addEventListener('click', () => {
                        fetch('<?php echo admin_url('admin-ajax.php?action=webp_remove_excluded_image&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'attachment_id=' + encodeURIComponent(button.getAttribute('data-id'))
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) updateStatus();
                            else alert('Error: ' + data.data);
                        })
                        .catch(error => {
                            console.error('Error removing excluded image:', error);
                            alert('Failed to remove excluded image: ' + error.message);
                        });
                    });
                });
            }

			let retryCounts = {};

			function convertNextImage(offset) {
				if (!isConverting) return;
				retryCounts = {}; // Clear retry counts when starting

				fetch('<?php echo admin_url('admin-ajax.php?action=webp_convert_single&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'offset=' + encodeURIComponent(offset)
				})
				.then(response => {
					if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
					return response.json();
				})
				.then(data => {
					if (data.success) {
						updateStatus();
						if (!data.data.complete && isConverting) {
							retryCounts[offset] = 0; // Reset retry count
							convertNextImage(data.data.offset);
						} else {
							document.getElementById('stop-conversion').style.display = 'none';
						}
					} else {
						// Failed: Retry up to 2 times
						retryCounts[offset] = (retryCounts[offset] || 0) + 1;
						if (retryCounts[offset] <= 2) {
							console.warn('Retrying offset:', offset, 'Attempt:', retryCounts[offset]);
							setTimeout(() => convertNextImage(offset), 1000); // Wait 1s before retry
						} else {
							console.error('Giving up on offset:', offset);
							if (isConverting) {
								convertNextImage(offset + <?php echo wpturbo_get_batch_size(); ?>); // Skip to next batch
							}
						}
					}
				})
				.catch(error => {
					console.error('Error in convertNextImage:', error);
					alert('Conversion failed: ' + error.message);
					document.getElementById('stop-conversion').style.display = 'none';
				});
			}



            <?php if (current_user_can('manage_options')): ?>
            const mediaFrame = wp.media({
                title: '<?php echo esc_js(__('Select Images to Exclude', 'wpturbo')); ?>',
                button: { text: '<?php echo esc_js(__('Add to Excluded List', 'wpturbo')); ?>' },
                multiple: true,
                library: { type: 'image' }
            });
            document.getElementById('open-media-library').addEventListener('click', () => mediaFrame.open());
            mediaFrame.on('select', () => {
                const selection = mediaFrame.state().get('selection');
                selection.each(attachment => {
                    fetch('<?php echo admin_url('admin-ajax.php?action=webp_add_excluded_image&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'attachment_id=' + encodeURIComponent(attachment.id)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) updateStatus();
                    })
                    .catch(error => {
                        console.error('Error adding excluded image:', error);
                        alert('Failed to add excluded image: ' + error.message);
                    });
                });
            });

            document.getElementById('set-max-width').addEventListener('click', () => {
                const maxWidths = document.getElementById('max-width-input').value;
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_max_width=1&max_width='); ?>' + encodeURIComponent(maxWidths))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting max width:', error);
                        alert('Failed to set max width: ' + error.message);
                    });
            });

            document.getElementById('set-max-height').addEventListener('click', () => {
                const maxHeights = document.getElementById('max-height-input').value;
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_max_height=1&max_height='); ?>' + encodeURIComponent(maxHeights))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting max height:', error);
                        alert('Failed to set max height: ' + error.message);
                    });
            });

            document.getElementById('resize-mode').addEventListener('change', () => {
                const mode = document.getElementById('resize-mode').value;
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_resize_mode=1&resize_mode='); ?>' + encodeURIComponent(mode))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting resize mode:', error);
                        alert('Failed to set resize mode: ' + error.message);
                    });
            });

            document.getElementById('preserve-originals').addEventListener('click', () => {
                const preserve = document.getElementById('preserve-originals').checked;
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_preserve_originals=1&preserve_originals='); ?>' + encodeURIComponent(preserve ? 1 : 0))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting preserve originals:', error);
                        alert('Failed to set preserve originals: ' + error.message);
                    });
            });

            document.getElementById('disable-auto-conversion').addEventListener('click', () => {
                const disable = document.getElementById('disable-auto-conversion').checked;
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_disable_auto_conversion=1&disable_auto_conversion='); ?>' + encodeURIComponent(disable ? 1 : 0))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting disable auto-conversion:', error);
                        alert('Failed to set disable auto-conversion: ' + error.message);
                    });
            });

            document.getElementById('set-min-size-kb').addEventListener('click', () => {
                const minSizeKB = document.getElementById('min-size-kb').value;
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_min_size_kb=1&min_size_kb='); ?>' + encodeURIComponent(minSizeKB))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting minimum size:', error);
                        alert('Failed to set minimum size: ' + error.message);
                    });
            });

            document.getElementById('use-avif').addEventListener('click', () => {
                const useAvif = document.getElementById('use-avif').checked;
                if (useAvif && !confirm('<?php echo esc_js(__('Switching to AVIF requires reconverting all images for consistency. Continue?', 'wpturbo')); ?>')) {
                    document.getElementById('use-avif').checked = false;
                    return;
                }
                fetch('<?php echo admin_url('admin.php?page=webp-converter&set_use_avif=1&use_avif='); ?>' + encodeURIComponent(useAvif ? 1 : 0))
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error setting AVIF option:', error);
                        alert('Failed to set AVIF option: ' + error.message);
                    });
            });

            document.getElementById('start-conversion').addEventListener('click', () => {
                isConverting = true;
                document.getElementById('stop-conversion').style.display = 'inline-block';
                fetch('<?php echo admin_url('admin.php?page=webp-converter&convert_existing_images_to_webp=1'); ?>')
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => {
                        updateStatus();
                        convertNextImage(0);
                    })
                    .catch(error => {
                        console.error('Error starting conversion:', error);
                        alert('Failed to start conversion: ' + error.message);
                    });
            });

            document.getElementById('cleanup-originals').addEventListener('click', () => {
                fetch('<?php echo admin_url('admin.php?page=webp-converter&cleanup_leftover_originals=1'); ?>')
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error cleaning up originals:', error);
                        alert('Failed to cleanup originals: ' + error.message);
                    });
            });

            document.getElementById('convert-post-images').addEventListener('click', () => {
                if (confirm('<?php echo esc_js(__('Update all post images to the selected format?', 'wpturbo')); ?>')) {
                    fetch('<?php echo admin_url('admin-ajax.php?action=convert_post_images_to_webp&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    })
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response.json();
                    })
                    .then(data => {
                        alert(data.success ? data.data.message : 'Error: ' + data.data);
                        updateStatus();
                    })
                    .catch(error => {
                        console.error('Error converting post images:', error);
                        alert('Failed to convert post images: ' + error.message);
                    });
                }
            });

            document.getElementById('run-all').addEventListener('click', () => {
                if (confirm('<?php echo esc_js(__('Run all steps?', 'wpturbo')); ?>')) {
                    isConverting = true;
                    document.getElementById('stop-conversion').style.display = 'inline-block';
                    fetch('<?php echo admin_url('admin.php?page=webp-converter&convert_existing_images_to_webp=1'); ?>')
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                            return response;
                        })
                        .then(() => {
                            convertNextImage(0);
                            return new Promise(resolve => {
                                const checkComplete = setInterval(() => {
                                    fetch('<?php echo admin_url('admin-ajax.php?action=webp_status&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>')
                                        .then(response => response.json())
                                        .then(data => {
                                            updateStatus();
                                            if (data.complete) {
                                                clearInterval(checkComplete);
                                                resolve();
                                            }
                                        })
                                        .catch(error => {
                                            console.error('Error checking conversion status:', error);
                                            clearInterval(checkComplete);
                                            resolve();
                                        });
                                }, 1000);
                            });
                        })
                        .then(() => {
                            return fetch('<?php echo admin_url('admin-ajax.php?action=convert_post_images_to_webp&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                            })
                            .then(response => {
                                if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                                return response.json();
                            })
                            .then(data => {
                                updateStatus();
                                alert(data.success ? data.data.message : 'Error: ' + data.data);
                            });
                        })
                        .then(() => {
                            return fetch('<?php echo admin_url('admin.php?page=webp-converter&cleanup_leftover_originals=1'); ?>');
                        })
                        .then(() => {
                            isConverting = false;
                            document.getElementById('stop-conversion').style.display = 'none';
                            updateStatus();
                            alert('<?php echo esc_js(__('All steps completed!', 'wpturbo')); ?>');
                        })
                        .catch(error => {
                            console.error('Error in Run All:', error);
                            alert('Run All failed: ' + error.message);
                            isConverting = false;
                            document.getElementById('stop-conversion').style.display = 'none';
                        });
                }
            });

            document.getElementById('stop-conversion').addEventListener('click', () => {
                isConverting = false;
                document.getElementById('stop-conversion').style.display = 'none';
            });

            document.getElementById('clear-log').addEventListener('click', () => {
                fetch('<?php echo admin_url('admin.php?page=webp-converter&clear_log=1'); ?>')
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response;
                    })
                    .then(() => updateStatus())
                    .catch(error => {
                        console.error('Error clearing log:', error);
                        alert('Failed to clear log: ' + error.message);
                    });
            });

            document.getElementById('reset-defaults').addEventListener('click', () => {
                if (confirm('<?php echo esc_js(__('Reset all settings to defaults?', 'wpturbo')); ?>')) {
                    fetch('<?php echo admin_url('admin.php?page=webp-converter&reset_defaults=1'); ?>')
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                            return response;
                        })
                        .then(() => updateStatus())
                        .catch(error => {
                            console.error('Error resetting defaults:', error);
                            alert('Failed to reset defaults: ' + error.message);
                        });
                }
            });

            document.getElementById('export-media-zip').addEventListener('click', () => {
                if (confirm('<?php echo esc_js(__('Export all media as a ZIP file?', 'wpturbo')); ?>')) {
                    const url = '<?php echo admin_url('admin-ajax.php?action=webp_export_media_zip&nonce=' . wp_create_nonce('webp_converter_nonce')); ?>';
                    window.location.href = url;
                }
            });
            <?php endif; ?>

            updateStatus();
        });
    </script>
    <?php
}

// Setup AJAX hooks
add_action('admin_init', function() {
    add_action('wp_ajax_webp_status', 'wpturbo_webp_conversion_status');
    add_action('wp_ajax_webp_convert_single', 'wpturbo_convert_single_image');
    add_action('wp_ajax_webp_export_media_zip', 'wpturbo_export_media_zip');
    if (isset($_GET['convert_existing_images_to_webp']) && current_user_can('manage_options')) {
        delete_option('webp_conversion_complete');
    }
});

// Admin notices
add_action('admin_notices', function() {
    if (isset($_GET['convert_existing_images_to_webp'])) {
        echo '<div class="notice notice-success"><p>' . __('Conversion started. Monitor progress in Media.', 'wpturbo') . '</p></div>';
    }
    if (isset($_GET['set_max_width']) && wpturbo_set_max_widths()) {
        echo '<div class="notice notice-success"><p>' . __('Max widths updated.', 'wpturbo') . '</p></div>';
    }
    if (isset($_GET['set_max_height']) && wpturbo_set_max_heights()) {
        echo '<div class="notice notice-success"><p>' . __('Max heights updated.', 'wpturbo') . '</p></div>';
    }
    if (isset($_GET['reset_defaults']) && wpturbo_reset_defaults()) {
        echo '<div class="notice notice-success"><p>' . __('Settings reset to defaults.', 'wpturbo') . '</p></div>';
    }
    if (isset($_GET['set_min_size_kb']) && wpturbo_set_min_size_kb()) {
        echo '<div class="notice notice-success"><p>' . __('Minimum size threshold updated.', 'wpturbo') . '</p></div>';
    }
    if (isset($_GET['set_use_avif']) && wpturbo_set_use_avif()) {
        echo '<div class="notice notice-success"><p>' . __('Conversion format updated. Please reconvert all images.', 'wpturbo') . '</p></div>';
    }
});

// Custom image size names
add_filter('image_size_names_choose', function($sizes) {
    $mode = wpturbo_get_resize_mode();
    $max_values = ($mode === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights();

    // Start with just the thumbnail size
    $sizes = ['thumbnail' => __('Thumbnail (150x150)', 'wpturbo')];

    foreach ($max_values as $index => $value) {
        // Skip index 0 (it's the main/original) if needed, or include all
        $label = ($mode === 'width') ? sprintf(__('Width %dpx', 'wpturbo'), $value) : sprintf(__('Height %dpx', 'wpturbo'), $value);
        $sizes["custom-$value"] = $label;
    }

    return $sizes;
}, 999);

// Disable scaling
add_filter('big_image_size_threshold', '__return_false', 999);

// Clean up attachment files on deletion
add_action('wp_delete_attachment', 'wpturbo_delete_attachment_files', 10, 1);
function wpturbo_delete_attachment_files($attachment_id) {
    if (in_array($attachment_id, wpturbo_get_excluded_images())) return;

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
}


// new function for elementor
function replace_urls_in_elementor_urls($data, $baseurl, $basedir, $extension, &$checked_images) {
    foreach ($data as $key => &$value) {
        if (is_array($value)) {
            $value = replace_urls_in_elementor_urls($value, $baseurl, $basedir, $extension, $checked_images);
        } elseif (is_string($value) && preg_match('/\.(jpg|jpeg|png)$/i', $value)) {
            $original_url = $value;
            if (strpos($original_url, $baseurl) === false) {
                continue;
            }

            $checked_images++;

            $dirname = pathinfo($original_url, PATHINFO_DIRNAME);
            $filename = pathinfo($original_url, PATHINFO_FILENAME);

            $new_url = $dirname . '/' . $filename . '.' . $extension;
            $scaled_url = $dirname . '/' . $filename . '-scaled.' . $extension;
            $new_path = str_replace($baseurl, $basedir, $new_url);
            $scaled_path = str_replace($baseurl, $basedir, $scaled_url);

            if (file_exists($scaled_path)) {
                add_log_entry("Replacing JSON: {$original_url} → {$scaled_url}");
                $value = $scaled_url;
            } elseif (file_exists($new_path)) {
                add_log_entry("Replacing JSON: {$original_url} → {$new_url}");
                $value = $new_url;
            } else {
                // fallback
                $base_name = preg_replace('/(-\d+x\d+|-scaled)$/', '', $filename);
                $fallback_url = $dirname . '/' . $base_name . '.' . $extension;
                $fallback_scaled_url = $dirname . '/' . $base_name . '-scaled.' . $extension;
                $fallback_path = str_replace($baseurl, $basedir, $fallback_url);
                $fallback_scaled_path = str_replace($baseurl, $basedir, $fallback_scaled_url);

                if (file_exists($fallback_scaled_path)) {
                    add_log_entry("Replacing JSON: {$original_url} → {$fallback_scaled_url}");
                    $value = $fallback_scaled_url;
                } elseif (file_exists($fallback_path)) {
                    add_log_entry("Replacing JSON: {$original_url} → {$fallback_url}");
                    $value = $fallback_url;
                }
            }
        }
    }
    return $data;
}


// Ensure MIME types on plugin activation or format switch
register_activation_hook(__FILE__, 'wpturbo_ensure_mime_types');
add_action('update_option_webp_use_avif', 'wpturbo_ensure_mime_types');


// Stop Processed Images from being Processed again unless Settings Change
add_action('admin_init', function () {
    if (!current_user_can('manage_options') || !isset($_GET['patch_pixrefiner_stamp'])) return;

    $attachments = get_posts([
        'post_type' => 'attachment',
        'post_mime_type' => ['image/webp', 'image/avif'],
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);

    $expected_stamp = [
        'format'      => wpturbo_get_use_avif() ? 'avif' : 'webp',
        'quality'     => wpturbo_get_quality(),
        'resize_mode' => wpturbo_get_resize_mode(),
        'max_values'  => (wpturbo_get_resize_mode() === 'width') ? wpturbo_get_max_widths() : wpturbo_get_max_heights(),
    ];

    foreach ($attachments as $id) {
        $meta = wp_get_attachment_metadata($id);
        if (empty($meta['pixrefiner_stamp'])) {
            $meta['pixrefiner_stamp'] = $expected_stamp;
            wp_update_attachment_metadata($id, $meta);
        }
    }

    echo "<div class='notice notice-success'><p> PixRefiner stamp patch complete.</p></div>";
});