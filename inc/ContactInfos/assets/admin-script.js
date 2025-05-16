jQuery(document).ready(function($) {
    // Branch management functionality
    function initBranchManagement() {
        $('.sfx-branches-container').each(function() {
            var container = $(this);
            var fieldId = container.data('field-id');
            var list = container.find('.sfx-branches-list');
            var template = container.find('.sfx-branch-template').html();
            var nextIndex = parseInt(container.data('next-index') || 0);
            
            // Unbind any previous event handlers to prevent duplicates
            container.off('click', '.sfx-add-branch');
            container.off('click', '.sfx-remove-branch');
            container.off('click', '.sfx-toggle-branch');
            
            // Add branch
            container.on('click', '.sfx-add-branch', function(e) {
                e.preventDefault();
                var newItem = template.replace(/\{\{index\}\}/g, nextIndex);
                list.append(newItem);
                nextIndex++;
                
                // Update next index data attribute
                container.attr('data-next-index', nextIndex);
            });
            
            // Remove branch
            container.on('click', '.sfx-remove-branch', function(e) {
                e.preventDefault();
                if (confirm(sfxContactSettings.confirmDelete)) {
                    $(this).closest('.sfx-branch-item').remove();
                }
            });
            
            // Toggle branch
            container.on('click', '.sfx-toggle-branch', function(e) {
                e.preventDefault();
                $(this).closest('.sfx-branch-item').find('.sfx-branch-fields').slideToggle();
                $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            });
        });
    }
    
    // Initialize branch management when document is ready
    initBranchManagement();
});
