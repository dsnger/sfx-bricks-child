<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

/**
 * Registers the 'sfx_contact_info' post type.
 */
class PostType
{
    /**
     * Custom post type slug.
     *
     * @var string
     */
    public static string $post_type = 'sfx_contact_info';

    /**
     * Initialize post type and meta box hooks.
     */
    public static function init(): void
    {
        // Register post type through consolidated system
        add_action('sfx_init_post_types', [self::class, 'register_post_type']);
        
        // Register meta boxes and save operations (these need to be on their specific hooks)
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('save_post_' . self::$post_type, [self::class, 'save_custom_fields']);
        
        // Register admin columns
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_type_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_type_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_address_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_address_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_contact_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_contact_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'add_status_column']);
        add_action('manage_' . self::$post_type . '_posts_custom_column', [self::class, 'render_status_column'], 10, 2);
        add_filter('manage_' . self::$post_type . '_posts_columns', [self::class, 'remove_date_column']);
        
        // Multilingual support through consolidated system
        add_action('sfx_init_advanced_features', [self::class, 'register_multilingual_support']);
    }

    /**
     * Register the custom post type.
     */
    public static function register_post_type(): void
    {
        $labels = [
            'name'                  => __('Contact Information', 'sfx-bricks-child'),
            'singular_name'         => __('Contact Info', 'sfx-bricks-child'),
            'add_new'               => __('Add New', 'sfx-bricks-child'),
            'add_new_item'          => __('Add New Contact Info', 'sfx-bricks-child'),
            'edit_item'             => __('Edit Contact Info', 'sfx-bricks-child'),
            'new_item'              => __('New Contact Info', 'sfx-bricks-child'),
            'view_item'             => __('View Contact Info', 'sfx-bricks-child'),
            'search_items'          => __('Search Contact Info', 'sfx-bricks-child'),
            'not_found'             => __('No contact info found', 'sfx-bricks-child'),
            'not_found_in_trash'    => __('No contact info found in Trash', 'sfx-bricks-child'),
            'all_items'             => __('All Contact Info', 'sfx-bricks-child'),
            'menu_name'             => __('Contact Info', 'sfx-bricks-child'),
            'name_admin_bar'        => __('Contact Info', 'sfx-bricks-child'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_in_menu'       => false, // Will be added as submenu under theme admin
            'show_in_rest'       => true,
            'supports'           => ['title'],
            'has_archive'        => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'show_ui'            => true,
            // Multilingual support
            'publicly_queryable' => false,
            'query_var'          => false,
        ];

        register_post_type(self::$post_type, $args);
        
        // Register meta fields
        self::register_meta_fields();
    }

    /**
     * Register meta fields for contact info
     */
    private static function register_meta_fields(): void
    {
        $fields = [
            'company', 'director', 'street', 'zip', 'city', 'country',
            'address', 'phone', 'mobile', 'fax', 'email', 'tax_id', 
            'vat', 'hrb', 'court', 'dsb', 'opening', 'maplink', 'contact_type'
        ];
        
        \SFX\MetaFieldManager::register_fields(self::$post_type, $fields);
        
        // Add validation and cleanup hooks
        add_action('save_post_' . self::$post_type, [self::class, 'validate_meta_fields']);
        add_action('delete_post_' . self::$post_type, [self::class, 'cleanup_meta_fields']);
    }

    /**
     * Validate contact info meta fields
     * 
     * @param int $post_id
     */
    public static function validate_meta_fields(int $post_id): void
    {
        $validation_rules = [
            '_email' => 'is_email',
            '_phone' => 'sanitize_text_field',
            '_mobile' => 'sanitize_text_field',
            '_fax' => 'sanitize_text_field',
            '_contact_type' => function($value) {
                return in_array($value, ['main', 'branch']) ? $value : 'main';
            }
        ];
        
        \SFX\MetaFieldManager::validate_fields($post_id, self::$post_type, $validation_rules);
    }

    /**
     * Cleanup contact info meta fields
     * 
     * @param int $post_id
     */
    public static function cleanup_meta_fields(int $post_id): void
    {
        $post_type = get_post_type($post_id);
        if ($post_type !== self::$post_type) {
            return;
        }
        
        $expected_fields = [
            '_company', '_director', '_street', '_zip', '_city', '_country',
            '_address', '_phone', '_mobile', '_fax', '_email', '_tax_id', 
            '_vat', '_hrb', '_court', '_dsb', '_opening', '_maplink', '_contact_type'
        ];
        
        \SFX\MetaFieldManager::cleanup_fields($post_id, self::$post_type, $expected_fields);
    }

    /**
     * Register the meta box for contact info configuration fields.
     */
    public static function register_meta_box(): void
    {
        add_meta_box(
            'sfx_contact_info_config',
            __('Contact Information Configuration', 'sfx-bricks-child'),
            [self::class, 'render_meta_box'],
            self::$post_type,
            'normal',
            'high'
        );
    }

    /**
     * Render the meta box UI for contact info configuration fields.
     *
     * @param \WP_Post $post
     */
    public static function render_meta_box($post): void
    {
        wp_nonce_field('sfx_contact_info_config_nonce', 'sfx_contact_info_config_nonce');
        
        // Batch retrieve all meta values in one query instead of 20+ individual calls
        $meta_keys = [
            '_contact_type', '_company', '_director', '_street', '_zip', '_city', '_country',
            '_address', '_phone', '_mobile', '_fax', '_email', '_tax_id', '_vat', '_hrb',
            '_court', '_dsb', '_opening', '_maplink'
        ];
        
        $all_meta = get_post_meta($post->ID, '', true);
        $contact_data = array_intersect_key($all_meta, array_flip($meta_keys));
        
        // Extract values with defaults and ensure they are strings
        $contact_type = is_array($contact_data['_contact_type'] ?? '') ? implode(', ', $contact_data['_contact_type']) : ($contact_data['_contact_type'] ?? 'main');
        $company = is_array($contact_data['_company'] ?? '') ? implode(', ', $contact_data['_company']) : ($contact_data['_company'] ?? '');
        $director = is_array($contact_data['_director'] ?? '') ? implode(', ', $contact_data['_director']) : ($contact_data['_director'] ?? '');
        $street = is_array($contact_data['_street'] ?? '') ? implode(', ', $contact_data['_street']) : ($contact_data['_street'] ?? '');
        $zip = is_array($contact_data['_zip'] ?? '') ? implode(', ', $contact_data['_zip']) : ($contact_data['_zip'] ?? '');
        $city = is_array($contact_data['_city'] ?? '') ? implode(', ', $contact_data['_city']) : ($contact_data['_city'] ?? '');
        $country = is_array($contact_data['_country'] ?? '') ? implode(', ', $contact_data['_country']) : ($contact_data['_country'] ?? '');
        $address = is_array($contact_data['_address'] ?? '') ? implode(', ', $contact_data['_address']) : ($contact_data['_address'] ?? '');
        $phone = is_array($contact_data['_phone'] ?? '') ? implode(', ', $contact_data['_phone']) : ($contact_data['_phone'] ?? '');
        $mobile = is_array($contact_data['_mobile'] ?? '') ? implode(', ', $contact_data['_mobile']) : ($contact_data['_mobile'] ?? '');
        $fax = is_array($contact_data['_fax'] ?? '') ? implode(', ', $contact_data['_fax']) : ($contact_data['_fax'] ?? '');
        $email = is_array($contact_data['_email'] ?? '') ? implode(', ', $contact_data['_email']) : ($contact_data['_email'] ?? '');
        $tax_id = is_array($contact_data['_tax_id'] ?? '') ? implode(', ', $contact_data['_tax_id']) : ($contact_data['_tax_id'] ?? '');
        $vat = is_array($contact_data['_vat'] ?? '') ? implode(', ', $contact_data['_vat']) : ($contact_data['_vat'] ?? '');
        $hrb = is_array($contact_data['_hrb'] ?? '') ? implode(', ', $contact_data['_hrb']) : ($contact_data['_hrb'] ?? '');
        $court = is_array($contact_data['_court'] ?? '') ? implode(', ', $contact_data['_court']) : ($contact_data['_court'] ?? '');
        $dsb = is_array($contact_data['_dsb'] ?? '') ? implode(', ', $contact_data['_dsb']) : ($contact_data['_dsb'] ?? '');
        $opening = is_array($contact_data['_opening'] ?? '') ? implode(', ', $contact_data['_opening']) : ($contact_data['_opening'] ?? '');
        $maplink = is_array($contact_data['_maplink'] ?? '') ? implode(', ', $contact_data['_maplink']) : ($contact_data['_maplink'] ?? '');
        ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="contact_type"><?php esc_html_e('Contact Type', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <select name="contact_type" id="contact_type">
                        <option value="main" <?php selected($contact_type, 'main'); ?>><?php esc_html_e('Main Contact', 'sfx-bricks-child'); ?></option>
                        <option value="branch" <?php selected($contact_type, 'branch'); ?>><?php esc_html_e('Branch/Location', 'sfx-bricks-child'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Select whether this is the main contact or a branch/location.', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php esc_html_e('Company Information', 'sfx-bricks-child'); ?></h3>
                </th>
            </tr>
            
            <tr id="company-fields" class="conditional-field" data-show-for="main">
                <th scope="row">
                    <label for="company"><?php esc_html_e('Company Name', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="company" id="company" value="<?php echo esc_attr($company); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Name of the company', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="director"><?php esc_html_e('Managing Director(s)', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="director" id="director" value="<?php echo esc_attr($director); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Name of the managing director(s)', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php esc_html_e('Address Information', 'sfx-bricks-child'); ?></h3>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="street"><?php esc_html_e('Street / No.', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="street" id="street" value="<?php echo esc_attr($street); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Street name and number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="zip"><?php esc_html_e('ZIP Code', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="zip" id="zip" value="<?php echo esc_attr($zip); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Postal code', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="city"><?php esc_html_e('City', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="city" id="city" value="<?php echo esc_attr($city); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('City/Town', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="country"><?php esc_html_e('Country', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="country" id="country" value="<?php echo esc_attr($country); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Country', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="address"><?php esc_html_e('Formatted Address', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <?php 
                    wp_editor(
                        $address,
                        'address',
                        [
                            'textarea_name' => 'address',
                            'textarea_rows' => 5,
                            'media_buttons' => false,
                            'teeny' => true,
                            'tinymce' => [
                                'toolbar1' => 'bold,italic,underline,|,bullist,numlist,|,link,unlink',
                                'toolbar2' => '',
                                'toolbar3' => '',
                            ],
                        ]
                    );
                    ?>
                    <p class="description"><?php esc_html_e('Formatted address for display', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php esc_html_e('Contact Details', 'sfx-bricks-child'); ?></h3>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="phone"><?php esc_html_e('Phone', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="phone" id="phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Phone number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="mobile"><?php esc_html_e('Mobile', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="mobile" id="mobile" value="<?php echo esc_attr($mobile); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Mobile number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="fax"><?php esc_html_e('Fax', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="fax" id="fax" value="<?php echo esc_attr($fax); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Fax number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="email"><?php esc_html_e('Email', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="email" name="email" id="email" value="<?php echo esc_attr($email); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Email address', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr id="business-section" class="conditional-field" data-show-for="main">
                <th scope="row" colspan="2">
                    <h3><?php esc_html_e('Business Information', 'sfx-bricks-child'); ?></h3>
                </th>
            </tr>
            
            <tr id="tax-id-fields" class="conditional-field" data-show-for="main">
                <th scope="row">
                    <label for="tax_id"><?php esc_html_e('Tax ID', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="tax_id" id="tax_id" value="<?php echo esc_attr($tax_id); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Tax identification number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr id="vat-fields" class="conditional-field" data-show-for="main">
                <th scope="row">
                    <label for="vat"><?php esc_html_e('VAT ID', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="vat" id="vat" value="<?php echo esc_attr($vat); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('VAT identification number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr id="hrb-fields" class="conditional-field" data-show-for="main">
                <th scope="row">
                    <label for="hrb"><?php esc_html_e('Company Registration No.', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="hrb" id="hrb" value="<?php echo esc_attr($hrb); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Commercial register number', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr id="court-fields" class="conditional-field" data-show-for="main">
                <th scope="row">
                    <label for="court"><?php esc_html_e('Registration Court', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="court" id="court" value="<?php echo esc_attr($court); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Responsible registration court', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr id="dsb-fields" class="conditional-field" data-show-for="main">
                <th scope="row">
                    <label for="dsb"><?php esc_html_e('Data Protection Officer', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="text" name="dsb" id="dsb" value="<?php echo esc_attr($dsb); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Name of the data protection officer', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row" colspan="2">
                    <h3><?php esc_html_e('Additional Information', 'sfx-bricks-child'); ?></h3>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="opening"><?php esc_html_e('Opening Hours', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <?php 
                    wp_editor(
                        $opening,
                        'opening',
                        [
                            'textarea_name' => 'opening',
                            'textarea_rows' => 5,
                            'media_buttons' => false,
                            'teeny' => true,
                            'tinymce' => [
                                'toolbar1' => 'bold,italic,underline,|,bullist,numlist,|,link,unlink',
                                'toolbar2' => '',
                                'toolbar3' => '',
                            ],
                        ]
                    );
                    ?>
                    <p class="description"><?php esc_html_e('Business hours of the company', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="maplink"><?php esc_html_e('Google Maps Link', 'sfx-bricks-child'); ?></label>
                </th>
                <td>
                    <input type="url" name="maplink" id="maplink" value="<?php echo esc_url($maplink); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Link to Google Maps', 'sfx-bricks-child'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the custom fields for the contact info post type.
     *
     * @param int $post_id
     */
    public static function save_custom_fields(int $post_id): void
    {
        if (!isset($_POST['sfx_contact_info_config_nonce']) || !wp_verify_nonce($_POST['sfx_contact_info_config_nonce'], 'sfx_contact_info_config_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save contact info configuration fields
        $contact_type = sanitize_text_field($_POST['contact_type'] ?? 'main');
        
        $fields = [
            'contact_type' => $contact_type,
            'director' => sanitize_text_field($_POST['director'] ?? ''),
            'street' => sanitize_text_field($_POST['street'] ?? ''),
            'zip' => sanitize_text_field($_POST['zip'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'address' => wp_kses_post($_POST['address'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'mobile' => sanitize_text_field($_POST['mobile'] ?? ''),
            'fax' => sanitize_text_field($_POST['fax'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'opening' => wp_kses_post($_POST['opening'] ?? ''),
            'maplink' => esc_url_raw($_POST['maplink'] ?? ''),
        ];
        
        // Only save company and business fields for main contact type
        if ($contact_type === 'main') {
            $fields['company'] = sanitize_text_field($_POST['company'] ?? '');
            $fields['tax_id'] = sanitize_text_field($_POST['tax_id'] ?? '');
            $fields['vat'] = sanitize_text_field($_POST['vat'] ?? '');
            $fields['hrb'] = sanitize_text_field($_POST['hrb'] ?? '');
            $fields['court'] = sanitize_text_field($_POST['court'] ?? '');
            $fields['dsb'] = sanitize_text_field($_POST['dsb'] ?? '');
        } else {
            // Clear business fields for branch type
            $fields['company'] = '';
            $fields['tax_id'] = '';
            $fields['vat'] = '';
            $fields['hrb'] = '';
            $fields['court'] = '';
            $fields['dsb'] = '';
        }

        foreach ($fields as $key => $value) {
            update_post_meta($post_id, '_' . $key, $value);
        }
    }

    /**
     * Add a custom column for contact type.
     *
     * @param array $columns
     * @return array
     */
    public static function add_type_column(array $columns): array
    {
        $date = $columns['date'] ?? null;
        unset($columns['date']);
        $columns['contact_type'] = __('Type', 'sfx-bricks-child');
        if ($date !== null) {
            $columns['date'] = $date;
        }
        return $columns;
    }

    /**
     * Add a custom column for address.
     *
     * @param array $columns
     * @return array
     */
    public static function add_address_column(array $columns): array
    {
        $columns['address'] = __('Address', 'sfx-bricks-child');
        return $columns;
    }

    /**
     * Add a custom column for contact details.
     *
     * @param array $columns
     * @return array
     */
    public static function add_contact_column(array $columns): array
    {
        $columns['contact'] = __('Contact', 'sfx-bricks-child');
        return $columns;
    }

    /**
     * Add a custom column for status.
     *
     * @param array $columns
     * @return array
     */
    public static function add_status_column(array $columns): array
    {
        $columns['status'] = __('Status', 'sfx-bricks-child');
        return $columns;
    }

    /**
     * Render the contact type column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_type_column(string $column, int $post_id): void
    {
        if ($column === 'contact_type') {
            $contact_type = get_post_meta($post_id, '_contact_type', true) ?: 'main';
            $type_labels = [
                'main' => __('Main Contact', 'sfx-bricks-child'),
                'branch' => __('Branch/Location', 'sfx-bricks-child'),
            ];
            echo '<span class="contact-type-' . esc_attr($contact_type) . '">' . esc_html($type_labels[$contact_type] ?? $contact_type) . '</span>';
        }
    }

    /**
     * Render address column with optimized batch meta retrieval
     * 
     * @param string $column
     * @param int $post_id
     * @return void
     */
    public static function render_address_column(string $column, int $post_id): void
    {
        if ($column === 'address') {
            // Get individual meta values to ensure we get strings, not arrays
            $street = self::get_translated_field($post_id, 'street', get_post_meta($post_id, '_street', true) ?: '');
            $zip = self::get_translated_field($post_id, 'zip', get_post_meta($post_id, '_zip', true) ?: '');
            $city = self::get_translated_field($post_id, 'city', get_post_meta($post_id, '_city', true) ?: '');
            $country = self::get_translated_field($post_id, 'country', get_post_meta($post_id, '_country', true) ?: '');
            
            $address_parts = [];
            if ($street) $address_parts[] = $street;
            if ($zip && $city) {
                $address_parts[] = $zip . ' ' . $city;
            } elseif ($city) {
                $address_parts[] = $city;
            }
            if ($country) $address_parts[] = $country;
            
            if (!empty($address_parts)) {
                echo '<div class="contact-address">';
                echo '<strong>' . esc_html__('Address:', 'sfx-bricks-child') . '</strong><br>';
                echo esc_html(implode(', ', $address_parts));
                echo '</div>';
            } else {
                echo '<span class="no-data">' . esc_html__('No address data', 'sfx-bricks-child') . '</span>';
            }
        }
    }

    /**
     * Render contact column with optimized batch meta retrieval
     * 
     * @param string $column
     * @param int $post_id
     * @return void
     */
    public static function render_contact_column(string $column, int $post_id): void
    {
        if ($column === 'contact') {
            // Get individual meta values to ensure we get strings, not arrays
            $phone = self::get_translated_field($post_id, 'phone', get_post_meta($post_id, '_phone', true) ?: '');
            $email = self::get_translated_field($post_id, 'email', get_post_meta($post_id, '_email', true) ?: '');
            
            $contact_parts = [];
            
            if ($phone) {
                $contact_parts[] = '<span class="contact-phone">📞 ' . esc_html($phone) . '</span>';
            }
            
            if ($email) {
                $contact_parts[] = '<span class="contact-email">✉️ ' . esc_html($email) . '</span>';
            }
            
            if (!empty($contact_parts)) {
                echo '<div class="contact-details">';
                echo implode('<br>', $contact_parts);
                echo '</div>';
            } else {
                echo '<span class="no-data">' . esc_html__('No contact data', 'sfx-bricks-child') . '</span>';
            }
        }
    }

    /**
     * Render the status column content.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function render_status_column(string $column, int $post_id): void
    {
        if ($column === 'status') {
            $post = get_post($post_id);
            $status = $post->post_status;
            $status_labels = [
                'publish' => __('Active', 'sfx-bricks-child'),
                'draft' => __('Draft', 'sfx-bricks-child'),
                'private' => __('Private', 'sfx-bricks-child'),
            ];
            echo '<span class="status-' . esc_attr($status) . '">' . esc_html($status_labels[$status] ?? $status) . '</span>';
        }
    }

    /**
     * Remove the date column.
     *
     * @param array $columns
     * @return array
     */
    public static function remove_date_column(array $columns): array
    {
        unset($columns['date']);
        return $columns;
    }

    /**
     * Register multilingual support for the post type.
     */
    public static function register_multilingual_support(): void
    {
        // Polylang support
        if (function_exists('pll_register_string')) {
            add_action('add_meta_boxes', [self::class, 'register_polylang_strings']);
        }
        
        // WPML support
        if (defined('ICL_SITEPRESS_VERSION')) {
            add_action('add_meta_boxes', [self::class, 'register_wpml_strings']);
        }
    }

    /**
     * Register Polylang strings for translation.
     */
    public static function register_polylang_strings(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::$post_type) {
            return;
        }

        // Register meta fields for translation
        $meta_fields = [
            'company', 'director', 'street', 'zip', 'city', 'country',
            'phone', 'mobile', 'fax', 'email', 'tax_id', 'vat', 'hrb', 'court', 'dsb'
        ];

        foreach ($meta_fields as $field) {
            add_action('save_post_' . self::$post_type, function($post_id) use ($field) {
                $value = get_post_meta($post_id, '_' . $field, true);
                if (!empty($value)) {
                    pll_register_string(
                        'contact_info_' . $field . '_' . $post_id,
                        $value,
                        'Contact Information'
                    );
                }
            });
        }
    }

    /**
     * Register WPML strings for translation.
     */
    public static function register_wpml_strings(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::$post_type) {
            return;
        }

        // Register meta fields for translation
        $meta_fields = [
            'company', 'director', 'street', 'zip', 'city', 'country',
            'phone', 'mobile', 'fax', 'email', 'tax_id', 'vat', 'hrb', 'court', 'dsb'
        ];

        foreach ($meta_fields as $field) {
            add_action('save_post_' . self::$post_type, function($post_id) use ($field) {
                $value = get_post_meta($post_id, '_' . $field, true);
                if (!empty($value)) {
                    do_action('wpml_register_single_string', 
                        'Contact Information', 
                        'contact_info_' . $field . '_' . $post_id, 
                        $value
                    );
                }
            });
        }
    }

    /**
     * Get translated field value with optional default
     * 
     * @param int $post_id
     * @param string $field
     * @param string $default
     * @return string
     */
    public static function get_translated_field(int $post_id, string $field, string $default = ''): string
    {
        // If no default provided, get from database
        if (empty($default)) {
            $value = get_post_meta($post_id, '_' . $field, true) ?: '';
        } else {
            $value = $default;
        }
        
        if (empty($value)) {
            return '';
        }
        
        // Polylang support
        if (function_exists('pll__')) {
            $translated_value = pll__($value);
            if ($translated_value !== $value) {
                return $translated_value;
            }
        }
        
        // WPML support
        if (defined('ICL_SITEPRESS_VERSION')) {
            $translated_value = icl_t('sfx_contact_info', $field . '_' . $post_id, $value);
            if ($translated_value !== $value) {
                return $translated_value;
            }
        }
        
        return $value;
    }
} 