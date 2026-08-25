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

        $filtered = apply_filters('sfx_media_credits_parts', $parts, $attachment_id);
        $parts    = self::validate_parts(is_array($filtered) ? $filtered : $parts, $parts, $labels);

        $line = self::compose($parts['copyright'], $parts['ai_key'], $parts['ai_label'], $parts['icon_id'], $attachment_id);

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
     *
     * $attachment_id defaults to 0 so the public signature stays backwards
     * compatible; compose() is the only caller and always supplies it.
     */
    public static function with_copyright_prefix(string $text, int $attachment_id = 0): string
    {
        $escaped = esc_html($text);

        if (preg_match('/^\s*(©|\(c\)|copyright)/i', $text) === 1) {
            return $escaped;
        }

        $prefix = (string) apply_filters('sfx_media_credits_copyright_prefix', '©&nbsp;', $text, $attachment_id);

        return $prefix . $escaped;
    }

    private static function compose(string $copyright, string $ai_key, string $ai_label, int $icon_id, int $attachment_id): string
    {
        $bits = [];

        if ($copyright !== '') {
            $bits[] = self::with_copyright_prefix($copyright, $attachment_id);
        }

        $ai = self::ai_part($ai_key, $ai_label, $icon_id, $attachment_id);
        if ($ai !== '') {
            $bits[] = $ai;
        }

        // Only consulted when both parts are present, so a one-part credit
        // never triggers a leading/trailing separator by accident.
        if (count($bits) <= 1) {
            return implode('', $bits);
        }

        $separator = (string) apply_filters('sfx_media_credits_separator', '&nbsp;·&nbsp;', $attachment_id);

        return implode($separator, $bits);
    }

    private static function ai_part(string $ai_key, string $ai_label, int $icon_id, int $attachment_id): string
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

        $img = (string) apply_filters('sfx_media_credits_seal_html', $img, $icon_id, $ai_key, $size, $attachment_id);

        return $display === 'icon' ? $img : $img . '&nbsp;' . esc_html($ai_label);
    }

    /**
     * Re-validate the parts array a `sfx_media_credits_parts` filter returned.
     *
     * The validated ai_key is authoritative. ai_label and icon_id are ALWAYS
     * derived from it, and any values the filter supplied for them are
     * discarded — never read here at all. Two Gate passes found holes when an
     * earlier draft let a filter set one and fall back independently on the
     * others: an invalid key with a stale label kept disclosing a marking
     * data-sfx-ai no longer carried, and a valid key could be paired with
     * another key's seal.
     *
     * @param array<string, mixed> $filtered the filter's return value
     * @param array<string, mixed> $original the unfiltered parts, as the fallback
     * @param array<string, string> $labels the label map for(), already resolved once — passed
     *   in rather than re-fetched, since sfx_media_credits_labels is a
     *   pre-existing filter with possible third-party callbacks, and this
     *   is on every for() call, not only when a parts filter is registered
     * @return array{copyright: string, ai_key: string, ai_label: string, icon_id: int}
     */
    private static function validate_parts(array $filtered, array $original, array $labels): array
    {
        $ai_key = (string) ($filtered['ai_key'] ?? $original['ai_key']);
        if (!isset($labels[$ai_key])) {
            $ai_key = '';
        }

        return [
            'copyright' => (string) ($filtered['copyright'] ?? $original['copyright']),
            'ai_key'    => $ai_key,
            'ai_label'  => $ai_key === '' ? '' : $labels[$ai_key],
            'icon_id'   => $ai_key === '' ? 0 : (int) Settings::get('seal_' . $ai_key),
        ];
    }

    /** Test seam for the per-request cache. */
    public static function reset_cache(): void
    {
        self::$cache = [];
    }
}
