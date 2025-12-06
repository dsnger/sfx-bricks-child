<?php

declare(strict_types=1);

namespace SFX\ImportExport;

use SFX\AccessControl;
use SFX\SFXBricksChildAdmin;

/**
 * Admin Page for Import/Export feature.
 * 
 * Handles the admin interface for exporting and importing theme settings.
 * 
 * @package SFX\ImportExport
 */
class AdminPage
{
    public static string $menu_slug = 'sfx-import-export';
    public static string $page_title = 'Import/Export';
    public static string $description = 'Export and import theme settings and custom post type data.';

    /**
     * Register admin page hooks.
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu_page']);
    }

    /**
     * Add submenu page under Global Theme Settings.
     */
    public static function add_menu_page(): void
    {
        // Only register menu if user has theme settings access
        if (!AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            SFXBricksChildAdmin::$menu_slug,
            __('Import/Export Settings', 'sfxtheme'),
            __('Import/Export', 'sfxtheme'),
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    /**
     * Render the admin page.
     */
    public static function render_page(): void
    {
        // Block direct URL access for unauthorized users
        AccessControl::die_if_unauthorized_theme();

        ?>
        <div class="wrap sfx-import-export-wrap">
            <h1 class="sfx-title"><?php echo esc_html__('Import/Export Settings', 'sfxtheme'); ?></h1>
            <p class="sfx-description">
                <?php echo esc_html__('Export your theme settings and custom post type data to a JSON file, or import settings from a previously exported file.', 'sfxtheme'); ?>
            </p>

            <div class="sfx-flex sfx-import-export-container">
                <!-- Export Section -->
                <div class="sfx-card sfx-export-section" style="flex: 1;">
                    <h2 class="sfx-section-title"><?php echo esc_html__('Export Settings', 'sfxtheme'); ?></h2>
                    <p class="sfx-description">
                        <?php echo esc_html__('Select which settings and data you want to export.', 'sfxtheme'); ?>
                    </p>

                    <form id="sfx-export-form" method="post">
                        <?php wp_nonce_field('sfx_export_settings_nonce', 'sfx_export_nonce'); ?>
                        
                        <div class="sfx-selection-controls">
                            <button type="button" class="button sfx-select-all"><?php echo esc_html__('Select All', 'sfxtheme'); ?></button>
                            <button type="button" class="button sfx-deselect-all"><?php echo esc_html__('Deselect All', 'sfxtheme'); ?></button>
                        </div>

                        <!-- Settings Group -->
                        <div class="sfx-export-group">
                            <h3 class="sfx-section-title"><?php echo esc_html__('Theme Settings', 'sfxtheme'); ?></h3>
                            <div class="sfx-checkbox-grid">
                                <?php self::render_settings_checkboxes('export'); ?>
                            </div>
                        </div>

                        <!-- Post Types Group -->
                        <div class="sfx-export-group">
                            <h3 class="sfx-section-title"><?php echo esc_html__('Custom Post Types', 'sfxtheme'); ?></h3>
                            <div class="sfx-checkbox-grid">
                                <?php self::render_post_type_checkboxes('export'); ?>
                            </div>
                        </div>

                        <div class="sfx-notice sfx-notice-info">
                            <p>
                                <strong><?php echo esc_html__('Note:', 'sfxtheme'); ?></strong>
                                <?php echo esc_html__('The Image Optimizer excluded images list is not exported as attachment IDs are site-specific.', 'sfxtheme'); ?>
                            </p>
                            <p>
                                <?php echo esc_html__('URLs (images, logos, links) are exported as-is. When importing to a different site, you may need to manually update these URLs.', 'sfxtheme'); ?>
                            </p>
                        </div>

                        <p class="submit">
                            <button type="submit" class="button button-primary sfx-export-btn">
                                <span class="dashicons dashicons-download"></span>
                                <?php echo esc_html__('Export Selected', 'sfxtheme'); ?>
                            </button>
                            <span class="spinner"></span>
                        </p>
                    </form>
                </div>

                <!-- Import Section -->
                <div class="sfx-card sfx-import-section" style="flex: 1;">
                    <h2 class="sfx-section-title"><?php echo esc_html__('Import Settings', 'sfxtheme'); ?></h2>
                    <p class="sfx-description">
                        <?php echo esc_html__('Upload a previously exported JSON file to import settings.', 'sfxtheme'); ?>
                    </p>

                    <form id="sfx-import-form" method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('sfx_import_settings_nonce', 'sfx_import_nonce'); ?>
                        
                        <div class="sfx-file-upload">
                            <label for="sfx-import-file"><?php echo esc_html__('Select JSON File:', 'sfxtheme'); ?></label>
                            <input type="file" 
                                   id="sfx-import-file" 
                                   name="import_file" 
                                   accept=".json,application/json" 
                                   required />
                            <p class="sfx-description"><?php echo esc_html__('Maximum file size: 2MB', 'sfxtheme'); ?></p>
                        </div>

                        <!-- Preview Section (hidden until file is loaded) -->
                        <div id="sfx-import-preview" class="sfx-import-preview" style="display: none;">
                            <h3 class="sfx-section-title"><?php echo esc_html__('Preview Import Data', 'sfxtheme'); ?></h3>
                            <div class="sfx-preview-meta">
                                <span class="sfx-preview-version"></span>
                                <span class="sfx-preview-date"></span>
                                <span class="sfx-preview-user"></span>
                            </div>
                            
                            <div class="sfx-selection-controls">
                                <button type="button" class="button sfx-select-all"><?php echo esc_html__('Select All', 'sfxtheme'); ?></button>
                                <button type="button" class="button sfx-deselect-all"><?php echo esc_html__('Deselect All', 'sfxtheme'); ?></button>
                            </div>

                            <!-- Settings to Import -->
                            <div class="sfx-import-group" id="sfx-import-settings-group" style="display: none;">
                                <h4 class="sfx-section-title"><?php echo esc_html__('Theme Settings', 'sfxtheme'); ?></h4>
                                <div class="sfx-checkbox-grid" id="sfx-import-settings-checkboxes"></div>
                            </div>

                            <!-- Post Types to Import -->
                            <div class="sfx-import-group" id="sfx-import-posttypes-group" style="display: none;">
                                <h4 class="sfx-section-title"><?php echo esc_html__('Custom Post Types', 'sfxtheme'); ?></h4>
                                <div class="sfx-checkbox-grid" id="sfx-import-posttypes-checkboxes"></div>
                            </div>
                        </div>

                        <!-- Import Mode -->
                        <div class="sfx-import-mode" id="sfx-import-mode-section" style="display: none;">
                            <h3 class="sfx-section-title"><?php echo esc_html__('Import Mode', 'sfxtheme'); ?></h3>
                            <label class="sfx-radio-label">
                                <input type="radio" name="import_mode" value="merge" checked />
                                <span>
                                    <strong><?php echo esc_html__('Merge', 'sfxtheme'); ?></strong>
                                    <span class="sfx-description"><?php echo esc_html__('Keep existing data and add new items from the import file.', 'sfxtheme'); ?></span>
                                </span>
                            </label>
                            <label class="sfx-radio-label">
                                <input type="radio" name="import_mode" value="replace" />
                                <span>
                                    <strong><?php echo esc_html__('Replace', 'sfxtheme'); ?></strong>
                                    <span class="sfx-description"><?php echo esc_html__('Delete existing data for selected items and replace with imported data.', 'sfxtheme'); ?></span>
                                </span>
                            </label>
                        </div>

                        <div class="sfx-notice sfx-notice-warning" id="sfx-import-warning" style="display: none;">
                            <p>
                                <strong><?php echo esc_html__('Warning:', 'sfxtheme'); ?></strong>
                                <?php echo esc_html__('Importing data will modify your site. Make sure you have a backup before proceeding.', 'sfxtheme'); ?>
                            </p>
                            <p>
                                <?php echo esc_html__('URLs from the export file (images, logos) will be imported as-is. If they point to a different domain, you may need to update them manually after import.', 'sfxtheme'); ?>
                            </p>
                        </div>

                        <p class="submit">
                            <button type="submit" class="button button-primary sfx-import-btn" disabled>
                                <span class="dashicons dashicons-upload"></span>
                                <?php echo esc_html__('Import Selected', 'sfxtheme'); ?>
                            </button>
                            <span class="spinner"></span>
                        </p>
                    </form>
                </div>
            </div>

            <!-- Results Section -->
            <div id="sfx-results" class="sfx-card sfx-results" style="display: none;">
                <div class="sfx-results-content"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings checkboxes.
     * 
     * @param string $context 'export' or 'import'
     */
    private static function render_settings_checkboxes(string $context): void
    {
        $settings_groups = Controller::get_settings_groups();
        $prefix = $context === 'export' ? 'export' : 'import';

        foreach ($settings_groups as $key => $group) {
            $id = "{$prefix}_settings_{$key}";
            ?>
            <label class="sfx-checkbox-label">
                <input type="checkbox" 
                       name="<?php echo esc_attr($prefix); ?>_settings[]" 
                       value="<?php echo esc_attr($key); ?>" 
                       id="<?php echo esc_attr($id); ?>"
                       checked />
                <span class="sfx-checkbox-text">
                    <strong><?php echo esc_html($group['label']); ?></strong>
                    <?php if (!empty($group['description'])): ?>
                        <span class="description"><?php echo esc_html($group['description']); ?></span>
                    <?php endif; ?>
                </span>
            </label>
            <?php
        }
    }

    /**
     * Render post type checkboxes.
     * 
     * @param string $context 'export' or 'import'
     */
    private static function render_post_type_checkboxes(string $context): void
    {
        $post_types = Controller::get_exportable_post_types();
        $prefix = $context === 'export' ? 'export' : 'import';

        foreach ($post_types as $post_type => $label) {
            $id = "{$prefix}_posttype_{$post_type}";
            $count = wp_count_posts($post_type);
            $total = isset($count->publish) ? (int) $count->publish : 0;
            $total += isset($count->draft) ? (int) $count->draft : 0;
            $total += isset($count->private) ? (int) $count->private : 0;
            ?>
            <label class="sfx-checkbox-label">
                <input type="checkbox" 
                       name="<?php echo esc_attr($prefix); ?>_posttypes[]" 
                       value="<?php echo esc_attr($post_type); ?>" 
                       id="<?php echo esc_attr($id); ?>"
                       checked />
                <span class="sfx-checkbox-text">
                    <strong><?php echo esc_html($label); ?></strong>
                    <span class="description">
                        <?php 
                        /* translators: %d: number of posts */
                        printf(esc_html__('%d items', 'sfxtheme'), $total); 
                        ?>
                    </span>
                </span>
            </label>
            <?php
        }
    }
}

