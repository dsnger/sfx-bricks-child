<?php

declare(strict_types=1);

namespace SFX\EditorProse;

/**
 * Builds what the editor script puts into the canvas. Bricks does the real work
 * (compiling the classes, scoping to the canvas); this only drives it the way
 * Bricks drives it for component classes (Block_Editor::generate_gutenberg_global_classes_css).
 */
class Payload
{
    /** The Assets statics Bricks saves around its own editor compile. */
    private const ASSET_STATICS = [
        'global_classes_elements',
        'inline_css',
        'inline_css_breakpoints',
        'unique_inline_css',
        'inline_css_dynamic_data',
        'current_generating_element',
    ];

    /** WordPress' classic editor stylesheet gives every block 28px top/bottom margins the frontend never has
     *  (`html :where(.wp-block)`, edit-post/classic.css). This repeats that exact selector — same specificity,
     *  later in the canvas — so only that rule is reverted to the layered cascade (Bricks' defaults); every
     *  more specific rule (block-library styles such as `.wp-block-image`, contextual spacing, prose classes,
     *  the title rule) still wins. It is injected into the canvas document only, never the admin page. */
    public const BLOCK_MARGIN_RESET = "html :where(.wp-block) { margin-top: revert-layer; margin-bottom: revert-layer; }\n";

    public static function build(array $o, string $post_type, string $baseline_url = ''): array
    {
        $classes = array_merge(['brxe-' . $o['element']], $o['classes']);
        $links = [];
        if ($o['baseline']) {
            $classes[] = 'sfx-prose';
            if ($baseline_url !== '') {
                $links[] = $baseline_url;
            }
        }

        $css = '';
        try {
            $compiled = $o['classes'] === [] ? '' : self::compile($o['classes'], $o['element']);
            if ($compiled !== '') {
                $fonts = self::font_links($compiled);
                $css = (string) \Bricks\Integrations\Block_Editor::scope_css_for_gutenberg($compiled);
                $links = array_merge($links, $fonts);
            }
        } catch (\Throwable $e) {
            $css = '';
        }

        $css .= self::BLOCK_MARGIN_RESET;
        $css .= self::title_rule($o['title_gap']);
        $css = (string) apply_filters('sfx_editor_prose_css', $css, $post_type);

        return ['classes' => $classes, 'css' => $css, 'links' => array_values(array_unique($links))];
    }

    public static function compile(array $names, string $element): string
    {
        if (!self::bricks_api_available()) {
            return '';
        }

        $map = [];
        $ids = self::ids_by_name();
        foreach ($names as $name) {
            if (isset($ids[$name])) {
                $map[$ids[$name]] = [$element];
            }
        }
        if ($map === []) {
            return '';
        }

        $saved = [];
        foreach (self::ASSET_STATICS as $prop) {
            $saved[$prop] = \Bricks\Assets::$$prop;
        }

        try {
            \Bricks\Assets::$global_classes_elements = $map;
            return (string) \Bricks\Assets::generate_global_classes('sfx_editor_prose');
        } finally {
            foreach ($saved as $prop => $value) {
                \Bricks\Assets::$$prop = $value;
            }
        }
    }

    /** Stylesheet hrefs Bricks would load for fonts used in $css; preconnect links ignored. */
    public static function font_links(string $css): array
    {
        $html = (string) \Bricks\Assets::load_webfonts($css, true);
        preg_match_all('/<link\b[^>]*>/i', $html, $tags);

        $out = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel=["\']stylesheet["\']/i', $tag) && preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href)) {
                $out[] = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return $out;
    }

    public static function title_rule(string $gap): string
    {
        return $gap === '' ? '' : ".editor-styles-wrapper .editor-post-title { margin-block-end: {$gap}; }\n";
    }

    /** Configured names with no Bricks global class of that name (all of them when Bricks is absent). */
    public static function missing_classes(array $names): array
    {
        // Name lookup needs only Bricks' class data, not the whole compiler API.
        $ids = class_exists('Bricks\Database') && property_exists('Bricks\Database', 'global_data') ? self::ids_by_name() : [];

        return array_values(array_filter($names, static fn($n) => !isset($ids[$n])));
    }

    private static function bricks_api_available(): bool
    {
        if (!class_exists('Bricks\Database') || !class_exists('Bricks\Assets')
            || !class_exists('Bricks\Integrations\Block_Editor')
            || !method_exists('Bricks\Assets', 'generate_global_classes')
            || !method_exists('Bricks\Assets', 'load_webfonts')
            || !method_exists('Bricks\Integrations\Block_Editor', 'scope_css_for_gutenberg')
            || !property_exists('Bricks\Database', 'global_data')) {
            return false;
        }
        foreach (self::ASSET_STATICS as $prop) {
            if (!property_exists('Bricks\Assets', $prop)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,string> class name => id */
    private static function ids_by_name(): array
    {
        $out = [];
        foreach ((array) (\Bricks\Database::$global_data['globalClasses'] ?? []) as $class) {
            if (is_array($class) && is_string($class['name'] ?? null) && !empty($class['id']) && !isset($out[$class['name']])) {
                $out[$class['name']] = (string) $class['id'];
            }
        }

        return $out;
    }
}
