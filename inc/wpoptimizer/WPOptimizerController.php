<?php

/**
 * 
 * @package SFX\WPOptimizer
 */

namespace SFX\WPOptimizer;

defined('ABSPATH') or die('Pet a cat!');

use SFX\WPOptimizer\ACF\WPOptimizerOptions;

use WP_Error;

/**
 * Beispielklasse, die alle Optimierungen zusammenführt und
 * über ein assoziatives Array steuern lässt.
 */
class WPOptimizerController
{
    /**
     * Welche Optimierungen aktiv/inaktiv sind.
     * Der Key ist zugleich der Name der entsprechenden Methode.
     *
     * @var array
     */
    private $optimizations = [];

    /**
     * Für deferCss() werden hier die gefundenen Styles gesammelt.
     *
     * @var array
     */
    private $styles = [];

    /**
     * Konstruktor: Definiert Defaults und parst übergebenes Array.
     */
    public function __construct(array $opts = [])
    {

        $defaults = [
            'remove_thumbnail_dimensions'   => true,
            'remove_nav_menu_container'     => true,
            'remove_caption_width'          => true,
            'handle_shortcode_formatting'   => true,
            'remove_archive_title_prefix'   => true,
            'add_slug_to_body_class'        => true,
            'block_external_http'           => false,
            'defer_css'                     => false,
            'defer_js'                      => false,
            'disable_comments'              => false,
            'disable_search'                => false,
            'add_json_mime_types'           => true,
            'add_font_mime_types'           => true,
            'remove_menus_appearance_patterns' => true,
            'remove_jquery_migrate'         => true,
            'disable_block_styling'         => false,
            'disable_embed'                 => false,
            'disable_emoji'                 => true,
            'disable_feeds'                 => false,
            'disable_heartbeat'             => false,
            'disable_jquery'                => true,
            'disable_jquery_migrate'        => true,
            'disable_rest_api'              => false,
            'disable_rsd'                   => true,
            'disable_shortlinks'            => true,
            'disable_theme_editor'          => true,
            'disable_version_numbers'       => true,
            'disable_wlw_manifest'          => true,
            'disable_wp_version'            => true,
            'disable_xmlrpc'                => true,
            'jquery_to_footer'              => true,
            'limit_revisions'               => true,
            'remove_comments_style'         => true,
            'slow_heartbeat'                => true,
            'remove_dns_prefetch'           => true,
            'disable_application_passwords' => true,
            'remove_wp_embed'               => true,
            'remove_global_styles_and_svg_filters' => true,
            'respect_acf_disable_optimizer' => true,

        ];

        $this->load_setting_pages();
        $this->optimizations = wp_parse_args($opts, $defaults);
        $this->init_from_acf();
    }



    private function load_setting_pages()
    {
        new \SFX\WPOptimizer\ACF\WPOptimizerOptions();
    }

    public function init_from_acf(): void
    {

        add_action('acf/init', function () {
            if (function_exists('get_field')) {
                $acfValues = get_field('wpoptimizer', 'option');
                // Prüfen, ob überhaupt Werte vorliegen
                if (!$acfValues || !is_array($acfValues)) {
                    $this->apply_optimizations();
                    return;
                }
                $this->optimizations = array_merge($this->optimizations, $acfValues);
                // error_log(print_r($this->optimizations, true));
                $this->apply_optimizations();
            }
        });

        add_action('acf/save_post', function ($post_id) {
            if ($post_id !== 'options') {
                return;
            }
            $acfValues = get_field('wpoptimizer', 'option');
            if (!$acfValues || !is_array($acfValues)) {
                return;
            }
            $this->optimizations = array_merge($this->optimizations, $acfValues);
            // error_log(print_r($this->optimizations, true));
            $this->apply_optimizations();
        });
    }

    private function is_optimizer_disabled(): bool
    {
        if (!$this->optimizations['respect_acf_disable_optimizer']) {
            return false;
        }

        return function_exists('get_field') && get_field('disable_wordpress_optimizer', 'option');
    }


    private function apply_optimizations(): void
    {
        if ($this->is_optimizer_disabled()) {
            return;
        }

        foreach ($this->optimizations as $methodName => $enabled) {
            if ($enabled && method_exists($this, $methodName)) {
                // error_log('Applying ' . $methodName);
                $this->$methodName();
            }
        }
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

    private function remove_jquery_migrate()
    {
        add_filter('wp_default_scripts', function ($scripts) {
            if (!empty($scripts->registered['jquery'])) {
                $scripts->registered['jquery']->deps = array_diff(
                    $scripts->registered['jquery']->deps,
                    ['jquery-migrate']
                );
            }
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
        add_filter('wp_default_scripts', function ($scripts) {
            if (!empty($scripts->registered['jquery'])) {
                $scripts->registered['jquery']->deps = array_diff(
                    $scripts->registered['jquery']->deps,
                    ['jquery-migrate']
                );
            }
        });
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
}
