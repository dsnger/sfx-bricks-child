<?php

declare(strict_types=1);

namespace SFX;

/**
 * Lightweight Meta Field Manager
 * 
 * Provides feature-based meta field registration and validation
 * 
 * @package WordPress
 * @subpackage sfxtheme
 * @since 1.0.0
 */

class MetaFieldManager
{
    /**
     * Register meta fields for a post type
     * 
     * @param string $post_type
     * @param array $fields
     * @param array $html_fields Fields that should allow HTML content
     * @return void
     */
    public static function register_fields(string $post_type, array $fields, array $html_fields = []): void
    {
        foreach ($fields as $field) {
            $sanitize_callback = in_array($field, $html_fields) ? 'wp_kses_post' : 'sanitize_text_field';
            
            register_meta('post', '_' . $field, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'sanitize_callback' => $sanitize_callback
            ]);
        }
    }

    /**
     * Validate meta fields on save
     * 
     * @param int $post_id
     * @param string $post_type
     * @param array $validation_rules
     * @return void
     */
    public static function validate_fields(int $post_id, string $post_type, array $validation_rules): void
    {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        foreach ($validation_rules as $meta_key => $validator) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (!empty($value)) {
                $validated_value = is_callable($validator) ? $validator($value) : $validator($value);
                update_post_meta($post_id, $meta_key, $validated_value);
            }
        }
    }

    /**
     * Cleanup orphaned meta fields
     * 
     * @param int $post_id
     * @param string $post_type
     * @param array $expected_fields
     * @return void
     */
    public static function cleanup_fields(int $post_id, string $post_type, array $expected_fields): void
    {
        // Get all meta for this post
        $all_meta = get_post_meta($post_id, '', false);
        
        // Remove unexpected meta fields
        foreach ($all_meta as $meta_key => $meta_values) {
            if (!in_array($meta_key, $expected_fields)) {
                delete_post_meta($post_id, $meta_key);
            }
        }
    }
} 