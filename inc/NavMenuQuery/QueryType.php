<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * The "Menu Items" query type: its registration, its element controls, and
 * running it.
 */
class QueryType
{
    /** The value stored in a Bricks query's objectType. */
    public const OBJECT_TYPE = 'sfx_nav_menu';

    private const CONTROL_KEYS = ['sfxNavMenuLocation', 'sfxNavMenuId', 'sfxNavMenuParent'];

    public static function register(): void
    {
        add_filter('bricks/setup/control_options', [self::class, 'add_query_type']);
        add_action('bricks/load_elements/before', [self::class, 'register_element_controls']);
    }

    /**
     * Offer the query type in the builder's Query → Type dropdown.
     *
     * Merged into, never replaced: Bricks' own five types and any other
     * plugin's additions have to survive.
     *
     * @param array<string, mixed> $control_options
     * @return array<string, mixed>
     */
    public static function add_query_type(array $control_options): array
    {
        $control_options['queryTypes'][self::OBJECT_TYPE] = esc_html__('Menu Items', 'sfxtheme');

        return $control_options;
    }

    /**
     * Attach the controls filter to every registered element.
     *
     * Runs on bricks/load_elements/before, which fires at the start of each
     * Elements::load_elements() call — so the registry is complete, including
     * elements registered after init. The guard is because that action can
     * fire twice per request (builder-permissions.php:28 loads elements again
     * for the Bricks Settings page); without it every element would get a
     * duplicate filter and build its controls twice over.
     */
    public static function register_element_controls(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        foreach (array_keys(\Bricks\Elements::$elements) as $name) {
            add_filter("bricks/elements/{$name}/controls", [self::class, 'add_element_controls']);
        }
    }

    /**
     * Add the three controls to any element that supports a query loop.
     *
     * hasLoop is the marker: bricks/elements/{name}/controls fires after
     * set_controls(), so a loop-capable element already carries it. This
     * covers all seven Bricks elements plus any third-party element that
     * opts into the loop builder.
     *
     * @param array<string, mixed> $controls
     * @return array<string, mixed>
     */
    public static function add_element_controls(array $controls): array
    {
        if (!isset($controls['hasLoop'])) {
            return $controls;
        }

        $controls['sfxNavMenuLocation'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Menu location', 'sfxtheme'),
            'type'        => 'select',
            'options'     => MenuOptions::locations(),
            'placeholder' => esc_html__('Select a location', 'sfxtheme'),
            'description' => esc_html__('Follows whichever menu is assigned to this location.', 'sfxtheme'),
            'required'    => ['query.objectType', '=', self::OBJECT_TYPE],
        ];

        $controls['sfxNavMenuId'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Menu', 'sfxtheme'),
            'type'        => 'select',
            'options'     => MenuOptions::menus(),
            'description' => esc_html__('Only used when no location is selected.', 'sfxtheme'),
            'required'    => [
                ['query.objectType', '=', self::OBJECT_TYPE],
                ['sfxNavMenuLocation', '=', ''],
            ],
        ];

        $controls['sfxNavMenuParent'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Items below', 'sfxtheme'),
            'type'        => 'select',
            'optionsAjax' => [
                'action'     => 'sfx_nav_menu_parent_options',
                'locationId' => '{{sfxNavMenuLocation}}',
                'menuId'     => '{{sfxNavMenuId}}',
            ],
            'searchable'  => true,
            'placeholder' => esc_html__('Top level', 'sfxtheme'),
            'required'    => ['query.objectType', '=', self::OBJECT_TYPE],
        ];

        // Map puts its query UI in the 'addresses' group (map.php:250). Ours
        // has to follow, or it renders in a different panel from the control
        // it modifies. Elements that pass no group leave this null.
        $group = $controls['query']['group'] ?? null;

        if ($group !== null) {
            foreach (self::CONTROL_KEYS as $key) {
                $controls[$key]['group'] = $group;
            }
        }

        return $controls;
    }
}
