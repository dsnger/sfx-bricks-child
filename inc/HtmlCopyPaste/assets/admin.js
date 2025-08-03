/**
 * SFX HTML Copy/Paste Admin JavaScript
 */

(function($) {
    'use strict';

    class SFXHtmlCopyPasteAdmin {
        constructor() {
            this.init();
        }

        init() {
            this.bindEvents();
            this.initStatusIndicators();
        }

        /**
         * Bind event listeners
         */
        bindEvents() {
            // Handle settings form submission
            $(document).on('submit', 'form', (e) => {
                this.handleFormSubmit(e);
            });

            // Handle checkbox changes
            $(document).on('change', 'input[type="checkbox"]', (e) => {
                this.handleCheckboxChange(e);
            });

            // Add feature preview
            this.addFeaturePreview();
        }

        /**
         * Handle form submission
         */
        handleFormSubmit(e) {
            const form = $(e.target);
            const submitButton = form.find('input[type="submit"]');
            
            // Show loading state
            submitButton.prop('disabled', true);
            submitButton.val('Saving...');

            // Reset after a delay
            setTimeout(() => {
                submitButton.prop('disabled', false);
                submitButton.val('Save Changes');
            }, 2000);
        }

        /**
         * Handle checkbox changes
         */
        handleCheckboxChange(e) {
            const checkbox = $(e.target);
            const fieldName = checkbox.attr('name');
            const isChecked = checkbox.is(':checked');
            
            // Update status indicator if it exists
            const statusIndicator = $(`.sfx-status-indicator[data-field="${fieldName}"]`);
            if (statusIndicator.length) {
                statusIndicator.removeClass('enabled disabled')
                    .addClass(isChecked ? 'enabled' : 'disabled')
                    .text(isChecked ? 'Enabled' : 'Disabled');
            }
        }

        /**
         * Initialize status indicators
         */
        initStatusIndicators() {
            $('input[type="checkbox"]').each((index, element) => {
                const checkbox = $(element);
                const fieldName = checkbox.attr('name');
                const isChecked = checkbox.is(':checked');
                
                // Create status indicator
                const statusIndicator = $(`
                    <span class="sfx-status-indicator ${isChecked ? 'enabled' : 'disabled'}" data-field="${fieldName}">
                        ${isChecked ? 'Enabled' : 'Disabled'}
                    </span>
                `);
                
                // Insert after the label
                checkbox.closest('label').after(statusIndicator);
            });
        }

        /**
         * Add feature preview section
         */
        addFeaturePreview() {
            const adminCard = $('.sfx-admin-card:first');
            if (adminCard.length) {
                const preview = $(`
                    <div class="sfx-feature-preview">
                        <h4>Feature Preview</h4>
                        <p>This feature adds HTML copy/paste functionality to Bricks Builder. You can copy HTML from any source and paste it directly into Bricks Builder, where it will be automatically converted to proper Bricks Builder elements.</p>
                        <p><strong>Supported elements:</strong> Div containers, text elements, images, links, SVG elements, and custom attributes.</p>
                    </div>
                `);
                
                adminCard.append(preview);
            }
        }

        /**
         * Show notification
         */
        showNotification(message, type = 'info') {
            const notification = $(`
                <div class="notice notice-${type} is-dismissible">
                    <p>${message}</p>
                </div>
            `);
            
            $('.wrap h1').after(notification);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                notification.fadeOut(() => {
                    notification.remove();
                });
            }, 5000);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(() => {
        new SFXHtmlCopyPasteAdmin();
    });

})(jQuery); 