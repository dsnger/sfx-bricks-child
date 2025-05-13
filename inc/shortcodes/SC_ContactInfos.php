<?php

namespace SFX\Shortcodes;

/**
 * Contact Infos Shortcode
 * 
 * Provides a shortcode to display contact information from ACF fields
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
        add_shortcode('contact-info', [$this, 'render_contact_info']);
    }

    /**
     * Contact Info Fields from ACF Option Page "Contact Infos"
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
                'field'      => null,
                'id'         => 'option',
                'location'   => null,
                'icon'       => null,
                'icon_class' => null,
                'text'       => null,
                'class'      => null,
                'link'       => 'true',
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
        $field_prefix = isset($atts['id']) && $atts['id'] !== 'option' ? 'branch' : 'contact_info';
        
        // Process classes
        $classes = $this->process_classes($atts['class']);
        $icon_classes = $this->process_classes($atts['icon_class']);
        
        // Set up icon and text
        $icon = !empty($atts['icon']) ? do_shortcode('[icon class="branch-info" icon="' . $atts['icon'] . '" pos="before" class="' . $icon_classes . '"]') : '';
        $text = !empty($atts['text']) ? $atts['text'] : null;

        // Get field value
        $value = $this->get_field_value($atts['field'], $atts['id'], $location, $field_prefix);
        
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
                return $this->render_address_field($value, $atts, $icon, $location, $field_prefix);
                
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
     * Get field value based on field name, ID and location
     * 
     * @param string $field Field name
     * @param string $id Field ID
     * @param string|null $location Location index
     * @param string $field_prefix Field prefix
     * @return mixed Field value
     */
    private function get_field_value($field, $id, $location, $field_prefix)
    {
        $value = get_field($field_prefix . '_' . $field, $id);
        
        if ($location !== null) {
            $location = (int) $location;
            $branches = get_field('contact_info', 'option');
            $value = isset($branches[$location]['branch_' . $field]) ? $branches[$location]['branch_' . $field] : null;
        }
        
        return $value;
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
    private function render_address_field($value, $atts, $icon, $location, $field_prefix)
    {
        $address = $value;
        $trimmed_address = $address ? trim($address) : null;
        
        if (empty($trimmed_address)) {
            $branches = null;
            
            if ($location === null) {
                $street = get_field($field_prefix . '_street', $atts['id']);
                $zip = get_field($field_prefix . '_zip', $atts['id']);
                $city = get_field($field_prefix . '_city', $atts['id']);
            } else {
                $branches = get_field('contact_info', 'option');
                $location = (int) $location;
                
                $street = isset($branches[$location]['branch_street']) ? $branches[$location]['branch_street'] : '';
                $zip = isset($branches[$location]['branch_zip']) ? $branches[$location]['branch_zip'] : '';
                $city = isset($branches[$location]['branch_city']) ? $branches[$location]['branch_city'] : '';
            }
            
            if (empty($street) && empty($city)) {
                return '';
            }
            
            $address = sprintf(
                '<span class="uk-text-nowrap">%s</span><br /><span class="uk-text-nowrap">%s %s</span>',
                $street,
                $zip,
                $city
            );
            
            return $icon . $address;
        }
        
        return $icon . str_replace(['<p>', '</p>'], '', $address);
    }
    
    /**
     * Render maplink field
     */
    private function render_maplink_field($value, $atts, $icon)
    {
        if (!$value || empty($value['url'])) {
            return '';
        }
        
        $text = !empty($value['title']) ? $value['title'] : __('Google Maps', 'sfxtheme');
        $class = 'contact_info_' . $atts['field'] . ' ' . $atts['class'];
        
        return sprintf(
            '<a class="%s" href="%s" target="_blank">%s%s</a>',
            $class,
            $value['url'],
            $icon,
            $text
        );
    }
    
    /**
     * Render default field
     */
    private function render_default_field($value, $atts, $icon)
    {
        $class = 'contact_info_' . $atts['field'] . ' ' . $atts['class'];
        
        return sprintf(
            '<span class="%s">%s%s</span>',
            $class,
            $icon,
            $value
        );
    }
} 