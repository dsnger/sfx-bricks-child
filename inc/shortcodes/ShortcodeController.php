<?php

namespace SFX\Shortcodes;

/**
 * Shortcode Controller
 * 
 * Manages the initialization of all shortcode classes
 * 
 * @package WordPress
 * @subpackage sfxtheme
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class ShortcodeController
{
    /**
     * Shortcode classes to initialize
     * 
     * @var array
     */
    private $shortcodes = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->register_shortcodes();
        $this->init_shortcodes();
    }

    /**
     * Register available shortcodes
     */
    private function register_shortcodes()
    {
        // Register shortcode classes - add new shortcodes here
        $this->shortcodes = [
            // Class name => dependencies check method (optional)
            \SFX\Shortcodes\IconifyIcon::class => 'check_iconify_enabled',
            \SFX\Shortcodes\ContactInfos::class => null,
            \SFX\Shortcodes\Logo::class => null,
            // Add other shortcode classes here
        ];
    }

    /**
     * Initialize all registered shortcodes
     */
    private function init_shortcodes()
    {
        // Skip when in builder
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return;
        }

        foreach ($this->shortcodes as $class => $dependency_check) {
            // Check if there's a dependency check method
            if (!empty($dependency_check) && method_exists($this, $dependency_check)) {
                // If the dependency check fails, skip this shortcode
                if (!$this->{$dependency_check}()) {
                    continue;
                }
            }
            
            // Initialize the shortcode class
            if (class_exists($class)) {
                new $class();
            }
        }
    }
    
    /**
     * Check if iconify is enabled in theme options
     * 
     * @return bool True if iconify is enabled, false otherwise
     */
    private function check_iconify_enabled()
    {
        // Check if ACF is active
        if (!function_exists('get_field')) {
            return false;
        }
        
        // Get the iconify setting from ACF options
        return get_field('enable_iconify', 'option') === true;
    }
} 