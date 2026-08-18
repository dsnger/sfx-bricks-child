<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

/**
 * ImageConversionService - Centralized image conversion logic
 * 
 * Provides shared conversion functionality for both upload handling (Controller)
 * and batch processing (Ajax). Includes WordPress hooks for extensibility.
 * 
 * Available Actions:
 * - sfx_image_before_convert: Fires before image conversion starts
 * - sfx_image_after_convert: Fires after successful conversion
 * - sfx_image_conversion_failed: Fires when conversion fails
 * - sfx_image_before_delete_original: Fires before original file deletion
 * - sfx_image_after_thumbnail: Fires after thumbnail generation
 * 
 * Available Filters:
 * - sfx_image_quality: Modify quality setting per-image
 * - sfx_image_max_values: Modify max dimensions per-image
 * - sfx_image_skip_conversion: Skip conversion for specific images
 * - sfx_image_metadata: Modify metadata before saving
 */
class ImageConversionService
{
    /**
     * Result status constants
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /**
     * Convert an image to the configured format with all size variations.
     * 
     * @param string   $file_path      Path to the source image
     * @param int|null $attachment_id  WordPress attachment ID (null for new uploads)
     * @param array    $options        Override options (quality, format, max_values, etc.)
     * @return array{status: string, files: array, main_file: string|null, log: array, error: string|null}
     */
    public static function convertImage(string $file_path, ?int $attachment_id = null, array $options = []): array
    {
        $log = [];
        $new_files = [];
        $main_converted_file = null;
        
        // Merge options with defaults from settings
        $use_avif = $options['use_avif'] ?? Settings::get_use_avif();
        $quality = $options['quality'] ?? Settings::get_quality();
        $mode = $options['mode'] ?? Settings::get_resize_mode();
        $max_values = $options['max_values'] ?? Settings::get_max_values();
        $format = $use_avif ? 'image/avif' : 'image/webp';
        $extension = $use_avif ? '.avif' : '.webp';
        
        // Allow filtering quality per-image
        $quality = apply_filters('sfx_image_quality', $quality, $attachment_id, $file_path);
        
        // Allow filtering max values per-image
        $max_values = apply_filters('sfx_image_max_values', $max_values, $attachment_id, $file_path);
        
        // Allow skipping conversion for specific images
        $skip = apply_filters('sfx_image_skip_conversion', false, $attachment_id, $file_path);
        if ($skip) {
            return [
                'status' => self::STATUS_SKIPPED,
                'files' => [],
                'main_file' => null,
                'log' => [sprintf(__('Skipped: Filtered out by sfx_image_skip_conversion - %s', 'sfxtheme'), basename($file_path))],
                'error' => null,
            ];
        }
        
        // Fire before conversion hook
        do_action('sfx_image_before_convert', $attachment_id, $file_path, [
            'quality' => $quality,
            'format' => $format,
            'max_values' => $max_values,
        ]);
        
        // Validate max values to avoid upscaling
        $valid_max_values = self::filterMaxValuesToAvoidUpscaling($file_path, $max_values, $mode);

        // An empty value set means we'd produce zero files but report
        // success, which lets callers delete the original while
        // $upload['file'] still points at it. Fail loudly instead.
        if (empty($valid_max_values)) {
            return [
                'status' => self::STATUS_FAILED,
                'files' => [],
                'main_file' => null,
                'log' => [sprintf(
                    __('Error: No valid sizes configured for %s — aborting to avoid data loss', 'sfxtheme'),
                    basename($file_path)
                )],
                'error' => __('No valid sizes configured', 'sfxtheme'),
            ];
        }

        // Convert each size
        $success = true;
        foreach ($valid_max_values as $index => $dimension) {
            $suffix = ($index === 0) ? '' : "-{$dimension}";
            $new_file_path = FormatConverter::convert_to_format(
                $file_path, 
                $dimension, 
                $log, 
                $attachment_id, 
                $suffix
            );
            
            if ($new_file_path) {
                if ($index === 0) {
                    $main_converted_file = $new_file_path;
                }
                $new_files[] = $new_file_path;
            } else {
                $success = false;
                break;
            }
        }
        
        // Generate thumbnail if main conversion succeeded
        if ($success && $main_converted_file) {
            $thumbnail_result = self::generateThumbnail(
                $main_converted_file,
                $extension,
                $format,
                $quality
            );
            
            if ($thumbnail_result['success']) {
                $new_files[] = $thumbnail_result['path'];
                $log[] = $thumbnail_result['message'];
                
                // Fire after thumbnail hook
                do_action('sfx_image_after_thumbnail', $attachment_id, $thumbnail_result['path']);
            } else {
                $success = false;
                $log[] = $thumbnail_result['message'];
            }
        }
        
        // Handle failure - rollback
        if (!$success) {
            self::rollbackConversion($new_files);
            $log[] = sprintf(__('Error: Conversion failed for %s, rolling back', 'sfxtheme'), basename($file_path));
            $log[] = sprintf(__('Original preserved: %s', 'sfxtheme'), basename($file_path));
            
            // Fire failure hook
            do_action('sfx_image_conversion_failed', $attachment_id, $file_path, $log);
            
            return [
                'status' => self::STATUS_FAILED,
                'files' => [],
                'main_file' => null,
                'log' => $log,
                'error' => __('Conversion failed', 'sfxtheme'),
            ];
        }
        
        // Belt-and-braces: never return SUCCESS without a main file.
        // Callers use main_file to rewrite $upload['file']; a null value
        // there is what lets the original-deletion path destroy data.
        if ($main_converted_file === null) {
            self::rollbackConversion($new_files);
            return [
                'status' => self::STATUS_FAILED,
                'files' => [],
                'main_file' => null,
                'log' => array_merge($log, [sprintf(
                    __('Error: Conversion produced no main file for %s', 'sfxtheme'),
                    basename($file_path)
                )]),
                'error' => __('Conversion produced no main file', 'sfxtheme'),
            ];
        }

        // Fire after conversion hook
        do_action('sfx_image_after_convert', $attachment_id, $main_converted_file, $new_files, [
            'original_path' => $file_path,
            'quality' => $quality,
            'format' => $format,
        ]);

        return [
            'status' => self::STATUS_SUCCESS,
            'files' => $new_files,
            'main_file' => $main_converted_file,
            'log' => $log,
            'error' => null,
            'format' => $format,
            'extension' => $extension,
            'quality' => $quality,
            'max_values' => $max_values,
            'mode' => $mode,
        ];
    }

    /**
     * Generate a thumbnail for the given image.
     * 
     * @param string $source_file Path to the source image
     * @param string $extension   File extension (e.g., '.webp')
     * @param string $format      MIME type (e.g., 'image/webp')
     * @param int    $quality     Quality setting (1-100)
     * @return array{success: bool, path: string|null, message: string}
     */
    public static function generateThumbnail(
        string $source_file, 
        string $extension, 
        string $format, 
        int $quality
    ): array {
        $thumb_size = Constants::THUMBNAIL_SIZE;
        $dirname = dirname($source_file);
        $base_name = pathinfo($source_file, PATHINFO_FILENAME);
        $thumbnail_path = "{$dirname}/{$base_name}-{$thumb_size}x{$thumb_size}{$extension}";
        
        $editor = wp_get_image_editor($source_file);
        if (is_wp_error($editor)) {
            return [
                'success' => false,
                'path' => null,
                'message' => sprintf(
                    __('Error: Image editor failed for thumbnail - %s', 'sfxtheme'),
                    $editor->get_error_message()
                ),
            ];
        }
        
        $editor->resize($thumb_size, $thumb_size, true);

        if ($format === 'image/webp') {
            $encoded = WebpEncoder::encode($editor, $thumbnail_path, $quality);
            if (is_wp_error($encoded)) {
                $saved = $editor->save($thumbnail_path, $format, ['quality' => $quality]);
            } else {
                $saved = ['path' => $thumbnail_path];
            }
        } else {
            $saved = $editor->save($thumbnail_path, $format, ['quality' => $quality]);
        }

        if (is_wp_error($saved)) {
            return [
                'success' => false,
                'path' => null,
                'message' => sprintf(
                    __('Error: Thumbnail generation failed - %s', 'sfxtheme'),
                    $saved->get_error_message()
                ),
            ];
        }
        
        return [
            'success' => true,
            'path' => $thumbnail_path,
            'message' => sprintf(__('Generated thumbnail: %s', 'sfxtheme'), basename($thumbnail_path)),
        ];
    }

    /**
     * Update attachment metadata after conversion.
     * 
     * @param int    $attachment_id Attachment ID
     * @param string $main_file     Path to the main converted file
     * @param array  $options       Conversion options (format, extension, quality, max_values, mode)
     * @param array  $log           Reference to log array
     * @return bool Success status
     */
    public static function updateAttachmentMetadata(
        int $attachment_id, 
        string $main_file, 
        array $options,
        array &$log
    ): bool {
        $format = $options['format'];
        $extension = $options['extension'];
        $quality = $options['quality'];
        $max_values = $options['max_values'];
        $mode = $options['mode'];
        $use_avif = $options['use_avif'] ?? ($format === 'image/avif');
        
        // Verify the new file exists
        if (!file_exists($main_file)) {
            $log[] = sprintf(__('Error: Converted file does not exist: %s', 'sfxtheme'), basename($main_file));
            return false;
        }
        
        // Update the attachment file path FIRST
        update_attached_file($attachment_id, $main_file);
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $format]);
        
        // Generate metadata using the updated file path
        $metadata = wp_generate_attachment_metadata($attachment_id, $main_file);
        if (is_wp_error($metadata)) {
            $log[] = sprintf(
                __('Error: Metadata regeneration failed for %s - %s', 'sfxtheme'),
                basename($main_file),
                $metadata->get_error_message()
            );
            return false;
        }
        
        // Set file path in metadata (relative to uploads dir)
        $upload_dir = wp_upload_dir();
        $metadata['file'] = str_replace($upload_dir['basedir'] . '/', '', $main_file);

        $main_size = self::readImageSize($main_file);
        if ($main_size !== null) {
            $metadata['width'] = $main_size['width'];
            $metadata['height'] = $main_size['height'];
        }

        $full_width = (int) ($metadata['width'] ?? 0);
        $full_height = (int) ($metadata['height'] ?? 0);
        
        $dirname = dirname($main_file);
        $base_name = pathinfo($main_file, PATHINFO_FILENAME);
        
        // Initialize sizes array if needed
        if (!isset($metadata['sizes'])) {
            $metadata['sizes'] = [];
        }
        
        // Add custom sizes with real pixel dimensions. Storing 0 for the
        // unconstrained axis makes WordPress emit height="1" (CLS).
        foreach ($max_values as $index => $dimension) {
            if ($index === 0) continue;
            $size_file = "{$dirname}/{$base_name}-{$dimension}{$extension}";
            if (file_exists($size_file)) {
                $size_dims = self::dimensionsForCustomSize(
                    $size_file,
                    (int) $dimension,
                    $mode,
                    $full_width,
                    $full_height
                );
                $metadata['sizes']["custom-{$dimension}"] = [
                    'file' => "{$base_name}-{$dimension}{$extension}",
                    'width' => $size_dims['width'],
                    'height' => $size_dims['height'],
                    'mime-type' => $format,
                ];
            }
        }
        
        // Add thumbnail metadata
        $thumb_size = Constants::THUMBNAIL_SIZE;
        $thumbnail_file = "{$dirname}/{$base_name}-{$thumb_size}x{$thumb_size}{$extension}";
        if (file_exists($thumbnail_file)) {
            $metadata['sizes']['thumbnail'] = [
                'file' => "{$base_name}-{$thumb_size}x{$thumb_size}{$extension}",
                'width' => $thumb_size,
                'height' => $thumb_size,
                'mime-type' => $format,
            ];
        }
        
        // Add quality and optimization stamp
        $metadata['webp_quality'] = $quality;
        $metadata['pixrefiner_stamp'] = [
            'format' => $use_avif ? 'avif' : 'webp',
            'quality' => $quality,
            'resize_mode' => $mode,
            'max_values' => $max_values,
            'enc' => Constants::ENCODER_VERSION,
        ];
        
        // Allow filtering metadata before save
        $metadata = apply_filters('sfx_image_metadata', $metadata, $attachment_id, $main_file);
        
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        return true;
    }

    /**
     * Rollback a failed conversion by deleting created files.
     * 
     * @param array $files List of file paths to delete
     * @return void
     */
    public static function rollbackConversion(array $files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Check if an image should be re-processed based on current settings.
     * 
     * @param int   $attachment_id Attachment ID
     * @param array $options       Current settings to compare against
     * @return bool True if image needs reprocessing
     */
    public static function needsReprocessing(int $attachment_id, array $options = []): bool
    {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata) {
            return true;
        }
        
        $use_avif = $options['use_avif'] ?? Settings::get_use_avif();
        $quality = $options['quality'] ?? Settings::get_quality();
        $mode = $options['mode'] ?? Settings::get_resize_mode();
        $max_values = $options['max_values'] ?? Settings::get_max_values();
        
        $expected_stamp = [
            'format' => $use_avif ? 'avif' : 'webp',
            'quality' => $quality,
            'resize_mode' => $mode,
            'max_values' => $max_values,
            'enc' => Constants::ENCODER_VERSION,
        ];

        $existing_stamp = $metadata['pixrefiner_stamp'] ?? null;
        
        return empty($existing_stamp) || $existing_stamp !== $expected_stamp;
    }

    /**
     * Check if a converted backup exists for an image.
     * 
     * @param string $file_path Path to the original image
     * @return bool True if a backup exists
     */
    public static function backupExists(string $file_path): bool
    {
        $dirname = dirname($file_path);
        $base_name = pathinfo($file_path, PATHINFO_FILENAME);
        
        foreach (Constants::CONVERTED_EXTENSIONS as $ext) {
            $potential_backup = "{$dirname}/{$base_name}.{$ext}";
            if (file_exists($potential_backup)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Delete the original file with safety checks and hooks.
     * 
     * @param string     $file_path          Path to the original file
     * @param int|null   $attachment_id      Attachment ID
     * @param bool       $preserve_originals Whether to preserve originals
     * @param bool       $was_restored       Whether image was previously restored
     * @param bool       $backup_existed     Whether backup existed before conversion
     * @param array|null $log                Reference to log array
     * @return bool True if file was deleted or should be preserved
     */
    public static function handleOriginalDeletion(
        string $file_path,
        ?int $attachment_id,
        bool $preserve_originals,
        bool $was_restored,
        bool $backup_existed,
        ?array &$log = null
    ): bool {
        $basename = basename($file_path);
        
        // Check if we should preserve the original
        if ($preserve_originals) {
            if ($log !== null) {
                $log[] = sprintf(__('Preserved original: %s', 'sfxtheme'), $basename);
            }
            return true;
        }
        
        if ($was_restored) {
            if ($log !== null) {
                $log[] = sprintf(__('Preserved original: %s (previously restored)', 'sfxtheme'), $basename);
            }
            return true;
        }
        
        if ($backup_existed) {
            if ($log !== null) {
                $log[] = sprintf(__('Preserved original: %s (was previously optimized)', 'sfxtheme'), $basename);
            }
            return true;
        }
        
        // Fire before delete hook - allows cancellation
        $should_delete = apply_filters('sfx_image_should_delete_original', true, $file_path, $attachment_id);
        if (!$should_delete) {
            if ($log !== null) {
                $log[] = sprintf(__('Preserved original: %s (filtered)', 'sfxtheme'), $basename);
            }
            return true;
        }
        
        // Fire action before deletion
        do_action('sfx_image_before_delete_original', $file_path, $attachment_id);
        
        // Perform deletion with retry
        return Settings::delete_file_with_retry($file_path, $log);
    }

    /**
     * Read pixel width/height from an image file.
     *
     * @return array{width: int, height: int}|null
     */
    public static function readImageSize(string $file_path): ?array
    {
        if ($file_path === '' || !is_readable($file_path)) {
            return null;
        }

        $size = function_exists('wp_getimagesize')
            ? wp_getimagesize($file_path)
            : @getimagesize($file_path);

        if (!is_array($size) || (int) $size[0] < 1 || (int) $size[1] < 1) {
            return null;
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    /**
     * Compute the missing axis for a size constrained on width or height.
     *
     * @return array{width: int, height: int}
     */
    public static function proportionalSize(int $dimension, string $mode, int $full_width, int $full_height): array
    {
        if ($mode === 'height') {
            $height = ($full_height > 0) ? min($dimension, $full_height) : $dimension;
            return [
                'width' => ($full_height > 0) ? max(1, (int) round($full_width * $height / $full_height)) : 0,
                'height' => $height,
            ];
        }

        $width = ($full_width > 0) ? min($dimension, $full_width) : $dimension;
        return [
            'width' => $width,
            'height' => ($full_width > 0) ? max(1, (int) round($full_height * $width / $full_width)) : 0,
        ];
    }

    /**
     * Resolve width/height for a generated custom size.
     *
     * Prefer the real pixel size of the file. Fall back to a proportion of
     * the full image so metadata never stores 0 on the unconstrained axis.
     *
     * @return array{width: int, height: int}
     */
    public static function dimensionsForCustomSize(
        string $size_file,
        int $dimension,
        string $mode,
        int $full_width = 0,
        int $full_height = 0
    ): array {
        $from_file = self::readImageSize($size_file);
        if ($from_file !== null) {
            return $from_file;
        }

        return self::proportionalSize($dimension, $mode, $full_width, $full_height);
    }

    /**
     * Fill in Image Optimizer custom-size width/height that WordPress would clamp to 1px.
     *
     * Existing attachments already stored height 0 (width mode) or width 0
     * (height mode). Repairing on read fixes CLS without a media regenerate.
     * Only `custom-{int}` sizes are touched; core and plugin crops are left alone.
     */
    public static function repairMissingSizeDimensions(array $metadata): array
    {
        $full_width = (int) ($metadata['width'] ?? 0);
        $full_height = (int) ($metadata['height'] ?? 0);

        if ($full_width <= 1 || $full_height <= 1 || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return $metadata;
        }

        foreach ($metadata['sizes'] as $size_name => $size) {
            if (!is_array($size) || !is_string($size_name) || !preg_match('/^custom-\d+$/', $size_name)) {
                continue;
            }

            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);

            if ($width > 1 && $height > 1) {
                continue;
            }

            if ($width > 1 && $height <= 1) {
                $metadata['sizes'][$size_name]['height'] = max(1, (int) round($full_height * $width / $full_width));
                continue;
            }

            if ($height > 1 && $width <= 1) {
                $metadata['sizes'][$size_name]['width'] = max(1, (int) round($full_width * $height / $full_height));
            }
        }

        return $metadata;
    }

    /**
     * Filter max values to avoid upscaling images.
     * 
     * @param string $file_path  Path to the source image
     * @param array  $max_values Array of max dimensions
     * @param string $mode       Resize mode ('width' or 'height')
     * @return array Filtered max values
     */
    private static function filterMaxValuesToAvoidUpscaling(string $file_path, array $max_values, string $mode): array
    {
        if ($mode !== 'width') {
            return $max_values;
        }
        
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return $max_values;
        }
        
        $dimensions = $editor->get_size();
        $original_width = $dimensions['width'];
        
        return array_filter($max_values, function($width, $index) use ($original_width) {
            // Always keep the first value (main image)
            return $index === 0 || $width <= $original_width;
        }, ARRAY_FILTER_USE_BOTH);
    }
}
