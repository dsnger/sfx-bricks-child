# SFX Bricks Child Theme - Release v0.6.1

## Release Information

- **Version**: 0.6.1
- **Release Date**: 2025-08-17
- **Release Type**: Bug Fix Release
- **Compatibility**: WordPress + Bricks Builder

## What's New in v0.6.1

### 🐛 Bug Fixes

**Bricks Dynamic Data Compatibility**
- Fixed fatal errors when using dynamic data fields in Bricks builder
- Resolved "Argument #1 ($tag) must be of type string, array given" errors
- Contact info and text snippet dynamic tags now work correctly without interfering with other Bricks dynamic data fields

### 🔧 Technical Improvements

- Removed global `bricks/dynamic_data/render_tag` filters that were causing conflicts
- Maintained content-specific filters for proper functionality
- Added robust error handling for array/string type compatibility
- Better separation of concerns between global and content-specific dynamic data processing

### 📁 Files Changed

- `inc/ContactInfos/Controller.php` - Removed problematic filter registration
  
- `inc/TextSnippets/Controller.php` - Removed problematic filter registration
- `style.css` - Version bump to 0.6.1
- `CHANGELOG.md` - Updated with release notes

## Installation

1. Download `sfx-bricks-child-v0.6.1.zip`
2. Go to WordPress Admin → Appearance → Themes
3. Click "Add New" → "Upload Theme"
4. Select the zip file and click "Install Now"
5. Activate the theme

## Compatibility

- **WordPress**: 6.0+
- **Bricks Builder**: 1.9+
- **PHP**: 8.0+

## Previous Version

- **v0.6.0**: Media replacement functionality and WPOptimizer enhancements

---

**Built with ❤️ by [SmileFX](https://smilefx.io/)** 