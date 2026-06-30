<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts\Shortcode;

use SFX\SocialMediaAccounts\FieldRegistry;

use function add_action;
use function add_shortcode;

/**
 * Social Accounts Shortcode
 *
 * Provides shortcodes for displaying social media accounts
 */
class SC_SocialAccounts
{
    public const SHORTCODE = 'social_accounts';

    private const CACHE_GEN_OPTION = 'sfx_social_accounts_cache_gen';

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_social_accounts']);
        add_shortcode('social_account', [$this, 'render_single_account']);

        add_action('save_post_sfx_social_account', [$this, 'clear_social_account_caches']);
        add_action('delete_post_sfx_social_account', [$this, 'clear_social_account_caches']);
    }

    public function clear_social_account_caches(int $post_id): void
    {
        update_option(self::CACHE_GEN_OPTION, $this->get_cache_generation() + 1, false);
    }

    private function get_cache_generation(): int
    {
        return (int) get_option(self::CACHE_GEN_OPTION, 0);
    }

    public function render_social_accounts($atts = []): string
    {
        return $this->render_all_accounts(is_array($atts) ? $atts : []);
    }

    public function render_all_accounts(array $atts = []): string
    {
        $atts = shortcode_atts([
            'class' => 'social-accounts',
            'style' => 'list',
            'size' => 'medium',
            'target' => '_blank',
        ], $atts, self::SHORTCODE);

        $cache_key = 'sfx_social_accounts_' . $this->get_cache_generation() . '_' . md5(serialize($atts));
        $cached_output = get_transient($cache_key);

        if ($cached_output !== false) {
            return (string) $cached_output;
        }

        $accounts = get_posts([
            'post_type' => 'sfx_social_account',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        if (empty($accounts)) {
            return '';
        }

        $output = '<div class="' . esc_attr($atts['class']) . ' social-accounts-' . esc_attr($atts['style']) . ' social-accounts-' . esc_attr($atts['size']) . '">';

        foreach ($accounts as $account) {
            $output .= $this->render_single_account_html($account, $atts);
        }

        $output .= '</div>';

        set_transient($cache_key, $output, HOUR_IN_SECONDS);

        return $output;
    }

    public function render_single_account($atts = []): string
    {
        $atts = shortcode_atts([
            'id' => '',
            'field' => 'html',
            'class' => 'social-account',
            'size' => 'medium',
            'target' => '_blank',
        ], is_array($atts) ? $atts : [], 'social_account');

        return $this->render_account_field($atts);
    }

    public function render_account_field(array $atts): string
    {
        $atts = shortcode_atts([
            'id' => '',
            'field' => 'html',
            'class' => 'social-account',
            'size' => 'medium',
            'target' => '_blank',
            'context' => 'text',
        ], $atts, 'social_account');

        $field = (string) ($atts['field'] ?? 'html');
        if (!array_key_exists($field, FieldRegistry::get_fields())) {
            return '';
        }

        if (empty($atts['id'])) {
            return '';
        }

        $post_id = (int) $atts['id'];
        $account = $this->resolve_published_account($post_id);
        if ($account === null) {
            return '';
        }

        if ($field === 'html') {
            $cache_key = 'sfx_social_account_' . $post_id . '_html_' . $this->get_cache_generation() . '_' . md5(serialize($atts));
            $cached_output = get_transient($cache_key);

            if ($cached_output !== false) {
                return (string) $cached_output;
            }

            $output = $this->render_single_account_html($account, $atts);
            set_transient($cache_key, $output, HOUR_IN_SECONDS);

            return $output;
        }

        return $this->render_scalar_field($account, $field, (string) ($atts['context'] ?? 'text'));
    }

    private function resolve_published_account(int $post_id): ?\WP_Post
    {
        if ($post_id <= 0) {
            return null;
        }

        $account = get_post($post_id);
        if (
            !$account
            || $account->post_type !== 'sfx_social_account'
            || $account->post_status !== 'publish'
        ) {
            return null;
        }

        return $account;
    }

    private function get_account_meta(int $post_id, string $meta_key): string
    {
        $value = get_post_meta($post_id, $meta_key, true);
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) ? $value : '';
    }

    private function render_scalar_field(\WP_Post $account, string $field, string $context = 'text'): string
    {
        $meta_key = FieldRegistry::get_meta_key($field);
        if ($meta_key === '') {
            return '';
        }

        switch ($field) {
            case 'url':
            case 'icon':
                $validated = esc_url($this->get_account_meta($account->ID, $meta_key));
                return $validated !== '' ? $validated : '';

            case 'title':
                $title = $this->get_account_meta($account->ID, $meta_key);
                if ($title === '') {
                    $title = $account->post_title;
                }
                return $this->escape_scalar_text($title, $context);

            case 'target':
                $target = $this->get_account_meta($account->ID, $meta_key);
                return in_array($target, ['_blank', '_self'], true) ? $target : '_blank';

            default:
                return '';
        }
    }

    private function escape_scalar_text(string $value, string $context): string
    {
        $value = sanitize_text_field($value);

        if (in_array(strtolower($context), ['attribute', 'attr'], true)) {
            return esc_attr($value);
        }

        return esc_html($value);
    }

    private function render_single_account_html(\WP_Post $account, array $atts): string
    {
        $icon_image = esc_url($this->get_account_meta($account->ID, FieldRegistry::get_meta_key('icon')));
        $link_url = esc_url($this->get_account_meta($account->ID, FieldRegistry::get_meta_key('url')));
        $link_title = $this->get_account_meta($account->ID, FieldRegistry::get_meta_key('title'));
        $link_target = $this->get_account_meta($account->ID, FieldRegistry::get_meta_key('target'));
        if ($link_target === '') {
            $link_target = (string) ($atts['target'] ?? '_blank');
        }
        $link_target = in_array($link_target, ['_blank', '_self'], true) ? $link_target : '_blank';

        if ($link_url === '') {
            return '';
        }

        $classes = [
            'social-account',
            'social-account-' . $account->ID,
            'social-account-' . ($atts['size'] ?? 'medium'),
        ];

        if (!empty($atts['class'])) {
            $classes[] = $atts['class'];
        }

        $output = '<div class="' . esc_attr(implode(' ', $classes)) . '">';

        if ($icon_image !== '') {
            $output .= '<a href="' . esc_url($link_url) . '" target="' . esc_attr($link_target) . '"';
            if ($link_title !== '') {
                $output .= ' title="' . esc_attr($link_title) . '"';
            }
            $output .= ' rel="noopener noreferrer">';
            $output .= '<img src="' . esc_url($icon_image) . '" alt="' . esc_attr($account->post_title) . '" class="social-account-icon" />';
            $output .= '</a>';
        } else {
            $output .= '<a href="' . esc_url($link_url) . '" target="' . esc_attr($link_target) . '"';
            if ($link_title !== '') {
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
