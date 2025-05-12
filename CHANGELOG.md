<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.5] - 2025-05-12

### Added

- Hook for Pixrefiner: When an attachment is deleted, this hook will also look for any preserved original files and delete them.

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

- PixRefiner image optimization:
  - Fixed issue where checkbox settings (AVIF Conversion, Preserve Original Files, Disable Auto-Conversion on Upload) had no save buttons
  - Added automatic saving functionality for checkbox options via AJAX
  - Implemented event handlers in JavaScript for immediate settings update
  - Added corresponding server-side handlers for these settings
  - Improved user feedback with log messages for setting changes

## [0.2.1] - 2025-05-11

### Added

- Theme Settings Page with infos and shortlinks to theme settings.
- Enhanced PixRefiner image optimization with:
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
