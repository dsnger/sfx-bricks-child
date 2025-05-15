<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class Settings
{
    public static string $OPTION_GROUP;

    /**
     * Register all settings for WP Optimizer options.
     */
    public static function register(string $option_key): void
    {
        self::$OPTION_GROUP = $option_key . '_group';
        add_action('admin_init', [self::class, 'register_settings']);
    }

    /**
     * Returns all WP Optimizer fields as an array.
     */
    public static function get_fields(): array
    {
        return [
            // [
            //     'id'          => 'disable_wp_optimizer',
            //     'label'       => __('Disable Theme Wordpress Optimizer', 'sfxtheme'),
            //     'description' => __('Temporarily disables all theme optimization features. Use for troubleshooting if you suspect optimizer is causing issues. Recommended only for debugging.', 'sfxtheme'),
            //     'type'        => 'checkbox',
            //     'default'     => 0,
            // ],
            [
                'id'          => 'disable_search',
                'label'       => __('Disable Search', 'sfxtheme'),
                'description' => __('Disables the default WordPress search feature. Useful for brochure sites or when search is not needed. Not recommended for content-heavy or blog sites.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'add_json_mime_types',
                'label'       => __('Allow Lottie JSON Files', 'sfxtheme'),
                'description' => __('Allows uploading of JSON files (e.g., for Lottie animations) and plain text files. Enable if you need to upload these file types. Only recommended if you trust your site\'s users, as JSON can contain sensitive data.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'media',
            ],
            [
                'id'          => 'disable_jquery',
                'label'       => __('Disable jQuery', 'sfx'),
                'description' => __('Removes jQuery from the frontend to improve performance. Only enable if your theme and plugins do not require jQuery. Not recommended unless you are certain no scripts depend on jQuery.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'performance',
            ],
            [
                'id'          => 'jquery_to_footer',
                'label'       => __('Load jQuery in Footer', 'sfx'),
                'description' => __('Loads jQuery in the footer instead of the header, improving page load speed. Recommended if your scripts do not require jQuery to be loaded early.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'performance',
            ],
            [
                'id'          => 'disable_jquery_migrate',
                'label'       => __('Disable jQuery Migrate', 'sfx'),
                'description' => __('Removes jQuery Migrate, which provides backward compatibility for older scripts. Recommended unless you use plugins or themes that require legacy jQuery features.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'performance',
            ],
            [
                'id'          => 'remove_thumbnail_dimensions',
                'label'       => __('Remove Thumbnail Dimensions', 'sfx'),
                'description' => __('Removes width and height attributes from thumbnail images for better responsive design. Recommended for modern, responsive themes.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'remove_nav_menu_container',
                'label'       => __('Remove Nav Menu Container', 'sfx'),
                'description' => __('Removes the default <div> container from wp_nav_menu output. Useful for cleaner HTML and easier CSS styling.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'remove_caption_width',
                'label'       => __('Remove Caption Width', 'sfx'),
                'description' => __('Removes inline width styling from image captions, allowing for more flexible and responsive layouts.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'handle_shortcode_formatting',
                'label'       => __('Handle Shortcode Formatting', 'sfx'),
                'description' => __('Prevents WordPress from wrapping shortcodes in unwanted <p> or <br> tags, which can break layout. Recommended if you use shortcodes in content.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'remove_archive_title_prefix',
                'label'       => __('Remove Archive Title Prefix', 'sfx'),
                'description' => __('Removes prefixes like "Category:" or "Archive:" from archive titles for cleaner headings.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'add_slug_body_class',
                'label'       => __('Add Slug to Body Class', 'sfx'),
                'description' => __('Adds the post or page slug to the <body> class attribute. Useful for targeted CSS styling.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'block_external_http',
                'label'       => __('Block External HTTP', 'sfx'),
                'description' => __('Blocks all outgoing HTTP requests from the frontend for security or privacy. Only enable if you do not need to fetch external resources (e.g., APIs, fonts).', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'security',
            ],
            [
                'id'          => 'defer_css',
                'label'       => __('Defer CSS', 'sfx'),
                'description' => __('Loads CSS files asynchronously to improve perceived page load speed. May cause a flash of unstyled content (FOUC). Recommended for advanced users.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'performance',
            ],
            [
                'id'          => 'defer_js',
                'label'       => __('Defer JS', 'sfx'),
                'description' => __('Adds the defer attribute to all scripts, improving page load performance. Not recommended if you have scripts that must run before page render.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'performance',
            ],
            [
                'id'          => 'disable_comments',
                'label'       => __('Disable Comments', 'sfx'),
                'description' => __('Disables all comment features site-wide. Recommended for sites that do not use comments.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'comments',
            ],
            [
                'id'          => 'limit_comments_js',
                'label'       => __('Limit Comments JS', 'sfx'),
                'description' => __('Only loads the comment-reply.js script when threaded comments are enabled and needed. Recommended for performance.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'comments',
            ],
            [
                'id'          => 'remove_comments_style',
                'label'       => __('Remove Comments Style', 'sfx'),
                'description' => __('Removes inline CSS added by the Recent Comments widget for cleaner HTML.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'comments',
            ],
            [
                'id'          => 'disable_emoji',
                'label'       => __('Disable Emoji', 'sfx'),
                'description' => __('Removes WordPress emoji scripts and styles to reduce page size. Recommended unless you need emoji support.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_feeds',
                'label'       => __('Disable Feeds', 'sfx'),
                'description' => __('Disables all RSS and Atom feeds. Recommended for private or brochure sites. Not recommended for blogs or news sites.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_rest_api',
                'label'       => __('Disable REST API', 'sfx'),
                'description' => __('Disables the REST API. Not recommended unless you are certain no plugins or integrations require the REST API. May break some features.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_rsd',
                'label'       => __('Disable RSD Links', 'sfx'),
                'description' => __('Removes the Really Simple Discovery (RSD) link from the header. Recommended for security.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_shortlinks',
                'label'       => __('Disable Shortlinks', 'sfx'),
                'description' => __('Removes the shortlink meta tag from the header. Recommended for privacy or SEO reasons.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_theme_editor',
                'label'       => __('Disable Theme Editor', 'sfx'),
                'description' => __('Disables the built-in theme and plugin editor in the admin for security. Recommended for production sites.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_version_numbers',
                'label'       => __('Disable Version Query Args', 'sfx'),
                'description' => __('Removes version query strings from scripts and styles to improve caching. May cause issues with cache busting.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_wlw_manifest',
                'label'       => __('Disable WLW Manifest', 'sfx'),
                'description' => __('Removes the Windows Live Writer manifest link from the header. Recommended for security.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_wp_version',
                'label'       => __('Disable WP Version', 'sfx'),
                'description' => __('Removes the WordPress version meta tag for security.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_xmlrpc',
                'label'       => __('Disable XMLRPC', 'sfx'),
                'description' => __('Disables XML-RPC, which is often targeted by attackers. Recommended unless you use remote publishing or pingbacks.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_dns_prefetch',
                'label'       => __('Disable DNS Prefetch', 'sfx'),
                'description' => __('Removes DNS prefetch resource hints from the header. May slightly reduce DNS lookups, but can impact performance for some resources.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'limit_revisions',
                'label'       => __('Limit Revisions', 'sfx'),
                'description' => __('Limits the number of post revisions to reduce database size. Recommended for performance.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'performance',
            ],
            [
                'id'          => 'limit_revisions_number',
                'label'       => __('Revisions number', 'sfx'),
                'description' => __('Sets the maximum number of post revisions to keep. Set to 0 to disable revisions. Recommended for performance on large sites.', 'sfx'),
                'type'        => 'number',
                'default'     => 0,
                'min'         => 0,
                'max'         => 10,
                'group'       => 'performance',
            ],
            [
                'id'          => 'disable_heartbeat',
                'label'       => __('Disable Heartbeat', 'sfx'),
                'description' => __('Disables the WordPress Heartbeat API in the admin, reducing AJAX requests. May affect autosave and real-time features.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'performance',
            ],
            [
                'id'          => 'slow_heartbeat',
                'label'       => __('Slow Heartbeat', 'sfx'),
                'description' => __('Increases the interval of the Heartbeat API to reduce server load. Recommended for high-traffic sites.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'performance',
            ],
            [
                'id'          => 'remove_wp_embed',
                'label'       => __('Remove WP Embed', 'sfx'),
                'description' => __('Removes the WP Embed script, which enables embedding content from other sites. Recommended if you do not use embeds.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_xml_sitemaps',
                'label'       => __('Disable XML Sitemaps', 'sfx'),
                'description' => __('Disables the default WordPress XML sitemaps. Enable if you use a third-party SEO plugin or want to hide your sitemap for privacy.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_self_pingbacks',
                'label'       => __('Disable Self Pingbacks', 'sfx'),
                'description' => __('Prevents WordPress from sending pingbacks to your own site when linking to your own posts. Reduces unnecessary database entries and notifications.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_dashicons_frontend',
                'label'       => __('Disable Dashicons on Frontend', 'sfx'),
                'description' => __('Prevents loading of Dashicons (admin icon font) for non-logged-in users, reducing HTTP requests and improving frontend performance.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'limit_autosave_interval',
                'label'       => __('Limit Autosave Interval', 'sfx'),
                'description' => __('Increases the autosave interval to reduce server load. Recommended for busy sites. Default is 60 seconds; this sets it to 5 minutes.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'performance',
            ],
            [
                'id'          => 'disable_author_archives',
                'label'       => __('Disable Author Archives', 'sfx'),
                'description' => __('Disables author archive pages to prevent user enumeration and improve SEO. Redirects author pages to the homepage.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_attachment_pages',
                'label'       => __('Disable Attachment Pages', 'sfx'),
                'description' => __('Redirects attachment pages to the parent post or homepage to prevent thin content and improve SEO.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'frontend',
            ],
            [
                'id'          => 'disable_comment_rss_feeds',
                'label'       => __('Disable Comment RSS Feeds', 'sfx'),
                'description' => __('Disables comment feeds, which are rarely used and can be a spam vector. Reduces unnecessary endpoints.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'comments',
            ],
            [
                'id'          => 'disable_rest_api_non_authenticated',
                'label'       => __('Disable REST API for Non-Authenticated Users', 'sfx'),
                'description' => __('Restricts REST API access to logged-in users only, improving privacy and security by hiding REST endpoints from anonymous users.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'security',
            ],
            [
                'id'          => 'disable_comments_on_attachments',
                'label'       => __('Disable Comments on Media Attachments', 'sfx'),
                'description' => __('Prevents comments on media attachment pages to reduce spam and unnecessary comment forms.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'comments',
            ],
        ];
    }

    public static function register_settings(): void
    {
        register_setting(self::$OPTION_GROUP, 'sfx_wpoptimizer_options', [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        add_settings_section(
            'sfx_wpoptimizer_options_section',
            __('WP Optimizer Options', 'sfxtheme'),
            [self::class, 'render_section'],
            self::$OPTION_GROUP
        );

        foreach (self::get_fields() as $field) {
            add_settings_field(
                $field['id'],
                $field['label'],
                [self::class, 'render_field'],
                self::$OPTION_GROUP,
                'sfx_wpoptimizer_options_section',
                $field
            );
        }
    }

    public static function render_section(): void
    {
        echo '<p>' . esc_html__('WP Optimizer Options', 'sfxtheme') . '</p>';
    }

    public static function render_field(array $args): void
    {
        $options = get_option('sfx_wpoptimizer_options', []);
        $id = esc_attr($args['id']);
        $type = $args['type'] ?? 'checkbox';
        $value = $options[$id] ?? $args['default'];
        if ($type === 'checkbox') {
            ?>
            <input type="checkbox" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="1" <?php checked((int)$value, 1); ?> />
            <label for="<?php echo $id; ?>"><?php echo esc_html($args['description']); ?></label>
            <?php
        } elseif ($type === 'number') {
            $min = isset($args['min']) ? (int)$args['min'] : 0;
            $max = isset($args['max']) ? (int)$args['max'] : 10;
            ?>
            <input type="number" id="<?php echo $id; ?>" name="sfx_wpoptimizer_options[<?php echo $id; ?>]" value="<?php echo esc_attr($value); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" />
            <label for="<?php echo $id; ?>"><?php echo esc_html($args['description']); ?></label>
            <?php
        }
    }

    public static function sanitize_options($input): array
    {
        $output = [];
        foreach (self::get_fields() as $field) {
            $id = $field['id'];
            if ($field['type'] === 'number') {
                $output[$id] = isset($input[$id]) ? (int)$input[$id] : (int)$field['default'];
            } else {
                $output[$id] = isset($input[$id]) && $input[$id] ? 1 : 0;
            }
        }
        return $output;
    }

    public static function delete(): void
    {
        delete_option('sfx_wpoptimizer_options');
    }
} 