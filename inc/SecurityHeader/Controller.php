<?php

declare(strict_types=1);

namespace SFX\SecurityHeader;


class Controller
{

    /**
     * Initialize the controller by registering all hooks
     */
    public function __construct()
    {
        Settings::register();
        AdminPage::register();
        AssetManager::register();
        add_filter('wp_headers', [self::class, 'filter_wp_headers']);
    }

    /**
     * Filter and add security headers to the response.
     *
     * @param array $headers
     * @return array
     */
    public static function filter_wp_headers(array $headers): array
    {
        // HSTS
        if (! (bool) get_option('sfx_disable_hsts_header', false)) {
            $headers['Strict-Transport-Security'] = self::get_hsts_header();
        }
        
        // CSP
        if (! (bool) get_option('sfx_disable_csp_header', false)) {
            $headers['Content-Security-Policy'] = self::get_csp_header();
            
            // CSP Report Only - add if report URI is set
            $report_uri = get_option('sfx_csp_report_uri', '');
            if (!empty($report_uri)) {
                $headers['Content-Security-Policy-Report-Only'] = self::get_csp_header();
            }
        }
        
        // Permissions Policy
        $headers['Permissions-Policy'] = get_option('sfx_permissions_policy', '');
        
        // X-Frame-Options
        if (! (bool) get_option('sfx_disable_x_frame_options_header', false)) {
            $headers['X-Frame-Options'] = self::get_x_frame_options_header();
        }
        
        // X-Content-Type-Options
        if (! (bool) get_option('sfx_disable_x_content_type_options_header', false)) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }
        
        // Cross-Origin headers
        $headers['Cross-Origin-Embedder-Policy'] = "unsafe-none; report-to='default'";
        $headers['Cross-Origin-Embedder-Policy-Report-Only'] = "unsafe-none; report-to='default'";
        $headers['Cross-Origin-Opener-Policy'] = 'unsafe-none';
        $headers['Cross-Origin-Opener-Policy-Report-Only'] = "unsafe-none; report-to='default'";
        $headers['Cross-Origin-Resource-Policy'] = 'cross-origin';
        
        // Access-Control headers
        $headers['Access-Control-Allow-Methods'] = 'GET,POST';
        $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';
        
        // Legacy/additional headers
        $headers['X-Content-Security-Policy'] = 'default-src \'self\'; img-src *; media-src * data:;';
        
        // Referrer Policy
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        
        // X-Permitted-Cross-Domain-Policies
        $headers['X-Permitted-Cross-Domain-Policies'] = 'none';
        
        return $headers;
    }

    private static function get_hsts_header(): string
    {
        $max_age = get_option('sfx_hsts_max_age', '63072000');
        $include_subdomains = (bool) get_option('sfx_hsts_include_subdomains', false);
        $preload = (bool) get_option('sfx_hsts_preload', false);
        $tokens = ["max-age={$max_age}"];
        if ($include_subdomains) {
            $tokens[] = 'includeSubDomains';
        }
        if ($preload) {
            $tokens[] = 'preload';
        }
        return implode('; ', $tokens);
    }

    private static function get_csp_header(): string
    {
        $csp = get_option('sfx_csp', 'upgrade-insecure-requests;');
        $report_uri = get_option('sfx_csp_report_uri', '');
        if (!empty($report_uri)) {
            $csp .= ' report-uri ' . esc_url_raw($report_uri) . ';';
            $csp .= ' report-to ' . esc_url_raw($report_uri) . ';';
        }
        return $csp;
    }

    private static function get_x_frame_options_header(): string
    {
        $x_frame = get_option('sfx_x_frame_options', 'SAMEORIGIN');
        if ($x_frame === 'ALLOW-FROM') {
            $url = get_option('sfx_x_frame_options_allow_from_url', '');
            if (!empty($url)) {
                return 'ALLOW-FROM ' . esc_url_raw($url);
            }
        }
        return $x_frame ?: 'SAMEORIGIN';
    }
}
