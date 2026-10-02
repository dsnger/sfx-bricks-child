<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

use SFX\WPOptimizer\classes\RestGuestAccess;

class AdminPage
{
    public static $menu_slug = 'sfx-wp-optimizer';
    /** Hook suffix (= screen id) of this page, set when the submenu is added. */
    private static string $page_hook = '';
    public static $page_title = 'WP Optimizer';
    public static $description = 'Toggle a wide range of WordPress optimizations (disable search, comments, REST API, feeds, version numbers, etc.) for performance and security.';

    /**
     * Evaluate conditional logic for field display
     */
    private static function evaluate_condition($value, $operator, $expected_value = null): bool
    {
        switch ($operator) {
            case 'checked':
                return (bool)$value;
            case '!checked':
                return !(bool)$value;
            case 'equals':
                return $value == $expected_value;
            case '!equals':
                return $value != $expected_value;
            case 'in_array':
                return is_array($expected_value) && in_array($value, $expected_value);
            case '!in_array':
                return is_array($expected_value) && !in_array($value, $expected_value);
            default:
                return true;
        }
    }

    /**
     * Get all conditional field configurations
     */
    private static function get_conditional_fields(): array
    {
        $fields = Settings::get_fields();
        $conditionals = [];

        foreach ($fields as $field) {
            if (isset($field['conditional'])) {
                $conditionals[] = [
                    'target' => $field['id'],
                    'dependency' => $field['conditional']['field'],
                    'operator' => $field['conditional']['operator'],
                    'value' => $field['conditional']['value'] ?? null
                ];
            }
        }

        return $conditionals;
    }

    /**
     * Render a single settings field control.
     *
     * @param array<string, mixed> $field
     * @param mixed $value
     * @param array<string, mixed> $options
     */
    private static function render_field_control(array $field, $value, array $options = [], string $input_style = 'margin-top: 16px;'): void
    {
        $id = esc_attr($field['id']);
        $type = $field['type'] ?? 'checkbox';

        if ($type === 'checkbox') {
            $style = $input_style === 'margin-top: 16px;' ? 'margin-top: 32px;' : $input_style;
            echo '<input type="checkbox" id="' . $id . '" name="sfx_wpoptimizer_options[' . $id . ']" value="1" ';
            checked((int) $value, 1);
            echo ' style="' . esc_attr($style) . '" />';

            return;
        }

        if ($type === 'number') {
            $min = isset($field['min']) ? (int) $field['min'] : 0;
            $max = isset($field['max']) ? (int) $field['max'] : 10;
            echo '<input type="number" id="' . $id . '" name="sfx_wpoptimizer_options[' . $id . ']" value="' . esc_attr($value) . '" min="' . $min . '" max="' . $max . '" style="' . esc_attr($input_style) . '" />';

            return;
        }

        if ($type === 'text') {
            echo '<input type="text" id="' . $id . '" name="sfx_wpoptimizer_options[' . $id . ']" value="' . esc_attr((string) $value) . '" class="regular-text" autocapitalize="off" autocorrect="off" spellcheck="false" style="' . esc_attr($input_style) . ' width: 100%;" />';

            return;
        }

        if ($type === 'post_types') {
            echo self::render_post_types_accordion($id, $value, $options);

            return;
        }

        if ($type === 'select') {
            echo '<select id="' . esc_attr($field['id']) . '" name="' . esc_attr('sfx_wpoptimizer_options[' . $field['id'] . ']') . '" style="' . esc_attr($input_style) . '">';
            foreach ($field['options'] ?? [] as $option_value => $option_label) {
                echo '<option value="' . esc_attr((string) $option_value) . '"' . selected((string) $value, (string) $option_value, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</select>';
            if ($field['id'] === 'rest_guest_mode') {
                echo self::render_bricks_warning();
            }

            return;
        }

        if ($type === 'rest_hide_index') {
            // The hidden 0 makes an unticked box post an explicit value (absent would read as the default, hidden).
            $name = esc_attr('sfx_wpoptimizer_options[' . $field['id'] . ']');
            echo '<input type="hidden" name="' . $name . '" value="0" />';
            echo '<input type="checkbox" id="' . esc_attr($field['id']) . '" name="' . $name . '" value="1" ';
            checked((int) $value, 1);
            echo ' style="' . esc_attr('margin-top: 32px;') . '" />';

            return;
        }

        if ($type === 'rest_namespaces') {
            echo self::render_rest_namespaces();

            return;
        }

        // 'hidden_list' (rest_guest_seen) renders nothing: the sanitizer derives it from rest_guest_displayed.
    }

    /** Inline warning below the mode select when the saved policy breaks Bricks for visitors. */
    private static function render_bricks_warning(): string
    {
        $o = RestGuestAccess::option();
        if (get_template() !== 'bricks' || !RestGuestAccess::enforcing($o)) {
            return '';
        }
        if (RestGuestAccess::mode($o) === 'allowlist' && (RestGuestAccess::allowed_map($o)['bricks/v1'] ?? null) === 'all') {
            return '';
        }
        return '<div class="notice notice-warning inline"><p>'
            . esc_html__('Bricks query loops, filters, pagination and popups will fail for visitors — they need bricks/v1 with all methods.', 'sfxtheme')
            . '</p></div>';
    }

    /** The namespace table (allowlist rows), the "Test as guest" button and its results table. */
    private static function render_rest_namespaces(): string
    {
        $o = RestGuestAccess::option();
        $live = RestGuestAccess::live_namespaces();
        $rows = RestGuestAccess::namespaces($o);
        $map = RestGuestAccess::effective_map($o, $live);
        $seen = RestGuestAccess::seen($o);
        // Map keys like "123" come back as ints; owner()/hint() take strings under strict types.
        $names = array_map('strval', array_unique(array_merge($live, array_keys($map))));
        sort($names, SORT_STRING);

        $html = '<table class="widefat striped" style="margin-top: 16px;"><thead><tr>'
            . '<th scope="col">' . esc_html__('Namespace', 'sfxtheme') . '</th>'
            . '<th scope="col">' . esc_html__('Owner', 'sfxtheme') . '</th>'
            . '<th scope="col">' . esc_html__('Notes', 'sfxtheme') . '</th>'
            . '<th scope="col">' . esc_html__('Allowed / methods', 'sfxtheme') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($names as $i => $ns) {
            $badges = [];
            if ($rows !== null && !in_array($ns, $seen, true)) {
                $badges[] = __('new', 'sfxtheme');
            }
            if (!in_array($ns, $live, true)) {
                $badges[] = __('not present', 'sfxtheme');
            }
            $supported = RestGuestAccess::supported($ns);
            if (!$supported) {
                $badges[] = __('unsupported characters — allow via the sfx/rest_guest_allowed_namespaces filter', 'sfxtheme');
            }
            $notes = esc_html(RestGuestAccess::hint($ns));
            foreach ($badges as $badge) {
                $notes .= ' <span class="sfx-rest-badge" style="display: inline-block; padding: 0 6px; border-radius: 3px; background: #f0f0f1; color: #50575e; font-size: 0.9em;">' . esc_html($badge) . '</span>';
            }
            $method = $map[$ns] ?? ($ns === 'wp/v2' ? 'get' : 'all');
            $inputs = $supported ? RestGuestAccess::row_inputs((int) $i, $ns, isset($map[$ns]), $method) : '';
            $html .= '<tr>'
                . '<td><code>' . esc_html($ns) . '</code>'
                . '<input type="hidden" name="sfx_wpoptimizer_options[rest_guest_displayed][]" value="' . esc_attr($ns) . '" /></td>'
                . '<td>' . esc_html(RestGuestAccess::owner($ns)) . '</td>'
                . '<td>' . $notes . '</td>'
                . '<td>' . $inputs . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<p style="margin-top: 16px;"><button type="button" class="button" id="sfx-rest-guest-test">' . esc_html__('Test as guest', 'sfxtheme') . '</button></p>';
        $html .= '<table id="sfx-rest-guest-results" class="widefat striped" hidden><thead><tr>'
            . '<th scope="col">' . esc_html__('Target', 'sfxtheme') . '</th>'
            . '<th scope="col">' . esc_html__('Configured', 'sfxtheme') . '</th>'
            . '<th scope="col">' . esc_html__('Reported', 'sfxtheme') . '</th>'
            . '<th scope="col">' . esc_html__('Result', 'sfxtheme') . '</th>'
            . '</tr></thead><tbody></tbody></table>';
        $html .= '<p class="description">' . esc_html__('The test checks the saved settings with real guest requests (no cookies). The sfx/rest_guest_is_allowed filter is not simulated, and page caching, password protection or CORS rules can change the results.', 'sfxtheme') . '</p>';

        return $html;
    }

    /** Configured (saved, filtered) state per target for the "Test as guest" script. */
    private static function rest_guest_test_config(): array
    {
        $o = RestGuestAccess::option();
        $enforcing = RestGuestAccess::enforcing($o);
        $mode = RestGuestAccess::mode($o);
        $allowed = RestGuestAccess::allowed_map($o);
        $hide = RestGuestAccess::hide_index($o);
        $state = static function (bool $ok) use ($enforcing): string {
            return $enforcing ? ($ok ? 'allowed' : 'blocked') : 'open';
        };
        $namespaces = [];
        foreach (RestGuestAccess::live_namespaces() as $ns) {
            $s = $state(RestGuestAccess::decide('namespace', $ns, 'GET', $mode, $allowed, $hide));
            $namespaces[] = ['namespace' => $ns, 'state' => $s, 'method' => $s === 'allowed' ? ($allowed[$ns] ?? null) : null];
        }
        return [
            'probe'      => rest_url(RestGuestAccess::INTERNAL_NAMESPACE . '/probe'),
            'index'      => ['state' => $state(RestGuestAccess::decide('index', null, 'GET', $mode, $allowed, $hide)), 'method' => null],
            'namespaces' => $namespaces,
            'labels'     => [
                'index'        => __('REST index (/wp-json/)', 'sfxtheme'),
                'open'         => __('open', 'sfxtheme'),
                'allowed'      => __('allowed', 'sfxtheme'),
                'blocked'      => __('blocked', 'sfxtheme'),
                'all'          => __('All methods', 'sfxtheme'),
                'get'          => __('GET only', 'sfxtheme'),
                'asConfigured' => __('as configured', 'sfxtheme'),
                'differs'      => __('differs', 'sfxtheme'),
                'inconclusive' => __('inconclusive', 'sfxtheme'),
                'noResponse'   => __('no response', 'sfxtheme'),
                'invalid'      => __('unexpected response', 'sfxtheme'),
            ],
        ];
    }

    /** admin_notices: live namespaces that appeared since the last save and are blocked for guests. */
    public static function render_rest_notice(): void
    {
        if (!current_user_can('manage_options') || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array_filter(['dashboard', 'plugins', self::$page_hook]), true)) {
            return;
        }
        $o = RestGuestAccess::option();
        if (!RestGuestAccess::enforcing($o) || RestGuestAccess::mode($o) !== 'allowlist' || RestGuestAccess::namespaces($o) === null) {
            return;
        }
        $new = RestGuestAccess::new_blocked(RestGuestAccess::live_namespaces(), RestGuestAccess::seen($o), RestGuestAccess::allowed_map($o));
        if ($new === []) {
            return;
        }
        $items = [];
        foreach ($new as $ns) {
            $items[] = RestGuestAccess::supported($ns)
                ? $ns
                : sprintf(
                    /* translators: %s: REST namespace */
                    __('%s (unsupported characters — allow via the sfx/rest_guest_allowed_namespaces filter)', 'sfxtheme'),
                    $ns
                );
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('New REST namespaces are blocked for guests:', 'sfxtheme') . ' '
            . esc_html(implode(', ', $items)) . ' '
            . '<a href="' . esc_url(admin_url('admin.php?page=' . self::$menu_slug)) . '">' . esc_html__('Review the REST API settings', 'sfxtheme') . '</a>'
            . '</p></div>';
    }

    /**
     * Render post types selection UI with accordion support
     */
    private static function render_post_types_selection($field_id, $value, $options = []): string
    {
        // Filter post types based on field ID
        if ($field_id === 'enable_content_order_post_types') {
            // For Content Order: ONLY hierarchical post types OR those supporting page-attributes
            // Taxonomies won't work because ContentOrder uses wp_posts.menu_order field
            $post_types = get_post_types(['public' => true], 'objects');

            $filtered_items = [];

            foreach ($post_types as $post_type => $post_type_obj) {
                // Only include post types that actually work with ContentOrder
                if ($post_type_obj->hierarchical || post_type_supports($post_type, 'page-attributes')) {
                    $filtered_items[$post_type] = (object) [
                        'labels' => $post_type_obj->labels,
                        'type' => 'post_type',
                        'hierarchical' => $post_type_obj->hierarchical,
                        'supports_page_attributes' => post_type_supports($post_type, 'page-attributes')
                    ];
                }
            }

            $items = $filtered_items;
            $help_text = __('Leave all unchecked to apply to all supported post types. Only hierarchical post types and those supporting page attributes can be ordered.', 'sfxtheme');
        } else {
            // For other post type fields (like revisions): all public post types
            $items = get_post_types(['public' => true], 'objects');
            $help_text = __('Leave all unchecked to apply to all post types.', 'sfxtheme');
        }

        $selected_items = [];
        if (is_array($value)) {
            $selected_items = $value;
        }

        ob_start();
?>
        <div class="post-types-selection">
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                <?php foreach ($items as $item_key => $item_obj): ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox"
                            name="sfx_wpoptimizer_options[<?php echo esc_attr($field_id); ?>][]"
                            value="<?php echo esc_attr($item_key); ?>"
                            <?php checked(in_array($item_key, $selected_items)); ?> />
                        <strong><?php echo esc_html($item_obj->labels->name); ?></strong>
                        <small style="color: #666;">(<?php echo esc_html($item_key); ?>)</small>
                        <?php if (isset($item_obj->hierarchical) && $item_obj->hierarchical): ?>
                            <span style="color: #0073aa; font-size: 0.9em;"> - Hierarchical</span>
                        <?php elseif (isset($item_obj->supports_page_attributes) && $item_obj->supports_page_attributes): ?>
                            <span style="color: #0073aa; font-size: 0.9em;"> - Page Attributes</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p><small><?php echo $help_text; ?></small></p>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Render post types selection with accordion wrapper
     */
    private static function render_post_types_accordion($field_id, $value, $options = []): string
    {
        ob_start();
    ?>
        <details class="post-types-accordion" style="margin-top: 10px;">
            <summary>
                <?php _e('Select Post Types', 'sfxtheme'); ?>
                <span>▼</span>
            </summary>
            <div style="border: 1px solid #ddd; border-top: none; padding: 10px; background: #f9f9f9;">
                <?php echo self::render_post_types_selection($field_id, $value, $options); ?>
            </div>
        </details>
    <?php
        return ob_get_clean();
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
        add_action('admin_notices', [self::class, 'render_rest_notice']);
    }

    public static function add_submenu_page(): void
    {
        // Only register menu if user has theme settings access
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        $hook = add_submenu_page(
            \SFX\SFXBricksChildAdmin::$menu_slug,
            self::$page_title,
            self::$page_title,
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
        self::$page_hook = is_string($hook) ? $hook : '';
    }

    public static function render_page(): void
    {
        // Block direct URL access for unauthorized users
        \SFX\AccessControl::die_if_unauthorized_theme();
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Optimizer Options', 'sfxtheme'); ?></h1>
            <?php settings_errors(\SFX\WPOptimizer\Settings::$OPTION_GROUP); ?>
            <?php
            $groups = [
                'performance' => __('Performance', 'sfxtheme'),
                'admin'       => __('Admin Enhancements', 'sfxtheme'),
                'security'    => __('Security & Privacy', 'sfxtheme'),
                'users'       => __('Users & Authors', 'sfxtheme'),
                'frontend'    => __('Frontend Cleanup', 'sfxtheme'),
                'media'       => __('Media & Uploads', 'sfxtheme'),
            ];
            $fields = \SFX\WPOptimizer\Settings::get_fields();
            $options = get_option('sfx_wpoptimizer_options', []);
            $options = is_array($options) ? $options : [];
            $guest = RestGuestAccess::option();
            $options['rest_guest_mode'] = RestGuestAccess::mode($guest);
            $options['rest_guest_hide_index'] = RestGuestAccess::hide_index($guest) ? 1 : 0;
            ?>
            <div id="sfx-wpoptimizer-tabs">
                <div class="sfx-tabs-nav">
                    <?php $first = true;
                    foreach ($groups as $group_key => $group_label): ?>
                        <button type="button" class="sfx-tab-btn<?php if ($first) echo ' active'; ?>" data-tab="<?php echo esc_attr($group_key); ?>">
                            <?php echo esc_html($group_label); ?>
                        </button>
                    <?php $first = false;
                    endforeach; ?>
                </div>
                <form method="post" action="options.php">
                    <?php settings_fields(\SFX\WPOptimizer\Settings::$OPTION_GROUP); ?>
                    <input type="hidden" name="sfx_wpoptimizer_options[sfx_wpo_form_start]" value="1" />
                    <div class="sfx-tabs-content">
                        <?php $first = true;
                        foreach ($groups as $group_key => $group_label):
                            $group_fields = array_filter($fields, fn($f) => ($f['group'] ?? '') === $group_key);
                            if (empty($group_fields)) continue;
                        ?>
                            <div id="<?php echo esc_attr($group_key); ?>" class="sfx-tab-content" <?php if (!$first) echo ' style="display:none;"'; ?>>
                                <div style="display: flex; flex-wrap: wrap; gap: 24px;">
                                    <?php
                                    $i = 0;
                                    while ($i < count($group_fields)):
                                        $field = array_values($group_fields)[$i];
                                        if (($field['label'] ?? '') === '') {
                                            $i++; // label-less fields (rest_guest_seen) have no card
                                            continue;
                                        }
                                        $id = esc_attr($field['id']);
                                        $is_wide = !empty($field['wide']);
                                        $type = $field['type'] ?? 'checkbox';
                                        $value = $options[$id] ?? $field['default'];

                                        // Check if this is a conditional field
                                        $is_conditional = isset($field['conditional']);
                                        $should_show = true;

                                        if ($is_conditional) {
                                            $dep_field = $field['conditional']['field'];
                                            $operator = $field['conditional']['operator'];
                                            $dep_value = $field['conditional']['value'] ?? null;
                                            $dep_field_value = $options[$dep_field] ?? 0;

                                            $should_show = self::evaluate_condition($dep_field_value, $operator, $dep_value);
                                            $display_style = $should_show ? 'flex' : 'none';

                                            echo '<div id="' . $id . '_container" style="display: ' . $display_style . ';' . ($is_wide ? ' flex: 1 1 100%;' : '') . '">';
                                        }

                                        // Check if next field is also conditional (for combining display)
                                        $next_field = null;
                                        $next_is_conditional = false;
                                        if ($i + 1 < count($group_fields)) {
                                            $next_field = array_values($group_fields)[$i + 1];
                                            $next_is_conditional = isset($next_field['conditional']);
                                        }

                                        // If current and next are both conditional and depend on the same field, combine them
                                        $combine_with_next = $is_conditional
                                            && $next_is_conditional
                                            && $field['conditional']['field'] === $next_field['conditional']['field']
                                            && ($field['conditional']['operator'] ?? null) === ($next_field['conditional']['operator'] ?? null)
                                            && (($field['conditional']['value'] ?? null) === ($next_field['conditional']['value'] ?? null))
                                            && empty($field['wide'])
                                            && empty($next_field['wide']);
                                        $card_size = $is_wide ? 'flex: 1 1 100%; max-width: none;' : 'flex: 1 1 33%; min-width: 220px; max-width: 350px;';
                                    ?>
                                        <div style="<?php echo esc_attr($card_size); ?> background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between;">
                                            <h2 style="margin-top:0; font-size: 1.1em;"><?php echo esc_html($field['label']); ?></h2>
                                            <p style="font-size: 0.97em; color: #555;margin-top: 0; margin-bottom: auto;"><?php echo esc_html($field['description']); ?></p>
                                            <?php self::render_field_control($field, $value, $options); ?>

                                            <?php if ($combine_with_next && $next_field): ?>
                                                <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e5e5;">
                                                <h3 style="margin-top: 0; font-size: 1em;"><?php echo esc_html($next_field['label']); ?></h3>
                                                <p style="font-size: 0.97em; color: #555; margin-bottom: 16px;"><?php echo esc_html($next_field['description']); ?></p>
                                                <?php
                                                $next_value = $options[$next_field['id']] ?? $next_field['default'] ?? '';
                                                self::render_field_control($next_field, $next_value, $options);
                                                ?>
                                                <?php $i++; // Skip the next field since we've already rendered it 
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php
                                        if ($is_conditional) {
                                            echo '</div>';
                                        }
                                        $i++;
                                    endwhile;
                                    ?>
                                </div>
                            </div>
                        <?php $first = false;
                        endforeach; ?>
                    </div>
                    <input type="hidden" name="sfx_wpoptimizer_options[sfx_wpo_form_end]" value="1" />
                    <?php submit_button(); ?>
                </form>
            </div>



            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Generic conditional field handler
                    const conditionalFields = <?php echo json_encode(self::get_conditional_fields()); ?>;

                    function evaluateCondition(value, operator, expectedValue = null) {
                        switch (operator) {
                            case 'checked':
                                return Boolean(value);
                            case '!checked':
                                return !Boolean(value);
                            case 'equals':
                                return value == expectedValue;
                            case '!equals':
                                return value != expectedValue;
                            case 'in_array':
                                return Array.isArray(expectedValue) && expectedValue.includes(value);
                            case '!in_array':
                                return Array.isArray(expectedValue) && !expectedValue.includes(value);
                            default:
                                return true;
                        }
                    }

                    function initializeConditionalFields() {
                        conditionalFields.forEach(config => {
                            const depCheckbox = document.getElementById(config.dependency);
                            const targetContainer = document.getElementById(config.target + '_container');

                            if (depCheckbox && targetContainer) {
                                const toggleFunction = () => {
                                    const depValue = depCheckbox.tagName === 'SELECT' ? depCheckbox.value : depCheckbox.checked;
                                    const shouldShow = evaluateCondition(depValue, config.operator, config.value);
                                    targetContainer.style.display = shouldShow ? 'flex' : 'none';
                                };

                                depCheckbox.addEventListener('change', toggleFunction);
                                toggleFunction(); // Set initial state
                            }
                        });
                    }

                    // Initialize all conditional fields
                    initializeConditionalFields();

                    // "Test as guest": asks the probe route, as a real guest, what the saved policy decides.
                    const sfxRestGuest = <?php echo wp_json_encode(self::rest_guest_test_config(), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                    const testButton = document.getElementById('sfx-rest-guest-test');
                    const resultsTable = document.getElementById('sfx-rest-guest-results');
                    let runId = 0;

                    async function probeGuest(url) {
                        const L = sfxRestGuest.labels;
                        const controller = new AbortController();
                        const timer = setTimeout(() => controller.abort(), 10000);
                        try {
                            const response = await fetch(url, {credentials: 'omit', cache: 'no-store', redirect: 'manual', signal: controller.signal});
                            let body = null;
                            try {
                                body = await response.json();
                            } catch (e) {
                                body = null;
                            }
                            if (response.status !== 200) {
                                const code = body && typeof body.code === 'string' ? ' ' + body.code : '';
                                return {ok: false, detail: 'HTTP ' + response.status + code};
                            }
                            if (!body || !['open', 'allowed', 'blocked'].includes(body.state) || !(body.method === null || ['get', 'all'].includes(body.method))) {
                                return {ok: false, detail: L.invalid};
                            }
                            return {ok: true, state: body.state, method: body.method};
                        } catch (e) {
                            return {ok: false, detail: L.noResponse};
                        } finally {
                            clearTimeout(timer);
                        }
                    }

                    function describe(state, method) {
                        const L = sfxRestGuest.labels;
                        const text = L[state] || String(state);
                        return state === 'allowed' && method ? text + ' (' + (L[method] || String(method)) + ')' : text;
                    }

                    if (testButton && resultsTable) {
                        testButton.addEventListener('click', async function() {
                            const L = sfxRestGuest.labels;
                            const run = ++runId;
                            testButton.disabled = true;
                            const tbody = resultsTable.tBodies[0];
                            tbody.textContent = '';
                            resultsTable.hidden = false;
                            const probe = sfxRestGuest.probe;
                            const join = probe.includes('?') ? '&' : '?';
                            const targets = [{label: L.index, url: probe + join + 'target=index', configured: sfxRestGuest.index, isNamespace: false}]
                                .concat(sfxRestGuest.namespaces.map(n => ({label: n.namespace, url: probe + join + 'namespace=' + encodeURIComponent(n.namespace), configured: n, isNamespace: true})));
                            try {
                                for (const target of targets) {
                                    const reported = await probeGuest(target.url);
                                    if (run !== runId) {
                                        return;
                                    }
                                    let verdict;
                                    if (!reported.ok) {
                                        verdict = L.inconclusive + ' — ' + reported.detail;
                                    } else {
                                        const checkMethod = target.isNamespace && target.configured.state === 'allowed';
                                        const same = reported.state === target.configured.state && (!checkMethod || reported.method === target.configured.method);
                                        verdict = same ? L.asConfigured : L.differs;
                                    }
                                    const row = tbody.insertRow();
                                    [
                                        target.label,
                                        describe(target.configured.state, target.configured.method),
                                        reported.ok ? describe(reported.state, reported.method) : '—',
                                        verdict
                                    ].forEach(text => {
                                        row.insertCell().textContent = text;
                                    });
                                }
                            } finally {
                                if (run === runId) {
                                    testButton.disabled = false;
                                }
                            }
                        });
                    }
                });
            </script>
        </div>
<?php
    }
}
