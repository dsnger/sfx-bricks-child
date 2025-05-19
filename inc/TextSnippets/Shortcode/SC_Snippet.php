<?php

declare(strict_types=1);

namespace SFX\TextSnippets\Shortcode;

use function add_shortcode;
use function get_post;
use function get_the_ID;
use function esc_html;
use function shortcode_atts;
use function current_user_can;
use function get_edit_post_link;
use function add_filter;

if (!defined('ABSPATH')) {
    exit;
}

class SC_Snippet
{
    /**
     * Shortcode tag.
     */
    private const SHORTCODE = 'snippet';

    /**
     * Register the shortcode on construction.
     */
    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
    }

    /**
     * Shared render logic for snippet content or field.
     *
     * @param int $post_id
     * @param string $field_slug
     * @return string
     */
    public static function render_snippet(int $post_id, string $field_slug = ''): string
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'cpt_text_snippet') {
            return '';
        }
        $fields = get_post_meta($post_id, '_sfx_text_snippet_fields', true);
        if (!is_array($fields)) {
            $fields = [];
        }
        // If a specific field is requested, return only that value
        if ($field_slug !== '') {
            foreach ($fields as $field) {
                if (isset($field['slug'], $field['value']) && $field['slug'] === $field_slug) {
                    $output = esc_html($field['value']);
                    if (current_user_can('edit_post', $post_id)) {
                        $edit_url = get_edit_post_link($post_id);
                        $output .= ' <a href="' . esc_url($edit_url) . '" class="sfx-snippet-edit-btn" style="font-size:11px;padding:2px 6px;border-radius:3px;background:#f1f1f1;color:#0073aa;text-decoration:none;vertical-align:middle;" title="Edit Snippet" target="_blank">✎ Edit Snippet</a>';
                    }
                    return $output;
                }
            }
            return '';
        }
        // Default: return the post content
        $content = apply_filters('the_content', $post->post_content);
        if (current_user_can('edit_post', $post_id)) {
            $edit_url = get_edit_post_link($post_id);
            $content .= ' <a href="' . esc_url($edit_url) . '" class="sfx-snippet-edit-btn" style="font-size:11px;padding:2px 6px;border-radius:3px;background:#f1f1f1;color:#0073aa;text-decoration:none;vertical-align:middle;" title="Edit Snippet" target="_blank">✎ Edit Snippet</a>';
        }
        return $content;
    }

    /**
     * Render the snippet shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function render(array $atts): string
    {
        $atts = shortcode_atts([
            'id'   => '',
            'lang' => '',
            'field' => '',
        ], $atts, self::SHORTCODE);

        $post_id = !empty($atts['id']) ? (int) $atts['id'] : get_the_ID();

        // Handle Polylang translation if present and requested
        if (!empty($atts['lang']) && function_exists('pll_get_post')) {
            $translated_post_id = pll_get_post($post_id, $atts['lang']);
            if ($translated_post_id) {
                $post_id = $translated_post_id;
            }
        }

        return self::render_snippet($post_id, $atts['field'] ?? '');
    }
}

// Register the shortcode
new SC_Snippet();
  