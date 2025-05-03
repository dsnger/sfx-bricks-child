<?php

namespace SFX\Shortcodes;

/**
 * IconifyIcon Shortcode
 * 
 * Provides a shortcode for displaying iconify icons
 * 
 * @package WordPress
 * @subpackage sfxtheme
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class IconifyIcon
{
    /**
     * Class constructor
     * Register the shortcode
     */
    public function __construct()
    {
        add_shortcode('icon', [$this, 'render_icon']);
    }

    /**
     * Render iconify icon
     *
     * Outputs an iconify-icon element with css classes for icons
     * Color and font size is inherit. Otherwise you can set color="{text-color-class here}" and
     * font-size="{1rem or 16px}"
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     * 
     * Usage: [icon class="my-class" color="primary|secondary|tertiary|quartinay..." size="32" pos="before|after" tooltip="Text..." style="custom styles here"]
     */
    public function render_icon($atts)
    {
        // Attributes
        $atts = shortcode_atts(
            [
                'icon'    => '',
                'class'   => '',
                'color'   => '',
                'size'    => '',
                'width'   => '',
                'tooltip' => '',
                'pos'     => '',
                'style'   => ''
            ],
            $atts,
            'icon'
        );

        // Return early if no icon provided
        if (empty($atts['icon'])) {
            return '';
        }

        // Process position class
        $pos_class = $this->get_position_class($atts['pos']);

        // Process color class
        $color_class = $this->get_color_class($atts['color']);

        // Process style attributes
        $style = $this->get_style_attributes($atts['size'], $atts['style']);

        // Process width
        $width = $this->get_width_attribute($atts['width']);

        // Process tooltip
        $tooltip = $this->get_tooltip_attribute($atts['tooltip']);

        // Build and return the icon HTML
        return sprintf(
            '<iconify-icon class="iconify %s %s %s" %s %s icon="%s" %s></iconify-icon>',
            $pos_class,
            $atts['class'],
            $color_class,
            $width,
            $style,
            $atts['icon'],
            $tooltip
        );
    }

    /**
     * Get position class based on position attribute
     * 
     * @param string $position The position value
     * @return string The position class
     */
    private function get_position_class($position)
    {
        if (empty($position) || !in_array($position, ['before', 'after'])) {
            return '';
        }

        return 'icon--' . $position;
    }

    /**
     * Get color class based on color attribute
     * 
     * @param string $color The color value
     * @return string The color class
     */
    private function get_color_class($color)
    {
        if (empty($color)) {
            return '';
        }

        return 'ds-text-' . sanitize_text_field($color);
    }

    /**
     * Get style attributes based on size and style attributes
     * 
     * @param string $size The size value
     * @param string $style Additional style attributes
     * @return string The style attribute
     */
    private function get_style_attributes($size, $style)
    {
        if (empty($size) && empty($style)) {
            return '';
        }

        $style_attr = 'style="';

        if (!empty($size)) {
            $style_attr .= 'font-size:' . sanitize_text_field($size) . ';';
        }

        if (!empty($style)) {
            $style_attr .= sanitize_text_field($style);
        }

        $style_attr .= '"';

        return $style_attr;
    }

    /**
     * Get width attribute
     * 
     * @param string $width The width value
     * @return string The width attribute
     */
    private function get_width_attribute($width)
    {
        if (empty($width)) {
            return '';
        }

        return 'width="' . sanitize_text_field($width) . '" height="auto"';
    }

    /**
     * Get tooltip attribute
     * 
     * @param string $tooltip The tooltip text
     * @return string The tooltip attribute
     */
    private function get_tooltip_attribute($tooltip)
    {
        if (empty($tooltip)) {
            return '';
        }

        return 'uk-tooltip="' . sanitize_text_field($tooltip) . '"';
    }
} 