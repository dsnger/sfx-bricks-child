<?php

namespace SFX\ContactInfos\Shortcode;

use SFX\ContactInfos\Settings;

/**
 * Contact Infos Shortcode
 * 
 * Provides a shortcode to display contact information from settings
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
     * Option name for storing contact settings
     */
    private string $option_name;

    /**
     * Contact settings data
     */
    private array $contact_data;

    /**
     * Class constructor
     * Register the shortcode
     */
    public function __construct()
    {
        add_shortcode('contact-info', [$this, 'render_contact_info']);

        // Get the option name from Settings class if available, otherwise default
        $this->option_name = defined('SFX\ContactInfos\Settings::$OPTION_NAME')
            ? Settings::$OPTION_NAME
            : 'contact_info_settings';

        // Get the contact data
        $this->contact_data = get_option($this->option_name, []);
    }

    /**
     * Contact Info Fields from Settings
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     * 
     * Usage: [contact-info field="fieldname" location="1"]
     */
    public function render_contact_info($atts)
    {
        // Attributes
        $atts = shortcode_atts(
            [
                'field'      => null,     // Field name to display
                'location'   => null,     // Index of branch location (starts at 0)
                'icon'       => null,     // Icon to display before the field
                'icon_class' => null,     // CSS classes for the icon
                'text'       => null,     // Custom text instead of the field value
                'class'      => null,     // CSS classes for the wrapper
                'link'       => 'true',   // Whether to make the value a link (for email, phone, etc.)
            ],
            $atts,
            'contact-info'
        );

        // Go back if no field
        if (empty($atts['field'])) {
            return '';
        }

        // Set up variables
        $location = $atts['location'];

        // Process classes
        $classes = $this->process_classes($atts['class']);
        $icon_classes = $this->process_classes($atts['icon_class']);

        // Set up icon and text
        $icon = !empty($atts['icon']) ? do_shortcode('[icon class="branch-info" icon="' . $atts['icon'] . '" pos="before" class="' . $icon_classes . '"]') : '';
        $text = !empty($atts['text']) ? $atts['text'] : null;

        // Get field value
        $value = $this->get_field_value($atts['field'], $location);

        // Return empty if no value found
        if (empty($value)) {
            return '';
        }

        $has_link = in_array($atts['link'], ['true', '1', 1, true], true);

        // Process different field types
        switch ($atts['field']) {
            case 'email':
                return $this->render_email_field($value, $atts, $icon, $text, $has_link);

            case 'mobile':
            case 'phone':
                return $this->render_phone_field($value, $atts, $icon, $has_link);

            case 'address':
                return $this->render_address_field($value, $atts, $icon, $location);

            case 'maplink':
                return $this->render_maplink_field($value, $atts, $icon);

            default:
                return $this->render_default_field($value, $atts, $icon);
        }
    }

    /**
     * Process CSS classes
     * 
     * @param string $classes_string Space-separated class string
     * @return string Sanitized class string
     */
    private function process_classes($classes_string)
    {
        if (empty($classes_string)) {
            return '';
        }

        $classes = explode(' ', $classes_string);
        $classes = array_map('sanitize_html_class', $classes);
        return implode(' ', $classes);
    }

    /**
     * Get field value based on field name and location
     * 
     * @param string $field Field name
     * @param string|null $location Location index
     * @return mixed Field value
     */
    private function get_field_value($field, $location = null)
    {
        // Get data from branches if location is specified
        if ($location !== null) {
            $location = (int) $location;

            if (
                isset($this->contact_data['branches']) &&
                isset($this->contact_data['branches'][$location]) &&
                isset($this->contact_data['branches'][$location]['branch_' . $field])
            ) {
                return $this->contact_data['branches'][$location]['branch_' . $field];
            }

            return null;
        }

        // Get data from main settings
        return isset($this->contact_data[$field]) ? $this->contact_data[$field] : null;
    }

    /**
     * Render email field
     */
    private function render_email_field($value, $atts, $icon, $text, $has_link)
    {
        $email = antispambot($value);
        $tooltip = !empty($text) ? 'uk-tooltip="' . $email . '"' : '';
        $text = !empty($text) ? $text : $email;
        $class = 'contact_info_' . $atts['field'] . ' ' . $atts['class'];

        if ($has_link) {
            return sprintf(
                '<a class="%s" href="mailto:%s" %s>%s%s</a>',
                $class,
                $email,
                $tooltip,
                $icon,
                $text
            );
        }

        return sprintf(
            '<span class="%s" %s>%s%s</span>',
            $class,
            $tooltip,
            $icon,
            $text
        );
    }

    /**
     * Render phone field
     */
    private function render_phone_field($value, $atts, $icon, $has_link)
    {
        $phone_string = $value;
        $phone_nr = !empty($phone_string) ? preg_replace('/[^\d+]/', '', $phone_string) : '';
        $class = 'contact_info_' . $atts['field'] . ' ' . $atts['class'];

        if ($has_link) {
            return sprintf(
                '<a class="%s" href="tel:%s">%s%s</a>',
                $class,
                $phone_nr,
                $icon,
                $phone_string
            );
        }

        return sprintf(
            '<span class="%s">%s%s</span>',
            $class,
            $icon,
            $phone_string
        );
    }

    /**
     * Render address field
     */
    private function render_address_field($value, $atts, $icon, $location)
    {
        $address = $value;
        $trimmed_address = $address ? trim($address) : null;

        if (empty($trimmed_address)) {
            // If no formatted address, attempt to build from components
            if ($location === null) {
                $street = $this->get_field_value('street');
                $zip = $this->get_field_value('zip');
                $city = $this->get_field_value('city');
            } else {
                $location = (int) $location;

                $street = $this->get_field_value('street', $location);
                $zip = $this->get_field_value('zip', $location);
                $city = $this->get_field_value('city', $location);
            }

            if (empty($street) && empty($city)) {
                return '';
            }

            $address = sprintf(
                '<span class="uk-text-nowrap">%s</span><br /><span class="uk-text-nowrap">%s %s</span>',
                esc_html($street),
                esc_html($zip),
                esc_html($city)
            );

            return $icon . $address;
        }

        // Allow HTML in formatted address
        return $icon . wp_kses_post($address);
    }

    /**
     * Render maplink field
     */
    private function render_maplink_field($value, $atts, $icon)
    {
        if (empty($value)) {
            return '';
        }

        $url = $value;
        $text = !empty($atts['text']) ? $atts['text'] : __('Google Maps', 'sfxtheme');
        $class = 'contact_info_' . $atts['field'] . ' ' . $atts['class'];

        return sprintf(
            '<a class="%s" href="%s" target="_blank">%s%s</a>',
            $class,
            esc_url($url),
            $icon,
            esc_html($text)
        );
    }

    /**
     * Render default field
     */
    private function render_default_field($value, $atts, $icon)
    {
        $class = 'contact_info_' . $atts['field'] . ' ' . $atts['class'];

        // Allow HTML in opening hours field
        if ($atts['field'] === 'opening') {
            return sprintf(
                '<span class="%s">%s%s</span>',
                $class,
                $icon,
                wp_kses_post($value)
            );
        }

        // Default handling for other fields
        return sprintf(
            '<span class="%s">%s%s</span>',
            $class,
            $icon,
            esc_html($value)
        );
    }
}
