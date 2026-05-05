<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

/**
 * Direct libwebp encoder that bypasses WP_Image_Editor::save() for WebP output.
 *
 * WP's WebP editors only set the quality value; they do not tune the libwebp
 * encoder method, do not enable lossless mode, and do not expose alpha quality.
 * This class fills those gaps so the admin quality slider has visible effect
 * across its range — in particular, quality=100 produces lossless WebP.
 */
final class WebpEncoder
{
    public static function isLosslessQuality(int $q): bool
    {
        return $q >= 100;
    }

    /**
     * Encode the editor's already-loaded (and resized) image as WebP.
     *
     * @return true|\WP_Error True on success, WP_Error on failure (caller should fall back).
     */
    public static function encode(\WP_Image_Editor $editor, string $path, int $quality): bool|\WP_Error
    {
        try {
            // WP_Image_Editor stores the underlying resource on protected $this->image.
            // Stable property name across both Imagick and GD subclasses since WP 3.5.
            $resource = \Closure::bind(fn() => $this->image, $editor, get_class($editor))();
        } catch (\Throwable $e) {
            return new \WP_Error('webp_encoder_resource', $e->getMessage());
        }

        if ($editor instanceof \WP_Image_Editor_Imagick && $resource instanceof \Imagick) {
            return self::encodeImagick($resource, $path, $quality);
        }
        if ($editor instanceof \WP_Image_Editor_GD) {
            return self::encodeGd($resource, $path, $quality);
        }
        return new \WP_Error('webp_encoder_unsupported', 'Unsupported WP_Image_Editor subclass');
    }

    private static function encodeImagick(\Imagick $src, string $path, int $quality): bool|\WP_Error
    {
        if (!in_array('WEBP', \Imagick::queryFormats('WEBP'), true)) {
            return new \WP_Error('webp_encoder_no_libwebp', 'Imagick build lacks WebP support');
        }

        $temp = $path . '.tmp';
        try {
            // Operate on the editor's image directly. Cloning Imagick has had
            // subtle bugs that can produce decodable-byte-count but undecodable
            // pixel data. WP's own _save() also writes in place. Caller treats
            // the editor as one-shot, so mutating it here is safe.
            $src->setImageFormat('webp');

            if (self::isLosslessQuality($quality)) {
                $src->setOption('webp:lossless', 'true');
            } else {
                $src->setImageCompressionQuality($quality);
                $src->setOption('webp:method', '6');
            }

            // Reset page geometry. Imagick keeps a canvas/page rectangle from
            // the source that survives resize and (especially) crop. writeImage
            // honors that page, so without this call cropped thumbnails export
            // a partially-empty/corrupt frame. Mirrors WP_Image_Editor_Imagick::_save.
            if (method_exists($src, 'setImagePage')) {
                $src->setImagePage($src->getImageWidth(), $src->getImageHeight(), 0, 0);
            }

            // Write to a temp path first so a fatal mid-write (timeout, OOM)
            // never leaves a partial/corrupt file at the live path.
            if (!$src->writeImage($temp)) {
                @unlink($temp);
                return new \WP_Error('webp_encoder_write', 'Imagick writeImage returned false');
            }
        } catch (\Throwable $e) {
            @unlink($temp);
            return new \WP_Error('webp_encoder_imagick', $e->getMessage());
        }

        return self::commitTemp($temp, $path);
    }

    private static function encodeGd($resource, string $path, int $quality): bool|\WP_Error
    {
        if (!function_exists('imagewebp')) {
            return new \WP_Error('webp_encoder_no_gd_webp', 'GD build lacks WebP support');
        }

        $temp = $path . '.tmp';
        $arg = self::isLosslessQuality($quality) ? IMG_WEBP_LOSSLESS : $quality;
        if (!@imagewebp($resource, $temp, $arg)) {
            @unlink($temp);
            return new \WP_Error('webp_encoder_gd', 'imagewebp returned false');
        }

        return self::commitTemp($temp, $path);
    }

    /**
     * Verify the temp output is valid, then atomically rename to the live path.
     * Failure deletes the temp and returns WP_Error so the caller falls back.
     */
    private static function commitTemp(string $temp, string $path): bool|\WP_Error
    {
        $verified = self::verifyOutput($temp);
        if (is_wp_error($verified)) {
            @unlink($temp);
            return $verified;
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            return new \WP_Error('webp_encoder_rename', 'Failed to rename temp file');
        }
        return true;
    }

    /**
     * Confirm the encoder actually produced a non-empty, decodable WebP. Any
     * failure here returns WP_Error so the caller falls back to $editor->save().
     */
    private static function verifyOutput(string $path): bool|\WP_Error
    {
        if (!file_exists($path) || filesize($path) === 0) {
            return new \WP_Error('webp_encoder_empty', 'Encoder wrote no bytes');
        }

        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return new \WP_Error('webp_encoder_invalid', 'Encoder output is not a valid image');
        }

        return true;
    }
}
