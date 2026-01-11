<?php
/**
 * Revert Converted Image Back to Original Format
 * 
 * This script converts specific WebP/AVIF images back to their original
 * PNG/JPG format if the original file was preserved on disk.
 * 
 * Usage: Add to functions.php temporarily:
 * add_action('admin_init', function() {
 *     if (isset($_GET['revert_image']) && current_user_can('manage_options')) {
 *         $attachment_id = absint($_GET['revert_image']);
 *         require_once get_stylesheet_directory() . '/inc/ImageOptimizer/revert-to-original.php';
 *         \SFX\ImageOptimizer\revert_to_original($attachment_id);
 *         wp_die('Revert complete.');
 *     }
 * });
 * 
 * Then visit: /wp-admin/?revert_image=123 (replace 123 with attachment ID)
 */

namespace SFX\ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Revert a single image back to its original format
 */
function revert_to_original(int $attachment_id): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    echo '<h1>Reverting Image to Original Format</h1>';
    echo '<pre>';

    $converted_file = get_attached_file($attachment_id);
    
    if (!$converted_file) {
        echo "❌ ERROR: Attachment $attachment_id not found\n";
        echo '</pre>';
        return;
    }

    $path_info = pathinfo($converted_file);
    $dirname = $path_info['dirname'];
    $basename = $path_info['filename'];
    $current_ext = $path_info['extension'];

    echo sprintf("Attachment ID: %d\n", $attachment_id);
    echo sprintf("Current file: %s\n", basename($converted_file));
    echo sprintf("Current format: %s\n\n", strtoupper($current_ext));

    // Check if it's already in original format
    if (!in_array($current_ext, ['webp', 'avif'])) {
        echo "ℹ️  This image is already in original format ($current_ext)\n";
        echo "No action needed.\n";
        echo '</pre>';
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
        echo "❌ ERROR: Original file not found\n";
        echo "Searched for:\n";
        foreach ($original_extensions as $ext) {
            echo "  - $basename.$ext\n";
        }
        echo "\nThe original was likely deleted (Preserve Originals was OFF).\n";
        echo "\nOptions:\n";
        echo "1. Re-upload the original image\n";
        echo "2. Use a backup to restore the original\n";
        echo "3. Keep using the WebP/AVIF version\n";
        echo '</pre>';
        return;
    }

    echo sprintf("✓ Found original: %s\n", basename($original_file));
    
    $original_size = filesize($original_file) / 1024;
    $converted_size = filesize($converted_file) / 1024;
    
    echo sprintf("Original size: %.1f KB\n", $original_size);
    echo sprintf("Converted size: %.1f KB\n\n", $converted_size);

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
    wp_update_attachment_metadata($attachment_id, $metadata);

    // Optionally delete the converted file
    if (file_exists($converted_file)) {
        @unlink($converted_file);
        echo sprintf("✓ Deleted converted file: %s\n", basename($converted_file));
    }

    echo "\n✅ SUCCESS: Image reverted to original format!\n";
    echo sprintf("New format: %s\n", strtoupper(pathinfo($original_file, PATHINFO_EXTENSION)));
    echo sprintf("File: %s\n", basename($original_file));
    
    // Add to excluded images so it doesn't get converted again
    if (Settings::add_excluded_image($attachment_id)) {
        echo "\n✓ Added to excluded images list (won't be converted again)\n";
    }

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "NEXT STEPS:\n";
    echo "1. Refresh the Media Library to see the changes\n";
    echo "2. The image is now excluded from future conversions\n";
    echo "3. If you uploaded content, update any hardcoded URLs\n";
    echo '</pre>';
}

/**
 * Revert multiple images at once
 */
function revert_multiple_to_original(array $attachment_ids): array
{
    $results = [
        'success' => [],
        'failed' => [],
        'not_found' => [],
        'already_original' => []
    ];

    foreach ($attachment_ids as $attachment_id) {
        $converted_file = get_attached_file($attachment_id);
        
        if (!$converted_file) {
            $results['not_found'][] = $attachment_id;
            continue;
        }

        $path_info = pathinfo($converted_file);
        $dirname = $path_info['dirname'];
        $basename = $path_info['filename'];
        $current_ext = $path_info['extension'];

        // Skip if already original format
        if (!in_array($current_ext, ['webp', 'avif'])) {
            $results['already_original'][] = $attachment_id;
            continue;
        }

        // Try to find original
        $original_extensions = ['png', 'PNG', 'jpg', 'JPG', 'jpeg', 'JPEG'];
        $original_file = null;

        foreach ($original_extensions as $ext) {
            $potential_original = "$dirname/$basename.$ext";
            if (file_exists($potential_original)) {
                $original_file = $potential_original;
                break;
            }
        }

        if (!$original_file) {
            $results['failed'][] = $attachment_id;
            continue;
        }

        // Revert to original
        update_attached_file($attachment_id, $original_file);
        
        $mime_type = wp_check_filetype($original_file)['type'];
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $mime_type
        ]);

        $metadata = wp_generate_attachment_metadata($attachment_id, $original_file);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Delete converted file
        if (file_exists($converted_file)) {
            @unlink($converted_file);
        }

        // Add to exclusion list
        Settings::add_excluded_image($attachment_id);

        $results['success'][] = $attachment_id;
    }

    return $results;
}
