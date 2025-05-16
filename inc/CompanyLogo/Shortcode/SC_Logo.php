<?php

declare(strict_types=1);

namespace SFX\CompanyLogo\Shortcode;

use function add_shortcode;
use function get_bloginfo;
use function esc_attr;
use function sanitize_url;
use function __;

defined('ABSPATH') || exit;

class SC_Logo
{
    /**
     * Shortcode tag
     */
    private const SHORTCODE = 'logo';

    /**
     * Logo settings field map
     */
    private const LOGO_FIELDS = [
        'default'     => 'company_logo',
        'tiny'        => 'company_logo_tiny',
        'invert'      => 'company_logo_inverted',
        'invert-tiny' => 'company_logo_inverted_tiny',
    ];

    /**
     * Option name for logo settings
     * @var string
     */
    private string $option_name;

    /**
     * Constructor: Register the shortcode
     * @param string $option_name
     */
    public function __construct(string $option_name)
    {
        $this->option_name = $option_name;
        add_shortcode(self::SHORTCODE, [$this, 'render_logo']);
    }

    /**
     * Render the logo shortcode
     *
     * @param array $atts
     * @return string
     */
    public function render_logo($atts): string
    {
        $atts = shortcode_atts([
            'type'     => 'default',
            'homelink' => 'true',
            'path'     => '',
            'maxwidth' => '',
            'width'    => '',
            'class'    => 'w-full h-auto',
            'style'    => '',
        ], $atts, self::SHORTCODE);

        $logo_src = $this->get_logo_src($atts);
        if (empty($logo_src)) {
            return '';
        }

        $width    = !empty($atts['width']) ? esc_attr($atts['width']) : '';
        $maxwidth = !empty($atts['maxwidth']) ? 'max-width:' . esc_attr($atts['maxwidth']) . ';' : '';
        $style    = trim($maxwidth . $atts['style']);
        $style    = !empty($style) ? 'style="' . esc_attr($style) . '"' : '';
        $class    = 'sfx-logo-img ' . esc_attr($atts['class']);
        $alt      = 'Logo ' . esc_attr(get_bloginfo('name'));

        $logo_img = sprintf(
            '<img class="%s" src="%s" alt="%s"%s%s loading="lazy">',
            $class,
            esc_url($logo_src),
            $alt,
            $width ? ' width="' . $width . '"' : '',
            $style ? ' ' . $style : ''
        );

        $homelink = in_array($atts['homelink'], ['true', '1', 1, true], true);
        if ($homelink) {
            $logo_img = sprintf(
                '<a href="%s" title="%s">%s</a>',
                esc_url(get_bloginfo('url')),
                esc_attr(__('Link to home', 'sfxtheme')),
                $logo_img
            );
        }

        return $logo_img;
    }

    /**
     * Get the logo source URL based on shortcode attributes
     *
     * @param array $atts
     * @return string|null
     */
    private function get_logo_src(array $atts): ?string
    {
        // Direct path overrides everything
        if (!empty($atts['path'])) {
            return sanitize_url($atts['path']);
        }

        $type = $atts['type'] ?? 'default';
        $options = get_option($this->option_name, []);
        if (isset(self::LOGO_FIELDS[$type])) {
            $field = self::LOGO_FIELDS[$type];
            $logo_url = $options[$field] ?? '';
            if (!empty($logo_url)) {
                return $logo_url;
            }
        }

        // Fallback: try default logo
        $default_logo = $options[self::LOGO_FIELDS['default']] ?? '';
        return !empty($default_logo) ? $default_logo : null;
    }
}
