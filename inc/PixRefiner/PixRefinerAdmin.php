<?php
namespace SFX\PixRefiner;

class PixRefinerAdmin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_pixrefiner_batch_convert', [$this, 'ajax_batch_convert']);
        add_action('wp_ajax_pixrefiner_get_log', [$this, 'ajax_get_log']);
    }

    public function add_admin_page() {
        add_submenu_page(
            \SFX\Options\AdminOptionPages::$menu_slug,
            __('PixRefiner Tools', 'sfxtheme'),
            __('PixRefiner Tools', 'sfxtheme'),
            'manage_options',
            'pixrefiner-tools',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings() {
        register_setting('pixrefiner_options', 'webp_max_widths');
        register_setting('pixrefiner_options', 'webp_max_heights');
        register_setting('pixrefiner_options', 'webp_resize_mode');
        register_setting('pixrefiner_options', 'webp_quality');
        register_setting('pixrefiner_options', 'webp_batch_size');
        register_setting('pixrefiner_options', 'webp_preserve_originals');
        register_setting('pixrefiner_options', 'webp_disable_auto_conversion');
        register_setting('pixrefiner_options', 'webp_min_size_kb');
        register_setting('pixrefiner_options', 'webp_use_avif');
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'pixrefiner-tools') === false) return;
        wp_enqueue_script('pixrefiner-admin', get_stylesheet_directory_uri() . '/assets/js/pixrefiner-admin.js', ['jquery'], null, true);
        wp_localize_script('pixrefiner-admin', 'PixRefinerAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('pixrefiner_nonce'),
        ]);
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('PixRefiner Tools', 'sfxtheme'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('pixrefiner_options');
                do_settings_sections('pixrefiner_options');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Max Widths', 'sfxtheme'); ?></th>
                        <td><input type="text" name="webp_max_widths" value="<?php echo esc_attr(get_option('webp_max_widths', '1920,1200,600,300')); ?>" class="regular-text" /> <small>e.g. 1920,1200,600,300</small></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Max Heights', 'sfxtheme'); ?></th>
                        <td><input type="text" name="webp_max_heights" value="<?php echo esc_attr(get_option('webp_max_heights', '1080,720,480,360')); ?>" class="regular-text" /> <small>e.g. 1080,720,480,360</small></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Resize Mode', 'sfxtheme'); ?></th>
                        <td>
                            <select name="webp_resize_mode">
                                <option value="width" <?php selected(get_option('webp_resize_mode', 'width'), 'width'); ?>><?php _e('Width', 'sfxtheme'); ?></option>
                                <option value="height" <?php selected(get_option('webp_resize_mode', 'width'), 'height'); ?>><?php _e('Height', 'sfxtheme'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Quality', 'sfxtheme'); ?></th>
                        <td><input type="number" name="webp_quality" value="<?php echo esc_attr(get_option('webp_quality', 80)); ?>" min="0" max="100" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Batch Size', 'sfxtheme'); ?></th>
                        <td><input type="number" name="webp_batch_size" value="<?php echo esc_attr(get_option('webp_batch_size', 5)); ?>" min="1" max="100" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Preserve Originals', 'sfxtheme'); ?></th>
                        <td><input type="checkbox" name="webp_preserve_originals" value="1" <?php checked(get_option('webp_preserve_originals', false), true); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Disable Auto Conversion', 'sfxtheme'); ?></th>
                        <td><input type="checkbox" name="webp_disable_auto_conversion" value="1" <?php checked(get_option('webp_disable_auto_conversion', false), true); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Min Size (KB)', 'sfxtheme'); ?></th>
                        <td><input type="number" name="webp_min_size_kb" value="<?php echo esc_attr(get_option('webp_min_size_kb', 0)); ?>" min="0" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Use AVIF', 'sfxtheme'); ?></th>
                        <td><input type="checkbox" name="webp_use_avif" value="1" <?php checked(get_option('webp_use_avif', false), true); ?> /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr />
            <button id="pixrefiner-batch-convert" class="button button-primary"><?php _e('Batch Convert Images', 'sfxtheme'); ?></button>
            <pre id="pixrefiner-log" style="margin-top:2em; background:#f7f7f7; padding:1em; max-height:300px; overflow:auto;"></pre>
        </div>
        <?php
    }

    public function ajax_batch_convert() {
        check_ajax_referer('pixrefiner_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'sfxtheme')]);
        }
        // Dummy batch conversion logic (replace with real logic)
        $log = get_option('pixrefiner_conversion_log', []);
        $log[] = date('Y-m-d H:i:s') . ': Batch conversion started.';
        update_option('pixrefiner_conversion_log', array_slice((array)$log, -500));
        wp_send_json_success(['message' => __('Batch conversion started.', 'sfxtheme')]);
    }

    public function ajax_get_log() {
        check_ajax_referer('pixrefiner_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'sfxtheme')]);
        }
        $log = get_option('pixrefiner_conversion_log', []);
        wp_send_json_success(['log' => $log]);
    }
} 