jQuery(document).ready(function($) {
    'use strict';

    // Script management functionality
    function initScriptManagement() {
        $('.sfx-scripts-container').each(function() {
            var container = $(this);
            var list = container.find('.sfx-scripts-list');
            var template = container.find('.sfx-script-template').html();
            var nextIndex = parseInt(container.data('next-index') || 0);
            
            // Unbind any previous event handlers to prevent duplicates
            container.off('click', '.sfx-add-script');
            container.off('click', '.sfx-remove-script');
            
            // Add script
            container.on('click', '.sfx-add-script', function(e) {
                e.preventDefault();
                var newItem = template.replace(/\{\{index\}\}/g, nextIndex);
                list.append(newItem);
                nextIndex++;
                
                // Update next index data attribute
                container.attr('data-next-index', nextIndex);
                
                // Initialize conditional logic for the new item
                toggleConditionalFields();
            });
            
            // Remove script
            container.on('click', '.sfx-remove-script', function(e) {
                e.preventDefault();
                if (confirm(sfxCustomScripts.confirmDelete)) {
                    $(this).closest('.sfx-settings-card').remove();
                }
            });
        });
    }

    // Media upload functionality
    $(document).on('click', '.sfx-upload-file', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const inputField = button.siblings('.sfx-file-input');
        
        // Create media frame
        const frame = wp.media({
            title: sfxCustomScripts.mediaUploadTitle,
            button: {
                text: sfxCustomScripts.mediaUploadButton
            },
            multiple: false,
            library: {
                type: ['application', 'text'] // Allow JS, CSS, and other file types
            }
        });

        // Handle file selection
        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            inputField.val(attachment.url);
        });

        // Open media frame
        frame.open();
    });

    // Handle conditional logic for script source type
    function toggleConditionalFields() {
        console.log('toggleConditionalFields called');
        $('.sfx-settings-card').each(function() {
            const card = $(this);
            const sourceType = card.find('input[name*="[script_source_type]"]:checked').val();
            console.log('Processing card, sourceType:', sourceType);
            
            // Hide all conditional fields first
            card.find('.sfx-conditional-field').hide();
            
            // Show relevant fields based on selection
            if (sourceType) {
                card.find('.sfx-conditional-field').each(function() {
                    const field = $(this);
                    const conditionalValues = field.attr('data-conditional');
                    console.log('Field conditional values:', conditionalValues);
                    
                    if (conditionalValues) {
                        // Handle both single values and comma-separated values
                        const allowedValues = conditionalValues.split(',').map(v => v.trim());
                        console.log('Allowed values:', allowedValues, 'checking for:', sourceType);
                        if (allowedValues.includes(sourceType)) {
                            console.log('Showing field');
                            field.show();
                        } else {
                            console.log('Hiding field');
                        }
                    }
                });
            }
        });
    }

    // Initialize conditional logic on page load
    toggleConditionalFields();

    // Handle radio button changes for conditional logic
    $(document).on('change', 'input[name*="[script_source_type]"]', function() {
        console.log('Radio button changed:', $(this).val());
        toggleConditionalFields();
    });

    // Update script name in header when input changes
    $(document).on('input', 'input[name*="[script_name]"]', function() {
        const nameInput = $(this);
        const card = nameInput.closest('.sfx-settings-card');
        const header = card.find('.sfx-settings-card-header h3');
        const newName = nameInput.val().trim();
        
        if (newName) {
            header.text(newName);
        } else {
            header.text('Unnamed Script');
        }
    });

    // Initialize script management when document is ready
    initScriptManagement();
});
