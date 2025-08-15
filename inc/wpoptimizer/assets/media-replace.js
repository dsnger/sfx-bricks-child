(function($) {
    'use strict';

    $(document).ready(function() {
        // Place the Replace Media section after the side metaboxes
        $('#media-replace-div').appendTo('#postbox-container-1');
    });

})(jQuery);

// Open media modal on clicking Select New Media File button. 
// This is set with onclick="replaceMedia('mime/type')" on the button HTML
function replaceMedia(originalAttachmentId, oldImageMimeType) {
    if (oldImageMimeType) {
        // There's a mime type defined. Do nothing.
    } else {
        // We're in the grid view of an image. Get the mime type form the file info in DOM.
        if (jQuery('.details .file-type').length) {
            var oldImageMimeTypeFromDom = jQuery('.details .file-type').html();
        }
        // Sometimes .file-type div is not there, and instead, a second .filename div is used to display file type info
        else if (jQuery('.details .filename:nth-child(2)').length) {
            var oldImageMimeTypeFromDom = jQuery('.details .filename:nth-child(2)').html();
        }
        // Replace '<strong>File type:</strong>' in any language with empty string
        oldImageMimeTypeFromDom = oldImageMimeTypeFromDom.replace(/<strong>(.*?)<\/strong>/, '');
        // Replace one blank spacing with an empty space / no space
        oldImageMimeType = oldImageMimeTypeFromDom.replace(' ', '');
    }

    // https://codex.wordpress.org/Javascript_Reference/wp.media
    // https://github.com/ericandrewlewis/wp-media-javascript-guide

    // Instantiate the media frame
    var mediaFrame = wp.media({
        title: 'Select New Media File',
        button: {
            text: 'Perform Replacement'
        },
        multiple: false // Enable/disable multiple select
    });

    // Open the media dialog and store it in a variable
    var mediaFrameEl = jQuery(mediaFrame.open().el);

    // Open the "Upload files" tab on load
    mediaFrameEl.find('#menu-item-upload').click();

    // When an image is selected
    mediaFrameEl.on('click', 'li.attachment', function(e) {
        var mimeTypeWarning = '<div class="mime-type-warning">The selected image is of a different type than the image to replace. Please choose an image with the same type.</div>';
        var selectedAttachment = mediaFrame.state().get('selection').first().toJSON();
        var selectedAttachmentMimeType = selectedAttachment.mime;

        if (oldImageMimeType != selectedAttachmentMimeType) {
            jQuery('.media-frame-toolbar .media-toolbar-primary .mime-type-warning').remove();
            jQuery('.media-frame-toolbar .media-toolbar-primary').prepend(mimeTypeWarning);
            jQuery('.media-frame-toolbar .media-toolbar-primary .media-button-select').prop('disabled', true);
        } else {
            jQuery('.media-frame-toolbar .media-toolbar-primary .mime-type-warning').remove();
            jQuery('.media-frame-toolbar .media-toolbar-primary .media-button-select').prop('disabled', false);
        }
    });

    // Make sure the "Drop files to upload" blue overlay is closed after dropping one or more files
    jQuery('.supports-drag-drop:not(.upload.php)').on('drop', function() {
        jQuery('.uploader-window').hide();
    });

    // When Perform Replacement button is clicked in the media frame...
    mediaFrame.on('select', function() {
        // Get media attachment details from the frame state
        var attachment = mediaFrame.state().get('selection').first().toJSON();
        var newImageMimeType = attachment.mime;

        if (oldImageMimeType == newImageMimeType) {
            // Close the media frame
            mediaFrame.close();
            
            // Show loading state
            jQuery('#sfx-media-replace').prop('disabled', true).text('Replacing...');
            
            // Send the replacement data via AJAX
            jQuery.ajax({
                url: sfxMediaReplace.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sfx_replace_media',
                    old_attachment_id: originalAttachmentId,
                    new_attachment_id: attachment.id,
                    nonce: sfxMediaReplace.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        jQuery('#media-replace-div .inside').prepend(
                            '<div class="notice notice-success"><p>Media replacement successful! The page will refresh to show the updated media.</p></div>'
                        );
                        
                        // Refresh the page after a short delay
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        jQuery('#media-replace-div .inside').prepend(
                            '<div class="notice notice-error"><p>Media replacement failed: ' + (response.data || 'Unknown error') + '</p></div>'
                        );
                        
                        // Re-enable button
                        jQuery('#sfx-media-replace').prop('disabled', false).text('Select New Media File');
                    }
                },
                error: function() {
                    // Show error message
                    jQuery('#media-replace-div .inside').prepend(
                        '<div class="notice notice-error"><p>Media replacement failed. Please try again.</p></div>'
                    );
                    
                    // Re-enable button
                    jQuery('#sfx-media-replace').prop('disabled', false).text('Select New Media File');
                }
            });
        }
    });
}
