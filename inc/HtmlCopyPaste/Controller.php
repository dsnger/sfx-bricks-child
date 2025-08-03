<?php

declare(strict_types=1);

namespace SFX\HtmlCopyPaste;

class Controller
{
    public const OPTION_NAME = 'sfx_html_copy_paste_options';
    public const FEATURE_KEY = 'html_copy_paste';

    public function __construct()
    {
        // Initialize components
        AdminPage::register();
        AssetManager::register();
        Settings::init();

        // Register hooks through consolidated system
        add_action('sfx_init_advanced_features', [$this, 'init_html_copy_paste']);
    }

    /**
     * Initialize HTML Copy/Paste functionality
     */
    public function init_html_copy_paste(): void
    {
        // Check if feature is enabled
        if (!$this->is_feature_enabled()) {
            return;
        }

        // Only load in Bricks Builder context
        if (!function_exists('bricks_is_builder') || !bricks_is_builder()) {
            return;
        }

        // Add HTML paste container to footer
        add_action('wp_footer', [$this, 'add_html_paste_container']);
        
        // Add context menu items
        add_action('wp_footer', [$this, 'add_context_menu_items']);
    }

    /**
     * Add HTML paste container to footer
     */
    public function add_html_paste_container(): void
    {
        ?>
        <div id="sfx-html-paste-editor" style="display: none;">
            <!-- Full screen overlay -->
            <div style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.8); z-index: 99999; display: flex; align-items: center; justify-content: center;">
                                    <!-- Modal content -->
                    <div style="background: #1f2937; border-radius: 8px; width: 90%; max-width: 700px; max-height: 80vh; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.5);">
                        
                        <!-- Header -->
                        <div style="padding: 20px; border-bottom: 1px solid #374151; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; color: #f9fafb; font-size: 18px; font-weight: 600;">Paste HTML Code</h3>
                            <div id="sfx-close-editor" style="cursor: pointer; font-size: 24px; color: #9ca3af; line-height: 1;">&times;</div>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 20px;">
                            <div id="sfx-msg" style="color: #d1d5db; margin-bottom: 15px; font-size: 14px; text-align: center; display: none;">
                                <?php esc_html_e('We have no permission for using clipboard data. Only Chrome supports fast pasting. If you are using Chrome, then give access to not show this window again. Paste text from your clipboard to input.', 'sfx-bricks-child'); ?>
                            </div>
                            
                            <div id="sfx-fast-paste" style="display: block;">
                                <label style="display: block; margin-bottom: 8px; color: #f9fafb; font-weight: 500; font-size: 14px;">HTML Code:</label>
                                <textarea id="sfx-textarea-paste" 
                                          placeholder="Paste your HTML code here..."
                                          style="width: 100%; height: 300px; border: 1px solid #4b5563; border-radius: 4px; padding: 12px; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 13px; line-height: 1.4; resize: vertical; background: #111827; color: #f9fafb;"
                                ></textarea>
                                <div style="color: #9ca3af; font-size: 12px; margin-top: 5px;">
                                    Tip: You can paste HTML directly with Ctrl+V (or Cmd+V on Mac)
                                </div>
                            </div>
                            
                            <div id="sfx-monaco-editor-container" style="display: none; height: 300px; border: 1px solid #4b5563; border-radius: 4px; margin-top: 10px;"></div>
                        </div>
                        
                        <!-- Footer -->
                        <div style="padding: 20px; border-top: 1px solid #374151; display: flex; justify-content: flex-end; gap: 10px;">
                            <button type="button" onclick="document.getElementById('sfx-html-paste-editor').style.display='none'" 
                                    style="padding: 8px 16px; border: 1px solid #4b5563; background: #374151; color: #d1d5db; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                Cancel
                            </button>
                            <button type="button" id="sfx-paste-from-editor" 
                                    style="padding: 8px 16px; border: none; background: #3b82f6; color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">
                                Insert HTML
                            </button>
                        </div>
                    
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add context menu items for HTML copy/paste
     */
    public function add_context_menu_items(): void
    {
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // Add context menu items if they don't exist
            const contextMenu = document.getElementById('bricks-builder-context-menu');
            if (contextMenu && !document.getElementById('sfx-paste-html')) {
                const menuItems = contextMenu.querySelector('.menu-items');
                if (menuItems) {
                    menuItems.insertAdjacentHTML('beforeend', `
                        <li id="sfx-paste-html" data-key="sfx_paste_html">
                            <span class="label"><?php esc_html_e('Paste HTML', 'sfx-bricks-child'); ?></span>
                        </li>
                        <li id="sfx-paste-html-editor" data-key="sfx_paste_html_editor">
                            <span class="label"><?php esc_html_e('Paste HTML with Editor', 'sfx-bricks-child'); ?></span>
                        </li>
                    `);
                }
            }
        });
        </script>
        <?php
    }

    /**
     * Check if feature is enabled
     */
    private function is_feature_enabled(): bool
    {
        $options = get_option(self::OPTION_NAME, $this->get_default_options());
        return !empty($options['enable_html_copy_paste']);
    }
    
    /**
     * Get default options
     */
    private function get_default_options(): array
    {
        return [
            'enable_html_copy_paste' => '0',
            'enable_editor_mode' => '0',
            'preserve_custom_attributes' => '0',
            'auto_convert_images' => '0',
            'auto_convert_links' => '0',
        ];
    }

    /**
     * Get feature configuration
     */
    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'show_in_theme_settings' => true,
            'error' => 'Missing HtmlCopyPasteController class in theme',
            'hook' => null,
        ];
    }
} 