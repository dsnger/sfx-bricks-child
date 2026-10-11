<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Wiring only. The module is registered by the auto-discovery of
 * SFXBricksChildTheme (get_feature_config()); with enable_site_check off
 * nothing here is constructed.
 */
final class Controller
{
    public function __construct()
    {
        AdminPage::register();
        OutsideEndpoints::register();
        Runs::register();
        DashboardBox::register();

        // Probe cleanup (spec lifecycle step 4): daily by its own hook,
        // regardless of monitoring, and whenever the check page loads.
        add_action('admin_init', [self::class, 'maybe_schedule_cleanup']);
        add_action(Options::hook('probe_cleanup'), [self::class, 'run_cleanup']);
        add_action('load-tools_page_' . AdminPage::$menu_slug, [AdminPage::class, 'on_load']);
    }

    public static function maybe_schedule_cleanup(): void
    {
        if (!wp_next_scheduled(Options::hook('probe_cleanup'))) {
            wp_schedule_event(time(), 'daily', Options::hook('probe_cleanup'));
        }
    }

    public static function run_cleanup(): void
    {
        Probe::cleanup();
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'url' => admin_url('tools.php?page=sfx-site-check'),
            // Literals, so string extraction finds them.
            'page_title' => __('Sicherheits-Check', 'sfxtheme'),
            'description' => __('Checks the site for exposed files, risky settings and launch mistakes, and shows what to fix first.', 'sfxtheme'),
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_site_check',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing SiteCheck Controller class in theme',
        ];
    }
}
