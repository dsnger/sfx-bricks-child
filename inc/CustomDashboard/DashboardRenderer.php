<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Renders the custom dashboard HTML
 *
 * @package SFX_Bricks_Child_Theme
 */
class DashboardRenderer
{
    /**
     * Dashboard options
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->options = get_option(Settings::$option_name, []);
        $this->load_defaults();
    }

    /**
     * Load default values for missing options
     *
     * @return void
     */
    private function load_defaults(): void
    {
        foreach (Settings::get_fields() as $field) {
            if (!isset($this->options[$field['id']])) {
                $this->options[$field['id']] = $field['default'];
            }
        }
    }

    /**
     * Get option value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function get_option(string $key, $default = '')
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Check if option is enabled
     *
     * @param string $key
     * @return bool
     */
    private function is_enabled(string $key): bool
    {
        return !empty($this->options[$key]);
    }

    /**
     * Render icon (SVG or text)
     *
     * @param string $icon
     * @return string
     */
    private function render_icon(string $icon): string
    {
        // Allow SVG elements and attributes
        $allowed_svg = Settings::get_allowed_svg_tags();

        return wp_kses($icon, $allowed_svg);
    }

    /**
     * Main render method
     *
     * @return void
     */
    public function render_dashboard(): void
    {
        if (!$this->is_enabled('enable_custom_dashboard')) {
            return;
        }

        // Determine which sections are visible
        $show_quicklinks = $this->is_enabled('show_quicklinks_section');
        $show_contact = $this->is_enabled('show_contact_section') && $this->has_contact_info();
        $show_tip_card = $this->is_enabled('show_tip_card') && $this->has_tip_card_content();
        $show_info_section = $show_contact || $show_tip_card;
        
        // Build grid classes
        $grid_classes = ['sfx-content-grid'];
        if ($show_quicklinks && !$show_info_section) {
            $grid_classes[] = 'sfx-content-grid--quicklinks-only';
        } elseif (!$show_quicklinks && $show_info_section) {
            $grid_classes[] = 'sfx-content-grid--contact-only';
        } elseif ($show_quicklinks && $show_info_section) {
            $grid_classes[] = 'sfx-content-grid--both';
        }

        // Get color mode settings
        $color_mode_default = $this->get_option('color_mode_default', 'light');
        $allow_mode_switch = $this->is_enabled('allow_user_mode_switch');

        // Get sidebar toggle settings
        $allow_sidebar_toggle = $this->is_enabled('allow_sidebar_toggle');
        $sidebar_default_state = $this->get_option('sidebar_default_state', 'visible');

        ?>
        <!-- SFX Custom Dashboard -->
        <div class="sfx-dashboard-container" data-theme="<?php echo esc_attr($color_mode_default); ?>" data-default-theme="<?php echo esc_attr($color_mode_default); ?>" data-sidebar-default="<?php echo esc_attr($sidebar_default_state); ?>">
            <?php $this->render_admin_notices(); ?>
            <?php $this->render_welcome_section(); ?>
            <?php $this->render_positioned_sections('below_header'); ?>
            <?php $this->render_status_bar(); ?>
            <?php $this->render_positioned_sections('below_health_updates'); ?>
            <?php if ($this->is_enabled('show_stats_section')): ?>
                <?php $this->render_stats_grid(); ?>
            <?php endif; ?>
            <?php $this->render_positioned_sections('below_stats'); ?>
            
            <?php if ($show_quicklinks || $show_info_section): ?>
            <div class="<?php echo esc_attr(implode(' ', $grid_classes)); ?>">
                <?php if ($show_quicklinks): ?>
                    <?php $this->render_quicklinks(); ?>
                <?php endif; ?>
                
                <?php if ($show_info_section): ?>
                    <?php $this->render_info_section(); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php $this->render_positioned_sections('below_quicklinks'); ?>

            <?php if ($this->is_enabled('show_form_submissions_section')): ?>
                <?php $this->render_form_submissions(); ?>
            <?php endif; ?>

            <?php $this->render_positioned_sections('below_widgets'); ?>

            <footer class="sfx-dashboard-footer">
                <a href="https://smilefx.io" target="_blank" rel="noopener noreferrer">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z" /></svg>
                    <?php esc_html_e('Made by smilefx.io', 'sfxtheme'); ?>
                </a>
            </footer>
        </div>
        <?php
    }

    /**
     * Render captured admin notices
     *
     * @return void
     */
    private function render_admin_notices(): void
    {
        if (!empty($GLOBALS['sfx_admin_notices'])) {
            echo '<div class="sfx-admin-notices">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices are already escaped by WordPress
            echo $GLOBALS['sfx_admin_notices'];
            echo '</div>';
        }
    }

    /**
     * Render status bar (updates + site health)
     *
     * @return void
     */
    private function render_status_bar(): void
    {
        $show_updates = $this->is_enabled('show_updates_section');
        $show_health = $this->is_enabled('show_site_health_section');

        if (!$show_updates && !$show_health) {
            return;
        }

        ?>
        <div class="sfx-status-bar">
            <?php if ($show_updates): ?>
                <?php $this->render_updates_section(); ?>
            <?php endif; ?>
            <?php if ($show_health): ?>
                <?php $this->render_site_health_section(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render site health section
     *
     * @return void
     */
    private function render_site_health_section(): void
    {
        $health = SystemInfoProvider::get_site_health_status();
        
        $status = $health['status']; // 'good', 'recommended', 'critical'
        $icon = match($status) {
            'critical' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
            'recommended' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>',
            default => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
        };

        ?>
        <div class="sfx-health-section sfx-health-<?php echo esc_attr($status); ?>">
            <div class="sfx-health-status">
                <span class="sfx-health-icon"><?php echo $this->render_icon($icon); ?></span>
                <span class="sfx-health-text"><?php esc_html_e('Site Health', 'sfxtheme'); ?></span>
            </div>
            
            <div class="sfx-health-details">
                <span class="sfx-health-label"><?php echo esc_html($health['label']); ?></span>
                <?php if ($health['issues'] > 0): ?>
                    <span class="sfx-health-badge <?php echo $health['critical'] > 0 ? 'sfx-badge-critical' : 'sfx-badge-warning'; ?>" 
                          title="<?php echo esc_attr(sprintf(__('%d issue(s) need attention', 'sfxtheme'), $health['issues'])); ?>">
                        <?php echo esc_html($health['issues']); ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <a href="<?php echo esc_url(admin_url('site-health.php')); ?>" class="sfx-health-action">
                <?php esc_html_e('View Details', 'sfxtheme'); ?>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </a>
        </div>
        <?php
    }

    /**
     * Render pending updates section
     *
     * @return void
     */
    private function render_updates_section(): void
    {
        // Get update data
        $update_data = wp_get_update_data();
        
        $plugin_updates = $update_data['counts']['plugins'] ?? 0;
        $theme_updates = $update_data['counts']['themes'] ?? 0;
        $wp_updates = $update_data['counts']['wordpress'] ?? 0;
        $total_updates = $update_data['counts']['total'] ?? 0;
        
        // Determine status
        if ($total_updates === 0) {
            $status = 'success';
            $status_text = __('All up to date', 'sfxtheme');
            $status_icon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        } elseif ($wp_updates > 0) {
            $status = 'critical';
            $status_text = __('Updates required', 'sfxtheme');
            $status_icon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>';
        } else {
            $status = 'warning';
            $status_text = sprintf(_n('%d update available', '%d updates available', $total_updates, 'sfxtheme'), $total_updates);
            $status_icon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>';
        }

        ?>
        <div class="sfx-updates-section sfx-updates-<?php echo esc_attr($status); ?>">
            <div class="sfx-updates-status">
                <span class="sfx-updates-icon"><?php echo $this->render_icon($status_icon); ?></span>
                <span class="sfx-updates-text"><?php echo esc_html($status_text); ?></span>
            </div>
            
            <?php if ($total_updates > 0): ?>
            <div class="sfx-updates-details">
                <?php if ($wp_updates > 0): ?>
                    <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="sfx-update-item sfx-update-critical">
                        <span class="sfx-update-count"><?php echo esc_html($wp_updates); ?></span>
                        <span class="sfx-update-label"><?php esc_html_e('WordPress', 'sfxtheme'); ?></span>
                    </a>
                <?php endif; ?>
                
                <?php if ($plugin_updates > 0): ?>
                    <a href="<?php echo esc_url(admin_url('plugins.php?plugin_status=upgrade')); ?>" class="sfx-update-item">
                        <span class="sfx-update-count"><?php echo esc_html($plugin_updates); ?></span>
                        <span class="sfx-update-label"><?php echo esc_html(_n('Plugin', 'Plugins', $plugin_updates, 'sfxtheme')); ?></span>
                    </a>
                <?php endif; ?>
                
                <?php if ($theme_updates > 0): ?>
                    <a href="<?php echo esc_url(admin_url('themes.php')); ?>" class="sfx-update-item">
                        <span class="sfx-update-count"><?php echo esc_html($theme_updates); ?></span>
                        <span class="sfx-update-label"><?php echo esc_html(_n('Theme', 'Themes', $theme_updates, 'sfxtheme')); ?></span>
                    </a>
                <?php endif; ?>
            </div>
            
            <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="sfx-updates-action">
                <?php esc_html_e('View Updates', 'sfxtheme'); ?>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render welcome section
     *
     * @return void
     */
    private function render_welcome_section(): void
    {
        $title = $this->get_option('dashboard_welcome_title', __('Welcome back, {user_name}! 👋', 'sfxtheme'));
        $subtitle = $this->get_option('dashboard_welcome_subtitle', __("Here's what's happening with your projects today.", 'sfxtheme'));
        $logo = $this->get_option('brand_logo', '');
        $allow_mode_switch = $this->is_enabled('allow_user_mode_switch');
        $allow_sidebar_toggle = $this->is_enabled('allow_sidebar_toggle');
        
        // Replace placeholders with dynamic values
        $current_user = wp_get_current_user();
        $title = $this->replace_placeholders($title, $current_user);
        $subtitle = $this->replace_placeholders($subtitle, $current_user);
        
        ?>
        <section class="sfx-welcome-section">
            <?php if (!empty($logo)): ?>
                <div class="sfx-welcome-logo">
                    <img src="<?php echo esc_url($logo); ?>" alt="<?php esc_attr_e('Agency Logo', 'sfxtheme'); ?>" />
                </div>
            <?php endif; ?>
            <div class="sfx-welcome-content">
                <h2 class="sfx-welcome-title"><?php echo esc_html($title); ?></h2>
                <p class="sfx-welcome-subtitle"><?php echo esc_html($subtitle); ?></p>
            </div>
            <div class="sfx-welcome-toggles">
                <?php $this->render_home_link(); ?>
                <?php if ($allow_sidebar_toggle || $allow_mode_switch): ?>
                    <?php if ($allow_sidebar_toggle): ?>
                        <?php $this->render_sidebar_toggle(); ?>
                    <?php endif; ?>
                    <?php if ($allow_mode_switch): ?>
                        <?php $this->render_theme_toggle(); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Render home link button
     *
     * @return void
     */
    private function render_home_link(): void
    {
        ?>
        <div class="sfx-home-link-wrapper">
            <a href="<?php echo esc_url(home_url()); ?>" target="_blank" rel="noopener noreferrer" class="sfx-home-link" aria-label="<?php esc_attr_e('View site', 'sfxtheme'); ?>" title="<?php esc_attr_e('View site', 'sfxtheme'); ?>">
                <svg class="sfx-home-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
            </a>
        </div>
        <?php
    }

    /**
     * Render sidebar toggle button
     *
     * @return void
     */
    private function render_sidebar_toggle(): void
    {
        ?>
        <div class="sfx-sidebar-toggle-wrapper">
            <button type="button" class="sfx-sidebar-toggle" id="sfx-sidebar-toggle" aria-label="<?php esc_attr_e('Toggle admin sidebar', 'sfxtheme'); ?>" title="<?php esc_attr_e('Toggle admin sidebar', 'sfxtheme'); ?>">
                <!-- Hamburger icon (shown when sidebar is collapsed) -->
                <svg class="sfx-sidebar-icon sfx-sidebar-icon-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <!-- Close icon (shown when sidebar is visible) -->
                <svg class="sfx-sidebar-icon sfx-sidebar-icon-close" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <?php
    }

    /**
     * Render theme toggle button
     *
     * @return void
     */
    private function render_theme_toggle(): void
    {
        ?>
        <div class="sfx-theme-toggle-wrapper">
            <button type="button" class="sfx-theme-toggle" id="sfx-theme-toggle" aria-label="<?php esc_attr_e('Toggle color mode', 'sfxtheme'); ?>" title="<?php esc_attr_e('Toggle color mode', 'sfxtheme'); ?>">
                <!-- Sun icon (shown in dark mode) -->
                <svg class="sfx-theme-icon sfx-theme-icon-sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                </svg>
                <!-- Moon icon (shown in light mode) -->
                <svg class="sfx-theme-icon sfx-theme-icon-moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
                <!-- System icon (shown when system mode) -->
                <svg class="sfx-theme-icon sfx-theme-icon-system" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                </svg>
            </button>
        </div>
        <?php
    }

    /**
     * Replace placeholders in text with dynamic values
     *
     * @param string $text
     * @param \WP_User $user
     * @return string
     */
    private function replace_placeholders(string $text, \WP_User $user): string
    {
        $replacements = [
            '{user_name}' => $user->display_name,
            '{first_name}' => $user->first_name,
            '{last_name}' => $user->last_name,
            '{username}' => $user->user_login,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Resolve URL with placeholders
     *
     * @param string $url
     * @return string
     */
    private function resolve_url(string $url): string
    {
        // Replace URL placeholders
        $replacements = [
            '{admin_url}' => admin_url(),
            '{site_url}' => site_url(),
            '{home_url}' => home_url(),
        ];

        $resolved = str_replace(array_keys($replacements), array_values($replacements), $url);

        // If no placeholder was used and URL is relative (no http/https), assume admin URL
        if ($resolved === $url && strpos($url, 'http') !== 0 && strpos($url, '//') !== 0 && strpos($url, '{') !== 0) {
            $resolved = admin_url($url);
        }

        return $resolved;
    }

    /**
     * Get stat configuration by ID
     *
     * @param string $stat_id
     * @return array<string, mixed>|null
     */
    private function get_stat_config(string $stat_id): ?array
    {
        $configs = [
            'posts' => [
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>',
                'label' => __('Published Posts', 'sfxtheme'),
                'url' => admin_url('edit.php'),
                'count' => StatsProvider::get_published_posts_count(),
            ],
            'pages' => [
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>',
                'label' => __('Pages', 'sfxtheme'),
                'url' => admin_url('edit.php?post_type=page'),
                'count' => StatsProvider::get_pages_count(),
            ],
            'media' => [
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>',
                'label' => __('Media Files', 'sfxtheme'),
                'url' => admin_url('upload.php'),
                'count' => StatsProvider::get_media_count(),
            ],
            'users' => [
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>',
                'label' => __('Users', 'sfxtheme'),
                'url' => admin_url('users.php'),
                'count' => StatsProvider::get_users_count(),
            ],
        ];

        // Allow filtering default stat configs (icons, labels, URLs, etc.)
        $configs = apply_filters('sfx_dashboard_stat_configs', $configs, $stat_id);

        if (isset($configs[$stat_id])) {
            $config = $configs[$stat_id];
            
            // Allow filtering individual stat config
            $config = apply_filters('sfx_dashboard_stat_config', $config, $stat_id);
            
            // Sanitize icon if it was modified
            if (isset($config['icon'])) {
                $config['icon'] = wp_kses($config['icon'], Settings::get_allowed_svg_tags());
            }
            
            return $config;
        }

        // Handle custom post types (cpt_*)
        if (strpos($stat_id, 'cpt_') === 0) {
            $post_type = str_replace('cpt_', '', $stat_id);
            $post_type_object = get_post_type_object($post_type);

            if ($post_type_object) {
                $count_obj = wp_count_posts($post_type);
                return [
                    'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>',
                    'label' => $post_type_object->labels->name ?? $post_type_object->label,
                    'url' => admin_url('edit.php?post_type=' . $post_type),
                    'count' => isset($count_obj->publish) ? (int) $count_obj->publish : 0,
                ];
            }
        }

        // Handle custom stats (custom_*) - registered via sfx_custom_dashboard_stats filter
        if (strpos($stat_id, 'custom_') === 0) {
            $custom_stats = apply_filters('sfx_custom_dashboard_stats', []);
            $custom_id = str_replace('custom_', '', $stat_id);

            if (isset($custom_stats[$custom_id])) {
                $config = $custom_stats[$custom_id];

                // Default icon for custom stats (bar chart)
                $default_icon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>';

                // Merge stat_id into config for StatsProvider
                $config['id'] = $custom_id;

                return [
                    'icon' => wp_kses($config['icon'] ?? $default_icon, Settings::get_allowed_svg_tags()),
                    'label' => sanitize_text_field($config['label']),
                    'url' => esc_url_raw($config['url'] ?? admin_url()),
                    'count' => StatsProvider::get_custom_stat_count($config),
                ];
            }
        }

        return null;
    }

    /**
     * Render stats grid
     *
     * @return void
     */
    private function render_stats_grid(): void
    {
        // Get stats items configuration (ordered)
        $stats_items = $this->get_option('stats_items', []);
        
        // If no saved config, use default
        if (empty($stats_items)) {
            $stats_items = Settings::get_default_stats_items();
        }

        // Filter to only enabled items and get their configs
        $enabled_stats = [];
        foreach ($stats_items as $item) {
            if (!empty($item['enabled']) && !empty($item['id'])) {
                $config = $this->get_stat_config($item['id']);
                if ($config) {
                    $enabled_stats[$item['id']] = $config;
                }
            }
        }

        if (empty($enabled_stats)) {
            return;
        }

        ?>
        <section class="sfx-stats-grid">
            <?php foreach ($enabled_stats as $stat_id => $config): ?>
                <a href="<?php echo esc_url($config['url']); ?>" class="sfx-stat-card" data-stat="<?php echo esc_attr($stat_id); ?>">
                    <div class="sfx-stat-icon"><?php echo $this->render_icon($config['icon']); ?></div>
                    <div class="sfx-stat-content">
                        <h3 class="sfx-stat-label"><?php echo esc_html($config['label']); ?></h3>
                        <p class="sfx-stat-value" data-target="<?php echo esc_attr($config['count']); ?>">0</p>
                    </div>
                </a>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /**
     * Check if current user can see a quicklink based on role restrictions
     *
     * @param array<string, mixed> $link The quicklink data
     * @return bool
     */
    private function user_can_see_quicklink(array $link): bool
    {
        $roles = $link['roles'] ?? [];

        // Validate roles array is properly sanitized
        if (!is_array($roles)) {
            return false;
        }

        // If no roles specified or 'all' is selected, show to everyone
        if (empty($roles) || in_array('all', $roles, true)) {
            return true;
        }

        // Get current user's roles
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->exists()) {
            return false;
        }

        $user_roles = (array) ($current_user->roles ?? []);
        
        // Sanitize user roles array
        $user_roles = array_map('sanitize_key', $user_roles);
        $roles = array_map('sanitize_key', $roles);
        
        // Check if user has at least one of the allowed roles
        return !empty(array_intersect($roles, $user_roles));
    }

    /**
     * Check if user can see a quicklink group
     *
     * @param array $group Group data with roles
     * @return bool
     */
    private function user_can_see_group(array $group): bool
    {
        $roles = $group['roles'] ?? [];
        
        // Validate roles array is properly sanitized
        if (!is_array($roles)) {
            return false;
        }
        
        // If no roles are specified, or 'all' is present, show to everyone
        if (empty($roles) || in_array('all', $roles, true)) {
            return true;
        }

        // Get current user's roles
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->exists()) {
            return false;
        }

        $user_roles = (array) ($current_user->roles ?? []);
        
        // Sanitize user roles array
        $user_roles = array_map('sanitize_key', $user_roles);
        $roles = array_map('sanitize_key', $roles);
        
        // Check if user has at least one of the allowed roles
        return !empty(array_intersect($roles, $user_roles));
    }

    /**
     * Render quicklinks section with groups support
     *
     * @return void
     */
    private function render_quicklinks(): void
    {
        $quicklinks_data = $this->get_option('quicklinks_sortable', []);
        $groups = Settings::get_ordered_quicklink_groups(is_array($quicklinks_data) ? $quicklinks_data : []);
        $default_icon = Settings::DEFAULT_CUSTOM_ICON;
        $available_roles = Settings::get_available_roles();

        // Filter groups that user can see and have enabled links
        $visible_groups = [];
        foreach ($groups as $group) {
            // Check group-level role restriction
            if (!$this->user_can_see_group($group)) {
                continue;
            }

            // Filter to only enabled links with valid title/url and user has permission
            $enabled_links = array_filter($group['quicklinks'] ?? [], function($link) {
                return !empty($link['enabled']) 
                    && !empty($link['title']) 
                    && !empty($link['url'])
                    && $this->user_can_see_quicklink($link);
            });

            // Skip empty groups
            if (!empty($enabled_links)) {
                $visible_groups[] = [
                    'group' => $group,
                    'links' => $enabled_links,
                ];
            }
        }

        // Don't render anything if no visible groups
        if (empty($visible_groups)) {
            return;
        }

        ?>
        <section class="sfx-quicklinks-section">
            <?php foreach ($visible_groups as $index => $group_data): 
                $group = $group_data['group'];
                $enabled_links = $group_data['links'];
                $group_title = $group['title'] ?? __('Quick Actions', 'sfxtheme');
                $group_roles = $group['roles'] ?? [];
                $group_has_role_restriction = !empty($group_roles) && !in_array('all', $group_roles);
            ?>
                <div class="sfx-quicklinks-group<?php echo $index > 0 ? ' sfx-quicklinks-group-stacked' : ''; ?>">
                    <h3 class="sfx-quicklinks-group-title">
                        <?php echo esc_html($group_title); ?>
                        <?php 
                        // Show group role badges to admins
                        if ($group_has_role_restriction && current_user_can('manage_options')): 
                        ?>
                            <span class="sfx-group-role-badges">
                                <?php foreach ($group_roles as $role_slug): 
                                    $role_name = $available_roles[$role_slug] ?? $role_slug;
                                ?>
                                    <span class="sfx-group-role-badge"><?php echo esc_html($role_name); ?></span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <div class="sfx-quicklinks-grid">
                        <?php foreach ($enabled_links as $link): 
                            $url = $this->resolve_url($link['url'] ?? '');
                            $icon = !empty($link['icon']) ? $link['icon'] : $default_icon;
                            $title = $link['title'] ?? '';
                            $target = $link['target'] ?? '_self';
                            $roles = $link['roles'] ?? [];
                            $has_role_restriction = !empty($roles) && !in_array('all', $roles);
                            $role_badges = '';
                            
                            // Show role badges only to admins for restricted links
                            if ($has_role_restriction && current_user_can('manage_options')) {
                                $role_badges = '<div class="sfx-quicklink-role-badges">';
                                foreach ($roles as $role_slug) {
                                    $role_name = $available_roles[$role_slug] ?? $role_slug;
                                    $role_badges .= '<span class="sfx-quicklink-role-badge">' . esc_html($role_name) . '</span>';
                                }
                                $role_badges .= '</div>';
                            }
                            ?>
                            <a href="<?php echo esc_url($url); ?>"<?php if ($target === '_blank'): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?> class="sfx-quicklink-card<?php echo $has_role_restriction ? ' sfx-quicklink-restricted' : ''; ?>">
                                <?php echo $role_badges; ?>
                                <span class="sfx-quicklink-icon"><?php echo $this->render_icon($icon); ?></span>
                                <span class="sfx-quicklink-text"><?php echo wp_kses($title, Settings::get_allowed_title_tags()); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /**
     * Check if contact info has any data
     *
     * @return bool
     */
    private function has_contact_info(): bool
    {
        $company = $this->get_option('contact_company', '');
        $email = $this->get_option('contact_email', '');
        $phone = $this->get_option('contact_phone', '');
        $website = $this->get_option('contact_website', '');
        $address = $this->get_option('contact_address', '');

        return !empty($company) || !empty($email) || !empty($phone) || !empty($website) || !empty($address);
    }

    /**
     * Check if tip card has content to display
     *
     * @return bool
     */
    private function has_tip_card_content(): bool
    {
        $content = $this->get_option('tip_card_content', __('You can use <button type="button" class="sfx-cmd-shortcut" data-shortcut="cmd+k"><kbd>Strg</kbd>/<kbd>Cmd</kbd>+<kbd>K</kbd></button> to access the command tool to manage WordPress.', 'sfxtheme'));
        return !empty($content);
    }

    /**
     * Render info section (tip card and contact card wrapper)
     *
     * @return void
     */
    private function render_info_section(): void
    {
        ?>
        <aside class="sfx-info-section">
            <?php $this->render_tip_card(); ?>
            <?php $this->render_contact_info(); ?>
        </aside>
        <?php
    }

    /**
     * Render contact info section
     *
     * @return void
     */
    private function render_contact_info(): void
    {
        if (!$this->is_enabled('show_contact_section') || !$this->has_contact_info()) {
            return;
        }

        $card_title = $this->get_option('contact_card_title', __('Contact', 'sfxtheme'));
        $card_subtitle = $this->get_option('contact_card_subtitle', '');
        $company = $this->get_option('contact_company', '');
        $email = $this->get_option('contact_email', '');
        $phone = $this->get_option('contact_phone', '');
        $website = $this->get_option('contact_website', '');
        $address = $this->get_option('contact_address', '');
        // Fallback to brand_logo for backwards compatibility
        $logo = $this->get_option('contact_logo', '');
        if (empty($logo)) {
            $logo = $this->get_option('brand_logo', '');
        }
        $logo_height = absint($this->get_option('contact_logo_height', 48));

        ?>
            <div class="sfx-info-card sfx-contact-info">
                <?php if (!empty($logo)): ?>
                    <div class="sfx-contact-logo">
                        <img src="<?php echo esc_url($logo); ?>" alt="<?php esc_attr_e('Agency Logo', 'sfxtheme'); ?>" style="height: <?php echo esc_attr($logo_height); ?>px; width: auto;" />
                    </div>
                <?php endif; ?>
                <h2 class="sfx-section-title"><?php echo esc_html($card_title); ?></h2>
                <?php if (!empty($card_subtitle)): ?>
                    <p class="sfx-contact-subtitle"><?php echo esc_html($card_subtitle); ?></p>
                <?php endif; ?>
                <div class="sfx-info-content">
                    <?php if (!empty($company)): ?>
                        <div class="sfx-contact-item">
                            <span class="sfx-contact-icon">
                                <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>'); ?>
                            </span>
                            <span class="sfx-contact-text"><?php echo esc_html($company); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($email)): ?>
                        <div class="sfx-contact-item">
                            <span class="sfx-contact-icon">
                                <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>'); ?>
                            </span>
                            <a href="mailto:<?php echo esc_attr($email); ?>" class="sfx-contact-link">
                                <?php echo esc_html($email); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($phone)): ?>
                        <div class="sfx-contact-item">
                            <span class="sfx-contact-icon">
                                <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>'); ?>
                            </span>
                            <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>" class="sfx-contact-link">
                                <?php echo esc_html($phone); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($website)): ?>
                        <div class="sfx-contact-item">
                            <span class="sfx-contact-icon">
                                <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" /></svg>'); ?>
                            </span>
                            <a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener noreferrer" class="sfx-contact-link">
                                <?php echo esc_html(preg_replace('#^https?://#', '', $website)); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($address)): ?>
                        <div class="sfx-contact-item">
                            <span class="sfx-contact-icon">
                                <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>'); ?>
                            </span>
                            <span class="sfx-contact-text"><?php echo esc_html($address); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php
    }

    /**
     * Render form submissions section
     *
     * @return void
     */
    private function render_form_submissions(): void
    {
        // Check if Bricks form submissions table exists
        if (!FormSubmissionsProvider::table_exists()) {
            return;
        }

        $forms_summary = FormSubmissionsProvider::get_forms_summary();
        $total_count = FormSubmissionsProvider::get_total_count();
        $all_submissions_url = FormSubmissionsProvider::get_admin_url();

        ?>
        <section class="sfx-form-submissions-section">
            <div class="sfx-section-header">
                <h2 class="sfx-section-title">
                    <?php esc_html_e('Form Submissions', 'sfxtheme'); ?>
                    <?php if ($total_count > 0): ?>
                        <span class="sfx-section-badge"><?php echo esc_html((string) $total_count); ?></span>
                    <?php endif; ?>
                </h2>
                <a href="<?php echo esc_url($all_submissions_url); ?>" class="sfx-section-action">
                    <?php esc_html_e('View All', 'sfxtheme'); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            <?php if (empty($forms_summary)): ?>
                <div class="sfx-submissions-empty">
                    <span class="sfx-submissions-empty-icon">
                        <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>'); ?>
                    </span>
                    <p><?php esc_html_e('No form submissions yet.', 'sfxtheme'); ?></p>
                </div>
            <?php else: ?>
                <div class="sfx-forms-list">
                    <?php foreach ($forms_summary as $form): 
                        $form_url = FormSubmissionsProvider::get_admin_url($form['form_id']);
                        $form_name = !empty($form['form_name']) ? $form['form_name'] : $form['form_id'];
                    ?>
                        <a href="<?php echo esc_url($form_url); ?>" class="sfx-form-row" title="<?php esc_attr_e('View submissions', 'sfxtheme'); ?>">
                            <span class="sfx-form-icon">
                                <?php echo $this->render_icon('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>'); ?>
                            </span>
                            <span class="sfx-form-name"><?php echo esc_html($form_name); ?></span>
                            <span class="sfx-form-count">
                                <?php 
                                printf(
                                    /* translators: %d: number of submissions */
                                    esc_html(_n('%d entry', '%d entries', $form['count'], 'sfxtheme')),
                                    $form['count']
                                );
                                ?>
                            </span>
                            <span class="sfx-form-arrow">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Render WordPress dashboard widgets
     *
     * @return void
     */
    private function render_dashboard_widgets(): void
    {
        $widgets_data = $this->get_option('enabled_dashboard_widgets', []);
        
        if (empty($widgets_data) || !is_array($widgets_data)) {
            return;
        }

        // Extract enabled widget IDs in order
        $enabled_widgets = [];
        foreach ($widgets_data as $widget) {
            // Support both old format (simple array of IDs) and new format (array of objects)
            if (is_array($widget) && !empty($widget['enabled']) && !empty($widget['id'])) {
                $enabled_widgets[] = $widget['id'];
            } elseif (is_string($widget)) {
                // Legacy format support
                $enabled_widgets[] = $widget;
            }
        }

        if (empty($enabled_widgets)) {
            return;
        }

        // Load dashboard functions if not already loaded
        if (!function_exists('wp_dashboard_right_now')) {
            require_once ABSPATH . 'wp-admin/includes/dashboard.php';
        }

        ?>
        <section class="sfx-wp-dashboard-widgets-section">
            <div class="sfx-dashboard-widgets-grid">
                <?php
                foreach ($enabled_widgets as $widget_id) {
                    $this->render_single_widget($widget_id);
                }
                ?>
            </div>
        </section>
        <?php
    }

    /**
     * Render a single dashboard widget
     *
     * @param string $widget_id
     * @return void
     */
    private function render_single_widget(string $widget_id): void
    {
        // Map of widget IDs to their callback functions and titles
        $widget_map = [
            'dashboard_site_health' => [
                'title' => __('Site Health Status', 'sfxtheme'),
                'callback' => 'wp_dashboard_site_health',
            ],
            'dashboard_right_now' => [
                'title' => __('At a Glance', 'sfxtheme'),
                'callback' => 'wp_dashboard_right_now',
            ],
            'dashboard_activity' => [
                'title' => __('Activity', 'sfxtheme'),
                'callback' => 'wp_dashboard_site_activity',
            ],
            'dashboard_quick_press' => [
                'title' => __('Quick Draft', 'sfxtheme'),
                'callback' => 'wp_dashboard_quick_press',
            ],
            'dashboard_primary' => [
                'title' => __('WordPress Events and News', 'sfxtheme'),
                'callback' => 'wp_dashboard_primary',
            ],
        ];

        // Check if it's a known widget
        if (!isset($widget_map[$widget_id])) {
            // Try to find in global meta boxes (for plugin widgets)
            global $wp_meta_boxes;
            if (isset($wp_meta_boxes['dashboard'])) {
                foreach ($wp_meta_boxes['dashboard'] as $context => $priority_widgets) {
                    foreach ($priority_widgets as $priority => $widgets) {
                        if (isset($widgets[$widget_id]) && isset($widgets[$widget_id]['callback'])) {
                            $widget_map[$widget_id] = [
                                'title' => $widgets[$widget_id]['title'] ?? ucwords(str_replace('_', ' ', $widget_id)),
                                'callback' => $widgets[$widget_id]['callback'],
                                'args' => $widgets[$widget_id]['args'] ?? [],
                            ];
                            break 2;
                        }
                    }
                }
            }
        }

        if (!isset($widget_map[$widget_id])) {
            return;
        }

        $widget = $widget_map[$widget_id];
        $callback = $widget['callback'];

        // Verify callback is callable
        if (!is_callable($callback)) {
            return;
        }

        ?>
        <div class="sfx-dashboard-widget-card">
            <div class="sfx-dashboard-widget-header">
                <h3 class="sfx-dashboard-widget-title"><?php echo esc_html($widget['title']); ?></h3>
            </div>
            <div class="sfx-dashboard-widget-content">
                <?php
                // Capture widget output
                ob_start();
                call_user_func($callback, null, $widget['args'] ?? []);
                $widget_content = ob_get_clean();
                
                // Output the widget content (allow dashboard widget HTML)
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $widget_content;
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the tip card
     *
     * @return void
     */
    private function render_tip_card(): void
    {
        if (!$this->is_enabled('show_tip_card')) {
            return;
        }

        $title = $this->get_option('tip_card_title', __('Tip', 'sfxtheme'));
        $content = $this->get_option('tip_card_content', __('You can use <button type="button" class="sfx-cmd-shortcut" data-shortcut="cmd+k"><kbd>Strg</kbd>/<kbd>Cmd</kbd>+<kbd>K</kbd></button> to access the command tool to manage WordPress.', 'sfxtheme'));

        if (empty($content)) {
            return;
        }

        ?>
        <div class="sfx-info-card sfx-tip-card">
            <span class="sfx-tip-icon">💡</span>
            <div class="sfx-tip-body">
                <?php if (!empty($title)): ?>
                    <h3 class="sfx-tip-title"><?php echo esc_html($title); ?></h3>
                <?php endif; ?>
                <div class="sfx-tip-content">
                    <?php echo wp_kses_post($content); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render positioned sections (note and/or widgets) at a specific position
     * 
     * When both sections are positioned at the same location, note renders first,
     * then widgets below it.
     *
     * @param string $position The position identifier
     * @return void
     */
    private function render_positioned_sections(string $position): void
    {
        $this->render_note_at_position($position);
        $this->render_widgets_at_position($position);
    }

    /**
     * Render note section at a specific position if enabled
     *
     * @param string $position The position identifier
     * @return void
     */
    private function render_note_at_position(string $position): void
    {
        if (!$this->is_enabled('show_note_section')) {
            return;
        }

        $note_position = $this->get_option('note_position', 'below_quicklinks');
        
        if ($note_position === $position) {
            $this->render_note_section();
        }
    }

    /**
     * Render dashboard widgets at a specific position if enabled
     *
     * @param string $position The position identifier
     * @return void
     */
    private function render_widgets_at_position(string $position): void
    {
        if (!$this->is_enabled('show_dashboard_widgets')) {
            return;
        }

        $widgets_position = $this->get_option('dashboard_widgets_position', 'below_widgets');
        
        if ($widgets_position === $position) {
            $this->render_dashboard_widgets();
        }
    }

    /**
     * Render the note section
     *
     * @return void
     */
    private function render_note_section(): void
    {
        $title = $this->get_option('note_title', '');
        $content = $this->get_option('note_content', '');

        if (empty($content)) {
            return;
        }

        ?>
        <section class="sfx-note-section">
            <?php if (!empty($title)): ?>
                <h2 class="sfx-section-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            <div class="sfx-note-content">
                <?php echo wp_kses_post($content); ?>
            </div>
        </section>
        <?php
    }
}

