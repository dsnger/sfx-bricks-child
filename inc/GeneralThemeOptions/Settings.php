<?php

namespace SFX\GeneralThemeOptions;

class Settings
{
    public static $OPTION_GROUP;
    public static $OPTION_NAME;

    /**
     * Register all settings for security headers.
     */
    public static function register(): void
    {
        // Initialize static properties
        self::$OPTION_GROUP = 'sfx_general_options';
        self::$OPTION_NAME = 'sfx_general_options';
        
        // Register settings through consolidated system
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    public static function get_fields(): array {
        return [
            [
                'id'          => 'enable_wp_optimizer',
                'label'       => __('Enable WP Optimizer', 'sfxtheme'),
                'description' => __('Enable WP Optimizer to manage some WordPress features for better performance and security.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_image_optimizer',
                'label'       => __('Enable Image Optimizer', 'sfxtheme'),
                'description' => __('Enable Image Optimizer to optimize images for better performance.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_security_header',
                'label'       => __('Enable Security Header', 'sfxtheme'),
                'description' => __('Enable Security Header to protect your website against common security threats.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'group'       => 'general',
            ],
            [
                'id'          => 'disable_bricks_js',
                'label'       => __('Disable Bricks JS', 'sfxtheme'),
                'description' => __('Remove the default Bricks JavaScript from the frontend for enhanced performance and custom JS solutions.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'disable_bricks_styles',
                'label'       => __('Disable Bricks Styling', 'sfxtheme'),
                'description' => __('Remove all default Bricks styling.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'delete_on_uninstall',
                'label'       => __('Delete Data on Uninstall', 'sfxtheme'),
                'description' => __('Delete all theme settings and data when the theme is deleted. This does not affect the theme when it is just deactivated.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            
        ];
    }

    /**
     * Get style module fields.
     */
    public static function get_style_fields(): array {
        return [
            [
                'id'          => 'enable_style_content_grid',
                'label'       => __('Content Grid', 'sfxtheme'),
                'description' => __('Responsive content grid classes (.content-grid, .content--full, .content--feature, etc.)', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'content-grid.css',
            ],
            [
                'id'          => 'enable_style_forms',
                'label'       => __('Form Styles', 'sfxtheme'),
                'description' => __('Form inputs, checkboxes, radios, file uploads, error states, and focus styles.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'forms.css',
            ],
            [
                'id'          => 'enable_style_buttons',
                'label'       => __('Button Styles', 'sfxtheme'),
                'description' => __('Button variants, sizes, outline, loading states, and icon animations.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'buttons.css',
            ],
            [
                'id'          => 'enable_style_lists',
                'label'       => __('List Styles', 'sfxtheme'),
                'description' => __('Icon list styles (.list--icon, .is-check) with custom icons.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'lists.css',
            ],
            [
                'id'          => 'enable_style_animations',
                'label'       => __('Animation Styles', 'sfxtheme'),
                'description' => __('Fade animations, stagger effects, and parallax keyframes.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'animations.css',
            ],
        ];
    }

    /**
     * Get all fields (general + styles) for sanitization.
     */
    public static function get_all_fields(): array {
        return array_merge(self::get_fields(), self::get_style_fields());
    }

    public static function register_settings(): void
    {
        register_setting(self::$OPTION_GROUP, self::$OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        // General Options Section
        add_settings_section(
            self::$OPTION_NAME.'_section',
            __('General Options', 'sfxtheme'),
            [self::class, 'render_section'],
            self::$OPTION_GROUP
        );

        foreach (self::get_fields() as $field) {
            add_settings_field(
                $field['id'],
                $field['label'],
                [self::class, 'render_field'],
                self::$OPTION_GROUP,
                self::$OPTION_NAME.'_section',
                $field
            );
        }

        // Style Modules Section
        add_settings_section(
            self::$OPTION_NAME.'_styles_section',
            __('Style Modules', 'sfxtheme'),
            [self::class, 'render_styles_section'],
            self::$OPTION_GROUP
        );

        foreach (self::get_style_fields() as $field) {
            add_settings_field(
                $field['id'],
                $field['label'],
                [self::class, 'render_field'],
                self::$OPTION_GROUP,
                self::$OPTION_NAME.'_styles_section',
                $field
            );
        }
    }

    public static function render_section(): void
    {
      echo '<p>' . esc_html__('Selectively enable or disable theme features.', 'sfxtheme') . '</p>';
    }

    public static function render_styles_section(): void
    {
      echo '<p>' . esc_html__('Enable or disable optional CSS style modules. All modules are enabled by default. Disabling unused modules can reduce page size.', 'sfxtheme') . '</p>';
    }

    public static function render_field(array $args): void
    {
        $options = get_option(self::$OPTION_NAME, []);
        $id = esc_attr($args['id']);
        $value = isset($options[$id]) ? (int) $options[$id] : (int) $args['default'];
        ?>
        <input type="checkbox" id="<?php echo $id; ?>" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo $id; ?>]" value="1" <?php checked($value, 1); ?> />
        <label for="<?php echo $id; ?>"><?php echo esc_html($args['description']); ?></label>
        <?php
    }

    public static function sanitize_options($input): array
    {
        $output = [];
        foreach (self::get_all_fields() as $field) {
            $id = $field['id'];
            $output[$id] = isset($input[$id]) && $input[$id] ? 1 : 0;
        }
        return $output;
    }

    public static function delete(): void
    {
        delete_option(self::$OPTION_NAME);
    }
}
