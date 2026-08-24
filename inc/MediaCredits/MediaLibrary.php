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
}
