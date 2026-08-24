<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Option schema for the Media Credits module.
 *
 * Normalisation is deliberately one function used from two directions. On
 * write it is the registered sanitize callback; on read it runs again, because
 * an import performed while the feature was disabled never reached
 * register_setting() and therefore never reached the callback.
 */
class Settings
{
    public const OPTION_NAME  = 'sfx_media_credits_options';
    public const OPTION_GROUP = 'sfx_media_credits_options';

    public const OUTPUT_MODES     = ['off', 'caption', 'overlay'];
    public const CREDIT_DISPLAYS  = ['text', 'icon', 'icon_text'];
    public const ICON_SIZE_MIN    = 8;
    public const ICON_SIZE_MAX    = 128;
    public const ICON_SIZE_DEFAULT = 24;

    public static function register(): void
    {
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default'           => self::get_defaults(),
        ]);
    }

    /**
     * The closed slug list with its default wording.
     *
     * @return array<string, string>
     */
    public static function get_default_labels(): array
    {
        return [
            'ai_generated'      => __('KI-generiert', 'sfxtheme'),
            'ai_edited'         => __('KI-bearbeitet', 'sfxtheme'),
            'ai_assisted'       => __('KI-unterstützt', 'sfxtheme'),
            'digitally_altered' => __('Digital verändert', 'sfxtheme'),
        ];
    }

    /**
     * The label map after the site filter, with the slug set enforced in both
     * directions: the intersection drops keys a filter invented, the merge
     * restores keys a filter dropped. Wording is negotiable, keys are not —
     * a slug without a label is an image that silently loses its disclosure.
     *
     * @return array<string, string>
     */
    public static function get_labels(): array
    {
        $defaults = self::get_default_labels();
        $filtered = apply_filters('sfx_media_credits_labels', $defaults);

        if (!is_array($filtered)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($filtered, $defaults));
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_defaults(): array
    {
        $defaults = [
            'output_mode'        => 'off',
            'force_wrapper'      => false,
            'credit_display'     => 'text',
            'icon_size'          => self::ICON_SIZE_DEFAULT,
            'fallback_copyright' => '',
        ];

        foreach (array_keys(self::get_default_labels()) as $slug) {
            $defaults['seal_' . $slug] = 0;
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $defaults = self::get_defaults();

        $mode = (string) ($raw['output_mode'] ?? '');
        $display = (string) ($raw['credit_display'] ?? '');

        $clean = [
            'output_mode'        => in_array($mode, self::OUTPUT_MODES, true) ? $mode : $defaults['output_mode'],
            'force_wrapper'      => !empty($raw['force_wrapper']),
            'credit_display'     => in_array($display, self::CREDIT_DISPLAYS, true) ? $display : $defaults['credit_display'],
            'icon_size'          => max(self::ICON_SIZE_MIN, min(self::ICON_SIZE_MAX, absint($raw['icon_size'] ?? self::ICON_SIZE_DEFAULT))),
            'fallback_copyright' => sanitize_text_field((string) ($raw['fallback_copyright'] ?? '')),
        ];

        foreach (array_keys(self::get_default_labels()) as $slug) {
            $id = absint($raw['seal_' . $slug] ?? 0);
            $clean['seal_' . $slug] = ($id > 0 && wp_attachment_is_image($id)) ? $id : 0;
        }

        return $clean;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize_options($input): array
    {
        return self::normalize(is_array($input) ? $input : []);
    }

    /**
     * One validated option value.
     *
     * @return mixed
     */
    public static function get(string $key)
    {
        $stored = get_option(self::OPTION_NAME, []);
        $clean  = self::normalize(is_array($stored) ? $stored : []);

        return $clean[$key] ?? null;
    }
}
