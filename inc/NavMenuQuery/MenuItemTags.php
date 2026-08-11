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
        add_filter('bricks/dynamic_tags_list', [self::class, 'add_tags_to_builder']);

        // Priority 20 is load-bearing, not a preference. See render_tag().
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 20, 3);

        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 10, 3);
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
     * Resolve a tag in a single-value context: an image or background source,
     * a lightbox image, the Code element's useDynamicData, SVG, and the
     * builder's dynamic-data preview.
     *
     * (A Link's href and a condition operand do NOT arrive here — both go
     * through bricks_render_dynamic_data() (functions.php:286) into
     * Providers::render_content(), which render_content() below serves.)
     *
     * This filter is shared by every dynamic-data provider and Bricks seeds it
     * with the tag itself, so the incoming $tag doubles as "nobody has
     * resolved this yet". Returning anything else for a tag we do not own —
     * '', null, a normalised copy — destroys the value for every provider
     * after us.
     *
     * Priority 20, and brace tolerance, are both mandatory — neither works
     * alone:
     *
     * - Bricks itself occupies priority 10 here. Providers::register() runs at
     *   include time (init.php:165, reached from bricks/functions.php:204),
     *   which is before our after_setup_theme hook can fire, so a priority-10
     *   registration of ours is always the SECOND one at that priority and
     *   still runs after Bricks.
     * - Bricks' handler, Providers::get_tag_value(), does not know our tags —
     *   they are never in Providers::$tags — so it hands on
     *   '{' . $original_tag . '}' (providers.php:562). Priority 20 without
     *   brace tolerance therefore receives '{sfx_menu_item_title}', fails the
     *   prefix test at offset 0, and the tag survives into the output.
     * - Brace tolerance without priority 20 is worse than useless: if the
     *   ordering ever flipped we would resolve first and hand Bricks a plain
     *   value, which it would not recognise either and would re-wrap as
     *   '{Kunst & Kultur}'.
     *
     * At priority 20 with the braces stripped before matching, the two
     * cooperate: Bricks passes our tag through untouched-but-wrapped, and we
     * unwrap, resolve, and return the value Bricks will not touch again.
     *
     * The bare form is still accepted, so the contract holds whether or not
     * another callback has re-wrapped the tag.
     *
     * Values come back RAW. The consuming control escapes for its own context;
     * escaping here would double-escape. render_content() is the opposite,
     * because it writes straight into markup.
     *
     * @param mixed $tag  the tag, with or without a surrounding brace pair
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_tag($tag, $post, $context)
    {
        // The picker can hand over an array (providers.php:647).
        if (!is_string($tag)) {
            return $tag;
        }

        $needle = $tag;

        // One pair only: Bricks strips just the outermost pair too
        // (providers.php:651-654).
        if (strlen($needle) > 1 && $needle[0] === '{' && substr($needle, -1) === '}') {
            $needle = substr($needle, 1, -1);
        }

        if (strpos($needle, self::PREFIX) !== 0) {
            return $tag;
        }

        $key = substr($needle, strlen(self::PREFIX));

        // Exact match only. A suffixed variant (Bricks' tag-filter syntax) is
        // unsupported; ignoring the suffix would silently drop what the editor
        // asked for, so it falls through and stays visible instead.
        if (!in_array($key, self::KEYS, true)) {
            return $tag;
        }

        $value = self::value($post, $key);

        // Every miss above and here returns the ORIGINAL $tag, braces and all,
        // never $needle: whatever the previous callback produced is what the
        // next one must see.
        return $value === null ? $tag : $value;
    }

    /** Test seam for the per-request static cache. */
    public static function reset_cache(): void
    {
        self::$cache = [];
    }

    /**
     * Human labels for the picker, keyed by tag key.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'title'       => __('Title', 'sfxtheme'),
            'url'         => __('URL', 'sfxtheme'),
            'id'          => __('ID', 'sfxtheme'),
            'target'      => __('Link target', 'sfxtheme'),
            'rel'         => __('Link relation', 'sfxtheme'),
            'classes'     => __('CSS classes', 'sfxtheme'),
            'description' => __('Description', 'sfxtheme'),
            'is_active'   => __('Is current page', 'sfxtheme'),
            'is_ancestor' => __('Is ancestor of current page', 'sfxtheme'),
        ];
    }

    /**
     * Put the nine tags in the builder's tag picker.
     *
     * Presentation only — bricks/dynamic_tags_list is builder-facing
     * (providers.php:797) and does not feed the content parser. Resolution
     * still comes from render_tag() and render_content().
     *
     * @param array<int, array<string, string>> $tags
     * @return array<int, array<string, string>>
     */
    public static function add_tags_to_builder(array $tags): array
    {
        $group = __('Menu item', 'sfxtheme');

        foreach (self::labels() as $key => $label) {
            $tags[] = [
                'name'  => '{' . self::PREFIX . $key . '}',
                'label' => $label,
                'group' => $group,
            ];
        }

        return $tags;
    }

    /**
     * Resolve tags inside text content.
     *
     * Not a duplicate of render_tag(). Bricks' content parser only resolves
     * tags present in Providers::$tags, which is built from registered
     * provider objects (providers.php:222, 327) — bricks/dynamic_tags_list
     * does not feed it. So the parser will never resolve these tags, but this
     * filter fires regardless (providers.php:368) and does the work itself.
     *
     * Values ARE escaped here, unlike render_tag(), because this writes
     * straight into markup.
     *
     * @param mixed $content
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_content($content, $post, $context)
    {
        if (!is_string($content) || strpos($content, '{' . self::PREFIX) === false) {
            return $content;
        }

        if (self::value($post, 'id') === null) {
            return $content;
        }

        $replacements = [];

        foreach (self::KEYS as $key) {
            $value = (string) self::value($post, $key);

            // id / is_active / is_ancestor are generated and structurally
            // incapable of carrying HTML-special characters — digits and
            // '1'/'' respectively — so leaving them raw is a statement of
            // intent, not a behavioural guarantee. Escaping them would be a
            // no-op, which is why no test asserts the difference: such a
            // test could only be a tautology.
            $replacements['{' . self::PREFIX . $key . '}'] = match ($key) {
                'url' => esc_url($value),
                'id', 'is_active', 'is_ancestor' => $value,
                default => esc_html($value),
            };
        }

        return strtr($content, $replacements);
    }
}
