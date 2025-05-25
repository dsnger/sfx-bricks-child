<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class Controller
{


  public const OPTION_NAME = 'sfx_contact_infos_options';
  
  private static $shortcode_instance;

  public function __construct()
  {
    Settings::register(self::OPTION_NAME);
    AdminPage::register();
    AssetManager::register();
    self::$shortcode_instance = new Shortcode\SC_ContactInfos(self::OPTION_NAME);

    // Initialize the theme only after ACF is confirmed to be active
    add_action('init', [$this,'handle_options']);
    add_action('update_option_' . self::OPTION_NAME, [$this, 'handle_options'], 10, 2);

    // Register Bricks dynamic data tag for contact infos
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
   * Register Bricks dynamic data tag {contact_info:field} or {contact_info:field:location} for contact infos.
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
      'name'  => '{contact_info}',
      'label' => 'Contact Info Field',
      'group' => 'Custom',
    ];
    return $tags;
  }

  /**
   * Render the custom tag output for Bricks.
   * Supports {contact_info:field} and {contact_info:field:location} with optional attributes.
   */
  public static function render_bricks_dynamic_tag(string $tag, $post, string $context): string
  {
    if (strpos($tag, '{contact_info:') !== 0) {
      return $tag;
    }
    
    // More flexible regex pattern to handle various attribute formats
    // Matches: {contact_info:field}, {contact_info:field:location}, {contact_info:field@attr:value}, etc.
    if (!preg_match('/\{contact_info:([a-zA-Z0-9_\-]+)(?::(\d+))?(?:\s*[@\|]\s*([^}]+))?\}/', $tag, $m)) {
      return '';
    }
    
    $field = $m[1];
    $location = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
    $attributes = isset($m[3]) ? $m[3] : '';

    // Parse attributes
    $atts = ['field' => $field];
    
    // Only add location if it's not null
    if ($location !== null) {
      $atts['location'] = $location;
    }
    
    if (!empty($attributes)) {
      // Handle both pipe and @ separated attributes
      $attr_pairs = preg_split('/[\|@]/', $attributes);
      foreach ($attr_pairs as $pair) {
        $pair = trim($pair);
        if (empty($pair)) {
          continue;
        }
        
        if (strpos($pair, '=') !== false) {
          list($key, $value) = explode('=', $pair, 2);
          $atts[trim($key)] = trim($value, '"\'');
        } elseif (strpos($pair, ':') !== false) {
          // Handle colon-separated key:value pairs (e.g., link:false)
          list($key, $value) = explode(':', $pair, 2);
          $atts[trim($key)] = trim($value, '"\'');
        } elseif (!empty($pair)) {
          // Handle boolean attributes without values
          $atts[trim($pair)] = true;
        }
      }
    }

    // Use the SC_ContactInfos class to render the field
    if (!class_exists('SFX\\ContactInfos\\Shortcode\\SC_ContactInfos')) {
      return '';
    }
    $sc = self::$shortcode_instance;
    // Render using the same logic as the shortcode
    return $sc->render_contact_info($atts);
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
    if (strpos($content, '{contact_info:') === false) {
      return $content;
    }
    
    // Regex to match contact_info: tag with any arguments
    if (!preg_match_all('/\{(contact_info:[^}]+)\}/', $content, $matches)) {
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