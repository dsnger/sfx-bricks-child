<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

class AdminPage
{

    public static $menu_slug = 'sfx-image-optimizer';
    public static $page_title = 'Image Optimizer';
    public static $description = 'Optimize your images by converting them to modern formats (WebP, AVIF) and resizing them for improved page load speed and better Core Web Vitals scores.';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
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
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        // Block direct URL access for unauthorized users
        \SFX\AccessControl::die_if_unauthorized_theme();

        AssetManager::enqueue_admin_assets('sfx-theme-settings_page_sfx-image-optimizer');
        ?>
        <div class="wrap" style="padding: 0; font-size: 14px;">
            <div style="display: flex; gap: 10px; align-items: flex-start;">
                <div style="width: 45%; display: flex; flex-direction: column; gap: 10px;">
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h1 style="font-size: 20px; font-weight: bold; color: #333; margin: -5px 0 15px 0;">
                            <?php _e('Image Optimizer - Size and Format Optimizer', 'wpturbo'); ?>
                        </h1>
                        <div style="margin-bottom: 20px;">
                            <label for="resize-mode" style="font-weight: bold;">Resize Mode:</label><br>
                            <select id="resize-mode" style="width: 100px; margin-right: 10px; padding: 0px 0px 0px 5px;">
                                <option value="width" <?php echo Settings::get_resize_mode() === 'width' ? 'selected' : ''; ?>>Width</option>
                                <option value="height" <?php echo Settings::get_resize_mode() === 'height' ? 'selected' : ''; ?>>Height</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-width-input" style="font-weight: bold;">Max Widths (up to 4, e.g., 1920, 1200, 600, 300) - 150 is set automatically:</label><br>
                            <input type="text" id="max-width-input" value="<?php echo esc_attr(implode(', ', Settings::get_max_widths())); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1920,1200,600,300">
                            <button id="set-max-width" class="button">Set Widths</button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-height-input" style="font-weight: bold;">Max Heights (up to 4, e.g., 1080, 720, 480, 360) - 150 is set automatically:</label><br>
                            <input type="text" id="max-height-input" value="<?php echo esc_attr(implode(', ', Settings::get_max_heights())); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1080,720,480,360">
                            <button id="set-max-height" class="button">Set Heights</button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="min-size-kb" style="font-weight: bold;">Min Size for Conversion (KB, Set to 0 to disable):</label><br>
                            <input type="number" id="min-size-kb" value="<?php echo esc_attr(Settings::get_min_size_kb()); ?>" min="0" style="width: 50px; margin-right: 10px; padding: 5px;" placeholder="0">
                            <button id="set-min-size-kb" class="button">Set Min Size</button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="use-avif" <?php echo Settings::get_use_avif() ? 'checked' : ''; ?>> Set to AVIF Conversion (not WebP)</label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="preserve-originals" <?php echo Settings::get_preserve_originals() ? 'checked' : ''; ?>> Preserve Original Files</label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="disable-auto-conversion" <?php echo Settings::get_disable_auto_conversion() ? 'checked' : ''; ?>> Disable Auto-Conversion on Upload</label>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="start-conversion" class="button"><?php _e('1. Convert/Scale', 'wpturbo'); ?></button>
                            <button id="cleanup-originals" class="button"><?php _e('2. Cleanup Images', 'wpturbo'); ?></button>
                            <button id="convert-post-images" class="button"><?php _e('3. Fix URLs', 'wpturbo'); ?></button>
                            <button id="run-all" class="button button-primary"><?php _e('Run All (1-3)', 'wpturbo'); ?></button>
                            <button id="stop-conversion" class="button" style="display: none;"><?php _e('Stop', 'wpturbo'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="clear-log" class="button"><?php _e('Clear Log', 'wpturbo'); ?></button>
                            <button id="reset-defaults" class="button"><?php _e('Reset Defaults', 'wpturbo'); ?></button>
                            <button id="export-media-zip" class="button"><?php _e('Export Media as ZIP', 'wpturbo'); ?></button>
                            <button id="optimized-cleanup" class="button button-secondary" title="<?php _e('Memory-optimized file cleanup (better for large sites)', 'wpturbo'); ?>"><?php _e('Optimized Cleanup', 'wpturbo'); ?></button>
                        </div>
                    </div>
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="font-size: 16px; margin: 0 0 15px 0;">Exclude Images</h2>
                        <button id="open-media-library" class="button" style="margin-bottom: 20px;">Add from Media Library</button>
                        <div id="excluded-images">
                            <h3 style="font-size: 14px; margin: 0 0 10px 0;">Excluded Images</h3>
                            <ul id="excluded-images-list" style="list-style: none; padding: 0; max-height: 300px; overflow-y: auto;"></ul>
                        </div>
                    </div>
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="font-size: 16px; margin: 0 0 15px 0;">How It Works</h2>
                        <p style="line-height: 1.5;">
                            Refine images to WebP or AVIF, and remove excess files to save space.<br><br>
                            <b>Set Auto-Conversion for New Uploads:</b><br>
                            <b>1. Resize Mode:</b> Pick if images shrink by width or height.<br>
                            <b>2. Set Max Sizes:</b> Choose up to 4 sizes (150x150 thumbnail is automatic).<br>
                            <b>3. Min Size for Conversion:</b> Sizes below the min are not affected. Default is 0.<br>
                            <b>4. Conversion Format:</b> Check to use AVIF. WebP is default.<br>
                            <b>5. Preserve Originals:</b> Check to stop original files from converting/deleting.<br>
                            <b>6. Disable Auto-Conversion:</b> Images will convert on upload unless this is ticked.<br>
                            <b>7. Upload:</b> Upload to Media Library or via elements/widgets.<br><br>
                            <b>Apply for Existing Images:</b><br>
                            <b>1. Repeat:</b> Set up steps 1-6 above.<br>
                            <b>2. Run All:</b> Hit "Run All" to do everything at once.<br><br>
                            <b>Apply Manually for Existing Images:</b><br>
                            <b>1. Repeat:</b> Set up steps 1-6 above.<br>
                            <b>2. Convert:</b> Change image sizes and format.<br>
                            <b>3. Cleanup:</b> Delete old formats/sizes (if not preserved).<br>
                            <b>4. Fix Links:</b> Update image links to the new format.<br><br>
                            <b>IMPORTANT:</b><br>
                            <b>a) Usability:</b> This tool is ideal for New Sites. Using with Legacy Sites must be done with care as variation due to methods, systems, sizes, can affect the outcome. Please use this tool carefully and at your own risk, as I cannot be held responsible for any issues that may arise from its use.<br>
                            <b>b) Backups:</b> Use a strong backup tool like All-in-One WP Migration before using this tool. Check if your host saves backups - as some charge a fee to restore.<br>
                            <b>c) Export Media:</b> Export images as a Zipped Folder prior to running.<br>
                            <b>d) Reset Defaults:</b> Resets all Settings 1-6.<br>
                            <b>e) Speed:</b> Bigger sites take longer to run. This depends on your server.<br>
                            <b>f) Log Wait:</b> Updates show every 50 images.<br>
                            <b>g) Stop Anytime:</b> Click "Stop" to pause.<br>
                            <b>h) AVIF Needs:</b> Your server must support AVIF. Check logs if it fails.<br>
                            <b>i) Old Browsers:</b> AVIF might not work on older browsers, WebP is safer.<br>
                            <b>j) MIME Types:</b> Server must support WebP/AVIF MIME (check with host).<br>
                            <b>k) Rollback:</b> If conversion fails, then rollback occurs, and prevents deletion of the original, regardless of whether the Preserve Originals is checked or not.
                        </p>
                    </div>
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 10px;">
                        
                        <div style="font-size: 13px; color: #888; text-align: left;">
                            The code for this feature is based and inspired by <a href="https://learn.websquadron.co.uk/codes/#ImageOptimizer" target="_blank" rel="noopener" style="color: #0073aa; text-decoration: underline;">Imran Siddiq / Web Squadron</a>
                        </div>
                        <div style="margin-top: 10px; display: flex; justify-content: flex-start;">
                            <a href="https://www.paypal.com/paypalme/iamimransiddiq" target="_blank" class="button" style="border: none;" rel="noopener">Support Imran</a>
                        </div>
                    </div>
                </div>
                <div style="width: 55%; min-height: 100vh; background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column;">
                    <h3 style="font-size: 16px; margin: 0 0 10px 0;">
                        <?php _e('Log (Last 500 Entries)', 'wpturbo'); ?>
                    </h3>
                    <pre id="log" style="background: #f9f9f9; padding: 15px; flex: 1; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; font-size: 13px;"></pre>
                </div>
            </div>
        </div>
        <!-- The full <script> block from the original UI should be included here for full interactivity -->
        <?php
    }
} 