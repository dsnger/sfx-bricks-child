<?php

namespace SFX\Options;

defined('ABSPATH') or die('Pet a cat!');

class Controller
{


  public function __construct()
  {
    // Initialize ACF option pages
    $this->load_setting_pages();

    // Initialize the theme only after ACF is confirmed to be active
    add_action('acf/init', [$this,'handle_options']);

  }

  /**
   * Initialize all ACF options pages.
   */
  private function load_setting_pages()
  {
    // new \SFX\Options\AdminOptionPages();
    new \SFX\Options\ACF\OptionsSocialMedia();
    new \SFX\Options\ACF\OptionsCustomScripts();
    new \SFX\Options\ACF\OptionsPresetScripts();
    new \SFX\Options\ACF\OptionsHeader();
    new \SFX\Options\ACF\OptionsFooter();

  }

  /**
   * Handle actions based on the ACF options set.
   */


  private function is_option_enabled($option_key)
  {
    return (bool) get_option('options_' . $option_key);
  }


  public function get_acf_option($option_key, $default = null)
  {
    // Attempt to get the option using get_option
    $value = get_field($option_key, 'option');

    // Ensure the value is unserialized if needed
    return $value !== false ? $value : $default;
  }


  public function handle_options()
  {
    // Check if Iconify is enabled
    if ($this->is_option_enabled('iconify')) {
      $this->execute_iconify();
    } else {
      ControllerHelper::delete_script_file('iconify');
    }

    // Check if Alpine JS is enabled
    if ($this->is_option_enabled('alpine')) {
      $this->execute_alpine_js();
    } else {
      // Enqueue additional Alpine.js plugins based on ACF options
      $alpine_options = $this->get_acf_option('alpine_options');
      if(!is_array($alpine_options)) return;
      foreach ($alpine_options as $plugin => $enabled) {
        if ($enabled) {
          ControllerHelper::delete_script_file("alpine_$plugin");
        }
      }
    }

    // Check if GSAP is enabled
    if ($this->is_option_enabled('gsap')) {
      $this->execute_gsap();
    } else {
      ControllerHelper::delete_script_file('gsap');
    }

    // Check if Locomotive Scroll is enabled
    if ($this->is_option_enabled('locomotive_scroll')) {
      $this->execute_locomotive_scroll();
    } else {
      ControllerHelper::delete_script_file('locomotive_scroll');
    }

    // Check if AOS is enabled
    if ($this->is_option_enabled('aos')) {
      $this->execute_aos();
    } else {
      ControllerHelper::delete_script_file('aos');
      remove_action('wp_footer', [$this, 'output_aos_init_script']);
    }

    if ($this->is_option_enabled('custom_scripts')) {
      $this->execute_custom_scripts();
    } else {
      $this->delete_custom_scripts();
    }

    if ($this->is_option_enabled('custom_head_html_frontend')) {
      $this->execute_custom_head_html();
    }

    if ($this->is_option_enabled('custom_footer_html_frontend')) {
      $this->execute_custom_footer_html();
    }
  }


  private function execute_iconify()
  {
    add_action('wp_enqueue_scripts', function () {
      $src = ControllerHelper::download_cdn_script('iconify', 'iconify');
      wp_enqueue_script('iconify', $src, [], null, true);
    });
  }

  private function execute_alpine_js()
  {
    add_action('wp_enqueue_scripts', function () {
      $src = ControllerHelper::download_cdn_script('alpine', 'alpine');
      wp_enqueue_script('alpine', $src, [], null, true);

      // Enqueue additional Alpine.js plugins based on ACF options
      $alpine_options = $this->get_acf_option('alpine_options');
      foreach ($alpine_options as $plugin => $enabled) {
        if ($enabled) {
          $plugin_src = ControllerHelper::download_cdn_script("alpine_$plugin", "alpine-$plugin");
          wp_enqueue_script("alpine-$plugin", $plugin_src, ['alpine'], null, true);
        }
      }
    });
  }


  private function execute_gsap()
  {
    add_action('wp_enqueue_scripts', function () {
      $gsap_src = ControllerHelper::download_cdn_script('gsap', 'gsap');
      $scrolltrigger_src = ControllerHelper::download_cdn_script('scrolltrigger', 'scrolltrigger');
      wp_enqueue_script('gsap', $gsap_src, [], null, true);
      wp_enqueue_script('scrolltrigger', $scrolltrigger_src, ['gsap'], null, true);
    });
  }

  private function execute_locomotive_scroll()
  {
    add_action('wp_enqueue_scripts', function () {
      $script_src = ControllerHelper::download_cdn_script('locomotive_scroll', 'locomotive-scroll');
      $style_src = ControllerHelper::download_cdn_script('locomotive_scroll_css', 'locomotive-scroll-css');
      wp_enqueue_script('locomotive-scroll', $script_src, [], null, true);
      wp_enqueue_style('locomotive-scroll-css', $style_src, [], null);
    });
  }

  private function execute_aos()
  {
    add_action('wp_enqueue_scripts', function () {
      $aos_js_src = ControllerHelper::download_cdn_script('aos', 'aos');
      $aos_css_src = ControllerHelper::download_cdn_script('aos_css', 'aos-css');
      wp_enqueue_script('aos', $aos_js_src, [], null, true);
      wp_enqueue_style('aos-css', $aos_css_src, [], null);

      // Add the footer script
      add_action('wp_footer', [$this, 'output_aos_init_script']);
    });
  }


  // Output AOS initialization script
  public function output_aos_init_script()
  {
    $aos_settings = $this->get_acf_option('aos_settings');
    if ($aos_settings) {
      $init_script = ControllerHelper::generate_aos_init_script($aos_settings);
      echo "<script>{$init_script}</script>";
    }
  }


  public function execute_custom_scripts()
  {
    $scripts = $this->get_acf_option('custom_scripts');

    if ($scripts) {
      foreach ($scripts as $script) {
        $this->handle_custom_scripts($script);
      }
    }
  }

  private function handle_custom_scripts($script)
  {
    $script_type = $script['script_type'];
    $location = $script['location'];
    $include_type = $script['include_type'];
    $frontend_only = $script['frontend_only'];
    $source_type = $script['script_source_type'];
    $handle = sanitize_title($script['script_name']);

    // Determine the script source URL based on the selected source type
    if ($source_type === 'file') {
      $src = $script['script_file'];
    } elseif ($source_type === 'cdn') {
      $src = $script['script_cdn']; // Use the CDN link directly
    } elseif ($source_type === 'cdn_file') {
      // Download the script from the CDN and serve it locally
      $src = ControllerHelper::download_cdn_script($script['script_cdn'], $handle);
    } else {
      return; // Exit if the source type is unrecognized
    }

    $in_footer = ($location === 'footer');

    if ($frontend_only && !is_admin()) {
      return;
    }

    if ($script_type === 'javascript') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $in_footer) {
          wp_enqueue_script($handle, $src, [], null, $in_footer);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $in_footer) {
          wp_register_script($handle, $src, [], null, $in_footer);
        });
      }
    } elseif ($script_type === 'CSS') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src) {
          wp_enqueue_style($handle, $src);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src) {
          wp_register_style($handle, $src);
        });
      }
    }
  }

  private function delete_custom_scripts()
  {
    $scripts = $this->get_acf_option('custom_scripts');

    if ($scripts) {
      foreach ($scripts as $script) {
        $handle = sanitize_title($script['script_name']);
        $source_type = $script['script_source_type'];

        if ($source_type === 'cdn_file' || $source_type === 'file') {
          // Delete the downloaded script file
          ControllerHelper::delete_script_file($handle, 'custom-scripts');
        }
      }
    }
  }


  public function execute_custom_head_html_frontend()
  {
    add_action('wp_head', [$this, 'handle_custom_head_html_frontend'], 1);
  }


  public function handle_custom_head_html_frontend()
  {
    $custom_html = $this->get_acf_option('custom_head_html_frontend'); // Retrieve custom HTML from settings
    if (!empty($custom_html)) {
      echo $custom_html;
    }
  }


  public function execute_custom_footer_html_frontend()
  {
    add_action('wp_head', [$this, 'handle_custom_footer_html_frontend'], 1);
  }


  public function handle_custom_footer_html_frontend()
  {
    $custom_html = $this->get_acf_option('custom_footer_html_frontend'); // Retrieve custom HTML from settings
    if (!empty($custom_html)) {
      echo $custom_html;
    }
  }


  private function enqueue_custom_scripts()
  {
    add_action('wp_enqueue_scripts', function () {
      // Example of enqueuing a custom script
      wp_enqueue_script('custom-script', get_template_directory_uri() . '/assets/js/custom-script.js', [], '1.0.0', true);
    });
  }


  private function enqueue_preset_scripts()
  {
    add_action('wp_enqueue_scripts', function () {
      // Example of enqueuing a preset script
      wp_enqueue_script('preset-script', get_template_directory_uri() . '/assets/js/preset-script.js', [], '1.0.0', true);
    });
  }

  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'activation_option_name' => 'options_admin',
      'activation_option_key' => null,
      'option_value' => null,
      'hook' => null,
      'error' => 'Missing AdminOptionsController class in theme',
    ];
  }

}
