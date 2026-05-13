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
                'description' => __('Form inputs and submit styling use scoped --sfx-form-* tokens wired from --form-* / --form-submit-* (define in Core Framework).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'forms.css',
            ],
            [
                'id'          => 'enable_style_buttons',
                'label'       => __('Button Styles', 'sfxtheme'),
                'description' => __('Buttons use scoped --sfx-btn-* wired from --btn-* (define in Core Framework). See Style Modules help for semantic keys.', 'sfxtheme'),
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
     * Extract CSS variables from a CSS file.
     * Results are cached using transients for performance.
     *
     * @param string $filename The CSS filename (e.g., 'buttons.css')
     * @return array List of unique CSS variable names
     */
    public static function get_css_variables(string $filename): array {
        $transient_key = 'sfx_css_vars_v5_' . sanitize_key($filename);
        $cached = get_transient($transient_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $file_path = get_stylesheet_directory() . '/assets/css/frontend/modules/' . $filename;
        
        if (!file_exists($file_path)) {
            return [];
        }
        
        $css_content = file_get_contents($file_path);
        if ($css_content === false) {
            return [];
        }
        
        $variables = [];
        
        // Match var(--variable-name) - variables being used
        if (preg_match_all('/var\(\s*(--[\w-]+)/', $css_content, $matches)) {
            $variables = array_merge($variables, $matches[1]);
        }
        
        // Match --variable-name: (variable definitions, but exclude the internal ones defined and used only within the file)
        // We want variables that are expected to come from outside (framework/theme)
        if (preg_match_all('/[\s{;](--[\w-]+)\s*:/', $css_content, $matches)) {
            // These are definitions - we'll include them as they show what can be customized
            $variables = array_merge($variables, $matches[1]);
        }
        
        // Remove duplicates and sort
        $variables = array_unique($variables);
        sort($variables);
        
        // Cache for 1 week (cleared on theme update via clear_all_theme_caches)
        set_transient($transient_key, $variables, WEEK_IN_SECONDS);
        
        return $variables;
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
      echo '<p>' . esc_html__('Buttons and Forms load after the main frontend sheet so cascade layers apply in order.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Token model: selectors consume scoped --sfx-* variables. Define matching --btn-* (buttons), --form-* and --form-submit-* (forms) in Core Framework, global CSS, or on a block—the theme ships literal fallbacks when those hooks are absent.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Optional: customize --sfx-* on a component root in Bricks to override computed values.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Use the "Copy CSS" button to copy the module source code before disabling it, allowing you to customize it in your own stylesheet.', 'sfxtheme') . '</p>';
      ?>
      <details class="sfx-token-mapping-summary">
        <summary><?php esc_html_e('External token mapping (Buttons)', 'sfxtheme'); ?></summary>
        <p><code><?php echo esc_html('Semantic / layout: --btn-gap, --btn-padding-block|inline, --btn-font-* , --btn-border-*, --btn-radius, --btn-shadow(|-hover), --btn-transition, --btn-focus-outline-*, --btn-mix, --btn-s-* , --btn-l-* , --btn-xl-*, plus --btn-color / --btn-color-fg when setting a flat pair.'); ?></code></p>
        <p><code><?php echo esc_html('Semantic backgrounds (filled + outline): --btn-primary-bg|fg, --btn-secondary-bg|fg|mix, --btn-accent-bg|fg, --btn-light-bg|fg, --btn-dark-bg|fg|mix, --btn-muted-bg|fg, --btn-info-bg|fg, --btn-success-bg|fg, --btn-danger-bg|fg, --btn-warning-bg|fg.'); ?></code></p>
        <p><code><?php echo esc_html('Scoped consumption in module: every longhand uses var(--sfx-btn-*); root maps --sfx-btn-* from var(--btn-*, literal).'); ?></code></p>
      </details>
      <details class="sfx-token-mapping-summary">
        <summary><?php esc_html_e('External token mapping (Forms)', 'sfxtheme'); ?></summary>
        <p><code><?php echo esc_html('Layout & fields: --form-input-height, --form-label-*, --form-padding-block|inline, --form-border-*, --form-font-* (size, line-height, family, weight, letter-spacing), --form-input-bg, --form-color, --form-placeholder-* (optional; cascades from field typography when unset), --form-select-* typography (optional; cascades from field tokens), --form-options-gap, --form-option-row-gap, --form-radius-small, --form-focus-*, --form-radio-active-color, --form-icon-*, --form-checkbox-icon, --form-group-spacing, --form-error-*, --form-file-remove-*, --form-submit-padding-*, --form-submit-border-*, --form-choose-files-padding-block|inline, --form-select-padding-inline-end.'); ?></code></p>
        <p><code><?php echo esc_html('Typographic cascade in CSS: placeholders use --form-placeholder-font-family|--form-placeholder-font-weight|--form-placeholder-letter-spacing defaulting to field --form-font-* ; placeholder font-size and line-height chain to --form-font-size|--form-line-height when their placeholder counterparts are omitted. Selects mirror this via --form-select-* chaining to --form-font-* .'); ?></code></p>
        <p><code><?php echo esc_html('Scoped consumption: longhands use var(--sfx-form-*); submit controls use var(--sfx-form-submit-*). No --btn-* in the forms module.'); ?></code></p>
      </details>
      <?php
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
        // Add "Copy CSS" button for style module fields
        if (!empty($args['file'])) {
            $css_url = get_stylesheet_directory_uri() . '/assets/css/frontend/modules/' . $args['file'];
            ?>
            <button type="button" 
                    class="sfx-copy-css-btn button button-small" 
                    data-css-file="<?php echo esc_url($css_url); ?>"
                    data-label-copy="<?php esc_attr_e('Copy CSS', 'sfxtheme'); ?>"
                    data-label-copied="<?php esc_attr_e('Copied!', 'sfxtheme'); ?>"
                    data-label-error="<?php esc_attr_e('Error', 'sfxtheme'); ?>">
                <?php esc_html_e('Copy CSS', 'sfxtheme'); ?>
            </button>
            <?php
        }
        
        // Show CSS variables list (parsed dynamically from CSS file)
        if (!empty($args['file'])) {
            $variables = self::get_css_variables($args['file']);
            if (!empty($variables)) {
                $variables_text = implode(', ', $variables);
                ?>
                <details class="sfx-css-variables">
                    <summary><?php esc_html_e('CSS Variables', 'sfxtheme'); ?> (<?php echo count($variables); ?>)</summary>
                    <div class="sfx-variables-wrapper">
                        <code class="sfx-variables-list"><?php echo esc_html($variables_text); ?></code>
                        <button type="button" 
                                class="sfx-copy-vars-btn button button-small" 
                                data-variables="<?php echo esc_attr($variables_text); ?>"
                                data-label-copy="<?php esc_attr_e('Copy', 'sfxtheme'); ?>"
                                data-label-copied="<?php esc_attr_e('Copied!', 'sfxtheme'); ?>">
                            <?php esc_html_e('Copy', 'sfxtheme'); ?>
                        </button>
                    </div>
                </details>
                <?php
            }
        }
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
