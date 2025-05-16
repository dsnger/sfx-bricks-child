<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class ContactInfos
{
    private static string $option_key = 'sfx_contact_infos';
    private static ?array $contact_data = null;

    public static function init(): void
    {
        // Register settings
        Settings::register(self::$option_key);
        
        // Add admin page
        AdminPage::register();
        
        // Register shortcodes
        add_shortcode('contact', [self::class, 'render_contact_shortcode']);
        add_shortcode('contact_branch', [self::class, 'render_branch_shortcode']);
    }

    /**
     * Get all contact data.
     */
    public static function get_data(): array
    {
        if (self::$contact_data === null) {
            self::$contact_data = get_option(self::$option_key, []);
        }
        
        return self::$contact_data;
    }

    /**
     * Get a specific contact field.
     */
    public static function get_field(string $field, $default = ''): string
    {
        $data = self::get_data();
        return isset($data[$field]) ? $data[$field] : $default;
    }

    /**
     * Get branch data.
     */
    public static function get_branch(int $branch_id): array
    {
        $data = self::get_data();
        $branches = $data['branches'] ?? [];
        
        return isset($branches[$branch_id]) ? $branches[$branch_id] : [];
    }

    /**
     * Get a specific branch field.
     */
    public static function get_branch_field(int $branch_id, string $field, $default = ''): string
    {
        $branch = self::get_branch($branch_id);
        return isset($branch[$field]) ? $branch[$field] : $default;
    }

    /**
     * Render the contact shortcode.
     */
    public static function render_contact_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'field' => '',
            'default' => '',
        ], $atts, 'contact');
        
        if (empty($atts['field'])) {
            return '';
        }
        
        $value = self::get_field($atts['field'], $atts['default']);
        
        // Handle special fields
        switch ($atts['field']) {
            case 'email':
                return '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
            
            case 'phone':
            case 'mobile':
            case 'fax':
                $clean_number = preg_replace('/[^0-9+]/', '', $value);
                return '<a href="tel:' . esc_attr($clean_number) . '">' . esc_html($value) . '</a>';
            
            case 'maplink':
                if (!empty($value)) {
                    return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . 
                        esc_html(self::get_field('street') . ', ' . self::get_field('zip') . ' ' . self::get_field('city')) . 
                    '</a>';
                }
                return esc_html(self::get_field('street') . ', ' . self::get_field('zip') . ' ' . self::get_field('city'));
                
            case 'address':
            case 'opening':
                return wp_kses_post($value);
                
            default:
                return esc_html($value);
        }
    }

    /**
     * Render the branch shortcode.
     */
    public static function render_branch_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'id' => 0,
            'field' => '',
            'default' => '',
        ], $atts, 'contact_branch');
        
        if (empty($atts['field'])) {
            return '';
        }
        
        $branch_id = intval($atts['id']);
        $value = self::get_branch_field($branch_id, $atts['field'], $atts['default']);
        
        // Handle special fields
        switch ($atts['field']) {
            case 'branch_email':
                return '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
            
            case 'branch_phone':
            case 'branch_mobile':
            case 'branch_fax':
                $clean_number = preg_replace('/[^0-9+]/', '', $value);
                return '<a href="tel:' . esc_attr($clean_number) . '">' . esc_html($value) . '</a>';
            
            case 'branch_maplink':
                $branch = self::get_branch($branch_id);
                if (!empty($value)) {
                    return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . 
                        esc_html($branch['branch_street'] . ', ' . $branch['branch_zip'] . ' ' . $branch['branch_city']) . 
                    '</a>';
                }
                return esc_html($branch['branch_street'] . ', ' . $branch['branch_zip'] . ' ' . $branch['branch_city']);
                
            case 'branch_address':
            case 'branch_opening':
                return wp_kses_post($value);
                
            case 'branch_pagelink':
                if (!empty($value)) {
                    $title = $branch['branch_title'] ?? __('Mehr Informationen', 'sfxtheme');
                    return '<a href="' . esc_url($value) . '">' . esc_html($title) . '</a>';
                }
                return '';
                
            default:
                return esc_html($value);
        }
    }
} 