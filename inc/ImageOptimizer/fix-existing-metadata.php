<?php
/**
 * Fix Existing Image Metadata
 * 
 * This script fixes attachments that have been converted to WebP/AVIF
 * but still have database metadata pointing to non-existent original files.
 * 
 * Run this once to fix all existing converted images and prevent
 * "Failed to open stream: No such file or directory" errors.
 * 
 * Usage: Add this to your functions.php temporarily:
 * add_action('admin_init', function() {
 *     if (isset($_GET['fix_image_metadata']) && current_user_can('manage_options')) {
 *         require_once get_stylesheet_directory() . '/inc/ImageOptimizer/fix-existing-metadata.php';
 *         \SFX\ImageOptimizer\fix_existing_metadata();
 *         wp_die('Metadata fix complete. Check the log above.');
 *     }
 * });
 * 
 * Then visit: /wp-admin/?fix_image_metadata=1
 */

namespace SFX\ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

function fix_existing_metadata(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    echo '<h1>Fixing Image Metadata</h1>';
    echo '<pre>';

    // Get all image attachments
    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jpg'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    $attachments = get_posts($args);
    $fixed_count = 0;
    $error_count = 0;
    $skipped_count = 0;

    echo sprintf("Found %d attachments to check...\n\n", count($attachments));

    foreach ($attachments as $attachment_id) {
        $file = get_attached_file($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);

        // Check if the file exists
        if (file_exists($file)) {
            echo sprintf("[SKIP] ID %d: File exists: %s\n", $attachment_id, basename($file));
            $skipped_count++;
            continue;
        }

        // File doesn't exist - try to find the converted version
        $path_info = pathinfo($file);
        $dirname = $path_info['dirname'];
        $basename = $path_info['filename'];
        $original_ext = $path_info['extension'];

        echo sprintf("[CHECK] ID %d: Missing file: %s\n", $attachment_id, basename($file));

        // Try to find converted versions
        $converted_extensions = ['webp', 'avif'];
        $found = false;

        foreach ($converted_extensions as $ext) {
            $converted_file = "$dirname/$basename.$ext";
            
            if (file_exists($converted_file)) {
                echo sprintf("  → Found converted file: %s\n", basename($converted_file));
                
                // Update attachment file path
                update_attached_file($attachment_id, $converted_file);
                
                // Update post MIME type
                wp_update_post([
                    'ID'             => $attachment_id,
                    'post_mime_type' => "image/$ext"
                ]);

                // Update metadata
                $upload_dir = wp_upload_dir();
                $metadata['file'] = str_replace($upload_dir['basedir'] . '/', '', $converted_file);
                $metadata['mime_type'] = "image/$ext";

                // Update sizes in metadata to use correct extension
                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size_name => &$size_data) {
                        // Update file extension in size data
                        $size_basename = pathinfo($size_data['file'], PATHINFO_FILENAME);
                        $size_data['file'] = "$size_basename.$ext";
                        $size_data['mime-type'] = "image/$ext";
                    }
                }

                wp_update_attachment_metadata($attachment_id, $metadata);

                echo sprintf("  ✓ FIXED: Updated to %s\n", basename($converted_file));
                $fixed_count++;
                $found = true;
                break;
            }
        }

        if (!$found) {
            echo sprintf("  ✗ ERROR: No converted file found for %s\n", basename($file));
            $error_count++;
        }

        echo "\n";
    }

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "SUMMARY:\n";
    echo sprintf("Total attachments: %d\n", count($attachments));
    echo sprintf("Fixed: %d\n", $fixed_count);
    echo sprintf("Skipped (already OK): %d\n", $skipped_count);
    echo sprintf("Errors (not found): %d\n", $error_count);
    echo str_repeat('=', 60) . "\n";
    echo '</pre>';
}
