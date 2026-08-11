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

    /** WordPress stores menu and term titles HTML-escaped. */
    private static function plain_text($text): string
    {
        return html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
