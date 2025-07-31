<?php

/**
 * 
 * @package SFX\WPOptimizer
 */

namespace SFX\WPOptimizer;

defined('ABSPATH') or die('Pet a cat!');


use WP_Error;

/**
 * Beispielklasse, that combines all optimizations and
 * controls them via an associative array.
 */
class Controller
{

    private $fields = [];
    public const OPTION_NAME = 'sfx_wpoptimizer_options';

    /**
     * Initialize the controller
     */
    public function __construct()
    {
        // Register components
        AdminPage::register();
        AssetManager::register();
        Settings::register();

        // Register hooks through consolidated system
        add_action('sfx_init_settings', [$this, 'handle_options']);

        // Clear caches when settings are updated
        add_action('update_option_sfx_wpoptimizer_options', [$this, 'clear_optimizer_caches']);
        add_action('delete_option_sfx_wpoptimizer_options', [$this, 'clear_optimizer_caches']);
    }

    /**
     * Clear optimizer caches when settings are updated
     */
    public function clear_optimizer_caches(): void
    {
        delete_transient('sfx_wp_optimizer_enabled');
        delete_transient('sfx_wp_optimizer_settings');
    }

    public function init_fields(): void
    {
        $this->fields = Settings::get_fields();
    }

    public function handle_options(): void
    {
        if (empty($this->fields) || !is_array($this->fields) || $this->is_option_enabled('disable_wp_optimizer')) {
            return;
        }
        foreach ($this->fields as $field) {
            if ($this->is_option_enabled($field['id']) && method_exists($this, $field['id'])) {
                $this->{$field['id']}();
            }
        }
    }

    private function is_option_enabled(string $option_key): bool
    {
        $options = get_option(self::OPTION_NAME, []);
        return !empty($options[$option_key]);
    }


    private function remove_thumbnail_dimensions()
    {
        add_filter('post_thumbnail_html', [$this, 'filter_remove_thumbnail_dimensions'], 10);
        add_filter('image_send_to_editor', [$this, 'filter_remove_thumbnail_dimensions'], 10);
    }

    /**
     * Hier die Callback-Funktion dazu, damit wir Hooks sauber trennen können.
     */
    public function filter_remove_thumbnail_dimensions($html)
    {
        return preg_replace('/(width|height)="\d*"\s/', '', $html);
    }

    private function remove_nav_menu_container()
    {
        add_filter('wp_nav_menu_args', function ($args) {
            $args['container'] = false;
            return $args;
        });
    }

    private function remove_caption_width()
    {
        add_filter('img_caption_shortcode_width', function () {
            return 0;
        }, 10, 3);
    }

    private function handle_shortcode_formatting()
    {
        // Hier mit Priorität 11
        add_filter('the_content', [$this, 'filter_shortcode_formatting'], 11);
        add_filter('acf_the_content', [$this, 'filter_shortcode_formatting'], 11);
    }

    public function filter_shortcode_formatting($content)
    {
        // Aus dem Original übernommen
        $array = [
            '<p>['    => '[',
            ']</p>'   => ']',
            ']<br />' => ']',
            ']<br>'   => ']'
        ];
        $content = strtr($content, $array);

        // P-Tags um Blockelemente entfernen
        $block   = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|select|option|form|map|area|blockquote|address|math|style|input|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';
        $content = preg_replace('/<p>\s*(<' . $block . '[^>]*>)/', '$1', $content);
        $content = preg_replace('!(</' . $block . '>)\s*</p>!', '$1', $content);

        return $content;
    }

    private function remove_archive_title_prefix()
    {
        add_filter('get_the_archive_title_prefix', function () {
            return '';
        });
    }

    private function add_slug_to_body_class()
    {
        add_filter('body_class', function ($classes) {
            global $post;
            if (is_home()) {
                return $classes;
            } elseif (is_page()) {
                $parent      = $post->post_parent ? end(get_post_ancestors($post)) : $post->ID;
                $parent_post = get_post($parent);
                $classes[]   = $parent_post->post_name;
                $classes[]   = sanitize_html_class($post->post_name);
            } elseif (is_singular()) {
                $classes[] = sanitize_html_class($post->post_name);
            }
            return $classes;
        });
    }

    private function add_font_mime_types()
    {
        add_filter('upload_mimes', function ($mimes) {
            $mimes['woff']  = 'application/font-woff';
            $mimes['woff2'] = 'application/font-woff2';
            $mimes['ttf']   = 'application/x-font-ttf';
            return $mimes;
        });

        $this->check_font_mime_types();
    }

    private function check_font_mime_types()
    {
        add_filter('wp_additional_filetype_and_ext', function ($data, $file, $filename, $mimes) {
            if (!current_user_can('administrator') || !current_user_can('upload_files')) {
                return $data;
            }

            $filetype = wp_check_filetype($filename, $mimes);
            if ('ttf' === $filetype['ext']) {
                $data['ext'] = 'ttf';
                $data['type'] = 'application/x-font-ttf';
            }
            if ('woff' === $filetype['ext']) {
                $data['ext'] = 'woff';
                $data['type'] = 'application/font-woff';
            }
            if ('woff2' === $filetype['ext']) {
                $data['ext'] = 'woff2';
                $data['type'] = 'application/font-woff2';
            }
            return $data;
        }, 10, 4);
    }


    private function add_json_mime_types()
    {
        add_filter('upload_mimes', function ($mimes) {
            $mimes['json']  = 'application/json';
            $mimes['txt']  = 'text/plain';
            return $mimes;
        });

        $this->check_json_mime_types();
    }

    function check_json_mime_types()
    {

        add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
            if (!current_user_can('administrator') || !current_user_can('upload_files')) {
                return $data;
            }
            $wp_file_type = wp_check_filetype($filename, $mimes);
            if ('json' === $wp_file_type['ext']) {
                $data['ext']  = 'json';
                $data['type'] = 'text/plain';
            } elseif ('txt' === $wp_file_type['ext']) {
                $data['ext']  = 'txt';
                $data['type'] = 'text/plain';
            }
            return $data;
        }, 10, 4);
    }


    private function remove_menus_appearance_patterns()
    {
        add_action('admin_menu', function () {
            remove_submenu_page('themes.php', 'site-editor.php?path=/patterns');
        });
    }

    // --- Beispiele aus WPOptimizer (2. Datei, ebenfalls umbenannt) ---

    private function block_external_http()
    {
        if (!is_admin()) {
            add_filter('pre_http_request', function () {
                return new WP_Error('http_request_failed', __('Request blocked by WP Optimize.'));
            }, 100);
        }
    }

    private function defer_css()
    {
        // Sammle Styles
        add_action('wp_enqueue_scripts', function () {
            if (is_customize_preview()) {
                return;
            }
            global $wp_styles;
            foreach ($wp_styles->queue as $handle) {
                $this->styles[] = $wp_styles->registered[$handle];
                // Dependencies auch gleich merken
                $deps = $wp_styles->registered[$handle]->deps ?? [];
                foreach ($deps as $dep) {
                    $this->styles[] = $wp_styles->registered[$dep] ?? null;
                }
            }
            // Duplikate entfernen
            $this->styles = array_unique(array_filter($this->styles), SORT_REGULAR);

            // Alle aus der Queue werfen
            foreach ($this->styles as $style) {
                wp_dequeue_style($style->handle);
            }
        }, 9999);

        // Per loadCSS einbinden
        add_action('wp_head', function () {
            if (is_customize_preview()) {
                return;
            }
            $out = '<script>function loadCSS(href,before,media,callback){"use strict";var ss=window.document.createElement("link");var ref=before||window.document.getElementsByTagName("script")[0];var sheets=window.document.styleSheets;ss.rel="stylesheet";ss.href=href;ss.media="only x";if(callback){ss.onload=callback;}ref.parentNode.insertBefore(ss,ref);ss.onloadcssdefined=function(cb){var defined;for(var i=0;i<sheets.length;i++){if(sheets[i].href&&sheets[i].href.indexOf(href)>-1){defined=true;}}defined?cb():setTimeout(function(){ss.onloadcssdefined(cb);});};ss.onloadcssdefined(function(){ss.media=media||"all";});return ss;}</script>';
            foreach ($this->styles as $style) {
                if (!isset($style->extra['conditional'])) {
                    $src = $style->src;
                    if (strpos($src, 'http') === false) {
                        $src = site_url() . $src;
                    }
                    $media = $style->args ?? 'all';
                    $out .= 'loadCSS("' . $src . '","","' . $media . '");';
                }
            }
            $out .= '</script>';
            echo $out;
        }, 9999);
    }

    private function defer_js()
    {
        if (is_customize_preview() || is_admin()) {
            return;
        }
        add_filter('script_loader_tag', function ($tag) {
            return str_replace(' src', ' defer="defer" src', $tag);
        }, 10, 1);
    }

    private function disable_block_styling()
    {
        add_action('wp_enqueue_scripts', function () {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-block-style');
        }, 100);
    }

    private function disable_comments()
    {
        if (is_admin()) {
            update_option('default_comment_status', 'closed');
        }
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

        add_action('admin_init', function () {
            $postTypes = get_post_types();
            foreach ($postTypes as $pt) {
                if (post_type_supports($pt, 'comments')) {
                    remove_post_type_support($pt, 'comments');
                    remove_post_type_support($pt, 'trackbacks');
                }
            }
        });

        add_action('admin_menu', function () {
            remove_menu_page('edit-comments.php');
        });

        add_action('wp_before_admin_bar_render', function () {
            global $wp_admin_bar;
            $wp_admin_bar->remove_menu('comments');
        });
    }

    private function disable_embed()
    {
        add_action('wp_enqueue_scripts', function () {
            wp_deregister_script('wp-embed');
        }, 100);

        add_action('init', function () {
            remove_action('wp_head', 'wp_oembed_add_host_js');
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('rest_api_init', 'wp_oembed_register_route');
            remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
            add_filter('embed_oembed_discover', '__return_false');
        });
    }

    private function disable_emoji()
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        add_filter('tiny_mce_plugins', function ($plugins) {
            if (!is_array($plugins)) {
                return [];
            }
            return array_diff($plugins, ['wpemoji']);
        }, 10, 1);
    }

    private function disable_feeds()
    {
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'feed_links', 2);

        add_action('do_feed', [$this, 'disable_feeds_hook'], 1);
        add_action('do_feed_rdf', [$this, 'disable_feeds_hook'], 1);
        add_action('do_feed_rss', [$this, 'disable_feeds_hook'], 1);
        add_action('do_feed_rss2', [$this, 'disable_feeds_hook'], 1);
        add_action('do_feed_atom', [$this, 'disable_feeds_hook'], 1);
    }

    public function disable_feeds_hook()
    {
        wp_die('<p>' . __('Feed disabled by WP Optimize.') . '</p>');
    }

    private function disable_heartbeat()
    {
        add_action('admin_enqueue_scripts', function () {
            wp_deregister_script('heartbeat');
        });
    }

    private function disable_jquery()
    {
        if (!is_admin() && !bricks_is_builder()) {
            add_action('wp_enqueue_scripts', function () {
                wp_deregister_script('jquery');
                wp_deregister_script('jquery-core');
            }, 100);
        }
    }

        private function disable_jquery_migrate()
    {
        // Remove jQuery Migrate from frontend only to avoid breaking admin
        add_action('wp_enqueue_scripts', function () {
            // Remove jquery-migrate from jquery dependencies on frontend
            global $wp_scripts;
            if (isset($wp_scripts->registered['jquery'])) {
                $wp_scripts->registered['jquery']->deps = array_diff(
                    $wp_scripts->registered['jquery']->deps,
                    ['jquery-migrate']
                );
            }
            // Deregister the script entirely
            wp_deregister_script('jquery-migrate');
        }, 1); // Early priority to catch before other scripts
    }

    private function disable_rest_api()
    {
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
        remove_action('rest_api_init', 'wp_oembed_register_route');
        add_filter('embed_oembed_discover', '__return_false');
        remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        remove_action('template_redirect', 'rest_output_link_header', 11, 0);

        add_filter('json_enabled', '__return_false');
        add_filter('json_jsonp_enabled', '__return_false');
        add_filter('rest_enabled', '__return_false');
        add_filter('rest_jsonp_enabled', '__return_false');
    }

    private function disable_rsd()
    {
        remove_action('wp_head', 'rsd_link');
    }

    private function disable_shortlinks()
    {
        remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0);
    }

    private function disable_theme_editor()
    {
        if (!defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }
    }

    private function disable_version_numbers()
    {
        add_filter('style_loader_src', [$this, 'disable_version_numbers_hook'], 9999);
        add_filter('script_loader_src', [$this, 'disable_version_numbers_hook'], 9999);
    }

    public function disable_version_numbers_hook(string $url = ''): string
    {
        if (strpos($url, 'ver=')) {
            $url = remove_query_arg('ver', $url);
        }
        return $url;
    }

    private function disable_wlw_manifest()
    {
        remove_action('wp_head', 'wlwmanifest_link');
    }

    private function disable_wp_version()
    {
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_null');
    }

    private function disable_xmlrpc()
    {
        if (is_admin()) {
            update_option('default_ping_status', 'closed');
        }
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('pre_update_option_enable_xmlrpc', '__return_false');
        add_filter('pre_option_enable_xmlrpc', '__return_zero');

        add_filter('wp_headers', function ($headers) {
            if (isset($headers['X-Pingback'])) {
                unset($headers['X-Pingback']);
            }
            return $headers;
        }, 10, 1);

        add_filter('xmlrpc_methods', function ($methods) {
            unset($methods['pingback.ping']);
            unset($methods['pingback.extensions.getPingbacks']);
            return $methods;
        }, 10, 1);
    }

    private function jquery_to_footer()
    {
        add_action('wp_enqueue_scripts', function () {
            wp_deregister_script('jquery');
            wp_register_script('jquery', includes_url('/js/jquery/jquery.js'), false, null, true);
            wp_enqueue_script('jquery');
        });
    }

    private function limit_comments_js()
    {
        add_action('wp_print_scripts', function () {
            if (is_singular() && (get_option('thread_comments') == 1) && comments_open() && get_comments_number()) {
                wp_enqueue_script('comment-reply');
            } else {
                wp_dequeue_script('comment-reply');
            }
        }, 100);
    }

    private function limit_revisions()
    {
        if (defined('WP_POST_REVISIONS') && WP_POST_REVISIONS !== false) {
            add_filter('wp_revisions_to_keep', function ($num) {
                return 0; // z.B. max 5 Revisionen
            }, 10, 2);
        }
    }

    private function remove_comments_style()
    {
        add_action('widgets_init', function () {
            global $wp_widget_factory;
            if (isset($wp_widget_factory->widgets['WP_Widget_Recent_Comments'])) {
                remove_action('wp_head', [
                    $wp_widget_factory->widgets['WP_Widget_Recent_Comments'],
                    'recent_comments_style'
                ]);
            }
        });
    }

    private function slow_heartbeat()
    {
        add_filter('heartbeat_settings', function ($settings) {
            $settings['interval'] = 60; // 1 Minute
            return $settings;
        });
    }


    private function remove_dns_prefetch()
    {
        add_action('init', function () {
            remove_action('wp_head', 'wp_resource_hints', 2, 99);
        });
    }


    private function remove_wp_embed()
    {
        add_action('init', function () {
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
            remove_action('wp_head', 'wp_oembed_add_query_string_parameters');
        });
    }


    private function disable_application_passwords()
    {
        /**
         * Eine neue Funktion, die mit WordPress 5.6 eingeführt wurde
         * Ermöglicht es dir, externen Systemen über generierte Passwörter Zugang zur Steuerung
         * deiner Website zu geben.Es sind keine Sicherheitslücken bekannt. Wenn du diese Funktion
         * nicht benötigst, brauchst du sie nicht zu aktivieren.Du kannst diese Funktion
         * deaktivieren, indem du diese einzige Codezeile in deine functions.php einfügst:
         */

        add_filter('wp_is_application_passwords_available', '__return_false');
    }

    /**
     * This snippet removes the Global Styles and SVG Filters that are mostly if not only used in Full Site Editing in WordPress 5.9.1+
     * Detailed discussion at: https://github.com/WordPress/gutenberg/issues/36834
     *
     * @return void
     */
    private function remove_global_styles_and_svg_filters()
    {
        add_action('init', function () {
            remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
            remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
        });
    }

    private function disable_xml_sitemaps()
    {
        add_filter('wp_sitemaps_enabled', '__return_false');
    }

    private function disable_self_pingbacks()
    {
        add_action('pre_ping', function (&$links) {
            $home = get_option('home');
            foreach ($links as $l => $link) {
                if (0 === strpos($link, $home)) {
                    unset($links[$l]);
                }
            }
        });
    }

    private function disable_dashicons_frontend()
    {
        add_action('wp_enqueue_scripts', function () {
            if (!is_user_logged_in()) {
                wp_deregister_style('dashicons');
            }
        });
    }

    private function limit_autosave_interval()
    {
        add_filter('autosave_interval', function () {
            return 300; // 5 minutes
        });
    }

    private function disable_author_archives()
    {
        add_action('template_redirect', function () {
            if (is_author()) {
                wp_redirect(home_url(), 301);
                exit;
            }
        });
    }

    private function disable_attachment_pages()
    {
        add_action('template_redirect', function () {
            if (is_attachment()) {
                global $post;
                if ($post && $post->post_parent) {
                    wp_redirect(get_permalink($post->post_parent), 301);
                    exit;
                } else {
                    wp_redirect(home_url(), 301);
                    exit;
                }
            }
        });
    }

    private function disable_comment_rss_feeds()
    {
        add_filter('feed_links_show_comments_feed', '__return_false');
    }

    private function disable_rest_api_non_authenticated()
    {
        add_filter('rest_authentication_errors', function ($result) {
            if (!is_user_logged_in()) {
                return new WP_Error('rest_cannot_access', __('REST API restricted to authenticated users.'), array('status' => 401));
            }
            return $result;
        });
    }

    private function disable_comments_on_attachments()
    {
        add_filter('comments_open', function ($open, $post_id) {
            $post = get_post($post_id);
            if ('attachment' === $post->post_type) {
                return false;
            }
            return $open;
        }, 10, 2);
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_wp_optimizer',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing WPOptimizerController class in theme',
        ];
    }

    public static function maybe_set_default_options(): void {
        if (false === get_option(self::OPTION_NAME, false)) {
            $defaults = [];
            foreach (Settings::get_fields() as $field) {
                $defaults[$field['id']] = $field['default'];
            }
            add_option(self::OPTION_NAME, $defaults);
        }
      }

    /**
     * Check if optimization is enabled with caching
     * 
     * @return bool
     */
    public static function is_optimization_enabled(): bool
    {
        $cache_key = 'sfx_wp_optimizer_enabled';
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            return (bool) $cached_result;
        }
        
        $enabled = Settings::get('enable_wp_optimizer', 1);
        
        // Cache for 30 minutes
        set_transient($cache_key, $enabled, 30 * MINUTE_IN_SECONDS);
        
        return (bool) $enabled;
    }

    /**
     * Get optimization settings with caching
     * 
     * @return array
     */
    public static function get_cached_settings(): array
    {
        $cache_key = 'sfx_wp_optimizer_settings';
        $cached_settings = get_transient($cache_key);
        
        if ($cached_settings !== false) {
            return $cached_settings;
        }
        
        $settings = Settings::get_all();
        
        // Cache for 1 hour
        set_transient($cache_key, $settings, HOUR_IN_SECONDS);
        
        return $settings;
    }
}
