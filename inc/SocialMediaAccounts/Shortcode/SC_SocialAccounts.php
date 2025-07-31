<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts\Shortcode;

use function add_shortcode;

/**
 * Social Accounts Shortcode
 * 
 * Provides shortcodes for displaying social media accounts
 * 
 * @package WordPress
 * @subpackage sfxtheme
 * @since 1.0.0
 */

class SC_SocialAccounts
{
    /**
     * Shortcode tag
     */
    public const SHORTCODE = 'social_accounts';

    /**
     * Constructor
     */
    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_social_accounts']);
        add_shortcode('social_account', [$this, 'render_single_account']);
    }

    /**
     * Render all social accounts
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_social_accounts($atts = [])
    {
        $atts = shortcode_atts([
            'class' => 'social-accounts',
            'style' => 'list', // list, grid, inline
            'size' => 'medium', // small, medium, large
            'target' => '_blank',
        ], $atts, self::SHORTCODE);

        // Get all published social accounts
        $accounts = get_posts([
            'post_type' => 'sfx_social_account',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC'
        ]);

        if (empty($accounts)) {
            return '';
        }

        $output = '<div class="' . esc_attr($atts['class']) . ' social-accounts-' . esc_attr($atts['style']) . ' social-accounts-' . esc_attr($atts['size']) . '">';
        
        foreach ($accounts as $account) {
            $output .= $this->render_single_account_html($account, $atts);
        }
        
        $output .= '</div>';

        return $output;
    }

    /**
     * Render a single social account
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_single_account($atts = [])
    {
        $atts = shortcode_atts([
            'id' => '',
            'class' => 'social-account',
            'size' => 'medium',
            'target' => '_blank',
        ], $atts, 'social_account');

        if (empty($atts['id'])) {
            return '';
        }

        $account = get_post($atts['id']);
        if (!$account || $account->post_type !== 'sfx_social_account' || $account->post_status !== 'publish') {
            return '';
        }

        return $this->render_single_account_html($account, $atts);
    }

    /**
     * Render single account HTML
     * 
     * @param \WP_Post $account Account post object
     * @param array $atts Attributes
     * @return string HTML output
     */
    private function render_single_account_html($account, $atts)
    {
        $icon_image = get_post_meta($account->ID, '_icon_image', true);
        $link_url = get_post_meta($account->ID, '_link_url', true);
        $link_title = get_post_meta($account->ID, '_link_title', true);
        $link_target = get_post_meta($account->ID, '_link_target', true) ?: $atts['target'];

        if (empty($link_url)) {
            return '';
        }

        $classes = [
            'social-account',
            'social-account-' . $account->ID,
            'social-account-' . $atts['size']
        ];

        if (!empty($atts['class'])) {
            $classes[] = $atts['class'];
        }

        $output = '<div class="' . esc_attr(implode(' ', $classes)) . '">';
        
        if (!empty($icon_image)) {
            $output .= '<a href="' . esc_url($link_url) . '" target="' . esc_attr($link_target) . '"';
            if (!empty($link_title)) {
                $output .= ' title="' . esc_attr($link_title) . '"';
            }
            $output .= ' rel="noopener noreferrer">';
            $output .= '<img src="' . esc_url($icon_image) . '" alt="' . esc_attr($account->post_title) . '" class="social-account-icon" />';
            $output .= '</a>';
        } else {
            $output .= '<a href="' . esc_url($link_url) . '" target="' . esc_attr($link_target) . '"';
            if (!empty($link_title)) {
                $output .= ' title="' . esc_attr($link_title) . '"';
            }
            $output .= ' rel="noopener noreferrer">';
            $output .= '<span class="social-account-text">' . esc_html($account->post_title) . '</span>';
            $output .= '</a>';
        }
        
        $output .= '</div>';

        return $output;
    }
} 