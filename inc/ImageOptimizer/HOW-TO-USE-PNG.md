# How to Use PNG/JPG Instead of WebP for Specific Images

## Quick Answer

**Yes!** You can keep specific images in PNG/JPG format using the **"Exclude Images"** feature.

---

## 📋 **3 Ways to Keep Original Formats**

### **Method 1: Exclude Images (Prevent Conversion)**

Use this when you want certain images to NEVER be converted.

**Steps:**

1. Go to **WordPress Admin → SFX Theme Settings → Image Optimizer**

2. Find the **"Exclude Images"** section (left sidebar, middle panel)

3. Click **"Add from Media Library"** button

4. Select the image(s) you want to keep as PNG/JPG

5. Click **"Select"** or **"Insert"**

**Result:**
- ✅ Selected images will **never be converted** to WebP/AVIF
- ✅ They remain in their **original format** (PNG/JPG)
- ✅ Future batch conversions will **skip** these images
- ✅ They appear in the **"Excluded Images"** list

---

### **Method 2: Revert Already Converted Images**

Use this when an image was already converted to WebP but you need the original PNG/JPG back.

**Requirements:**
- ✅ "Preserve Originals" must have been enabled when the image was converted
- ✅ The original PNG/JPG file must still exist on the server

**Steps:**

1. Add this code to your `functions.php` **temporarily**:

```php
add_action('admin_init', function() {
    if (isset($_GET['revert_image']) && current_user_can('manage_options')) {
        $attachment_id = absint($_GET['revert_image']);
        require_once get_stylesheet_directory() . '/inc/ImageOptimizer/revert-to-original.php';
        \SFX\ImageOptimizer\revert_to_original($attachment_id);
        wp_die('Revert complete.');
    }
});
```

2. Find the **Attachment ID** of the image:
   - Go to Media Library
   - Click on the image
   - Look at the URL: `/wp-admin/post.php?post=**123**&action=edit`
   - The number (123) is the Attachment ID

3. Visit: `/wp-admin/?revert_image=123` (replace 123 with your ID)

4. The script will:
   - ✅ Find the original PNG/JPG file
   - ✅ Update WordPress to use the original
   - ✅ Delete the WebP/AVIF version
   - ✅ Add the image to the exclusion list
   - ✅ Show you the results

5. **Remove the code** from `functions.php` when done

**What if the original is missing?**

If you see "ERROR: Original file not found", it means:
- ❌ "Preserve Originals" was OFF when converted
- ❌ The original file was deleted

**Options:**
- Re-upload the original image
- Restore from backup
- Keep using the WebP version

---

### **Method 3: Disable Auto-Conversion + Manual Upload**

Use this workflow when you need full control over what gets converted.

**Steps:**

1. Go to **Image Optimizer** settings

2. Check ☑️ **"Disable Auto-Conversion on Upload"**

3. Upload your images normally

4. Images will stay in their **original format** (PNG/JPG/etc)

5. **Manually select** which images to convert:
   - Use the exclusion feature to mark images to keep as-is
   - Run batch conversion (excluded images won't be touched)

**Pros:**
- ✅ Full control over each image
- ✅ No accidental conversions
- ✅ Can review before converting

**Cons:**
- ❌ Must manually manage conversions
- ❌ Extra steps for each upload

---

## 🎯 **Real-World Use Cases**

### **Use Case 1: Logo with Transparency**

**Scenario:** Your PNG logo has transparency and must stay PNG for print/design use.

**Solution:**
1. Upload logo (or if already uploaded as WebP, revert it)
2. Add to excluded images
3. Logo will always be PNG

---

### **Use Case 2: Product Images (Some PNG, Some WebP)**

**Scenario:** Hero images can be WebP, but product detail shots must be PNG for quality.

**Solution:**
1. Enable auto-conversion (default)
2. Upload all images (they convert to WebP)
3. For product detail shots:
   - Revert them to PNG using Method 2
   - They're automatically added to exclusion list
4. Future uploads are WebP unless you manually exclude them

---

### **Use Case 3: Client Requires Specific Formats**

**Scenario:** Client contract requires certain images stay in PNG format.

**Solution:**
1. Upload images (let them convert to WebP)
2. Identify which images need to be PNG
3. Revert those specific images (Method 2)
4. They're automatically excluded from future conversions

---

## 🔧 **Advanced: Bulk Revert Multiple Images**

If you need to revert MANY images at once, modify the revert script:

Add to `functions.php` temporarily:

```php
add_action('admin_init', function() {
    if (isset($_GET['revert_multiple']) && current_user_can('manage_options')) {
        // Replace these IDs with your attachment IDs
        $ids = [123, 456, 789, 101, 102];
        
        require_once get_stylesheet_directory() . '/inc/ImageOptimizer/revert-to-original.php';
        $results = \SFX\ImageOptimizer\revert_multiple_to_original($ids);
        
        echo '<h1>Bulk Revert Results</h1>';
        echo '<pre>';
        echo 'Success: ' . count($results['success']) . "\n";
        echo 'Failed (no original): ' . count($results['failed']) . "\n";
        echo 'Already original: ' . count($results['already_original']) . "\n";
        echo 'Not found: ' . count($results['not_found']) . "\n";
        print_r($results);
        echo '</pre>';
        
        wp_die('Bulk revert complete.');
    }
});
```

Visit: `/wp-admin/?revert_multiple=1`

---

## ⚙️ **Best Practices**

### **1. Enable "Preserve Originals" (Recommended)**

**Always keep this enabled** if you might need to revert images later.

- ✅ Lets you revert to PNG/JPG anytime
- ✅ Provides a safety net
- ✅ Disk space is cheap
- ❌ Uses more disk space (but worth it)

### **2. Plan Your Exclusion Strategy**

**Before batch converting:**
1. Identify which images MUST stay original
2. Add them to exclusion list FIRST
3. Then run batch conversion
4. Excluded images won't be touched

### **3. Document Your Excluded Images**

Keep a list of which images are excluded and why:
- Logo: needs transparency
- Product X: client requirement
- Banner Y: print usage

### **4. Test Before Production**

On a staging site:
1. Test conversion process
2. Verify exclusions work
3. Test revert process
4. Then apply to production

---

## 📊 **Comparison: Which Method to Use?**

| Scenario | Best Method | Why |
|----------|-------------|-----|
| **New upload, must stay PNG** | Method 1 (Exclude) | Prevents conversion from start |
| **Already WebP, need PNG back** | Method 2 (Revert) | Recovers original if preserved |
| **Want control over everything** | Method 3 (Disable Auto) | Manual workflow |
| **Most images WebP, few PNG** | Method 1 (Exclude) | Easy to manage exceptions |
| **Bulk revert many images** | Advanced (Bulk) | Faster than one-by-one |

---

## 🆘 **Troubleshooting**

### **"Original file not found" when reverting**

**Cause:** Original was deleted (Preserve Originals was OFF)

**Fix:**
1. Re-upload the original image
2. Enable "Preserve Originals" setting
3. Going forward, originals will be kept

### **Excluded image still got converted**

**Cause:** It was converted before being added to exclusion list

**Fix:**
1. Revert the image (Method 2)
2. It will auto-add to exclusion list
3. Won't be converted again

### **Can't find Attachment ID**

**Solution:**
1. Go to Media Library (List View)
2. Hover over the image
3. Look at the URL in browser status bar
4. The number is the Attachment ID

Or use this code temporarily:

```php
add_action('admin_init', function() {
    if (isset($_GET['find_id']) && current_user_can('manage_options')) {
        $filename = sanitize_text_field($_GET['find_id']);
        $args = [
            'post_type' => 'attachment',
            'posts_per_page' => -1,
        ];
        $attachments = get_posts($args);
        echo '<h1>Search Results for: ' . esc_html($filename) . '</h1><pre>';
        foreach ($attachments as $att) {
            if (strpos(get_attached_file($att->ID), $filename) !== false) {
                echo sprintf("ID: %d - %s\n", $att->ID, basename(get_attached_file($att->ID)));
            }
        }
        echo '</pre>';
        wp_die('Search complete.');
    }
});
```

Visit: `/wp-admin/?find_id=my-image.png`

---

## ✅ **Summary**

**Yes, you can definitely use PNG versions!**

- 🎯 **Exclude images** to prevent conversion
- 🔄 **Revert images** back to original format
- ⚙️ **Disable auto-conversion** for manual control
- 📝 **Best practice:** Enable "Preserve Originals"

The ImageOptimizer is flexible - you can convert most images to WebP for performance while keeping specific images in PNG/JPG format when needed!

---

**Need help?** Check the `revert-to-original.php` and `verify-originals.php` scripts in the ImageOptimizer folder.
