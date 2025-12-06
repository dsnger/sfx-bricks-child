<?php

declare(strict_types=1);

namespace SFX\HtmlCopyPaste;

class Settings
{
    public static function init(): void
    {
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function register_settings(): void
    {
        register_setting(
            Controller::OPTION_NAME,
            Controller::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize_settings'],
                'default' => [
                    'enable_html_copy_paste' => '0',
                    'enable_editor_mode' => '0',
                    'preserve_custom_attributes' => '0',
                    'auto_convert_images' => '0',
                    'auto_convert_links' => '0',
                ]
            ]
        );

        add_settings_section(
            'sfx_html_copy_paste_main',
            __('Main Settings', 'sfxtheme'),
            [self::class, 'render_section_description'],
            Controller::OPTION_NAME
        );

        add_settings_field(
            'enable_html_copy_paste',
            __('Enable HTML Copy/Paste', 'sfxtheme'),
            [self::class, 'render_checkbox_field'],
            Controller::OPTION_NAME,
            'sfx_html_copy_paste_main',
            [
                'label_for' => 'enable_html_copy_paste',
                'description' => __('Enable the HTML copy/paste functionality in Bricks Builder.', 'sfxtheme'),
            ]
        );

        add_settings_field(
            'enable_editor_mode',
            __('Enable HTML Editor', 'sfxtheme'),
            [self::class, 'render_checkbox_field'],
            Controller::OPTION_NAME,
            'sfx_html_copy_paste_main',
            [
                'label_for' => 'enable_editor_mode',
                'description' => __('Enable the HTML editor mode for advanced editing before conversion.', 'sfxtheme'),
            ]
        );

        add_settings_field(
            'preserve_custom_attributes',
            __('Preserve Custom Attributes', 'sfxtheme'),
            [self::class, 'render_checkbox_field'],
            Controller::OPTION_NAME,
            'sfx_html_copy_paste_main',
            [
                'label_for' => 'preserve_custom_attributes',
                'description' => __('Preserve custom HTML attributes when converting to Bricks elements.', 'sfxtheme'),
            ]
        );

        add_settings_field(
            'auto_convert_images',
            __('Auto Convert Images', 'sfxtheme'),
            [self::class, 'render_checkbox_field'],
            Controller::OPTION_NAME,
            'sfx_html_copy_paste_main',
            [
                'label_for' => 'auto_convert_images',
                'description' => __('Automatically convert img tags to Bricks image elements.', 'sfxtheme'),
            ]
        );

        add_settings_field(
            'auto_convert_links',
            __('Auto Convert Links', 'sfxtheme'),
            [self::class, 'render_checkbox_field'],
            Controller::OPTION_NAME,
            'sfx_html_copy_paste_main',
            [
                'label_for' => 'auto_convert_links',
                'description' => __('Automatically convert anchor tags to Bricks link elements.', 'sfxtheme'),
            ]
        );
    }

    public static function render_section_description(): void
    {
        echo '<p>' . esc_html__('Configure the HTML copy/paste functionality for Bricks Builder.', 'sfxtheme') . '</p>';
    }

    public static function render_checkbox_field(array $args): void
    {
        $defaults = [
            'enable_html_copy_paste' => '0',
            'enable_editor_mode' => '0',
            'preserve_custom_attributes' => '0',
            'auto_convert_images' => '0',
            'auto_convert_links' => '0',
        ];
        
        $options = get_option(Controller::OPTION_NAME, $defaults);
        $field_name = $args['label_for'];
        $value = $options[$field_name] ?? '0';
        $description = $args['description'] ?? '';

        ?>
        <label for="<?php echo esc_attr($field_name); ?>">
            <input type="checkbox" 
                   id="<?php echo esc_attr($field_name); ?>"
                   name="<?php echo esc_attr(Controller::OPTION_NAME . '[' . $field_name . ']'); ?>"
                   value="1"
                   <?php checked('1', $value); ?>>
            <?php echo esc_html($description); ?>
        </label>
        <?php
    }

    public static function sanitize_settings($input): array
    {
        // Handle null input (when option is being deleted/reset)
        if ($input === null || !is_array($input)) {
            $input = [];
        }
        
        $sanitized = [];
        
        $checkbox_fields = [
            'enable_html_copy_paste',
            'enable_editor_mode',
            'preserve_custom_attributes',
            'auto_convert_images',
            'auto_convert_links',
        ];

        foreach ($checkbox_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? '1' : '0';
        }

        return $sanitized;
    }
} 