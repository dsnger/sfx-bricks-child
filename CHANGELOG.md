<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



## [0.7.0] - 2025-12-03

### Added

- **Custom Dashboard Module**: New admin dashboard replacement with extensive customization
  - Custom welcome message with user personalization
  - Brand color management with primary/secondary colors and reset functionality
  - Quicklinks management with sortable UI for custom dashboard links
  - Custom CSS injection for dashboard styling
  - Media editor integration for brand assets
  - Color mode settings with light/dark theme toggle
  - Dashboard gap/layout configuration
  - Form submissions, stats, and system info providers

### Enhanced

- **Access Control**: Two-tier access control system for theme settings and dashboard
- **Uninstall Cleanup**: Added `uninstall.php` to properly clean up theme data on removal

## [0.6.5] - 2025-01-15

### Enhanced

- **SecurityHeader Module**: Improved security header delivery with multiple methods
  - Added direct header sending via `header()` function for better compatibility
  - Implemented multiple hooks (`send_headers`, `template_redirect`) to ensure headers are sent regardless of caching
  - Added duplicate header prevention to avoid conflicts
  - Improved Permissions-Policy handling to only add header when value is not empty
  - Refactored code to extract shared `build_headers_array()` method
  - Maintained backward compatibility with `wp_headers` filter

## [0.6.4] - 2025-01-15

### Enhanced

- **jQuery Migrate Handling**: Improved jQuery Migrate disabling to be frontend-only
  - Added `is_admin()` checks to prevent jQuery Migrate disabling in admin areas
  - Replaced multiple hooks with single `template_redirect` hook for better control
  - Simplified logic and removed redundant Bricks Builder context checks
  - Preserves admin functionality while maintaining frontend performance optimization

### Changed

- **Version Update**: Incremented theme version to 0.6.4 for maintenance and tracking purposes

## [0.6.3] - 2025-09-01

### Fixed

- **Bricks Builder Compatibility**: Fixed critical regression in Bricks Builder dynamic data functionality
  - **Issue**: Fatal errors when using dynamic data fields in Bricks builder (regression from v0.6.2)
  - **Root Cause**: `bricks/dynamic_data/render_tag` filter was accidentally re-added in ContactInfos Controller
  - **Fix**: Removed problematic `bricks/dynamic_data/render_tag` filter to restore v0.6.1 compatibility
  - **Impact**: 
    - ✅ Bricks Builder dynamic data fields work correctly again
    - ✅ No more fatal errors when using dynamic data in Bricks
    - ✅ Contact info functionality remains intact
    - ✅ Restored compatibility with Bricks framework

## [0.6.2] - 2025-09-01

### Added

- **Contact Info Cache Management**: Added manual cache clearing functionality for Contact Info module
  - **New Feature**: "Clear Cache" button in Contact Info admin list page
  - **Functionality**: Clears all contact info caches including type-based and field-specific caches
  - **Security**: Nonce protection and user permission checks
  - **User Experience**: Success message confirmation and automatic page refresh
  - **Use Cases**: Useful for troubleshooting, development, and ensuring fresh data after updates

### Fixed

- **Contact Info Bricks Dynamic Content**: Fixed individual field selection in Bricks Builder
  - **Issue**: Only generic `{contact_info}` tag was available in Bricks dynamic content picker
  - **Fix**: Each contact info field now available as individual dynamic tag (e.g., `{contact_info:vat}`, `{contact_info:address}`)
  - **Implementation**: Registered individual fields as separate dynamic tags in Bricks picker
  - **Address Field Logic**: Enhanced address field to return "full address" if available, otherwise build from street/ZIP/city with line breaks
  - **Type Safety**: Fixed type casting issues for contact_id parameters
  - **Meta Data Handling**: Improved array-to-string conversion for meta field values

### Removed

- **Company Logo Feature**: Completely removed Company Logo functionality from theme
  - **Files Removed**: All CompanyLogo directory files and assets
  - **Code Cleanup**: Removed all references and documentation mentions
  - **Settings Cleanup**: Removed font MIME types setting (no longer needed)

## [0.6.1] - 2025-08-17

### Fixed

- **Bricks Dynamic Data Compatibility**: Fixed fatal errors when using dynamic data fields in Bricks builder
  - **Issue**: Fatal error "Argument #1 ($tag) must be of type string, array given" when using dynamic data fields
  - **Root Cause**: ContactInfos and TextSnippets controllers were registering global `bricks/dynamic_data/render_tag` filters that interfered with all dynamic data rendering
  - **Fix**: Removed global `bricks/dynamic_data/render_tag` filters and kept only content-specific filters
  - **Implementation**: 
    - Removed `bricks/dynamic_data/render_tag` filter registration from all three controllers
    - Kept `bricks/dynamic_data/render_content` and `bricks/frontend/render_data` filters for content processing
    - Maintained backward compatibility for contact info and text snippet functionality
    - Added robust error handling for array/string type compatibility
  - **Impact**: 
    - No more fatal errors when using dynamic data fields in Bricks
    - Dynamic image data, post titles, and other Bricks dynamic fields work correctly
    - Contact info and text snippet tags still work in content areas
    - Better separation of concerns between global and content-specific dynamic data processing
    - Improved compatibility with Bricks framework updates

## [0.6.0] - 2025-08-15

### Added

- **Media Replacement Functionality**: Complete media replacement system integrated into WPOptimizer
  - **New Feature**: Replace media files while preserving IDs, URLs, and metadata
  - **Admin Integration**: "Replace Media" button appears on attachment edit screens
  - **AJAX-Based**: Modern, non-blocking replacement process with real-time feedback
  - **Smart File Handling**: Automatic thumbnail regeneration and metadata updates
  - **Cache Busting**: Built-in timestamp system ensures immediate visibility of replaced media
  - **Security**: Nonce verification, user permission checks, and file validation
  - **User Experience**: Loading states, success/error messages, and automatic page refresh
  - **File Safety**: Comprehensive validation prevents accidental file deletion or corruption

### Enhanced

- **WPOptimizer Module**: Extended with new media replacement capabilities
  - **Asset Management**: CSS and JavaScript files for media replacement interface
  - **Settings Integration**: Media replacement option in WPOptimizer settings
  - **Context Awareness**: Only shows on full attachment edit screens, not in modals
  - **Error Handling**: Graceful failures with user-friendly error messages
  - **Performance**: Efficient file operations with fallback copy methods

## [0.5.0] - 2025-01-07

### Enhanced

- **Content Order Functionality**: Added comprehensive content ordering system for hierarchical post types
  - **New Feature**: Drag & drop interface for reordering posts, pages, and custom post types
  - **Post Type Support**: Automatic detection of hierarchical post types and those supporting page attributes
  - **Performance Optimized**: Hardware-accelerated dragging with smooth animations and responsive feedback
  - **Admin Integration**: New "Order" submenu under each supported post type for easy access
  - **Asset Management**: Efficient loading of required libraries (jQuery UI, nestedSortable) only when needed
  - **User Experience**: Visual feedback during operations, success/error states, and mobile-responsive design

## [0.4.73] - 2025-08-14

Fixed: admin/Bricks guards for defer CSS/JS; Fixed: invalid loadCSS entries; Changed: safer admin menu removal timing; Fixed: REST/context options timing; Fixed: search redirect hook timing


### Enhanced

- **Font Optimization**: Improved CSS font rendering and performance
  - Added missing `-moz-text-size-adjust` vendor prefix for Firefox
  - Moved `backface-visibility` from universal selector to body only for better performance
  - Enhanced cross-browser compatibility and code organization

## [0.4.71] - 2025-01-07

### Fixed

- **Critical Environment.php Missing File Error**: Fixed fatal PHP error when Environment.php file is missing from deployed theme
  - **Issue**: Fatal error "Failed opening required '/inc/Environment.php'" causing site to crash
  - **Root Cause**: Environment.php file missing from deployed theme package
  - **Fix**: Added file existence check before requiring Environment.php
  - **Implementation**: 
    - Added `file_exists()` check before requiring Environment.php
    - Added `class_exists()` checks before using Environment class methods
    - Graceful fallback when Environment class is not available
    - Prevents fatal errors when files are missing from deployment
  - **Impact**: 
    - ✅ Site no longer crashes when Environment.php is missing
    - ✅ Graceful degradation when optional files are not present
    - ✅ Maintains functionality even with missing files
    - ✅ Better error handling for deployment issues

## [0.4.70] - 2025-01-07

### Fixed

- **ContactInfos WYSIWYG Editor HTML Rendering**: Fixed HTML content from WYSIWYG editors being output as text instead of rendered HTML
  - **Issue**: HTML tags like `<strong>`, `<em>`, `<br>` were being displayed as text (`&lt;strong&gt;`) instead of rendered formatting
  - **Root Cause**: Shortcode was using `esc_html()` instead of `wp_kses_post()` for WYSIWYG editor fields
  - **Fix**: Updated shortcode rendering methods to properly handle HTML content from WYSIWYG editors
  - **Implementation**: 
    - **Address Field**: Changed from `nl2br(esc_html($value))` to `wp_kses_post($value)` in `render_address_field()`
    - **Opening Field**: Added dedicated `render_opening_field()` method using `wp_kses_post($value)`
    - **Span Wrapper Removal**: Removed unnecessary `<span>` wrapper for WYSIWYG fields to prevent HTML structure conflicts
    - **Debug Mode**: Added `debug` parameter to shortcode for troubleshooting HTML content issues
    - **Enhanced Cache Clearing**: Improved cache invalidation to ensure fresh HTML content retrieval
  - **Impact**: 
    - ✅ Opening Hours WYSIWYG editor content now renders with proper HTML formatting
    - ✅ Formatted Address WYSIWYG editor content now renders with proper HTML formatting
    - ✅ HTML tags like `<strong>`, `<em>`, `<br>`, `<ul>`, `<li>`, `<a>` render correctly
    - ✅ No more unwanted `<span>` wrappers around WYSIWYG content
    - ✅ Security maintained using `wp_kses_post()` for safe HTML rendering
    - ✅ Backward compatibility preserved for existing functionality
    - ✅ Debug mode available for troubleshooting: `[contact_info field="opening" debug="1"]`

### Enhanced

- **ContactInfos Shortcode Debugging**: Added debug mode for troubleshooting HTML content issues
  - **New Parameter**: `debug` attribute for shortcode to display raw database content
  - **Usage**: `[contact_info field="opening" debug="1"]` shows raw HTML content from database
  - **Benefit**: Easier troubleshooting of HTML rendering issues and content validation

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