<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class Settings
{
    public static string $OPTION_GROUP;
    public static string $OPTION_NAME;
    /**
     * Register all settings for contact options.
     */
    public static function register(string $option_key): void
    {
        self::$OPTION_GROUP = $option_key . '_group';
        self::$OPTION_NAME = $option_key;
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue admin styles and scripts for the settings page.
     */
    public static function enqueue_admin_assets(string $hook): void
    {
        // Only enqueue on settings pages
        if (strpos($hook, 'settings_page') === false && strpos($hook, 'options') === false) {
            return;
        }

        // Register and enqueue styles
        $style_path = get_stylesheet_directory_uri() . '/inc/ContactInfos/assets/admin-styles.css';
        wp_register_style('sfx-contact-settings', $style_path, [], '1.0.0');
        wp_enqueue_style('sfx-contact-settings');
        
        // Register and enqueue scripts
        $script_path = get_stylesheet_directory_uri() . '/inc/ContactInfos/assets/admin-script.js';
        wp_register_script('sfx-contact-settings-js', $script_path, ['jquery'], '1.0.0', true);
        
        // Add localized data
        wp_localize_script('sfx-contact-settings-js', 'sfxContactSettings', [
            'confirmDelete' => __('Are you sure you want to delete this branch?', 'sfxtheme'),
        ]);
        
        wp_enqueue_script('sfx-contact-settings-js');
    }

    /**
     * Get field groups for the settings page.
     */
    public static function get_field_groups(): array
    {
        return [
            'company' => [
                'title' => __('Company Information', 'sfxtheme'),
                'description' => __('General information about your company', 'sfxtheme'),
                'fields' => ['company', 'director'],
            ],
            'address' => [
                'title' => __('Address', 'sfxtheme'),
                'description' => __('Address information for your main location', 'sfxtheme'),
                'fields' => ['street', 'zip', 'city', 'country', 'address'],
            ],
            'contact' => [
                'title' => __('Contact Details', 'sfxtheme'),
                'description' => __('Contact methods for your company', 'sfxtheme'),
                'fields' => ['phone', 'mobile', 'fax', 'email'],
            ],
            'business' => [
                'title' => __('Business Information', 'sfxtheme'),
                'description' => __('Legal and business details', 'sfxtheme'),
                'fields' => ['tax_id', 'vat', 'hrb', 'court', 'dsb'],
            ],
            'additional' => [
                'title' => __('Additional Information', 'sfxtheme'),
                'description' => __('Other relevant information', 'sfxtheme'),
                'fields' => ['opening', 'maplink'],
            ],
        ];
    }

    /**
     * Get all contact fields for the settings page.
     */
    public static function get_fields(): array
    {
        return [
            // Company Information
            [
                'id'          => 'company',
                'label'       => __('Company Name', 'sfxtheme'),
                'description' => __('Name of the company', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'company',
            ],
            [
                'id'          => 'director',
                'label'       => __('Managing Director', 'sfxtheme'),
                'description' => __('Name of the managing director', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'company',
            ],
            // Address Fields
            [
                'id'          => 'street',
                'label'       => __('Street / No.', 'sfxtheme'),
                'description' => __('Street name and number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'address',
            ],
            [
                'id'          => 'zip',
                'label'       => __('ZIP Code', 'sfxtheme'),
                'description' => __('Postal code', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'address',
            ],
            [
                'id'          => 'city',
                'label'       => __('City', 'sfxtheme'),
                'description' => __('City/Town', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'address',
            ],
            [
                'id'          => 'country',
                'label'       => __('Country', 'sfxtheme'),
                'description' => __('Country', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'address',
            ],
            [
                'id'          => 'address',
                'label'       => __('Formatted Address', 'sfxtheme'),
                'description' => __('Formatted address for display', 'sfxtheme'),
                'type'        => 'textarea',
                'default'     => '',
                'group'       => 'address',
            ],
            // Contact Information
            [
                'id'          => 'phone',
                'label'       => __('Phone', 'sfxtheme'),
                'description' => __('Phone number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'contact',
            ],
            [
                'id'          => 'mobile',
                'label'       => __('Mobile', 'sfxtheme'),
                'description' => __('Mobile number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'contact',
            ],
            [
                'id'          => 'fax',
                'label'       => __('Fax', 'sfxtheme'),
                'description' => __('Fax number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'contact',
            ],
            [
                'id'          => 'email',
                'label'       => __('Email', 'sfxtheme'),
                'description' => __('Email address', 'sfxtheme'),
                'type'        => 'email',
                'default'     => '',
                'group'       => 'contact',
            ],
            // Business Information
            [
                'id'          => 'tax_id',
                'label'       => __('Tax ID', 'sfxtheme'),
                'description' => __('Tax identification number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'business',
            ],
            [
                'id'          => 'vat',
                'label'       => __('VAT ID', 'sfxtheme'),
                'description' => __('VAT identification number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'business',
            ],
            [
                'id'          => 'hrb',
                'label'       => __('Company Registration No.', 'sfxtheme'),
                'description' => __('Commercial register number', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'business',
            ],
            [
                'id'          => 'court',
                'label'       => __('Registration Court', 'sfxtheme'),
                'description' => __('Responsible registration court', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'business',
            ],
            [
                'id'          => 'dsb',
                'label'       => __('Data Protection Officer', 'sfxtheme'),
                'description' => __('Name of the data protection officer', 'sfxtheme'),
                'type'        => 'text',
                'default'     => '',
                'group'       => 'business',
            ],
            // Additional Information
            [
                'id'          => 'opening',
                'label'       => __('Opening Hours', 'sfxtheme'),
                'description' => __('Business hours of the company', 'sfxtheme'),
                'type'        => 'textarea',
                'default'     => '',
                'group'       => 'additional',
            ],
            [
                'id'          => 'maplink',
                'label'       => __('Google Maps Link', 'sfxtheme'),
                'description' => __('Link to Google Maps', 'sfxtheme'),
                'type'        => 'url',
                'default'     => '',
                'group'       => 'additional',
            ],
            // Branches/Locations
            [
                'id'          => 'branches',
                'label'       => __('Branches/Locations', 'sfxtheme'),
                'description' => __('List of branches or locations', 'sfxtheme'),
                'type'        => 'repeater',
                'default'     => [],
                'fields'      => [
                    [
                        'id'      => 'branch_title',
                        'label'   => __('Branch Name', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_contact_person',
                        'label'   => __('Contact Person', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_street',
                        'label'   => __('Street / No.', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_zip',
                        'label'   => __('ZIP Code', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_city',
                        'label'   => __('City', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_country',
                        'label'   => __('Country', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_address',
                        'label'   => __('Formatted Address', 'sfxtheme'),
                        'type'    => 'textarea',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_opening',
                        'label'   => __('Opening Hours', 'sfxtheme'),
                        'type'    => 'textarea',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_phone',
                        'label'   => __('Phone', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_fax',
                        'label'   => __('Fax', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_mobile',
                        'label'   => __('Mobile', 'sfxtheme'),
                        'type'    => 'text',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_email',
                        'label'   => __('Email', 'sfxtheme'),
                        'type'    => 'email',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_pagelink',
                        'label'   => __('Link to Location Page', 'sfxtheme'),
                        'type'    => 'url',
                        'default' => '',
                    ],
                    [
                        'id'      => 'branch_maplink',
                        'label'   => __('Google Maps Link', 'sfxtheme'),
                        'type'    => 'url',
                        'default' => '',
                    ],
                ],
            ],
        ];
    }

    /**
     * Register all contact options with proper sanitization.
     */
    public static function register_settings(): void
    {
        register_setting(self::$OPTION_GROUP, self::$OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [],
        ]);

        // Main section header
        add_settings_section(
            'contact_info_main',
            __('Contact Information / Legal Details', 'sfxtheme'),
            [self::class, 'render_main_section'],
            self::$OPTION_GROUP
        );

        // Register group sections
        $groups = self::get_field_groups();
        $fields = self::get_fields();
        $group_fields = [];

        // Group fields by their group
        foreach ($fields as $field) {
            // Skip branches field as it has special handling
            if ($field['id'] === 'branches') {
                continue;
            }

            if (isset($field['group'])) {
                $group_fields[$field['group']][] = $field;
            }
        }

        // Add field groups as settings fields
        foreach ($groups as $group_id => $group) {
            if (isset($group_fields[$group_id])) {
                add_settings_field(
                    'group_' . $group_id,
                    $group['title'],
                    [self::class, 'render_field_group'],
                    self::$OPTION_GROUP,
                    'contact_info_main',
                    [
                        'group_id' => $group_id,
                        'group' => $group,
                        'fields' => $group_fields[$group_id],
                    ]
                );
            }
        }

        // Register branches section separately
        add_settings_section(
            'branches_section',
            '',
            [self::class, 'render_branches_section'],
            self::$OPTION_GROUP
        );

        // Add branches field to branches section
        foreach ($fields as $field) {
            if ($field['id'] === 'branches') {
                add_settings_field(
                    $field['id'],
                    $field['label'],
                    [self::class, 'render_branches_field'],
                    self::$OPTION_GROUP,
                    'branches_section',
                    $field
                );
            }
        }
    }

    /**
     * Render a group of fields as a card.
     */
    public static function render_field_group(array $args): void
    {
        $group = $args['group'];
        $fields = $args['fields'];
?>
        <div class="sfx-settings-card">
            <div class="sfx-settings-card-header">
                <h3><?php echo esc_html($group['title']); ?></h3>
            </div>
            <div class="sfx-settings-card-body">
                <?php if (!empty($group['description'])) : ?>
                    <p><?php echo esc_html($group['description']); ?></p>
                <?php endif; ?>
                <div class="sfx-settings-fields-grid">
                    <?php foreach ($fields as $field) : ?>
                        <div class="sfx-settings-field">
                            <?php self::render_field($field); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Sanitize all option values.
     */
    public static function sanitize_options($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $output = [];
        $fields = self::get_fields();

        foreach ($fields as $field) {
            $id = $field['id'];

            // Skip branches as it needs special handling
            if ($id === 'branches') {
                if (isset($input[$id]) && is_array($input[$id])) {
                    $output[$id] = self::sanitize_branches($input[$id], $field['fields']);
                } else {
                    $output[$id] = [];
                }
                continue;
            }

            if (isset($input[$id])) {
                switch ($field['type']) {
                    case 'email':
                        $output[$id] = sanitize_email($input[$id]);
                        break;
                    case 'url':
                        $output[$id] = esc_url_raw($input[$id]);
                        break;
                    case 'textarea':
                        $output[$id] = sanitize_textarea_field($input[$id]);
                        break;
                    default:
                        $output[$id] = sanitize_text_field($input[$id]);
                }
            } else {
                $output[$id] = $field['default'];
            }
        }

        return $output;
    }

    /**
     * Sanitize branch fields.
     */
    private static function sanitize_branches(array $branches, array $branch_fields): array
    {
        $sanitized_branches = [];

        foreach ($branches as $index => $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $sanitized_branch = [];
            foreach ($branch_fields as $field) {
                $field_id = $field['id'];
                if (isset($branch[$field_id])) {
                    switch ($field['type']) {
                        case 'email':
                            $sanitized_branch[$field_id] = sanitize_email($branch[$field_id]);
                            break;
                        case 'url':
                            $sanitized_branch[$field_id] = esc_url_raw($branch[$field_id]);
                            break;
                        case 'textarea':
                            $sanitized_branch[$field_id] = sanitize_textarea_field($branch[$field_id]);
                            break;
                        default:
                            $sanitized_branch[$field_id] = sanitize_text_field($branch[$field_id]);
                    }
                } else {
                    $sanitized_branch[$field_id] = $field['default'];
                }
            }

            $sanitized_branches[$index] = $sanitized_branch;
        }

        return $sanitized_branches;
    }

    /**
     * Render a standard field.
     */
    public static function render_field(array $args): void
    {
        $options = get_option(self::$OPTION_NAME, []);
        $id = esc_attr($args['id']);
        $name = self::$OPTION_NAME . '[' . $id . ']';
        $value = isset($options[$id]) ? $options[$id] : $args['default'];

        echo '<label for="' . esc_attr($id) . '">' . esc_html($args['label']) . '</label>';

        switch ($args['type']) {
            case 'text':
            case 'email':
        ?>
                <input
                    type="<?php echo esc_attr($args['type']); ?>"
                    id="<?php echo esc_attr($id); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    value="<?php echo esc_attr($value); ?>"
                    class="regular-text" />
            <?php
                break;
            case 'url':
            ?>
                <input
                    type="url"
                    id="<?php echo esc_attr($id); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    value="<?php echo esc_url($value); ?>"
                    class="regular-text" />
            <?php
                break;
            case 'textarea':
            ?>
                <textarea
                    id="<?php echo esc_attr($id); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    rows="5"
                    class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <?php
                break;
        }

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Render the branches repeater field.
     */
    public static function render_branches_field(array $args): void
    {
        $options = get_option(self::$OPTION_NAME, []);
        $id = esc_attr($args['id']);
        $branches = isset($options[$id]) ? $options[$id] : [];
        $branch_fields = $args['fields'];
        ?>

        <h3><?php echo esc_html($args['label']); ?></h3>

        <?php if (!empty($args['description'])) : ?>
            <p><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>

        <div class="sfx-branches-container" data-field-id="<?php echo esc_attr($id); ?>" data-next-index="<?php echo count($branches); ?>">
            <div class="sfx-branches-list">
                <?php if (!empty($branches)) : ?>
                    <?php foreach ($branches as $index => $branch) : ?>
                        <div class="sfx-branch-item" data-index="<?php echo esc_attr($index); ?>">
                            <h3 class="sfx-branch-title">
                                <?php echo !empty($branch['branch_title']) ? esc_html($branch['branch_title']) : __('New Branch', 'sfxtheme'); ?>
                                <button type="button" class="sfx-toggle-branch button-link">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                                <button type="button" class="sfx-remove-branch button-link">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </h3>
                            <div class="sfx-branch-fields sfx-settings-card-body">
                                <?php foreach ($branch_fields as $field) : ?>
                                    <div class="sfx-branch-field">
                                        <label>
                                            <?php echo esc_html($field['label']); ?>
                                            <?php
                                            $field_id = $field['id'];
                                            $field_name = self::$OPTION_NAME . '[' . $id . '][' . $index . '][' . $field_id . ']';
                                            $field_value = isset($branch[$field_id]) ? $branch[$field_id] : '';

                                            switch ($field['type']) {
                                                case 'text':
                                                case 'email':
                                            ?>
                                                    <input
                                                        type="<?php echo esc_attr($field['type']); ?>"
                                                        name="<?php echo esc_attr($field_name); ?>"
                                                        value="<?php echo esc_attr($field_value); ?>"
                                                        class="regular-text" />
                                                <?php
                                                    break;
                                                case 'url':
                                                ?>
                                                    <input
                                                        type="url"
                                                        name="<?php echo esc_attr($field_name); ?>"
                                                        value="<?php echo esc_url($field_value); ?>"
                                                        class="regular-text" />
                                                <?php
                                                    break;
                                                case 'textarea':
                                                ?>
                                                    <textarea
                                                        name="<?php echo esc_attr($field_name); ?>"
                                                        rows="3"
                                                        class="large-text"><?php echo esc_textarea($field_value); ?></textarea>
                                            <?php
                                                    break;
                                            }
                                            ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <template class="sfx-branch-template">
                <div class="sfx-branch-item" data-index="{{index}}">
                    <h3 class="sfx-branch-title">
                        <?php echo __('New Branch', 'sfxtheme'); ?>
                        <button type="button" class="sfx-toggle-branch button-link">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <button type="button" class="sfx-remove-branch button-link">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </h3>
                    <div class="sfx-branch-fields sfx-settings-card-body">
                        <?php foreach ($branch_fields as $field) : ?>
                            <div class="sfx-branch-field">
                                <label>
                                    <?php echo esc_html($field['label']); ?>
                                    <?php
                                    $field_id = $field['id'];
                                    switch ($field['type']) {
                                        case 'text':
                                        case 'email':
                                    ?>
                                            <input
                                                type="<?php echo esc_attr($field['type']); ?>"
                                                name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]"
                                                value=""
                                                class="regular-text" />
                                        <?php
                                            break;
                                        case 'url':
                                        ?>
                                            <input
                                                type="url"
                                                name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]"
                                                value=""
                                                class="regular-text" />
                                        <?php
                                            break;
                                        case 'textarea':
                                        ?>
                                            <textarea
                                                name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]"
                                                rows="3"
                                                class="large-text"></textarea>
                                    <?php
                                            break;
                                    }
                                    ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </template>

            <button type="button" class="button sfx-add-branch">
                <?php echo __('Add Branch', 'sfxtheme'); ?>
            </button>
        </div>
    <?php
    }

    /**
     * Render the main section description.
     */
    public static function render_main_section(): void
    {
        //
    }

    /**
     * Render the branches section description.
     */
    public static function render_branches_section(): void
    {
        //
    }

    /**
     * Delete all contact options.
     */
    public static function delete_all_options(): void
    {
        delete_option(self::$OPTION_NAME);
    }
}
