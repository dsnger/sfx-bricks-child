<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



## [0.4.68] - 2025-01-07

### Fixed

- **WPOptimizer Bricks Builder Compatibility**: Enhanced multiple functions to prevent interference with Bricks Builder
  - **`disable_embed()`**: Added Bricks Builder context checks to prevent `wp-embed` script deregistration
  - **`defer_js()`**: Added Bricks Builder context checks to prevent script deferring in builder
  - **`defer_css()`**: Added Bricks Builder context checks to prevent CSS deferring in builder
  - **Context-Sensitive Options**: Added `disable_embed`, `defer_js`, and `defer_css` to context-sensitive handling
  - **Impact**: Resolves "wp is not defined" errors and ensures Bricks Builder functionality is preserved
  - **Root Cause**: Functions were deregistering/deferring scripts and CSS needed by Bricks Builder
  - **Solution**: Added comprehensive Bricks Builder context checks across all script/CSS optimization functions

## [0.4.67] - 2025-01-07

### Fixed

- **WPOptimizer jQuery Safeguards**: Ensure jQuery and jQuery Migrate are not disabled in Admin or Bricks Builder
  - Added admin and Bricks Builder context checks to `disable_jquery_migrate()` (aligned with `disable_jquery()` logic)
  - Frontend-only disabling preserved for performance, while protecting backend and builder functionality

## [0.4.66] - 2025-01-07

### Enhanced

- **Native HTML Accordion for Post Type Selection**: Improved WPOptimizer settings interface with native HTML accordion
  - **Problem**: Default browser summary marker was still visible in post type selection
  - **Root Cause**: Incomplete CSS styling for hiding default browser markers
  - **Enhanced Implementation**:
    - Added comprehensive CSS to hide default summary markers across all browsers
    - Added Firefox-specific styling (`list-style: none` and `::-moz-list-bullet`)
    - Enhanced accordion styling with proper transitions and hover effects
    - Moved all inline styles to CSS file for better maintainability
    - Added smooth arrow rotation animation when accordion opens/closes
  - **Impact**:
    - ✅ Default browser triangle marker completely hidden
    - ✅ Cross-browser compatibility (Chrome, Firefox, Safari, Edge)
    - ✅ Professional appearance with smooth animations
    - ✅ Clean separation of HTML and CSS
    - ✅ Better user experience in WPOptimizer settings

## [0.4.65] - 2025-01-07

### Fixed

- **WordPress Core jQuery Migrate Disabling**: Fixed jQuery Migrate loading from WordPress core (`wp-includes`)
  - **Problem**: jQuery Migrate was still loading from WordPress core despite being disabled
  - **Root Cause**: WordPress core itself loads jQuery Migrate, not just plugins
  - **Enhanced Implementation**:
    - Added `wp_default_scripts` hook to prevent core registration
    - Added `init` hook for early deregistration
    - Enhanced script dependency removal from WordPress core
    - Added complete script removal from WordPress core scripts object
  - **Impact**:
    - ✅ jQuery Migrate completely prevented from WordPress core loading
    - ✅ No more console messages about jQuery Migrate from wp-includes
    - ✅ Works at the WordPress core level, not just plugin level
    - ✅ Most comprehensive jQuery Migrate disabling approach

## [0.4.64] - 2025-01-07

### Fixed

- **Enhanced jQuery Migrate Disabling**: Fixed issue where jQuery Migrate was still loading despite being disabled
  - **Problem**: Console message "JQMIGRATE: Migrate is installed, version 3.4.1" appearing even when disabled
  - **Root Cause**: Other plugins/themes loading jQuery Migrate after our function runs
  - **Enhanced Implementation**: 
    - Added `wp_head` hook to catch late registrations
    - Added `wp_script_loader_tag` filter to prevent script loading
    - Added `script_loader_tag` filter to remove script tags
    - Added `wp_default_scripts` hook to prevent registration
    - Added `wp_print_scripts` hook for final cleanup
  - **Impact**: 
    - ✅ jQuery Migrate completely prevented from loading
    - ✅ No more console messages about jQuery Migrate
    - ✅ More aggressive and comprehensive disabling approach
    - ✅ Works even when other plugins try to load jQuery Migrate

## [0.4.63] - 2025-01-07

### Changed

- **WPOptimizer Comments Consolidation**: Consolidated all comment-related settings into a single comprehensive option
  - **Removed**: Individual settings for "Limit Comments JS", "Remove Comments Style", "Disable Comment RSS Feeds", and "Disable Comments on Media Attachments"
  - **Enhanced**: Single "Disable Comments" setting now handles all comment functionality comprehensively
  - **Implementation**: 
    - Removed redundant settings from Settings.php
    - Enhanced `disable_comments()` function to include all comment-related functionality
    - Removed individual comment functions (`limit_comments_js`, `remove_comments_style`, `disable_comment_rss_feeds`, `disable_comments_on_attachments`)
    - Consolidated functionality into single `disable_comments()` method
  - **Impact**: 
    - ✅ Cleaner admin interface with single comment control
    - ✅ Simplified user experience - one setting controls all comment features
    - ✅ Reduced code complexity and maintenance overhead
    - ✅ All comment functionality properly disabled when setting is enabled

## [0.4.62] - 2025-01-07

### Fixed

- **WPOptimizer AdminPage Fatal Errors**: Fixed critical PHP errors in AdminPage.php
  - **Fatal Error**: Fixed `in_array(): Argument #2 ($haystack) must be of type array, null given` on line 137
  - **Warning**: Fixed `Undefined array key "limit_revisions_post_types"` on line 128
  - **Root Cause**: Improper handling of null/undefined array values in post type selection
  - **Fix**: Added proper null checks and array validation before using `in_array()` function
  - **Implementation**: 
    - Added `isset()` checks before accessing array keys
    - Added `is_array()` validation before using array values
    - Initialized empty arrays as fallbacks for null values
  - **Impact**: 
    - ✅ WPOptimizer admin page now loads without fatal errors
    - ✅ Post type selection checkboxes work correctly
    - ✅ Revision settings display properly
    - ✅ No more PHP warnings or fatal errors

- **Comments Menu Removal**: Enhanced menu removal to prevent foreach warnings
  - **Added**: Higher priority (999) for menu removal hooks
  - **Added**: Additional submenu removal for discussion settings
  - **Added**: Node existence check before admin bar removal
  - **Impact**: Reduced foreach warnings from menu removal operations

## [0.4.61] - 2025-01-07

### Fixed

- **WPOptimizer Function Issues**: Fixed multiple critical issues in WPOptimizer functions
  - **Indentation Error**: Fixed incorrect indentation in `disable_jquery_migrate()` function
  - **Missing Visibility Modifier**: Added `private` modifier to `check_json_mime_types()` function
  - **Missing Property Declaration**: Added `private $styles = [];` property for `defer_css()` function
  - **Enhanced Error Handling**: Added null checks and proper initialization in `defer_css()` function
  - **Impact**: All WPOptimizer functions now work correctly without PHP errors

- **Comments Menu Removal**: Improved comments disabling functionality
  - **Simplified Approach**: Replaced complex menu removal with clean WordPress functions
  - **Comprehensive Disabling**: Single setting now disables all comment functionality (database, post types, frontend, admin, capabilities, feeds)
  - **No More Warnings**: Eliminated foreach warnings by using proper WordPress API calls

## [0.4.60] - 2025-01-27

### Fixed

- **WYSIWYG Editor HTML Formatting**: Fixed HTML formatting not being saved in Contact Info WYSIWYG editor fields
  - **Issue**: MetaFieldManager was using `sanitize_text_field` for all fields, which strips HTML tags from WYSIWYG editor content
  - **Root Cause**: WordPress's meta field sanitization was overriding the `wp_kses_post()` sanitization used in individual post type save methods
  - **Fix**: Enhanced MetaFieldManager to support HTML fields with `wp_kses_post` sanitization
  - **Implementation**: 
    - Updated `MetaFieldManager::register_fields()` to accept optional `$html_fields` parameter
    - Modified sanitize callback logic to use `wp_kses_post` for HTML fields and `sanitize_text_field` for regular fields
    - Updated ContactInfos PostType to specify `['address', 'opening']` as HTML fields
    - Updated CustomScriptsManager PostType to specify `['script_content']` as HTML field
  - **Impact**: 
    - ✅ Opening Hours WYSIWYG editor now saves HTML formatting (bold, italic, lists, links, etc.)
    - ✅ Formatted Address WYSIWYG editor now saves HTML formatting
    - ✅ Script Content textarea now properly handles HTML content
    - ✅ Backward compatibility maintained for existing functionality
    - ✅ Security preserved using `wp_kses_post()` for proper HTML sanitization

### Changed

- **MetaFieldManager Enhancement**: Added support for HTML field registration
  - **New Parameter**: `$html_fields` array to specify which fields should allow HTML content
  - **Smart Sanitization**: Automatic selection of appropriate sanitize callback based on field type
  - **Maintainability**: Centralized HTML field management for consistent behavior across features

## [0.4.59] - 2025-01-27

### Fixed

- **ImageOptimizer JavaScript Error**: Fixed "ImageOptimizerAjax is not defined" console error
  - **Issue**: JavaScript was trying to access `ImageOptimizerAjax.ajax_url` and `ImageOptimizerAjax.nonce` but these values were never localized
  - **Fix**: Added `wp_localize_script()` in AssetManager to pass AJAX URL and nonce to JavaScript
  - **Impact**: All ImageOptimizer AJAX functionality now works properly (Convert/Scale, Cleanup Images, Fix URLs, etc.)
  - **Implementation**: Added script localization with `admin_url('admin-ajax.php')` and `wp_create_nonce('webp_converter_nonce')`

## [0.4.58] - 2025-01-27

### Fixed

- **Theme Settings Button Links**: Fixed incorrect button URLs for features that use post type edit pages
  - **ContactInfos (Company Informations / Branches)**: Now correctly links to `edit.php?post_type=sfx_contact_info`
  - **CustomScriptsManager**: Now correctly links to `edit.php?post_type=sfx_custom_script`
  - **SocialMediaAccounts**: Now correctly links to `edit.php?post_type=sfx_social_account`
  - **Implementation**: Added `url` field to feature configurations for custom URL handling
  - **Maintainability**: Each feature controls its own URL through feature configuration

- **TextSnippets Feature Exclusion**: Excluded TextSnippets from theme settings page since it's a standalone feature
  - **Added**: `show_in_theme_settings` flag to feature configuration system
  - **Implementation**: TextSnippets now uses `show_in_theme_settings => false` to exclude from admin cards
  - **Benefit**: Cleaner admin interface without redundant standalone feature cards

- **Global Hook Performance Issues**: Fixed critical performance bugs with global `delete_post` hooks
  - **TextSnippets Shortcode**: Changed from global `delete_post` to `delete_post_cpt_text_snippet`
  - **ContactInfos Shortcode**: Changed from global `delete_post` to `delete_post_sfx_contact_info`
  - **SocialMediaAccounts Shortcode**: Changed from global `delete_post` to `delete_post_sfx_social_account`
  - **ContactInfos PostType**: Changed from global `delete_post` to `delete_post_sfx_contact_info`
  - **SocialMediaAccounts PostType**: Changed from global `delete_post` to `delete_post_sfx_social_account`
  - **CustomScriptsManager PostType**: Changed from global `delete_post` to `delete_post_sfx_custom_script`
  - **CustomScriptsManager Controller**: Changed from global `delete_post` to `delete_post_sfx_custom_script`
  - **Performance Impact**: Eliminated unnecessary function calls for unrelated post types
  - **WordPress Best Practices**: Now using proper post-type-specific hooks throughout

### Changed

- **Feature Configuration System**: Enhanced feature registration with custom URL support
  - **Added**: `url` field for features that need custom URLs (post type edit pages)
  - **Added**: `show_in_theme_settings` flag for excluding standalone features
  - **Maintainability**: Each feature controls its own display and URL behavior

## [0.4.57] - 2025-08-03

### Added

- **HTML Copy/Paste for Bricks Builder**: New advanced theme feature to paste regular HTML into Bricks Builder
  - **Core Functionality**: Copy HTML code and paste directly into Bricks Builder to auto-generate elements
  - **Smart Conversion**: Converts HTML elements (divs, headings, images, links, etc.) to proper Bricks element structure
  - **Modal Editor**: Clean dark-themed modal with textarea editor for HTML input
  - **Button Integration**: Seamlessly integrates paste buttons into Bricks Builder toolbar
  - **Root-level Insertion**: Supports pasting at root level when no element is selected
  - **Settings Panel**: Configurable options for HTML conversion behavior, editor mode, and attribute preservation
  - **Theme Integration**: Fully integrated into theme's `/inc/HtmlCopyPaste/` directory structure
  - **Production Ready**: Clean console output with essential error logging only

### Technical Implementation

- **Bricks Builder API**: Direct integration with Bricks' internal Vue.js API for seamless element creation
- **Clipboard API**: Modern clipboard access with fallbacks for browser compatibility
- **Asset Management**: Proper WordPress asset enqueuing with builder context detection
- **Settings API**: WordPress-compliant settings registration and sanitization
- **Cache Busting**: Automatic file versioning for development and production environments

### Changed

- **Asset Naming Convention**: Renamed frontend assets to builder assets for clarity
  - `frontend.css` → `builder.css` (for Bricks Builder interface styling)
  - `frontend.js` → `builder.js` (for Bricks Builder functionality)
  - Updated AssetManager to reflect proper naming (builder vs. frontend user assets)

- **Admin UI Improvements**: Enhanced admin card layout and button alignment
  - **Button Alignment**: All admin feature cards now have buttons aligned at the bottom
  - **Flexbox Layout**: Implemented CSS flexbox for consistent card heights and button positioning
  - **Responsive Design**: Mobile-optimized with full-width buttons on smaller screens
  - **Visual Consistency**: Uniform card appearance regardless of content length

### Fixed

- **Debug Cleanup**: Removed verbose development logging for production readiness
  - **Before**: ~30+ console.log statements flooding browser console
  - **After**: Clean console with essential error reporting only
  - **Retained**: Critical error messages and user operation feedback
  - **Performance**: Slightly improved due to reduced string operations

## [0.4.56] - 2025-08-01

### Fixed

- **ContactInfos PostType**: Fixed array to string conversion error in WYSIWYG editors
  - **Issue**: Batch meta retrieval could return arrays instead of strings for meta values
  - **Fix**: Added array to string conversion for all meta field values before passing to wp_editor()
  - **Impact**: ContactInfos edit pages now load without PHP errors

### Changed

- **Back to List Button**: Simplified button design by removing notice wrapper
  - **Before**: Button wrapped in notice div with background and border
  - **After**: Pure button with direct styling and hover effects
  - **Impact**: Cleaner, more minimal design that integrates better with WordPress admin

## [0.4.55] - 2025-07-31

### Added

- **Back to List Button**: Added "Back to List" button on all custom post type edit pages
  - **Location**: Admin notice area (top of edit pages)
  - **Post Types**: CustomScriptsManager, ContactInfos, SocialMediaAccounts, TextSnippets
  - **Features**: WordPress-styled button with hover effects and responsive design
  - **Implementation**: Global functions in SFXBricksChildTheme class with global CSS styling in backend styles

## [0.4.54] - 2025-07-31

### Fixed

- **ContactInfos Shortcode**: Fixed TypeError where `get_translated_field()` received array instead of string
  - **Issue**: Batch meta retrieval could return arrays instead of strings for meta values
  - **Fix**: Added array to string conversion before calling translation method
  - **Impact**: ContactInfos shortcodes now handle multiple meta values correctly

## [0.4.53] - 2025-07-31

### Fixed

- **ContactInfos Shortcode**: Fixed TypeError where `get_translated_field()` received string instead of integer
  - **Issue**: Cached contact_id from transient was being passed as string to `get_translated_field()`
  - **Fix**: Added integer casting and validation for contact_id before calling translation method
  - **Impact**: ContactInfos shortcodes now work correctly without type errors

## [0.4.52] - 2025-07-31

### Fixed

- **GitHubThemeUpdater**: Fixed transient caching issue where "update available" message persisted after theme update
  - **Issue**: WordPress cached `update_themes` transient wasn't cleared after theme updates
  - **Fix**: Added hooks to clear transient on theme updates and version changes
  - **Impact**: Update notifications now properly disappear after theme updates

## [0.4.51] - 2025-07-31

### Fixed

- **ContactInfos Controller**: Fixed fatal error `Call to a member function render_contact_info() on null` in Bricks dynamic tags
  - **Issue**: `self::$shortcode_instance` was null because it was never properly initialized
  - **Fix**: Properly initialize shortcode instance in constructor and add safety check in render method
  - **Impact**: ContactInfos dynamic tags now work correctly in Bricks builder

## [0.4.50] - 2025-07-31

### Added

- **Performance Optimizations (CRITICAL)**:
  - **Database Query Optimization**: Implemented batch `get_post_meta()` calls to eliminate N+1 query problems across all features
  - **Hook Priority Standardization**: Standardized all `admin_enqueue_scripts` hooks to priority `20` for consistent loading order
  - **Conditional Loading**: Implemented `should_load_assets()` methods for all AssetManagers to load assets only where needed
  - **Select2 Asset Optimization**: Removed Select2 loading from features that don't use it, kept only in CustomScriptsManager
  - **Autoloader Performance**: Replaced manual class map with clean PSR-4 compliant fallback autoloader
  - **Dynamic Admin Page Detection**: Replaced hardcoded `$allowed_pages` array with dynamic detection using `sfx_is_theme_related_page()` and `sfx_get_theme_post_types()`
  - **Hook Consolidation**: Centralized and phased `init` and `admin_init` hooks for better performance and consistency
  - **Sitemap Exclusion System**: Added automatic exclusion of private post types (`sfx_custom_script`, `