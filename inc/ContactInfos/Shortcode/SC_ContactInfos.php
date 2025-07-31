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
     * Register the shortcode
     */
    public function __construct()
    {
        add_shortcode('contact_info', [$this, 'render_contact_info']);
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
                return $this->render_address_field($value, $atts, $icon, $atts['contact_id']);

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
     * Get field value from post type
     * 
     * @param string $field
     * @param int|null $contact_id
     * @param string $type
     * @return string
     */
    private function get_field_value(string $field, ?int $contact_id = null, string $type = 'main'): string
    {
        // If specific contact ID is provided
        if ($contact_id) {
            $post = get_post($contact_id);
            if ($post && $post->post_type === 'sfx_contact_info') {
                return \SFX\ContactInfos\PostType::get_translated_field($contact_id, $field);
            }
        }

        // Get contact info by type
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
            $query->the_post();
            $post_id = get_the_ID();
            $value = \SFX\ContactInfos\PostType::get_translated_field($post_id, $field);
            wp_reset_postdata();
            return $value;
        }

        return '';
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
    private function render_address_field(string $value, array $atts, string $icon, ?int $contact_id): string
    {
        $classes = $this->process_classes($atts['class']);
        $classes[] = 'contact-info-address';

        $output = '<span class="' . esc_attr(implode(' ', $classes)) . '">';

        if ($icon) {
            $output .= $icon;
        }

        // If we have a formatted address, use it
        if (!empty($value)) {
            $output .= nl2br(esc_html($value));
        } else {
            // Build address from individual fields
            $address_parts = [];
            
            if ($contact_id) {
                $street = get_post_meta($contact_id, '_street', true);
                $zip = get_post_meta($contact_id, '_zip', true);
                $city = get_post_meta($contact_id, '_city', true);
                $country = get_post_meta($contact_id, '_country', true);
                
                if ($street) $address_parts[] = $street;
                if ($zip && $city) {
                    $address_parts[] = $zip . ' ' . $city;
                } elseif ($city) {
                    $address_parts[] = $city;
                }
                if ($country) $address_parts[] = $country;
            }
            
            $output .= implode('<br>', array_map('esc_html', $address_parts));
        }

        $output .= '</span>';

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

        $output .= '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View on Map', 'sfx-bricks-child') . '</a>';

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
