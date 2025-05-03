<?php

namespace SFX\Options;

defined('ABSPATH') or die('Pet a cat!');


class ControllerHelper
{

    static $custom_download_folder = 'sfx-custom-scripts';

    /**
     * Retrieve the appropriate CDN link for the requested script.
     *
     * @param string $script_name The name of the script.
     * @return string The CDN link.
     */
    public static function get_cdn_link($script_name)
    {
        $cdn_links = [
            'iconify' => 'https://code.iconify.design/2/2.1.2/iconify.min.js',
            'alpine' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.10.2/dist/cdn.min.js',
            'alpine_ajax' => 'https://cdn.jsdelivr.net/npm/alpinejs-ajax@0.1.1/dist/alpine-ajax.min.js',
            'alpine_anchor' => 'https://cdn.jsdelivr.net/npm/alpinejs-anchor@0.1.1/dist/alpine-anchor.min.js',
            'alpine_collapse' => 'https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.9.6/dist/cdn.min.js',
            'alpine_focus' => 'https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.9.6/dist/cdn.min.js',
            'alpine_intersect' => 'https://cdn.jsdelivr.net/npm/@alpinejs/intersect@3.9.6/dist/cdn.min.js',
            'alpine_mask' => 'https://cdn.jsdelivr.net/npm/alpinejs-mask@0.6.2/dist/cdn.min.js',
            'alpine_morph' => 'https://cdn.jsdelivr.net/npm/alpinejs-morph@3.10.2/dist/cdn.min.js',
            'alpine_persist' => 'https://cdn.jsdelivr.net/npm/@alpinejs/persist@3.9.6/dist/cdn.min.js',
            'gsap' => 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.10.4/gsap.min.js',
            'scrolltrigger' => 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.10.4/ScrollTrigger.min.js',
            'locomotive_scroll' => 'https://cdn.jsdelivr.net/npm/locomotive-scroll@4.1.4/dist/locomotive-scroll.min.js',
            'locomotive_scroll_css' => 'https://cdn.jsdelivr.net/npm/locomotive-scroll@4.1.4/dist/locomotive-scroll.min.css',
            'aos' => 'https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.min.js',
            'aos_css' => 'https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.min.css',
        ];

        return $cdn_links[$script_name] ?? '';
    }



    public static function download_cdn_script($cdn_url_key, $handle)
    {
        $cdn_url = self::get_cdn_link($cdn_url_key);
        if (!$cdn_url) {
            error_log("CDN URL not found for key: $cdn_url_key");
            return '';
        }

        // Get the uploads directory
        $upload_dir = wp_upload_dir();

        // Set the custom folder path
        $custom_path = trailingslashit($upload_dir['basedir']) . self::$custom_download_folder;
        $custom_url = trailingslashit($upload_dir['baseurl']) . self::$custom_download_folder;

        // Ensure the custom folder exists
        if (!file_exists($custom_path)) {
            $created = wp_mkdir_p($custom_path); // Create the directory if it doesn't exist
            if (!$created) {
                error_log("Failed to create directory: $custom_path");
                return ''; // Return empty string if directory creation failed
            } else {
                error_log("Directory created successfully: $custom_path");
            }
        } else {
            error_log("Directory already exists: $custom_path");
        }

        // Generate the file path and URL
        $filename = $handle . '-' . md5($cdn_url) . '.js';
        $file_path = $custom_path . '/' . $filename;
        $file_url = $custom_url . '/' . $filename;

        // Check if the file already exists
        if (!file_exists($file_path)) {
            // Download the file from the CDN
            $response = wp_remote_get($cdn_url);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log("Failed to download script from CDN: $cdn_url");
                return $cdn_url; // Fallback to CDN if download fails
            }

            // Save the file to the custom folder
            $file_content = wp_remote_retrieve_body($response);
            file_put_contents($file_path, $file_content);
            error_log("Script downloaded and saved: $file_path");
        } else {
            error_log("Script already exists, no download needed: $file_path");
        }

        return $file_url;
    }


    public static function delete_script_file($handle)
    {
        // Get the uploads directory
        $upload_dir = wp_upload_dir();

        // Set the custom folder path
        $custom_path = trailingslashit($upload_dir['basedir']) . self::$custom_download_folder;

        // Locate the file using a wildcard (e.g., iconify-[hash].js)
        $files = glob($custom_path . '/' . $handle . '-*.js');

        $deleted = false;
        foreach ($files as $file) {
            if (file_exists($file)) {
                $deleted = unlink($file); // Delete the file
            }
        }

        return $deleted;
    }


    public static function generate_aos_init_script($settings)
    {
        $script = 'AOS.init({';
        $script .= 'duration: ' . ($settings['default_duration'] ?? 400) . ',';
        $script .= 'easing: "' . ($settings['default_easing'] ?? 'ease') . '",';
        $script .= 'offset: ' . ($settings['default_offset'] ?? 120) . ',';
        $script .= 'delay: ' . ($settings['default_delay'] ?? 0) . ',';
        $script .= 'once: ' . (($settings['once'] ?? true) ? 'true' : 'false') . ',';
        $script .= '});';

        return $script;
    }
}
