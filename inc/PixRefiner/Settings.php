<?php
declare(strict_types=1);

namespace SFX\PixRefiner;

class Settings
{
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
        $value = get_option('webp_max_widths', '1920,1200,600,300');
        $widths = array_map('absint', array_filter(explode(',', $value)));
        $widths = array_filter($widths, fn($w) => $w > 0 && $w <= 9999);
        return array_slice($widths, 0, 4);
    }

    public static function get_max_heights(): array
    {
        $value = get_option('webp_max_heights', '1080,720,480,360');
        $heights = array_map('absint', array_filter(explode(',', $value)));
        $heights = array_filter($heights, fn($h) => $h > 0 && $h <= 9999);
        return array_slice($heights, 0, 4);
    }

    public static function get_resize_mode(): string
    {
        return get_option('webp_resize_mode', 'width');
    }

    public static function get_quality(): int
    {
        return (int) get_option('webp_quality', 80);
    }

    public static function get_batch_size(): int
    {
        return (int) get_option('webp_batch_size', 5);
    }

    public static function get_preserve_originals(): bool
    {
        return (bool) get_option('webp_preserve_originals', false);
    }

    public static function get_disable_auto_conversion(): bool
    {
        return (bool) get_option('webp_disable_auto_conversion', false);
    }

    public static function get_min_size_kb(): int
    {
        return (int) get_option('webp_min_size_kb', 0);
    }

    public static function get_use_avif(): bool
    {
        return (bool) get_option('webp_use_avif', false);
    }

    public static function get_excluded_images(): array
    {
        $excluded = get_option('webp_excluded_images', []);
        return is_array($excluded) ? array_map('absint', $excluded) : [];
    }

    public static function add_excluded_image(int $attachment_id): bool
    {
        $excluded = self::get_excluded_images();
        if (!in_array($attachment_id, $excluded, true)) {
            $excluded[] = $attachment_id;
            update_option('webp_excluded_images', array_unique($excluded));
            $log = get_option('webp_conversion_log', []);
            $log[] = sprintf(__('Excluded image added: Attachment ID %d', 'wpturbo'), $attachment_id);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
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
            update_option('webp_excluded_images', array_values($excluded));
            $log = get_option('webp_conversion_log', []);
            $log[] = sprintf(__('Excluded image removed: Attachment ID %d', 'wpturbo'), $attachment_id);
            update_option('webp_conversion_log', array_slice((array)$log, -500));
            return true;
        }
        return false;
    }
} 