<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

/**
 * Puts the organization on the page: its own JSON-LD entry, or merged into Yoast's or Rank Math's
 * entry (spec § "Mode selection", § "Output encoding", § "Fail-safe in extension mode").
 * Each callback checks the mode itself, so the hooks are safe to add on every request.
 */
final class Output
{
    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function register(): void
    {
        if (is_admin()) {
            return;
        }
        add_action('wp_head', [self::class, 'print_own']);
        add_filter('wpseo_schema_organization', [self::class, 'yoast_organization'], 10, 2);
        // After Rank Math's Local SEO (9), before its entity connector (99).
        add_filter('rank_math/json_ld', [self::class, 'rank_math_json_ld'], 20, 2);
    }

    /** Own output (C6): one JSON-LD script; nothing when there is nothing to publish or encoding fails. */
    public static function print_own(): void
    {
        try {
            if (Mode::detect()['mode'] !== 'own') {
                return;
            }
            $entity = GraphBuilder::organization(Selection::resolve(), Settings::get()['type'])['entity'];
            if ($entity === []) {
                return;
            }
            $home = home_url('/');
            $graph = ['@context' => 'https://schema.org', '@type' => $entity['@type'], '@id' => $home . '#organization']
                + $entity + ['url' => $home];
            $logo = self::logo_url();
            if ($logo !== '') {
                $graph['logo'] = $logo;
            }
            $json = wp_json_encode($graph, self::JSON_FLAGS);
            if (!is_string($json)) {
                return;
            }
            echo '<script type="application/ld+json">' . $json . "</script>\n"; // JSON_HEX_TAG: no "<" survives
        } catch (\Throwable $e) {
            return;
        }
    }

    /** Yoast calls this only while it represents a company (Organization::is_needed()). */
    public static function yoast_organization($data, $context = null)
    {
        try {
            if (!is_array($data) || Mode::detect()['mode'] !== 'yoast') {
                return $data;
            }
            $theme = self::theme_entity();
            return $theme === [] ? $data : PluginMerge::merge($data, $theme);
        } catch (\Throwable $e) {
            return $data;
        }
    }

    public static function rank_math_json_ld($data, $jsonld = null)
    {
        try {
            if (!is_array($data) || !is_array($data['publisher'] ?? null) || !isset($data['publisher']['@type'])
                || Mode::detect()['mode'] !== 'rank_math' || !Mode::rank_math_is_company()) {
                return $data;
            }
            $type = $data['publisher']['@type'];
            if ((!is_string($type) && !is_array($type)) || in_array('Person', (array) $type, true)) {
                return $data; // person mode emits ['Person','Organization'] or ['Organization','Person']
            }
            $theme = self::theme_entity();
            if ($theme === []) {
                return $data;
            }
            $data['publisher'] = PluginMerge::merge($data['publisher'], $theme);
            if (isset($theme['address']) && is_array($data['place'] ?? null)) {
                $data['place']['address'] = $theme['address']; // the place's geo stays Rank Math's
            }
            return $data;
        } catch (\Throwable $e) {
            return $data;
        }
    }

    /** C2: the plugin keeps its @type, so the entity is built as a plain Organization. */
    private static function theme_entity(): array
    {
        return GraphBuilder::organization(Selection::resolve(), 'Organization')['entity'];
    }

    /** C8: the custom logo, else the site icon, else none. */
    private static function logo_url(): string
    {
        $logo_id = (int) get_theme_mod('custom_logo');
        $url = $logo_id > 0 ? wp_get_attachment_image_url($logo_id, 'full') : false;
        return is_string($url) && $url !== '' ? $url : (string) get_site_icon_url();
    }
}
