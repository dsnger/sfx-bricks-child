<?php

declare(strict_types=1);

namespace SFX\EditorProse;

class Settings
{
    public const OPTION_NAME = 'sfx_editor_prose_options';
    public const OPTION_GROUP = 'sfx_editor_prose_options';
    public const ELEMENTS = ['text', 'post-content'];

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
        return [
            'classes' => [],
            'element' => 'text',
            'all_post_types' => true,
            'post_types' => [],
            'baseline' => false,
            'title_gap' => '',
        ];
    }

    /** Sanitized on every read, so imports and hand-edited values never reach the editor raw. */
    public static function get(): array
    {
        return self::sanitize(get_option(self::OPTION_NAME, self::defaults()));
    }

    /** Total: any input yields the full, typed settings array. */
    public static function sanitize($raw): array
    {
        $d = self::defaults();
        if (!is_array($raw)) {
            return $d;
        }

        return [
            'classes' => self::sanitize_classes($raw['classes'] ?? []),
            'element' => in_array($raw['element'] ?? null, self::ELEMENTS, true) ? $raw['element'] : 'text',
            'all_post_types' => array_key_exists('all_post_types', $raw) ? self::to_bool($raw['all_post_types']) : $d['all_post_types'],
            'post_types' => self::sanitize_post_types($raw['post_types'] ?? []),
            'baseline' => array_key_exists('baseline', $raw) ? self::to_bool($raw['baseline']) : $d['baseline'],
            'title_gap' => self::sanitize_title_gap($raw['title_gap'] ?? ''),
        ];
    }

    public static function applies_to(array $o, string $post_type): bool
    {
        if ($o['classes'] === [] && !$o['baseline']) {
            return false;
        }

        return $o['all_post_types'] || in_array($post_type, $o['post_types'], true);
    }

    public static function bricks_ok(?string $version): bool
    {
        return $version !== null && version_compare($version, '2.4', '>=');
    }

    private static function to_bool($v): bool
    {
        return $v === true || $v === 1 || $v === '1';
    }

    private static function sanitize_classes($v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $c) {
            if (!is_string($c)) {
                continue;
            }
            $c = ltrim(trim($c), '.');
            if (preg_match('/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/D', $c) && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private static function sanitize_post_types($v): array
    {
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $pt) {
            if (!is_string($pt) || in_array($pt, $out, true) || !post_type_exists($pt)) {
                continue;
            }
            if (function_exists('use_block_editor_for_post_type') && !use_block_editor_for_post_type($pt)) {
                continue;
            }
            $out[] = $pt;
        }

        return $out;
    }

    private static function sanitize_title_gap($v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = trim($v);
        if ($v === '' || strlen($v) > 100 || preg_match('/[;{}<>\\\\]|\/\*|url\(/i', $v)) {
            return '';
        }

        return $v;
    }
}
