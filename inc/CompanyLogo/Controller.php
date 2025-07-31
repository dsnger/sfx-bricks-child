<?php

declare(strict_types=1);

namespace SFX\CompanyLogo;

class Controller
{


  public const OPTION_NAME = 'sfx_company_logo_options';

  public function __construct()
  {
    // Initialize components
    AdminPage::register();
    AssetManager::register();
    Settings::register();
    new Shortcode\SC_Logo(self::OPTION_NAME);

    // Register hooks through consolidated system
    add_action('sfx_init_settings', [$this, 'handle_options']);
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
    add_filter('bricks/frontend/render_data', [self::class, 'render_bricks_frontend_data'], 20, 2);
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
   * For bricks/dynamic_data/render_content (3 params)
   */
  public static function render_bricks_dynamic_content($content, $post = null, $context = 'text')
  {
    return self::process_dynamic_tags_in_content($content, $post, $context);
  }

  /**
   * Replace all occurrences of the dynamic tag in content.
   * For bricks/frontend/render_data (2 params)
   */
  public static function render_bricks_frontend_data($content, $post = null)
  {
    return self::process_dynamic_tags_in_content($content, $post, 'text');
  }

  /**
   * Process dynamic tags in content - shared logic
   */
  private static function process_dynamic_tags_in_content($content, $post = null, $context = 'text')
  {
    if (strpos($content, '{company_logo:') === false) {
      return $content;
    }
    
    // Regex to match company_logo: tag with any arguments
    if (!preg_match_all('/\{(company_logo:[^}]+)\}/', $content, $matches)) {
      return $content;
    }
    
    // Nothing grouped in the regex, return the original content
    if (empty($matches[0])) {
      return $content;
    }
    
    foreach ($matches[1] as $key => $match) {
      $tag = $matches[0][$key]; // Full tag with braces
      $tag_content = $matches[1][$key]; // Tag content without braces
      
      // Get the dynamic data value using the tag content without braces
      $value = self::render_bricks_dynamic_tag('{' . $tag_content . '}', $post, $context);
      
      // Replace the tag with the transformed value
      $content = str_replace($tag, $value, $content);
    }
    
    return $content;
  }
}