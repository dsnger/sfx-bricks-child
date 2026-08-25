<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Everything the module does inside wp-admin: the two attachment fields, the
 * list column, the list filter and the one-shot IPTC prefill.
 */
class MediaLibrary
{
    public const FIELD_COPYRIGHT = 'sfx_media_copyright';
    public const FIELD_AI        = 'sfx_media_ai';
    public const FILTER_PARAM    = 'sfx_media_credit_filter';

    public static function register(): void
    {
        add_action('init', [self::class, 'register_meta']);
        add_filter('attachment_fields_to_edit', [self::class, 'fields'], 10, 2);
        add_filter('attachment_fields_to_save', [self::class, 'save'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [self::class, 'prefill_iptc'], 10, 3);
        add_filter('manage_media_columns', [self::class, 'columns']);
        add_action('manage_media_custom_column', [self::class, 'column'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'filter_dropdown']);
        add_action('pre_get_posts', [self::class, 'filter_query']);

        // add_meta_boxes_attachment, not the generic add_meta_boxes: it only
        // ever fires for this one post type, so the callback needs no post
        // type check of its own and never runs on unrelated screens.
        add_action('add_meta_boxes_attachment', [self::class, 'register_meta_box']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_meta_box_style']);
    }

    /**
     * The meta box's two layout rules, as a stylesheet rather than an inline
     * <style> block. Scoped to the attachment edit screen, the only screen
     * add_meta_boxes_attachment draws the box on.
     */
    public static function enqueue_meta_box_style(string $hook): void
    {
        if ($hook !== 'post.php') {
            return;
        }

        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== 'attachment') {
            return;
        }

        $path = get_stylesheet_directory() . '/inc/MediaCredits/assets/media-credits-admin.css';

        if (!file_exists($path)) {
            return;
        }

        wp_enqueue_style(
            'sfx-media-credits-attachment',
            get_stylesheet_directory_uri() . '/inc/MediaCredits/assets/media-credits-admin.css',
            [],
            (string) filemtime($path)
        );
    }

    /**
     * Underscore-prefixed so the keys stay out of the Custom Fields box, and
     * out of REST: this is not a public API, it is two fields and a marker.
     */
    public static function register_meta(): void
    {
        register_meta('post', Credit::META_COPYRIGHT, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_meta('post', Credit::META_AI, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => [self::class, 'sanitize_ai_key'],
        ]);

        register_meta('post', Credit::META_IPTC_MARKER, [
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => false,
        ]);
    }

    /**
     * Both fields, for every attachment type. The AI Act covers video and
     * audio too, and a MIME check would only add a way to be wrong.
     *
     * Suppressed on the attachment edit screen (post.php): the sidebar meta
     * box (see register_meta_box()/render_meta_box()) renders the same two
     * controls there, and rendering both here and there would show every
     * field twice. The media modal is unaffected: its request is
     * admin-ajax.php, never post.php.
     *
     * Note $GLOBALS['pagenow'], not get_current_screen()->id: WP_Screen
     * strips the .php suffix (see the note on filter_query() below), so a
     * screen-id comparison against 'post.php' would never match.
     *
     * @param array<string, mixed> $form_fields
     * @param mixed $post
     * @return array<string, mixed>
     */
    public static function fields(array $form_fields, $post): array
    {
        if (($GLOBALS['pagenow'] ?? '') === 'post.php') {
            return $form_fields;
        }

        $id       = isset($post->ID) ? (int) $post->ID : 0;
        $controls = self::field_controls($id);

        $form_fields[self::FIELD_COPYRIGHT] = [
            'label' => __('Copyright', 'sfxtheme'),
            'input' => 'html',
            'html'  => $controls['copyright'],
            'helps' => __('Free text, e.g. © Photographer or an agency notice.', 'sfxtheme'),
        ];

        $form_fields[self::FIELD_AI] = [
            'label' => __('AI marking', 'sfxtheme'),
            'input' => 'html',
            'html'  => $controls['ai'],
            'helps' => __('How this file was produced or altered.', 'sfxtheme'),
        ];

        return $form_fields;
    }

    /**
     * The Copyright input and AI select markup, built once and shared by
     * fields() (the media modal's compat fields) and render_meta_box() (the
     * attachment edit screen's sidebar box). Both keep the exact input names
     * — attachments[<ID>][sfx_media_copyright] and
     * attachments[<ID>][sfx_media_ai] — that save() already reads out of
     * $_POST['attachments'][<ID>] via the attachment_fields_to_save filter,
     * so neither caller needs a save path of its own.
     *
     * @return array{copyright: string, ai: string}
     */
    private static function field_controls(int $id): array
    {
        $copyright = sprintf(
            '<input type="text" class="text" name="attachments[%1$d][%2$s]" id="attachments-%1$d-%2$s" value="%3$s">',
            $id,
            esc_attr(self::FIELD_COPYRIGHT),
            esc_attr((string) get_post_meta($id, Credit::META_COPYRIGHT, true))
        );

        $current = (string) get_post_meta($id, Credit::META_AI, true);
        $options = '<option value="">' . esc_html__('No marking', 'sfxtheme') . '</option>';

        foreach (Settings::get_labels() as $slug => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($slug),
                selected($current, $slug, false),
                esc_html($label)
            );
        }

        $ai = sprintf(
            '<select name="attachments[%1$d][%2$s]" id="attachments-%1$d-%2$s">%3$s</select>',
            $id,
            esc_attr(self::FIELD_AI),
            $options
        );

        return ['copyright' => $copyright, 'ai' => $ai];
    }

    /**
     * The sidebar meta box that replaces the two compat fields on the
     * attachment edit screen. Registered from register() via
     * add_meta_boxes_attachment.
     */
    public static function register_meta_box(): void
    {
        add_meta_box(
            'sfx_media_credits',
            __('Media Credits', 'sfxtheme'),
            [self::class, 'render_meta_box'],
            'attachment',
            'side'
        );
    }

    /**
     * Renders the same two controls as fields(), via the same
     * field_controls() helper, under the same input names — so this box has
     * nothing of its own to save. WordPress' core update handler for the
     * attachment edit screen collects $_POST['attachments'][<ID>] and runs
     * it through attachment_fields_to_save (save() above) exactly as it
     * does today for the compat fields; this box only changes where the
     * inputs are drawn, not how they are persisted.
     *
     * No wp_nonce_field() here: a nonce protects a save handler that reads
     * it, and this box registers none — there is nothing of ours for a
     * nonce to guard. The house pattern in ContactInfos/PostType.php and
     * SocialMediaAccounts/PostType.php nonces because those boxes each have
     * their own save_post handler that checks it; adding one here with no
     * corresponding check would be inert markup, not a safeguard.
     *
     * @param \WP_Post $post
     */
    public static function render_meta_box($post): void
    {
        $id       = isset($post->ID) ? (int) $post->ID : 0;
        $controls = self::field_controls($id);
        ?>
        <p>
            <label for="attachments-<?php echo esc_attr((string) $id); ?>-<?php echo esc_attr(self::FIELD_COPYRIGHT); ?>">
                <strong><?php esc_html_e('Copyright', 'sfxtheme'); ?></strong>
            </label>
            <?php echo $controls['copyright']; ?>
            <span class="description"><?php esc_html_e('Free text, e.g. © Photographer or an agency notice.', 'sfxtheme'); ?></span>
        </p>
        <p>
            <label for="attachments-<?php echo esc_attr((string) $id); ?>-<?php echo esc_attr(self::FIELD_AI); ?>">
                <strong><?php esc_html_e('AI marking', 'sfxtheme'); ?></strong>
            </label>
            <?php echo $controls['ai']; ?>
            <span class="description"><?php esc_html_e('How this file was produced or altered.', 'sfxtheme'); ?></span>
        </p>
        <?php
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    public static function save(array $post, array $attachment): array
    {
        $id = isset($post['ID']) ? (int) $post['ID'] : 0;

        if ($id <= 0) {
            return $post;
        }

        $touched = false;

        if (array_key_exists(self::FIELD_COPYRIGHT, $attachment)) {
            update_post_meta(
                $id,
                Credit::META_COPYRIGHT,
                sanitize_text_field((string) $attachment[self::FIELD_COPYRIGHT])
            );
            $touched = true;
        }

        if (array_key_exists(self::FIELD_AI, $attachment)) {
            update_post_meta(
                $id,
                Credit::META_AI,
                self::sanitize_ai_key((string) $attachment[self::FIELD_AI])
            );
            $touched = true;
        }

        // "At least one field was present", not "both were written" — save()
        // only ever writes a field the submitted payload actually contains,
        // so a partial edit (only copyright, only the AI select) must still
        // notify, exactly once, not zero times and not once per field.
        if ($touched) {
            self::notify_saved($id, 'save');
        }

        return $post;
    }

    /**
     * Re-read both fields' current values and fire sfx_media_credits_saved.
     *
     * Shared by save() (context 'save', at least one field present in the
     * submitted payload) and prefill_iptc() (context 'iptc', immediately
     * after the one write that path can ever make) so the action's contract
     * — and its docblock — lives in exactly one place.
     *
     * This is the seam the parent spec deliberately left open. That spec
     * lists page-cache invalidation in its Out of Scope table as "add when a
     * site actually runs a page cache and a stale disclosure is observed".
     * This action is how a site does that without the module knowing
     * anything about caches.
     *
     * Credit::reset_cache() below is required, not defensive: Credit::for()
     * memoises per request, and neither save() nor prefill_iptc() otherwise
     * invalidates it. A listener doing the obvious thing — calling
     * Credit::for($attachment_id) to see what changed — would otherwise be
     * handed the pre-save value. Clearing the whole per-request cache is
     * the accepted cost: saves are rare and the cache is per request, so a
     * per-id invalidation would be a second API for no real gain.
     */
    private static function notify_saved(int $id, string $context): void
    {
        $copyright = (string) get_post_meta($id, Credit::META_COPYRIGHT, true);
        $ai_key    = (string) get_post_meta($id, Credit::META_AI, true);

        Credit::reset_cache();

        /**
         * Fires after at least one Media Credits field has been written for
         * an attachment — from the compat-fields save handler or the
         * one-shot IPTC prefill. Not de-duplicated against the previous
         * value: comparing old and new would cost every save an extra read
         * to serve a listener that can compare for itself.
         *
         * @param int    $attachment_id
         * @param string $copyright     current value, re-read after the write
         * @param string $ai_key        current value, re-read after the write
         * @param string $context       'save' (attachment_fields_to_save) or
         *                              'iptc' (the one-shot prefill)
         */
        do_action('sfx_media_credits_saved', $id, $copyright, $ai_key, $context);
    }

    /**
     * Anything outside the closed slug list stores as "no marking". A value we
     * do not recognise must never survive into output as a label we cannot
     * render.
     */
    public static function sanitize_ai_key(string $key): string
    {
        return isset(Settings::get_labels()[$key]) ? $key : '';
    }

    /**
     * Prefill the copyright field from the IPTC data WordPress already parsed.
     *
     * Two independent guards, because they answer different questions:
     *
     * - $context must be 'create'. WordPress passes 'create' on upload
     *   (wp-admin/includes/image.php:750) and 'update' on regeneration
     *   (image.php:185). Without this, the first regeneration after the
     *   feature is switched on would backfill every older attachment that
     *   happens to carry IPTC data — a mass write nobody asked for.
     * - The marker must be absent. This is what keeps a second 'create' from
     *   resurrecting a value an editor deliberately cleared.
     *
     * @param mixed $metadata
     * @param mixed $attachment_id
     * @param mixed $context 'create' on upload, 'update' on regeneration
     * @return mixed the metadata, untouched — this is a read-only passenger on the filter
     */
    public static function prefill_iptc($metadata, $attachment_id, $context = 'create')
    {
        if (!is_array($metadata) || $context !== 'create') {
            return $metadata;
        }

        $id = (int) $attachment_id;

        if ($id <= 0) {
            return $metadata;
        }

        if ((string) get_post_meta($id, Credit::META_IPTC_MARKER, true) !== '') {
            return $metadata;
        }

        // The marker is set on the first run whether or not anything was
        // found. "We have looked" is the fact being recorded, not "we wrote".
        update_post_meta($id, Credit::META_IPTC_MARKER, '1');

        $image_meta = is_array($metadata['image_meta'] ?? null) ? $metadata['image_meta'] : [];
        $value      = self::iptc_copyright($image_meta);

        // Lets a site read a different IPTC field, normalise agency
        // spellings, or suppress the prefill for a source it does not
        // trust by returning ''. Fires after iptc_copyright() has picked
        // copyright/credit, before the empty check and the write — so a
        // filter can veto the write by returning '', or supply a value from
        // a field iptc_copyright() does not read at all.
        $value = sanitize_text_field((string) apply_filters('sfx_media_credits_iptc_value', $value, $image_meta, $id));

        if ($value === '') {
            return $metadata;
        }

        if (trim((string) get_post_meta($id, Credit::META_COPYRIGHT, true)) !== '') {
            return $metadata;
        }

        // update_post_meta() -> update_metadata() unslashes its input, so it
        // expects slashed data. save() gets that for free from $_POST; IPTC
        // values come straight from wp_read_image_metadata() and are not
        // slashed, so a backslash in a notice would otherwise be eaten.
        update_post_meta($id, Credit::META_COPYRIGHT, wp_slash($value));

        self::notify_saved($id, 'iptc');

        return $metadata;
    }

    /**
     * IPTC `copyright`, else `credit`. Both are already parsed by
     * wp_read_image_metadata(); this module owns no parser of its own.
     *
     * @param array<string, mixed> $image_meta
     */
    public static function iptc_copyright(array $image_meta): string
    {
        foreach (['copyright', 'credit'] as $key) {
            $value = sanitize_text_field((string) ($image_meta[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $columns['sfx_media_credit'] = __('Credit', 'sfxtheme');

        return $columns;
    }

    /**
     * The column reports STORED state, deliberately not Credit::for().
     *
     * Its job is to show what an editor entered, and a value that exists only
     * because of the global fallback has to look different from one that does
     * not — otherwise the column shows a copyright for the very rows the
     * "without copyright" filter below calls empty.
     *
     * @param mixed $id
     */
    public static function column(string $column, $id): void
    {
        if ($column !== 'sfx_media_credit') {
            return;
        }

        $id        = (int) $id;
        $copyright = trim((string) get_post_meta($id, Credit::META_COPYRIGHT, true));
        $ai_key    = (string) get_post_meta($id, Credit::META_AI, true);
        $labels    = Settings::get_labels();

        if ($copyright !== '') {
            echo '<div>' . esc_html($copyright) . '</div>';
        } else {
            $fallback = trim((string) Settings::get('fallback_copyright'));

            if ($fallback !== '') {
                printf(
                    '<div style="opacity:.6"><em>%s</em></div>',
                    esc_html(sprintf(
                        /* translators: %s: the site-wide fallback copyright notice */
                        __('%s (fallback)', 'sfxtheme'),
                        $fallback
                    ))
                );
            }
        }

        if (isset($labels[$ai_key])) {
            echo '<div><strong>' . esc_html($labels[$ai_key]) . '</strong></div>';
        }
    }

    public static function filter_dropdown(): void
    {
        global $pagenow;

        if ($pagenow !== 'upload.php') {
            return;
        }

        $current = isset($_GET[self::FILTER_PARAM])
            ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM]))
            : '';

        $choices = [
            ''              => __('All credits', 'sfxtheme'),
            'no_copyright'  => __('Without copyright', 'sfxtheme'),
            'any_ai'        => __('With AI marking', 'sfxtheme'),
        ];

        foreach (Settings::get_labels() as $slug => $label) {
            $choices[$slug] = $label;
        }

        echo '<select name="' . esc_attr(self::FILTER_PARAM) . '">';

        foreach ($choices as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    /**
     * pre_get_posts fires for every query in the request, so the scope
     * contract comes first and the work second.
     *
     * Note $pagenow, not get_current_screen()->id: WP_Screen strips the .php
     * suffix (class-wp-screen.php:235-237), so a screen comparison against
     * 'upload.php' never matches and the filter would silently do nothing.
     *
     * @param mixed $query
     */
    public static function filter_query($query): void
    {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'upload.php') {
            return;
        }

        if (!is_object($query) || !method_exists($query, 'is_main_query') || !$query->is_main_query()) {
            return;
        }

        $post_type = $query->get('post_type');
        $types     = is_array($post_type) ? $post_type : [$post_type];

        // Exactly attachment. upload.php's main query always sets it, and
        // accepting an empty post_type would let this touch any other main
        // query that happened to run on that screen.
        if (!in_array('attachment', $types, true)) {
            return;
        }

        $value = isset($_GET[self::FILTER_PARAM])
            ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM]))
            : '';

        $meta_query = self::filter_meta_query($value);

        if ($meta_query === []) {
            return;
        }

        // Combine rather than replace: another plugin may already have put
        // constraints on this query, and discarding them silently changes
        // results that are not ours to change.
        $existing = $query->get('meta_query');

        if (is_array($existing) && $existing !== []) {
            $meta_query = ['relation' => 'AND', $existing, $meta_query];
        }

        $query->set('meta_query', $meta_query);
    }

    /**
     * The meta query for one filter value, or [] for "not one of ours".
     *
     * @return array<mixed>
     */
    public static function filter_meta_query(string $value): array
    {
        if ($value === 'no_copyright') {
            // Two ways to have no copyright: never written, or cleared.
            return [
                'relation' => 'OR',
                ['key' => Credit::META_COPYRIGHT, 'compare' => 'NOT EXISTS'],
                ['key' => Credit::META_COPYRIGHT, 'value' => '', 'compare' => '='],
            ];
        }

        if ($value === 'any_ai') {
            return [
                ['key' => Credit::META_AI, 'value' => '', 'compare' => '!='],
            ];
        }

        if ($value !== '' && isset(Settings::get_labels()[$value])) {
            return [
                ['key' => Credit::META_AI, 'value' => $value, 'compare' => '='],
            ];
        }

        return [];
    }
}
