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
    add_submenu_page(
      \SFX\SFXBricksChildAdmin::$menu_slug,
      self::$page_title,
      self::$page_title,
      'manage_options',
      self::$menu_slug,
      [self::class, 'render_admin_page']
    );
  }

  /**
   * Render the admin page for logo settings.
   */
  public static function render_admin_page(): void
  {
    $fields = Settings::get_fields();
    $options = get_option(Settings::$OPTION_NAME, []);
?>
    <div class="wrap sfx-logo-admin-page">
      <h1><?php echo esc_html(self::$page_title); ?></h1>

      <div class="sfx-logo-admin-columns">
        <!-- Settings Column -->
        <div class="sfx-logo-admin-column sfx-logo-settings-column">

          <h2><?php esc_html_e('Text Snippet Settings', 'sfxtheme'); ?></h2>
          <form method="post" action="options.php">
            <?php
            settings_fields(Settings::$OPTION_GROUP);
            do_settings_sections(Settings::$OPTION_GROUP);
            submit_button();
            ?>
          </form>

        </div>

        <!-- Instructions Column -->
        <div class="sfx-logo-admin-column sfx-logo-instructions-column">
          <h2><?php esc_html_e('Shortcode Usage', 'sfxtheme'); ?></h2>
          <p><?php esc_html_e('You can display the company logo anywhere using the following shortcode:', 'sfxtheme'); ?></p>
          <div class="sfx-logo-admin-box sfx-card">

            <div style="position:relative;display:inline-block;margin-bottom:15px;width:100%;">
              <pre style="background:#f7f7f7;padding:10px 72px 10px 10px;border-radius:4px;margin:0;"><code id="sfx-logo-shortcode">[logo type="default" homelink="true" maxwidth="150px"]</code></pre>
              <button type="button" id="sfx-copy-shortcode-btn" class="button" style="position:absolute;top:5px;right:5px;">Copy</button>
            </div>
            <script>
              document.addEventListener('DOMContentLoaded', function() {
                var btn = document.getElementById('sfx-copy-shortcode-btn');
                var code = document.getElementById('sfx-logo-shortcode');
                if (btn && code) {
                  btn.addEventListener('click', function() {
                    var text = code.textContent;
                    navigator.clipboard.writeText(text).then(function() {
                      btn.textContent = 'Copied!';
                      setTimeout(function() {
                        btn.textContent = 'Copy';
                      }, 1500);
                    });
                  });
                }
              });
            </script>

            <h3><?php esc_html_e('Available Attributes', 'sfxtheme'); ?></h3>
            <ul class="sfx-logo-attribute-list">
              <li>
                <code>type</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('default | tiny | invert | invert-tiny', 'sfxtheme'); ?></span>
              </li>
              <li>
                <code>homelink</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('true | false (wrap logo in home link)', 'sfxtheme'); ?></span>
              </li>
              <li>
                <code>path</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('Custom image URL (overrides type)', 'sfxtheme'); ?></span>
              </li>
              <li>
                <code>maxwidth</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('CSS max-width for the logo (e.g., 150px)', 'sfxtheme'); ?></span>
              </li>
              <li>
                <code>width</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('HTML width attribute', 'sfxtheme'); ?></span>
              </li>
              <li>
                <code>class</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('Additional CSS classes', 'sfxtheme'); ?></span>
              </li>
              <li>
                <code>style</code>
                <span class="sfx-logo-attribute-desc"><?php esc_html_e('Inline CSS styles', 'sfxtheme'); ?></span>
              </li>
            </ul>

            <h3><?php esc_html_e('Examples', 'sfxtheme'); ?></h3>
            <div class="sfx-logo-example">
              <code>[logo]</code>
              <span class="sfx-logo-example-desc"><br><?php esc_html_e('Basic usage with default logo', 'sfxtheme'); ?></span>
            </div>
            <div class="sfx-logo-example">
              <code>[logo type="invert"]</code>
              <span class="sfx-logo-example-desc"><br><?php esc_html_e('Display inverted logo (for dark backgrounds)', 'sfxtheme'); ?></span>
            </div>
            <div class="sfx-logo-example">
              <code>[logo type="tiny" homelink="false" maxwidth="80px"]</code>
              <span class="sfx-logo-example-desc"><br><?php esc_html_e('Small logo without home link, maximum width 80px', 'sfxtheme'); ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
<?php
  }
}
