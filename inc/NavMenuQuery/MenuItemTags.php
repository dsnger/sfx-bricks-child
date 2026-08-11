<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * The {sfx_menu_item_*} tag vocabulary and its values.
 */
class MenuItemTags
{
    public const PREFIX = 'sfx_menu_item_';

    /** @var list<string> the nine tag keys, in display order */
    public const KEYS = [
        'title',
        'url',
        'id',
        'target',
        'rel',
        'classes',
        'description',
        'is_active',
        'is_ancestor',
    ];

    /** @var array<int, array<string, string>> per-request cache, keyed by item id */
    private static array $cache = [];

    public static function register(): void
    {
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 10, 3);
    }

    /**
     * Find the menu item the current context refers to.
     *
     * The fallback is not optional: on the link path Bricks calls
     * bricks_render_dynamic_data() with its own post id (base.php:2524), so
     * $post is not the menu item and the tag would survive into the href.
     *
     * @param mixed $post
     */
    public static function item_from_context($post): ?\WP_Post
    {
        if ($post instanceof \WP_Post && $post->post_type === 'nav_menu_item') {
            return $post;
        }

        if (class_exists('Bricks\Query') && \Bricks\Query::is_looping()) {
            $loop_object = \Bricks\Query::get_loop_object();

            if ($loop_object instanceof \WP_Post && $loop_object->post_type === 'nav_menu_item') {
                return $loop_object;
            }
        }

        return null;
    }

    /**
     * One menu item value, raw and unescaped.
     *
     * null means "not resolvable, or not one of ours" — callers must leave the
     * tag alone. An empty string means "ours, and empty".
     *
     * @param mixed $post
     */
    public static function value($post, string $key): ?string
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $item = self::item_from_context($post);

        if (!$item instanceof \WP_Post) {
            return null;
        }

        $id = (int) $item->ID;

        if (!isset(self::$cache[$id])) {
            // clone so the loop's $post is not mutated. wp_setup_nav_menu_item
            // resolves ->url from _menu_item_object_id / _menu_item_url and
            // sets ->title to the nav label rather than the page title.
            $prepared = wp_setup_nav_menu_item(clone $item);

            // Active state and classes were set on the ORIGINAL item by
            // _wp_menu_item_classes_by_context() during the query run, so they
            // are read from $item, not from the prepared clone.
            self::$cache[$id] = [
                'title'       => (string) ($prepared->title ?? ''),
                'url'         => (string) ($prepared->url ?? ''),
                'id'          => (string) $id,
                'target'      => (string) ($prepared->target ?? ''),
                'rel'         => (string) ($prepared->xfn ?? ''),
                'classes'     => implode(' ', array_filter((array) ($item->classes ?? []), static fn($c) => $c !== '')),
                'description' => (string) ($prepared->description ?? ''),
                'is_active'   => !empty($item->current) ? '1' : '',
                'is_ancestor' => !empty($item->current_item_ancestor) ? '1' : '',
            ];
        }

        return self::$cache[$id][$key];
    }

    /**
     * Resolve a tag in a single-value context: a Link URL, an image source,
     * a condition operand.
     *
     * This filter is shared by every dynamic-data provider and Bricks seeds it
     * with the tag itself, so the incoming $tag doubles as "nobody has
     * resolved this yet". Returning anything else for a tag we do not own —
     * '', null, a normalised copy — destroys the value for every provider
     * after us.
     *
     * Values come back RAW. The consuming control escapes for its own context;
     * escaping here would double-escape. render_content() is the opposite,
     * because it writes straight into markup.
     *
     * @param mixed $tag  already stripped of its outer braces by Bricks
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_tag($tag, $post, $context)
    {
        // The picker can hand over an array (providers.php:647).
        if (!is_string($tag) || strpos($tag, self::PREFIX) !== 0) {
            return $tag;
        }

        $key = substr($tag, strlen(self::PREFIX));

        // Exact match only. A suffixed variant (Bricks' tag-filter syntax) is
        // unsupported; ignoring the suffix would silently drop what the editor
        // asked for, so it falls through and stays visible instead.
        if (!in_array($key, self::KEYS, true)) {
            return $tag;
        }

        $value = self::value($post, $key);

        return $value === null ? $tag : $value;
    }

    /** Test seam for the per-request static cache. */
    public static function reset_cache(): void
    {
        self::$cache = [];
    }
}
