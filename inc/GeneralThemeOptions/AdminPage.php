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
    </div>
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
