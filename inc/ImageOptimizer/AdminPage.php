<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

class AdminPage
{

    public static $menu_slug = 'sfx-image-optimizer';
    public static $page_title = 'Image Optimizer';
    public static $description = 'Optimize your images by converting them to modern formats (WebP, AVIF) and resizing them for improved page load speed and better Core Web Vitals scores.';

    public static function get_page_title(): string
    {
        return __('Image Optimizer', 'sfxtheme');
    }

    public static function get_description(): string
    {
        return __('Optimize your images by converting them to modern formats (WebP, AVIF) and resizing them for improved page load speed and better Core Web Vitals scores.', 'sfxtheme');
    }

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
            self::get_page_title(),
            self::get_page_title(),
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
                            <?php _e('Image Optimizer - Size and Format Optimizer', 'sfxtheme'); ?>
                        </h1>
                        <div style="margin-bottom: 20px;">
                            <label for="resize-mode" style="font-weight: bold;"><?php esc_html_e('Resize Mode:', 'sfxtheme'); ?></label><br>
                            <select id="resize-mode" style="width: 100px; margin-right: 10px; padding: 0px 0px 0px 5px;">
                                <option value="width" <?php echo Settings::get_resize_mode() === 'width' ? 'selected' : ''; ?>><?php esc_html_e('Width', 'sfxtheme'); ?></option>
                                <option value="height" <?php echo Settings::get_resize_mode() === 'height' ? 'selected' : ''; ?>><?php esc_html_e('Height', 'sfxtheme'); ?></option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-width-input" style="font-weight: bold;"><?php esc_html_e('Max Widths (up to 4, e.g., 1920, 1200, 600, 300) - 150 is set automatically:', 'sfxtheme'); ?></label><br>
                            <input type="text" id="max-width-input" value="<?php echo esc_attr(implode(', ', Settings::get_max_widths())); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1920,1200,600,300">
                            <button id="set-max-width" class="button"><?php esc_html_e('Set Widths', 'sfxtheme'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-height-input" style="font-weight: bold;"><?php esc_html_e('Max Heights (up to 4, e.g., 1080, 720, 480, 360) - 150 is set automatically:', 'sfxtheme'); ?></label><br>
                            <input type="text" id="max-height-input" value="<?php echo esc_attr(implode(', ', Settings::get_max_heights())); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1080,720,480,360">
                            <button id="set-max-height" class="button"><?php esc_html_e('Set Heights', 'sfxtheme'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="min-size-kb" style="font-weight: bold;"><?php esc_html_e('Min Size for Conversion (KB, Set to 0 to disable):', 'sfxtheme'); ?></label><br>
                            <input type="number" id="min-size-kb" value="<?php echo esc_attr(Settings::get_min_size_kb()); ?>" min="0" style="width: 50px; margin-right: 10px; padding: 5px;" placeholder="0">
                            <button id="set-min-size-kb" class="button"><?php esc_html_e('Set Min Size', 'sfxtheme'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="quality-input" style="font-weight: bold;"><?php esc_html_e('Quality (1-100):', 'sfxtheme'); ?></label><br>
                            <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                                <input type="range" id="quality-slider" min="1" max="100" value="<?php echo esc_attr(Settings::get_quality()); ?>" style="width: 150px; height: 6px; cursor: pointer; -webkit-appearance: auto; appearance: auto;">
                                <input type="number" id="quality-input" value="<?php echo esc_attr(Settings::get_quality()); ?>" min="1" max="100" style="width: 55px; padding: 5px; text-align: center;">
                                <button id="set-quality" class="button"><?php esc_html_e('Set Quality', 'sfxtheme'); ?></button>
                            </div>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="batch-size-input" style="font-weight: bold;"><?php esc_html_e('Batch Size (images per request):', 'sfxtheme'); ?></label><br>
                            <input type="number" id="batch-size-input" value="<?php echo esc_attr(Settings::get_batch_size()); ?>" min="1" max="50" style="width: 50px; margin-right: 10px; padding: 5px;">
                            <button id="set-batch-size" class="button"><?php esc_html_e('Set Batch Size', 'sfxtheme'); ?></button>
                            <span style="color: #666; font-size: 12px; margin-left: 10px;"><?php esc_html_e('(Lower values for slower servers)', 'sfxtheme'); ?></span>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="use-avif" <?php echo Settings::get_use_avif() ? 'checked' : ''; ?>> <?php esc_html_e('Set to AVIF Conversion (not WebP)', 'sfxtheme'); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="preserve-originals" <?php echo Settings::get_preserve_originals() ? 'checked' : ''; ?>> <?php esc_html_e('Preserve Original Files', 'sfxtheme'); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="disable-auto-conversion" <?php echo Settings::get_disable_auto_conversion() ? 'checked' : ''; ?>> <?php esc_html_e('Disable Auto-Conversion on Upload', 'sfxtheme'); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="force-reconvert"> <?php esc_html_e('Force Re-convert (ignore optimization stamp)', 'sfxtheme'); ?></label>
                            <span style="color: #666; font-size: 12px; display: block; margin-top: 3px;"><?php esc_html_e('Use this to re-process images that were already optimized with different settings.', 'sfxtheme'); ?></span>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="start-conversion" class="button"><?php _e('1. Convert/Scale', 'sfxtheme'); ?></button>
                            <button id="cleanup-originals" class="button"><?php _e('2. Cleanup Images', 'sfxtheme'); ?></button>
                            <button id="convert-post-images" class="button"><?php _e('3. Fix URLs', 'sfxtheme'); ?></button>
                            <button id="run-all" class="button button-primary"><?php _e('Run All (1-3)', 'sfxtheme'); ?></button>
                            <button id="stop-conversion" class="button" style="display: none;"><?php _e('Stop', 'sfxtheme'); ?></button>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="clear-log" class="button"><?php _e('Clear Log', 'sfxtheme'); ?></button>
                            <button id="reset-defaults" class="button"><?php _e('Reset Defaults', 'sfxtheme'); ?></button>
                            <button id="export-media-zip" class="button"><?php _e('Export Media as ZIP', 'sfxtheme'); ?></button>
                            <button id="optimized-cleanup" class="button button-secondary" title="<?php _e('Memory-optimized file cleanup (better for large sites)', 'sfxtheme'); ?>"><?php _e('Optimized Cleanup', 'sfxtheme'); ?></button>
                        </div>
                    </div>
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="font-size: 16px; margin: 0 0 15px 0;"><?php esc_html_e('Exclude Images', 'sfxtheme'); ?></h2>
                        <p style="font-size: 13px; color: #666; margin-bottom: 15px;">
                            <?php esc_html_e('Exclude images from conversion. If already optimized, use "Revert" button to restore original format (requires Preserve Originals).', 'sfxtheme'); ?>
                        </p>
                        <button id="open-media-library" class="button" style="margin-bottom: 20px;"><?php esc_html_e('Add from Media Library', 'sfxtheme'); ?></button>
                        <div id="excluded-images">
                            <h3 style="font-size: 14px; margin: 0 0 10px 0;"><?php esc_html_e('Excluded Images', 'sfxtheme'); ?></h3>
                            <ul id="excluded-images-list" style="list-style: none; padding: 0; max-height: 300px; overflow-y: auto;"></ul>
                        </div>
                    </div>
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="font-size: 16px; margin: 0 0 15px 0;"><?php esc_html_e('How It Works', 'sfxtheme'); ?></h2>
                        <p style="line-height: 1.5;">
                            <?php esc_html_e('Refine images to WebP or AVIF, and remove excess files to save space.', 'sfxtheme'); ?><br><br>
                            <b><?php esc_html_e('Set Auto-Conversion for New Uploads:', 'sfxtheme'); ?></b><br>
                            <b>1. <?php esc_html_e('Resize Mode:', 'sfxtheme'); ?></b> <?php esc_html_e('Pick if images shrink by width or height.', 'sfxtheme'); ?><br>
                            <b>2. <?php esc_html_e('Set Max Sizes:', 'sfxtheme'); ?></b> <?php esc_html_e('Choose up to 4 sizes (150x150 thumbnail is automatic).', 'sfxtheme'); ?><br>
                            <b>3. <?php esc_html_e('Min Size for Conversion:', 'sfxtheme'); ?></b> <?php esc_html_e('Sizes below the min are not affected. Default is 0.', 'sfxtheme'); ?><br>
                            <b>4. <?php esc_html_e('Conversion Format:', 'sfxtheme'); ?></b> <?php esc_html_e('Check to use AVIF. WebP is default.', 'sfxtheme'); ?><br>
                            <b>5. <?php esc_html_e('Preserve Originals:', 'sfxtheme'); ?></b> <?php esc_html_e('Check to stop original files from converting/deleting.', 'sfxtheme'); ?><br>
                            <b>6. <?php esc_html_e('Disable Auto-Conversion:', 'sfxtheme'); ?></b> <?php esc_html_e('Images will convert on upload unless this is ticked.', 'sfxtheme'); ?><br>
                            <b>7. <?php esc_html_e('Upload:', 'sfxtheme'); ?></b> <?php esc_html_e('Upload to Media Library or via elements/widgets.', 'sfxtheme'); ?><br><br>
                            <b><?php esc_html_e('Apply for Existing Images:', 'sfxtheme'); ?></b><br>
                            <b>1. <?php esc_html_e('Repeat:', 'sfxtheme'); ?></b> <?php esc_html_e('Set up steps 1-6 above.', 'sfxtheme'); ?><br>
                            <b>2. <?php esc_html_e('Run All:', 'sfxtheme'); ?></b> <?php esc_html_e('Hit "Run All" to do everything at once.', 'sfxtheme'); ?><br><br>
                            <b><?php esc_html_e('Apply Manually for Existing Images:', 'sfxtheme'); ?></b><br>
                            <b>1. <?php esc_html_e('Repeat:', 'sfxtheme'); ?></b> <?php esc_html_e('Set up steps 1-6 above.', 'sfxtheme'); ?><br>
                            <b>2. <?php esc_html_e('Convert:', 'sfxtheme'); ?></b> <?php esc_html_e('Change image sizes and format.', 'sfxtheme'); ?><br>
                            <b>3. <?php esc_html_e('Cleanup:', 'sfxtheme'); ?></b> <?php esc_html_e('Delete old formats/sizes (if not preserved).', 'sfxtheme'); ?><br>
                            <b>4. <?php esc_html_e('Fix Links:', 'sfxtheme'); ?></b> <?php esc_html_e('Update image links to the new format.', 'sfxtheme'); ?><br><br>
                            <b><?php esc_html_e('IMPORTANT:', 'sfxtheme'); ?></b><br>
                            <b>a) <?php esc_html_e('Usability:', 'sfxtheme'); ?></b> <?php esc_html_e('This tool is ideal for New Sites. Using with Legacy Sites must be done with care as variation due to methods, systems, sizes, can affect the outcome. Please use this tool carefully and at your own risk, as I cannot be held responsible for any issues that may arise from its use.', 'sfxtheme'); ?><br>
                            <b>b) <?php esc_html_e('Backups:', 'sfxtheme'); ?></b> <?php esc_html_e('Use a strong backup tool like All-in-One WP Migration before using this tool. Check if your host saves backups - as some charge a fee to restore.', 'sfxtheme'); ?><br>
                            <b>c) <?php esc_html_e('Export Media:', 'sfxtheme'); ?></b> <?php esc_html_e('Export images as a Zipped Folder prior to running.', 'sfxtheme'); ?><br>
                            <b>d) <?php esc_html_e('Reset Defaults:', 'sfxtheme'); ?></b> <?php esc_html_e('Resets all Settings 1-6.', 'sfxtheme'); ?><br>
                            <b>e) <?php esc_html_e('Speed:', 'sfxtheme'); ?></b> <?php esc_html_e('Bigger sites take longer to run. This depends on your server.', 'sfxtheme'); ?><br>
                            <b>f) <?php esc_html_e('Log Wait:', 'sfxtheme'); ?></b> <?php esc_html_e('Updates show every 50 images.', 'sfxtheme'); ?><br>
                            <b>g) <?php esc_html_e('Stop Anytime:', 'sfxtheme'); ?></b> <?php esc_html_e('Click "Stop" to pause.', 'sfxtheme'); ?><br>
                            <b>h) <?php esc_html_e('AVIF Needs:', 'sfxtheme'); ?></b> <?php esc_html_e('Your server must support AVIF. Check logs if it fails.', 'sfxtheme'); ?><br>
                            <b>i) <?php esc_html_e('Old Browsers:', 'sfxtheme'); ?></b> <?php esc_html_e('AVIF might not work on older browsers, WebP is safer.', 'sfxtheme'); ?><br>
                            <b>j) <?php esc_html_e('MIME Types:', 'sfxtheme'); ?></b> <?php esc_html_e('Server must support WebP/AVIF MIME (check with host).', 'sfxtheme'); ?><br>
                            <b>k) <?php esc_html_e('Rollback:', 'sfxtheme'); ?></b> <?php esc_html_e('If conversion fails, then rollback occurs, and prevents deletion of the original, regardless of whether the Preserve Originals is checked or not.', 'sfxtheme'); ?>
                        </p>
                    </div>
                    <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 10px;">
                        
                        <div style="font-size: 13px; color: #888; text-align: left;">
                            <?php
                            printf(
                                esc_html__('The code for this feature is based and inspired by %s', 'sfxtheme'),
                                '<a href="https://learn.websquadron.co.uk/codes/#ImageOptimizer" target="_blank" rel="noopener" style="color: #0073aa; text-decoration: underline;">Imran Siddiq / Web Squadron</a>'
                            );
                            ?>
                        </div>
                        <div style="margin-top: 10px; display: flex; justify-content: flex-start;">
                            <a href="https://www.paypal.com/paypalme/iamimransiddiq" target="_blank" class="button" style="border: none;" rel="noopener"><?php esc_html_e('Support Imran', 'sfxtheme'); ?></a>
                        </div>
                    </div>
                </div>
                <div style="width: 55%; min-height: 100vh; background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column;">
                    <h3 style="font-size: 16px; margin: 0 0 10px 0;">
                        <?php _e('Log (Last 500 Entries)', 'sfxtheme'); ?>
                    </h3>
                    <pre id="log" style="background: #f9f9f9; padding: 15px; flex: 1; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; font-size: 13px;"></pre>
                </div>
            </div>
        </div>
        <!-- The full <script> block from the original UI should be included here for full interactivity -->
        <?php
    }
} 