<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * Which menu a loop points at, and what the builder's selects offer.
 *
 * Shared by both sides: the builder calls the option lists and the AJAX
 * endpoint, the render path calls resolve_menu_id(). One resolver, so a
 * builder preview and a frontend render cannot disagree about the menu.
 */
class MenuOptions
{
    /** Stored parent value meaning "children of the enclosing loop's item". */
    public const RELATIVE_PARENT = 'current';

    public static function register(): void
    {
        add_action('wp_ajax_sfx_nav_menu_parent_options', [self::class, 'ajax_parent_options']);
    }

    /**
     * Registered theme locations, slug => label.
     *
     * @return array<string, string>
     */
    public static function locations(): array
    {
        $options = [];

        foreach (get_registered_nav_menus() as $slug => $label) {
            $options[(string) $slug] = self::plain_text($label);
        }

        return $options;
    }

    /**
     * Registered menus, term id (as string) => name.
     *
     * @return array<string, string>
     */
    public static function menus(): array
    {
        $options = [];

        foreach (wp_get_nav_menus() as $menu) {
            $options[(string) $menu->term_id] = self::plain_text($menu->name);
        }

        return $options;
    }

    /**
     * Resolve the menu a loop points at.
     *
     * A non-empty location NEVER falls through to $menu_id: selecting a
     * location says "follow whatever is assigned there", so an unassigned
     * location means no menu, not the id the editor happened to pick before.
     *
     * @param mixed $menu_id
     */
    public static function resolve_menu_id(string $location, $menu_id): int
    {
        if ($location !== '') {
            $locations = get_nav_menu_locations();

            return isset($locations[$location]) ? (int) $locations[$location] : 0;
        }

        return (int) $menu_id;
    }

    /**
     * Options for the "Items below" select.
     *
     * Every item is listed, leaves included: hiding leaves makes an editor
     * who cannot find "Kontakt" conclude the feature is broken, and makes the
     * list change shape as the menu is edited. The trailing (n) says how many
     * direct children a choice has, so (0) explains an empty loop up front.
     *
     * @return array<string, string>
     */
    public static function parent_options(int $menu_id): array
    {
        $options = [self::RELATIVE_PARENT => __('↑ Children of the current item', 'sfxtheme')];

        $items = $menu_id > 0 ? wp_get_nav_menu_items($menu_id) : [];

        if (!$items) {
            return $options;
        }

        $titles  = [];
        $parents = [];
        $counts  = [];

        foreach ($items as $item) {
            $id           = (int) $item->ID;
            $titles[$id]  = self::plain_text($item->title);
            $parents[$id] = (int) $item->menu_item_parent;
        }

        foreach ($parents as $parent) {
            if ($parent) {
                $counts[$parent] = ($counts[$parent] ?? 0) + 1;
            }
        }

        foreach ($titles as $id => $title) {
            $options[(string) $id] = sprintf(
                '%s (%d)',
                self::path_label($id, $titles, $parents),
                $counts[$id] ?? 0
            );
        }

        return $options;
    }

    /**
     * Full path to an item, e.g. "Sehen & Erleben › Sehenswürdigkeiten".
     *
     * Names repeat between levels, so a bare title is ambiguous. The visited
     * guard exists because corrupt postmeta can form a parent cycle — rare,
     * but without it the walk never terminates and the admin screen times out
     * instead of failing diagnosably.
     *
     * @param array<int, string> $titles
     * @param array<int, int>    $parents
     */
    private static function path_label(int $id, array $titles, array $parents): string
    {
        $path    = [];
        $seen    = [];
        $current = $id;

        while ($current && isset($titles[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            array_unshift($path, $titles[$current]);
            $current = (int) ($parents[$current] ?? 0);
        }

        return implode(' › ', $path);
    }

    /** WordPress stores menu and term titles HTML-escaped. */
    private static function plain_text($text): string
    {
        return html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Feed the "Items below" select.
     *
     * No wp_ajax_nopriv_ counterpart: the builder is never available to
     * logged-out users. The capability check is not about secrecy — menu
     * structure is not sensitive — but enumerating it to any authenticated
     * user is a needless disclosure, and the check is one line.
     */
    public static function ajax_parent_options(): void
    {
        if (!check_ajax_referer('bricks-nonce-builder', 'nonce', false)) {
            wp_send_json_error(__('Invalid nonce', 'sfxtheme'));
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'sfxtheme'));
        }

        $location = self::scalar_param('locationId');
        $menu_id  = self::scalar_param('menuId');

        wp_send_json_success(self::parent_options(self::resolve_menu_id($location, $menu_id)));
    }

    /**
     * Read one request parameter safely.
     *
     * Bricks sends {{control}} values as arrays for some control types, so a
     * single unwrap is expected. Anything still non-scalar after that is
     * malformed — casting it would emit a notice and produce the literal
     * "Array". wp_unslash() must precede sanitize_text_field(), or a value
     * containing an escaped character is sanitised while still slashed and
     * never matches a real key.
     */
    private static function scalar_param(string $key): string
    {
        $value = $_GET[$key] ?? '';

        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_scalar($value)) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $value));
    }
}
