<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * The single source of truth for a composed credit.
 *
 * Everything that renders a credit — the Bricks tags, caption auto-output,
 * overlay auto-output — goes through for(). The media library column is the
 * one deliberate exception: it reports what an editor stored, which is not the
 * same question.
 */
class Credit
{
    public const META_COPYRIGHT   = '_sfx_media_copyright';
    public const META_AI          = '_sfx_media_ai';
    public const META_IPTC_MARKER = '_sfx_media_iptc_prefilled';

    /** @var array<int, array<string, mixed>> per-request memoisation, keyed by attachment id */
    private static array $cache = [];

    /**
     * Neutralise Bricks' dynamic-data delimiters.
     *
     * Bricks parses the whole assembled document for {tags} after every
     * element has rendered (frontend.php:947). Anything we emit is part of
     * that document, and a copyright notice is free text typed by anyone who
     * can upload media — so `{post_title}` would silently become the page
     * title, and `{echo:…}` would reach Bricks' echo tag. wp_kses_post() does
     * not help: braces are not HTML.
     */
    public static function escape_braces(string $value): string
    {
        return strtr($value, ['{' => '&#123;', '}' => '&#125;']);
    }

    /**
     * @return array{copyright: string, ai_key: string, ai_label: string, icon_id: int, line: string}
     */
    public static function for(int $attachment_id): array
    {
        $empty = ['copyright' => '', 'ai_key' => '', 'ai_label' => '', 'icon_id' => 0, 'line' => ''];

        if ($attachment_id <= 0) {
            return $empty;
        }

        if (array_key_exists($attachment_id, self::$cache)) {
            return self::$cache[$attachment_id];
        }

        // A stored id whose file is gone. Bricks keeps the id and renders a
        // placeholder (image.php:839-848); a confident copyright line under an
        // error placeholder names an owner who never took the picture.
        if (!wp_get_attachment_url($attachment_id)) {
            return self::$cache[$attachment_id] = $empty;
        }

        $labels = Settings::get_labels();

        $copyright = trim((string) get_post_meta($attachment_id, self::META_COPYRIGHT, true));
        if ($copyright === '') {
            $copyright = trim((string) Settings::get('fallback_copyright'));
        }

        $ai_key = (string) get_post_meta($attachment_id, self::META_AI, true);
        if (!isset($labels[$ai_key])) {
            $ai_key = '';
        }

        $ai_label = $ai_key === '' ? '' : $labels[$ai_key];
        $icon_id  = $ai_key === '' ? 0 : (int) Settings::get('seal_' . $ai_key);

        $parts = [
            'copyright' => $copyright,
            'ai_key'    => $ai_key,
            'ai_label'  => $ai_label,
            'icon_id'   => $icon_id,
        ];

        $line = self::compose($copyright, $ai_key, $ai_label, $icon_id);

        if ($line !== '') {
            $line = (string) apply_filters('sfx_media_credits_line', $line, $attachment_id, $parts);
            // Order is the point: kses first so a filter cannot add script,
            // braces last so a filter cannot add a dynamic-data tag either.
            $line = self::escape_braces(wp_kses_post($line));
        }

        return self::$cache[$attachment_id] = $parts + ['line' => $line];
    }

    /**
     * The copyright fragment, escaped, with `©` prepended unless the editor
     * already wrote one.
     */
    public static function with_copyright_prefix(string $text): string
    {
        $escaped = esc_html($text);

        if (preg_match('/^\s*(©|\(c\)|copyright)/i', $text) === 1) {
            return $escaped;
        }

        return '©&nbsp;' . $escaped;
    }

    private static function compose(string $copyright, string $ai_key, string $ai_label, int $icon_id): string
    {
        $bits = [];

        if ($copyright !== '') {
            $bits[] = self::with_copyright_prefix($copyright);
        }

        $ai = self::ai_part($ai_key, $ai_label, $icon_id);
        if ($ai !== '') {
            $bits[] = $ai;
        }

        return implode('&nbsp;·&nbsp;', $bits);
    }

    private static function ai_part(string $ai_key, string $ai_label, int $icon_id): string
    {
        if ($ai_key === '') {
            return '';
        }

        $display = (string) Settings::get('credit_display');
        $url     = $icon_id > 0 ? wp_get_attachment_image_url($icon_id, 'full') : false;

        // Text mode, or a seal whose attachment was deleted: the label alone.
        // Never a broken image where a disclosure should be.
        if ($display === 'text' || !$url) {
            return esc_html($ai_label);
        }

        $size = (int) Settings::get('icon_size');
        $alt  = $display === 'icon' ? $ai_label : '';

        $img = sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="sfx-credit__seal">',
            esc_url((string) $url),
            esc_attr($alt),
            $size,
            $size
        );

        return $display === 'icon' ? $img : $img . '&nbsp;' . esc_html($ai_label);
    }

    /** Test seam for the per-request cache. */
    public static function reset_cache(): void
    {
        self::$cache = [];
    }
}
