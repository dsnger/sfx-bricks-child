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
                'id'          => 'enable_smooth_scroll',
                'label'       => __('Enable Smooth Scroll', 'sfxtheme'),
                'description' => __('Enable Lenis-powered smooth scrolling (replaces the Bricksforge Scroll Smoother).', 'sfxtheme'),
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
                'description' => __('Responsive grid classes (.content-grid, .content--full, .content--feature, .content--split-50-breakout) use scoped --sfx-cg-* wired from --cg-* (which chain to Bricks core --container-*, --grid-gap, --space-m).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'content-grid.css',
            ],
            [
                'id'          => 'enable_style_forms',
                'label'       => __('Form Styles', 'sfxtheme'),
                'description' => __('Form inputs/submit/file-result/state-feedback use scoped --sfx-form-* wired from --form-* (which chain to Bricks core --primary, --text, --danger, --success where applicable).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 1,
                'file'        => 'forms.css',
            ],
            [
                'id'          => 'enable_style_buttons',
                'label'       => __('Button Styles', 'sfxtheme'),
                'description' => __('Buttons use scoped --sfx-btn-* wired from --btn-* (chains through Bricks core pairs --primary/--primary-fg, --accent/--accent-fg, etc.). See Style Modules help for semantic keys.', 'sfxtheme'),
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
     * Extract CSS variables from a CSS file.
     * Results are cached using transients for performance.
     *
     * @param string $filename The CSS filename (e.g., 'buttons.css')
     * @return array List of unique CSS variable names
     */
    public static function get_css_variables(string $filename): array {
        $sanitized = sanitize_key($filename);
        $transient_key = 'sfx_css_vars_v8_' . $sanitized;
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            return $cached;
        }

        // Drop superseded cache keys on recompute (TTL is one week; theme
        // switch/update also wipes all _transient_sfx_% via clear_all_theme_caches).
        foreach (['', 'v2_', 'v3_', 'v4_', 'v5_', 'v6_', 'v7_'] as $legacy_suffix) {
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
      echo '<p>' . esc_html__('Enable or disable optional CSS style modules. All modules are enabled by default. Disabling unused modules can reduce page size.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Buttons and Forms load after the main frontend sheet so cascade layers apply in order.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Token model: every module exposes external --<prefix>-* wireup tokens that default-chain through Bricks core tokens (--primary, --primary-fg, --space-s, etc.) with literal fallbacks. Internal --sfx-<prefix>-* tokens are implementation detail and not shown in this panel. Define the externals where you need a module-specific override; otherwise the chain falls through to your Bricks core tokens.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Optional: customize --sfx-* on a component root in Bricks to override the computed value just for that instance.', 'sfxtheme') . '</p>';
      echo '<p>' . esc_html__('Use the "Copy CSS" button to copy the module source code before disabling it, allowing you to customize it in your own stylesheet.', 'sfxtheme') . '</p>';
      ?>
      <details class="sfx-token-mapping-summary">
        <summary><?php esc_html_e('External token mapping (Buttons)', 'sfxtheme'); ?></summary>
        <p><code><?php echo esc_html('Chrome / layout: --btn-gap, --btn-padding-block|inline, --btn-font-*, --btn-border-*, --btn-radius, --btn-shadow(|-hover), --btn-transition, --btn-focus-outline-*, --btn-mix (hover blend, default black), --btn-s-*, --btn-l-*, --btn-xl-*.'); ?></code></p>
        <p><code><?php echo esc_html('Per-variant chain (filled + outline): --btn-<v>-bg falls back to Bricks --<v>; --btn-<v>-fg falls back to Bricks --<v>-fg. Variants: primary, secondary, accent, light, dark, muted, info, success, danger, warning. Define semantic Bricks pairs (--primary/--primary-fg, --accent/--accent-fg, ...) and buttons follow without per-variant button tokens.'); ?></code></p>
        <p><code><?php echo esc_html('Light/dark are special-cased: --btn-light-fg falls back to Bricks --dark; --btn-dark-fg falls back to Bricks --light (contrast locked even when paired -fg tokens are absent). secondary and dark variants flip --btn-mix to white for lighter hover on already-dark colors; override via --btn-secondary-mix / --btn-dark-mix.'); ?></code></p>
        <p><code><?php echo esc_html('Scoped consumption in module: every longhand uses var(--sfx-btn-*); the scoped selector maps --sfx-btn-* from the external --btn-* / --<v> / --<v>-fg chain with literal fallbacks.'); ?></code></p>
      </details>
      <details class="sfx-token-mapping-summary">
        <summary><?php esc_html_e('External token mapping (Forms)', 'sfxtheme'); ?></summary>
        <p><code><?php echo esc_html('Layout & fields: --form-input-height, --form-label-*, --form-padding-block|inline, --form-border-*, --form-font-* (size, line-height, family, weight, letter-spacing), --form-input-bg, --form-color, --form-placeholder-* (optional; cascades from field typography when unset), --form-select-* typography (optional; cascades from field tokens), --form-options-gap, --form-option-row-gap, --form-radius-small, --form-focus-*, --form-radio-active-color, --form-icon-*, --form-checkbox-icon, --form-group-spacing, --form-error-bg|color (validation-message tooltip), --form-file-remove-*, --form-submit-padding-*, --form-submit-border-*, --form-choose-files-padding-block|inline, --form-select-padding-inline-end.'); ?></code></p>
        <p><code><?php echo esc_html('State-surface (file-result inline feedback, distinct from --form-error-bg|color): --form-success-surface, --form-success-surface-fg, --form-error-surface, --form-error-surface-fg. Defaults chain to Bricks --success / --danger; background defaults to color-mix(5% surface-fg, input-bg).'); ?></code></p>
        <p><code><?php echo esc_html('Component-wide: --form-transition (default all .15s ease), --form-placeholder-transition (default opacity .2s ease), --form-file-result-gap|padding|icon-size, --form-submit-sending-gap.'); ?></code></p>
        <p><code><?php echo esc_html('Bricks core chains: --form-focus-color & --form-radio-active-color chain to --primary; --form-label-color & --form-color chain to --text; --form-error-bg|color chain to --danger-bg|fg; --form-file-remove-color & --form-file-remove-hover-bg chain to --danger.'); ?></code></p>
        <p><code><?php echo esc_html('Typographic cascade in CSS: placeholders use --form-placeholder-font-family|--form-placeholder-font-weight|--form-placeholder-letter-spacing defaulting to field --form-font-*; placeholder font-size and line-height chain to --form-font-size|--form-line-height when their placeholder counterparts are omitted. Selects mirror this via --form-select-* chaining to --form-font-*.'); ?></code></p>
        <p><code><?php echo esc_html('Scoped consumption: longhands use var(--sfx-form-*); submit controls use var(--sfx-form-submit-*). No --btn-* in the forms module.'); ?></code></p>
      </details>
      <details class="sfx-token-mapping-summary">
        <summary><?php esc_html_e('External token mapping (Lists)', 'sfxtheme'); ?></summary>
        <p><code><?php echo esc_html('Layout: --list-gutter, --list-indent, --list-gap, --list-item-gutter, --list-icon-gap. Defaults chain to Bricks --space-s / --space-2xs / --article-gutter-xs.'); ?></code></p>
        <p><code><?php echo esc_html('Icon: --list-icon-url (default check SVG mask), --list-icon-size, --list-icon-offset, --list-icon-display, --list-icon-color (default --secondary), --list-icon-bg-color (default --tertiary-l-4), --list-icon-bg-offset, --list-icon-bg-size-pad, --list-icon-bg-radius (default --radius-full).'); ?></code></p>
        <p><code><?php echo esc_html('.is-check variant overrides: --list-check-icon-url, --list-check-icon-color, --list-check-icon-offset, --list-check-icon-size, --list-check-icon-icon-gap, --list-check-icon-display, --list-check-icon-indent, --list-check-icon-gap, --list-check-icon-bg-offset, --list-check-icon-bg-color, --list-check-icon-bg-color-alt. Each falls back through the base --list-* token before the literal.'); ?></code></p>
        <p><code><?php echo esc_html('Scoped consumption: every longhand uses var(--sfx-list-*); two triggers (.list--icon on a <ul>, or .has-list-icons on a wrapping element) opt a list into the system.'); ?></code></p>
      </details>
      <details class="sfx-token-mapping-summary">
        <summary><?php esc_html_e('External token mapping (Content Grid)', 'sfxtheme'); ?></summary>
        <p><code><?php echo esc_html('Wireup at :root: --cg-gutter (chains to --container-padding-horizontal), --cg-content (chains to --max-screen-width), --cg-feature (fixed 2100px default), --cg-feature-max (fixed 2450px default), --cg-gap (chains to --grid-gap then --space-m), --cg-split (split-breakout left-column fraction, default 0.5).'); ?></code></p>
        <p><code><?php echo esc_html('Scoped consumption: grid rules use var(--sfx-cg-*). Breakpoint literals in @media queries (768/2100/2450) stay hardcoded — CSS forbids var() inside @media rules, so --cg-feature/-max keep fixed px defaults (not the scaling --container-xlarge/2xlarge) to stay in sync. --cg-content is free to grow.'); ?></code></p>
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
