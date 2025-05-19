<?php

declare(strict_types=1);

namespace SFX\TextSnippets;

class Settings
{
    public static string $OPTION_GROUP;
    public static string $OPTION_NAME;
    /**
     * Register all settings for company logo options.
     */
    public static function register(string $option_key): void
    {
        self::$OPTION_GROUP = $option_key . '_group';
        self::$OPTION_NAME = $option_key;
        add_action('admin_init', [self::class, 'register_settings']);
    }

    /**
     * Get all logo fields for the settings page.
     */
    public static function get_fields(): array
    {
        return [];
    }

    /**
     * Register all logo options with proper sanitization.
     */
    public static function register_settings(): void
    {

        register_setting(self::$OPTION_GROUP, self::$OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        add_settings_section(
            self::$OPTION_NAME . '_section',
            __('', 'sfxtheme'),
            [self::class, 'render_section'],
            self::$OPTION_GROUP
        );

        foreach (self::get_fields() as $field) {
            add_settings_field(
                $field['id'],
                $field['label'],
                [self::class, 'render_field'],
                self::$OPTION_GROUP,
                self::$OPTION_NAME . '_section',
                $field
            );
        }
    }


    public static function sanitize_options($input): array
    {
        $output = [];
        foreach (self::get_fields() as $field) {
            $id = $field['id'];
            // Sanitize as text field (URL or empty string)
            $output[$id] = isset($input[$id]) ? sanitize_text_field($input[$id]) : '';
        }
        return $output;
    }



    public static function render_field(array $args): void
    {
        $options = get_option(self::$OPTION_NAME, []);
        $id = esc_attr($args['id']);
        $value = isset($options[$id]) ? $options[$id] : $args['default'];
?>
        <div class="sfx-card">
        
        </div>
<?php
    }

    public static function render_section(): void
    {
        echo '<p>' . esc_html__('Configure and manage the company logo options below.', 'sfxtheme') . '</p>';
    }


    /**
     * Delete all logo options.
     */
    public static function delete_all_options(): void
    {
        delete_option(self::$OPTION_NAME);
    }
}
