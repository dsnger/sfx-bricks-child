<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Main controller for Custom Dashboard
 *
 * @package SFX_Bricks_Child_Theme
 */
class Controller
{
    /**
     * Dashboard renderer instance
     *
     * @var DashboardRenderer|null
     */
    private ?DashboardRenderer $renderer = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Set default options if not already set
        self::maybe_set_default_options();
        
        // Migrate old emoji icons to SVG icons
        self::maybe_migrate_icons_to_svg();

        // Initialize components
        AdminPage::register();
        Settings::register();
        AssetManager::register();

        // Register hooks through consolidated system
        add_action('sfx_init_settings', [$this, 'handle_dashboard']);
        add_action('sfx_init_admin_features', [$this, 'setup_admin_hooks']);

        // Clear stats cache on content changes
        $this->register_cache_clearing_hooks();
    }

    /**
     * Handle dashboard display
     *
     * @return void
     */
    public function handle_dashboard(): void
    {
        if (!$this->is_option_enabled('enable_custom_dashboard')) {
            return;
        }

        // Initialize renderer
        $this->renderer = new DashboardRenderer();

        // Remove/hide default WordPress dashboard widgets
        add_action('wp_dashboard_setup', [$this, 'remove_dashboard_widgets'], 999);

        // Inject custom dashboard
        add_action('admin_head-index.php', [$this, 'inject_custom_dashboard']);

        // Add body class for sidebar state (prevent FOUC)
        add_filter('admin_body_class', [$this, 'add_sidebar_body_class']);

        // Add early inline script to prevent sidebar flash
        add_action('admin_head-index.php', [$this, 'inject_sidebar_state_script'], 1);
    }

    /**
     * Add sidebar collapsed class to body if needed
     *
     * @param string $classes
     * @return string
     */
    public function add_sidebar_body_class(string $classes): string
    {
        global $pagenow;
        
        if ($pagenow !== 'index.php') {
            return $classes;
        }

        $options = get_option(Settings::$option_name, []);
        $sidebar_default = $options['sidebar_default_state'] ?? 'visible';
        
        if ($sidebar_default === 'collapsed') {
            $classes .= ' sfx-sidebar-collapsed';
        }

        return $classes;
    }

    /**
     * Inject early script to handle sidebar state from localStorage
     *
     * @return void
     */
    public function inject_sidebar_state_script(): void
    {
        ?>
        <script>
        (function() {
            var stored = localStorage.getItem('sfx-dashboard-sidebar');
            if (stored === 'collapsed') {
                document.documentElement.classList.add('sfx-sidebar-collapsed');
                document.body.classList.add('sfx-sidebar-collapsed');
            } else if (stored === 'visible') {
                document.documentElement.classList.remove('sfx-sidebar-collapsed');
                document.body.classList.remove('sfx-sidebar-collapsed');
            }
        })();
        </script>
        <?php
    }

    /**
     * Setup admin-specific hooks
     *
     * @return void
     */
    public function setup_admin_hooks(): void
    {
        // Additional admin hooks can be added here
    }

    /**
     * Remove ALL default WordPress dashboard widgets
     *
     * @return void
     */
    public function remove_dashboard_widgets(): void
    {
        if (!$this->is_option_enabled('enable_custom_dashboard')) {
            return;
        }

        global $wp_meta_boxes;

        // Remove welcome panel completely
        remove_action('welcome_panel', 'wp_welcome_panel');

        // Remove ALL dashboard widgets (we'll selectively include them in our custom layout)
        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard']['normal']['core'] = [];
            $wp_meta_boxes['dashboard']['normal']['high'] = [];
            $wp_meta_boxes['dashboard']['side']['core'] = [];
            $wp_meta_boxes['dashboard']['side']['high'] = [];
            $wp_meta_boxes['dashboard']['column3']['core'] = [];
            $wp_meta_boxes['dashboard']['column3']['high'] = [];
            $wp_meta_boxes['dashboard']['column4']['core'] = [];
            $wp_meta_boxes['dashboard']['column4']['high'] = [];
        }
    }

    /**
     * Inject custom dashboard content
     *
     * @return void
     */
    public function inject_custom_dashboard(): void
    {
        if (!$this->is_option_enabled('enable_custom_dashboard')) {
            return;
        }

        // Hide default dashboard widgets, screen options, and notices via CSS
        // Also apply full-page background color
        ?>
        <style>
            #dashboard-widgets-wrap {
                display: none !important;
            }
            .wrap h1 {
                display: none !important;
            }
            #screen-meta-links,
            #screen-meta {
                display: none !important;
            }
            /* Hide notices in default location - we capture and display them in our dashboard */
            .wrap > .notice,
            .wrap > .updated,
            .wrap > .error,
            .wrap > .update-nag,
            #wpbody-content > .notice,
            #wpbody-content > .updated,
            #wpbody-content > .error,
            #wpbody-content > .update-nag {
                display: none !important;
            }
            /* Full page background - remove default WordPress spacing */
            #wpcontent {
                padding-left: 0 !important;
            }
            #wpbody-content {
                padding-bottom: 0 !important;
                float: none !important;
            }
            .wrap {
                margin: 0 !important;
                padding: 0 !important;
            }
            #wpfooter {
                display: none !important;
            }
        </style>
        <?php

        // Remove screen options for dashboard page
        add_filter('screen_options_show_screen', function($show_screen, $screen) {
            if ($screen->id === 'dashboard') {
                return false;
            }
            return $show_screen;
        }, 10, 2);

        // Capture admin notices
        add_action('admin_notices', [$this, 'capture_admin_notices'], 1);
        add_action('all_admin_notices', [$this, 'capture_admin_notices'], 1);

        // Render custom dashboard after page load
        add_action('in_admin_header', function() {
            if ($this->renderer) {
                echo '<div id="sfx-custom-dashboard-root">';
                $this->renderer->render_dashboard();
                echo '</div>';
            }
        }, 100);
    }

    /**
     * Capture admin notices for display in custom dashboard
     *
     * @return void
     */
    public function capture_admin_notices(): void
    {
        // Start output buffering to capture notices
        if (!isset($GLOBALS['sfx_notices_captured'])) {
            $GLOBALS['sfx_notices_captured'] = true;
            ob_start();
            
            // Register shutdown function to capture the notices
            add_action('in_admin_header', function() {
                if (ob_get_level() > 0) {
                    $notices = ob_get_clean();
                    if (!empty(trim($notices))) {
                        $GLOBALS['sfx_admin_notices'] = $notices;
                    }
                }
            }, 99);
        }
    }

    /**
     * Register cache clearing hooks
     *
     * @return void
     */
    private function register_cache_clearing_hooks(): void
    {
        // Clear cache when posts are published, updated, or deleted
        add_action('transition_post_status', [$this, 'clear_stats_cache'], 10, 3);
        add_action('delete_post', [$this, 'clear_stats_cache']);
        
        // Clear cache when attachments are added or deleted
        add_action('add_attachment', [$this, 'clear_stats_cache']);
        add_action('delete_attachment', [$this, 'clear_stats_cache']);
        
        // Clear cache when users are added or deleted
        add_action('user_register', [$this, 'clear_stats_cache']);
        add_action('delete_user', [$this, 'clear_stats_cache']);
    }

    /**
     * Clear stats cache
     *
     * @return void
     */
    public function clear_stats_cache(): void
    {
        $options = get_option(Settings::$option_name, []);
        $stats_items = $options['stats_items'] ?? [];
        
        // Extract enabled custom post types from stats configuration
        $enabled_cpts = [];
        if (is_array($stats_items)) {
            foreach ($stats_items as $item) {
                if (!empty($item['enabled']) && !empty($item['id']) && strpos($item['id'], 'cpt_') === 0) {
                    $enabled_cpts[] = str_replace('cpt_', '', $item['id']);
                }
            }
        }
        
        StatsProvider::clear_all_cache($enabled_cpts);
        FormSubmissionsProvider::clear_cache();
    }

    /**
     * Check if option is enabled
     *
     * @param string $option_key
     * @return bool
     */
    private function is_option_enabled(string $option_key): bool
    {
        $options = get_option(Settings::$option_name, []);
        return !empty($options[$option_key]);
    }

    /**
     * Get feature configuration for theme registry
     *
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'error' => 'Missing CustomDashboard Controller class in theme',
            'hook' => null,
        ];
    }

    /**
     * Set default options on first activation
     *
     * @return void
     */
    public static function maybe_set_default_options(): void
    {
        if (false === get_option(Settings::$option_name, false)) {
            $defaults = [];
            foreach (Settings::get_fields() as $field) {
                $defaults[$field['id']] = $field['default'];
            }
            add_option(Settings::$option_name, $defaults);
        }
    }

    /**
     * Migrate old emoji/Feather icons to new Heroicons
     *
     * @return void
     */
    public static function maybe_migrate_icons_to_svg(): void
    {
        $options = get_option(Settings::$option_name, []);
        
        if (empty($options)) {
            return;
        }

        // Check if migration is needed (version 3 for proper SVG sanitization)
        $migration_version = get_option(Settings::$option_name . '_icon_migration_version', 0);
        if ($migration_version >= 3) {
            return;
        }

        $updated = false;

        // Update predefined quicklinks with new Heroicons
        if (isset($options['predefined_quicklinks']) && is_array($options['predefined_quicklinks'])) {
            $new_defaults = Settings::get_default_quicklinks();
            foreach ($options['predefined_quicklinks'] as $index => $link) {
                // Update icon if it matches the new default by ID
                if (isset($link['id']) && isset($new_defaults[$index]['id']) && $link['id'] === $new_defaults[$index]['id']) {
                    $options['predefined_quicklinks'][$index]['icon'] = $new_defaults[$index]['icon'];
                    $options['predefined_quicklinks'][$index]['title'] = $new_defaults[$index]['title'];
                    $options['predefined_quicklinks'][$index]['url'] = $new_defaults[$index]['url'];
                    $updated = true;
                }
            }
        }

        if ($updated) {
            update_option(Settings::$option_name, $options);
        }

        // Mark migration version 3 as done (Heroicons with proper SVG sanitization)
        update_option(Settings::$option_name . '_icon_migration_version', 3);
    }
}

