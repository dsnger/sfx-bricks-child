<?php

declare(strict_types=1);

namespace SFX\SecurityHeader;

/**
 * Handles registration and retrieval of security header settings.
 */
class Settings
{
    public const OPTION_GROUP = 'sfx_security_header_settings_group';

    /**
     * Register all settings for security headers.
     */
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'register_settings']);
    }

    /**
     * Register settings with WordPress.
     */
    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, 'sfx_hsts_max_age', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '63072000',
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_hsts_include_subdomains', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_hsts_preload', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_csp', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'upgrade-insecure-requests;',
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_csp_report_uri', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_permissions_policy', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'accelerometer=(), autoplay=(), camera=(), cross-origin-isolated=(), display-capture=(self), encrypted-media=(), fullscreen=*, geolocation=(self), gyroscope=(), keyboard-map=(), magnetometer=(), microphone=(), midi=(), payment=*, picture-in-picture=*, publickey-credentials-get=(), screen-wake-lock=(), sync-xhr=*, usb=(), xr-spatial-tracking=(), gamepad=(), serial=()',
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_x_frame_options', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'SAMEORIGIN',
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_x_frame_options_allow_from_url', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_disable_hsts_header', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_disable_csp_header', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_disable_x_content_type_options_header', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting(self::OPTION_GROUP, 'sfx_disable_x_frame_options_header', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
    }

    // Add static getter methods for each option as needed, e.g.:
    public static function get(string $option, $default = null)
    {
        return get_option($option, $default);
    }

    /**
     * Delete all options created by the SecurityHeader feature.
     *
     * @return void
     */
    public static function delete_all_options(): void
    {
        $options = [
            'sfx_hsts_max_age',
            'sfx_hsts_include_subdomains',
            'sfx_hsts_preload',
            'sfx_csp',
            'sfx_csp_report_uri',
            'sfx_permissions_policy',
            'sfx_x_frame_options',
            'sfx_x_frame_options_allow_from_url',
            'sfx_disable_hsts_header',
            'sfx_disable_csp_header',
            'sfx_disable_x_content_type_options_header',
            'sfx_disable_x_frame_options_header',
        ];
        foreach ($options as $option) {
            delete_option($option);
        }
    }
}
