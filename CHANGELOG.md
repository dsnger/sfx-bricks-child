<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
