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
        $options = get_option(Settings::$OPTION_NAME, []);
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

        // Enqueue WordPress media library
        wp_enqueue_media();

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

        if (file_exists($js_file)) {
            wp_enqueue_script(
                'sfx-custom-dashboard-admin',
                get_stylesheet_directory_uri() . '/inc/CustomDashboard/assets/admin-script.js',
                ['jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-mouse', 'jquery-ui-sortable', 'media-editor'],
                filemtime($js_file),
                true
            );

            // Pass option name to JavaScript for dynamic field management
            wp_localize_script('sfx-custom-dashboard-admin', 'sfxDashboardAdmin', [
                'optionName' => Settings::$OPTION_NAME,
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
     * Enqueue dashboard page assets
     *
     * @return void
     */
    public static function enqueue_dashboard_assets(): void
    {
        if (!self::is_dashboard_page() || !self::is_custom_dashboard_enabled()) {
            return;
        }

        $css_file = get_stylesheet_directory() . '/inc/CustomDashboard/assets/dashboard-style.css';
        $js_file = get_stylesheet_directory() . '/inc/CustomDashboard/assets/dashboard-script.js';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'sfx-custom-dashboard',
                get_stylesheet_directory_uri() . '/inc/CustomDashboard/assets/dashboard-style.css',
                [],
                filemtime($css_file)
            );

            // Inject custom brand CSS variables
            self::inject_brand_styles();
        }

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
        $options = get_option(Settings::$OPTION_NAME, []);
        
        $primary = $options['brand_primary_color'] ?? '#667eea';
        $secondary = $options['brand_secondary_color'] ?? '#764ba2';
        $accent = $options['brand_accent_color'] ?? '#f093fb';
        $radius = $options['brand_border_radius'] ?? 8;
        $border_width = $options['brand_border_width'] ?? 0;
        $shadow_enabled = !empty($options['brand_shadow_enabled']);
        $shadow_intensity = absint($options['brand_shadow_intensity'] ?? 1);
        $color_mode_default = $options['color_mode_default'] ?? 'light';
        
        // Generate semantic color palettes
        $light_palette = ColorUtils::generatePalette($primary, 'light');
        $dark_palette = ColorUtils::generatePalette($primary, 'dark');
        
        // Color map for brand color selections
        $brand_colors = Settings::get_brand_colors();
        
        $header_gradient = !empty($options['brand_header_gradient']);
        $header_gradient_start_key = $options['brand_header_gradient_start'] ?? 'primary';
        $header_gradient_end_key = $options['brand_header_gradient_end'] ?? 'secondary';
        $header_bg_key = $options['brand_header_bg_color'] ?? 'primary';
        
        $gradient_start = self::resolve_color($header_gradient_start_key, $brand_colors, $primary);
        $gradient_end = self::resolve_color($header_gradient_end_key, $brand_colors, $secondary);
        $header_bg_solid = self::resolve_color($header_bg_key, $brand_colors, $primary);
        
        // Generate header background (gradient or solid)
        $header_bg_light = $header_gradient 
            ? "linear-gradient(135deg, {$gradient_start} 0%, {$gradient_end} 100%)"
            : $header_bg_solid;
        
        // For dark mode, adjust the gradient slightly
        $header_bg_dark = $header_gradient 
            ? ColorUtils::generateHeaderGradient($primary, 'dark')
            : ColorUtils::hslToHex($dark_palette['primary']['h'], $dark_palette['primary']['s'], $dark_palette['primary']['l']);

        // Define shadow values based on intensity
        $shadows_light = [
            0 => 'none',
            1 => '0 2px 4px rgba(0, 0, 0, 0.08)',
            2 => '0 4px 6px rgba(0, 0, 0, 0.1)',
            3 => '0 8px 16px rgba(0, 0, 0, 0.15)',
        ];
        
        $shadows_dark = [
            0 => 'none',
            1 => '0 2px 4px rgba(0, 0, 0, 0.3)',
            2 => '0 4px 6px rgba(0, 0, 0, 0.4)',
            3 => '0 8px 16px rgba(0, 0, 0, 0.5)',
        ];

        $shadow_value_light = $shadow_enabled ? ($shadows_light[$shadow_intensity] ?? $shadows_light[1]) : 'none';
        $shadow_value_dark = $shadow_enabled ? ($shadows_dark[$shadow_intensity] ?? $shadows_dark[1]) : 'none';

        // Card styling options
        $card_border_width = $options['card_border_width'] ?? 2;
        $card_radius = $options['card_border_radius'] ?? 8;
        $card_shadow_enabled = !empty($options['card_shadow_enabled']);
        
        $card_shadow_light = $card_shadow_enabled ? '0 2px 4px rgba(0, 0, 0, 0.08)' : 'none';
        $card_shadow_dark = $card_shadow_enabled ? '0 2px 4px rgba(0, 0, 0, 0.3)' : 'none';
        
        // Column and gap settings
        $stats_columns = max(2, min(6, absint($options['stats_columns'] ?? 4)));
        $stats_gap = max(5, min(50, absint($options['stats_gap'] ?? 20)));
        $quicklinks_columns = max(2, min(6, absint($options['quicklinks_columns'] ?? 4)));
        $quicklinks_gap = max(5, min(50, absint($options['quicklinks_gap'] ?? 15)));

        // Generate CSS for light mode palette
        $light_css_vars = ColorUtils::paletteToCss($light_palette);
        $dark_css_vars = ColorUtils::paletteToCss($dark_palette);

        $custom_css = "
/* Light Mode (default) */
body.index-php #wpcontent,
body.index-php:has([data-theme=\"light\"]) #wpcontent,
.sfx-dashboard-container,
.sfx-dashboard-container[data-theme=\"light\"] {
{$light_css_vars}
  --sfx-radius: {$radius}px;
  --sfx-border-width: {$border_width}px;
  --sfx-shadow: {$shadow_value_light};
  --sfx-header-shadow: " . ($shadow_enabled && $shadow_intensity > 0 ? '0 4px 6px rgba(0, 0, 0, 0.1)' : 'none') . ";
  --sfx-header-bg: {$header_bg_light};
  --sfx-card-border-width: {$card_border_width}px;
  --sfx-card-radius: {$card_radius}px;
  --sfx-card-shadow: {$card_shadow_light};
  
  /* Legacy compatibility variables */
  --primary-color: hsl(var(--primary));
  --secondary-color: {$secondary};
  --accent-color: {$accent};
  --border-radius: var(--sfx-radius);
  --border-width: var(--sfx-border-width);
  --border-color: hsl(var(--border));
  --box-shadow: var(--sfx-shadow);
  --header-shadow: var(--sfx-header-shadow);
  --header-bg-color: var(--sfx-header-bg);
  --header-text-color: hsl(var(--primary-foreground));
  --card-bg-color: hsl(var(--card));
  --card-text-color: hsl(var(--card-foreground));
  --card-border-width: var(--sfx-card-border-width);
  --card-border-color: hsl(var(--border));
  --card-border-radius: var(--sfx-card-radius);
  --card-shadow: var(--sfx-card-shadow);
  --card-hover-bg: hsl(var(--primary));
  --card-hover-text: hsl(var(--primary-foreground));
  --card-hover-border: hsl(var(--primary));
}

/* Dark Mode */
body.index-php:has([data-theme=\"dark\"]) #wpcontent,
.sfx-dashboard-container[data-theme=\"dark\"] {
{$dark_css_vars}
  --sfx-radius: {$radius}px;
  --sfx-border-width: {$border_width}px;
  --sfx-shadow: {$shadow_value_dark};
  --sfx-header-shadow: " . ($shadow_enabled && $shadow_intensity > 0 ? '0 4px 6px rgba(0, 0, 0, 0.3)' : 'none') . ";
  --sfx-header-bg: {$header_bg_dark};
  --sfx-card-border-width: {$card_border_width}px;
  --sfx-card-radius: {$card_radius}px;
  --sfx-card-shadow: {$card_shadow_dark};
  
  /* Legacy compatibility variables */
  --primary-color: hsl(var(--primary));
  --secondary-color: {$secondary};
  --accent-color: {$accent};
  --border-radius: var(--sfx-radius);
  --border-width: var(--sfx-border-width);
  --border-color: hsl(var(--border));
  --box-shadow: var(--sfx-shadow);
  --header-shadow: var(--sfx-header-shadow);
  --header-bg-color: var(--sfx-header-bg);
  --header-text-color: hsl(var(--primary-foreground));
  --card-bg-color: hsl(var(--card));
  --card-text-color: hsl(var(--card-foreground));
  --card-border-width: var(--sfx-card-border-width);
  --card-border-color: hsl(var(--border));
  --card-border-radius: var(--sfx-card-radius);
  --card-shadow: var(--sfx-card-shadow);
  --card-hover-bg: hsl(var(--primary));
  --card-hover-text: hsl(var(--primary-foreground));
  --card-hover-border: hsl(var(--primary));
}

/* System preference (when data-theme='system') */
@media (prefers-color-scheme: dark) {
  body.index-php:has([data-theme=\"system\"]) #wpcontent,
  .sfx-dashboard-container[data-theme=\"system\"] {
{$dark_css_vars}
    --sfx-radius: {$radius}px;
    --sfx-border-width: {$border_width}px;
    --sfx-shadow: {$shadow_value_dark};
    --sfx-header-shadow: " . ($shadow_enabled && $shadow_intensity > 0 ? '0 4px 6px rgba(0, 0, 0, 0.3)' : 'none') . ";
    --sfx-header-bg: {$header_bg_dark};
    --sfx-card-border-width: {$card_border_width}px;
    --sfx-card-radius: {$card_radius}px;
    --sfx-card-shadow: {$card_shadow_dark};
    
    /* Legacy compatibility variables */
    --primary-color: hsl(var(--primary));
    --secondary-color: {$secondary};
    --accent-color: {$accent};
    --border-radius: var(--sfx-radius);
    --border-width: var(--sfx-border-width);
    --border-color: hsl(var(--border));
    --box-shadow: var(--sfx-shadow);
    --header-shadow: var(--sfx-header-shadow);
    --header-bg-color: var(--sfx-header-bg);
    --header-text-color: hsl(var(--primary-foreground));
    --card-bg-color: hsl(var(--card));
    --card-text-color: hsl(var(--card-foreground));
    --card-border-width: var(--sfx-card-border-width);
    --card-border-color: hsl(var(--border));
    --card-border-radius: var(--sfx-card-radius);
    --card-shadow: var(--sfx-card-shadow);
    --card-hover-bg: hsl(var(--primary));
    --card-hover-text: hsl(var(--primary-foreground));
    --card-hover-border: hsl(var(--primary));
  }
}

/* Grid layouts */
.sfx-stats-grid {
    grid-template-columns: repeat({$stats_columns}, 1fr);
    gap: {$stats_gap}px;
}
.sfx-quicklinks-grid {
    grid-template-columns: repeat({$quicklinks_columns}, 1fr);
    gap: {$quicklinks_gap}px;
}
@media (max-width: 1024px) {
    .sfx-stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .sfx-quicklinks-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .sfx-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .sfx-quicklinks-grid {
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
}

