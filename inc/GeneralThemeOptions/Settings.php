<?php

namespace SFX\GeneralThemeOptions;

class Settings
{
    public static $OPTION_GROUP;

    /**
     * Register all settings for security headers.
     */
    public static function register($option_key): void
    {
        self::$OPTION_GROUP = $option_key.'_group';
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function get_fields(): array {
        return [
            [
                'id'          => 'enable_wp_optimizer',
                'label'       => __('Enable WP Optimizer', 'sfxtheme'),
                'description' => __('Enable WP Optimizer to manage some WordPress features for better performance and security.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'enable_image_optimizer',
                'label'       => __('Enable Image Optimizer', 'sfxtheme'),
                'description' => __('Enable Image Optimizer to optimize images for better performance.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'enable_security_header',
                'label'       => __('Enable Security Header', 'sfxtheme'),
                'description' => __('Enable Security Header to protect your website against common security threats.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
            ],
            [
                'id'          => 'disable_bricks_js',
                'label'       => __('Disable Bricks JS', 'sfxtheme'),
                'description' => __('Remove the default Bricks JavaScript from the frontend for enhanced performance and custom JS solutions.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            [
                'id'          => 'disable_bricks_styles',
                'label'       => __('Disable Bricks Styling', 'sfxtheme'),
                'description' => __('Remove all default Bricks styling.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
            ],
            
        ];
    }

    public static function register_settings(): void
    {
        register_setting(self::$OPTION_GROUP, 'sfx_general_options', [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        add_settings_section(
            'sfx_general_options_section',
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
                'sfx_general_options_section',
                $field
            );
        }
    }

    public static function render_section(): void
    {
      echo '<p>General Options</p>';
    }

    public static function render_field(array $args): void
    {
        $options = get_option('sfx_general_options', []);
        $id = esc_attr($args['id']);
        $value = isset($options[$id]) ? (int) $options[$id] : (int) $args['default'];
        ?>
        <input type="checkbox" id="<?php echo $id; ?>" name="sfx_general_options[<?php echo $id; ?>]" value="1" <?php checked($value, 1); ?> />
        <label for="<?php echo $id; ?>"><?php echo esc_html($args['description']); ?></label>
        <?php
    }

    public static function sanitize_options($input): array
    {
        $output = [];
        foreach (self::get_fields() as $field) {
            $id = $field['id'];
            $output[$id] = isset($input[$id]) && $input[$id] ? 1 : 0;
        }
        return $output;
    }

    public static function delete(): void
    {
        delete_option('sfx_general_options');
    }
}
