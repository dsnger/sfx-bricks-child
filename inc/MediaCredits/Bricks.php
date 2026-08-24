<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Bricks integration.
 *
 * Three mechanisms, only the last of which touches rendered HTML:
 *   1. tag substitution inside the Image element's own captionCustom setting
 *   2. caption auto-output, written as a setting
 *   3. overlay auto-output, injected into an existing wrapper
 */
class Bricks
{
    public const PREFIX = 'sfx_media_';

    /** @var list<string> */
    public const KEYS = ['copyright', 'ai_label', 'credit'];

    public const MARKER_CLASS = 'sfx-credit';

    public static function register(): void
    {
        add_filter('bricks/element/settings', [self::class, 'element_settings'], 10, 2);
        add_filter('bricks/frontend/render_element', [self::class, 'render_element'], 10, 2);
        add_filter('wp_get_attachment_image_attributes', [self::class, 'image_attributes'], 10, 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_overlay_styles'], 20);

        add_filter('bricks/dynamic_tags_list', [self::class, 'add_tags_to_builder']);

        // Priority 20 is load-bearing, not a preference. Bricks occupies
        // priority 10 and registers at include time, before our
        // after_setup_theme hook can fire — so a priority-10 registration of
        // ours always runs second at that priority anyway. Its handler does
        // not know our tags and hands them on re-wrapped as '{tag}'
        // (providers.php:562), which is why the parser below tolerates braces.
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 20, 3);

        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 10, 3);
    }

    /**
     * @param array<string, mixed> $tags
     * @return array<string, mixed>
     */
    public static function add_tags_to_builder(array $tags): array
    {
        $group  = __('Media Credits', 'sfxtheme');
        $labels = [
            'copyright' => __('Copyright notice', 'sfxtheme'),
            'ai_label'  => __('AI marking', 'sfxtheme'),
            'credit'    => __('Credit line', 'sfxtheme'),
        ];

        foreach (self::KEYS as $key) {
            $tags[] = [
                'name'  => '{' . self::PREFIX . $key . '}',
                'label' => $labels[$key],
                'group' => $group,
            ];
        }

        return $tags;
    }

    /**
     * Resolve a tag in a single-value context.
     *
     * Bricks seeds this filter with the tag itself, so the incoming $tag
     * doubles as "nobody has resolved this yet". Anything we return for a tag
     * we do not own — '', null, a normalised copy — destroys the value for
     * every provider after us, so every miss returns the ORIGINAL $tag.
     *
     * @param mixed $tag
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_tag($tag, $post, $context)
    {
        if (!is_string($tag)) {
            return $tag;
        }

        $needle = $tag;

        // One pair only — Bricks strips just the outermost pair too.
        if (strlen($needle) > 1 && $needle[0] === '{' && substr($needle, -1) === '}') {
            $needle = substr($needle, 1, -1);
        }

        $parsed = self::parse($needle);

        if ($parsed === null) {
            return $tag;
        }

        $value = self::raw_value($post, $parsed['key'], $parsed['id']);

        return $value === null ? $tag : $value;
    }

    /**
     * Resolve every one of our tags inside a block of content.
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

        $pattern = '/\{' . preg_quote(self::PREFIX, '/') . '(' . implode('|', self::KEYS) . ')(?::(\d+))?\}/';

        return preg_replace_callback(
            $pattern,
            static function (array $m) use ($post): string {
                $value = self::raw_value($post, $m[1], isset($m[2]) ? (int) $m[2] : 0);

                if ($value === null) {
                    return $m[0];
                }

                // The composed line is HTML by contract; the two text values
                // are not, and this context writes straight into markup.
                return $m[1] === 'credit' ? $value : esc_html($value);
            },
            $content
        );
    }

    /**
     * One tag's value, or null for "not one of ours".
     *
     * Text values come back raw for their consuming control to escape, as in
     * NavMenuQuery — but always brace-escaped, because that is not an escaping
     * choice, it is the boundary that stops stored text becoming a Bricks tag.
     */
    public static function raw_value($post, string $key, int $explicit_id = 0): ?string
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $id = $explicit_id > 0 ? $explicit_id : self::resolve_id($post);

        if ($id <= 0) {
            return '';
        }

        $credit = Credit::for($id);

        switch ($key) {
            case 'copyright':
                return Credit::escape_braces($credit['copyright']);
            case 'ai_label':
                return Credit::escape_braces($credit['ai_label']);
            case 'credit':
                return $credit['line'];
        }

        return null;
    }

    /**
     * Attachment context, first hit wins: Bricks loop object, the global
     * $post, then the current post's featured image.
     *
     * Inside an image element the tag has already been substituted by
     * element_settings(), so this list is never consulted there.
     */
    public static function resolve_id($post): int
    {
        if (class_exists('Bricks\Query') && \Bricks\Query::is_looping()) {
            $loop_object = \Bricks\Query::get_loop_object();

            if ($loop_object instanceof \WP_Post && $loop_object->post_type === 'attachment') {
                return (int) $loop_object->ID;
            }
        }

        if ($post instanceof \WP_Post && $post->post_type === 'attachment') {
            return (int) $post->ID;
        }

        $post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();

        return $post_id > 0 ? (int) get_post_thumbnail_id($post_id) : 0;
    }

    /**
     * @return array{key: string, id: int}|null
     */
    private static function parse(string $needle): ?array
    {
        if (strpos($needle, self::PREFIX) !== 0) {
            return null;
        }

        $rest = substr($needle, strlen(self::PREFIX));
        $id   = 0;

        if (strpos($rest, ':') !== false) {
            [$rest, $suffix] = explode(':', $rest, 2);

            if ($suffix === '' || !ctype_digit($suffix)) {
                return null;
            }

            $id = (int) $suffix;
        }

        return in_array($rest, self::KEYS, true) ? ['key' => $rest, 'id' => $id] : null;
    }

    /**
     * Mechanisms 1 and 2, in a fixed order because both write captionCustom.
     *
     * This filter fires inside Element::init() immediately before render()
     * (base.php:2948), which is the only moment where the element and our tag
     * are in the same room: captionCustom is never passed through
     * render_dynamic_data() (image.php:805-806), so a tag left in it survives
     * into the page and is resolved much later, against the wrong context.
     *
     * @param mixed $settings
     * @param mixed $element
     * @return mixed
     */
    public static function element_settings($settings, $element)
    {
        if (!is_array($settings) || !is_object($element) || ($element->name ?? '') !== 'image') {
            return $settings;
        }

        $id = self::image_id_from_element($element, $settings);

        if ($id <= 0) {
            return $settings;
        }

        // 1 · substitute our tags where the editor placed them
        $caption_custom = (string) ($settings['captionCustom'] ?? '');

        if (strpos($caption_custom, '{' . self::PREFIX) !== false) {
            $settings['captionCustom'] = self::substitute($caption_custom, $id);
        }

        if (self::has_no_credit_class($settings)) {
            return $settings;
        }

        $mode = (string) Settings::get('output_mode');

        if ($mode === 'overlay') {
            // The overlay needs a wrapper to attach to. Setting the key is
            // what flips $has_html_tag (image.php:822); the tag NAME comes
            // from the constructor and is already 'figure' for this element
            // (image.php:10), so an element that chose 'div' keeps it.
            if (Settings::get('force_wrapper') && !isset($settings['tag']) && Credit::for($id)['line'] !== '') {
                $settings['tag'] = 'figure';
            }

            return $settings;
        }

        if ($mode !== 'caption') {
            return $settings;
        }

        // 2 · caption auto-output, written as a setting rather than injected
        $default_type = is_array($element->theme_styles ?? null) && !empty($element->theme_styles['caption'])
            ? (string) $element->theme_styles['caption']
            : 'attachment';

        $effective = self::effective_caption($settings, $id, $default_type);

        // Tested against the EFFECTIVE caption on purpose: a marker sitting in
        // a captionCustom that Bricks is not going to render would otherwise
        // suppress the disclosure entirely.
        if (self::has_marker($effective)) {
            return $settings;
        }

        $line = Credit::for($id)['line'];

        if ($line === '') {
            return $settings;
        }

        $credit = '<span class="' . self::MARKER_CLASS . '">' . $line . '</span>';

        $settings['caption']       = 'custom';
        $settings['captionCustom'] = $effective === '' ? $credit : $effective . '<br>' . $credit;

        return $settings;
    }

    /**
     * Replace our tags in one string, each wrapped in the marker span.
     */
    public static function substitute(string $text, int $id): string
    {
        $pattern = '/\{' . preg_quote(self::PREFIX, '/') . '(' . implode('|', self::KEYS) . ')(?::(\d+))?\}/';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($id): string {
                $target = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $id;
                $credit = Credit::for($target);

                if ($m[1] === 'credit') {
                    $value = $credit['line'];
                } else {
                    $raw   = $m[1] === 'copyright' ? $credit['copyright'] : $credit['ai_label'];
                    $value = Credit::escape_braces(esc_html($raw));
                }

                if ($value === '') {
                    return '';
                }

                return '<span class="' . self::MARKER_CLASS . '">' . $value . '</span>';
            },
            $text
        );
    }

    /**
     * What Bricks will actually render as the caption.
     *
     * Reproduces image.php:794-810 branch for branch. The third branch is the
     * subtle one: type 'custom' with an EMPTY field still falls through to the
     * attachment caption, so treating "custom" as "captionCustom" would let us
     * overwrite a caption the editor never touched.
     *
     * @param array<string, mixed> $settings
     */
    public static function effective_caption(array $settings, int $image_id, string $default_type = 'attachment'): string
    {
        $type = isset($settings['caption']) ? (string) $settings['caption'] : $default_type;

        if ($type === 'none') {
            return '';
        }

        // empty() first, trim() after — Bricks' own order (image.php:805-806).
        // The difference is not academic: '0' is empty to PHP and therefore
        // falls through to the attachment caption, and a whitespace-only
        // custom caption is NOT empty and therefore trims to ''. Testing
        // trim() !== '' instead would get both backwards.
        if ($type === 'custom' && !empty($settings['captionCustom'])) {
            return trim((string) $settings['captionCustom']);
        }

        if ($image_id <= 0) {
            return '';
        }

        $attachment = get_post($image_id);

        return $attachment ? (string) ($attachment->post_excerpt ?? '') : '';
    }

    /**
     * Is our marker class present as a class-attribute token?
     *
     * A bare substring test would also fire on the words in prose and on
     * `sfx-credit-note`, and suppressing a disclosure by accident is the
     * expensive direction of this mistake.
     */
    public static function has_marker(string $html): bool
    {
        $class = preg_quote(self::MARKER_CLASS, '/');

        return preg_match('/class\s*=\s*(["\'])(?:[^"\']*\s)?' . $class . '(?:\s[^"\']*)?\1/', $html) === 1;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function has_no_credit_class(array $settings): bool
    {
        $classes = trim((string) ($settings['_cssClasses'] ?? ''));

        if ($classes === '') {
            return false;
        }

        $tokens = preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array('no-credit', $tokens, true);
    }

    /**
     * The element's attachment id, through Bricks' own resolver so a dynamic
     * image source is honoured. A provider returning a URL rather than an id
     * leaves id at 0 (image.php:738-760) — those images get no credit, which
     * the spec accepts.
     *
     * @param mixed $element
     * @param array<string, mixed> $settings
     */
    public static function image_id_from_element($element, array $settings): int
    {
        if (!is_object($element) || !method_exists($element, 'get_normalized_image_settings')) {
            return 0;
        }

        $image = $element->get_normalized_image_settings($settings);

        return is_array($image) && !empty($image['id']) ? (int) $image['id'] : 0;
    }

    /**
     * @param mixed $html
     * @param mixed $element
     * @return mixed
     */
    public static function render_element($html, $element)
    {
        return $html; // Task 8 replaces this body.
    }

    /**
     * @param mixed $attr
     * @param mixed $attachment
     * @return mixed
     */
    public static function image_attributes($attr, $attachment)
    {
        return $attr; // Task 8 replaces this body.
    }

    /**
     * The overlay stylesheet, enqueued only in overlay mode.
     */
    public static function enqueue_overlay_styles(): void
    {
        // Task 8 replaces this body.
    }
}
