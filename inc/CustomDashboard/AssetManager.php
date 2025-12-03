<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Asset management for Custom Dashboard
 *
 * @package SFX_Bricks_Child_Theme
 */
class AssetManager
{
    /**
     * Register asset hooks
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_dashboard_assets'], 20);
    }

    /**
     * Check if we're on the settings page
     *
     * @return bool
     */
    private static function is_settings_page(): bool
    {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, AdminPage::$menu_slug) !== false;
    }

    /**
     * Check if we're on the dashboard page
     *
     * @return bool
     */
    private static function is_dashboard_page(): bool
    {
        $screen = get_current_screen();
        return $screen && $screen->id === 'dashboard';
    }

    /**
     * Check if custom dashboard is enabled
     *
     * @return bool
     */
    private static function is_custom_dashboard_enabled(): bool
    {
        $options = get_option(Settings::$option_name, []);
        return !empty($options['enable_custom_dashboard']);
    }

    /**
     * Enqueue admin settings page assets
     *
     * @return void
     */
    public static function enqueue_admin_assets(): void
    {
        if (!self::is_settings_page()) {
            return;
        }

        // Enqueue WordPress media library and editor
        wp_enqueue_media();
        wp_enqueue_editor();

        $css_file = get_stylesheet_directory() . '/inc/CustomDashboard/assets/admin-style.css';
        $js_file = get_stylesheet_directory() . '/inc/CustomDashboard/assets/admin-script.js';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'sfx-custom-dashboard-admin',
                get_stylesheet_directory_uri() . '/inc/CustomDashboard/assets/admin-style.css',
                [],
                filemtime($css_file)
            );
        }

        // jQuery UI for sortable (all required dependencies)
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-widget');
        wp_enqueue_script('jquery-ui-mouse');
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue CodeMirror for CSS editor if on custom_css subtab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_subtab = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        
        if ($current_tab === 'brand' && $current_subtab === 'custom_css') {
            self::enqueue_code_editor();
        }

        if (file_exists($js_file)) {
            wp_enqueue_script(
                'sfx-custom-dashboard-admin',
                get_stylesheet_directory_uri() . '/inc/CustomDashboard/assets/admin-script.js',
                ['jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-mouse', 'jquery-ui-sortable', 'media-editor'],
                filemtime($js_file),
                true
            );

            // Pass option name and default values to JavaScript for dynamic field management
            wp_localize_script('sfx-custom-dashboard-admin', 'sfxDashboardAdmin', [
                'optionName' => Settings::$option_name,
                'defaultIcon' => Settings::DEFAULT_CUSTOM_ICON,
                'strings' => [
                    'remove' => __('Remove', 'sfxtheme'),
                    'icon' => __('Icon', 'sfxtheme'),
                    'title' => __('Title', 'sfxtheme'),
                    'url' => __('URL', 'sfxtheme'),
                    'custom' => __('Custom', 'sfxtheme'),
                    'confirmRemove' => __('Are you sure you want to remove this custom link?', 'sfxtheme'),
                ],
            ]);
        }
    }

    /**
     * Enqueue WordPress CodeMirror for CSS editing
     *
     * @return void
     */
    private static function enqueue_code_editor(): void
    {
        // Use WordPress built-in code editor
        $settings = wp_enqueue_code_editor(['type' => 'text/css']);
        
        if ($settings === false) {
            // Code editor disabled, fallback to plain textarea
            return;
        }
        
        // Add inline script to initialize CodeMirror
        wp_add_inline_script(
            'code-editor',
            sprintf(
                'jQuery(function($) {
                    var $textarea = $(".sfx-code-editor");
                    if ($textarea.length) {
                        var editorSettings = %s;
                        editorSettings.codemirror.lineNumbers = true;
                        editorSettings.codemirror.lineWrapping = true;
                        editorSettings.codemirror.viewportMargin = Infinity;
                        window.sfxCodeMirrorEditor = wp.codeEditor.initialize($textarea, editorSettings);
                    }
                });',
                wp_json_encode($settings)
            )
        );
    }

    /**
     * Enqueue dashboard page assets
     *
     * @return void
     */
    public static function enqueue_dashboard_assets(): void
    {
        if (!self::is_dashboard_page() || !self::is_custom_dashboard_enabled()) {
            return;
        }

        // Always load default stylesheet
        $css_file = get_stylesheet_directory() . '/inc/CustomDashboard/assets/dashboard-style.css';
        
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'sfx-custom-dashboard',
                get_stylesheet_directory_uri() . '/inc/CustomDashboard/assets/dashboard-style.css',
                [],
                filemtime($css_file)
            );

            // Inject custom brand CSS variables
            self::inject_brand_styles();
            
            // Add custom CSS on top if set
            $options = get_option(Settings::$option_name, []);
            $custom_css = $options['dashboard_custom_css'] ?? '';
            
            if (!empty($custom_css)) {
                wp_add_inline_style('sfx-custom-dashboard', $custom_css);
            }
        }

        $js_file = get_stylesheet_directory() . '/inc/CustomDashboard/assets/dashboard-script.js';
        
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'sfx-custom-dashboard',
                get_stylesheet_directory_uri() . '/inc/CustomDashboard/assets/dashboard-script.js',
                [],
                filemtime($js_file),
                true
            );
        }
    }

    /**
     * Resolve a color key to its CSS value
     * 
     * Handles both hex colors and semantic CSS variable references
     *
     * @param string $color_key The color key from settings
     * @param array<string, array<string, mixed>> $brand_colors The brand colors array
     * @param string $fallback Fallback color if key not found
     * @return string CSS color value (hex or hsl(var(--name)))
     */
    private static function resolve_color(string $color_key, array $brand_colors, string $fallback = '#667eea'): string
    {
        if (!isset($brand_colors[$color_key])) {
            return $fallback;
        }
        
        $color_data = $brand_colors[$color_key];
        $color = $color_data['color'] ?? $fallback;
        
        // If it's a CSS variable reference, wrap it in hsl()
        if (!empty($color_data['is_variable'])) {
            return 'hsl(' . $color . ')';
        }
        
        return $color;
    }

    /**
     * Inject brand styles as CSS variables
     *
     * @return void
     */
    private static function inject_brand_styles(): void
    {
        $options = get_option(Settings::$option_name, []);
        
        $defaults = Settings::get_default_brand_colors();
        $primary = $options['brand_primary_color'] ?? $defaults['brand_primary_color'];
        $secondary = $options['brand_secondary_color'] ?? $defaults['brand_secondary_color'];
        $accent = $options['brand_accent_color'] ?? $defaults['brand_accent_color'];
        $radius = $options['brand_border_radius'] ?? 8;
        $border_width = $options['brand_border_width'] ?? 0;
        $shadow_enabled = !empty($options['brand_shadow_enabled']);
        $shadow_intensity = absint($options['brand_shadow_intensity'] ?? 1);
        
        // Get custom status colors
        $statusColors = [
            'success' => $options['brand_success_color'] ?? $defaults['brand_success_color'],
            'warning' => $options['brand_warning_color'] ?? $defaults['brand_warning_color'],
            'error' => $options['brand_error_color'] ?? $defaults['brand_error_color'],
        ];
        
        // Generate semantic color palettes with custom status colors
        $light_palette = ColorUtils::generatePalette($primary, 'light', $statusColors);
        $dark_palette = ColorUtils::generatePalette($primary, 'dark', $statusColors);
        
        // Color map for brand color selections
        $brand_colors = Settings::get_brand_colors();
        
        $header_gradient = !empty($options['brand_header_gradient']);
        $header_gradient_start_key = $options['brand_header_gradient_start'] ?? 'primary';
        $header_gradient_end_key = $options['brand_header_gradient_end'] ?? 'secondary';
        $header_bg_key = $options['brand_header_bg_color'] ?? 'primary';
        
        $gradient_start = self::resolve_color($header_gradient_start_key, $brand_colors, $primary);
        $gradient_end = self::resolve_color($header_gradient_end_key, $brand_colors, $secondary);
        $header_bg_solid = self::resolve_color($header_bg_key, $brand_colors, $primary);
        
        // Generate header backgrounds (gradient or solid)
        $header_bg_light = $header_gradient 
            ? "linear-gradient(135deg, {$gradient_start} 0%, {$gradient_end} 100%)"
            : $header_bg_solid;
        $header_bg_dark = $header_gradient 
            ? ColorUtils::generateHeaderGradient($primary, 'dark')
            : ColorUtils::hslToHex($dark_palette['primary']['h'], $dark_palette['primary']['s'], $dark_palette['primary']['l']);

        // Card styling options
        $card_border_width = $options['card_border_width'] ?? 2;
        $card_radius = $options['card_border_radius'] ?? 8;
        $card_shadow_enabled = !empty($options['card_shadow_enabled']);
        
        // Resolve color settings from admin options
        $header_text_color = self::resolve_color($options['brand_header_text_color'] ?? 'primary-foreground', $brand_colors, 'hsl(var(--primary-foreground))');
        $border_color = self::resolve_color($options['brand_border_color'] ?? 'border', $brand_colors, 'hsl(var(--border))');
        $card_bg_color = self::resolve_color($options['card_background_color'] ?? 'secondary-color', $brand_colors, 'hsl(var(--secondary))');
        $card_text_color = self::resolve_color($options['card_text_color'] ?? 'secondary-foreground', $brand_colors, 'hsl(var(--secondary-foreground))');
        $card_border_color = self::resolve_color($options['card_border_color'] ?? 'border', $brand_colors, 'hsl(var(--border))');
        $card_hover_bg = self::resolve_color($options['card_hover_background_color'] ?? 'primary', $brand_colors, 'hsl(var(--primary))');
        $card_hover_text = self::resolve_color($options['card_hover_text_color'] ?? 'primary-foreground', $brand_colors, 'hsl(var(--primary-foreground))');
        $card_hover_border = self::resolve_color($options['card_hover_border_color'] ?? 'primary', $brand_colors, 'hsl(var(--primary))');
        
        // Column and gap settings
        $dashboard_gap = max(10, min(50, absint($options['dashboard_gap'] ?? 15)));
        $stats_columns = max(2, min(6, absint($options['stats_columns'] ?? 4)));
        $quicklinks_columns = max(2, min(6, absint($options['quicklinks_columns'] ?? 4)));

        // Build shared variables array
        $shared_vars = [
            'secondary' => $secondary,
            'accent' => $accent,
            'radius' => $radius,
            'border_width' => $border_width,
            'border_color' => $border_color,
            'header_text_color' => $header_text_color,
            'card_border_width' => $card_border_width,
            'card_radius' => $card_radius,
            'card_bg_color' => $card_bg_color,
            'card_text_color' => $card_text_color,
            'card_border_color' => $card_border_color,
            'card_hover_bg' => $card_hover_bg,
            'card_hover_text' => $card_hover_text,
            'card_hover_border' => $card_hover_border,
        ];

        // Light mode specific
        $light_vars = self::build_mode_vars($light_palette, $shadow_enabled, $shadow_intensity, $header_bg_light, $card_shadow_enabled, 'light');
        
        // Dark mode specific
        $dark_vars = self::build_mode_vars($dark_palette, $shadow_enabled, $shadow_intensity, $header_bg_dark, $card_shadow_enabled, 'dark');

        // Generate CSS
        $light_css_block = self::generate_css_vars_block($light_vars, $shared_vars);
        $dark_css_block = self::generate_css_vars_block($dark_vars, $shared_vars);

        $custom_css = "
/* Light Mode (default) */
body.index-php #wpcontent,
body.index-php:has([data-theme=\"light\"]) #wpcontent,
.sfx-dashboard-container,
.sfx-dashboard-container[data-theme=\"light\"] {
{$light_css_block}
}

/* Dark Mode */
body.index-php:has([data-theme=\"dark\"]) #wpcontent,
.sfx-dashboard-container[data-theme=\"dark\"] {
{$dark_css_block}
}

/* System preference (when data-theme='system') */
@media (prefers-color-scheme: dark) {
  body.index-php:has([data-theme=\"system\"]) #wpcontent,
  .sfx-dashboard-container[data-theme=\"system\"] {
{$dark_css_block}
  }
}

/* Dashboard gap variable */
.sfx-dashboard-container {
    --sfx-dashboard-gap: {$dashboard_gap}px;
}

/* Grid layouts */
.sfx-stats-grid {
    grid-template-columns: repeat({$stats_columns}, 1fr);
    gap: var(--sfx-dashboard-gap);
}
.sfx-quicklinks-grid {
    grid-template-columns: repeat({$quicklinks_columns}, 1fr);
    gap: var(--sfx-dashboard-gap);
}
.sfx-content-grid {
    gap: var(--sfx-dashboard-gap);
}
.sfx-dashboard-widgets-grid {
    gap: var(--sfx-dashboard-gap);
}
.sfx-status-bar {
    gap: var(--sfx-dashboard-gap);
}
.sfx-welcome-section {
    margin-bottom: var(--sfx-dashboard-gap);
}
.sfx-stats-grid {
    margin-bottom: var(--sfx-dashboard-gap);
}
.sfx-content-grid {
    margin-bottom: var(--sfx-dashboard-gap);
}
.sfx-form-submissions-section,
.sfx-wp-dashboard-widgets-section,
.sfx-note-section {
    margin-top: var(--sfx-dashboard-gap);
}
@media (max-width: 1024px) {
    .sfx-stats-grid, .sfx-quicklinks-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .sfx-stats-grid, .sfx-quicklinks-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .sfx-stats-grid {
        grid-template-columns: 1fr;
    }
    .sfx-quicklinks-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Color transition for smooth theme switching */
.sfx-dashboard-container,
.sfx-dashboard-container * {
    transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}
        ";

        wp_add_inline_style('sfx-custom-dashboard', $custom_css);
    }

    /**
     * Build mode-specific CSS variables
     *
     * @param array $palette Color palette
     * @param bool $shadow_enabled Whether shadows are enabled
     * @param int $shadow_intensity Shadow intensity level (0-3)
     * @param string $header_bg Header background value
     * @param bool $card_shadow_enabled Whether card shadows are enabled
     * @param string $mode 'light' or 'dark'
     * @return array<string, string>
     */
    private static function build_mode_vars(array $palette, bool $shadow_enabled, int $shadow_intensity, string $header_bg, bool $card_shadow_enabled, string $mode): array
    {
        $is_dark = $mode === 'dark';
        
        $shadows = $is_dark ? [
            0 => 'none',
            1 => '0 2px 4px rgba(0, 0, 0, 0.3)',
            2 => '0 4px 6px rgba(0, 0, 0, 0.4)',
            3 => '0 8px 16px rgba(0, 0, 0, 0.5)',
        ] : [
            0 => 'none',
            1 => '0 2px 4px rgba(0, 0, 0, 0.08)',
            2 => '0 4px 6px rgba(0, 0, 0, 0.1)',
            3 => '0 8px 16px rgba(0, 0, 0, 0.15)',
        ];
        
        $header_shadow_opacity = $is_dark ? '0.3' : '0.1';
        
        return [
            'palette_css' => ColorUtils::paletteToCss($palette),
            'shadow' => $shadow_enabled ? ($shadows[$shadow_intensity] ?? $shadows[1]) : 'none',
            'header_shadow' => ($shadow_enabled && $shadow_intensity > 0) ? "0 4px 6px rgba(0, 0, 0, {$header_shadow_opacity})" : 'none',
            'header_bg' => $header_bg,
            'card_shadow' => $card_shadow_enabled ? ($is_dark ? '0 2px 4px rgba(0, 0, 0, 0.3)' : '0 2px 4px rgba(0, 0, 0, 0.08)') : 'none',
        ];
    }

    /**
     * Generate CSS variables block from mode and shared variables
     *
     * @param array<string, string> $mode_vars Mode-specific variables
     * @param array<string, mixed> $shared_vars Shared variables
     * @return string CSS block content
     */
    private static function generate_css_vars_block(array $mode_vars, array $shared_vars): string
    {
        return "{$mode_vars['palette_css']}  --sfx-radius: {$shared_vars['radius']}px;
  --sfx-border-width: {$shared_vars['border_width']}px;
  --sfx-shadow: {$mode_vars['shadow']};
  --sfx-header-shadow: {$mode_vars['header_shadow']};
  --sfx-header-bg: {$mode_vars['header_bg']};
  --sfx-card-border-width: {$shared_vars['card_border_width']}px;
  --sfx-card-radius: {$shared_vars['card_radius']}px;
  --sfx-card-shadow: {$mode_vars['card_shadow']};
  
  /* Legacy compatibility variables */
  --primary-color: hsl(var(--primary));
  --secondary-color: {$shared_vars['secondary']};
  --accent-color: {$shared_vars['accent']};
  --border-radius: var(--sfx-radius);
  --border-width: var(--sfx-border-width);
  --border-color: {$shared_vars['border_color']};
  --box-shadow: var(--sfx-shadow);
  --header-shadow: var(--sfx-header-shadow);
  --header-bg-color: var(--sfx-header-bg);
  --header-text-color: {$shared_vars['header_text_color']};
  --card-bg-color: {$shared_vars['card_bg_color']};
  --card-text-color: {$shared_vars['card_text_color']};
  --card-border-width: var(--sfx-card-border-width);
  --card-border-color: {$shared_vars['card_border_color']};
  --card-border-radius: var(--sfx-card-radius);
  --card-shadow: var(--sfx-card-shadow);
  --card-hover-bg: {$shared_vars['card_hover_bg']};
  --card-hover-text: {$shared_vars['card_hover_text']};
  --card-hover-border: {$shared_vars['card_hover_border']};";
    }
}

