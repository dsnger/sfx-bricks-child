<?php

namespace SFX\GeneralThemeOptions;

class AdminPage
{

  public static $menu_slug = 'sfx-general-theme-options';
  public static $page_title = 'General Theme Options';
  public static $description = 'Enable or disable core scripts, styles, and optional CSS modules for performance and customization.';


  public static function register()
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
    add_action('admin_head', [self::class, 'add_inline_styles']);
    add_action('admin_post_sfx_purge_theme_data', [self::class, 'handle_purge']);
  }

  /**
   * The Danger Zone's handler.
   *
   * Four gates before anything is deleted, in this order: the nonce, the
   * theme's own access gate, manage_options, and the typed phrase. The last
   * one is the reason this is safe to expose at all — the disabled button in
   * the browser is convenience and is simply absent from a request built by
   * hand.
   */
  public static function handle_purge(): void
  {
    check_admin_referer('sfx_purge_theme_data');
    \SFX\AccessControl::die_if_unauthorized_theme();

    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__('You do not have permission to delete theme data.', 'sfxtheme'),
        esc_html__('Access Denied', 'sfxtheme'),
        ['response' => 403, 'back_link' => true]
      );
    }

    $typed = isset($_POST['sfx_purge_confirmation']) && is_string($_POST['sfx_purge_confirmation'])
      ? wp_unslash($_POST['sfx_purge_confirmation'])
      : '';

    if (!\SFX\DataPurge::confirmed($typed)) {
      wp_die(
        esc_html__('The confirmation phrase did not match. Nothing was deleted.', 'sfxtheme'),
        esc_html__('Not confirmed', 'sfxtheme'),
        ['response' => 400, 'back_link' => true]
      );
    }

    // Media Credits meta is editor-authored content, so it needs its own
    // deliberate act rather than riding along with the settings.
    $include_media_credits = !empty($_POST['sfx_purge_media_credits']);

    $report = \SFX\DataPurge::run($include_media_credits);

    // The counts travel in the URL so the notice can report what actually
    // happened. An irreversible operation that always claims success is worse
    // than one that admits it removed less than expected.
    wp_safe_redirect(
      add_query_arg(
        [
          'sfx-purged'     => '1',
          'sfx-options'    => (int) $report['options'],
          'sfx-meta'       => (int) $report['meta_keys'],
          'sfx-transients' => (int) $report['transients'],
        ],
        admin_url('admin.php?page=' . self::$menu_slug)
      )
    );
    exit;
  }

  public static function add_submenu_page(): void
  {
    // Only register menu if user has theme settings access
    if (!\SFX\AccessControl::can_access_theme_settings()) {
      return;
    }

    add_submenu_page(
      \SFX\SFXBricksChildAdmin::$menu_slug,
      self::$page_title,
      self::$page_title,
      'manage_options',
      self::$menu_slug,
      [self::class, 'render_page'],
      1
    );
  }

  /**
   * Add inline styles for the settings page.
   */
  public static function add_inline_styles(): void
  {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, self::$menu_slug) === false) {
      return;
    }
    ?>
    <style>
      .sfx-settings-section {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        padding: 1px 20px 20px;
        margin-bottom: 20px;
      }
      .sfx-settings-section h2 {
        margin: 20px -20px 15px;
        padding: 12px 20px;
        background: #f6f7f7;
        border-bottom: 1px solid #c3c4c7;
        font-size: 14px;
        font-weight: 600;
      }
      .sfx-settings-section h2:first-child {
        margin-top: -1px;
        border-radius: 4px 4px 0 0;
      }
      .sfx-settings-section .form-table th {
        padding-left: 0;
        width: 200px;
      }
      .sfx-settings-section p.description {
        color: #646970;
        font-style: italic;
        margin: 0 0 15px;
      }
      /* Copy CSS button styles */
      .sfx-copy-css-btn {
        margin-left: 10px !important;
        vertical-align: middle;
        transition: background-color 0.2s, border-color 0.2s;
      }
      .sfx-copy-css-btn.copied {
        background-color: #00a32a !important;
        border-color: #00a32a !important;
        color: #fff !important;
      }
      .sfx-copy-css-btn.error {
        background-color: #d63638 !important;
        border-color: #d63638 !important;
        color: #fff !important;
      }
      /* CSS Variables display */
      .sfx-css-variables {
        margin-top: 8px;
      }
      .sfx-css-variables summary {
        cursor: pointer;
        color: #2271b1;
        font-size: 12px;
        user-select: none;
      }
      .sfx-css-variables summary:hover {
        color: #135e96;
      }
      .sfx-css-variables[open] summary {
        margin-bottom: 6px;
      }
      .sfx-variables-wrapper {
        display: flex;
        gap: 8px;
        align-items: flex-start;
      }
      .sfx-variables-list {
        display: block;
        flex: 1;
        background: #f6f7f7;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        padding: 8px 10px;
        font-size: 11px;
        line-height: 1.6;
        color: #1e1e1e;
        word-break: break-word;
        max-width: 500px;
      }
      .sfx-copy-vars-btn {
        flex-shrink: 0;
        transition: background-color 0.2s, border-color 0.2s;
      }
      .sfx-copy-vars-btn.copied {
        background-color: #00a32a !important;
        border-color: #00a32a !important;
        color: #fff !important;
      }
      /* Danger Zone — set apart on purpose: this is the one control on the
         page that cannot be undone by saving again. */
      .sfx-danger-zone {
        margin-top: 3rem;
        padding: 1.25rem 1.5rem;
        border: 1px solid #d63638;
        border-left-width: 4px;
        border-radius: 4px;
        background: #fcf0f1;
        max-width: 48rem;
      }

      .sfx-danger-zone h2 {
        margin-top: 0;
        color: #8a1f21;
      }

      .sfx-danger-zone code {
        background: #fff;
        border: 1px solid #dcdcde;
        padding: 0.1em 0.4em;
      }

      .sfx-danger-zone__button:not(:disabled) {
        border-color: #d63638;
        color: #d63638;
      }

      .sfx-danger-zone__button:not(:disabled):hover {
        background: #d63638;
        border-color: #d63638;
        color: #fff;
      }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Helper function to copy text to clipboard
      function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          return navigator.clipboard.writeText(text);
        } else {
          // Fallback for older browsers
          return new Promise(function(resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            var success = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (success) {
              resolve();
            } else {
              reject(new Error('Fallback copy failed'));
            }
          });
        }
      }
      
      // Copy CSS file content
      document.querySelectorAll('.sfx-copy-css-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          var button = this;
          var cssUrl = button.dataset.cssFile;
          var labelCopy = button.dataset.labelCopy;
          var labelCopied = button.dataset.labelCopied;
          var labelError = button.dataset.labelError;
          
          button.disabled = true;
          
          fetch(cssUrl)
            .then(function(response) {
              if (!response.ok) {
                throw new Error('Network response was not ok');
              }
              return response.text();
            })
            .then(function(css) {
              return copyToClipboard(css);
            })
            .then(function() {
              button.textContent = labelCopied;
              button.classList.add('copied');
              button.classList.remove('error');
              
              setTimeout(function() {
                button.textContent = labelCopy;
                button.classList.remove('copied');
                button.disabled = false;
              }, 2000);
            })
            .catch(function(err) {
              console.error('Copy CSS error:', err);
              button.textContent = labelError;
              button.classList.add('error');
              button.classList.remove('copied');
              
              setTimeout(function() {
                button.textContent = labelCopy;
                button.classList.remove('error');
                button.disabled = false;
              }, 2000);
            });
        });
      });
      
      // Copy CSS variables
      document.querySelectorAll('.sfx-copy-vars-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          var button = this;
          var variables = button.dataset.variables;
          var labelCopy = button.dataset.labelCopy;
          var labelCopied = button.dataset.labelCopied;
          
          button.disabled = true;
          
          copyToClipboard(variables)
            .then(function() {
              button.textContent = labelCopied;
              button.classList.add('copied');
              
              setTimeout(function() {
                button.textContent = labelCopy;
                button.classList.remove('copied');
                button.disabled = false;
              }, 2000);
            })
            .catch(function(err) {
              console.error('Copy variables error:', err);
              button.disabled = false;
            });
        });
      });
    });
    </script>
    <?php
  }

  public static function render_page()
  {
    // Block direct URL access for unauthorized users
    \SFX\AccessControl::die_if_unauthorized_theme();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('General Theme Options', 'sfxtheme'); ?></h1>
      <form method="post" action="options.php">
        <?php
        settings_fields(\SFX\GeneralThemeOptions\Settings::$OPTION_GROUP);
        
        // Render sections with custom wrapper
        self::render_sections();
        
        submit_button();
        ?>
      </form>

      <?php self::render_danger_zone(); ?>
    </div>
    <?php
  }

  /**
   * Delete every setting this theme stored, while the theme is still active.
   *
   * WordPress runs no uninstall routine for a theme — uninstall.php is a
   * plugin convention and delete_theme() never includes it. The only moment
   * this can happen is now, from a screen the theme itself renders, which is
   * why the control lives here rather than behind a promise about later.
   */
  private static function render_danger_zone(): void
  {
    $present = 0;

    foreach (\SFX\DataPurge::option_names() as $option) {
      if (get_option($option, null) !== null) {
        $present++;
      }
    }

    if (isset($_GET['sfx-purged'])) {
      $removed_options    = isset($_GET['sfx-options']) ? absint($_GET['sfx-options']) : 0;
      $removed_meta       = isset($_GET['sfx-meta']) ? absint($_GET['sfx-meta']) : 0;
      $removed_transients = isset($_GET['sfx-transients']) ? absint($_GET['sfx-transients']) : 0;

      wp_admin_notice(
        sprintf(
          /* translators: 1: settings removed, 2: cached rows removed */
          esc_html__('Theme data deleted: %1$d settings and %2$d cached rows.', 'sfxtheme'),
          $removed_options,
          $removed_transients
        ) . ' ' . ($removed_meta > 0
          ? esc_html__('The copyright and AI markings on your media were deleted as well.', 'sfxtheme')
          : esc_html__('Your content, including the copyright and AI markings on your media, was not touched.', 'sfxtheme')),
        ['type' => $removed_options > 0 ? 'success' : 'warning', 'dismissible' => true]
      );
    }
    ?>
    <div class="sfx-danger-zone">
      <h2><?php esc_html_e('Danger Zone', 'sfxtheme'); ?></h2>

      <p>
        <?php esc_html_e('Deletes every setting this theme stored, right now. There is no undo.', 'sfxtheme'); ?>
      </p>

      <p>
        <strong><?php esc_html_e('Deleted:', 'sfxtheme'); ?></strong>
        <?php
        printf(
          /* translators: %d: number of stored settings found on this site */
          esc_html__('all theme settings (%d found on this site) and the theme\'s cached data, on this site only.', 'sfxtheme'),
          (int) $present
        );
        ?>
        <br>
        <strong><?php esc_html_e('Not deleted:', 'sfxtheme'); ?></strong>
        <?php esc_html_e('your content. Contact infos, social accounts, custom scripts, posts, pages and media files all stay exactly as they are — and so do the copyright notices on your media, unless you tick the box below.', 'sfxtheme'); ?>
      </p>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="sfx_purge_theme_data">
        <?php wp_nonce_field('sfx_purge_theme_data'); ?>

        <p>
          <label for="sfx-purge-confirmation">
            <?php
            printf(
              /* translators: %s: the exact phrase the user must type */
              esc_html__('Type %s to enable the button:', 'sfxtheme'),
              '<code>' . esc_html(\SFX\DataPurge::CONFIRMATION_PHRASE) . '</code>'
            );
            ?>
          </label>
          <br>
          <input type="text" id="sfx-purge-confirmation" name="sfx_purge_confirmation"
                 class="regular-text" autocomplete="off" spellcheck="false"
                 data-expected="<?php echo esc_attr(\SFX\DataPurge::CONFIRMATION_PHRASE); ?>">
        </p>

        <p>
          <label for="sfx-purge-media-credits">
            <input type="checkbox" id="sfx-purge-media-credits" name="sfx_purge_media_credits" value="1">
            <?php esc_html_e('Also delete the copyright and AI markings stored on my media files. These were typed by an editor and may matter legally — they are kept unless you tick this.', 'sfxtheme'); ?>
          </label>
        </p>

        <p>
          <button type="submit" id="sfx-purge-submit" class="button button-secondary sfx-danger-zone__button">
            <?php esc_html_e('Delete all theme data now', 'sfxtheme'); ?>
          </button>
        </p>
      </form>
    </div>

    <script>
      (function () {
        var field = document.getElementById('sfx-purge-confirmation');
        var button = document.getElementById('sfx-purge-submit');

        if (!field || !button) {
          return;
        }

        // Disabled from here, not from the markup: with JavaScript off the
        // button has to stay usable, and the server-side check on the typed
        // phrase is what actually guards the deletion either way.
        button.disabled = true;

        field.addEventListener('input', function () {
          button.disabled = field.value.trim() !== field.dataset.expected;
        });
      }());
    </script>
    <?php
  }

  /**
   * Render settings sections with visual grouping.
   */
  private static function render_sections(): void
  {
    global $wp_settings_sections, $wp_settings_fields;
    
    $page = Settings::$OPTION_GROUP;
    
    if (!isset($wp_settings_sections[$page])) {
      return;
    }

    foreach ((array) $wp_settings_sections[$page] as $section) {
      echo '<div class="sfx-settings-section">';
      
      if ($section['title']) {
        echo '<h2>' . esc_html($section['title']) . '</h2>';
      }

      if ($section['callback']) {
        echo '<p class="description">';
        call_user_func($section['callback'], $section);
        echo '</p>';
      }

      if (!isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section['id']])) {
        echo '</div>';
        continue;
      }

      echo '<table class="form-table" role="presentation">';
      do_settings_fields($page, $section['id']);
      echo '</table>';
      echo '</div>';
    }
  }
}
