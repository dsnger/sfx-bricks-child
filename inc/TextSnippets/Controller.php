<?php

declare(strict_types=1);

namespace SFX\TextSnippets;

class Controller
{


  public const OPTION_NAME = 'sfx_text_snippets_options';

  public function __construct()
  {
    // Initialize components
    AdminPage::register();
    AssetManager::register();
    PostType::init();
    new Shortcode\SC_Snippet();

    // Register hooks through consolidated system
    add_action('sfx_init_settings', [$this, 'handle_options']);
    add_action('sfx_init_advanced_features', [$this, 'register_bricks_dynamic_tag']);
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
    // Only register render_tag filter for content processing, not for individual tag rendering
    add_filter('bricks/dynamic_data/render_content', [self::class, 'render_bricks_dynamic_content'], 20, 3);
    add_filter('bricks/frontend/render_data', [self::class, 'render_bricks_frontend_data'], 20, 2);
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
  public static function render_bricks_dynamic_tag($tag, $post, $context = 'text'): string
  {
    // Handle case where $tag might be an array (Bricks framework compatibility)
    if (is_array($tag)) {
      // If tag is an array, try to extract the tag value
      if (isset($tag['tag'])) {
        $tag = $tag['tag'];
      } elseif (isset($tag['name'])) {
        $tag = $tag['name'];
      } elseif (isset($tag['value'])) {
        $tag = $tag['value'];
      } else {
        // Log the array structure for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log('TextSnippets: render_bricks_dynamic_tag received array with keys: ' . implode(', ', array_keys($tag)));
        }
        // If we can't determine the tag, return empty string
        return '';
      }
    }
    
    // Ensure tag is a string
    if (!is_string($tag)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('TextSnippets: render_bricks_dynamic_tag received non-string tag: ' . gettype($tag));
      }
      return '';
    }
    
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
    if (strpos($content, '{snippet_content:') === false) {
      return $content;
    }
    
    // Regex to match snippet_content: tag with any arguments
    if (!preg_match_all('/\{(snippet_content:[^}]+)\}/', $content, $matches)) {
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
      'show_in_theme_settings' => false, // Exclude from theme settings page since it's a standalone feature
      'error' => 'Missing TextSnippetsController class in theme',
      'hook'  => null,
    ];
  }
}