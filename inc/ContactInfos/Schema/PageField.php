<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

/**
 * Raw JSON-LD for one page, editable by administrators only (spec § "Per-page custom field", D5).
 * It reaches a <script> element, so it is stored only as validated JSON and printed only re-encoded.
 */
final class PageField
{
    public const META = '_sfx_page_schema';

    private const NONCE_ACTION = 'sfx_page_schema_save';
    private const NONCE_FIELD = 'sfx_page_schema_nonce';
    private const FIELD = 'sfx_page_schema';
    private const MAX_BYTES = 65536;

    public static function register(): void
    {
        add_action('init', [self::class, 'register_meta']);
        add_action('add_meta_boxes', [self::class, 'add_meta_box'], 10, 2);
        add_action('save_post', [self::class, 'save']);
        add_action('wp_head', [self::class, 'print_for_request']);
    }

    public static function register_meta(): void
    {
        register_post_meta('', self::META, [
            'show_in_rest'  => false,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => [self::class, 'auth'],
        ]);
    }

    /** `auth_post_meta_{key}` callback: the same three capabilities as the meta box. */
    public static function auth($allowed, $meta_key, $object_id, $user_id = 0, $cap = '', $caps = []): bool
    {
        return self::can_edit((int) $object_id);
    }

    /** unfiltered_html alone would admit single-site Editors, so manage_options is required too. */
    public static function can_edit(int $post_id): bool
    {
        return current_user_can('manage_options') && current_user_can('unfiltered_html')
            && current_user_can('edit_post', $post_id);
    }

    /** @return array{action:'delete'|'store'|'reject', value:string, error:?string} */
    public static function validate(string $raw): array
    {
        if (trim($raw) === '') {
            return ['action' => 'delete', 'value' => '', 'error' => null];
        }
        if (strlen($raw) > self::MAX_BYTES) { // the submitted bytes, surrounding whitespace included
            return ['action' => 'reject', 'value' => '', 'error' => __('The structured data was not saved: it is larger than 64 KB. The previous value was kept.', 'sfxtheme')];
        }
        if (self::decode($raw) === null) {
            return ['action' => 'reject', 'value' => '', 'error' => __('The structured data was not saved: it must be a JSON object or an array of JSON objects. The previous value was kept.', 'sfxtheme')];
        }
        return ['action' => 'store', 'value' => $raw, 'error' => null];
    }

    public static function add_meta_box($post_type, $post): void
    {
        if (!$post instanceof \WP_Post || !self::can_edit($post->ID)
            || !in_array($post_type, array_diff(get_post_types(['public' => true, 'show_ui' => true]), ['attachment']), true)) {
            return;
        }
        add_meta_box('sfx_page_schema', __('Structured data (JSON-LD)', 'sfxtheme'), [self::class, 'render_meta_box'], $post_type, 'normal', 'low');
    }

    public static function render_meta_box($post): void
    {
        if (!$post instanceof \WP_Post || !self::can_edit($post->ID)) {
            return;
        }
        // Shown here, not in admin_notices: the block editor does not print legacy notices.
        $error = get_transient(self::notice_key());
        if (is_string($error) && $error !== '') {
            delete_transient(self::notice_key());
            echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
        }
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $value = get_post_meta($post->ID, self::META, true);
        echo '<p>' . esc_html__('A JSON-LD object, or an array of objects, added to this page while structured data is switched on. Leave empty to remove it.', 'sfxtheme') . '</p>';
        echo '<textarea name="' . esc_attr(self::FIELD) . '" rows="8" class="large-text code">' . esc_textarea(is_string($value) ? $value : '') . '</textarea>';
    }

    /** Processed only with its nonce, so Quick Edit, REST and other saves leave the value untouched. */
    public static function save($post_id): void
    {
        $post_id = (int) $post_id;
        $nonce = $_POST[self::NONCE_FIELD] ?? null;
        $raw = $_POST[self::FIELD] ?? null;
        // save_post also fires for other posts updated in the same request; only the posted form's post counts.
        if ((int) ($_POST['post_ID'] ?? 0) !== $post_id || !is_string($nonce) || !is_string($raw) || !wp_verify_nonce(wp_unslash($nonce), self::NONCE_ACTION)
            || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !self::can_edit($post_id)) {
            return;
        }
        $result = self::validate(wp_unslash($raw));
        if ($result['action'] === 'delete') {
            delete_post_meta($post_id, self::META);
        } elseif ($result['action'] === 'store') {
            update_post_meta($post_id, self::META, wp_slash($result['value'])); // update_post_meta() unslashes
        } else {
            set_transient(self::notice_key(), $result['error'], 5 * MINUTE_IN_SECONDS);
        }
    }

    public static function print_for_request(): void
    {
        echo self::render_for_request(); // already encoded with JSON_HEX_TAG | JSON_HEX_AMP
    }

    /** Every mode except Off; singular, published, not password-protected; '' otherwise. */
    public static function render_for_request(): string
    {
        // The switch itself, not Mode::detect(): no plugin read stands between the page and its own field.
        if (!Settings::get()['enabled'] || !is_singular()) {
            return '';
        }
        $post_id = (int) get_queried_object_id();
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || post_password_required($post)) {
            return '';
        }
        $stored = get_post_meta($post_id, self::META, true);
        $decoded = is_string($stored) ? self::decode(trim($stored)) : null; // re-checked: the meta may come from elsewhere
        if ($decoded === null) {
            return '';
        }
        $json = wp_json_encode($decoded, Output::JSON_FLAGS);
        return is_string($json) ? '<script type="application/ld+json">' . $json . "</script>\n" : '';
    }

    /** A JSON object, or a non-empty array whose items are all objects; null otherwise. Objects stay objects. */
    private static function decode(string $json): object|array|null
    {
        $decoded = json_decode($json);
        if ($decoded instanceof \stdClass) {
            return $decoded;
        }
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        foreach ($decoded as $item) {
            if (!$item instanceof \stdClass) {
                return null;
            }
        }
        return $decoded;
    }

    private static function notice_key(): string
    {
        return 'sfx_page_schema_error_' . get_current_user_id();
    }
}
