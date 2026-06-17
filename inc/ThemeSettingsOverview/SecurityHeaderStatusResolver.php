<?php

declare(strict_types=1);

namespace SFX\ThemeSettingsOverview;

/**
 * Mirrors SecurityHeader\Controller runtime header resolution for overview status.
 */
final class SecurityHeaderStatusResolver
{
    public static function is_hsts_active(): bool
    {
        return ! (bool) get_option('sfx_disable_hsts_header', false);
    }

    public static function is_csp_active(): bool
    {
        return ! (bool) get_option('sfx_disable_csp_header', false);
    }

    public static function is_x_frame_options_active(): bool
    {
        return ! (bool) get_option('sfx_disable_x_frame_options_header', false);
    }

    public static function is_x_content_type_options_active(): bool
    {
        return ! (bool) get_option('sfx_disable_x_content_type_options_header', false);
    }

    public static function resolve_permissions_policy(): string
    {
        $policy = (string) get_option('sfx_permissions_policy', '');
        if ((bool) get_option('sfx_restrict_sensitive_browser_features', false)) {
            $policy = self::restrict_sensitive_browser_features($policy);
        }

        return $policy;
    }

    public static function is_permissions_policy_active(): bool
    {
        return self::resolve_permissions_policy() !== '';
    }

    public static function get_hsts_detail(): string
    {
        $max_age = (string) get_option('sfx_hsts_max_age', '63072000');
        $parts = ['max-age=' . $max_age];
        if ((bool) get_option('sfx_hsts_include_subdomains', false)) {
            $parts[] = 'includeSubDomains';
        }
        if ((bool) get_option('sfx_hsts_preload', false)) {
            $parts[] = 'preload';
        }

        return implode('; ', $parts);
    }

    public static function get_csp_detail(): string
    {
        $csp = (string) get_option('sfx_csp', 'upgrade-insecure-requests;');
        $default = 'upgrade-insecure-requests;';
        $detail = $csp === $default
            ? __('Default policy (upgrade-insecure-requests)', 'sfxtheme')
            : __('Custom policy configured', 'sfxtheme');

        $report_uri = (string) get_option('sfx_csp_report_uri', '');
        if ($report_uri !== '') {
            $detail .= '; ' . __('report-uri set', 'sfxtheme');
        }

        return $detail;
    }

    public static function get_x_frame_options_detail(): string
    {
        $mode = (string) get_option('sfx_x_frame_options', 'SAMEORIGIN');
        if ($mode === 'ALLOW-FROM') {
            $url = (string) get_option('sfx_x_frame_options_allow_from_url', '');
            if ($url !== '') {
                return 'ALLOW-FROM ' . $url;
            }
        }

        return $mode ?: 'SAMEORIGIN';
    }

    /**
     * @return array<int, array{id: string, label: string, status: string, detail: string|null}>
     */
    public static function get_header_items(): array
    {
        $items = [
            [
                'id' => 'hsts',
                'label' => __('HSTS', 'sfxtheme'),
                'status' => self::is_hsts_active() ? 'active' : 'inactive',
                'detail' => self::is_hsts_active() ? self::get_hsts_detail() : null,
            ],
            [
                'id' => 'csp',
                'label' => __('Content-Security-Policy', 'sfxtheme'),
                'status' => self::is_csp_active() ? 'active' : 'inactive',
                'detail' => self::is_csp_active() ? self::get_csp_detail() : null,
            ],
            [
                'id' => 'permissions_policy',
                'label' => __('Permissions-Policy', 'sfxtheme'),
                'status' => self::is_permissions_policy_active() ? 'active' : 'inactive',
                'detail' => self::is_permissions_policy_active()
                    ? __('Policy directives configured', 'sfxtheme')
                    : null,
            ],
            [
                'id' => 'x_frame_options',
                'label' => __('X-Frame-Options', 'sfxtheme'),
                'status' => self::is_x_frame_options_active() ? 'active' : 'inactive',
                'detail' => self::is_x_frame_options_active() ? self::get_x_frame_options_detail() : null,
            ],
            [
                'id' => 'x_content_type_options',
                'label' => __('X-Content-Type-Options', 'sfxtheme'),
                'status' => self::is_x_content_type_options_active() ? 'active' : 'inactive',
                'detail' => self::is_x_content_type_options_active() ? 'nosniff' : null,
            ],
        ];

        $always_on = [
            'referrer_policy' => __('Referrer-Policy', 'sfxtheme'),
            'cross_origin_headers' => __('Cross-Origin headers', 'sfxtheme'),
        ];

        foreach ($always_on as $id => $label) {
            $items[] = [
                'id' => $id,
                'label' => $label,
                'status' => 'active',
                'detail' => __('Always sent when Security Header module is on', 'sfxtheme'),
            ];
        }

        return $items;
    }

    private static function restrict_sensitive_browser_features(string $policy): string
    {
        $restricted = [
            'geolocation' => 'geolocation=()',
            'camera' => 'camera=()',
            'microphone' => 'microphone=()',
        ];

        $directives = [];
        $seen = [];
        foreach (array_filter(array_map('trim', explode(',', $policy))) as $directive) {
            $name = trim(explode('=', $directive, 2)[0]);
            if (isset($restricted[$name])) {
                $directives[] = $restricted[$name];
                $seen[$name] = true;
                continue;
            }

            $directives[] = $directive;
        }

        foreach ($restricted as $name => $directive) {
            if (empty($seen[$name])) {
                $directives[] = $directive;
            }
        }

        return implode(', ', $directives);
    }
}
