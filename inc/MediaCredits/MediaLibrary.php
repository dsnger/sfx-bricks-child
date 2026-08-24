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

    public static function register(): void
    {
        add_filter('attachment_fields_to_edit', [self::class, 'fields'], 10, 2);
        add_filter('attachment_fields_to_save', [self::class, 'save'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [self::class, 'prefill_iptc'], 10, 3);
    }

    /**
     * Both fields, for every attachment type. The AI Act covers video and
     * audio too, and a MIME check would only add a way to be wrong.
     *
     * @param array<string, mixed> $form_fields
     * @param mixed $post
     * @return array<string, mixed>
     */
    public static function fields(array $form_fields, $post): array
    {
        $id = isset($post->ID) ? (int) $post->ID : 0;

        $form_fields[self::FIELD_COPYRIGHT] = [
            'label' => __('Copyright', 'sfxtheme'),
            'input' => 'text',
            'value' => (string) get_post_meta($id, Credit::META_COPYRIGHT, true),
            'helps' => __('Free text, e.g. © Photographer or an agency notice.', 'sfxtheme'),
        ];

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

        $form_fields[self::FIELD_AI] = [
            'label' => __('AI marking', 'sfxtheme'),
            'input' => 'html',
            'html'  => sprintf(
                '<select name="attachments[%1$d][%2$s]" id="attachments-%1$d-%2$s">%3$s</select>',
                $id,
                esc_attr(self::FIELD_AI),
                $options
            ),
            'helps' => __('How this file was produced or altered.', 'sfxtheme'),
        ];

        return $form_fields;
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

        if (array_key_exists(self::FIELD_COPYRIGHT, $attachment)) {
            update_post_meta(
                $id,
                Credit::META_COPYRIGHT,
                sanitize_text_field((string) $attachment[self::FIELD_COPYRIGHT])
            );
        }

        if (array_key_exists(self::FIELD_AI, $attachment)) {
            update_post_meta(
                $id,
                Credit::META_AI,
                self::sanitize_ai_key((string) $attachment[self::FIELD_AI])
            );
        }

        return $post;
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

        $value = self::iptc_copyright(is_array($metadata['image_meta'] ?? null) ? $metadata['image_meta'] : []);

        if ($value === '') {
            return $metadata;
        }

        if (trim((string) get_post_meta($id, Credit::META_COPYRIGHT, true)) !== '') {
            return $metadata;
        }

        update_post_meta($id, Credit::META_COPYRIGHT, $value);

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
}
