<?php

declare(strict_types=1);

namespace SFX\CompanyLogo;

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
        return [
            [
                'id'          => 'company_logo',
                'label'       => __('Company Logo', 'sfxtheme'),
                'description' => __('Upload or select the main company logo.', 'sfxtheme'),
                'type'        => 'image',
                'default'     => '',
            ],
            [
                'id'          => 'company_logo_inverted',
                'label'       => __('Company Logo (Inverted)', 'sfxtheme'),
                'description' => __('Upload or select an inverted company logo for dark backgrounds.', 'sfxtheme'),
                'type'        => 'image',
                'default'     => '',
            ],
            [
                'id'          => 'company_logo_tiny',
                'label'       => __('Company Logo (Small)', 'sfxtheme'),
                'description' => __('Upload or select a small or compact version of the company logo for shrinked headers.', 'sfxtheme'),
                'type'        => 'image',
                'default'     => '',
            ],
            [
                'id'          => 'company_logo_inverted_tiny',
                'label'       => __('Company Logo (Small Inverted)', 'sfxtheme'),
                'description' => __('Upload or select a small or compact version of the inverted company logo for shrinked headers.', 'sfxtheme'),
                'type'        => 'image',
                'default'     => '',
            ],
        ];
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
            <h3 class="sfx-section-title"><?php echo esc_html($args['label']); ?></h3>
            <div class="sfx-logo-image-upload">
                <input type="hidden"
                    id="<?php echo $id; ?>"
                    name="<?php echo esc_attr(self::$OPTION_NAME . '[' . $id . ']'); ?>"
                    value="<?php echo esc_attr($value); ?>" />
                <img src="<?php echo esc_url($value); ?>" class="sfx-logo-preview" style="max-width:150px;<?php echo empty($value) ? 'display:none;' : ''; ?>" />
                <div class="sfx-logo-buttons" style="margin: 12px 0;">
                    <button type="button" class="button sfx-logo-upload-btn" data-target="<?php echo $id; ?>">
                        <?php esc_html_e('Select Image', 'sfxtheme'); ?>
                    </button>
                    <button type="button" class="button sfx-logo-remove-btn" data-target="<?php echo $id; ?>" <?php echo empty($value) ? 'style=\"display:none;\"' : ''; ?>>
                        <?php esc_html_e('Remove', 'sfxtheme'); ?>
                    </button>
                </div>
                <p class="sfx-description"><?php echo esc_html($args['description']); ?></p>
            </div>
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
