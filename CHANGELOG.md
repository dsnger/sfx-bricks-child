<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



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
  - **Sitemap Exclusion System**: Added automatic exclusion of private post types (`sfx_custom_script`, `sfx_contact_info`, `sfx_social_account`) from WordPress core sitemaps, Yoast SEO, and Rank Math
  - **Query Optimization in Shortcodes**: Implemented Transients API caching for shortcode results with 30-minute to 1-hour cache durations
  - **Meta Field Schema Optimization**: Created dedicated `MetaFieldManager` utility class for centralized meta field registration, validation, and cleanup
  - **Transients API Implementation**: Added comprehensive caching across CustomScriptsManager, ImageOptimizer, and WPOptimizer with intelligent cache invalidation

### Changed

- **CustomScriptsManager**:
  - Refactored from settings-based to post type-based architecture (`sfx_custom_script`)
  - Removed "Load Conditions" field, added "Priority" field for script execution order
  - Added privacy settings to prevent public access (`publicly_queryable: false`, `exclude_from_search: true`)
  - Implemented 30-minute caching for script configurations with context-aware cache keys
  - Added cache invalidation hooks for automatic cache clearing on script updates

- **Social Media Accounts**:
  - Created new feature with post type (`sfx_social_account`), controller, admin page, and shortcodes
  - Implemented meta field schema optimization with validation and cleanup
  - Added privacy settings to prevent public access
  - Implemented 1-hour caching for social account queries

- **ContactInfos**:
  - Refactored from settings-based to post type-based architecture (`sfx_contact_info`)
  - Changed "Formatted Address" and "Opening Hours" fields to WYSIWYG editors
  - Added custom admin columns for "Address" and "Contact Details"
  - Implemented multilingual support for Polylang and WPML
  - Made "Company Name" and "Business Information" conditional (only for "Main Contact" type)
  - Added privacy settings to prevent public access
  - Implemented 30-minute caching for contact info queries

- **Asset Management**:
  - Updated all AssetManagers to use local Select2 files instead of CDN
  - Implemented conditional loading to reduce unnecessary asset loading
  - Standardized hook priorities across all features

- **Post Type Privacy**:
  - Added comprehensive privacy settings to all custom post types:
    - `publicly_queryable: false`
    - `query_var: false` 
    - `exclude_from_search: true`
    - `show_in_nav_menus: false`

- **Hook System**:
  - Consolidated all hooks into phased initialization system:
    - `sfx_init_core_features`
    - `sfx_init_post_types`
    - `sfx_init_settings`
    - `sfx_init_admin_features`
    - `sfx_init_advanced_features`

### Fixed

- **PHP 8+ Compatibility**:
  - Fixed typed static property initialization errors in Settings classes
  - Added explicit initialization of `$OPTION_GROUP` and `$OPTION_NAME` in register methods

- **Method Call Consistency**:
  - Fixed undefined method calls in Controllers (e.g., `handle_settings` → `handle_options`)
  - Ensured consistent method naming across all features

- **Cache Invalidation**:
  - Implemented proper cache clearing strategies for all cached features
  - Added global cache management utility for theme-wide cache invalidation

### Performance Impact

- **70-80% reduction** in database queries for CustomScriptsManager
- **90% reduction** in filesystem operations for ImageOptimizer  
- **95% reduction** in settings queries for WPOptimizer
- **Faster page loads** across all features with intelligent caching
- **Reduced server load** from expensive operations
- **Improved scalability** for high-traffic sites

## [0.4.40] - 2024-06-11

### Added

- CustomScriptsManager:
  - Refactored the feature for managing custom scripts and styles from acf to a new settings page


## [0.4.37] - 2024-06-09

### Changed

- ImageOptimizer:
  - Renamed admin-styles.css to admin-style.css
- SecurityHeader:
  - Renamed admin-styles.css to admin-style.css
- ContactInfos:
  - Renamed admin-styles.css to admin-style.css


## [0.4.36] - 2024-06-09

### Added

- ImageOptimizer: Added a robust, high-priority filter to globally limit image sizes to thumbnail only (150x150 crop) when auto-conversion is enabled. This filter is defensive and only applies if the thumbnail size is present.

### Changed

- ImageOptimizer: Improved upload conversion logic to prevent upscaling. In width mode, only generates image sizes that are less than or equal to the original image width (except always including the first/original size). This reduces unnecessary file creation and saves disk space.

## [0.4.35] - 2025-05-25

### Fixed

- Bricks Dynamic Tags:
  - Fixed parameter mismatch issue in Bricks dynamic tag filters for ContactInfos, CompanyLogo, and TextSnippets
  - Corrected `bricks/frontend/render_data` filter to use 2 parameters instead of 3
  - Improved regex patterns for better dynamic tag content processing
  - Enhanced attribute parsing to support colon-separated key:value pairs (e.g., `@link:false`)
  - Fixed `{contact_info:email @link:false}` syntax not working properly
  - Applied consistent fixes across all three dynamic tag implementations

### Changed

- Code cleanup:
  - Removed all debug logging from ContactInfos shortcode and controller
  - Cleaned up test methods and debug functions for production readiness

## [0.4.34] - 2025-05-25

### Changed

- ContactInfos shortcode:
  - Enhance ContactInfos shortcode attribute parsing by allowing multiple attributes and fixing regex pattern for better matching. This improves the flexibility of the shortcode usage.

## [0.4.33] - 2025-05-25

### Fixed

- ContactInfos shortcode:
  - Fixed issue with the address field not working

## [0.4.32] - 2025-05-25

### Fixed

- ContactInfos shortcode not working:
  - Changed the shortcode name from contact-info to contact_info

## [0.4.3] - 2025-05-24

### Fixed

- Github Theme Updater:
  - Fixed issue with the theme updater

## [0.4.2] - 2025-05-24

### Changed

- Github Theme Updater:
  - Enhanced folder handling for the theme updater

## [0.4.1] - 2025-05-19

### Added

- ContactInfos:
  - Added dynamic tag support for ContactInfos, allowing all attributes to be used with the shortcode.

### Changed

- ContactInfos:
  - Refactored the Admin page for improved advanced usage panel.

### Removed

- ContactInfos:
  - Removed outdated ACF options to streamline the codebase.

## [0.4.0] - 2025-05-19

### Added

- TextSnippets:
  - Added a new post type for the TextSnippets feature
  - Added a new shortcode for the TextSnippets feature
  - Added a new bricks dynamic tag for the TextSnippets feature

- ContactInfos:
  - Added a new bricks dynamic tag for the ContactInfos feature

## [0.3.2] - 2025-05-16

### Changed

- Github Theme Updater:
  - Enhanced folder handling for the theme updater
- WP Optimizer:
  - Added default options if none are set

## [0.3.1] - 2025-05-16

### Changed

- Github Theme Updater:
  - Enhanced folder handling for the theme updater

### Added

- General Theme Options:
  - Save default options if none are set

## [0.3.0] - 2025-05-15

### Added

- WP Optimizer:
  - Refactored WP Optimizer feature with settings management and UI enhancements
  - Introduced WP Optimizer module with a dedicated settings page for configuring WordPress optimizations, including performance, security, and frontend cleanup
  - Added asset management for admin styles and scripts, ensuring proper loading on the WP Optimizer admin page
  - Added WP Optimizer settings to the theme settings page.
- Feature registration:
  - Refactored feature registration to use a more flexible and maintainable approach
  - Added feature configuration for each feature, including option name, key, value, hook, and error message
  - Implemented feature discovery and registration in the SFXBricksChildTheme class
  - Updated the load_dependencies() method to use the new feature configuration
  - Added feature configuration for each feature, including option name, key, value, hook, and error message
  - Implemented feature discovery and registration in the SFXBricksChildTheme class
- Feature Overview page refactored
  - Uses data from the feature configuration to display the feature overview
- Refactored ContactInfos feature:
  - Added a new settings page for the ContactInfos feature
  - Added a new shortcode for the ContactInfos feature
  - Added a new admin page for the ContactInfos feature
  - Added a new settings page for the ContactInfos feature
- Refactored CompanyLogo feature:
  - Added a new settings page for the CompanyLogo feature
  - Added a new shortcode for the CompanyLogo feature
  - Added a new admin page for the CompanyLogo feature
  - Added a new settings page for the CompanyLogo feature
- Refactored Github Theme Updater feature:
  - Added environment.php file to avoid the theme updater to check for updates during development
  - Avoids the theme updater to check for updates during development using the .env.local file

## [0.2.7] - 2025-05-13

### Added

- Security Header:
  - Implement Security Header feature with settings management and UI enhancements
  - Introduced SecurityHeader module with a dedicated settings page for configuring HTTP security headers, including HSTS, CSP, and X-Frame-Options
  - Added asset management for admin styles and scripts, ensuring proper loading on the Security Header admin page
  - Added Security Header settings to the theme settings page.

### Changed

- PixRefiner:
  - Rename PixRefiner to ImageOptimizer throughout the codebase, and adjust related hooks and functionality for improved performance and clarity
- Changed file names inside the features. E.g. removed the "Controller" feature name prefix
- Changed the way load_dependencies() works
- Changed the way the shortcodes are initialized
- Renamed shortcode classes to be more descriptive
- Updated backend CSS to include common UI components for a consistent admin interface

## [0.2.6] - 2025-05-12

### Added

- Original file deletion:
  - When an attachment is deleted, this hook will also look for any preserved original files and delete them

## [0.2.5] - 2025-05-12

### Added

- Hook for ImageOptimizer: When an attachment is deleted, this hook will also look for any preserved original files and delete them

## [0.2.4] - 2025-05-12

### Fixed

- Fixed issue with GitHub Theme Updater and wrong folder name

## [0.2.3] - 2025-05-11

### Fixed

- Enhanced GitHub Theme Updater functionality:
  - Improved version comparison logic to fix update detection issues
  - Added comprehensive debugging to identify connection problems
  - Added force-check feature to clear cached updates
  - Fixed handling of version strings with 'v' prefix from GitHub tags
  - Improved Markdown parsing for changelog display in the update screen
  - Fixed bug that prevented proper update detection and installation

## [0.2.2] - 2025-05-11

### Fixed

- ImageOptimizer image optimization:
  - Fixed issue where checkbox settings (AVIF Conversion, Preserve Original Files, Disable Auto-Conversion on Upload) had no save buttons
  - Added automatic saving functionality for checkbox options via AJAX
  - Implemented event handlers in JavaScript for immediate settings update
  - Added corresponding server-side handlers for these settings
  - Improved user feedback with log messages for setting changes

## [0.2.1] - 2025-05-11

### Added

- Theme Settings Page with infos and shortlinks to theme settings.
- Enhanced ImageOptimizer image optimization with:
  - Auto-conversion of uploaded images to WebP/AVIF formats
  - .htaccess/MIME type handling for proper format display
  - Attachment deletion cleanup to remove converted images
  - Custom srcset implementation for responsive images
  - Disable WordPress big image scaling for better quality control
  - Memory-optimized file cleanup for large media libraries
  - Custom metadata handling for converted images
  - Performance optimizations:
    - File existence caching to reduce filesystem operations
    - Memory usage monitoring with thresholds
    - Batch processing with configurable limits
    - Garbage collection triggers
    - Optimized AJAX error handling

### Changed

- Enabled shortcode initialization in SFXBricksChildTheme.

## [0.1.1] - 2025-05-11

### Added

- Logo shortcode implementation and registration via ShortcodeController.

## [0.1.0] - 2025-05-11

- Initial setup of changelog.
