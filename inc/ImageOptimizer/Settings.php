<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

class Settings
{
    /**
     * Migrate legacy option names to new prefixed names.
     * 
     * @deprecated 0.8.0 This migration function should be removed in version 0.9.0 or later
     *             once all users have had sufficient time to update.
     * @todo Remove this function in version 0.9.0+
     * 
     * @return void
     */
    public static function migrate_legacy_options(): void
    {
        // Check if migration has already been performed
        if (get_option('sfx_webp_migration_complete', false)) {
            return;
        }

        // Map of old option names to new option names
        $option_map = [
            'webp_max_widths'             => 'sfx_webp_max_widths',
            'webp_max_heights'            => 'sfx_webp_max_heights',
            'webp_resize_mode'            => 'sfx_webp_resize_mode',
            'webp_quality'                => 'sfx_webp_quality',
            'webp_batch_size'             => 'sfx_webp_batch_size',
            'webp_preserve_originals'     => 'sfx_webp_preserve_originals',
            'webp_disable_auto_conversion'=> 'sfx_webp_disable_auto_conversion',
            'webp_min_size_kb'            => 'sfx_webp_min_size_kb',
            'webp_use_avif'               => 'sfx_webp_use_avif',
            'webp_excluded_images'        => 'sfx_webp_excluded_images',
            'webp_conversion_log'         => 'sfx_webp_conversion_log',
        ];

        $migrated_count = 0;

        foreach ($option_map as $old_key => $new_key) {
            $old_value = get_option($old_key, null);
            
            // Only migrate if old option exists and new option doesn't
            if ($old_value !== null && get_option($new_key, null) === null) {
                update_option($new_key, $old_value);
                $migrated_count++;
            }
            
            // Delete old option after migration (cleanup)
            if ($old_value !== null) {
                delete_option($old_key);
            }
        }

        // Log migration if any options were migrated
        if ($migrated_count > 0) {
            self::append_log(sprintf(
                /* translators: %d: number of migrated options */
                __('Migration complete: %d legacy options migrated to new format.', 'sfxtheme'),
                $migrated_count
            ));
        }

        // Mark migration as complete
        update_option('sfx_webp_migration_complete', true);
    }

    public static function limit_image_sizes(array $sizes): array
    {
        if (self::get_disable_auto_conversion()) {
            return $sizes;
        }
        return ['thumbnail' => $sizes['thumbnail']];
    }

    public static function set_thumbnail_size(): void
    {
        update_option('thumbnail_size_w', 150);
        update_option('thumbnail_size_h', 150);
        update_option('thumbnail_crop', 1);
    }

    public static function register_custom_sizes(): void
    {
        $mode = self::get_resize_mode();
        if ($mode === 'width') {
            $max_values = self::get_max_widths();
            $additional_values = array_slice($max_values, 1, 3);
            foreach ($additional_values as $width) {
                add_image_size("custom-$width", $width, 0, false);
            }
        } else {
            $max_values = self::get_max_heights();
            $additional_values = array_slice($max_values, 1, 3);
            foreach ($additional_values as $height) {
                add_image_size("custom-$height", 0, $height, false);
            }
        }
    }

    public static function get_max_widths(): array
    {
        $value = get_option('sfx_webp_max_widths', Constants::DEFAULT_MAX_WIDTHS);
        $widths = array_map('absint', array_filter(explode(',', $value)));
        $widths = array_filter($widths, fn($w) => $w > 0 && $w <= Constants::MAX_DIMENSION);
        return array_slice($widths, 0, Constants::MAX_CUSTOM_SIZES);
    }

    public static function get_max_heights(): array
    {
        $value = get_option('sfx_webp_max_heights', Constants::DEFAULT_MAX_HEIGHTS);
        $heights = array_map('absint', array_filter(explode(',', $value)));
        $heights = array_filter($heights, fn($h) => $h > 0 && $h <= Constants::MAX_DIMENSION);
        return array_slice($heights, 0, Constants::MAX_CUSTOM_SIZES);
    }

    public static function get_resize_mode(): string
    {
        return get_option('sfx_webp_resize_mode', Constants::DEFAULT_RESIZE_MODE);
    }

    public static function get_quality(): int
    {
        return (int) get_option('sfx_webp_quality', Constants::DEFAULT_QUALITY);
    }

    public static function get_batch_size(): int
    {
        return (int) get_option('sfx_webp_batch_size', Constants::DEFAULT_BATCH_SIZE);
    }

    public static function get_preserve_originals(): bool
    {
        return (bool) get_option('sfx_webp_preserve_originals', false);
    }

    public static function get_disable_auto_conversion(): bool
    {
        return (bool) get_option('sfx_webp_disable_auto_conversion', false);
    }

    public static function get_min_size_kb(): int
    {
        return (int) get_option('sfx_webp_min_size_kb', 0);
    }

    public static function get_use_avif(): bool
    {
        return (bool) get_option('sfx_webp_use_avif', false);
    }

    /**
     * Get the target image format MIME type.
     *
     * @return string MIME type (image/webp or image/avif)
     */
    public static function get_format(): string
    {
        return self::get_use_avif() ? 'image/avif' : 'image/webp';
    }

    /**
     * Get the target image file extension (with dot).
     *
     * @return string Extension (.webp or .avif)
     */
    public static function get_extension(): string
    {
        return self::get_use_avif() ? '.avif' : '.webp';
    }

    /**
     * Get the target image file extension (without dot).
     *
     * @return string Extension (webp or avif)
     */
    public static function get_extension_name(): string
    {
        return self::get_use_avif() ? 'avif' : 'webp';
    }

    /**
     * Get max dimension values based on current resize mode.
     *
     * @return array Array of max dimension values
     */
    public static function get_max_values(): array
    {
        return (self::get_resize_mode() === 'width') 
            ? self::get_max_widths() 
            : self::get_max_heights();
    }

    public static function get_excluded_images(): array
    {
        $excluded = get_option('sfx_webp_excluded_images', []);
        return is_array($excluded) ? array_map('absint', $excluded) : [];
    }

    public static function add_excluded_image(int $attachment_id): bool
    {
        $excluded = self::get_excluded_images();
        if (!in_array($attachment_id, $excluded, true)) {
            $excluded[] = $attachment_id;
            update_option('sfx_webp_excluded_images', array_values(array_unique($excluded)));
            self::append_log(sprintf(__('Excluded image added: Attachment ID %d', 'sfxtheme'), $attachment_id));
            return true;
        }
        return false;
    }

    public static function remove_excluded_image(int $attachment_id): bool
    {
        $excluded = self::get_excluded_images();
        $index = array_search($attachment_id, $excluded, true);
        if ($index !== false) {
            unset($excluded[$index]);
            update_option('sfx_webp_excluded_images', array_values($excluded));
            self::append_log(sprintf(__('Excluded image removed: Attachment ID %d', 'sfxtheme'), $attachment_id));
            return true;
        }
        return false;
    }

    /**
     * Append message(s) to the conversion log.
     * Handles both single messages and arrays of messages.
     * 
     * @param string|array $messages Single message string or array of messages
     * @return void
     */
    public static function append_log(string|array $messages): void
    {
        $log = get_option(Constants::LOG_OPTION_KEY, []);
        
        if (is_string($messages)) {
            $log[] = $messages;
        } else {
            $log = array_merge($log, $messages);
        }
        
        update_option(Constants::LOG_OPTION_KEY, array_slice($log, -Constants::LOG_LIMIT));
    }

    /**
     * Get the current log entries.
     * 
     * @return array
     */
    public static function get_log(): array
    {
        return get_option(Constants::LOG_OPTION_KEY, []);
    }

    /**
     * Clear the conversion log.
     * 
     * @return void
     */
    public static function clear_log(): void
    {
        update_option(Constants::LOG_OPTION_KEY, []);
    }

    /**
     * Attempt to delete a file with retry logic.
     * 
     * @param string     $file_path   Path to file to delete
     * @param array|null $log         Reference to log array for status messages
     * @param int        $max_retries Maximum number of retry attempts
     * @return bool True if file was deleted or doesn't exist, false on failure
     */
    public static function delete_file_with_retry(string $file_path, ?array &$log = null, int $max_retries = 0): bool
    {
        if ($max_retries === 0) {
            $max_retries = Constants::MAX_DELETE_RETRIES;
        }

        // If file doesn't exist, consider it a success
        if (!file_exists($file_path)) {
            return true;
        }

        $attempts = 0;
        $chmod_failed = false;
        $basename = basename($file_path);

        while ($attempts < $max_retries && file_exists($file_path)) {
            // Try to make file writable if it isn't
            if (!is_writable($file_path)) {
                @chmod($file_path, 0644);
                if (!is_writable($file_path)) {
                    if ($chmod_failed) {
                        if ($log !== null) {
                            $log[] = sprintf(
                                __('Error: Cannot make %s writable after retry - skipping deletion', 'sfxtheme'),
                                $basename
                            );
                        }
                        return false;
                    }
                    $chmod_failed = true;
                }
            }

            // Attempt deletion
            if (@unlink($file_path)) {
                if ($log !== null) {
                    $log[] = sprintf(__('Deleted original: %s', 'sfxtheme'), $basename);
                }
                return true;
            }

            $attempts++;
            if ($attempts < $max_retries) {
                sleep(Constants::RETRY_DELAY_SECONDS);
            }
        }

        // If we get here, all retries failed
        if (file_exists($file_path)) {
            if ($log !== null) {
                $log[] = sprintf(
                    __('Error: Failed to delete original %s after %d retries', 'sfxtheme'),
                    $basename,
                    $max_retries
                );
            }
            return false;
        }

        return true;
    }

    /**
     * Delete all options created by the ImageOptimizer feature.
     *
     * @return void
     */
    public static function delete_all_options(): void
    {
        $options = [
            'sfx_webp_max_widths',
            'sfx_webp_max_heights',
            'sfx_webp_resize_mode',
            'sfx_webp_quality',
            'sfx_webp_batch_size',
            'sfx_webp_preserve_originals',
            'sfx_webp_disable_auto_conversion',
            'sfx_webp_min_size_kb',
            'sfx_webp_use_avif',
            'sfx_webp_excluded_images',
            'sfx_webp_conversion_log',
        ];
        foreach ($options as $option) {
            delete_option($option);
        }
    }
} 