/**
 * Contact Information Admin Scripts
 * Handles conditional field visibility based on contact type
 */

jQuery(document).ready(function($) {
    // Function to toggle conditional fields
    function toggleConditionalFields() {
        var selectedType = $('#contact_type').val();
        console.log('Contact type changed to:', selectedType);
        
        var conditionalFields = $('.conditional-field');
        console.log('Found', conditionalFields.length, 'conditional fields');
        
        conditionalFields.each(function() {
            var showFor = $(this).data('show-for');
            var fieldId = $(this).attr('id') || 'unknown';
            console.log('Field:', fieldId, 'show-for:', showFor, 'selected:', selectedType);
            
            if (showFor === selectedType) {
                $(this).show();
                console.log('Showing field:', fieldId);
            } else {
                $(this).hide();
                console.log('Hiding field:', fieldId);
            }
        });
    }
    
    // Function to initialize conditional fields
    function initConditionalFields() {
        var contactTypeSelect = $('#contact_type');
        
        if (contactTypeSelect.length > 0) {
            console.log('Contact type selector found, initializing conditional fields');
            
            // Initial toggle on page load
            toggleConditionalFields();
            
            // Toggle on contact type change
            contactTypeSelect.off('change.conditionalFields').on('change.conditionalFields', function() {
                console.log('Contact type changed, toggling fields');
                toggleConditionalFields();
            });
            
            return true;
        } else {
            console.log('Contact type selector not found');
            return false;
        }
    }
    
    // Try to initialize immediately
    if (!initConditionalFields()) {
        // If not found immediately, try again after a short delay
        setTimeout(function() {
            console.log('Retrying conditional fields initialization...');
            initConditionalFields();
        }, 500);
    }
});
