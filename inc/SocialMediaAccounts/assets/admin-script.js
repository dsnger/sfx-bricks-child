/**
 * Social Media Accounts Admin Scripts
 */

jQuery(document).ready(function($) {
    'use strict';

    // Initialize when on social account pages
    if ($('body').hasClass('post-type-sfx_social_account')) {
        initSocialMediaAccounts();
    }

    function initSocialMediaAccounts() {
        // Handle image upload functionality
        handleImageUpload();
        
        // Handle form validation
        handleFormValidation();
    }

    function handleImageUpload() {
        // Image upload functionality is handled inline in the meta box
        // This is for any additional JavaScript functionality
    }

    function handleFormValidation() {
        // Add form validation if needed
        $('#post').on('submit', function(e) {
            var title = $('#title').val().trim();
            var linkUrl = $('#link_url').val().trim();
            
            if (!title) {
                alert('Please enter a title for the social account.');
                e.preventDefault();
                return false;
            }
            
            if (linkUrl && !isValidUrl(linkUrl)) {
                alert('Please enter a valid URL for the link.');
                e.preventDefault();
                return false;
            }
        });
    }

    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }

    // Handle admin notices
    if (typeof sfxSocialMediaAccounts !== 'undefined') {
        // Handle any AJAX responses or admin notices
        $(document).on('click', '.sfx-notice-dismiss', function(e) {
            e.preventDefault();
            var notice = $(this).closest('.notice');
            notice.fadeOut();
        });
    }
}); 