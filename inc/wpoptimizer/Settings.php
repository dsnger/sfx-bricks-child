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
            [
                'id'          => 'disable_wp_optimizer',
                'label'       => __('Disable Theme Wordpress Optimizer', 'sfxtheme'),
                'description' => __('If there are any issues with WordPress, you can disable the theme-included optimize functions to check if the issue is caused by the optimizer.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'disable_search',
                'label'       => __('Disable Search', 'sfxtheme'),
                'description' => __('Disable WordPress search functionality.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'add_json_mime_types',
                'label'       => __('Allow Lottie JSON Files', 'sfxtheme'),
                'description' => __('Allow the use of JSON files for Lottie animations.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'disable_jquery',
                'label'       => __('Disable jQuery', 'sfx'),
                'description' => __('Deregisters core jQuery in front-end.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'jquery_to_footer',
                'label'       => __('Load jQuery in Footer', 'sfx'),
                'description' => __('Moves the core jQuery script to the footer.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'disable_jquery_migrate',
                'label'       => __('Disable jQuery Migrate', 'sfx'),
                'description' => __('Recommended: Removes jQuery Migrate as a dependency for jQuery.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'remove_thumbnail_dimensions',
                'label'       => __('Remove Thumbnail Dimensions', 'sfx'),
                'description' => __('Removes width/height attributes from thumbnails.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'remove_nav_menu_container',
                'label'       => __('Remove Nav Menu Container', 'sfx'),
                'description' => __('Removes the default container around wp_nav_menu.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'remove_caption_width',
                'label'       => __('Remove Caption Width', 'sfx'),
                'description' => __('Sets caption width to 0, removing inline width styling.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'handle_shortcode_formatting',
                'label'       => __('Handle Shortcode Formatting', 'sfx'),
                'description' => __('Removes unwanted p or br tags around shortcodes.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'remove_archive_title_prefix',
                'label'       => __('Remove Archive Title Prefix', 'sfx'),
                'description' => __('Removes "Archive:", "Category:" etc. prefix in archive titles.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'add_slug_body_class',
                'label'       => __('Add Slug to Body Class', 'sfx'),
                'description' => __('Adds page/post slug as extra class in body.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'block_external_http',
                'label'       => __('Block External HTTP', 'sfx'),
                'description' => __('Blocks external HTTP requests if not in admin area.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'defer_css',
                'label'       => __('Defer CSS', 'sfx'),
                'description' => __('Loads CSS asynchronously using loadCSS.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'defer_js',
                'label'       => __('Defer JS', 'sfx'),
                'description' => __('Adds `defer` attribute to scripts (except in admin/customizer).', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_comments',
                'label'       => __('Disable Comments', 'sfx'),
                'description' => __('Completely disables comment functionality.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'limit_comments_js',
                'label'       => __('Limit Comments JS', 'sfx'),
                'description' => __('Only load comment-reply.js if comments are open and needed.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'remove_comments_style',
                'label'       => __('Remove Comments Style', 'sfx'),
                'description' => __('Removes inline CSS for recent comments widget.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_emoji',
                'label'       => __('Disable Emoji', 'sfx'),
                'description' => __('Removes WP emoji scripts and styles.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_feeds',
                'label'       => __('Disable Feeds', 'sfx'),
                'description' => __('Disables WordPress RSS/Atom feeds.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_rest_api',
                'label'       => __('Disable REST API', 'sfx'),
                'description' => __('Not recommended: Disables the WordPress REST API endpoints.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'disable_rsd',
                'label'       => __('Disable RSD Links', 'sfx'),
                'description' => __('Removes the RSD link in head (pingback).', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_shortlinks',
                'label'       => __('Disable Shortlinks', 'sfx'),
                'description' => __('Removes shortlink in the header.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'disable_theme_editor',
                'label'       => __('Disable Theme Editor', 'sfx'),
                'description' => __('Prevents editing theme and plugins via WP Admin.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_version_numbers',
                'label'       => __('Disable Version Query Args', 'sfx'),
                'description' => __('Removes ?ver= from script and style URLs.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_wlw_manifest',
                'label'       => __('Disable WLW Manifest', 'sfx'),
                'description' => __('Removes WLW Manifest link (for Windows Live Writer).', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_wp_version',
                'label'       => __('Disable WP Version', 'sfx'),
                'description' => __('Removes WordPress version meta tag.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_xmlrpc',
                'label'       => __('Disable XMLRPC', 'sfx'),
                'description' => __('Disables XMLRPC functionality (pingback, remote posting, etc.).', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_dns_prefetch',
                'label'       => __('Disable DNS Prefetch', 'sfx'),
                'description' => __('Removes DNS prefetching from the header.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'limit_revisions',
                'label'       => __('Limit Revisions', 'sfx'),
                'description' => __('Limits the number of post revisions stored.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'limit_revisions_number',
                'label'       => __('Revisions number', 'sfx'),
                'description' => __('Limits the number of post revisions stored.', 'sfx'),
                'type'        => 'number',
                'default'     => 0,
                'min'         => 0,
                'max'         => 10,
            ],
            [
                'id'          => 'disable_heartbeat',
                'label'       => __('Disable Heartbeat', 'sfx'),
                'description' => __('Deregisters heartbeat script in the admin.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'slow_heartbeat',
                'label'       => __('Slow Heartbeat', 'sfx'),
                'description' => __('Increases Heartbeat API interval to 60 seconds.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'remove_wp_embed',
                'label'       => __('Remove WP Embed', 'sfx'),
                'description' => __('Removes WP Embed.', 'sfx'),
                'type'        => 'checkbox',
                'default'     => 1,
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