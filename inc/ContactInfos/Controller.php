<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class Controller
{
  /**
   * Marker property used to select post types for Bricks' post type lists.
   * Inert: nothing in WordPress or Bricks reads it apart from our own filter.
   *
   * Must be the SAME string the SocialMediaAccounts controller uses, so the two filters
   * compose on the shared bricks/registered_post_types_args hook: each reads the marker the
   * other already set and adds its own CPT, exposing both instead of clobbering one.
   *
   * Kept as a duplicated literal rather than a shared constant on purpose: the two CPT
   * modules stay decoupled, and the value is an arbitrary internal marker with no reason to
   * change. If the two ever drift apart, the composition regression case (Case 38 in
   * social-bricks-dynamic-data-test.php) fails — it asserts both CPTs survive the chained
   * filters — so the coupling is pinned by a test, not only by this comment.
   */
  private const BRICKS_SELECTABLE_PROP = 'sfx_bricks_selectable';

  private static $shortcode_instance;

  public function __construct()
  {
    // Initialize components
    AssetManager::register();
    PostType::init();
    
    // Initialize shortcode instance and store it
    self::$shortcode_instance = new Shortcode\SC_ContactInfos();

    // Register hooks through consolidated system
    add_action('sfx_init_advanced_features', [$this, 'register_bricks_dynamic_tag']);
    

  }


  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'menu_slug' => AdminPage::$menu_slug,
      'page_title' => __(AdminPage::$page_title, 'sfxtheme'),
      'description' => __(AdminPage::$description, 'sfxtheme'),
      'url' => admin_url('edit.php?post_type=' . PostType::$post_type),
      'show_in_theme_settings' => false,
      'error' => 'Missing ContactInfosController class in theme',
      'hook'  => null,
    ];
  }



  /**
   * Register Bricks dynamic data tag {contact_info:field} or {contact_info:field:location} for contact infos.
   */
  public static function register_bricks_dynamic_tag(): void
  {
    add_filter('bricks/dynamic_tags_list', [self::class, 'add_bricks_dynamic_tag'], 20);
    // Only register render_tag filter for content processing, not for individual tag rendering
    add_filter('bricks/dynamic_data/render_content', [self::class, 'render_bricks_dynamic_content'], 20, 3);
    add_filter('bricks/frontend/render_data', [self::class, 'render_bricks_frontend_data'], 20, 2);

    // Let the (non-public) contact CPT appear in Bricks' query-loop post type list.
    // Late (20) so we consume whatever args other plugins have already asked for.
    add_filter('bricks/registered_post_types_args', [self::class, 'allow_bricks_post_type_selection'], 20);
  }

  /**
   * Let the (non-public) contact info CPT appear in Bricks' post type lists without making
   * the CPT public. Same marker-property approach as SocialMediaAccounts — see
   * BRICKS_SELECTABLE_PROP for why the marker string must match. Bricks feeds these args to
   * get_post_types(), which AND-matches every pair, so "public OR sfx_contact_info" is not
   * expressible as args (every internal sfx_* CPT shares an identical public/show_ui profile);
   * we compute the union ourselves and select it via an inert marker property. No existing
   * property is touched, so sitemap exclusion and the noindex profile keep working.
   *
   * @param array<string, mixed> $args
   * @return array<string, mixed>
   */
  public static function allow_bricks_post_type_selection(array $args): array
  {
    global $wp_post_types;

    if (!is_array($wp_post_types)) {
      return $args;
    }

    $selectable = get_post_types($args);
    $selectable[PostType::$post_type] = PostType::$post_type;

    foreach ($wp_post_types as $name => $object) {
      $object->{self::BRICKS_SELECTABLE_PROP} = isset($selectable[$name]);
    }

    return [self::BRICKS_SELECTABLE_PROP => true];
  }

  /**
   * Add the custom tag to Bricks dynamic data picker.
   */
  public static function add_bricks_dynamic_tag(array $tags): array
  {
    $contact_fields = FieldRegistry::get_fields();
    
    // Add each field as a separate dynamic tag option
    foreach ($contact_fields as $field => $label) {
      $tags[] = [
        'name'  => '{contact_info:' . $field . '}',
        'label' => 'Contact Info: ' . $label,
        'group' => 'Contact Info',
      ];
    }
    
    return $tags;
  }

  /**
   * Render the custom tag output for Bricks.
   * Supports {contact_info:field} and {contact_info:field:location} with optional attributes.
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
        // If we can't determine the tag, return empty string
        return '';
      }
    }
    
    // Ensure tag is a string
    if (!is_string($tag)) {
      return '';
    }
    
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

    // Convert old location parameter to contact_id or type
    if ($location !== null) {
      // If it's a numeric location, treat as contact_id
      if (is_numeric($location)) {
        $atts['contact_id'] = (int) $location;
      } else {
        // Otherwise treat as type (main/branch)
        $atts['type'] = $location;
      }
    } else {
      // No explicit id/type: inside a Bricks query loop over contacts, resolve the loop post
      // so {contact_info:field} renders each iterated contact. Only kicks in when the context
      // IS a published contact; otherwise the existing type=main default is preserved, so
      // header/footer usage on ordinary pages is unaffected.
      $context_id = self::resolve_context_contact_id($post);
      if ($context_id > 0) {
        $atts['contact_id'] = $context_id;
      }
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
    
    // Ensure contact_id is properly cast to integer if it exists
    if (isset($atts['contact_id'])) {
      $atts['contact_id'] = (int) $atts['contact_id'];
      if ($atts['contact_id'] <= 0) {
        unset($atts['contact_id']);
      }
    }

    // Use the SC_ContactInfos class to render the field
    if (!class_exists('SFX\\ContactInfos\\Shortcode\\SC_ContactInfos')) {
      return '';
    }
    
    try {
      // Ensure shortcode instance exists
      if (self::$shortcode_instance === null) {
        self::$shortcode_instance = new Shortcode\SC_ContactInfos();
      }
      
      $sc = self::$shortcode_instance;
      

      
      // Render using the same logic as the shortcode
      $result = $sc->render_contact_info($atts);
      
      return $result;
    } catch (\Exception $e) {
      return '';
    }
  }

  /**
   * Resolve the contextual post to a published contact info ID, or 0.
   *
   * Bricks hands us the resolved loop post, so prefer it and only fall back to the global
   * current post when it is absent. Anything that is not a published sfx_contact_info yields
   * 0, which keeps the type=main default in play for non-loop usage.
   *
   * @param \WP_Post|null $post Bricks only ever passes a WP_Post or null; any other value
   *                           is treated as "don't guess" and returns 0.
   */
  private static function resolve_context_contact_id($post): int
  {
    // Bricks only ever hands these filters a WP_Post or null.
    if ($post instanceof \WP_Post) {
      $post_id = $post->ID;
    } elseif ($post === null) {
      $post_id = (int) (get_the_ID() ?: 0);
    } else {
      // Context was supplied but is not a post: do not guess.
      return 0;
    }

    if ($post_id <= 0) {
      return 0;
    }

    $candidate = get_post($post_id);
    if (!$candidate instanceof \WP_Post
      || $candidate->post_type !== PostType::$post_type
      || $candidate->post_status !== 'publish') {
      return 0;
    }

    return $post_id;
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
