<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Initial setup of changelog.

## [0.1.1] - 2025-05-11

### Added

- Logo shortcode implementation and registration via ShortcodeController.

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
