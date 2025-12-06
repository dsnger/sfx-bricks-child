<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

/**
 * Class for Media Replacement functionality
 * 
 * @since 1.0.0
 */
class MediaReplacement
{
    /**
     * Register media replacement functionality
     */
    public static function register(): void
    {
        // Check if media replacement is enabled
        if (!self::is_enabled()) {
            return;
        }

        // Add media replacement button in attachment edit screen
        add_filter('attachment_fields_to_edit', [self::class, 'add_media_replacement_button'], 10, 2);
        
        // Handle AJAX media replacement
        add_action('wp_ajax_sfx_replace_media', [self::class, 'handle_ajax_replace_media']);
        
        // Customize attachment updated message
        add_filter('post_updated_messages', [self::class, 'attachment_updated_custom_message']);
        
        // Cache busting filters will be registered dynamically when media is replaced
    }

    /**
     * Add media replacement button in the edit screen of media/attachment
     */
    public static function add_media_replacement_button($fields, $post): array
    {
        global $pagenow, $typenow;
        
        // Check if user has permission to edit attachments
        if (!current_user_can('edit_posts')) {
            return $fields;
        }
        
        // Only show on the full attachment edit screen (post.php with attachment post type)
        // This excludes media details modal, post creation screens, and other contexts
        if ($pagenow === 'post.php' && $typenow === 'attachment') {
            
            $original_attachment_id = '';
            $image_mime_type = '';
            
            if (is_object($post)) {
                $original_attachment_id = $post->ID;
                if (property_exists($post, 'post_mime_type')) {
                    $image_mime_type = $post->post_mime_type;
                }
            }
                    
            // Enqueues all scripts, styles, settings, and templates necessary to use all media JS APIs
            wp_enqueue_media();

            // Add new field to attachment fields for the media replace functionality
            $fields['sfx-media-replace'] = [
                'label' => '',
                'input' => 'html',
                'html' => '
                    <div id="media-replace-div" class="postbox attachment-id-' . $original_attachment_id . '" data-original-image-id="' . $original_attachment_id . '">
                        <div class="postbox-header">
                            <h2 class="hndle ui-sortable-handle">' . __('Replace Media', 'sfxtheme') . '</h2>
                        </div>
                        <div class="inside">
                            <button type="button" id="sfx-media-replace" class="button-secondary button-large sfx-media-replace-button" data-old-image-mime-type="' . $image_mime_type . '" onclick="replaceMedia(\'' . $original_attachment_id . '\',\'' . $image_mime_type . '\');">' . __('Select New Media File', 'sfxtheme') . '</button>
                            <div class="sfx-media-replace-notes"><p>' . __('The current file will be replaced with the uploaded / selected file (of the same type) while retaining the current ID, publish date and file name. Thus, no existing links will break.', 'sfxtheme') . '</p></div>
                        </div>
                    </div>
                '
            ];
        }

        return $fields;
    }

    /**
     * Handle AJAX media replacement request
     */
    public static function handle_ajax_replace_media(): void
    {
        // Check nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'sfx_media_replace_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Get attachment IDs
        $old_attachment_id = (int) ($_POST['old_attachment_id'] ?? 0);
        $new_attachment_id = (int) ($_POST['new_attachment_id'] ?? 0);

        if (!$old_attachment_id || !$new_attachment_id) {
            wp_send_json_error('Invalid attachment IDs');
            return;
        }

        // Perform the replacement
        $result = self::perform_media_replacement($old_attachment_id, $new_attachment_id);

        if ($result) {
            wp_send_json_success('Media replacement completed successfully');
        } else {
            wp_send_json_error('Media replacement failed');
        }
    }

    /**
     * Perform the actual media replacement
     */
    public static function perform_media_replacement($old_attachment_id, $new_attachment_id): bool
    {
        // Check if user has permission to edit attachments
        if (!current_user_can('edit_posts')) {
            return false;
        }

        // The new attachment ID is now passed as a parameter

        // Verify that the new attachment actually exists
        if (!$new_attachment_id || !get_post($new_attachment_id)) {
            error_log('SFX Media Replacement: New attachment ID ' . $new_attachment_id . ' does not exist');
            return false;
        }

        $old_post_meta = get_post($old_attachment_id, ARRAY_A);
        if (!$old_post_meta || !isset($old_post_meta['post_mime_type'])) {
            error_log('SFX Media Replacement: Invalid old attachment data for ID: ' . $old_attachment_id);
            return false;
        }
        $old_post_mime = $old_post_meta['post_mime_type']; // e.g. 'image/jpeg'

        $new_post_meta = get_post($new_attachment_id, ARRAY_A);
        if (!$new_post_meta || !isset($new_post_meta['post_mime_type'])) {
            error_log('SFX Media Replacement: Invalid new attachment data for ID: ' . $new_attachment_id);
            return false;
        }
        $new_post_mime = $new_post_meta['post_mime_type']; // e.g. 'image/jpeg'

        // Check if the media file ID selected via the media frame and passed on to the #new-attachment-id hidden field
        // Ensure the mime type matches too
        if ((!empty($new_attachment_id)) && is_numeric($new_attachment_id) && ($old_post_mime == $new_post_mime)) {

            // Get the new attachment file path
            $new_attachment_file = get_post_meta($new_attachment_id, '_wp_attached_file', true);
            $upload_dir = wp_upload_dir();
            $new_media_file_path = $upload_dir['basedir'] . '/' . $new_attachment_file;

            // Check if the new media file exists and is readable
            if (!is_file($new_media_file_path)) {
                return false;
            }
            
            if (!is_readable($new_media_file_path)) {
                return false;
            }
            
            $new_file_size = filesize($new_media_file_path);
            if ($new_file_size === false || $new_file_size === 0) {
                return false;
            }

            // Get the old attachment file path
            $old_attachment_file = get_post_meta($old_attachment_id, '_wp_attached_file', true);
            if (empty($old_attachment_file)) {
                error_log('SFX Media Replacement: Old attachment file path is empty for ID: ' . $old_attachment_id);
                return false;
            }

            $old_media_file_path = $upload_dir['basedir'] . '/' . $old_attachment_file;

            // Ensure the target directory exists and is writable
            $target_dir = dirname($old_media_file_path);
            if (!file_exists($target_dir)) {
                if (!mkdir($target_dir, 0755, true)) {
                    return false;
                }
            }
            
            // Check if target directory is writable
            if (!is_writable($target_dir)) {
                return false;
            }
            
            // Check if we can write to the target file location
            if (file_exists($old_media_file_path) && !is_writable($old_media_file_path)) {
                return false;
            }

            // Copy the new media file into the old media file's path
            $copy_success = false;
            
            // Try direct copy first
            if (copy($new_media_file_path, $old_media_file_path)) {
                $copy_success = true;
            } else {
                // Alternative: read file content and write it
                $file_content = file_get_contents($new_media_file_path);
                if ($file_content !== false && file_put_contents($old_media_file_path, $file_content) !== false) {
                    $copy_success = true;
                }
            }
            
            if (!$copy_success) {
                return false;
            }

            // Verify the copy was successful by checking file size
            $copied_file_size = filesize($old_media_file_path);
            
            if ($new_file_size !== $copied_file_size) {
                return false;
            }

            // Verify the copied file is readable and has content
            if (!is_readable($old_media_file_path)) {
                return false;
            }

            // Check if file has actual content (not empty)
            if ($copied_file_size === 0) {
                return false;
            }

            // Check if file still exists after copy (before thumbnail deletion)
            if (!file_exists($old_media_file_path)) {
                return false;
            }

            // Delete only the old thumbnails, not the main file
            self::delete_media_files($old_attachment_id);

            // Verify the main file still exists before metadata generation
            if (!file_exists($old_media_file_path)) {
                return false;
            }

            // Regenerate attachment metadata
            $new_metadata = wp_generate_attachment_metadata($old_attachment_id, $old_media_file_path);
            
            if (is_wp_error($new_metadata)) {
                return false;
            }

            if (empty($new_metadata)) {
                return false;
            }

            // Update the attachment metadata
            $update_result = wp_update_attachment_metadata($old_attachment_id, $new_metadata);
            
            if (is_wp_error($update_result)) {
                return false;
            }

            // Final verification: check if the file still exists and has content
            if (!file_exists($old_media_file_path)) {
                return false;
            }
            
            $final_file_size = filesize($old_media_file_path);
            if ($final_file_size === false || $final_file_size === 0) {
                return false;
            }

            // Delete the newly uploaded media file and it's sub-sizes, and also delete post and post meta entries for it in the database.
            wp_delete_attachment($new_attachment_id, true);
            
            // Add old attachment ID to recently replaced media option. This will be used for cache busting to ensure the new replacement images are immediately loaded in the browser / wp-admin
            $options_extra = get_option('sfx_wpoptimizer_extra', []);
            $recently_replaced_media = isset($options_extra['recently_replaced_media']) ? $options_extra['recently_replaced_media'] : [];
            $max_media_number_to_cache_bust = 5;
            
            if (count($recently_replaced_media) >= $max_media_number_to_cache_bust) {
                // Remove first/oldest attachment ID
                array_shift($recently_replaced_media);
            }
            $recently_replaced_media[] = $old_attachment_id;
            $recently_replaced_media = array_unique($recently_replaced_media);
            
            $options_extra['recently_replaced_media'] = $recently_replaced_media;
            update_option('sfx_wpoptimizer_extra', $options_extra, true);
            
            // Register cache busting filters now that we have replaced media
            self::register_cache_busting_filters();
            
            return true;
        }

        return false;
    }

    /**
     * Delete the existing/old media files when performing media replacement
     */
    public static function delete_media_files($post_id): void
    {
        $attachment_meta = wp_get_attachment_metadata($post_id);

        // Will get '-scaled' version if it exists, e.g. /path/to/uploads/year/month/file-name.jpg
        $attachment_file_path = get_attached_file($post_id); 

        // Safety check: only proceed if we have valid file paths
        if (!$attachment_file_path) {
            return;
        }

        // e.g. file-name.jpg
        $attachment_file_basename = basename($attachment_file_path);

        // Delete intermediate images if there are any
        if (isset($attachment_meta['sizes']) && is_array($attachment_meta['sizes'])) {
            foreach ($attachment_meta['sizes'] as $size => $size_info) {
                if (isset($size_info['file']) && !empty($size_info['file'])) {
                    // /path/to/uploads/year/month/file-name.jpg --> /path/to/uploads/year/month/file-name-1024x768.jpg
                    $intermediate_file_path = str_replace($attachment_file_basename, $size_info['file'], $attachment_file_path);
                    if (file_exists($intermediate_file_path)) {
                        wp_delete_file($intermediate_file_path);
                    }
                }
            }
        }

        // NOTE: We do NOT delete the main attachment file during replacement
        // This is because we need it to exist for metadata generation
        // The main file will be overwritten by the copy operation

        // If original file is larger than 2560 pixel
        // https://make.wordpress.org/core/2019/10/09/introducing-handling-of-big-images-in-wordpress-5-3/
        $attachment_original_file_path = wp_get_original_image_path($post_id);

        // CRITICAL SAFETY CHECK: Never delete the main attachment file
        // Only delete the original large image if it's different from the main file
        if ($attachment_original_file_path && 
            file_exists($attachment_original_file_path) && 
            $attachment_original_file_path !== $attachment_file_path) {
            wp_delete_file($attachment_original_file_path);            
        }
    }

    /**
     * Customize the attachment updated message
     */
    public static function attachment_updated_custom_message($messages): array
    {
        $new_messages = [];

        foreach ($messages as $post_type => $messages_array) {
            if ($post_type == 'attachment') {
                // Message ID for successful edit/update of an attachment is 4. Customize it here.
                $messages_array[4] = 'Media file updated. You may need to <a href="https://fabricdigital.co.nz/blog/how-to-hard-refresh-your-browser-and-clear-cache" target="_blank">hard refresh</a> your browser to see the updated media preview image below.';
            }
            $new_messages[$post_type] = $messages_array;
        }

        return $new_messages;
    }
    
    /**
     * Append cache busting parameter to the end of image srcset
     */
    public static function append_cache_busting_param_to_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id): array
    {
        // If sources is false or not an array, return empty array to prevent errors
        if (!$sources || !is_array($sources)) {
            return [];
        }

        $options_extra = get_option('sfx_wpoptimizer_extra', []);
        $recently_replaced_media = isset($options_extra['recently_replaced_media']) ? $options_extra['recently_replaced_media'] : [];
        $attachment_mime_type = get_post_mime_type($attachment_id);

        if (in_array($attachment_id, $recently_replaced_media) 
            && $attachment_mime_type && false !== strpos($attachment_mime_type, 'image')) {
            foreach ($sources as $size => $source) {
                $source['url'] .= self::maybe_append_timestamp_parameter($source['url']);
                $sources[$size] = $source;
            }
        }
        return $sources;
    }

    /**
     * Append cache busting parameter to the end of image src
     */
    public static function append_cache_busting_param_to_attachment_image_src($image, $attachment_id): array
    {
        // If image is false or not an array, return empty array to prevent errors
        if (!$image || !is_array($image)) {
            return [];
        }

        $options_extra = get_option('sfx_wpoptimizer_extra', []);
        $recently_replaced_media = isset($options_extra['recently_replaced_media']) ? $options_extra['recently_replaced_media'] : [];
        $attachment_mime_type = get_post_mime_type($attachment_id);

        if (!empty($image[0]) 
            && in_array($attachment_id, $recently_replaced_media) 
            && $attachment_mime_type && false !== strpos($attachment_mime_type, 'image')) {
            $image[0] .= self::maybe_append_timestamp_parameter($image[0]);
        }

        return $image;
    }

    /**
     * Register cache busting filters dynamically when needed
     */
    private static function register_cache_busting_filters(): void
    {
        static $filters_registered = false;
        
        if ($filters_registered) {
            return;
        }

        add_filter('wp_calculate_image_srcset', [self::class, 'append_cache_busting_param_to_image_srcset'], 10, 5);
        add_filter('wp_get_attachment_image_src', [self::class, 'append_cache_busting_param_to_attachment_image_src'], 10, 2);
        add_filter('wp_prepare_attachment_for_js', [self::class, 'append_cache_busting_param_to_attachment_for_js'], 10, 2);
        add_filter('wp_get_attachment_url', [self::class, 'append_cache_busting_param_to_attachment_url'], 10, 2);
        
        $filters_registered = true;
    }

    /**
     * Append cache busting parameter to image src for js
     */
    public static function append_cache_busting_param_to_attachment_for_js($response, $attachment): array
    {
        // If response is false or not an array, return empty array to prevent errors
        if (!$response || !is_array($response)) {
            return [];
        }

        // If attachment is not an object or doesn't have ID, return response as is
        if (!is_object($attachment) || !isset($attachment->ID)) {
            return $response;
        }

        $options_extra = get_option('sfx_wpoptimizer_extra', []);
        $recently_replaced_media = isset($options_extra['recently_replaced_media']) ? $options_extra['recently_replaced_media'] : [];
        $attachment_mime_type = get_post_mime_type($attachment->ID);

        if (in_array($attachment->ID, $recently_replaced_media) 
            && $attachment_mime_type && false !== strpos($attachment_mime_type, 'image')) {
            if (isset($response['url']) && false !== strpos($response['url'], '?')) {
                $response['url'] .= self::maybe_append_timestamp_parameter($response['url']);
            }
            if (isset($response['sizes'])) {
                foreach ($response['sizes'] as $size_name => $size) {
                    if (isset($size['url'])) {
                        $response['sizes'][$size_name]['url'] .= self::maybe_append_timestamp_parameter($size['url']);
                    }
                }
            }
        }

        return $response;       
    }
    
    /**
     * Append cache busting parameter to attachment URL
     */
    public static function append_cache_busting_param_to_attachment_url($url, $attachment_id): string
    {
        // If url is false or not a string, return empty string to prevent errors
        if (!$url || !is_string($url)) {
            return '';
        }

        $options_extra = get_option('sfx_wpoptimizer_extra', []);
        $recently_replaced_media = isset($options_extra['recently_replaced_media']) ? $options_extra['recently_replaced_media'] : [];
        $attachment_mime_type = get_post_mime_type($attachment_id);

        // Check if mime type is valid and attachment is in recently replaced list
        if (in_array($attachment_id, $recently_replaced_media) 
            && $attachment_mime_type && false !== strpos($attachment_mime_type, 'image')) {
            $url .= self::maybe_append_timestamp_parameter($url);
        }

        return $url;
    }
    
    /**
     * Maybe append timestamp parameter
     */
    public static function maybe_append_timestamp_parameter($url): string
    {
        // If url is false or not a string, return empty string to prevent errors
        if (!$url || !is_string($url)) {
            return '';
        }

        $parts = parse_url($url);
        $additional_url_parameter = '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            if (isset($query['t']) && !empty($query['t'])) {
                // Do not add another timestamp parameter
            } else {
                $additional_url_parameter = (false === strpos($url, '?') ? '?' : '&') . 't=' . time();
            }
        } else {
            $additional_url_parameter = (false === strpos($url, '?') ? '?' : '&') . 't=' . time();
        }

        return $additional_url_parameter;
    }

    /**
     * Check if media replacement is enabled
     */
    private static function is_enabled(): bool
    {
        $options = get_option('sfx_wpoptimizer_options', []);
        return isset($options['enable_media_replacement']) && $options['enable_media_replacement'];
    }

}
