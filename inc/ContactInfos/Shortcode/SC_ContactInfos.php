<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Shortcode;

/**
 * Contact Infos Shortcode
 * 
 * Provides a shortcode to display contact information from post types
 *
 * @package WordPress
 * @subpackage sfxtheme
 * @since 1.0.0
 *
 */

defined('ABSPATH') || exit;

class SC_ContactInfos
{
    /**
     * Class constructor
     * Register the shortcode and cache invalidation hooks
     */
    public function __construct()
    {
        add_shortcode('contact_info', [$this, 'render_contact_info']);
        
        // Clear caches when contact info posts are updated
        add_action('save_post_sfx_contact_info', [$this, 'clear_contact_info_caches']);
        add_action('delete_post_sfx_contact_info', [$this, 'clear_contact_info_caches']);
        

    }
    
    /**
     * Clear contact info caches when posts are updated
     * 
     * @param int $post_id
     */
    public function clear_contact_info_caches(int $post_id): void
    {
        // Clear type-based caches
        delete_transient('sfx_contact_info_type_main');
        delete_transient('sfx_contact_info_type_branch');
        
        // Clear field-specific caches for this post
        $meta_keys = ['company', 'director', 'street', 'zip', 'city', 'country', 'address', 'phone', 'mobile', 'fax', 'email', 'tax_id', 'vat', 'hrb', 'court', 'dsb', 'opening', 'maplink'];
        
        foreach ($meta_keys as $field) {
            delete_transient('sfx_contact_info_' . $post_id . '_' . $field);
        }
        
        // Also clear any cached values for this post
        delete_transient('sfx_contact_info_' . $post_id . '_all');
        

    }

    /**
     * Contact Info Fields from Post Types
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     * 
     * Usage: [contact_info field="fieldname" contact_id="123"]
     */
    public function render_contact_info($atts)
    {
        // Attributes
        $atts = shortcode_atts(
            [
                'field'      => null,     // Field name to display
                'contact_id' => null,     // Specific contact post ID
                'type'       => 'main',   // Contact type: main or branch
                'icon'       => null,     // Icon to display before the field
                'icon_class' => null,     // CSS classes for the icon
                'text'       => null,     // Custom text instead of the field value
                'class'      => null,     // CSS classes for the wrapper
                'link'       => 'true',   // Whether to make the value a link (for email, phone, etc.)
                'debug'      => null,     // Debug mode to show raw value
            ],
            $atts,
            'contact_info'
        );

        // Go back if no field
        if (empty($atts['field'])) {
            return '';
        }

        // Process classes
        $classes = $this->process_classes($atts['class']);
        $icon_classes = $this->process_classes($atts['icon_class']);

        // Set up icon and text
        $icon = !empty($atts['icon']) ? do_shortcode('[icon class="branch-info" icon="' . $atts['icon'] . '" pos="before" class="' . $icon_classes . '"]') : '';
        $text = !empty($atts['text']) ? $atts['text'] : null;

        // Get field value
        $value = $this->get_field_value($atts['field'], $atts['contact_id'], $atts['type']);

        // Debug mode - show raw value
        if (!empty($atts['debug'])) {
            return '<pre>' . htmlspecialchars($value) . '</pre>';
        }

        // Handle link attribute properly - check for 'false' string
        $has_link = !in_array(strtolower($atts['link']), ['false', '0', 'no', 'off'], true);

        // Process different field types
        switch ($atts['field']) {
            case 'email':
                if (empty($value)) {
                    return '';
                }
                return $this->render_email_field($value, $atts, $icon, $text, $has_link);

            case 'mobile':
            case 'phone':
                if (empty($value)) {
                    return '';
                }
                return $this->render_phone_field($value, $atts, $icon, $has_link);

            case 'address':
                // For address field, we need to get the contact_id from the field value retrieval context
                $contact_id = $atts['contact_id'] ?? null;
                if (!$contact_id) {
                    // Try to find contact by type if no specific ID provided
                    $type = $atts['type'] ?? 'main';
                    $type_cache_key = 'sfx_contact_info_type_' . $type;
                    $contact_id = get_transient($type_cache_key);
                    
                    if ($contact_id === false) {
                        $args = [
                            'post_type' => 'sfx_contact_info',
                            'post_status' => 'publish',
                            'posts_per_page' => 1,
                            'meta_query' => [
                                [
                                    'key' => '_contact_type',
                                    'value' => $type,
                                    'compare' => '='
                                ]
                            ]
                        ];
                        
                        $query = new \WP_Query($args);
                        
                        if ($query->have_posts()) {
                            $contact_id = $query->posts[0]->ID;
                            set_transient($type_cache_key, $contact_id, HOUR_IN_SECONDS);
                        }
                    }
                }
                
                // Ensure contact_id is properly cast to integer or null
                if ($contact_id !== null) {
                    $contact_id = (int) $contact_id;
                    if ($contact_id <= 0) {
                        $contact_id = null;
                    }
                }
                
                return $this->render_address_field($value, $atts, $icon, $contact_id);

            case 'opening':
                return $this->render_opening_field($value, $atts, $icon);

            case 'maplink':
                if (empty($value)) {
                    return '';
                }
                return $this->render_maplink_field($value, $atts, $icon);

            default:
                if (empty($value)) {
                    return '';
                }
                return $this->render_default_field($value, $atts, $icon);
        }
    }

    /**
     * Process CSS classes string into array
     * 
     * @param string|null $classes_string
     * @return array
     */
    private function process_classes($classes_string): array
    {
        if (empty($classes_string)) {
            return [];
        }

        $classes = explode(' ', $classes_string);
        $classes = array_map('trim', $classes);
        $classes = array_filter($classes);

        return $classes;
    }

    /**
     * Get field value with optimized batch meta retrieval and caching
     * 
     * @param string $field
     * @param int|null $contact_id
     * @param string $type
     * @return string
     */
    private function get_field_value(string $field, ?int $contact_id = null, string $type = 'main'): string
    {
        // Create cache key
        $cache_key = 'sfx_contact_info_' . ($contact_id ?? 'type_' . $type) . '_' . $field;
        $cached_value = get_transient($cache_key);
        
        if ($cached_value !== false) {
            return $cached_value;
        }
        
        // If no specific contact ID, try to find by type
        if (!$contact_id) {
            $type_cache_key = 'sfx_contact_info_type_' . $type;
            $contact_id = get_transient($type_cache_key);
            
            if ($contact_id === false) {
                $args = [
                    'post_type' => 'sfx_contact_info',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'meta_query' => [
                        [
                            'key' => '_contact_type',
                            'value' => $type,
                            'compare' => '='
                        ]
                    ]
                ];
                
                $query = new \WP_Query($args);
                
                if ($query->have_posts()) {
                    $contact_id = $query->posts[0]->ID;
                    // Cache the contact ID for this type for 1 hour
                    set_transient($type_cache_key, $contact_id, HOUR_IN_SECONDS);
                } else {
                    return '';
                }
            } else {
                // Ensure contact_id from cache is properly cast to integer
                $contact_id = (int) $contact_id;
            }
        }
        

        
        // Ensure contact_id is always an integer
        $contact_id = (int) $contact_id;
        
        // Validate contact_id
        if ($contact_id <= 0) {
            return '';
        }
        
        // Batch retrieve all meta values for this contact in one query
        $meta_keys = [
            '_company', '_director', '_street', '_zip', '_city', '_country',
            '_address', '_phone', '_mobile', '_fax', '_email', '_tax_id', '_vat', '_hrb',
            '_court', '_dsb', '_opening', '_maplink'
        ];
        
        $all_meta = get_post_meta($contact_id, '', true);
        $contact_data = array_intersect_key($all_meta, array_flip($meta_keys));
        
        // Get the specific field value with translation support
        $meta_key = '_' . $field;
        $value = $contact_data[$meta_key] ?? '';
        

        

        

        
        // Ensure value is always a string
        if (is_array($value)) {
            $value = implode(', ', $value);
        } else {
            $value = (string) $value;
        }
        
        // Apply translation if available
        if (!empty($value)) {
            $value = \SFX\ContactInfos\PostType::get_translated_field($contact_id, $field, $value);
        }
        
        // Cache the result for 30 minutes
        set_transient($cache_key, $value, 30 * MINUTE_IN_SECONDS);
        
        return $value;
    }



    /**
     * Convert meta value to string, handling arrays and other types
     * 
     * @param mixed $value
     * @return string
     */
    private function convert_meta_to_string($value): string
    {
        if (is_array($value)) {
            // Handle nested arrays by flattening them
            $flattened = [];
            array_walk_recursive($value, function($item) use (&$flattened) {
                if (!empty($item)) {
                    $flattened[] = $item;
                }
            });
            return implode(', ', $flattened);
        }
        
        return (string) $value;
    }

    /**
     * Render email field with link
     * 
     * @param string $value
     * @param array $atts
     * @param string $icon
     * @param string|null $text
     * @param bool $has_link
     * @return string
     */
    private function render_email_field(string $value, array $atts, string $icon, ?string $text, bool $has_link): string
    {
        $classes = $this->process_classes($atts['class']);
        $classes[] = 'contact-info-email';

        $display_text = $text ?: $value;
        $output = '<span class="' . esc_attr(implode(' ', $classes)) . '">';

        if ($icon) {
            $output .= $icon;
        }

        if ($has_link) {
            $output .= '<a href="mailto:' . esc_attr($value) . '">' . esc_html($display_text) . '</a>';
        } else {
            $output .= esc_html($display_text);
        }

        $output .= '</span>';

        return $output;
    }

    /**
     * Render phone field with link
     * 
     * @param string $value
     * @param array $atts
     * @param string $icon
     * @param bool $has_link
     * @return string
     */
    private function render_phone_field(string $value, array $atts, string $icon, bool $has_link): string
    {
        $classes = $this->process_classes($atts['class']);
        $classes[] = 'contact-info-phone';

        $output = '<span class="' . esc_attr(implode(' ', $classes)) . '">';

        if ($icon) {
            $output .= $icon;
        }

        if ($has_link) {
            $output .= '<a href="tel:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
        } else {
            $output .= esc_html($value);
        }

        $output .= '</span>';

        return $output;
    }

    /**
     * Render address field
     * 
     * @param string $value
     * @param array $atts
     * @param string $icon
     * @param int|null $contact_id
     * @return string
     */
    private function render_address_field(string $value, array $atts, string $icon, $contact_id): string
    {
        // Ensure contact_id is properly typed
        if ($contact_id !== null) {
            $contact_id = (int) $contact_id;
            if ($contact_id <= 0) {
                $contact_id = null;
            }
        }
        
        $classes = $this->process_classes($atts['class']);
        $classes[] = 'contact-info-address';

        $output = '';

        if ($icon) {
            $output .= $icon;
        }



        // If we have a formatted address, use it (allow HTML content from WYSIWYG editor)
        if (!empty($value)) {
            $output .= wp_kses_post($value);
        } else {
            // Build address from individual fields using batch meta data
            $address_parts = [];
            
            if ($contact_id) {
                // Use batch meta retrieval for address fields
                $address_meta_keys = ['_street', '_zip', '_city', '_country'];
                $all_meta = get_post_meta($contact_id, '', true);
                $address_data = array_intersect_key($all_meta, array_flip($address_meta_keys));
                

                
                $street = $address_data['_street'] ?? '';
                $zip = $address_data['_zip'] ?? '';
                $city = $address_data['_city'] ?? '';
                $country = $address_data['_country'] ?? '';
                
                // Convert arrays to strings with better handling
                $street = $this->convert_meta_to_string($street);
                $zip = $this->convert_meta_to_string($zip);
                $city = $this->convert_meta_to_string($city);
                $country = $this->convert_meta_to_string($country);
                

                
                if ($street) $address_parts[] = $street;
                if ($zip && $city) {
                    $address_parts[] = $zip . ' ' . $city;
                } elseif ($city) {
                    $address_parts[] = $city;
                }
                if ($country) $address_parts[] = $country;
                

            } else {
                // Fallback: try to get individual address fields directly
                
                // Get individual address fields using the same method as other fields
                $street = $this->get_field_value('street', $contact_id, $atts['type'] ?? 'main');
                $zip = $this->get_field_value('zip', $contact_id, $atts['type'] ?? 'main');
                $city = $this->get_field_value('city', $contact_id, $atts['type'] ?? 'main');
                $country = $this->get_field_value('country', $contact_id, $atts['type'] ?? 'main');
                

                
                if ($street) $address_parts[] = $street;
                if ($zip && $city) {
                    $address_parts[] = $zip . ' ' . $city;
                } elseif ($city) {
                    $address_parts[] = $city;
                }
                if ($country) $address_parts[] = $country;
            }
            
            // For non-WYSIWYG address, wrap in span with classes
            if (!empty($address_parts)) {
                $output .= '<span class="' . esc_attr(implode(' ', $classes)) . '">';
                $output .= implode('<br>', array_map('esc_html', $address_parts));
                $output .= '</span>';
            }
        }

        return $output;
    }

    /**
     * Render opening hours field (allows HTML content from WYSIWYG editor)
     * 
     * @param string $value
     * @param array $atts
     * @param string $icon
     * @return string
     */
    private function render_opening_field(string $value, array $atts, string $icon): string
    {
        $output = '';

        if ($icon) {
            $output .= $icon;
        }

        // Allow HTML content from WYSIWYG editor without wrapping in span
        if (!empty($value)) {
            $output .= wp_kses_post($value);
        }

        return $output;
    }

    /**
     * Render map link field
     * 
     * @param string $value
     * @param array $atts
     * @param string $icon
     * @return string
     */
    private function render_maplink_field(string $value, array $atts, string $icon): string
    {
        $classes = $this->process_classes($atts['class']);
        $classes[] = 'contact-info-maplink';

        $output = '<span class="' . esc_attr(implode(' ', $classes)) . '">';

        if ($icon) {
            $output .= $icon;
        }

        $output .= '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View on Map', 'sfxtheme') . '</a>';

        $output .= '</span>';

        return $output;
    }

    /**
     * Render default field
     * 
     * @param string $value
     * @param array $atts
     * @param string $icon
     * @return string
     */
    private function render_default_field(string $value, array $atts, string $icon): string
    {
        $classes = $this->process_classes($atts['class']);
        $classes[] = 'contact-info-field';



        $output = '<span class="' . esc_attr(implode(' ', $classes)) . '">';

        if ($icon) {
            $output .= $icon;
        }

        $output .= esc_html($value);

        $output .= '</span>';

        return $output;
    }

}
