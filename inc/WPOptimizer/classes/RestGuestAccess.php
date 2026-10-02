<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

/**
 * Guest REST policy (spec docs/superpowers/specs/2026-10-02-rest-guest-access-design.md).
 * Every read of the four rest_guest_* keys goes through the normalisers below: Import/Export
 * can write the option while the module is off, so stored values may be anything.
 */
final class RestGuestAccess
{
    public const OPTION = 'sfx_wpoptimizer_options';
    public const MODES = ['open', 'allowlist', 'closed'];
    public const INTERNAL_NAMESPACE = 'sfx-guest/v1';
    public const DEFAULTS = [
        'bricks/v1'         => 'all',
        'oembed/1.0'        => 'get',
        'contact-form-7/v1' => 'all',
        'fluentform/v1'     => 'all',
        'burst/v1'          => 'all',
    ];
    public const ADMIN_NAMESPACES = [
        'wp-site-health/v1', 'wp-block-editor/v1', 'wp-abilities/v1',
        'fluent-smtp', 'fluent-snippets', 'core-framework/v2',
    ];

    public static function option(): array
    {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    /** Characters that survive sanitize_text_field() and wp_kses unchanged, so an import cannot alter an identity. */
    public static function supported($ns): bool
    {
        if (!is_string($ns) || !preg_match('#\A[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*\z#', $ns)) {
            return false;
        }
        foreach (explode('/', $ns) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    public static function mode(array $o): string
    {
        $mode = $o['rest_guest_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::MODES, true)) {
            return $mode;
        }
        // Read-time migration of the old all-or-nothing switch.
        if (!array_key_exists('rest_guest_mode', $o) && !empty($o['disable_rest_api_non_authenticated'])) {
            return 'closed';
        }
        return 'open';
    }

    /** @return list<array{namespace:string, method:string}>|null  null = never saved */
    public static function namespaces(array $o, bool $from_form = false): ?array
    {
        if (!array_key_exists('rest_guest_namespaces', $o) || $o['rest_guest_namespaces'] === null) {
            return null;
        }
        $rows = $o['rest_guest_namespaces'];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !self::supported($row['namespace'] ?? null) || $row['namespace'] === self::INTERNAL_NAMESPACE) {
                continue;
            }
            // A form row is a grant only with an explicit allowed=1 (a cut-off row has none).
            if ($from_form && ($row['allowed'] ?? null) !== '1') {
                continue;
            }
            $method = ($row['method'] ?? null) === 'get' ? 'get' : 'all';
            $out[$row['namespace']] = ['namespace' => $row['namespace'], 'method' => $method];
        }
        return array_values($out);
    }

    /** @return list<string> */
    public static function seen(array $o): array
    {
        $list = $o['rest_guest_seen'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_unique(array_filter($list, static fn($v) => is_string($v) && $v !== '' && $v !== self::INTERNAL_NAMESPACE)));
    }

    public static function hide_index(array $o): bool
    {
        $v = $o['rest_guest_hide_index'] ?? null;
        if ($v === null) {
            return true;
        }
        return !in_array($v, [0, '0', false], true);
    }

    /** @param list<array{namespace:string, method:string}> $rows @return array<string,string> */
    public static function to_map(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['namespace']] = $row['method'];
        }
        return $map;
    }
}
