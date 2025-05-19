<?php

declare(strict_types=1);

namespace SFX\TextSnippets;

class Controller
{


  public const OPTION_NAME = 'sfx_text_snippets_options';

  public function __construct()
  {
    Settings::register(self::OPTION_NAME);
    AdminPage::register();
    AssetManager::register();
    PostType::init();
    new Shortcode\SC_Snippet();

    // Initialize the theme only after ACF is confirmed to be active
    add_action('init', [$this,'handle_options']);
    add_action('update_option_' . self::OPTION_NAME, [$this, 'handle_options'], 10, 2);
    add_action('init', [$this, 'register_bricks_dynamic_tag'], 20, 3);
  }


  public function handle_options():void
  {
  //
  }


  /**
   * Register Bricks dynamic data tag {snippet_content:ID} for text snippets.
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
      'name'  => '{snippet_content}',
      'label' => 'Text Snippet Content (by ID)',
      'group' => 'Custom',
    ];
    return $tags;
  }

  /**
   * Render the custom tag output for Bricks.
   * Supports {snippet_content:ID} for content and {snippet_content:ID:field_slug} for custom field value.
   */
  public static function render_bricks_dynamic_tag(string $tag, $post, string $context): string
  {
    if (strpos($tag, '{snippet_content:') !== 0) {
      return $tag;
    }
    // Match {snippet_content:ID} or {snippet_content:ID:field_slug}
    if (!preg_match('/\{snippet_content:(\d+)(?::([a-zA-Z0-9_\-]+))?\}/', $tag, $m)) {
      return '';
    }
    $id = (int) $m[1];
    $field_slug = $m[2] ?? '';
    // Use the shared render logic from SC_Snippet
    return Shortcode\SC_Snippet::render_snippet($id, $field_slug);
  }

  /**
   * Replace all occurrences of the dynamic tag in content.
   */
  public static function render_bricks_dynamic_content($content, $post = null, $context = 'text')
  {
    if (strpos($content, '{snippet_content:') === false) {
      return $content;
    }
    if (!preg_match_all('/\{snippet_content:[^}]+\}/', $content, $matches)) {
      return $content;
    }
    foreach ($matches[0] as $tag) {
      $replacement = self::render_bricks_dynamic_tag($tag, $post, $context);
      $content = str_replace($tag, $replacement, $content);
    }
    return $content;
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
      'error' => 'Missing TextSnippetsController class in theme',
      'hook'  => null,
    ];
  }
}