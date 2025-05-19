<?php

declare(strict_types=1);

namespace SFX\CompanyLogo;

class Controller
{


  public const OPTION_NAME = 'sfx_company_logo_options';

  public function __construct()
  {
    Settings::register(self::OPTION_NAME);
    AdminPage::register();
    AssetManager::register();
    new Shortcode\SC_Logo(self::OPTION_NAME);

    // Initialize the theme only after ACF is confirmed to be active
    add_action('init', [$this,'handle_options']);
    add_action('update_option_' . self::OPTION_NAME, [$this, 'handle_options'], 10, 2);

    // Register Bricks dynamic data tag for company logo
    self::register_bricks_dynamic_tag();
  }


  public function handle_options():void
  {
  //

  }


  public function handle_company_logo($company_logo)
  {
    // Handle the company logo
  } 


  private function is_option_enabled(string $option_key): bool
  {
    $options = get_option(self::OPTION_NAME, []);
    return !empty($options[$option_key]);
  }


  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'menu_slug' => AdminPage::$menu_slug,
      'page_title' => AdminPage::$page_title,
      'description' => AdminPage::$description,
      'error' => 'Missing CompanyLogoController class in theme',
      'hook'  => null,
    ];
  }

  /**
   * Register Bricks dynamic data tag {company_logo:type} for logo output.
   */
  public static function register_bricks_dynamic_tag(): void
  {
    add_filter('bricks/dynamic_tags_list', [self::class, 'add_bricks_dynamic_tag'], 20);
    add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_bricks_dynamic_tag'], 20, 3);
    add_filter('bricks/dynamic_data/render_content', [self::class, 'render_bricks_dynamic_content'], 20, 3);
    add_filter('bricks/frontend/render_data', [self::class, 'render_bricks_dynamic_content'], 20, 2);
  }

  /**
   * Add the custom tag to Bricks dynamic data picker.
   */
  public static function add_bricks_dynamic_tag(array $tags): array
  {
    $tags[] = [
      'name'  => '{company_logo}',
      'label' => 'Company Logo',
      'group' => 'Custom',
    ];
    return $tags;
  }

  /**
   * Render the custom tag output for Bricks.
   * Supports {company_logo:type} and {company_logo:type:attr1=val1,attr2=val2} for advanced usage.
   */
  public static function render_bricks_dynamic_tag(string $tag, $post, string $context): string
  {
    if (strpos($tag, '{company_logo:') !== 0) {
      return $tag;
    }
    // Match {company_logo:type} or {company_logo:type:attr1=val1,attr2=val2}
    if (!preg_match('/\{company_logo:([a-zA-Z0-9_\-]+)(?::([^}]+))?\}/', $tag, $m)) {
      return '';
    }
    $type = $m[1];
    $attr_string = $m[2] ?? '';
    $atts = ['type' => $type];
    // Parse additional attributes if present (format: key=val,key2=val2)
    if ($attr_string) {
      $pairs = explode(',', $attr_string);
      foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== false) {
          [$k, $v] = explode('=', $pair, 2);
          $atts[trim($k)] = trim($v);
        }
      }
    }
    if (!class_exists('SFX\\CompanyLogo\\Shortcode\\SC_Logo')) {
      return '';
    }
    $sc = new \SFX\CompanyLogo\Shortcode\SC_Logo(self::OPTION_NAME);
    return $sc->render_logo($atts);
  }

  /**
   * Replace all occurrences of the dynamic tag in content.
   */
  public static function render_bricks_dynamic_content($content, $post = null, $context = 'text')
  {
    if (strpos($content, '{company_logo:') === false) {
      return $content;
    }
    if (!preg_match_all('/\{company_logo:[^}]+\}/', $content, $matches)) {
      return $content;
    }
    foreach ($matches[0] as $tag) {
      $replacement = self::render_bricks_dynamic_tag($tag, $post, $context);
      $content = str_replace($tag, $replacement, $content);
    }
    return $content;
  }
}