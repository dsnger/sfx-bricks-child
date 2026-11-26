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
                'id'          => 'enable_password_protected',
                'label'       => __('Enable Password Protection module', 'sfxtheme'),
                'description' => __('Loads the password protection module and adds its settings page. The protection itself is switched on inside that page.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_smooth_scroll',
                'label'       => __('Enable Smooth Scroll', 'sfxtheme'),
                'description' => __('Enable Lenis-powered smooth scrolling (replaces the Bricksforge Scroll Smoother).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_nav_menu_query',
                'label'       => __('Enable Menu Items query type', 'sfxtheme'),
                'description' => __('Adds a "Menu Items" query type to the Bricks query loop, so a WordPress menu can drive Bricks markup.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_media_credits',
                'label'       => __('Enable Media Credits', 'sfxtheme'),
                'description' => __('Adds a copyright notice and an AI marking to every media attachment, usable in Bricks image elements.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_redirects',
                'label'       => __('Enable Redirects', 'sfxtheme'),
                'description' => __('Manage redirects (301, 302, 307, 308, 410), log 404 errors and create redirects automatically when a page\'s address changes.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_editor_prose',
                'label'       => __('Enable Editor Prose', 'sfxtheme'),
                'description' => __('Makes the block editor show Gutenberg content like the Bricks frontend (prose class, spacing, optional baseline).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_markdown',
                'label'       => __('Enable Markdown for AI agents', 'sfxtheme'),
                'description' => __('Serves a Markdown version of your pages to AI agents that ask for it, and an llms.txt index.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
            [
                'id'          => 'enable_site_check',
                'label'       => __('Enable Sicherheits-Check', 'sfxtheme'),
                'description' => __('Adds Tools → Sicherheits-Check: checks the site for exposed files, risky settings and launch mistakes.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
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
                'id'          => 'disable_bricks_css',
                'label'       => __('Disable Bricks Styling', 'sfxtheme'),
                'description' => __('Remove all default Bricks styling.', 'sfxtheme'),
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
                'description' => __('Responsive grid classes (.content-grid, .content--full, .content--feature, .content--split-50-breakout) use scoped --sfx-cg-* wired from --cg-* (which chain to Bricks core --container-*, --grid-gap, --space-m).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'content-grid.css',
            ],
            [
                'id'          => 'enable_style_forms',
                'label'       => __('Form Styles', 'sfxtheme'),
                'description' => __('Form inputs/submit/file-result/state-feedback use scoped --sfx-form-* wired from --form-* (which chain to --primary, --text or --text-title, --danger or --error, --success where applicable).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'forms.css',
            ],
            [
                'id'          => 'enable_style_buttons',
                'label'       => __('Button Styles', 'sfxtheme'),
                'description' => __('Buttons use scoped --sfx-btn-* wired from --btn-* (chains through Bricks core pairs --primary/--primary-fg, --accent/--accent-fg, etc.).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'buttons.css',
            ],
            [
                'id'          => 'enable_style_lists',
                'label'       => __('List Styles', 'sfxtheme'),
                'description' => __('Icon list styles (.list--icon renders a default check; .is-check tunes spacing) use scoped --sfx-list-* wired from --list-* (chain to Bricks core --space-*, --radius-full, --secondary).', 'sfxtheme'),
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
     * Public tokens a module sets itself (a default per class) but a site may still set.
     */
    private const LISTED_ALTHOUGH_SET = [
        'animations.css' => ['--animate-delay-step'],
    ];

    /**
     * Extract CSS variables from a CSS file.
     * Results are cached using transients for performance.
     *
     * @param string $filename The CSS filename (e.g., 'buttons.css')
     * @return array List of unique CSS variable names
     */
    public static function get_css_variables(string $filename): array {
        $sanitized = sanitize_key($filename);
        $transient_key = 'sfx_css_vars_v9_' . $sanitized;
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            return $cached;
        }

        // Drop superseded cache keys on recompute (TTL is one week; theme
        // switch/update also wipes all _transient_sfx_% via clear_all_theme_caches).
        foreach (['', 'v2_', 'v3_', 'v4_', 'v5_', 'v6_', 'v7_', 'v8_'] as $legacy_suffix) {
            delete_transient('sfx_css_vars_' . $legacy_suffix . $sanitized);
        }

        $file_path = get_stylesheet_directory() . '/assets/css/frontend/modules/' . $filename;

        if (!file_exists($file_path)) {
            return [];
        }

        $css_content = file_get_contents($file_path);
        if ($css_content === false) {
            return [];
        }

        // Strip /* ... */ comments so wildcard placeholders inside
        // header comments (e.g. "var(--btn-*, literal)") don't surface
        // as bogus tokens like "--btn-".
        $css_content = preg_replace('!/\*.*?\*/!s', '', $css_content);

        // Show only tokens the module *expects from outside* — i.e. referenced
        // via var() but not defined in the file. Internal scoped tokens
        // (--sfx-*) are both defined and used inside the module; they
        // shouldn't be exposed for theme-option wiring.
        $referenced = [];
        if (preg_match_all('/var\(\s*(--[\w-]+)/', $css_content, $matches)) {
            $referenced = array_unique($matches[1]);
        }

        $defined = [];
        if (preg_match_all('/[\s{;](--[\w-]+)\s*:/', $css_content, $matches)) {
            $defined = array_unique($matches[1]);
        }

        // A public knob the module also sets itself as a default stays listed.
        $defined = array_diff($defined, self::LISTED_ALTHOUGH_SET[$filename] ?? []);
        $variables = array_values(array_diff($referenced, $defined));

        // Allowlist by module prefix: every module references Bricks core
        // tokens (--primary, --space-s, etc.) as default-chain fallbacks
        // after the tokenization alignment refactor. Those belong to the
        // Bricks/core framework surface, not to the per-module override
        // list, so they're filtered out here. Keeping only --<prefix>-*
        // tokens gives each module's theme-options panel a focused,
        // module-scoped configuration surface.
        $prefix = self::get_module_prefix($filename);
        if ($prefix !== '') {
            $variables = array_values(array_filter(
                $variables,
                static fn(string $v): bool => str_starts_with($v, $prefix)
            ));
        }

        sort($variables);

        // Cache for 1 week; all sfx_* transients cleared on theme switch/update.
        set_transient($transient_key, $variables, WEEK_IN_SECONDS);

        return $variables;
    }

    /**
     * Map a module CSS filename to its public-token prefix.
     *
     * @param string $filename The CSS filename (e.g., 'buttons.css')
     * @return string The prefix (e.g., '--btn-') or '' if unknown
     */
    private static function get_module_prefix(string $filename): string {
        $map = [
            'buttons.css'      => '--btn-',
            'forms.css'        => '--form-',
            'lists.css'        => '--list-',
            'content-grid.css' => '--cg-',
            'animations.css'   => '--animate-',
        ];
        return $map[$filename] ?? '';
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
      echo '<p>' . esc_html__('Switch off the style modules you do not use to keep pages smaller.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('To change how a module looks, set one of its variables (listed under "CSS Variables" in its card) in Core Framework or in your own CSS, for example --btn-radius: 999px for round buttons.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Use the "Copy CSS" button to copy the module source code before disabling it, allowing you to customize it in your own stylesheet.', 'sfxtheme') . '</p>';
    }

    public static function render_field(array $args): void
    {
        $options = get_option(self::$OPTION_NAME, []);
        $id = (string) $args['id'];
        $value = isset($options[$id]) ? (int) $options[$id] : (int) $args['default'];
        ?>
        <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr(self::$OPTION_NAME . '[' . $id . ']'); ?>" value="1" aria-describedby="<?php echo esc_attr($id . '-desc'); ?>" <?php checked($value, 1); ?> />
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
