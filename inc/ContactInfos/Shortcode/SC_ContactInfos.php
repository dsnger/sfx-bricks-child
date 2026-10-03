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
     * Shortcode attribute defaults. Public so the admin help tab lists exactly these keys.
     */
    public const DEFAULT_ATTS = [
        'field'      => null,     // Field name to display
        'contact_id' => null,     // Specific contact post ID
        'type'       => 'main',   // Contact type: main or branch
        'icon'       => null,     // Icon to display before the field
        'icon_class' => null,     // CSS classes for the icon
        'text'       => null,     // Custom text instead of the field value
        'class'      => null,     // CSS classes for the wrapper (implicitly enables wrap)
        'link'       => 'true',   // Whether to make the value a link (for email, phone, etc.)
        'wrap'       => 'false',  // Whether to wrap the output in a tag (default: bare output)
        'tag'        => null,     // Wrapper tag (default 'span'); setting this implicitly enables wrap
        'debug'      => null,     // Debug mode to show raw value
        'format'     => null,     // 'tel': phone/mobile/fax as a bare tel: number (no link, icon or wrapper)
    ];

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
        // Writes that bypass save_post (e.g. the Order screen's direct menu_order update) still clean the post cache.
        add_action('clean_post_cache', [$this, 'clear_on_clean_post_cache'], 10, 2);
        

    }
    
    /**
     * @param int      $post_id
     * @param \WP_Post $post
     */
    public function clear_on_clean_post_cache($post_id, $post): void
    {
        if ($post instanceof \WP_Post && $post->post_type === 'sfx_contact_info') {
            $this->clear_contact_info_caches((int) $post_id);
        }
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
        $atts = shortcode_atts(self::DEFAULT_ATTS, $atts, 'contact_info');

        // Go back if no field
        if (empty($atts['field'])) {
            return '';
        }

        // Process classes
        $classes = $this->process_classes($atts['class']);
        $icon_classes = $this->process_classes($atts['icon_class']);

        // Set up icon and text
        // Through do_shortcode so the shortcode filters still apply. Brackets would end the
        // shortcode early, so they go in as entities. ponytail: right for handlers using esc_attr();
        // one that double-encodes (htmlspecialchars) shows &#091; literally - pass raw atts if that ever matters.
        // Without a registered [icon] there is no icon rather than its raw text.
        $shortcode_attr = static fn(string $v): string => str_replace(['[', ']'], ['&#91;', '&#93;'], esc_attr($v));
        $icon = !empty($atts['icon']) && shortcode_exists('icon')
            ? do_shortcode('[icon icon="' . $shortcode_attr((string) $atts['icon']) . '" pos="before" class="'
                . $shortcode_attr(implode(' ', array_merge(['branch-info'], $icon_classes))) . '"]')
            : '';
        $text = !empty($atts['text']) ? $atts['text'] : null;

        // Get field value
        // Shortcode attributes arrive as strings; resolve_contact_id() takes ?int under strict_types.
        // Numeric values keep their meaning (0 = by type, negative = none); get_field_value() gets the resolved ID.
        $contact_id = $this->resolve_contact_id(
            is_numeric($atts['contact_id']) ? (int) $atts['contact_id'] : null,
            (string) $atts['type']
        );
        $value = $this->get_field_value($atts['field'], $contact_id);

        // Bare number for a tel: link set elsewhere, e.g. a Bricks button "tel:{contact_info:phone@format:tel}".
        // Before debug: the result goes into a link field and must never carry markup.
        if ($atts['format'] === 'tel' && in_array($atts['field'], ['phone', 'mobile', 'fax'], true)) {
            return esc_html(self::normalize_tel($value));
        }

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
     * Wrap inner HTML in a configurable tag (or return it bare).
     *
     * Wrap is enabled when any of the following is true:
     * - `wrap` is truthy (true, 1, yes, on)
     * - `tag` attribute is provided (non-empty)
     * - `class` attribute is provided (non-empty)
     *
     * @param string $inner       Inner HTML already escaped/safe.
     * @param array  $atts        Full shortcode attributes.
     * @param string $field_class Field-specific class added to the wrapper (e.g. 'contact-info-email').
     * @return string
     */
    private function wrap_output(string $inner, array $atts, string $field_class): string
    {
        $tag_raw = isset($atts['tag']) ? trim((string) $atts['tag']) : '';
        $has_custom_tag = $tag_raw !== '';

        $wrap_raw = $atts['wrap'] ?? 'false';
        if (is_bool($wrap_raw)) {
            $wrap_truthy = $wrap_raw;
        } else {
            $wrap_truthy = in_array(strtolower((string) $wrap_raw), ['true', '1', 'yes', 'on'], true);
        }

        $has_class = !empty($atts['class']);

        $do_wrap = $wrap_truthy || $has_custom_tag || $has_class;

        if (!$do_wrap) {
            return $inner;
        }

        $tag = $has_custom_tag && preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,15}$/', $tag_raw)
            ? strtolower($tag_raw)
            : 'span';

        $classes = $this->process_classes($atts['class'] ?? '');
        $classes[] = $field_class;

        return '<' . $tag . ' class="' . esc_attr(implode(' ', $classes)) . '">' . $inner . '</' . $tag . '>';
    }

    /**
     * The published contact entry to read: an explicit ID if it is one, otherwise the
     * entry of the given type that comes first by Order (then newest). 0 = none.
     */
    private function resolve_contact_id(?int $contact_id, string $type): int
    {
        if ($contact_id !== null && $contact_id !== 0) {
            // Drafts, private entries and other post types stay hidden.
            return $contact_id > 0
                && get_post_type($contact_id) === 'sfx_contact_info'
                && get_post_status($contact_id) === 'publish'
                ? $contact_id
                : 0;
        }

        $type_cache_key = 'sfx_contact_info_type_' . $type;
        $cached_id = get_transient($type_cache_key);
        if ($cached_id !== false) {
            return (int) $cached_id;
        }

        $query = new \WP_Query([
            'post_type' => 'sfx_contact_info',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'meta_query' => [
                [
                    'key' => '_contact_type',
                    'value' => $type,
                    'compare' => '='
                ]
            ]
        ]);
        if (!$query->have_posts()) {
            return 0;
        }

        $found_id = (int) $query->posts[0]->ID;
        // Cleared on every contact save, so a changed Order or type takes effect at once.
        set_transient($type_cache_key, $found_id, HOUR_IN_SECONDS);
        return $found_id;
    }

    /**
     * Field value of a resolved contact. The raw value is cached per contact ID and
     * translated per request, so languages never share a cached translation.
     */
    private function get_field_value(string $field, int $contact_id): string
    {
        // Only the contact fields: never an arbitrary meta key such as _edit_lock.
        if ($contact_id <= 0 || !array_key_exists($field, \SFX\ContactInfos\FieldRegistry::get_fields())) {
            return '';
        }

        $cache_key = 'sfx_contact_info_' . $contact_id . '_' . $field;
        $value = get_transient($cache_key);

        if ($value === false) {
            $all_meta = get_post_meta($contact_id, '', true);
            $value = $all_meta['_' . $field] ?? '';
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            set_transient($cache_key, $value, 30 * MINUTE_IN_SECONDS);
        }

        $value = (string) $value;
        return $value === '' ? '' : \SFX\ContactInfos\PostType::get_translated_field($contact_id, $field, $value);
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
        $display_text = $text ?: $value;

        $inner = $icon;
        if ($has_link) {
            $inner .= '<a href="mailto:' . esc_attr($value) . '">' . esc_html($display_text) . '</a>';
        } else {
            $inner .= esc_html($display_text);
        }

        return $this->wrap_output($inner, $atts, 'contact-info-email');
    }

    /**
     * A phone number as RFC 3966 wants it in a tel: URI: digits and one leading +, no spaces.
     * A leading 00 becomes +, a leading single 0 becomes +<country code> (filter
     * sfx_contact_info_default_country_code, default 49), and the "(0)" written after a country
     * code is dropped. Numbers without 0/00/+ cannot be completed and keep their digits only.
     * An extension (";ext=123", "x 123", "ext. 123", "Durchwahl 123", "DW 123" at the end) becomes
     * ";ext=123"; German "-0" style switchboard digits are part of the number and stay.
     * Known limit: the "(0)" marker is recognised after 1–3 digits following + or 00, so a
     * compact "+493(0)…" cannot be told apart from "+49 (0)…" without a country-code table.
     * Output only — stored values stay as entered.
     */
    public static function normalize_tel(string $value): string
    {
        // An RFC 3966 extension (";ext=123") is kept apart, so its digits never join the number.
        $ext = '';
        // An extension is kept apart, so its digits never join the number. RFC 3966 form first:
        // everything from the first ";" is parameters, only "ext=" among them is used.
        $ext_raw = '';
        $semi = strpos($value, ';');
        if ($semi !== false) {
            // Digits, separators and spaces only — note text after it ("12 Büro 3") ends the extension.
            if (preg_match('/;[\s\p{Z}]*ext[\s\p{Z}]*=([0-9().\/\-\s\p{Z}]*)/iu', substr($value, $semi), $m)) {
                $ext_raw = $m[1];
            }
            $value = substr($value, 0, $semi);
        } elseif (preg_match('/(?<=[0-9\s\p{Z},)])(?:x|ext\.?|extension|durchwahl|dw\.?)[\s\p{Z}]*:?[\s\p{Z}]*([0-9(][0-9().\/\-\s\p{Z}]*)$/iu', $value, $m, PREG_OFFSET_CAPTURE)) {
            // Written form at the end: "x 12", "x12", "ext. 12", "Durchwahl 12", "DW 12".
            $ext_raw = $m[1][0];
            $value = substr($value, 0, $m[0][1]);
        }
        $ext_digits = (string) preg_replace('/\D+/', '', $ext_raw); // visual separators allowed
        $ext = $ext_digits !== '' ? ';ext=' . $ext_digits : '';

        $value = (string) preg_replace('/^[\s\p{Z}]+/u', '', $value); // incl. non-breaking spaces
        $international = str_starts_with($value, '+') || str_starts_with($value, '00');
        if ($international) {
            // "+49 (0)208": the trunk zero is only written, never dialled after a country code.
            // Only the marker right after the country code — a "(0)" later is a subscriber digit.
            $value = (string) preg_replace('/^(\+|00)([\s\p{Z}]*\d{1,3})[\s\p{Z}.\/-]*\([\s\p{Z}]*0[\s\p{Z}]*\)/u', '$1$2 ', $value);
        }

        $digits = (string) preg_replace('/\D+/', '', $value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($value, '+')) {
            return '+' . $digits . $ext;
        }
        if (str_starts_with($digits, '00')) {
            $rest = substr($digits, 2);
            return $rest !== '' ? '+' . $rest . $ext : ''; // "00" alone is no number
        }
        if (str_starts_with($digits, '0')) {
            $rest = substr($digits, 1);
            if ($rest === '') {
                return '';
            }
            $country = (string) preg_replace('/\D+/', '', (string) apply_filters('sfx_contact_info_default_country_code', '49'));
            return '+' . ($country !== '' ? $country : '49') . $rest . $ext;
        }
        return $digits . $ext;
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
        $inner = $icon;
        $tel = self::normalize_tel($value);
        if ($has_link && $tel !== '') { // "on request" has no number to dial
            $inner .= '<a href="tel:' . esc_attr($tel) . '">' . esc_html($value) . '</a>';
        } else {
            $inner .= esc_html($value);
        }

        return $this->wrap_output($inner, $atts, 'contact-info-phone');
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
                

            }
            
            if (!empty($address_parts)) {
                $inner = implode('<br>', array_map('esc_html', $address_parts));
                $output .= $this->wrap_output($inner, $atts, 'contact-info-address');
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
        $inner  = $icon;
        $inner .= '<a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View on Map', 'sfxtheme') . '</a>';

        return $this->wrap_output($inner, $atts, 'contact-info-maplink');
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
        $inner  = $icon;
        $inner .= esc_html($value);

        return $this->wrap_output($inner, $atts, 'contact-info-field');
    }

}
