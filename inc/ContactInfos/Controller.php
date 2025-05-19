<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class Controller
{


  public const OPTION_NAME = 'sfx_contact_infos_options';

  public function __construct()
  {
    Settings::register(self::OPTION_NAME);
    AdminPage::register();
    AssetManager::register();
    new Shortcode\SC_ContactInfos(self::OPTION_NAME);

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
    add_filter('bricks/frontend/render_data', [self::class, 'render_bricks_dynamic_content'], 20, 2);
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
   * Supports {contact_info:field} and {contact_info:field:location}.
   */
  public static function render_bricks_dynamic_tag(string $tag, $post, string $context): string
  {
    if (strpos($tag, '{contact_info:') !== 0) {
      return $tag;
    }
    // Match {contact_info:field} or {contact_info:field:location}
    if (!preg_match('/\{contact_info:([a-zA-Z0-9_\-]+)(?::(\d+))?\}/', $tag, $m)) {
      return '';
    }
    $field = $m[1];
    $location = isset($m[2]) ? $m[2] : null;
    // Use the SC_ContactInfos class to render the field
    if (!class_exists('SFX\\ContactInfos\\Shortcode\\SC_ContactInfos')) {
      return '';
    }
    $sc = new \SFX\ContactInfos\Shortcode\SC_ContactInfos();
    // Render using the same logic as the shortcode
    return $sc->render_contact_info([
      'field' => $field,
      'location' => $location,
    ]);
  }

  /**
   * Replace all occurrences of the dynamic tag in content.
   */
  public static function render_bricks_dynamic_content($content, $post = null, $context = 'text')
  {
    if (strpos($content, '{contact_info:') === false) {
      return $content;
    }
    if (!preg_match_all('/\{contact_info:[^}]+\}/', $content, $matches)) {
      return $content;
    }
    foreach ($matches[0] as $tag) {
      $replacement = self::render_bricks_dynamic_tag($tag, $post, $context);
      $content = str_replace($tag, $replacement, $content);
    }
    return $content;
  }
}