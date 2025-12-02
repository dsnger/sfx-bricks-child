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
                    'title' => __('Link Title', 'sfxtheme'),
                    'url' => __('URL or admin page', 'sfxtheme'),
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
        
        // Color map for brand color selections
        $brand_colors = Settings::get_brand_colors();
        $color_map = array_map(function($item) {
            return $item['color'];
        }, $brand_colors);
        
        $border_color_key = $options['brand_border_color'] ?? 'light-gray';
        $header_gradient = !empty($options['brand_header_gradient']);
        $header_gradient_start_key = $options['brand_header_gradient_start'] ?? 'primary';
        $header_gradient_end_key = $options['brand_header_gradient_end'] ?? 'secondary';
        $header_bg_key = $options['brand_header_bg_color'] ?? 'primary';
        $header_text_key = $options['brand_header_text_color'] ?? 'white';
        
        $border_color = $color_map[$border_color_key] ?? $color_map['light-gray'];
        $gradient_start = $color_map[$header_gradient_start_key] ?? $color_map['primary'];
        $gradient_end = $color_map[$header_gradient_end_key] ?? $color_map['secondary'];
        $header_bg_solid = $color_map[$header_bg_key] ?? $color_map['primary'];
        $header_text = $color_map[$header_text_key] ?? $color_map['white'];
        
        // Generate header background (gradient or solid)
        $header_bg = $header_gradient 
            ? "linear-gradient(135deg, {$gradient_start} 0%, {$gradient_end} 100%)"
            : $header_bg_solid;

        // Define shadow values based on intensity
        $shadows = [
            0 => 'none',
            1 => '0 2px 4px rgba(0, 0, 0, 0.08)',
            2 => '0 4px 6px rgba(0, 0, 0, 0.1)',
            3 => '0 8px 16px rgba(0, 0, 0, 0.15)',
        ];

        $shadow_value = $shadow_enabled ? ($shadows[$shadow_intensity] ?? $shadows[1]) : 'none';
        $header_shadow = $shadow_enabled && $shadow_intensity > 0 ? '0 4px 6px rgba(0, 0, 0, 0.1)' : 'none';

        // Card styling options
        $card_bg_key = $options['card_background_color'] ?? 'white';
        $card_text_key = $options['card_text_color'] ?? 'dark-gray';
        $card_border_width = $options['card_border_width'] ?? 2;
        $card_border_color_key = $options['card_border_color'] ?? 'light-gray';
        $card_radius = $options['card_border_radius'] ?? 8;
        $card_shadow_enabled = !empty($options['card_shadow_enabled']);
        
        // Card hover states
        $card_hover_bg_key = $options['card_hover_background_color'] ?? 'primary';
        $card_hover_text_key = $options['card_hover_text_color'] ?? 'white';
        $card_hover_border_key = $options['card_hover_border_color'] ?? 'primary';
        
        $card_bg = $color_map[$card_bg_key] ?? $color_map['white'];
        $card_text = $color_map[$card_text_key] ?? $color_map['dark-gray'];
        $card_border_color = $color_map[$card_border_color_key] ?? $color_map['light-gray'];
        $card_shadow = $card_shadow_enabled ? '0 2px 4px rgba(0, 0, 0, 0.08)' : 'none';
        $card_hover_bg = $color_map[$card_hover_bg_key] ?? $color_map['primary'];
        $card_hover_text = $color_map[$card_hover_text_key] ?? $color_map['white'];
        $card_hover_border = $color_map[$card_hover_border_key] ?? $color_map['primary'];
        
        // Column and gap settings
        $stats_columns = max(2, min(6, absint($options['stats_columns'] ?? 4)));
        $stats_gap = max(5, min(50, absint($options['stats_gap'] ?? 20)));
        $quicklinks_columns = max(2, min(6, absint($options['quicklinks_columns'] ?? 4)));
        $quicklinks_gap = max(5, min(50, absint($options['quicklinks_gap'] ?? 15)));

        $custom_css = "
        :root {
            --sfx-primary: {$primary};
            --sfx-secondary: {$secondary};
            --sfx-accent: {$accent};
            --sfx-radius: {$radius}px;
            --sfx-border-width: {$border_width}px;
            --sfx-border-color: {$border_color};
            --sfx-shadow: {$shadow_value};
            --sfx-header-shadow: {$header_shadow};
            --sfx-header-bg: {$header_bg};
            --sfx-header-text: {$header_text};
            --sfx-card-bg: {$card_bg};
            --sfx-card-text: {$card_text};
            --sfx-card-border-width: {$card_border_width}px;
            --sfx-card-border-color: {$card_border_color};
            --sfx-card-radius: {$card_radius}px;
            --sfx-card-shadow: {$card_shadow};
            --sfx-card-hover-bg: {$card_hover_bg};
            --sfx-card-hover-text: {$card_hover_text};
            --sfx-card-hover-border: {$card_hover_border};
        }
        .sfx-dashboard-container {
            --primary-color: var(--sfx-primary);
            --secondary-color: var(--sfx-secondary);
            --accent-color: var(--sfx-accent);
            --border-radius: var(--sfx-radius);
            --border-width: var(--sfx-border-width);
            --border-color: var(--sfx-border-color);
            --box-shadow: var(--sfx-shadow);
            --header-shadow: var(--sfx-header-shadow);
            --header-bg-color: var(--sfx-header-bg);
            --header-text-color: var(--sfx-header-text);
            --card-bg-color: var(--sfx-card-bg);
            --card-text-color: var(--sfx-card-text);
            --card-border-width: var(--sfx-card-border-width);
            --card-border-color: var(--sfx-card-border-color);
            --card-border-radius: var(--sfx-card-radius);
            --card-shadow: var(--sfx-card-shadow);
            --card-hover-bg: var(--sfx-card-hover-bg);
            --card-hover-text: var(--sfx-card-hover-text);
            --card-hover-border: var(--sfx-card-hover-border);
        }
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
        ";

        wp_add_inline_style('sfx-custom-dashboard', $custom_css);
    }
}

