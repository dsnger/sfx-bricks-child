<?php

namespace SFX\WPOptimizer\ACF;

class WPOptimizerOptions
{

  public function __construct()
  {
    add_action('acf/init', [$this, 'add_acf_options_pages']);
    add_action('acf/init', [$this, 'register_fields']);
  }


  public function add_acf_options_pages()
  {

    // Make sure ACF is active
    if (function_exists('acf_add_options_page')) {

      acf_add_options_sub_page(array(
        'page_title'    => __('WP Enhancements', 'sfxtheme'),
        'menu_title'    => __('WP Enhancements', 'sfxtheme'),
        'parent_slug'   => \SFX\Options\AdminOptionPages::$menu_slug,
      ));
    }
  }


  public function register_fields()
  {
    // Your ACF field registration code here
    if (function_exists('acf_add_local_field_group')) {
      
      acf_add_local_field_group(
        array(
          'key' => 'group_wpoptimizer',
          'title' => __('WP Optimizer Settings', 'sfxtheme'),
          'fields' => array(
            array(
              'key' => 'field_6777fe151b71b',
              'label' => '',
              'name' => 'wpoptimizer',
              'type' => 'group',
              'instructions' => '',
              'layout' => 'block',
              'sub_fields' => array(
                array(
                  'key' => 'field_66dc98d3df425',
                  'label' => __('Disable Theme Wordpress Optimizer', 'sfxtheme'),
                  'name' => 'disable_wordpress_optimizer',
                  'type' => 'true_false',
                  'instructions' => __('If there are any issues with WordPress, you can disable the theme-included optimize functions to check if the issue is caused by the optimizer.', 'sfxtheme'),
                  'default_value' => 0,
                  'ui' => 1,
                  'wrapper' => array(
                    'width' => '25',
                  ),
                ),
                array(
                  'key' => 'field_66dc94e2617a2',
                  'label' => __('Disable Search', 'sfxtheme'),
                  'name' => 'disable_search',
                  'type' => 'true_false',
                  'instructions' => __('Disable WordPress search functionality.', 'sfxtheme'),
                  'default_value' => 0,
                  'ui' => 1,
                  'wrapper' => array(
                    'width' => '25',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_66cdd1b58b699',
                  'label' => __('Allow Lottie JSON Files', 'sfxtheme'),
                  'name' => 'add_json_mime_types',
                  'type' => 'true_false',
                  'instructions' => __('Allow the use of JSON files for Lottie animations.', 'sfxtheme'),
                  'default_value' => 0,
                  'ui' => 1,
                  'wrapper' => array(
                    'width' => '25',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_disable_jquery',
                  'label' => __('Disable jQuery', 'sfx'),
                  'name' => 'disable_jquery',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Deregisters core jQuery in front-end.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_jquery_to_footer',
                  'label' => __('Load jQuery in Footer', 'sfx'),
                  'name' => 'jquery_to_footer',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Moves the core jQuery script to the footer.', 'sfx'),
                  'default_value' => 0,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_disable_jquery_migrate',
                  'label' => __('Disable jQuery Migrate', 'sfx'),
                  'name' => 'disable_jquery_migrate',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Recommended: Removes jQuery Migrate as a dependency for jQuery.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_67781d1e5a661',
                  'label' => __('Remove Thumbnail Dimensions', 'sfx'),
                  'name' => 'remove_thumbnail_dimensions',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes width/height attributes from thumbnails.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_67781d3467a47',
                  'label' => __('Remove Nav Menu Container', 'sfx'),
                  'name' => 'remove_nav_menu_container',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes the default container around wp_nav_menu.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781d678cbf7',
                  'label' => __('Remove Caption Width', 'sfx'),
                  'name' => 'remove_caption_width',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Sets caption width to 0, removing inline width styling.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_67781d786d7f0',
                  'label' => __('Handle Shortcode Formatting', 'sfx'),
                  'name' => 'handle_shortcode_formatting',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes unwanted p or br tags around shortcodes.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_67781d9348b7a',
                  'label' => __('Remove Archive Title Prefix', 'sfx'),
                  'name' => 'remove_archive_title_prefix',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes "Archive:", "Category:" etc. prefix in archive titles.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),
                array(
                  'key' => 'field_67781dad28a05',
                  'label' => __('Add Slug to Body Class', 'sfx'),
                  'name' => 'add_slug_body_class',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Adds page/post slug as extra class in body.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781deb2005b',
                  'label' => __('Block External HTTP', 'sfx'),
                  'name' => 'block_external_http',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Blocks external HTTP requests if not in admin area.', 'sfx'),
                  'default_value' => 0,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781e05dd6c8',
                  'label' => __('Defer CSS', 'sfx'),
                  'name' => 'defer_css',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Loads CSS asynchronously using loadCSS.', 'sfx'),
                  'default_value' => 0,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781e42d893e',
                  'label' => __('Defer JS', 'sfx'),
                  'name' => 'defer_js',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Adds `defer` attribute to scripts (except in admin/customizer).', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781e4edda08',
                  'label' => __('Disable Comments', 'sfx'),
                  'name' => 'disable_comments',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Completely disables comment functionality.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),


                array(
                  'key' => 'field_67781e599fe35',
                  'label' => __('Limit Comments JS', 'sfx'),
                  'name' => 'limit_comments_js',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Only load comment-reply.js if comments are open and needed.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781e6799a01',
                  'label' => __('Remove Comments Style', 'sfx'),
                  'name' => 'remove_comments_style',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes inline CSS for recent comments widget.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),


                array(
                  'key' => 'field_67781e70e166f',
                  'label' => __('Disable Emoji', 'sfx'),
                  'name' => 'disable_emoji',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes WP emoji scripts and styles.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67781e7b8dd50',
                  'label' => __('Disable Feeds', 'sfx'),
                  'name' => 'disable_feeds',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Disables WordPress RSS/Atom feeds.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780f3106a25',
                  'label' => __('Disable REST API', 'sfx'),
                  'name' => 'disable_rest_api',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Not recommended: Disables the WordPress REST API endpoints.', 'sfx'),
                  'default_value' => 0,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780f2784aeb',
                  'label' => __('Disable RSD Links', 'sfx'),
                  'name' => 'disable_rsd',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes the RSD link in head (pingback).', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780f1e8f082',
                  'label' => __('Disable Shortlinks', 'sfx'),
                  'name' => 'disable_shortlinks',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes shortlink in the header.', 'sfx'),
                  'default_value' => 0,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780f1524664',
                  'label' => __('Disable Theme Editor', 'sfx'),
                  'name' => 'disable_theme_editor',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Prevents editing theme and plugins via WP Admin.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),


                array(
                  'key' => 'field_67780f0b115ee',
                  'label' => __('Disable Version Query Args', 'sfx'),
                  'name' => 'disable_version_numbers',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes ?ver= from script and style URLs.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780efcbf721',
                  'label' => __('Disable WLW Manifest', 'sfx'),
                  'name' => 'disable_wlw_manifest',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes WLW Manifest link (for Windows Live Writer).', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780ef2263cd',
                  'label' => __('Disable WP Version', 'sfx'),
                  'name' => 'disable_wp_version',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes WordPress version meta tag.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780ee864679',
                  'label' => __('Disable XMLRPC', 'sfx'),
                  'name' => 'disable_xmlrpc',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Disables XMLRPC functionality (pingback, remote posting, etc.).', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780ecedf73c',
                  'label' => __('Disable DNS Prefetch', 'sfx'),
                  'name' => 'disable_dns_prefetch',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes DNS prefetching from the header.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),


                array(
                  'key' => 'field_67780ec44d5f4',
                  'label' => __('Limit Revisions', 'sfx'),
                  'name' => 'limit_revisions',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Limits the number of post revisions stored.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780eb7573cc',
                  'label' => __('Revisions number', 'sfx'),
                  'name' => 'limit_revisions_number',
                  'type' => 'number',
                  'ui' => 1,
                  'instructions' => __('Limits the number of post revisions stored.', 'sfx'),
                  'default_value' => 0,
                  'min' => 0,
                  'max' => 10,
                  'conditional_logic' => array(
                    array(
                      array('field' => 'field_67780ec44d5f4', 'operator' => '==', 'value' => '1'),
                      array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                    ),

                  ),
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                ),

                array(
                  'key' => 'field_67780eadd18d1',
                  'label' => __('Disable Heartbeat', 'sfx'),
                  'name' => 'disable_heartbeat',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Deregisters heartbeat script in the admin.', 'sfx'),
                  'default_value' => 0,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),

                array(
                  'key' => 'field_67780e99cf764',
                  'label' => __('Slow Heartbeat', 'sfx'),
                  'name' => 'slow_heartbeat',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Increases Heartbeat API interval to 60 seconds.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array(
                      array('field' => 'field_67780eadd18d1', 'operator' => '==', 'value' => '0'),
                      array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                    ),

                  ),
                ),

                array(
                  'key' => 'field_67780e8f35f03',
                  'label' => __('Remove WP Embed', 'sfx'),
                  'name' => 'remove_wp_embed',
                  'type' => 'true_false',
                  'ui' => 1,
                  'instructions' => __('Removes WP Embed.', 'sfx'),
                  'default_value' => true,
                  'wrapper' => array(
                    'width' => '25%',
                  ),
                  'conditional_logic' => array(
                    array('field' => 'field_66dc98d3df425', 'operator' => '==', 'value' => '0'),
                  ),
                ),


              ),
              'style' => 'seamless',
              'position' => 'normal',
              'active' => true,
            ),
          ),
          'location' => array(
            array(
              array(
                'param' => 'options_page',
                'operator' => '==',
                'value' => 'acf-options-wp-enhancements',
              ),
            ),
          ),
          'style' => 'seamless',
          'label_placement' => 'top',
          'instruction_placement' => 'label',
          'menu_order' => 0,
          'position' => 'normal',
          'hide_on_screen' => '',
          'active' => true,
          'description' => '',
        ));
    }
  }
}
