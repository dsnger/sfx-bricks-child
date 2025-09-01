<?php

declare(strict_types=1);

namespace SFX\TextSnippets;

class AdminPage
{
  public static string $menu_slug = 'sfx-text-snippets';
  public static string $page_title = 'Text Snippets';
  public static string $description = 'Configure and manage the text snippets which can be used everywhere in pages or posts via shortcode.';


  public static function register(): void
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
  }


  public static function init(): void
  {
    add_action('admin_menu', [self::class, 'add_admin_menu']);
  }

  public static function add_submenu_page(): void
  {
    // add_submenu_page(
    //   \SFX\SFXBricksChildAdmin::$menu_slug,
    //   self::$page_title,
    //   self::$page_title,
    //   'manage_options',
    //   self::$menu_slug,
    //   [self::class, 'render_admin_page']
    // );
  }

  /**
   * Render the admin page for text snippet settings.
   */
  public static function render_admin_page(): void
  {
    $fields = Settings::get_fields();
    $options = get_option(Settings::$OPTION_NAME, []);
?>
    <div class="wrap">
      <h1>Text Snippets</h1>
    </div>
<?php
  }
}
