<?php
/**
 * Verify Original Files Are Preserved
 * 
 * This script checks if original JPG/PNG files still exist on disk
 * even though WordPress shows WebP/AVIF in the media library.
 * 
 * Usage: Add to functions.php temporarily:
 * add_action('admin_init', function() {
 *     if (isset($_GET['verify_originals']) && current_user_can('manage_options')) {
 *         require_once get_stylesheet_directory() . '/inc/ImageOptimizer/verify-originals.php';
 *         \SFX\ImageOptimizer\verify_originals();
 *         wp_die('Verification complete.');
 *     }
 * });
 * 
 * Then visit: /wp-admin/?verify_originals=1
 */

namespace SFX\ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

function verify_originals(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    echo '<h1>Verifying Original Files</h1>';
    echo '<p>Checking if original JPG/PNG files are preserved on disk...</p>';
    echo '<pre>';

    // Get all WebP/AVIF attachments
    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/webp', 'image/avif'],
        'posts_per_page' => 100, // Check first 100
        'fields'         => 'ids',
    ];

    $attachments = get_posts($args);
    $with_originals = 0;
    $without_originals = 0;

    echo sprintf("Checking %d WebP/AVIF attachments...\n\n", count($attachments));
    echo str_repeat('=', 80) . "\n\n";

    foreach ($attachments as $attachment_id) {
        $converted_file = get_attached_file($attachment_id);
        $path_info = pathinfo($converted_file);
        $dirname = $path_info['dirname'];
        $basename = $path_info['filename'];
        
        echo sprintf("ID %d: %s\n", $attachment_id, basename($converted_file));
        
        // Check for original files
        $original_extensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];
        $found_original = false;
        
        foreach ($original_extensions as $ext) {
            $original_file = "$dirname/$basename.$ext";
            if (file_exists($original_file)) {
                $original_size = filesize($original_file) / 1024; // KB
                $converted_size = filesize($converted_file) / 1024; // KB
                $savings = (($original_size - $converted_size) / $original_size) * 100;
                
                echo sprintf("  ✓ ORIGINAL PRESERVED: %s (%.1f KB)\n", 
                    basename($original_file), 
                    $original_size
                );
                echo sprintf("  → Converted version: %.1f KB\n", $converted_size);
                echo sprintf("  → Savings: %.1f%%\n", $savings);
                
                $with_originals++;
                $found_original = true;
                break;
            }
        }
        
        if (!$found_original) {
            echo "  ✗ No original found (may have been deleted before preserve was enabled)\n";
            $without_originals++;
        }
        
        echo "\n";
    }

    echo str_repeat('=', 80) . "\n";
    echo "SUMMARY:\n";
    echo sprintf("Checked: %d attachments\n", count($attachments));
    echo sprintf("With preserved originals: %d\n", $with_originals);
    echo sprintf("Without originals: %d\n", $without_originals);
    echo str_repeat('=', 80) . "\n";
    
    if ($with_originals > 0) {
        echo "\n✓ SUCCESS: Original files ARE being preserved on disk!\n";
        echo "They're just not shown in the Media Library (by design).\n";
    } else {
        echo "\n⚠ WARNING: No original files found.\n";
        echo "This might mean:\n";
        echo "1. Preserve originals was disabled when these were converted\n";
        echo "2. Files were converted before enabling preserve originals\n";
        echo "3. Need to check the preserve originals setting\n";
    }
    
    echo '</pre>';
}
