/**
 * Custom Dashboard Admin Settings Scripts
 *
 * @package SFX_Bricks_Child_Theme
 */

(function($) {
    'use strict';

    /**
     * Initialize custom quicklinks management
     */
    function initCustomQuicklinks() {
        const wrapper = $('#sfx-custom-quicklinks-wrapper');
        const tbody = $('#sfx-custom-quicklinks-body');
        const addButton = $('#sfx-add-custom-link');

        if (!wrapper.length || !tbody.length || !addButton.length) {
            return;
        }

        // Get option name from localized script
        const optionName = sfxDashboardAdmin.optionName;
        const strings = sfxDashboardAdmin.strings;

        // Default SVG icon for new links
        var defaultSvgIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>';

        // Add custom link
        addButton.on('click', function(e) {
            e.preventDefault();
            
            // Get current row count
            const rowCount = tbody.find('tr').length;
            const newIndex = rowCount;

            // Create new row
            const newRow = $('<tr>', { class: 'sfx-custom-link-row' });
            
            // Icon field (textarea for SVG with preview)
            const iconWrapper = $('<div>', { class: 'sfx-icon-input-wrapper' });
            
            const iconTextarea = $('<textarea>', {
                name: optionName + '[custom_quicklinks][' + newIndex + '][icon]',
                rows: 3,
                class: 'sfx-svg-icon-input',
                placeholder: 'SVG code'
            }).val(defaultSvgIcon);
            
            const iconPreview = $('<div>', { 
                class: 'sfx-icon-preview',
                title: 'Icon Preview'
            }).html(defaultSvgIcon);
            
            iconWrapper.append(iconTextarea, iconPreview);
            const iconCell = $('<td>').append(iconWrapper);

            // Title field
            const titleCell = $('<td>').append(
                $('<input>', {
                    type: 'text',
                    name: optionName + '[custom_quicklinks][' + newIndex + '][title]',
                    value: '',
                    class: 'regular-text',
                    placeholder: strings.title
                })
            );

            // URL field
            const urlCell = $('<td>').append(
                $('<input>', {
                    type: 'text',
                    name: optionName + '[custom_quicklinks][' + newIndex + '][url]',
                    value: '',
                    class: 'regular-text',
                    placeholder: strings.url
                })
            );

            // Action field (remove button)
            const actionCell = $('<td>').append(
                $('<button>', {
                    type: 'button',
                    class: 'button sfx-remove-link',
                    text: strings.remove
                })
            );

            // Append cells to row
            newRow.append(iconCell, titleCell, urlCell, actionCell);
            
            // Append row to tbody
            tbody.append(newRow);

            // Focus on title field
            titleCell.find('input').focus();
        });

        // Remove custom link (delegated event for dynamically added rows)
        tbody.on('click', '.sfx-remove-link', function(e) {
            e.preventDefault();
            
            const row = $(this).closest('tr');
            
            // Confirm before removing
            if (confirm('Are you sure you want to remove this custom link?')) {
                row.fadeOut(300, function() {
                    $(this).remove();
                    reindexCustomLinks();
                });
            }
        });

        // Live SVG preview update
        tbody.on('input', '.sfx-svg-icon-input', function() {
            const textarea = $(this);
            const preview = textarea.closest('.sfx-icon-input-wrapper').find('.sfx-icon-preview');
            const svgCode = textarea.val().trim();
            
            // Only update if it looks like valid SVG
            if (svgCode.indexOf('<svg') !== -1 && svgCode.indexOf('</svg>') !== -1) {
                preview.html(svgCode);
            } else {
                preview.html('');
            }
        });

        /**
         * Reindex custom link fields after removal
         */
        function reindexCustomLinks() {
            tbody.find('tr').each(function(index) {
                $(this).find('input').each(function() {
                    const name = $(this).attr('name');
                    if (name) {
                        // Replace the index in the name attribute
                        const newName = name.replace(/\[custom_quicklinks\]\[\d+\]/, '[custom_quicklinks][' + index + ']');
                        $(this).attr('name', newName);
                    }
                });
            });
        }
    }

    /**
     * Initialize logo uploader
     */
    function initLogoUploader() {
        $(document).on('click', '.sfx-upload-logo-button', function(e) {
            e.preventDefault();
            var button = $(this);
            var container = button.closest('.sfx-logo-upload');
            var fileInput = container.find('.sfx-logo-file-input');
            
            // Trigger file input click
            fileInput.click();
        });

        $(document).on('change', '.sfx-logo-file-input', function(e) {
            var input = $(this);
            var container = input.closest('.sfx-logo-upload');
            var preview = container.find('.sfx-logo-preview');
            var removeButton = container.find('.sfx-remove-logo-button');
            var file = this.files[0];

            if (file) {
                // Validate file type
                var validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];
                if (validTypes.indexOf(file.type) === -1) {
                    alert('Invalid file type. Please use PNG, JPG, SVG, or WebP.');
                    input.val('');
                    return;
                }

                // Validate file size (200KB)
                if (file.size > 204800) {
                    alert('File too large. Maximum size is 200KB.');
                    input.val('');
                    return;
                }

                // Show preview
                var reader = new FileReader();
                reader.onload = function(e) {
                    preview.html('<img src="' + e.target.result + '" style="max-width: 200px; max-height: 100px; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px; padding: 5px; background: #f9f9f9;" />');
                    preview.show();
                    removeButton.show();
                };
                reader.readAsDataURL(file);
            }
        });

        $(document).on('click', '.sfx-remove-logo-button', function(e) {
            e.preventDefault();

            var button = $(this);
            var container = button.closest('.sfx-logo-upload');
            var hiddenInput = container.find('.sfx-logo-url');
            var fileInput = container.find('.sfx-logo-file-input');
            var preview = container.find('.sfx-logo-preview');

            hiddenInput.val('');
            fileInput.val('');
            preview.html('').hide();
            button.hide();
        });
    }

    /**
     * Initialize sortable stats
     */
    function initStatsSortable() {
        var $sortable = $('#sfx-stats-sortable');
        
        if (!$sortable.length) {
            return;
        }
        
        // Check if sortable is available
        if (typeof $.fn.sortable === 'undefined') {
            console.error('SFX: jQuery UI Sortable not loaded!');
            return;
        }
        
        // Destroy existing sortable if it exists
        try {
            if ($sortable.data('ui-sortable')) {
                $sortable.sortable('destroy');
            }
        } catch(e) {}
        
        // Initialize sortable - drag anywhere on the item
        $sortable.sortable({
            items: '> li.sfx-stat-item',
            placeholder: 'sfx-stat-placeholder',
            axis: 'y',
            cursor: 'move',
            opacity: 0.8,
            revert: 150,
            update: function(event, ui) {
                updateStatsIndices();
            }
        });
    }

    /**
     * Update stats item indices after reorder
     */
    function updateStatsIndices() {
        $('#sfx-stats-sortable .sfx-stat-item').each(function(index) {
            var $item = $(this);
            $item.find('input').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                if (name) {
                    // Replace the index number in stats_items[X]
                    name = name.replace(/\[stats_items\]\[\d+\]/, '[stats_items][' + index + ']');
                    $input.attr('name', name);
                }
            });
        });
    }

    /**
     * Initialize on document ready
     */
    /**
     * Initialize color select preview updates
     */
    function initColorSelectPreview() {
        $(document).on('change', '.sfx-color-select', function() {
            var $select = $(this);
            var $preview = $select.siblings('.sfx-color-preview');
            var selectedOption = $select.find('option:selected');
            var color = selectedOption.data('color');
            
            if (color && $preview.length) {
                $preview.css('background-color', color);
            }
        });
    }

    $(document).ready(function() {
        initCustomQuicklinks();
        initLogoUploader();
        initColorSelectPreview();
        
        // Initialize sortable with a small delay to ensure DOM is ready
        setTimeout(initStatsSortable, 150);
        
        // Re-initialize sortable when switching tabs
        $(document).on('click', '.sfx-dashboard-tabs .nav-tab', function() {
            setTimeout(initStatsSortable, 200);
        });
    });

})(jQuery);

