<?php

declare(strict_types=1);

namespace SFX\TelFormat;

use SFX\TelNormalizer;

/**
 * `@format:tel` for simple Bricks dynamic tags: `tel:{acf_phone @format:tel}` → `tel:+49…`.
 *
 * Always on. Only `{name @format:tel}` (a tag name, the attribute, nothing else) is touched;
 * what that changes and what stays is the spec's compatibility table. ContactInfos handles its own
 * `{contact_info:… @format:tel}`. Spec: docs/superpowers/specs/2026-10-08-tel-format-design.md
 */
class Controller
{
    /** @var array<string, true> Resolutions in progress, keyed by name|post ID|context. */
    private static array $in_flight = [];

    public function __construct()
    {
        // Priority 9: before Bricks' own resolver (10) sees the tag.
        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 9, 3);
        add_filter('bricks/frontend/render_data', [self::class, 'render_data'], 9, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class'                  => self::class,
            'show_in_theme_settings' => false,
            'hook'                   => null,
            'error'                  => 'Missing TelFormat Controller class in theme',
        ];
    }

    /**
     * bricks/frontend/render_data passes whole element output without a context.
     */
    public static function render_data($content, $post = null)
    {
        return self::render_content($content, $post, 'text');
    }

    public static function render_content($content, $post = null, $context = 'text')
    {
        if (!is_string($content) || strpos($content, '@format:tel') === false) {
            return $content;
        }

        $result = preg_replace_callback(
            '/\{([a-zA-Z0-9_-]+)\s*@format:tel\}/',
            static fn(array $m): string => self::resolve($m[1], $post, (string) $context),
            $content
        );

        return $result ?? $content; // PCRE failure: leave the content as it was
    }

    private static function resolve(string $name, $post, string $context): string
    {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        $key = $name . '|' . $post_id . '|' . $context;
        if (isset(self::$in_flight[$key])) {
            return ''; // a provider asked for the same tag again while resolving it
        }

        self::$in_flight[$key] = true;
        try {
            $value = bricks_render_dynamic_data('{' . $name . '}', $post_id, $context);
        } finally {
            unset(self::$in_flight[$key]);
        }

        if (!is_string($value)) {
            return '';
        }

        // Decode first: the checks below must see &#123; and &lt;, and normalize_tel()
        // reads the first ";" as URI parameters (&nbsp; would cut the number).
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/^[\s\p{Z}]*tel:[\s\p{Z}]*/iu', '', $value); // keeps a leading "+"; NBSP too

        // Unresolved tag or markup: digits from a tag name or from href and text would be dialled.
        // Empty, not the tag — render_data would resolve a left-over tag against the page post.
        if (strpos($value, '{') !== false || strpos($value, '<') !== false) {
            return ''; // unresolved-or-markup
        }

        $tel = TelNormalizer::normalize_tel($value);

        return $tel === '' ? '' : esc_html($tel);
    }
}
