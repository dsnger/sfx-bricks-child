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
        
        // Clear caches when social account posts are updated
        add_action('save_post_sfx_social_account', [$this, 'clear_social_account_caches']);
        add_action('delete_post', [$this, 'clear_social_account_caches']);
    }
    
    /**
     * Clear social account caches when posts are updated
     * 
     * @param int $post_id
     */
    public function clear_social_account_caches(int $post_id): void
    {
        // Clear all social account caches (simple approach for now)
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_sfx_social_account_%'
            )
        );
    }

    /**
     * Render all social accounts with caching
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

        // Create cache key based on attributes
        $cache_key = 'sfx_social_accounts_' . md5(serialize($atts));
        $cached_output = get_transient($cache_key);
        
        if ($cached_output !== false) {
            return $cached_output;
        }

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

        // Cache the result for 1 hour
        set_transient($cache_key, $output, HOUR_IN_SECONDS);

        return $output;
    }

    /**
     * Render a single social account with caching
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

        // Create cache key
        $cache_key = 'sfx_social_account_' . $atts['id'] . '_' . md5(serialize($atts));
        $cached_output = get_transient($cache_key);
        
        if ($cached_output !== false) {
            return $cached_output;
        }

        $account = get_post($atts['id']);
        if (!$account || $account->post_type !== 'sfx_social_account' || $account->post_status !== 'publish') {
            return '';
        }

        $output = $this->render_single_account_html($account, $atts);
        
        // Cache the result for 1 hour
        set_transient($cache_key, $output, HOUR_IN_SECONDS);

        return $output;
    }

    /**
     * Render single account HTML with optimized batch meta retrieval
     * 
     * @param \WP_Post $account Account post object
     * @param array $atts Attributes
     * @return string HTML output
     */
    private function render_single_account_html($account, $atts)
    {
        // Batch retrieve all meta values in one query
        $meta_keys = ['_icon_image', '_link_url', '_link_title', '_link_target'];
        $all_meta = get_post_meta($account->ID, '', true);
        $account_data = array_intersect_key($all_meta, array_flip($meta_keys));
        
        $icon_image = $account_data['_icon_image'] ?? '';
        $link_url = $account_data['_link_url'] ?? '';
        $link_title = $account_data['_link_title'] ?? '';
        $link_target = $account_data['_link_target'] ?? $atts['target'];

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