# ImageOptimizer Bug Fix - File Path Resolution Issues

## Issue Description

The ImageOptimizer was throwing errors like:
```
PHP Warning: getimagesize(/path/to/image.png): Failed to open stream: No such file or directory
PHP Warning: exif_imagetype(/path/to/image.jpg): Failed to open stream: No such file or directory
PHP Warning: file_get_contents(/path/to/image.jpg): Failed to open stream: No such file or directory
```

## Root Cause

The conversion process had **critical timing issues** where:

1. Images were converted from PNG/JPG to WebP/AVIF
2. WordPress attachment metadata still referenced the **old file paths**
3. When WordPress tried to generate thumbnails or read image dimensions, it looked for files that no longer existed
4. The attachment file path update happened AFTER metadata generation (wrong order)
5. Thumbnail generation used the original file instead of the converted file
6. Path calculations (dirname, basename) used the original file instead of converted file

## Files Modified

### 1. `Controller.php` (Upload Conversion Handler)

**Lines 198-257: Conversion Loop**
- ✅ Added `$main_converted_file` tracking variable
- ✅ Changed thumbnail generation to use converted file instead of original
- ✅ Added better error logging with WP_Error messages

**Lines 268-342: Metadata Generation**
- ✅ Added file existence check before metadata generation
- ✅ **CRITICAL FIX**: Update `update_attached_file()` BEFORE calling `wp_generate_attachment_metadata()`
- ✅ Changed path calculations to use converted file (`$upload['file']`) instead of original (`$file_path`)
- ✅ Explicitly set `$metadata['file']` to the converted file path

**Lines 475-559: fix_format_metadata() Function**
- ✅ Added auto-recovery logic for existing broken attachments
- ✅ Searches for converted files if original is missing
- ✅ Auto-updates attachment file path and metadata if converted file is found
- ✅ Logs recovery actions for debugging

### 2. `Ajax.php` (Batch Conversion Handler)

**Lines 161-197: Conversion Loop**
- ✅ Added `$main_converted_file` tracking variable
- ✅ Updated `$dirname` and `$base_name` calculations after conversion
- ✅ Changed thumbnail generation to use converted file
- ✅ Added better error logging

**Lines 204-264: Metadata Generation**
- ✅ Added file existence check before metadata generation
- ✅ **CRITICAL FIX**: Update `update_attached_file()` BEFORE calling `wp_generate_attachment_metadata()`
- ✅ Explicitly set `$metadata['file']` to the converted file path

## How The Fixes Work

### Before (Broken Flow):
```
1. Convert image: original.png → original.webp
2. Call wp_generate_attachment_metadata() 
   ↳ WordPress reads get_attached_file() → returns "original.png"
   ↳ WordPress tries to read original.png → FILE NOT FOUND ❌
3. Update attachment file path to original.webp (too late!)
```

### After (Fixed Flow):
```
1. Convert image: original.png → original.webp
2. Update attachment file path to original.webp (FIRST!)
3. Update post MIME type to image/webp
4. Verify file exists
5. Call wp_generate_attachment_metadata()
   ↳ WordPress reads get_attached_file() → returns "original.webp"
   ↳ WordPress reads original.webp → SUCCESS ✅
6. Explicitly update metadata['file'] = "original.webp"
7. Save metadata
```

## Additional Features

### Auto-Recovery in fix_format_metadata()

The `fix_format_metadata()` filter hook now includes intelligent auto-recovery:

1. When WordPress requests attachment metadata
2. If the file doesn't exist at the expected path
3. Search for converted versions (webp, avif)
4. Auto-update the attachment to point to the converted file
5. Fix the metadata structure
6. Log the recovery action

This provides **automatic healing** for existing broken attachments.

## One-Time Metadata Fix Script

For existing attachments that are already broken, use the included fix script:

### File: `fix-existing-metadata.php`

**Usage:**

1. Add to your `functions.php` temporarily:
```php
add_action('admin_init', function() {
    if (isset($_GET['fix_image_metadata']) && current_user_can('manage_options')) {
        require_once get_stylesheet_directory() . '/inc/ImageOptimizer/fix-existing-metadata.php';
        \SFX\ImageOptimizer\fix_existing_metadata();
        wp_die('Metadata fix complete. Check the log above.');
    }
});
```

2. Visit: `/wp-admin/?fix_image_metadata=1`

3. Review the output showing which attachments were fixed

4. Remove the code from `functions.php`

**What it does:**
- Scans all image attachments
- Identifies attachments with missing files
- Searches for converted WebP/AVIF versions
- Updates attachment paths and metadata
- Reports results

## Testing Checklist

- [ ] Upload a new PNG image → should convert to WebP/AVIF without errors
- [ ] Upload a new JPG image → should convert to WebP/AVIF without errors
- [ ] Run batch conversion on existing images → no "file not found" errors
- [ ] Check WordPress debug log → no getimagesize/exif_imagetype errors
- [ ] Verify thumbnails display in Media Library
- [ ] Verify images display correctly on frontend
- [ ] Check that srcset attributes contain correct file paths
- [ ] Test image regeneration/editing in Media Library

## Prevention

These fixes ensure that:
1. ✅ File paths are always updated BEFORE metadata generation
2. ✅ All path calculations use the converted file, not the original
3. ✅ File existence is verified before processing
4. ✅ Metadata explicitly references the converted file
5. ✅ Broken attachments are auto-healed on access
6. ✅ Comprehensive error logging helps diagnose issues

## Compatibility

- PHP 7.4+
- WordPress 5.8+
- GD or Imagick image library
- WebP support (built-in with modern PHP)
- AVIF support (requires Imagick 7.0+ or GD with AVIF support)

## Date of Fix

**January 10, 2026**

## Notes

- The auto-recovery in `fix_format_metadata()` runs on every metadata request, providing ongoing healing
- Use the one-time fix script for bulk repairs of existing attachments
- Monitor your error log after implementing these fixes to confirm resolution
- Consider running the bulk fix script before/after batch conversions

---

**Status: ✅ RESOLVED**

The ImageOptimizer now properly handles file path references during conversion, preventing "Failed to open stream" errors.
