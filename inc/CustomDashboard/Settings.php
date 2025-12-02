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
    public static $OPTION_GROUP;
    public static $OPTION_NAME;

    /**
     * Register all settings for custom dashboard
     *
     * @return void
     */
    public static function register(): void
    {
        // Initialize static properties
        self::$OPTION_GROUP = 'sfx_custom_dashboard';
        self::$OPTION_NAME = 'sfx_custom_dashboard';
        
        // Register settings through consolidated system
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
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
     * @return array<string, array<string, string>>
     */
    public static function get_brand_colors(): array
    {
        $options = get_option(self::$OPTION_NAME, []);
        $primary = $options['brand_primary_color'] ?? '#667eea';
        $secondary = $options['brand_secondary_color'] ?? '#764ba2';
        $accent = $options['brand_accent_color'] ?? '#f093fb';
        
        return [
            'primary' => ['label' => __('Primary', 'sfxtheme'), 'color' => $primary],
            'secondary' => ['label' => __('Secondary', 'sfxtheme'), 'color' => $secondary],
            'accent' => ['label' => __('Accent', 'sfxtheme'), 'color' => $accent],
            'white' => ['label' => __('White', 'sfxtheme'), 'color' => '#ffffff'],
            'black' => ['label' => __('Black', 'sfxtheme'), 'color' => '#000000'],
            'light-gray' => ['label' => __('Light Gray', 'sfxtheme'), 'color' => '#e2e8f0'],
            'gray' => ['label' => __('Gray', 'sfxtheme'), 'color' => '#94a3b8'],
            'dark-gray' => ['label' => __('Dark Gray', 'sfxtheme'), 'color' => '#475569'],
        ];
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
            ],
            [
                'id' => 'new_page',
                'title' => __('New Page', 'sfxtheme'),
                'url' => 'post-new.php?post_type=page',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'media',
                'title' => __('Media', 'sfxtheme'),
                'url' => 'upload.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'themes',
                'title' => __('Themes', 'sfxtheme'),
                'url' => 'themes.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'plugins',
                'title' => __('Plugins', 'sfxtheme'),
                'url' => 'plugins.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'users',
                'title' => __('Users', 'sfxtheme'),
                'url' => 'users.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'settings',
                'title' => __('Settings', 'sfxtheme'),
                'url' => 'options-general.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'bricks',
                'title' => __('Bricks', 'sfxtheme'),
                'url' => 'admin.php?page=bricks',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'menus',
                'title' => __('Menus', 'sfxtheme'),
                'url' => 'nav-menus.php',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>',
                'enabled' => 1,
            ],
            [
                'id' => 'contact_info',
                'title' => __('Contact Info', 'sfxtheme'),
                'url' => 'edit.php?post_type=sfx_contact_info',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>',
                'enabled' => 0,
            ],
            [
                'id' => 'social_media',
                'title' => __('Social Media', 'sfxtheme'),
                'url' => 'edit.php?post_type=sfx_social_account',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>',
                'enabled' => 0,
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
                'default' => 1,
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
            [
                'id' => 'brand_primary_color',
                'label' => __('Primary Brand Color', 'sfxtheme'),
                'description' => __('Main brand color for buttons, links, and accents.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#667eea',
            ],
            [
                'id' => 'brand_secondary_color',
                'label' => __('Secondary Brand Color', 'sfxtheme'),
                'description' => __('Secondary brand color for hover states and highlights.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#764ba2',
            ],
            [
                'id' => 'brand_accent_color',
                'label' => __('Accent Color', 'sfxtheme'),
                'description' => __('Accent color for special elements.', 'sfxtheme'),
                'type' => 'color',
                'default' => '#f093fb',
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
                'default' => 0,
            ],
            [
                'id' => 'brand_border_color',
                'label' => __('Border Color', 'sfxtheme'),
                'description' => __('Border color for cards and elements.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'light-gray',
            ],
            [
                'id' => 'brand_shadow_enabled',
                'label' => __('Enable Shadows', 'sfxtheme'),
                'description' => __('Add shadow effects to cards and elements.', 'sfxtheme'),
                'type' => 'checkbox',
                'default' => 1,
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
                'default' => 'white',
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
                'default' => 'white',
            ],
            [
                'id' => 'card_text_color',
                'label' => __('Card Text Color', 'sfxtheme'),
                'description' => __('Text color for quick action cards.', 'sfxtheme'),
                'type' => 'brand_color_select',
                'default' => 'dark-gray',
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
                'default' => 'light-gray',
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
                'default' => 'white',
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
            [
                'id' => 'quicklinks_gap',
                'label' => __('Quick Links Gap', 'sfxtheme'),
                'description' => __('Gap between quick action boxes in pixels (5-50).', 'sfxtheme'),
                'type' => 'number',
                'default' => 15,
            ],
            // Layout Settings
            [
                'id' => 'stats_columns',
                'label' => __('Stats Columns', 'sfxtheme'),
                'description' => __('Number of columns for statistics cards (2-6).', 'sfxtheme'),
                'type' => 'number',
                'default' => 4,
            ],
            [
                'id' => 'stats_gap',
                'label' => __('Stats Gap', 'sfxtheme'),
                'description' => __('Gap between statistics cards in pixels (5-50).', 'sfxtheme'),
                'type' => 'number',
                'default' => 20,
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
                'id' => 'predefined_quicklinks',
                'label' => __('Predefined Quick Links', 'sfxtheme'),
                'description' => __('Enable or disable predefined quick action links.', 'sfxtheme'),
                'type' => 'quicklinks',
                'default' => self::get_default_quicklinks(),
            ],
            [
                'id' => 'custom_quicklinks',
                'label' => __('Custom Quick Links', 'sfxtheme'),
                'description' => __('Add your own custom quick action links.', 'sfxtheme'),
                'type' => 'custom_quicklinks',
                'default' => [],
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
        register_setting(self::$OPTION_GROUP, self::$OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        // Main Settings Section
        add_settings_section(
            self::$OPTION_NAME . '_main',
            __('General Settings', 'sfxtheme'),
            [self::class, 'render_main_section'],
            self::$OPTION_GROUP
        );

        // Section Visibility
        add_settings_section(
            self::$OPTION_NAME . '_sections',
            __('Dashboard Sections', 'sfxtheme'),
            [self::class, 'render_sections_section'],
            self::$OPTION_GROUP
        );

        // Stats Settings
        add_settings_section(
            self::$OPTION_NAME . '_stats',
            __('Statistics Settings', 'sfxtheme'),
            [self::class, 'render_stats_section'],
            self::$OPTION_GROUP
        );

        // Quicklinks Settings
        add_settings_section(
            self::$OPTION_NAME . '_quicklinks',
            __('Quick Actions Settings', 'sfxtheme'),
            [self::class, 'render_quicklinks_section'],
            self::$OPTION_GROUP
        );

        // Contact Settings
        add_settings_section(
            self::$OPTION_NAME . '_contact',
            __('Contact Information', 'sfxtheme'),
            [self::class, 'render_contact_section'],
            self::$OPTION_GROUP
        );

        // Brand Settings
        add_settings_section(
            self::$OPTION_NAME . '_brand',
            __('Brand & Styling', 'sfxtheme'),
            [self::class, 'render_brand_section'],
            self::$OPTION_GROUP
        );

        // Add fields to sections
        foreach (self::get_fields() as $field) {
            $section = self::$OPTION_NAME . '_main';
            
            // Determine section based on field
            if ($field['id'] === 'stats_items') {
                $section = self::$OPTION_NAME . '_stats';
            } elseif (strpos($field['id'], 'show_') === 0 && strpos($field['id'], 'note_') !== 0) {
                $section = self::$OPTION_NAME . '_sections';
            } elseif (strpos($field['id'], 'quicklinks') !== false && !in_array($field['id'], ['quicklinks_columns', 'quicklinks_gap'])) {
                $section = self::$OPTION_NAME . '_quicklinks';
            } elseif (strpos($field['id'], 'contact_') === 0) {
                $section = self::$OPTION_NAME . '_contact';
            } elseif ($field['id'] === 'custom_quicklinks') {
                $section = self::$OPTION_NAME . '_quicklinks';
            } elseif ($field['id'] === 'enabled_dashboard_widgets' || $field['id'] === 'show_dashboard_widgets') {
                $section = self::$OPTION_NAME . '_sections';
            } elseif (in_array($field['id'], ['note_title', 'note_content', 'show_note_section', 'form_submissions_limit'])) {
                $section = self::$OPTION_NAME . '_sections';
            } elseif (strpos($field['id'], 'brand_') === 0 || strpos($field['id'], 'card_') === 0 || in_array($field['id'], ['stats_columns', 'stats_gap', 'quicklinks_columns', 'quicklinks_gap'])) {
                $section = self::$OPTION_NAME . '_brand';
            }

            add_settings_field(
                $field['id'],
                $field['label'],
                [self::class, 'render_field'],
                self::$OPTION_GROUP,
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
        $options = get_option(self::$OPTION_NAME, []);
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
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                       value="1" 
                       <?php checked($checked, 1); ?> />
                <label for="<?php echo $id; ?>"><?php echo esc_html($args['description']); ?></label>
                <?php
                break;

            case 'text':
                ?>
                <input type="text" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       class="regular-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'html_textarea':
                ?>
                <textarea 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                       rows="6"
                       class="large-text code"
                       style="font-family: monospace;"><?php echo esc_textarea($value); ?></textarea>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'email':
                ?>
                <input type="email" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       class="regular-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'url':
                ?>
                <input type="url" 
                       id="<?php echo $id; ?>" 
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" 
                       class="regular-text" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'textarea':
                ?>
                <textarea id="<?php echo $id; ?>" 
                          name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                          rows="3" 
                          class="large-text"><?php echo esc_textarea($value); ?></textarea>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'quicklinks':
                self::render_quicklinks_field($id, $value);
                break;

            case 'custom_quicklinks':
                self::render_custom_quicklinks_field($id, $value);
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
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
                       value="<?php echo esc_attr($value); ?>" />
                <p class="description"><?php echo esc_html($args['description']); ?></p>
                <?php
                break;

            case 'brand_color_select':
                $color_options = self::get_brand_colors();
                $current_color = $color_options[$value]['color'] ?? '#667eea';
                ?>
                <div class="sfx-color-select-wrapper">
                    <span class="sfx-color-preview" style="background-color: <?php echo esc_attr($current_color); ?>;"></span>
                    <select id="<?php echo $id; ?>" 
                            name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]"
                            class="sfx-color-select"
                            data-colors='<?php echo esc_attr(wp_json_encode(array_map(function($opt) { return $opt['color']; }, $color_options))); ?>'>
                        <?php foreach ($color_options as $option_value => $option_data): ?>
                            <option value="<?php echo esc_attr($option_value); ?>" 
                                    data-color="<?php echo esc_attr($option_data['color']); ?>"
                                    <?php selected($value, $option_value); ?>>
                                <?php echo esc_html($option_data['label']); ?>
                            </option>
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
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
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
                           name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" 
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
     * Render predefined quicklinks field
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    private static function render_quicklinks_field(string $id, $value): void
    {
        $quicklinks = is_array($value) && !empty($value) ? $value : self::get_default_quicklinks();
        ?>
        <table class="widefat sfx-quicklinks-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Enabled', 'sfxtheme'); ?></th>
                    <th><?php esc_html_e('Icon', 'sfxtheme'); ?></th>
                    <th><?php esc_html_e('Title', 'sfxtheme'); ?></th>
                    <th><?php esc_html_e('URL', 'sfxtheme'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quicklinks as $index => $link): ?>
                <tr>
                    <td>
                        <input type="checkbox" 
                               name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>][<?php echo $index; ?>][enabled]" 
                               value="1" 
                               <?php checked(!empty($link['enabled']), true); ?> />
                        <input type="hidden" 
                               name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>][<?php echo $index; ?>][id]" 
                               value="<?php echo esc_attr($link['id']); ?>" />
                    </td>
                    <td><div style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;"><?php echo wp_kses_post($link['icon']); ?></div></td>
                    <td><?php echo esc_html($link['title']); ?></td>
                    <td><code><?php echo esc_html($link['url']); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
                                   name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][enabled]" 
                                   value="1" 
                                   <?php checked(!empty($item['enabled'])); ?> />
                            <input type="hidden" 
                                   name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][<?php echo $index; ?>][id]" 
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
                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][]" 
                       value="<?php echo esc_attr($widget_id); ?>"
                       <?php echo $checked; ?> />
                <span><?php echo esc_html($widget_title); ?></span>
            </label>
            <?php
        }
        echo '</div>';
    }

    /**
     * Render custom quicklinks field
     *
     * @param string $id
     * @param mixed $value
     * @return void
     */
    private static function render_custom_quicklinks_field(string $id, $value): void
    {
        $custom_links = is_array($value) ? $value : [];
        ?>
        <div id="sfx-custom-quicklinks-wrapper">
            <table class="widefat sfx-custom-quicklinks-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Icon', 'sfxtheme'); ?></th>
                        <th><?php esc_html_e('Title', 'sfxtheme'); ?></th>
                        <th><?php esc_html_e('URL', 'sfxtheme'); ?></th>
                        <th><?php esc_html_e('Action', 'sfxtheme'); ?></th>
                    </tr>
                </thead>
                <tbody id="sfx-custom-quicklinks-body">
                    <?php if (!empty($custom_links)): ?>
                        <?php foreach ($custom_links as $index => $link): ?>
                        <tr class="sfx-custom-link-row">
                            <td>
                                <div class="sfx-icon-input-wrapper">
                                    <textarea 
                                           name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>][<?php echo $index; ?>][icon]" 
                                           rows="3"
                                           class="sfx-svg-icon-input" 
                                           placeholder="SVG code"><?php echo esc_textarea($link['icon'] ?? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>'); ?></textarea>
                                    <div class="sfx-icon-preview" title="<?php esc_attr_e('Icon Preview', 'sfxtheme'); ?>"><?php echo $link['icon'] ?? ''; ?></div>
                                </div>
                            </td>
                            <td>
                                <input type="text" 
                                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>][<?php echo $index; ?>][title]" 
                                       value="<?php echo esc_attr($link['title'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="<?php esc_attr_e('Link Title', 'sfxtheme'); ?>" />
                            </td>
                            <td>
                                <input type="text" 
                                       name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>][<?php echo $index; ?>][url]" 
                                       value="<?php echo esc_attr($link['url'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="<?php esc_attr_e('URL or admin page', 'sfxtheme'); ?>" />
                            </td>
                            <td>
                                <button type="button" class="button sfx-remove-link"><?php esc_html_e('Remove', 'sfxtheme'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button type="button" id="sfx-add-custom-link" class="button button-secondary" style="margin-top: 10px;">
                <?php esc_html_e('Add Custom Link', 'sfxtheme'); ?>
            </button>
        </div>
        <p class="description" style="margin-top: 15px;">
            <?php esc_html_e('Add custom quick action links.', 'sfxtheme'); ?>
            <br>
            <strong><?php esc_html_e('URL:', 'sfxtheme'); ?></strong> <?php esc_html_e('Use admin paths (e.g., "edit.php") or placeholders:', 'sfxtheme'); ?>
            <code>{admin_url}</code>, <code>{site_url}</code>, <code>{home_url}</code>
            <br>
            <span style="color: #666; font-size: 12px;"><?php esc_html_e('Example:', 'sfxtheme'); ?> <code>{admin_url}edit.php?post_type=page</code> <?php esc_html_e('or', 'sfxtheme'); ?> <code>{site_url}/contact</code></span>
            <br>
            <strong><?php esc_html_e('Icon:', 'sfxtheme'); ?></strong> <?php esc_html_e('Paste SVG code. We recommend', 'sfxtheme'); ?> <a href="https://heroicons.com/" target="_blank" rel="noopener">Heroicons</a> <?php esc_html_e('(outline style, 24x24).', 'sfxtheme'); ?>
        </p>
        <?php
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

                case 'quicklinks':
                    // Check if it's JSON encoded (from hidden field)
                    if (is_string($input[$id]) && !empty($input[$id])) {
                        $decoded = json_decode($input[$id], true);
                        $output[$id] = is_array($decoded) ? $decoded : self::sanitize_quicklinks($input[$id]);
                    } else {
                        $output[$id] = self::sanitize_quicklinks($input[$id]);
                    }
                    break;

                case 'custom_quicklinks':
                    // Check if it's JSON encoded (from hidden field)
                    if (is_string($input[$id]) && !empty($input[$id])) {
                        $decoded = json_decode($input[$id], true);
                        $output[$id] = is_array($decoded) ? $decoded : self::sanitize_custom_quicklinks($input[$id]);
                    } else {
                        $output[$id] = self::sanitize_custom_quicklinks($input[$id]);
                    }
                    break;

                case 'color':
                    $output[$id] = sanitize_hex_color($input[$id]);
                    break;

                case 'brand_color_select':
                    $allowed_values = ['primary', 'secondary', 'accent', 'white', 'black', 'light-gray', 'gray', 'dark-gray'];
                    $output[$id] = in_array($input[$id], $allowed_values) ? sanitize_key($input[$id]) : $default;
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
     * Sanitize predefined quicklinks
     *
     * @param mixed $input
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_quicklinks($input): array
    {
        if (!is_array($input)) {
            return self::get_default_quicklinks();
        }

        $sanitized = [];
        $defaults = self::get_default_quicklinks();

        // Define allowed SVG tags and attributes
        $allowed_svg = self::get_allowed_svg_tags();

        foreach ($defaults as $index => $default_link) {
            if (isset($input[$index])) {
                $sanitized[$index] = [
                    'id' => sanitize_key($input[$index]['id'] ?? $default_link['id']),
                    'title' => sanitize_text_field($default_link['title']),
                    'url' => sanitize_text_field($default_link['url']),
                    'icon' => wp_kses($default_link['icon'], $allowed_svg),
                    'enabled' => !empty($input[$index]['enabled']) ? 1 : 0,
                ];
            } else {
                $sanitized[$index] = $default_link;
                $sanitized[$index]['enabled'] = 0;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize custom quicklinks
     *
     * @param mixed $input
     * @return array<int, array<string, string>>
     */
    private static function sanitize_custom_quicklinks($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        // Define allowed SVG tags and attributes
        $allowed_svg = self::get_allowed_svg_tags();

        $sanitized = [];

        foreach ($input as $link) {
            if (!is_array($link) || empty($link['title']) || empty($link['url'])) {
                continue;
            }

            $default_icon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>';

            $sanitized[] = [
                'icon' => wp_kses($link['icon'] ?? $default_icon, $allowed_svg),
                'title' => sanitize_text_field($link['title']),
                'url' => sanitize_text_field($link['url']),
            ];
        }

        return $sanitized;
    }

    /**
     * Handle logo file upload
     *
     * @param string $file_key
     * @return string|false URL of uploaded file or false on failure
     */
    private static function handle_logo_upload(string $file_key)
    {
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        // Create upload directory if it doesn't exist
        $upload_dir = WP_CONTENT_DIR . '/uploads/dashboard-logos';
        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
            // Add index.php for security
            file_put_contents($upload_dir . '/index.php', '<?php // Silence is golden. ?>');
        }

        $file = $_FILES[$file_key];
        
        // Validate file type
        $allowed_types = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];
        $file_type = $file['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            add_settings_error(
                self::$OPTION_NAME,
                'invalid_file_type',
                __('Invalid file type. Only PNG, JPG, SVG, and WebP are allowed.', 'sfxtheme')
            );
            return false;
        }

        // Validate file size (max 200KB)
        if ($file['size'] > 204800) {
            add_settings_error(
                self::$OPTION_NAME,
                'file_too_large',
                __('File too large. Maximum size is 200KB.', 'sfxtheme')
            );
            return false;
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'logo-' . time() . '-' . wp_generate_password(8, false) . '.' . $extension;
        $filepath = $upload_dir . '/' . $filename;

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Return URL
            return content_url('uploads/dashboard-logos/' . $filename);
        }

        add_settings_error(
            self::$OPTION_NAME,
            'upload_failed',
            __('Failed to upload logo. Please try again.', 'sfxtheme')
        );
        return false;
    }

    /**
     * Delete logo file
     *
     * @param string $logo_url
     * @return void
     */
    private static function delete_logo(string $logo_url): void
    {
        if (empty($logo_url)) {
            return;
        }

        // Extract filename from URL
        $filename = basename($logo_url);
        $filepath = WP_CONTENT_DIR . '/uploads/dashboard-logos/' . $filename;

        // Delete file if it exists
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }

    /**
     * Delete all options
     *
     * @return void
     */
    public static function delete_all_options(): void
    {
        delete_option(self::$OPTION_NAME);
    }
}

