<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Settings management for Custom Dashboard
 *
 * @package SFX_Bricks_Child_Theme
 */
class Settings
{
    /**
     * Option group name for settings registration
     * @var string
     */
    public static string $option_group = 'sfx_custom_dashboard';

    /**
     * Option name for storing settings in database
     * @var string
     */
    public static string $option_name = 'sfx_custom_dashboard';

    /**
     * Default SVG icon for custom quicklinks (Heroicons link icon)
     */
    public const DEFAULT_CUSTOM_ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>';

    /**
     * Tab to fields mapping - single source of truth
     * @var array<string, array<string>>|null
     */
    private static ?array $tab_fields_map = null;

    /**
     * Register all settings for custom dashboard
     *
     * @return void
     */
    public static function register(): void
    {
        // Register settings through consolidated system
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    /**
     * Get tab to fields mapping (single source of truth)
     *
     * @return array<string, array<string>>
     */
    public static function get_tab_fields_map(): array
    {
        if (self::$tab_fields_map !== null) {
            return self::$tab_fields_map;
        }

        self::$tab_fields_map = [
            'general' => [
                'enable_custom_dashboard',
                'dashboard_welcome_title',
                'dashboard_welcome_subtitle',
            ],
            'sections' => [
                'show_updates_section',
                'show_site_health_section',
                'show_stats_section',
                'show_quicklinks_section',
                'show_contact_section',
                'show_form_submissions_section',
                'form_submissions_limit',
                'show_dashboard_widgets',
                'enabled_dashboard_widgets',
                'show_note_section',
                'note_title',
                'note_content',
            ],
            'stats' => [
                'stats_items',
            ],
            'quicklinks' => [
                'quicklinks_sortable',
            ],
            'contact' => [
                'contact_card_title',
                'contact_card_subtitle',
                'contact_company',
                'contact_email',
                'contact_phone',
                'contact_website',
                'contact_address',
            ],
            'brand' => [
                'color_mode_default',
                'allow_user_mode_switch',
                'brand_primary_color',
                'brand_secondary_color',
                'brand_accent_color',
                'brand_success_color',
                'brand_warning_color',
                'brand_error_color',
                'brand_border_radius',
                'brand_border_width',
                'brand_border_color',
                'brand_shadow_enabled',
                'brand_shadow_intensity',
                'brand_header_gradient',
                'brand_header_gradient_start',
                'brand_header_gradient_end',
                'brand_header_bg_color',
                'brand_header_text_color',
                'brand_logo',
                'card_background_color',
                'card_text_color',
                'card_border_width',
                'card_border_color',
                'card_border_radius',
                'card_shadow_enabled',
                'card_hover_background_color',
                'card_hover_text_color',
                'card_hover_border_color',
                'dashboard_gap',
                'stats_columns',
                'quicklinks_columns',
                'dashboard_custom_css',
            ],
        ];

        return self::$tab_fields_map;
    }

    /**
     * Get brand sub-tab to fields mapping
     *
     * @return array<string, array<string>>
     */
    public static function get_brand_subtab_fields(): array
    {
        return [
            'color_mode' => ['color_mode_default', 'allow_user_mode_switch'],
            'colors' => ['brand_primary_color', 'brand_secondary_color', 'brand_accent_color', 'brand_success_color', 'brand_warning_color', 'brand_error_color'],
            'header' => ['brand_header_gradient', 'brand_header_gradient_start', 'brand_header_gradient_end', 'brand_header_bg_color', 'brand_header_text_color', 'brand_logo'],
            'cards' => ['card_background_color', 'card_text_color', 'card_border_width', 'card_border_color', 'card_border_radius', 'card_shadow_enabled', 'card_hover_background_color', 'card_hover_text_color', 'card_hover_border_color', 'quicklinks_columns'],
            'general_style' => ['brand_border_radius', 'brand_border_width', 'brand_border_color', 'brand_shadow_enabled', 'brand_shadow_intensity', 'dashboard_gap', 'stats_columns'],
            'custom_css' => ['dashboard_custom_css'],
        ];
    }

    /**
     * Get allowed SVG tags and attributes for sanitization
     *
     * @return array<string, array<string, bool>>
     */
    public static function get_allowed_svg_tags(): array
    {
        return [
            'svg' => [
                'xmlns' => true,
                'width' => true,
                'height' => true,
                'viewbox' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
                'class' => true,
            ],
            'path' => [
                'd' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'circle' => [
                'cx' => true,
                'cy' => true,
                'r' => true,
                'fill' => true,
                'stroke' => true,
            ],
            'rect' => [
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'rx' => true,
                'ry' => true,
                'fill' => true,
                'stroke' => true,
            ],
            'line' => [
                'x1' => true,
                'y1' => true,
                'x2' => true,
                'y2' => true,
                'stroke' => true,
            ],
            'polyline' => [
                'points' => true,
                'fill' => true,
                'stroke' => true,
            ],
            'polygon' => [
                'points' => true,
                'fill' => true,
                'stroke' => true,
            ],
        ];
    }

    /**
     * Get available brand color options
     * 
     * Returns colors organized in groups:
     * - Brand colors (user-defined)
     * - Semantic colors (auto-generated, use CSS variables)
     * - Static colors (fixed values)
     *
     * @return array<string, array<string, string>>
     */
    public static function get_brand_colors(): array
    {
        $options = get_option(self::$option_name, []);
        $primary = $options['brand_primary_color'] ?? '#667eea';
        $secondary = $options['brand_secondary_color'] ?? '#764ba2';
        $accent = $options['brand_accent_color'] ?? '#f093fb';
        
        return [
            // Brand Colors (user-defined)
            'primary' => ['label' => __('Primary', 'sfxtheme'), 'color' => $primary, 'group' => 'brand'],
            'secondary' => ['label' => __('Secondary', 'sfxtheme'), 'color' => $secondary, 'group' => 'brand'],
            'accent' => ['label' => __('Accent', 'sfxtheme'), 'color' => $accent, 'group' => 'brand'],
            
            // Semantic Colors (auto-generated, CSS variable references)
            'background' => ['label' => __('Background', 'sfxtheme'), 'color' => 'var(--background)', 'group' => 'semantic', 'is_variable' => true],
            'foreground' => ['label' => __('Foreground', 'sfxtheme'), 'color' => 'var(--foreground)', 'group' => 'semantic', 'is_variable' => true],
            'card' => ['label' => __('Card', 'sfxtheme'), 'color' => 'var(--card)', 'group' => 'semantic', 'is_variable' => true],
            'card-foreground' => ['label' => __('Card Foreground', 'sfxtheme'), 'color' => 'var(--card-foreground)', 'group' => 'semantic', 'is_variable' => true],
            'muted' => ['label' => __('Muted', 'sfxtheme'), 'color' => 'var(--muted)', 'group' => 'semantic', 'is_variable' => true],
            'muted-foreground' => ['label' => __('Muted Foreground', 'sfxtheme'), 'color' => 'var(--muted-foreground)', 'group' => 'semantic', 'is_variable' => true],
            'border' => ['label' => __('Border', 'sfxtheme'), 'color' => 'var(--border)', 'group' => 'semantic', 'is_variable' => true],
            'primary-foreground' => ['label' => __('Primary Foreground', 'sfxtheme'), 'color' => 'var(--primary-foreground)', 'group' => 'semantic', 'is_variable' => true],
            'secondary-color' => ['label' => __('Secondary (Semantic)', 'sfxtheme'), 'color' => 'var(--secondary)', 'group' => 'semantic', 'is_variable' => true],
            'secondary-foreground' => ['label' => __('Secondary Foreground', 'sfxtheme'), 'color' => 'var(--secondary-foreground)', 'group' => 'semantic', 'is_variable' => true],
            
            // Static Colors (fixed values)
            'white' => ['label' => __('White', 'sfxtheme'), 'color' => '#ffffff', 'group' => 'static'],
            'black' => ['label' => __('Black', 'sfxtheme'), 'color' => '#000000', 'group' => 'static'],
            'light-gray' => ['label' => __('Light Gray', 'sfxtheme'), 'color' => '#e2e8f0', 'group' => 'static'],
            'gray' => ['label' => __('Gray', 'sfxtheme'), 'color' => '#94a3b8', 'group' => 'static'],
            'dark-gray' => ['label' => __('Dark Gray', 'sfxtheme'), 'color' => '#475569', 'group' => 'static'],
        ];
    }

    /**
     * Get list of all valid brand color keys for sanitization
     *
     * @return array<int, string>
     */
    public static function get_brand_color_keys(): array
    {
        return array_keys(self::get_brand_colors());
    }

    /**
     * Get default brand colors for reset functionality
     *
     * @return array<string, string>
     */
    public static function get_default_brand_colors(): array
    {
        return [
            'brand_primary_color' => '#00DBD0',
            'brand_secondary_color' => '#138680',
            'brand_accent_color' => '#FF8624',
            'brand_success_color' => '#22c55e',
            'brand_warning_color' => '#f59e0b',
            'brand_error_color' => '#ef4444',
        ];
    }

    /**
     * Get default header settings for reset functionality
     *
     * @return array<string, mixed>
     */
    public static function get_default_header_settings(): array
    {
        return [
            'brand_header_gradient' => 1,
            'brand_header_gradient_start' => 'primary',
            'brand_header_gradient_end' => 'secondary',
            'brand_header_bg_color' => 'primary',
            'brand_header_text_color' => 'primary-foreground',
            'brand_logo' => '',
        ];
    }

    /**
     * Get default card/quicklinks styling settings for reset functionality
     *
     * @return array<string, mixed>
     */
    public static function get_default_card_settings(): array
    {
        return [
            'card_background_color' => 'secondary-color',
            'card_text_color' => 'secondary-foreground',
            'card_border_width' => 2,
            'card_border_color' => 'border',
            'card_border_radius' => 8,
            'card_shadow_enabled' => 0,
            'card_hover_background_color' => 'primary',
            'card_hover_text_color' => 'primary-foreground',
            'card_hover_border_color' => 'primary',
            'quicklinks_columns' => 4,
        ];
    }

    /**
     * Get default dashboard layout settings for reset functionality
     *
     * @return array<string, mixed>
     */
    public static function get_default_layout_settings(): array
    {
        return [
            'brand_border_radius' => 8,
            'brand_border_width' => 1,
            'brand_border_color' => 'border',
            'brand_shadow_enabled' => 0,
            'brand_shadow_intensity' => 1,
            'dashboard_gap' => 15,
            'stats_columns' => 4,
        ];
    }

    /**
     * Get default dashboard CSS from the stylesheet file
     *
     * @return string
     */
    public static function get_default_dashboard_css(): string
    {
        $css_file = __DIR__ . '/assets/dashboard-style.css';
        
        if (file_exists($css_file)) {
            $css = file_get_contents($css_file);
            return $css !== false ? $css : '';
        }
        
        return '';
    }

    /**
     * Get CSS variables reference for the editor
     *
     * @return string
     */
    public static function get_css_variables_reference(): string
    {
        $variables = <<<'CSS'
/* ===== COLOR VARIABLES ===== */

/* Primary Colors (from Brand Settings) */
--primary: <hsl values>;           /* Primary brand color */
--primary-foreground: <hsl>;       /* Text on primary background */

/* Secondary Colors */
--secondary: <hsl values>;         /* Secondary/muted backgrounds */
--secondary-foreground: <hsl>;     /* Text on secondary background */

/* Semantic Colors */
--background: <hsl values>;        /* Page background */
--foreground: <hsl values>;        /* Main text color */
--card: <hsl values>;              /* Card backgrounds */
--card-foreground: <hsl>;          /* Card text color */
--muted: <hsl values>;             /* Muted/subtle backgrounds */
--muted-foreground: <hsl>;         /* Muted text */
--border: <hsl values>;            /* Border color */
--accent: <hsl values>;            /* Accent color */
--accent-foreground: <hsl>;        /* Text on accent */

/* Status Colors */
--destructive: <hsl values>;       /* Error/destructive actions */
--destructive-foreground: <hsl>;   /* Text on destructive */
--success: <hsl values>;           /* Success states */
--success-foreground: <hsl>;       /* Text on success */
--warning: <hsl values>;           /* Warning states */
--warning-foreground: <hsl>;       /* Text on warning */

/* ===== LEGACY VARIABLES ===== */
--primary-color: hsl(var(--primary));
--secondary-color: <hex>;
--accent-color: <hex>;
--border-color: hsl(var(--border));
--box-shadow: var(--sfx-shadow);

/* ===== LAYOUT VARIABLES ===== */
--sfx-radius: Xpx;                 /* Global border radius */
--sfx-border-width: Xpx;           /* Global border width */
--sfx-shadow: <shadow value>;      /* Global shadow */

/* Header */
--sfx-header-bg: <gradient/color>; /* Header background */
--sfx-header-shadow: <shadow>;     /* Header shadow */
--header-bg-color: var(--sfx-header-bg);
--header-text-color: <color>;      /* Header text */

/* Cards */
--sfx-card-border-width: Xpx;      /* Card border width */
--sfx-card-radius: Xpx;            /* Card border radius */
--sfx-card-shadow: <shadow>;       /* Card shadow */
--card-bg-color: <color>;          /* Card background */
--card-text-color: <color>;        /* Card text */
--card-border-color: <color>;      /* Card border */
--card-hover-bg: <color>;          /* Card hover background */
--card-hover-text: <color>;        /* Card hover text */
--card-hover-border: <color>;      /* Card hover border */

/* ===== EXAMPLE USAGE ===== */
/*
.my-custom-element {
    background: hsl(var(--primary));
    color: hsl(var(--primary-foreground));
    border: 1px solid hsl(var(--border));
    border-radius: var(--sfx-radius);
}

.my-custom-card {
    background: var(--card-bg-color);
    color: var(--card-text-color);
}
*/
CSS;
        
        return $variables;
    }

    /**
     * Get all available WordPress user roles
     *
     * @return array<string, string> Array of role slug => role display name
     */
    public static function get_available_roles(): array
    {
        global $wp_roles;

        if (!isset($wp_roles)) {
            $wp_roles = new \WP_Roles();
        }

        $roles = [];
        foreach ($wp_roles->roles as $role_slug => $role_data) {
            $roles[$role_slug] = translate_user_role($role_data['name']);
        }

        return $roles;
    }

    /**
     * Get default predefined quicklinks
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_default_quicklinks(): array
    {
        return [
            [
                'id' => 'new_post',
                'title' => __('New Post', 'sfxtheme'),
                'url' => 'post-new.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'new_page',
                'title' => __('New Page', 'sfxtheme'),
                'url' => 'post-new.php?post_type=page',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'media',
                'title' => __('Media', 'sfxtheme'),
                'url' => 'upload.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'themes',
                'title' => __('Themes', 'sfxtheme'),
                'url' => 'themes.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'plugins',
                'title' => __('Plugins', 'sfxtheme'),
                'url' => 'plugins.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'users',
                'title' => __('Users', 'sfxtheme'),
                'url' => 'users.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'settings',
                'title' => __('Settings', 'sfxtheme'),
                'url' => 'options-general.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'bricks',
                'title' => __('Bricks', 'sfxtheme'),
                'url' => 'admin.php?page=bricks',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'menus',
                'title' => __('Menus', 'sfxtheme'),
                'url' => 'nav-menus.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>',
                'enabled' => 1,
                'roles' => [],
            ],
            [
                'id' => 'contact_info',
                'title' => __('Contact Info', 'sfxtheme'),
                'url' => 'edit.php?post_type=sfx_contact_info',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>',
                'enabled' => 0,
                'roles' => [],
            ],
            [
                'id' => 'social_media',
                'title' => __('Social Media', 'sfxtheme'),
                'url' => 'edit.php?post_type=sfx_social_account',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>',
                'enabled' => 0,
                'roles' => [],
            ],
        ];
    }

    /**
     * Get field definitions
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_fields(): array
    {
        return [
            [
                'id' => 'enable_custom_dashboard',
                'label' => __('Enable Custom Dashboard', 'sfxtheme'),
                'description' => __('Enable the custom dashboard to replace the default WordPress dashboard.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 0,
            ],
            [
                'id' => 'dashboard_welcome_title',
                'label' => __('Welcome Title', 'sfxtheme'),
                'description' => __('The title text displayed at the top of the dashboard. Available placeholders: {user_name}, {first_name}, {last_name}, {username}', 'sfxtheme'),
                'type' => 'text',
                'default' => __('Welcome back, {user_name}! 👋', 'sfxtheme'),
            ],
            [
                'id' => 'dashboard_welcome_subtitle',
                'label' => __('Welcome Subtitle', 'sfxtheme'),
                'description' => __('The subtitle text displayed below the title. Available placeholders: {user_name}, {first_name}, {last_name}, {username}', 'sfxtheme'),
                'type' => 'text',
                'default' => __("Here's what's happening with your projects today.", 'sfxtheme'),
            ],
            [
                'id' => 'show_updates_section',
                'label' => __('Show Pending Updates', 'sfxtheme'),
                'description' => __('Display pending plugin, theme, and WordPress core updates with status indicator.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'show_site_health_section',
                'label' => __('Show Site Health', 'sfxtheme'),
                'description' => __('Display WordPress site health status indicator.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'show_stats_section',
                'label' => __('Show Stats Section', 'sfxtheme'),
                'description' => __('Display the statistics section with post, page, media, and user counts.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'stats_items',
                'label' => __('Statistics Cards', 'sfxtheme'),
                'description' => __('Enable, disable, and reorder stat cards. Drag to reorder.', 'sfxtheme'),
                'type' => 'stats_items',
                'default' => [],
            ],
            [
                'id' => 'show_quicklinks_section',
                'label' => __('Show Quick Actions Section', 'sfxtheme'),
                'description' => __('Display the quick actions section with links to common admin pages.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'show_contact_section',
                'label' => __('Show Contact Section', 'sfxtheme'),
                'description' => __('Display the contact information section.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'show_form_submissions_section',
                'label' => __('Show Form Submissions Section', 'sfxtheme'),
                'description' => __('Display recent Bricks form submissions.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'form_submissions_limit',
                'label' => __('Number of Form Submissions to Show', 'sfxtheme'),
                'description' => __('Maximum number of recent form submissions to display (1-20).', 'sfxtheme'),
                'type' => 'number',
                'default' => 5,
            ],
            [
                'id' => 'show_dashboard_widgets',
                'label' => __('Show WordPress Dashboard Widgets', 'sfxtheme'),
                'description' => __('Include WordPress dashboard widgets in your custom dashboard.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 0,
            ],
            [
                'id' => 'enabled_dashboard_widgets',
                'label' => __('Enabled Dashboard Widgets', 'sfxtheme'),
                'description' => __('Select which WordPress dashboard widgets to display.', 'sfxtheme'),
                'type' => 'dashboard_widgets',
                'default' => [],
            ],
            [
                'id' => 'contact_card_title',
                'label' => __('Contact Card Title', 'sfxtheme'),
                'description' => __('Title for the contact information card (e.g., "Created by", "Contact").', 'sfxtheme'),
                'type' => 'text',
                'default' => __('Contact', 'sfxtheme'),
            ],
            [
                'id' => 'contact_card_subtitle',
                'label' => __('Contact Card Subtitle', 'sfxtheme'),
                'description' => __('Optional subtitle text below the title.', 'sfxtheme'),
                'type' => 'text',
                'default' => '',
            ],
            [
                'id' => 'contact_company',
                'label' => __('Company Name', 'sfxtheme'),
                'description' => __('Your company or organization name.', 'sfxtheme'),
                'type' => 'text',
                'default' => '',
            ],
            [
                'id' => 'contact_email',
                'label' => __('Email Address', 'sfxtheme'),
                'description' => __('Contact email address.', 'sfxtheme'),
                'type' => 'email',
                'default' => '',
            ],
            [
                'id' => 'contact_phone',
                'label' => __('Phone Number', 'sfxtheme'),
                'description' => __('Contact phone number.', 'sfxtheme'),
                'type' => 'text',
                'default' => '',
            ],
            [
                'id' => 'contact_website',
                'label' => __('Website URL', 'sfxtheme'),
                'description' => __('Company website URL.', 'sfxtheme'),
                'type' => 'url',
                'default' => '',
            ],
            [
                'id' => 'contact_address',
                'label' => __('Physical Address', 'sfxtheme'),
                'description' => __('Company physical address.', 'sfxtheme'),
                'type' => 'textarea',
                'default' => '',
            ],
            // Color Mode Settings
            [
                'id' => 'color_mode_default',
                'label' => __('Default Color Mode', 'sfxtheme'),
                'description' => __('Choose the default color mode for the dashboard.', 'sfxtheme'),
                'type' => 'select',
                'default' => 'light',
                'options' => [
                    'light' => __('Light', 'sfxtheme'),
                    'dark' => __('Dark', 'sfxtheme'),
                    'system' => __('System (follow OS preference)', 'sfxtheme'),
                ],
            ],
            [
                'id' => 'allow_user_mode_switch',
                'label' => __('Allow Mode Switching', 'sfxtheme'),
                'description' => __('Show a toggle button on the dashboard allowing users to switch between light and dark modes.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'brand_primary_color',
                'label' => __('Primary Brand Color', 'sfxtheme'),
                'description' => __('Main brand color - all other colors will be auto-generated from this. Choose a color that represents your brand.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#00DBD0',
            ],
            [
                'id' => 'brand_secondary_color',
                'label' => __('Secondary Brand Color', 'sfxtheme'),
                'description' => __('Secondary brand color for hover states and highlights.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#138680',
            ],
            [
                'id' => 'brand_accent_color',
                'label' => __('Accent Color', 'sfxtheme'),
                'description' => __('Accent color for special elements.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#FF8624',
            ],
            // Status Colors
            [
                'id' => 'brand_success_color',
                'label' => __('Success Color', 'sfxtheme'),
                'description' => __('Color for success states, health indicators, and positive messages.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#22c55e',
            ],
            [
                'id' => 'brand_warning_color',
                'label' => __('Warning Color', 'sfxtheme'),
                'description' => __('Color for warnings, pending updates, and caution messages.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#f59e0b',
            ],
            [
                'id' => 'brand_error_color',
                'label' => __('Error Color', 'sfxtheme'),
                'description' => __('Color for errors, critical issues, and destructive actions.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#ef4444',
            ],
            [
                'id' => 'brand_border_radius',
                'label' => __('Border Radius', 'sfxtheme'),
                'description' => __('Border radius in pixels for cards and elements (0-50).', 'sfxtheme'),
                'type' => 'number',
                'default' => 8,
            ],
            [
                'id' => 'brand_border_width',
                'label' => __('Border Width', 'sfxtheme'),
                'description' => __('Border width in pixels for cards and elements (0-10).', 'sfxtheme'),
                'type' => 'number',
                'default' => 1,
            ],
            [
                'id' => 'brand_border_color',
                'label' => __('Border Color', 'sfxtheme'),
                'description' => __('Border color for cards and elements.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'border',
            ],
            [
                'id' => 'brand_shadow_enabled',
                'label' => __('Enable Shadows', 'sfxtheme'),
                'description' => __('Add shadow effects to cards and elements.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 0,
            ],
            [
                'id' => 'brand_shadow_intensity',
                'label' => __('Shadow Intensity', 'sfxtheme'),
                'description' => __('Shadow intensity level: 0 (none), 1 (light), 2 (medium), 3 (strong).', 'sfxtheme'),
                'type' => 'number',
                'default' => 1,
            ],
            [
                'id' => 'brand_header_gradient',
                'label' => __('Header Background Gradient', 'sfxtheme'),
                'description' => __('Enable gradient background for the header.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
            ],
            [
                'id' => 'brand_header_gradient_start',
                'label' => __('Gradient Start Color', 'sfxtheme'),
                'description' => __('First color of the gradient (left side).', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'primary',
            ],
            [
                'id' => 'brand_header_gradient_end',
                'label' => __('Gradient End Color', 'sfxtheme'),
                'description' => __('Second color of the gradient (right side).', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'secondary',
            ],
            [
                'id' => 'brand_header_bg_color',
                'label' => __('Header Background Color', 'sfxtheme'),
                'description' => __('Background color when gradient is disabled.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'primary',
            ],
            [
                'id' => 'brand_header_text_color',
                'label' => __('Header Text Color', 'sfxtheme'),
                'description' => __('Text color for the welcome header section.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'primary-foreground',
            ],
            [
                'id' => 'brand_logo',
                'label' => __('Agency Logo', 'sfxtheme'),
                'description' => __('Upload your agency logo to display in the dashboard header. Recommended: PNG or SVG, max 200KB.', 'sfxtheme'),
                'type' => 'logo_upload',
                'default' => '',
            ],
            // Card Styling Options
            [
                'id' => 'card_background_color',
                'label' => __('Card Background Color', 'sfxtheme'),
                'description' => __('Background color for quick action cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'secondary-color',
            ],
            [
                'id' => 'card_text_color',
                'label' => __('Card Text Color', 'sfxtheme'),
                'description' => __('Text color for quick action cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'secondary-foreground',
            ],
            [
                'id' => 'card_border_width',
                'label' => __('Card Border Width', 'sfxtheme'),
                'description' => __('Border width for cards in pixels.', 'sfxtheme'),
                'type' => 'number',
                'default' => 2,
            ],
            [
                'id' => 'card_border_color',
                'label' => __('Card Border Color', 'sfxtheme'),
                'description' => __('Border color for quick action cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'border',
            ],
            [
                'id' => 'card_border_radius',
                'label' => __('Card Border Radius', 'sfxtheme'),
                'description' => __('Border radius for cards in pixels.', 'sfxtheme'),
                'type' => 'number',
                'default' => 8,
            ],
            [
                'id' => 'card_shadow_enabled',
                'label' => __('Card Shadow', 'sfxtheme'),
                'description' => __('Enable shadow effect for cards.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 0,
            ],
            // Card Hover States
            [
                'id' => 'card_hover_background_color',
                'label' => __('Hover Background Color', 'sfxtheme'),
                'description' => __('Background color when hovering over cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'primary',
            ],
            [
                'id' => 'card_hover_text_color',
                'label' => __('Hover Text Color', 'sfxtheme'),
                'description' => __('Text color when hovering over cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'primary-foreground',
            ],
            [
                'id' => 'card_hover_border_color',
                'label' => __('Hover Border Color', 'sfxtheme'),
                'description' => __('Border color when hovering over cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'primary',
            ],
            [
                'id' => 'quicklinks_columns',
                'label' => __('Quick Links Columns', 'sfxtheme'),
                'description' => __('Number of columns for quick action boxes (2-6).', 'sfxtheme'),
                'type' => 'number',
                'default' => 4,
            ],
            // Layout Settings
            [
                'id' => 'dashboard_gap',
                'label' => __('Dashboard Gap', 'sfxtheme'),
                'description' => __('Gap between all dashboard sections and grid items in pixels (10-50).', 'sfxtheme'),
                'type' => 'number',
                'default' => 15,
            ],
            [
                'id' => 'stats_columns',
                'label' => __('Stats Columns', 'sfxtheme'),
                'description' => __('Number of columns for statistics cards (2-6).', 'sfxtheme'),
                'type' => 'number',
                'default' => 4,
            ],
            // Note Section
            [
                'id' => 'show_note_section',
                'label' => __('Show Note Section', 'sfxtheme'),
                'description' => __('Display a custom note section at the bottom of the dashboard.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 0,
            ],
            [
                'id' => 'note_title',
                'label' => __('Note Title', 'sfxtheme'),
                'description' => __('Title for the note section (optional).', 'sfxtheme'),
                'type' => 'text',
                'default' => '',
            ],
            [
                'id' => 'note_content',
                'label' => __('Note Content', 'sfxtheme'),
                'description' => __('Content for the note section. HTML is allowed.', 'sfxtheme'),
                'type' => 'html_textarea',
                'default' => '',
            ],
            [
                'id' => 'quicklinks_sortable',
                'label' => __('Quick Links', 'sfxtheme'),
                'description' => __('Enable, disable, and reorder quick action links. Drag to reorder.', 'sfxtheme'),
                'type' => 'quicklinks_sortable',
                'default' => [],
            ],
            // Custom CSS
            [
                'id' => 'dashboard_custom_css',
                'label' => __('Additional CSS', 'sfxtheme'),
                'description' => __('Add custom CSS that will be applied on top of the default dashboard styles.', 'sfxtheme'),
                'type' => 'code_editor',
                'default' => '',
            ],
        ];
    }

    /**
     * Register settings
     *
     * @return void
     */
    public static function register_settings(): void
    {
        register_setting(self::$option_group, self::$option_name, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        // Main Settings Section
        add_settings_section(
            self::$option_name . '_main',
            __('General Settings', 'sfxtheme'),
            [self::class, 'render_main_section'],
            self::$option_group
        );

        // Section Visibility
        add_settings_section(
            self::$option_name . '_sections',
            __('Dashboard Sections', 'sfxtheme'),
            [self::class, 'render_sections_section'],
            self::$option_group
        );

        // Stats Settings
        add_settings_section(
            self::$option_name . '_stats',
            __('Statistics Settings', 'sfxtheme'),
            [self::class, 'render_stats_section'],
            self::$option_group
        );

        // Quicklinks Settings
        add_settings_section(
            self::$option_name . '_quicklinks',
            __('Quick Actions Settings', 'sfxtheme'),
            [self::class, 'render_quicklinks_section'],
            self::$option_group
        );

        // Contact Settings
        add_settings_section(
            self::$option_name . '_contact',
            __('Contact Information', 'sfxtheme'),
            [self::class, 'render_contact_section'],
            self::$option_group
        );

        // Brand Settings
        add_settings_section(
            self::$option_name . '_brand',
            __('Brand & Styling', 'sfxtheme'),
            [self::class, 'render_brand_section'],
            self::$option_group
        );

        // Add fields to sections
        foreach (self::get_fields() as $field) {
            $section = self::$option_name . '_main';
            
            // Determine section based on field
            if ($field['id'] === 'stats_items') {
                $section = self::$option_name . '_stats';
            } elseif (strpos($field['id'], 'show_') === 0 && strpos($field['id'], 'note_') !== 0) {
                $section = self::$option_name . '_sections';
            } elseif (strpos($field['id'], 'quicklinks') !== false && $field['id'] !== 'quicklinks_columns') {
                $section = self::$option_name . '_quicklinks';
            } elseif (strpos($field['id'], 'contact_') === 0) {
                $section = self::$option_name . '_contact';
            } elseif ($field['id'] === 'custom_quicklinks') {
                $section = self::$option_name . '_quicklinks';
            } elseif ($field['id'] === 'enabled_dashboard_widgets' || $field['id'] === 'show_dashboard_widgets') {
                $section = self::$option_name . '_sections';
            } elseif (in_array($field['id'], ['note_title', 'note_content', 'show_note_section', 'form_submissions_limit'])) {
                $section = self::$option_name . '_sections';
            } elseif (strpos($field['id'], 'brand_') === 0 || strpos($field['id'], 'card_') === 0 || strpos($field['id'], 'color_mode') === 0 || in_array($field['id'], ['dashboard_gap', 'stats_columns', 'quicklinks_columns', 'allow_user_mode_switch', 'dashboard_custom_css'])) {
                $section = self::$option_name . '_brand';
            }

            add_settings_field(
                $field['id'],
                $field['label'],
                [self::class, 'render_field'],
                self::$option_group,
                $section,
                $field
            );
        }
    }

    /**
     * Render section descriptions
     */
    public static function render_main_section(): void
    {
        echo '<p>' . esc_html__('Configure the main settings for your custom dashboard.', 'sfxtheme') . '</p>';
    }

    public static function render_sections_section(): void
    {
        echo '<p>' . esc_html__('Control which sections are visible on your custom dashboard.', 'sfxtheme') . '</p>';
    }

    public static function render_stats_section(): void
    {
        echo '<p>' . esc_html__('Choose which statistics to display on your dashboard.', 'sfxtheme') . '</p>';
    }

    public static function render_quicklinks_section(): void
    {
        echo '<p>' . esc_html__('Manage quick action links that appear on your dashboard.', 'sfxtheme') . '</p>';
    }

    public static function render_contact_section(): void
    {
        echo '<p>' . esc_html__('Add contact information to display on your dashboard.', 'sfxtheme') . '</p>';
    }

    public static function render_brand_section(): void
    {
        echo '<p>' . esc_html__('Customize the look and feel of your dashboard with brand colors and styling.', 'sfxtheme') . '</p>';
    }

    /**
     * Render field
     *
     * @param array<string, mixed> $args
     * @return void
     */
    public static function render_field(array $args): void
    {
        $options = get_option(self::$option_name, []);
        $id = esc_attr($args['id']);
        $type = $args['type'];
        $default = $args['default'];
        $value = isset($options[$id]) ? $options[$id] : $default;

        switch ($type) {
            case 'checkbox':
                $checked = !empty($value) ? 1 : 0;
                ?>
                <input type="checkbox" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                       value="1" 
                       <?php checked($checked, 1); ?> />
                <label for="<?php echo $id; ?>"><?php echo esc_html($args['description']); ?></label>
                <?php
                break;

            case 'text':
                ?>
                <input type="text" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       class="regular-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'html_textarea':
                $editor_id = str_replace('-', '_', $id); // wp_editor requires underscores
                $editor_settings = [
                    'textarea_name' => esc_attr(self::$option_name) . '[' . $id . ']',
                    'textarea_rows' => 8,
                    'media_buttons' => true,
                    'teeny'         => false,
                    'quicktags'     => true,
                    'tinymce'       => [
                        'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,hr,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,fullscreen,wp_adv',
                        'toolbar2' => 'styleselect,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
                    ],
                ];
                ?>
                <div class="sfx-editor-wrapper">
                    <?php wp_editor($value ?? '', $editor_id, $editor_settings); ?>
                    <p class="description" style="margin-top: 10px;"><?php echo esc_html($args['description']); ?></p>
                </div>
                <?php
                break;

            case 'code_editor':
                $css_value = $value ?? '';
                ?>
                <div class="sfx-code-editor-wrapper">
                    <textarea 
                        id="<?php echo esc_attr($id); ?>" 
                        name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>]" 
                        rows="20"
                        class="large-text sfx-code-editor"
                        placeholder="/* Add your custom CSS here */"
                        style="width: 100%; min-height: 300px; font-family: monospace;"><?php echo esc_textarea($css_value); ?></textarea>
                    <p class="description" style="margin-top: 10px;"><?php echo esc_html($args['description']); ?></p>
                </div>
                
                <!-- CSS Variables Reference -->
                <div class="sfx-css-reference" style="margin-top: 30px;">
                    <h3 style="margin-bottom: 15px;"><?php esc_html_e('Available CSS Variables', 'sfxtheme'); ?></h3>
                    <p class="description" style="margin-bottom: 10px;"><?php esc_html_e('These CSS variables are available for use in your custom CSS:', 'sfxtheme'); ?></p>
                    <div style="background: #f5f5f5; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">
                        <pre style="margin: 0; white-space: pre-wrap;"><?php echo esc_html(self::get_css_variables_reference()); ?></pre>
                    </div>
                </div>
                
                <!-- Default CSS Reference -->
                <div class="sfx-css-reference" style="margin-top: 30px;">
                    <h3 style="margin-bottom: 15px;"><?php esc_html_e('Default Dashboard CSS', 'sfxtheme'); ?></h3>
                    <p class="description" style="margin-bottom: 10px;"><?php esc_html_e('Reference: The complete default stylesheet (read-only):', 'sfxtheme'); ?></p>
                    <details style="background: #f5f5f5; border-radius: 4px;">
                        <summary style="padding: 15px; cursor: pointer; font-weight: 500;"><?php esc_html_e('Click to expand default CSS', 'sfxtheme'); ?></summary>
                        <div style="padding: 15px; padding-top: 0; font-family: monospace; font-size: 11px; max-height: 400px; overflow-y: auto;">
                            <pre style="margin: 0; white-space: pre-wrap;"><?php echo esc_html(self::get_default_dashboard_css()); ?></pre>
                        </div>
                    </details>
                </div>
                
                <style>
                    .sfx-code-editor-wrapper .CodeMirror {
                        min-height: 300px;
                        height: auto;
                        border: 1px solid #ddd;
                    }
                </style>
                <?php
                break;

            case 'email':
                ?>
                <input type="email" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       class="regular-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'url':
                ?>
                <input type="url" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       class="regular-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'textarea':
                ?>
                <textarea id="<?php echo $id; ?>" 
                          name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                          rows="3" 
                          class="large-text"><?php echo esc_textarea($value); ?></textarea>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'quicklinks_sortable':
                self::render_quicklinks_sortable_field($id, $value);
                break;

            case 'dashboard_widgets':
                self::render_dashboard_widgets_field($id, $value);
                break;

            case 'stats_items':
                self::render_stats_items_field($id, $value);
                break;

            case 'color':
                ?>
                <input type="color" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'select':
                $select_options = $args['options'] ?? [];
                ?>
                <select id="<?php echo $id; ?>" 
                        name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]">
                    <?php foreach ($select_options as $option_value => $option_label): ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>>
                            <?php echo esc_html($option_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'brand_color_select':
                $color_options = self::get_brand_colors();
                $current_color_data = $color_options[$value] ?? $color_options['primary'];
                $current_color = $current_color_data['color'] ?? '#667eea';
                $is_variable = !empty($current_color_data['is_variable']);
                
                // Group colors by their group
                $grouped = [
                    'brand' => [],
                    'semantic' => [],
                    'static' => [],
                ];
                foreach ($color_options as $key => $data) {
                    $group = $data['group'] ?? 'static';
                    $grouped[$group][$key] = $data;
                }
                
                $group_labels = [
                    'brand' => __('Brand Colors', 'sfxtheme'),
                    'semantic' => __('Semantic Colors (Auto-generated)', 'sfxtheme'),
                    'static' => __('Static Colors', 'sfxtheme'),
                ];
                ?>
                <div class="sfx-color-select-wrapper">
                    <span class="sfx-color-preview" style="background: <?php echo $is_variable ? 'hsl(' . esc_attr($current_color) . ')' : esc_attr($current_color); ?>;"></span>
                    <select id="<?php echo $id; ?>" 
                            name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]"
                            class="sfx-color-select"
                            data-colors='<?php echo esc_attr(wp_json_encode(array_map(function($opt) { return $opt['color']; }, $color_options))); ?>'>
                        <?php foreach ($grouped as $group_key => $group_options): ?>
                            <?php if (!empty($group_options)): ?>
                                <optgroup label="<?php echo esc_attr($group_labels[$group_key]); ?>">
                                    <?php foreach ($group_options as $option_value => $option_data): ?>
                                        <option value="<?php echo esc_attr($option_value); ?>" 
                                                data-color="<?php echo esc_attr($option_data['color']); ?>"
                                                data-is-variable="<?php echo !empty($option_data['is_variable']) ? '1' : '0'; ?>"
                                                <?php selected($value, $option_value); ?>>
                                            <?php echo esc_html($option_data['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'number':
                $min = 0;
                $max = 50;
                if ($id === 'form_submissions_limit') {
                    $min = 1;
                    $max = 20;
                } elseif ($id === 'brand_border_width') {
                    $min = 0;
                    $max = 10;
                } elseif ($id === 'brand_shadow_intensity') {
                    $min = 0;
                    $max = 3;
                }
                ?>
                <input type="number" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       min="<?php echo esc_attr($min); ?>" 
                       max="<?php echo esc_attr($max); ?>"
                       class="small-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'logo_upload':
                ?>
                <div class="sfx-logo-upload">
                    <input type="hidden" 
                           id="<?php echo $id; ?>" 
                           name="<?php echo esc_attr(self::$option_name); ?>[<?php echo $id; ?>]" 
                           value="<?php echo esc_attr($value); ?>" 
                           class="sfx-logo-url" />
                    <input type="file" 
                           id="<?php echo $id; ?>_file" 
                           name="<?php echo $id; ?>_file" 
                           accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/webp"
                           class="sfx-logo-file-input"
                           style="display: none;" />
                    <button type="button" class="button sfx-upload-logo-button">
                        <?php esc_html_e('Choose Logo', 'sfxtheme'); ?>
                    </button>
                    <button type="button" class="button sfx-remove-logo-button" <?php echo empty($value) ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e('Remove', 'sfxtheme'); ?>
                    </button>
                    <div class="sfx-logo-preview" <?php echo empty($value) ? 'style="display:none;"' : ''; ?>>
                        <?php if (!empty($value)): ?>
                            <img src="<?php echo esc_url($value); ?>" style="max-width: 200px; max-height: 100px; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px; padding: 5px; background: #f9f9f9;" />
                        <?php endif; ?>
                    </div>
                </div>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;
        }
    }

    /**
     * Migrate from legacy quicklinks format (predefined_quicklinks + custom_quicklinks)
     * to the new unified quicklinks_sortable format
     *
     * @return array<int, array<string, mixed>>|null Returns migrated data or null if no migration needed
     */
    public static function migrate_legacy_quicklinks(): ?array
    {
        $options = get_option(self::$option_name, []);
        
        // Check if we already have the new format
        if (!empty($options['quicklinks_sortable'])) {
            return null;
        }
        
        // Check for legacy data
        $legacy_predefined = $options['predefined_quicklinks'] ?? [];
        $legacy_custom = $options['custom_quicklinks'] ?? [];
        
        if (empty($legacy_predefined) && empty($legacy_custom)) {
            return null;
        }
        
        $migrated = [];
        
        // Migrate predefined quicklinks
        if (!empty($legacy_predefined)) {
            foreach ($legacy_predefined as $link) {
                if (isset($link['id'])) {
                    $migrated[] = [
                        'type' => 'predefined',
                        'id' => $link['id'],
                        'enabled' => !empty($link['enabled']),
                    ];
                }
            }
        }
        
        // Migrate custom quicklinks
        if (!empty($legacy_custom)) {
            foreach ($legacy_custom as $index => $link) {
                if (!empty($link['title']) || !empty($link['url'])) {
                    $migrated[] = [
                        'type' => 'custom',
                        'id' => 'custom_migrated_' . $index,
                        'title' => $link['title'] ?? '',
                        'url' => $link['url'] ?? '',
                        'icon' => $link['icon'] ?? '',
                        'enabled' => true, // Custom links were always enabled in the old format
                    ];
                }
            }
        }
        
        return $migrated;
    }

    /**
     * Get all quicklinks (predefined + custom) with order
     *
     * @param array $saved_data Saved quicklinks data
     * @return array<int, array<string, mixed>>
     */
    public static function get_ordered_quicklinks(array $saved_data = []): array
    {
        $predefined = self::get_default_quicklinks();
        $items = [];
        
        // Check for legacy migration if no data provided
        if (empty($saved_data)) {
            $migrated = self::migrate_legacy_quicklinks();
            if ($migrated !== null) {
                $saved_data = $migrated;
            }
        }
        
        if (!empty($saved_data)) {
            // Use saved order and settings
            foreach ($saved_data as $item) {
                if (!isset($item['type'])) {
                    continue;
                }
                
                if ($item['type'] === 'predefined') {
                    // Find predefined link data
                    foreach ($predefined as $predef) {
                        if ($predef['id'] === $item['id']) {
                            $items[] = array_merge($predef, [
                                'type' => 'predefined',
                                'enabled' => !empty($item['enabled']),
                                'roles' => $item['roles'] ?? [],
                            ]);
                            break;
                        }
                    }
                } elseif ($item['type'] === 'custom') {
                    $items[] = [
                        'type' => 'custom',
                        'id' => $item['id'] ?? 'custom_' . uniqid(),
                        'title' => $item['title'] ?? '',
                        'url' => $item['url'] ?? '',
                        'icon' => $item['icon'] ?? '',
                        'enabled' => !empty($item['enabled']),
                        'roles' => $item['roles'] ?? [],
                    ];
                }
            }
            
            // Add any new predefined links that weren't in saved data
            $saved_predefined_ids = array_column(array_filter($saved_data, fn($i) => ($i['type'] ?? '') === 'predefined'), 'id');
            foreach ($predefined as $predef) {
                if (!in_array($predef['id'], $saved_predefined_ids)) {
                    $items[] = array_merge($predef, [
                        'type' => 'predefined',
                        'enabled' => !empty($predef['enabled']),
                        'roles' => [],
                    ]);
                }
            }
        } else {
            // Initialize with defaults
            foreach ($predefined as $predef) {
                $items[] = array_merge($predef, [
                    'type' => 'predefined',
                    'roles' => [],
                ]);
            }
        }
        
        return $items;
    }

    /**
     * Render unified sortable quicklinks field
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    public static function render_quicklinks_sortable_field(string $id, $value): void
    {
        $quicklinks = self::get_ordered_quicklinks(is_array($value) ? $value : []);
        $allowed_svg = self::get_allowed_svg_tags();
        $available_roles = self::get_available_roles();
        ?>
        <div class="sfx-quicklinks-sortable-container">
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('Drag to reorder. Check to enable/disable each quick link. Use the role selector to restrict visibility.', 'sfxtheme'); ?>
            </p>
            <ul class="sfx-quicklinks-sortable" id="sfx-quicklinks-sortable">
                <?php foreach ($quicklinks as $index => $link): ?>
                    <?php
                    $type_badge = $link['type'] === 'custom' ? '<span class="sfx-quicklink-badge">' . esc_html__('Custom', 'sfxtheme') . '</span>' : '';
                    $is_custom = $link['type'] === 'custom';
                    $link_roles = $link['roles'] ?? [];
                    $has_all_roles = in_array('all', $link_roles);
                    ?>
                    <li class="sfx-quicklink-item <?php echo $is_custom ? 'sfx-quicklink-item-custom' : ''; ?>" 
                        data-id="<?php echo esc_attr($link['id']); ?>" 
                        data-type="<?php echo esc_attr($link['type']); ?>">
                        
                        <?php if ($is_custom): ?>
                            <!-- Custom Link: Compact View (like predefined) -->
                            <span class="sfx-quicklink-drag-handle">☰</span>
                            <label class="sfx-quicklink-checkbox">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][enabled]" 
                                       value="1" 
                                       <?php checked(!empty($link['enabled'])); ?> />
                                <input type="hidden" 
                                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][id]" 
                                       value="<?php echo esc_attr($link['id']); ?>" 
                                       class="sfx-quicklink-id" />
                                <input type="hidden" 
                                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][type]" 
                                       value="<?php echo esc_attr($link['type']); ?>" 
                                       class="sfx-quicklink-type" />
                            </label>
                            <span class="sfx-quicklink-icon-preview"><?php echo wp_kses($link['icon'], $allowed_svg); ?></span>
                            <span class="sfx-quicklink-label sfx-quicklink-title-display"><?php echo wp_kses($link['title'] ?: __('Untitled', 'sfxtheme'), self::get_allowed_title_tags()); ?></span>
                            <code class="sfx-quicklink-url sfx-quicklink-url-display"><?php echo esc_html($link['url'] ?: '—'); ?></code>
                            <?php echo $type_badge; ?>
                            
                            <!-- Role Selector -->
                            <div class="sfx-quicklink-roles">
                                <button type="button" class="sfx-quicklink-roles-toggle" aria-expanded="false">
                                    <span class="sfx-roles-toggle-icon">▼</span>
                                    <span class="sfx-roles-toggle-label">
                                        <?php 
                                        if (empty($link_roles) || $has_all_roles) {
                                            esc_html_e('All Roles', 'sfxtheme');
                                        } else {
                                            $role_count = count($link_roles);
                                            printf(
                                                esc_html(_n('%d Role', '%d Roles', $role_count, 'sfxtheme')),
                                                $role_count
                                            );
                                        }
                                        ?>
                                    </span>
                                </button>
                                <div class="sfx-quicklink-roles-dropdown" style="display: none;">
                                    <label class="sfx-role-checkbox sfx-role-checkbox-all">
                                        <input type="checkbox" 
                                               name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][roles][]" 
                                               value="all" 
                                               class="sfx-role-all-checkbox"
                                               <?php checked($has_all_roles || empty($link_roles)); ?> />
                                        <span><?php esc_html_e('All Roles', 'sfxtheme'); ?></span>
                                    </label>
                                    <div class="sfx-roles-divider"></div>
                                    <?php foreach ($available_roles as $role_slug => $role_name): ?>
                                        <label class="sfx-role-checkbox">
                                            <input type="checkbox" 
                                                   name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][roles][]" 
                                                   value="<?php echo esc_attr($role_slug); ?>" 
                                                   class="sfx-role-individual-checkbox"
                                                   <?php checked(in_array($role_slug, $link_roles) && !$has_all_roles && !empty($link_roles)); ?>
                                                   <?php disabled($has_all_roles || empty($link_roles)); ?> />
                                            <span><?php echo esc_html($role_name); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="sfx-quicklink-actions">
                                <button type="button" class="button sfx-edit-quicklink" title="<?php esc_attr_e('Edit', 'sfxtheme'); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                </button>
                                <button type="button" class="button sfx-remove-quicklink" title="<?php esc_attr_e('Remove', 'sfxtheme'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                            
                            <!-- Custom Link: Editable Form (hidden by default) -->
                            <div class="sfx-quicklink-edit-form" style="display: none;">
                                <div class="sfx-quicklink-edit-fields">
                                    <div class="sfx-edit-field">
                                        <label><?php esc_html_e('Title', 'sfxtheme'); ?></label>
                                        <input type="text" 
                                               name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][title]" 
                                               value="<?php echo esc_attr($link['title']); ?>" 
                                               class="sfx-quicklink-title-input" 
                                               placeholder="<?php esc_attr_e('Link Title', 'sfxtheme'); ?>" />
                                    </div>
                                    <div class="sfx-edit-field">
                                        <label><?php esc_html_e('URL', 'sfxtheme'); ?></label>
                                        <input type="text" 
                                               name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][url]" 
                                               value="<?php echo esc_attr($link['url']); ?>" 
                                               class="sfx-quicklink-url-input" 
                                               placeholder="<?php esc_attr_e('admin.php?page=example', 'sfxtheme'); ?>" />
                                    </div>
                                    <div class="sfx-edit-field sfx-edit-field-full">
                                        <label><?php esc_html_e('SVG Icon', 'sfxtheme'); ?></label>
                                        <textarea name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][icon]" 
                                                  class="sfx-quicklink-icon-input" 
                                                  placeholder="<?php esc_attr_e('<svg>...</svg>', 'sfxtheme'); ?>" 
                                                  rows="3"><?php echo esc_textarea($link['icon']); ?></textarea>
                                    </div>
                                </div>
                                <div class="sfx-quicklink-edit-actions">
                                    <button type="button" class="button button-primary sfx-save-quicklink"><?php esc_html_e('Done', 'sfxtheme'); ?></button>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Predefined Link: Single Row -->
                            <span class="sfx-quicklink-drag-handle">☰</span>
                            <label class="sfx-quicklink-checkbox">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][enabled]" 
                                       value="1" 
                                       <?php checked(!empty($link['enabled'])); ?> />
                                <input type="hidden" 
                                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][id]" 
                                       value="<?php echo esc_attr($link['id']); ?>" 
                                       class="sfx-quicklink-id" />
                                <input type="hidden" 
                                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][type]" 
                                       value="<?php echo esc_attr($link['type']); ?>" 
                                       class="sfx-quicklink-type" />
                            </label>
                            <span class="sfx-quicklink-icon-preview"><?php echo wp_kses($link['icon'], $allowed_svg); ?></span>
                            <span class="sfx-quicklink-label"><?php echo esc_html($link['title']); ?></span>
                            <code class="sfx-quicklink-url"><?php echo esc_html($link['url']); ?></code>
                            
                            <!-- Role Selector -->
                            <div class="sfx-quicklink-roles">
                                <button type="button" class="sfx-quicklink-roles-toggle" aria-expanded="false">
                                    <span class="sfx-roles-toggle-icon">▼</span>
                                    <span class="sfx-roles-toggle-label">
                                        <?php 
                                        if (empty($link_roles) || $has_all_roles) {
                                            esc_html_e('All Roles', 'sfxtheme');
                                        } else {
                                            $role_count = count($link_roles);
                                            printf(
                                                esc_html(_n('%d Role', '%d Roles', $role_count, 'sfxtheme')),
                                                $role_count
                                            );
                                        }
                                        ?>
                                    </span>
                                </button>
                                <div class="sfx-quicklink-roles-dropdown" style="display: none;">
                                    <label class="sfx-role-checkbox sfx-role-checkbox-all">
                                        <input type="checkbox" 
                                               name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][roles][]" 
                                               value="all" 
                                               class="sfx-role-all-checkbox"
                                               <?php checked($has_all_roles || empty($link_roles)); ?> />
                                        <span><?php esc_html_e('All Roles', 'sfxtheme'); ?></span>
                                    </label>
                                    <div class="sfx-roles-divider"></div>
                                    <?php foreach ($available_roles as $role_slug => $role_name): ?>
                                        <label class="sfx-role-checkbox">
                                            <input type="checkbox" 
                                                   name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][roles][]" 
                                                   value="<?php echo esc_attr($role_slug); ?>" 
                                                   class="sfx-role-individual-checkbox"
                                                   <?php checked(in_array($role_slug, $link_roles) && !$has_all_roles && !empty($link_roles)); ?>
                                                   <?php disabled($has_all_roles || empty($link_roles)); ?> />
                                            <span><?php echo esc_html($role_name); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="sfx-quicklinks-actions">
                <button type="button" id="sfx-add-custom-quicklink" class="button button-secondary">
                    <?php esc_html_e('+ Add Custom Link', 'sfxtheme'); ?>
                </button>
            </div>
            <p class="description" style="margin-top: 15px;">
                <strong><?php esc_html_e('URL:', 'sfxtheme'); ?></strong> <?php esc_html_e('Use admin paths (e.g., "edit.php") or placeholders:', 'sfxtheme'); ?>
                <code>{admin_url}</code>, <code>{site_url}</code>, <code>{home_url}</code>
                <br>
                <strong><?php esc_html_e('Icon:', 'sfxtheme'); ?></strong> <?php esc_html_e('Paste SVG code.', 'sfxtheme'); ?> 
                <a href="https://heroicons.com/" target="_blank" rel="noopener">Heroicons</a> <?php esc_html_e('(outline style, 24x24) recommended.', 'sfxtheme'); ?>
                <br>
                <strong><?php esc_html_e('Roles:', 'sfxtheme'); ?></strong> <?php esc_html_e('Select which user roles can see this link. "All Roles" shows the link to everyone.', 'sfxtheme'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get all available stats (default + custom post types)
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_available_stats(): array
    {
        $stats = [
            'posts' => [
                'id' => 'posts',
                'label' => __('Published Posts', 'sfxtheme'),
                'type' => 'builtin',
            ],
            'pages' => [
                'id' => 'pages',
                'label' => __('Pages', 'sfxtheme'),
                'type' => 'builtin',
            ],
            'media' => [
                'id' => 'media',
                'label' => __('Media Files', 'sfxtheme'),
                'type' => 'builtin',
            ],
            'users' => [
                'id' => 'users',
                'label' => __('Users', 'sfxtheme'),
                'type' => 'builtin',
            ],
        ];

        // Add custom post types
        $post_types = get_post_types([
            'public' => true,
            '_builtin' => false,
        ], 'objects');

        foreach ($post_types as $post_type) {
            $stats['cpt_' . $post_type->name] = [
                'id' => 'cpt_' . $post_type->name,
                'label' => $post_type->labels->name ?? $post_type->label ?? $post_type->name,
                'type' => 'cpt',
                'post_type' => $post_type->name,
            ];
        }

        return $stats;
    }

    /**
     * Get default stats items configuration
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_default_stats_items(): array
    {
        $items = [];
        $order = 0;

        foreach (self::get_available_stats() as $id => $stat) {
            $items[] = [
                'id' => $id,
                'enabled' => in_array($id, ['posts', 'pages', 'media', 'users']),
                'order' => $order++,
            ];
        }

        return $items;
    }

    /**
     * Render stats items field (sortable)
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    public static function render_stats_items_field(string $id, $value): void
    {
        $available_stats = self::get_available_stats();
        
        if (!is_array($value) || empty($value)) {
            $value = self::get_default_stats_items();
        }

        // Build ordered list with current settings
        $ordered_items = [];
        $used_ids = [];

        // First, add items in saved order
        foreach ($value as $item) {
            if (isset($available_stats[$item['id']])) {
                $ordered_items[] = array_merge($available_stats[$item['id']], [
                    'enabled' => !empty($item['enabled']),
                ]);
                $used_ids[] = $item['id'];
            }
        }

        // Add any new stats that weren't in saved order
        foreach ($available_stats as $stat_id => $stat) {
            if (!in_array($stat_id, $used_ids)) {
                $ordered_items[] = array_merge($stat, [
                    'enabled' => false,
                ]);
            }
        }

        ?>
        <div class="sfx-stats-items-container">
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('Drag to reorder. Check to enable/disable each stat card.', 'sfxtheme'); ?>
            </p>
            <ul class="sfx-stats-sortable" id="sfx-stats-sortable">
                <?php foreach ($ordered_items as $index => $item): ?>
                    <?php
                    $count = self::get_stat_count($item);
                    $type_badge = $item['type'] === 'cpt' ? '<span class="sfx-stat-badge">CPT</span>' : '';
                    ?>
                    <li class="sfx-stat-item" data-id="<?php echo esc_attr($item['id']); ?>">
                        <span class="sfx-stat-drag-handle">☰</span>
                        <label class="sfx-stat-checkbox">
                            <input type="checkbox" 
                                   name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][enabled]" 
                                   value="1" 
                                   <?php checked(!empty($item['enabled'])); ?> />
                            <input type="hidden" 
                                   name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][id]" 
                                   value="<?php echo esc_attr($item['id']); ?>" />
                        </label>
                        <span class="sfx-stat-label">
                            <?php echo esc_html($item['label']); ?>
                            <?php echo $type_badge; ?>
                        </span>
                        <span class="sfx-stat-count"><?php echo absint($count); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Get count for a stat item
     *
     * @param array<string, mixed> $item
     * @return int
     */
    private static function get_stat_count(array $item): int
    {
        $id = $item['id'] ?? '';

        switch ($id) {
            case 'posts':
                $count = wp_count_posts('post');
                return isset($count->publish) ? (int) $count->publish : 0;
            case 'pages':
                $count = wp_count_posts('page');
                return isset($count->publish) ? (int) $count->publish : 0;
            case 'media':
                $count = wp_count_posts('attachment');
                return isset($count->inherit) ? (int) $count->inherit : 0;
            case 'users':
                $users = count_users();
                return isset($users['total_users']) ? (int) $users['total_users'] : 0;
            default:
                // Custom post type
                if (strpos($id, 'cpt_') === 0) {
                    $post_type = $item['post_type'] ?? str_replace('cpt_', '', $id);
                    $count = wp_count_posts($post_type);
                    return isset($count->publish) ? (int) $count->publish : 0;
                }
                return 0;
        }
    }

    /**
     * Sanitize stats items
     *
     * @param mixed $input
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_stats_items($input): array
    {
        if (!is_array($input)) {
            return self::get_default_stats_items();
        }

        $sanitized = [];

        foreach ($input as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }

            $sanitized[] = [
                'id' => sanitize_key($item['id']),
                'enabled' => !empty($item['enabled']),
            ];
        }

        return $sanitized;
    }

    /**
     * Render dashboard widgets selection field
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    public static function render_dashboard_widgets_field(string $id, $value): void
    {
        if (!is_array($value)) {
            $value = [];
        }

        // Default WordPress dashboard widgets (always available)
        $available_widgets = [
            'dashboard_site_health' => __('Site Health Status', 'sfxtheme'),
            'dashboard_right_now' => __('At a Glance', 'sfxtheme'),
            'dashboard_activity' => __('Activity', 'sfxtheme'),
            'dashboard_quick_press' => __('Quick Draft', 'sfxtheme'),
            'dashboard_primary' => __('WordPress Events and News', 'sfxtheme'),
        ];

        // Try to get additional registered widgets from global
        global $wp_meta_boxes;
        
        if (isset($wp_meta_boxes['dashboard']) && is_array($wp_meta_boxes['dashboard'])) {
            foreach ($wp_meta_boxes['dashboard'] as $context => $priority_widgets) {
                if (!is_array($priority_widgets)) continue;
                foreach ($priority_widgets as $priority => $widgets) {
                    if (!is_array($widgets)) continue;
                    foreach ($widgets as $widget_id => $widget) {
                        if (!isset($available_widgets[$widget_id]) && !empty($widget['title'])) {
                            $available_widgets[$widget_id] = $widget['title'];
                        }
                    }
                }
            }
        }

        echo '<div class="sfx-dashboard-widgets-selection">';
        foreach ($available_widgets as $widget_id => $widget_title) {
            $checked = in_array($widget_id, $value) ? 'checked' : '';
            ?>
            <label class="sfx-widget-checkbox">
                <input type="checkbox" 
                       name="<?php echo esc_attr(self::$option_name); ?>[<?php echo esc_attr($id); ?>][]" 
                       value="<?php echo esc_attr($widget_id); ?>"
                       <?php echo $checked; ?> />
                <span><?php echo esc_html($widget_title); ?></span>
            </label>
            <?php
        }
        echo '</div>';
    }

    /**
     * Sanitize options
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize_options($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $output = [];
        
        foreach (self::get_fields() as $field) {
            $id = $field['id'];
            $type = $field['type'];
            $default = $field['default'];

            if (!isset($input[$id])) {
                $output[$id] = ($type === 'checkbox') ? 0 : $default;
                continue;
            }

            switch ($type) {
                case 'checkbox':
                    $output[$id] = !empty($input[$id]) ? 1 : 0;
                    break;

                case 'text':
                    $output[$id] = sanitize_text_field($input[$id]);
                    break;

                case 'email':
                    $output[$id] = sanitize_email($input[$id]);
                    break;

                case 'url':
                    $output[$id] = esc_url_raw($input[$id]);
                    break;

                case 'textarea':
                    $output[$id] = sanitize_textarea_field($input[$id]);
                    break;

                case 'html_textarea':
                    $output[$id] = wp_kses_post($input[$id]);
                    break;

                case 'code_editor':
                    // Allow CSS but strip PHP tags for security
                    $css = $input[$id];
                    // Remove any PHP tags
                    $css = preg_replace('/<\?php.*?\?>/is', '', $css);
                    $css = preg_replace('/<\?.*?\?>/is', '', $css);
                    // Remove script tags
                    $css = preg_replace('/<script.*?>.*?<\/script>/is', '', $css);
                    // Store the sanitized CSS
                    $output[$id] = $css;
                    break;

                case 'quicklinks_sortable':
                    // Check if it's JSON encoded (from hidden field)
                    if (is_string($input[$id]) && !empty($input[$id])) {
                        $decoded = json_decode($input[$id], true);
                        $output[$id] = is_array($decoded) ? self::sanitize_quicklinks_sortable($decoded) : [];
                    } else {
                        $output[$id] = self::sanitize_quicklinks_sortable($input[$id] ?? []);
                    }
                    break;

                case 'color':
                    $output[$id] = sanitize_hex_color($input[$id]);
                    break;

                case 'brand_color_select':
                    $allowed_values = self::get_brand_color_keys();
                    $output[$id] = in_array($input[$id], $allowed_values) ? sanitize_text_field($input[$id]) : $default;
                    break;

                case 'select':
                    // Get allowed options from field definition
                    $field_def = array_filter(self::get_fields(), fn($f) => $f['id'] === $id);
                    $field_def = reset($field_def);
                    $allowed_options = array_keys($field_def['options'] ?? []);
                    $output[$id] = in_array($input[$id], $allowed_options) ? sanitize_key($input[$id]) : $default;
                    break;

                case 'number':
                    $output[$id] = absint($input[$id]);
                    // Handle specific field constraints
                    if ($id === 'brand_border_radius' && $output[$id] > 50) {
                        $output[$id] = 50;
                    } elseif ($id === 'brand_border_width' && $output[$id] > 10) {
                        $output[$id] = 10;
                    } elseif ($id === 'brand_shadow_intensity' && $output[$id] > 3) {
                        $output[$id] = 3;
                    } elseif ($id === 'form_submissions_limit') {
                        $output[$id] = min(max($output[$id], 1), 20);
                    }
                    break;

                case 'dashboard_widgets':
                    $output[$id] = is_array($input[$id]) ? array_map('sanitize_key', $input[$id]) : [];
                    break;

                case 'stats_items':
                    // Check if it's JSON encoded (from hidden field)
                    if (is_string($input[$id] ?? '') && !empty($input[$id])) {
                        $decoded = json_decode($input[$id], true);
                        $output[$id] = is_array($decoded) ? self::sanitize_stats_items($decoded) : self::sanitize_stats_items([]);
                    } else {
                        $output[$id] = self::sanitize_stats_items($input[$id] ?? []);
                    }
                    break;

                case 'logo_upload':
                    // Handle file upload if present
                    if (isset($_FILES[$id . '_file']) && !empty($_FILES[$id . '_file']['name'])) {
                        $uploaded_url = self::handle_logo_upload($id . '_file');
                        if ($uploaded_url) {
                            // Delete old logo if exists
                            if (!empty($input[$id])) {
                                self::delete_logo($input[$id]);
                            }
                            $output[$id] = $uploaded_url;
                        } else {
                            $output[$id] = !empty($input[$id]) ? esc_url_raw($input[$id]) : $default;
                        }
                    } else {
                        // Keep existing value
                        $output[$id] = !empty($input[$id]) ? esc_url_raw($input[$id]) : $default;
                    }
                    break;

                default:
                    $output[$id] = $default;
            }
        }

        return $output;
    }

    /**
     * Get allowed HTML tags for quicklink titles
     * Allows basic formatting tags for display
     *
     * @return array<string, array<string, bool>>
     */
    public static function get_allowed_title_tags(): array
    {
        return [
            'strong' => [],
            'b' => [],
            'em' => [],
            'i' => [],
            'span' => [
                'class' => true,
                'style' => true,
            ],
            'code' => [],
            'mark' => [],
            'small' => [],
            'sub' => [],
            'sup' => [],
        ];
    }

    /**
     * Validate and sanitize URL for quicklinks
     * Allows admin URLs, relative paths, and external URLs
     *
     * @param string $url
     * @return string Sanitized URL or empty string if invalid
     */
    private static function sanitize_quicklink_url(string $url): string
    {
        $url = trim($url);
        
        if (empty($url)) {
            return '';
        }

        // Remove any null bytes and control characters
        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);

        // Check for dangerous protocols
        $dangerous_protocols = ['javascript:', 'data:', 'vbscript:', 'file:'];
        $url_lower = strtolower($url);
        foreach ($dangerous_protocols as $protocol) {
            if (strpos($url_lower, $protocol) === 0) {
                return '';
            }
        }

        // Handle relative admin URLs (most common use case)
        if (preg_match('/^[a-zA-Z0-9\-_\.]+\.php/', $url) || strpos($url, 'admin.php') === 0) {
            // Relative admin URL like "edit.php" or "admin.php?page=something"
            return esc_url_raw($url, ['https', 'http']);
        }

        // Handle paths starting with /
        if (strpos($url, '/') === 0) {
            return esc_url_raw($url, ['https', 'http']);
        }

        // Handle full URLs
        if (preg_match('/^https?:\/\//i', $url)) {
            return esc_url_raw($url, ['https', 'http']);
        }

        // Handle query strings and anchors
        if (strpos($url, '?') === 0 || strpos($url, '#') === 0) {
            return esc_attr($url);
        }

        // Default: sanitize as URL
        return esc_url_raw($url, ['https', 'http']);
    }

    /**
     * Sanitize sortable quicklinks
     *
     * @param mixed $input
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_quicklinks_sortable($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $allowed_svg = self::get_allowed_svg_tags();
        $allowed_title_tags = self::get_allowed_title_tags();
        $predefined = self::get_default_quicklinks();
        $predefined_ids = array_column($predefined, 'id');
        $available_roles = array_keys(self::get_available_roles());

        $sanitized = [];

        foreach ($input as $item) {
            if (!is_array($item) || empty($item['type'])) {
                continue;
            }

            $type = sanitize_key($item['type']);
            
            // Sanitize roles array
            $roles = [];
            if (!empty($item['roles']) && is_array($item['roles'])) {
                foreach ($item['roles'] as $role) {
                    $sanitized_role = sanitize_key($role);
                    // Allow 'all' as a special value, or validate against available roles
                    if ($sanitized_role === 'all' || in_array($sanitized_role, $available_roles)) {
                        $roles[] = $sanitized_role;
                    }
                }
            }

            if ($type === 'predefined') {
                $id = sanitize_key($item['id'] ?? '');
                if (in_array($id, $predefined_ids)) {
                    $sanitized[] = [
                        'type' => 'predefined',
                        'id' => $id,
                        'enabled' => !empty($item['enabled']),
                        'roles' => $roles,
                    ];
                }
            } elseif ($type === 'custom') {
                // Sanitize title - allow basic HTML tags
                $raw_title = $item['title'] ?? '';
                $title = wp_kses(trim($raw_title), $allowed_title_tags);
                
                // Validate title length (max 100 chars after stripping tags)
                $title_text = wp_strip_all_tags($title);
                if (mb_strlen($title_text) > 100) {
                    $title = mb_substr($title, 0, 100);
                }
                
                // Sanitize URL with proper validation
                $url = self::sanitize_quicklink_url($item['url'] ?? '');
                
                // Sanitize icon - must be valid SVG
                $raw_icon = $item['icon'] ?? '';
                $icon = wp_kses(trim($raw_icon), $allowed_svg);
                
                // Validate SVG structure (must have opening and closing svg tags)
                if (!empty($icon) && (strpos($icon, '<svg') === false || strpos($icon, '</svg>') === false)) {
                    $icon = self::DEFAULT_CUSTOM_ICON;
                }
                
                // Only add custom links with at least a title or url
                if (!empty($title) || !empty($url)) {
                    // Ensure ID is valid
                    $id = $item['id'] ?? '';
                    if (empty($id) || !preg_match('/^custom_[a-zA-Z0-9_]+$/', $id)) {
                        $id = 'custom_' . uniqid();
                    } else {
                        $id = sanitize_key($id);
                    }
                    
                    $sanitized[] = [
                        'type' => 'custom',
                        'id' => $id,
                        'title' => $title,
                        'url' => $url,
                        'icon' => !empty($icon) ? $icon : self::DEFAULT_CUSTOM_ICON,
                        'enabled' => !empty($item['enabled']),
                        'roles' => $roles,
                    ];
                }
            }
        }

        return $sanitized;
    }

    /**
     * Handle logo file upload using WordPress upload system
     *
     * @param string $file_key
     * @return string|false URL of uploaded file or false on failure
     */
    private static function handle_logo_upload(string $file_key)
    {
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        $file = $_FILES[$file_key];
        
        // Validate file size (max 200KB) before upload
        if ($file['size'] > 204800) {
            add_settings_error(
                self::$option_name,
                'file_too_large',
                __('File too large. Maximum size is 200KB.', 'sfxtheme')
            );
            return false;
        }

        // Include required file for wp_handle_upload
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Define allowed mime types for logo upload
        $allowed_mimes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        ];

        // WordPress upload overrides
        $upload_overrides = [
            'test_form' => false,
            'mimes' => $allowed_mimes,
            'unique_filename_callback' => function ($dir, $name, $ext) {
                return 'dashboard-logo-' . time() . '-' . wp_generate_password(8, false) . $ext;
            },
        ];

        // Use WordPress's secure upload handling
        $movefile = wp_handle_upload($file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            return $movefile['url'];
        }

        // Handle upload error
        $error_message = $movefile['error'] ?? __('Failed to upload logo. Please try again.', 'sfxtheme');
        add_settings_error(
            self::$option_name,
            'upload_failed',
            $error_message
        );
        return false;
    }

    /**
     * Delete logo file from WordPress uploads
     *
     * @param string $logo_url
     * @return void
     */
    private static function delete_logo(string $logo_url): void
    {
        if (empty($logo_url)) {
            return;
        }

        // Get upload directory info
        $upload_dir = wp_upload_dir();
        $upload_base_url = $upload_dir['baseurl'];

        // Only delete files that are in WordPress uploads directory
        if (strpos($logo_url, $upload_base_url) !== 0) {
            return;
        }

        // Convert URL to file path
        $relative_path = str_replace($upload_base_url, '', $logo_url);
        $filepath = $upload_dir['basedir'] . $relative_path;

        // Delete file if it exists and is a dashboard logo
        if (file_exists($filepath) && strpos(basename($filepath), 'dashboard-logo-') === 0) {
            wp_delete_file($filepath);
        }
    }

    /**
     * Delete all options
     *
     * @return void
     */
    public static function delete_all_options(): void
    {
        delete_option(self::$option_name);
    }
}

