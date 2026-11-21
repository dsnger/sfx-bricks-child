<?php

declare(strict_types=1);

namespace SFX\Markdown;

class Settings
{
    public const OPTION_NAME = 'sfx_markdown_options';
    public const OPTION_GROUP = 'sfx_markdown_options';

    public static function register(): void
    {
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function defaults(): array
    {
        return ['post_types' => ['page', 'post']];
    }

    /** Sanitized on every read, so imports and hand-edited values never reach the front end raw. */
    public static function get(): array
    {
        return self::sanitize(get_option(self::OPTION_NAME, self::defaults()));
    }

    /** Total: any input yields the full, typed settings array. */
    public static function sanitize($raw): array
    {
        if (!is_array($raw)) {
            return self::defaults();
        }

        $offered = self::offered_post_types();
        $types = is_array($raw['post_types'] ?? null) ? $raw['post_types'] : [];
        $out = [];
        foreach ($types as $pt) {
            if (is_string($pt) && isset($offered[$pt]) && !in_array($pt, $out, true)) {
                $out[] = $pt;
            }
        }

        return ['post_types' => $out];
    }

    /** Post types that can be served as Markdown: public ones plus page, minus media and Bricks templates. @return array<string,string> name => plural label */
    public static function offered_post_types(): array
    {
        $objects = get_post_types(['publicly_queryable' => true], 'objects') + get_post_types(['name' => 'page'], 'objects');
        unset($objects['attachment'], $objects['bricks_template']);

        $out = [];
        foreach ($objects as $name => $object) {
            $out[(string) $name] = (string) ($object->labels->name ?? $object->label ?? $name);
        }

        return $out;
    }
}
