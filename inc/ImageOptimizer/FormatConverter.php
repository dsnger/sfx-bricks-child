<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

class FormatConverter
{
    /**
     * Convert an image to WebP or AVIF format.
     *
     * @param string     $file_path     Path to the source image file
     * @param int        $dimension     Target dimension (width or height based on mode)
     * @param array|null $log           Reference to log array for status messages
     * @param int|null   $attachment_id WordPress attachment ID (optional)
     * @param string     $suffix        Filename suffix for resized versions
     * @return string|false New file path on success, false on failure
     */
    public static function convert_to_format(string $file_path, int $dimension, ?array &$log = null, ?int $attachment_id = null, string $suffix = ''): string|false
    {
        $use_avif = Settings::get_use_avif();
        $format = Settings::get_format();
        $extension = Settings::get_extension();
        $path_info = pathinfo($file_path);
        $new_file_path = $path_info['dirname'] . '/' . $path_info['filename'] . $suffix . $extension;
        $quality = Settings::get_quality();
        $mode = Settings::get_resize_mode();

        if (!(extension_loaded('imagick') || extension_loaded('gd'))) {
            if ($log !== null) $log[] = sprintf(__('Error: No image library (Imagick/GD) available for %s', 'sfxtheme'), basename($file_path));
            return false;
        }

        $has_avif_support = (extension_loaded('imagick') && class_exists('Imagick') && in_array('AVIF', \Imagick::queryFormats())) || (extension_loaded('gd') && function_exists('imageavif'));
        if ($use_avif && !$has_avif_support) {
            if ($log !== null) $log[] = sprintf(__('Error: AVIF not supported on this server for %s', 'sfxtheme'), basename($file_path));
            return false;
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            if ($log !== null) $log[] = sprintf(__('Error: Image editor failed for %s - %s', 'sfxtheme'), basename($file_path), $editor->get_error_message());
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
            if ($log !== null) $log[] = sprintf(__('Error: Conversion failed for %s - %s', 'sfxtheme'), basename($file_path), $result->get_error_message());
            return false;
        }

        if ($log !== null) {
            $log[] = sprintf(
                __('Converted: %s → %s %s', 'sfxtheme'),
                basename($file_path),
                basename($new_file_path),
                $resized ? sprintf(__('(resized to %dpx %s, quality %d)', 'sfxtheme'), $dimension, $mode, $quality) : sprintf(__('(quality %d)', 'sfxtheme'), $quality)
            );
        }

        return $new_file_path;
    }
} 