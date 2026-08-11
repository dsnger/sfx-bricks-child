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
}
