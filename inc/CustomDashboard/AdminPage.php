<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Admin page for Custom Dashboard settings
 *
 * @package SFX_Bricks_Child_Theme
 */
class AdminPage
{
    public static $menu_slug = 'sfx-custom-dashboard';
    public static $page_title = 'Custom Dashboard';
    public static $description = 'Customize your WordPress dashboard with stats, quick actions, and contact information.';

    /**
     * Register admin page
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    /**
     * Add submenu page under Global Theme Settings
     *
     * @return void
     */
    public static function add_submenu_page(): void
    {
        // Only register menu if user has dashboard settings access
        if (!\SFX\AccessControl::can_access_dashboard_settings()) {
            return;
        }

        add_submenu_page(
            \SFX\SFXBricksChildAdmin::$menu_slug,
            self::$page_title,
            self::$page_title,
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page'],
            2
        );
    }

    /**
     * Render settings page
     *
     * @return void
     */
    public static function render_page(): void
    {
        // Block direct URL access for unauthorized users
        \SFX\AccessControl::die_if_unauthorized_dashboard();

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        ?>
        <div class="wrap">
            <div class="sfx-dashboard-header">
                <div class="sfx-dashboard-header-content">
                    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                    <p><?php echo esc_html(self::$description); ?></p>
                </div>
                <div class="sfx-dashboard-header-actions">
                    <a href="<?php echo esc_url(admin_url('index.php')); ?>" class="button button-primary">
                        <span class="dashicons dashicons-dashboard" style="vertical-align: middle;"></span>
                        <?php esc_html_e('View Dashboard', 'sfxtheme'); ?>
                    </a>
                </div>
            </div>
            
            <?php settings_errors(); ?>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <?php
                $tabs = self::get_tabs();
                foreach ($tabs as $tab_key => $tab_label) {
                    $active = $current_tab === $tab_key ? 'nav-tab-active' : '';
                    $url = add_query_arg(['page' => self::$menu_slug, 'tab' => $tab_key], admin_url('admin.php'));
                    printf(
                        '<a href="%s" class="nav-tab %s">%s</a>',
                        esc_url($url),
                        esc_attr($active),
                        esc_html($tab_label)
                    );
                }
                ?>
            </nav>

            <form method="post" action="options.php" class="sfx-dashboard-settings-form" enctype="multipart/form-data">
                <?php
                settings_fields(Settings::$OPTION_GROUP);
                
                // Render visible tab content
                self::render_tab_content($current_tab);
                
                // Include hidden fields for all other tabs to preserve their values
                self::render_hidden_fields($current_tab);
                
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Get tabs configuration
     *
     * @return array<string, string>
     */
    private static function get_tabs(): array
    {
        return [
            'general' => __('General', 'sfxtheme'),
            'sections' => __('Sections', 'sfxtheme'),
            'stats' => __('Statistics', 'sfxtheme'),
            'quicklinks' => __('Quick Actions', 'sfxtheme'),
            'contact' => __('Contact Info', 'sfxtheme'),
            'brand' => __('Brand & Styling', 'sfxtheme'),
        ];
    }

    /**
     * Get brand sub-tabs configuration
     *
     * @return array<string, string>
     */
    private static function get_brand_subtabs(): array
    {
        return [
            'color_mode' => __('Color Mode', 'sfxtheme'),
            'colors' => __('Brand Colors', 'sfxtheme'),
            'header' => __('Welcome Header', 'sfxtheme'),
            'cards' => __('Quick Action Boxes', 'sfxtheme'),
            'general_style' => __('Dashboard Layout', 'sfxtheme'),
        ];
    }

    /**
     * Get fields for brand sub-tabs
     *
     * @return array<string, array>
     */
    private static function get_brand_subtab_fields(): array
    {
        return [
            'color_mode' => ['color_mode_default', 'allow_user_mode_switch'],
            'colors' => ['brand_primary_color', 'brand_secondary_color', 'brand_accent_color', 'brand_success_color', 'brand_warning_color', 'brand_error_color'],
            'header' => ['brand_header_gradient', 'brand_header_gradient_start', 'brand_header_gradient_end', 'brand_header_bg_color', 'brand_header_text_color', 'brand_logo'],
            'cards' => ['card_background_color', 'card_text_color', 'card_border_width', 'card_border_color', 'card_border_radius', 'card_shadow_enabled', 'card_hover_background_color', 'card_hover_text_color', 'card_hover_border_color', 'quicklinks_columns', 'quicklinks_gap'],
            'general_style' => ['brand_border_radius', 'brand_border_width', 'brand_border_color', 'brand_shadow_enabled', 'brand_shadow_intensity', 'stats_columns', 'stats_gap'],
        ];
    }

    /**
     * Render tab content
     *
     * @param string $tab
     * @return void
     */
    private static function render_tab_content(string $tab): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        $page = Settings::$OPTION_GROUP;

        if (!isset($wp_settings_sections[$page])) {
            return;
        }

        // Special handling for brand tab with sub-tabs
        if ($tab === 'brand') {
            self::render_brand_tab_content();
            return;
        }

        // Map tabs to their sections
        $sections_map = [
            'general' => [Settings::$OPTION_NAME . '_main'],
            'sections' => [Settings::$OPTION_NAME . '_sections'],
            'stats' => [Settings::$OPTION_NAME . '_stats'],
            'quicklinks' => [Settings::$OPTION_NAME . '_quicklinks'],
            'contact' => [Settings::$OPTION_NAME . '_contact'],
        ];

        $sections_to_show = $sections_map[$tab] ?? [];

        foreach ($sections_to_show as $section) {
            if (!isset($wp_settings_sections[$page][$section])) {
                continue;
            }

            echo '<div class="sfx-settings-section">';
            
            if ($wp_settings_sections[$page][$section]['title']) {
                echo '<h2>' . esc_html($wp_settings_sections[$page][$section]['title']) . '</h2>';
            }

            if ($wp_settings_sections[$page][$section]['callback']) {
                call_user_func($wp_settings_sections[$page][$section]['callback'], $wp_settings_sections[$page][$section]);
            }

            if (!isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section])) {
                continue;
            }

            echo '<table class="form-table" role="presentation">';
            do_settings_fields($page, $section);
            echo '</table>';
            echo '</div>';
        }
    }

    /**
     * Render brand tab content with sub-tabs
     *
     * @return void
     */
    private static function render_brand_tab_content(): void
    {
        global $wp_settings_fields;

        $page = Settings::$OPTION_GROUP;
        $section = Settings::$OPTION_NAME . '_brand';
        $subtabs = self::get_brand_subtabs();
        $subtab_fields = self::get_brand_subtab_fields();
        $options = get_option(Settings::$OPTION_NAME, []);
        
        // Get current subtab
        $current_subtab = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : 'color_mode';
        if (!isset($subtabs[$current_subtab])) {
            $current_subtab = 'color_mode';
        }

        // Render sub-tab navigation
        echo '<div class="sfx-subtab-wrapper">';
        echo '<nav class="sfx-subtab-nav">';
        foreach ($subtabs as $subtab_key => $subtab_label) {
            $active = $current_subtab === $subtab_key ? 'sfx-subtab-active' : '';
            $url = add_query_arg([
                'page' => self::$menu_slug, 
                'tab' => 'brand', 
                'subtab' => $subtab_key
            ], admin_url('admin.php'));
            printf(
                '<a href="%s" class="sfx-subtab %s">%s</a>',
                esc_url($url),
                esc_attr($active),
                esc_html($subtab_label)
            );
        }
        echo '</nav>';

        // Get fields for current subtab
        $current_subtab_fields = $subtab_fields[$current_subtab] ?? [];

        echo '<div class="sfx-settings-section sfx-subtab-content">';
        echo '<h2>' . esc_html($subtabs[$current_subtab]) . '</h2>';

        if (isset($wp_settings_fields[$page][$section])) {
            echo '<table class="form-table" role="presentation">';
            foreach ($wp_settings_fields[$page][$section] as $field_id => $field) {
                if (in_array($field_id, $current_subtab_fields)) {
                    echo '<tr>';
                    echo '<th scope="row">';
                    if (!empty($field['title'])) {
                        echo '<label for="' . esc_attr($field_id) . '">' . esc_html($field['title']) . '</label>';
                    }
                    echo '</th>';
                    echo '<td>';
                    call_user_func($field['callback'], $field['args']);
                    echo '</td>';
                    echo '</tr>';
                }
            }
            echo '</table>';
        }

        // Add reset buttons for each subtab
        self::render_subtab_reset_button($current_subtab);

        echo '</div>';
        
        // Render hidden fields for other sub-tabs to preserve their values
        self::render_brand_hidden_fields($current_subtab, $subtab_fields, $options);
        
        echo '</div>';
    }

    /**
     * Render hidden fields for brand sub-tabs not currently visible
     *
     * @param string $current_subtab
     * @param array $subtab_fields
     * @param array $options
     * @return void
     */
    private static function render_brand_hidden_fields(string $current_subtab, array $subtab_fields, array $options): void
    {
        $all_fields = Settings::get_fields();
        $current_fields = $subtab_fields[$current_subtab] ?? [];
        
        // Get all brand fields that are not in current subtab
        $all_brand_fields = [];
        foreach ($subtab_fields as $fields) {
            $all_brand_fields = array_merge($all_brand_fields, $fields);
        }
        
        foreach ($all_fields as $field) {
            $field_id = $field['id'];
            
            // Only process brand fields not in current subtab
            if (!in_array($field_id, $all_brand_fields) || in_array($field_id, $current_fields)) {
                continue;
            }
            
            $value = $options[$field_id] ?? $field['default'];
            
            if (is_array($value)) {
                echo '<input type="hidden" name="' . esc_attr(Settings::$OPTION_NAME) . '[' . esc_attr($field_id) . ']" value="' . esc_attr(wp_json_encode($value)) . '" />';
            } else {
                echo '<input type="hidden" name="' . esc_attr(Settings::$OPTION_NAME) . '[' . esc_attr($field_id) . ']" value="' . esc_attr((string) $value) . '" />';
            }
        }
    }

    /**
     * Render reset button for a brand subtab
     *
     * @param string $subtab
     * @return void
     */
    private static function render_subtab_reset_button(string $subtab): void
    {
        $reset_config = [
            'colors' => [
                'id' => 'sfx-reset-brand-colors',
                'label' => __('Reset Colors to Defaults', 'sfxtheme'),
                'description' => __('Reset all brand and status colors to their default values.', 'sfxtheme'),
                'defaults_method' => 'get_default_brand_colors',
                'var_name' => 'sfxDefaultBrandColors',
            ],
            'header' => [
                'id' => 'sfx-reset-header-settings',
                'label' => __('Reset Header to Defaults', 'sfxtheme'),
                'description' => __('Reset welcome header settings to their default values.', 'sfxtheme'),
                'defaults_method' => 'get_default_header_settings',
                'var_name' => 'sfxDefaultHeaderSettings',
            ],
            'cards' => [
                'id' => 'sfx-reset-card-settings',
                'label' => __('Reset Card Styling to Defaults', 'sfxtheme'),
                'description' => __('Reset quick action card styling to default values.', 'sfxtheme'),
                'defaults_method' => 'get_default_card_settings',
                'var_name' => 'sfxDefaultCardSettings',
            ],
            'general_style' => [
                'id' => 'sfx-reset-layout-settings',
                'label' => __('Reset Layout to Defaults', 'sfxtheme'),
                'description' => __('Reset dashboard layout settings to default values.', 'sfxtheme'),
                'defaults_method' => 'get_default_layout_settings',
                'var_name' => 'sfxDefaultLayoutSettings',
            ],
        ];

        if (!isset($reset_config[$subtab])) {
            return;
        }

        $config = $reset_config[$subtab];
        $defaults = call_user_func([Settings::class, $config['defaults_method']]);
        ?>
        <div class="sfx-reset-wrapper" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            <button type="button" id="<?php echo esc_attr($config['id']); ?>" class="button button-secondary sfx-reset-btn">
                <?php echo esc_html($config['label']); ?>
            </button>
            <p class="description" style="margin-top: 8px;">
                <?php echo esc_html($config['description']); ?>
            </p>
        </div>
        <script type="text/javascript">
            var <?php echo esc_js($config['var_name']); ?> = <?php echo wp_json_encode($defaults); ?>;
        </script>
        <?php
    }

    /**
     * Render hidden fields for tabs not currently visible
     *
     * @param string $current_tab
     * @return void
     */
    private static function render_hidden_fields(string $current_tab): void
    {
        $options = get_option(Settings::$OPTION_NAME, []);
        
        // Get all fields
        $all_fields = Settings::get_fields();
        
        // Map fields to tabs
        $tab_fields = [
            'general' => ['enable_custom_dashboard', 'dashboard_welcome_title', 'dashboard_welcome_subtitle'],
            'sections' => [
                'show_updates_section', 'show_site_health_section', 'show_stats_section', 'show_quicklinks_section', 
                'show_contact_section', 'show_form_submissions_section', 'form_submissions_limit', 
                'show_dashboard_widgets', 'enabled_dashboard_widgets', 
                'show_note_section', 'note_title', 'note_content'
            ],
            'stats' => ['stats_items'],
            'quicklinks' => ['quicklinks_sortable'],
            'contact' => ['contact_card_title', 'contact_card_subtitle', 'contact_company', 'contact_email', 'contact_phone', 'contact_website', 'contact_address'],
            'brand' => ['color_mode_default', 'allow_user_mode_switch', 'brand_primary_color', 'brand_secondary_color', 'brand_accent_color', 'brand_success_color', 'brand_warning_color', 'brand_error_color', 'brand_border_radius', 'brand_border_width', 'brand_border_color', 'brand_shadow_enabled', 'brand_shadow_intensity', 'brand_header_gradient', 'brand_header_gradient_start', 'brand_header_gradient_end', 'brand_header_bg_color', 'brand_header_text_color', 'brand_logo', 'card_background_color', 'card_text_color', 'card_border_width', 'card_border_color', 'card_border_radius', 'card_shadow_enabled', 'card_hover_background_color', 'card_hover_text_color', 'card_hover_border_color', 'stats_columns', 'stats_gap', 'quicklinks_columns', 'quicklinks_gap'],
        ];

        // Get fields for current tab
        $current_fields = $tab_fields[$current_tab] ?? [];

        // Render hidden fields for all other tabs
        foreach ($all_fields as $field) {
            $field_id = $field['id'];
            
            // Skip if this field belongs to current tab
            if (in_array($field_id, $current_fields)) {
                continue;
            }

            // Get current value
            $value = $options[$field_id] ?? $field['default'];

            // Render as hidden field based on type
            if ($field['type'] === 'quicklinks_sortable' || $field['type'] === 'stats_items' || $field['type'] === 'dashboard_widgets' || is_array($value)) {
                // For complex array fields, serialize as JSON in hidden input
                echo '<input type="hidden" name="' . esc_attr(Settings::$OPTION_NAME) . '[' . esc_attr($field_id) . ']" value="' . esc_attr(wp_json_encode($value)) . '" data-sfx-json-field="true" />';
            } else {
                // For simple fields
                echo '<input type="hidden" name="' . esc_attr(Settings::$OPTION_NAME) . '[' . esc_attr($field_id) . ']" value="' . esc_attr((string) $value) . '" />';
            }
        }
    }
}

