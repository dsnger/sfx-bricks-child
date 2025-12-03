/**
 * Custom Dashboard Admin Settings Scripts
 *
 * @package SFX_Bricks_Child_Theme
 */

(function($) {
    'use strict';

    /**
     * Initialize sortable quicklinks
     */
    function initQuicklinksSortable() {
        var $sortable = $('#sfx-quicklinks-sortable');
        
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
        
        // Initialize sortable
        $sortable.sortable({
            items: '> li.sfx-quicklink-item',
            placeholder: 'sfx-quicklink-placeholder',
            axis: 'y',
            cursor: 'move',
            opacity: 0.8,
            revert: 150,
            handle: '.sfx-quicklink-drag-handle',
            update: function(event, ui) {
                updateQuicklinksIndices();
            }
        });
    }

    /**
     * Update quicklinks item indices after reorder
     */
    function updateQuicklinksIndices() {
        $('#sfx-quicklinks-sortable .sfx-quicklink-item').each(function(index) {
            var $item = $(this);
            $item.find('input, textarea').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                if (name) {
                    // Replace the index number in quicklinks_sortable[X]
                    name = name.replace(/\[quicklinks_sortable\]\[\d+\]/, '[quicklinks_sortable][' + index + ']');
                    $input.attr('name', name);
                }
            });
        });
    }

    /**
     * Initialize add custom quicklink button
     */
    function initAddCustomQuicklink() {
        var $addButton = $('#sfx-add-custom-quicklink');
        var $sortable = $('#sfx-quicklinks-sortable');
        
        if (!$addButton.length || !$sortable.length) {
            return;
        }

        var optionName = sfxDashboardAdmin.optionName;
        var strings = sfxDashboardAdmin.strings;
        var defaultSvgIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>';

        $addButton.on('click', function(e) {
            e.preventDefault();
            
            var newIndex = $sortable.find('.sfx-quicklink-item').length;
            var newId = 'custom_' + Date.now();
            
            var $newItem = $('<li>', {
                class: 'sfx-quicklink-item sfx-quicklink-item-custom sfx-quicklink-item-new',
                'data-id': newId,
                'data-type': 'custom'
            });
            
            // Build the item HTML
            var itemHtml = '<span class="sfx-quicklink-drag-handle">☰</span>' +
                '<label class="sfx-quicklink-checkbox">' +
                    '<input type="checkbox" name="' + optionName + '[quicklinks_sortable][' + newIndex + '][enabled]" value="1" checked />' +
                    '<input type="hidden" name="' + optionName + '[quicklinks_sortable][' + newIndex + '][id]" value="' + newId + '" class="sfx-quicklink-id" />' +
                    '<input type="hidden" name="' + optionName + '[quicklinks_sortable][' + newIndex + '][type]" value="custom" class="sfx-quicklink-type" />' +
                '</label>' +
                '<span class="sfx-quicklink-icon-preview">' + defaultSvgIcon + '</span>' +
                '<div class="sfx-quicklink-custom-fields">' +
                    '<input type="text" name="' + optionName + '[quicklinks_sortable][' + newIndex + '][title]" value="" class="sfx-quicklink-title-input" placeholder="' + (strings.title || 'Title') + '" />' +
                    '<input type="text" name="' + optionName + '[quicklinks_sortable][' + newIndex + '][url]" value="" class="sfx-quicklink-url-input" placeholder="' + (strings.url || 'URL') + '" />' +
                    '<textarea name="' + optionName + '[quicklinks_sortable][' + newIndex + '][icon]" class="sfx-quicklink-icon-input" placeholder="SVG Icon" rows="2">' + defaultSvgIcon + '</textarea>' +
                    '<button type="button" class="button sfx-remove-quicklink" title="' + (strings.remove || 'Remove') + '">✕</button>' +
                '</div>' +
                '<span class="sfx-quicklink-badge">' + (strings.custom || 'Custom') + '</span>';
            
            $newItem.html(itemHtml);
            $sortable.append($newItem);
            
            // Focus on title input
            $newItem.find('.sfx-quicklink-title-input').focus();
            
            // Remove highlight after animation
            setTimeout(function() {
                $newItem.removeClass('sfx-quicklink-item-new');
            }, 1000);
            
            // Refresh sortable
            $sortable.sortable('refresh');
        });
    }

    /**
     * Initialize remove custom quicklink buttons
     */
    function initRemoveCustomQuicklink() {
        $(document).on('click', '.sfx-remove-quicklink', function(e) {
            e.preventDefault();
            
            var $item = $(this).closest('.sfx-quicklink-item');
            
            if (confirm(sfxDashboardAdmin.strings.confirmRemove || 'Are you sure you want to remove this custom link?')) {
                $item.fadeOut(300, function() {
                    $(this).remove();
                    updateQuicklinksIndices();
                });
            }
        });
    }

    /**
     * Initialize live SVG icon preview for quicklinks
     */
    function initQuicklinkIconPreview() {
        $(document).on('input', '.sfx-quicklink-icon-input', function() {
            var $textarea = $(this);
            var $item = $textarea.closest('.sfx-quicklink-item');
            var $preview = $item.find('.sfx-quicklink-icon-preview');
            var svgCode = $textarea.val().trim();
            
            // Only update if it looks like valid SVG
            if (svgCode.indexOf('<svg') !== -1 && svgCode.indexOf('</svg>') !== -1) {
                $preview.html(svgCode);
            } else {
                $preview.html('');
            }
        });
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
     * Initialize color select preview updates
     */
    function initColorSelectPreview() {
        $(document).on('change', '.sfx-color-select', function() {
            var $select = $(this);
            var $preview = $select.siblings('.sfx-color-preview');
            var selectedOption = $select.find('option:selected');
            var color = selectedOption.data('color');
            var isVariable = selectedOption.data('is-variable') === 1 || selectedOption.data('is-variable') === '1';
            
            if (color && $preview.length) {
                if (isVariable) {
                    // For CSS variable colors, use the variable reference
                    $preview.css('background', 'hsl(' + color + ')');
                } else {
                    $preview.css('background', color);
                }
            }
        });
    }

    /**
     * Initialize all reset buttons
     */
    function initResetButtons() {
        var resetConfigs = [
            {
                buttonId: 'sfx-reset-brand-colors',
                varName: 'sfxDefaultBrandColors',
                confirmMsg: 'Are you sure you want to reset all brand and status colors to their default values?',
                successMsg: 'Colors Reset!'
            },
            {
                buttonId: 'sfx-reset-header-settings',
                varName: 'sfxDefaultHeaderSettings',
                confirmMsg: 'Are you sure you want to reset header settings to their default values?',
                successMsg: 'Header Reset!'
            },
            {
                buttonId: 'sfx-reset-card-settings',
                varName: 'sfxDefaultCardSettings',
                confirmMsg: 'Are you sure you want to reset card styling to default values?',
                successMsg: 'Cards Reset!'
            },
            {
                buttonId: 'sfx-reset-layout-settings',
                varName: 'sfxDefaultLayoutSettings',
                confirmMsg: 'Are you sure you want to reset layout settings to default values?',
                successMsg: 'Layout Reset!'
            }
        ];

        $.each(resetConfigs, function(i, config) {
            initSingleResetButton(config);
        });
    }

    /**
     * Initialize a single reset button
     */
    function initSingleResetButton(config) {
        var $resetButton = $('#' + config.buttonId);
        
        if (!$resetButton.length) {
            return;
        }
        
        $resetButton.on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(config.confirmMsg)) {
                return;
            }
            
            // Get defaults from global variable set by PHP
            var defaults = window[config.varName];
            
            if (!defaults || typeof defaults !== 'object') {
                console.error('SFX: No defaults found for ' + config.varName);
                alert('Error: Could not load default values.');
                return;
            }
            
            // Update each input with its default value
            $.each(defaults, function(fieldId, defaultValue) {
                var $input = $('#' + fieldId);
                
                if (!$input.length) {
                    // Try finding by name attribute for select elements
                    $input = $('[name="sfx_custom_dashboard_options[' + fieldId + ']"]');
                }
                
                if ($input.length) {
                    if ($input.is(':checkbox')) {
                        $input.prop('checked', !!defaultValue);
                    } else if ($input.is('select')) {
                        $input.val(defaultValue);
                    } else {
                        $input.val(defaultValue);
                    }
                    // Trigger change event to update any previews
                    $input.trigger('change');
                    console.log('SFX: Reset ' + fieldId + ' to ' + defaultValue);
                } else {
                    console.warn('SFX: Could not find input for ' + fieldId);
                }
            });
            
            // Show feedback
            var originalText = $resetButton.text();
            $resetButton.text(config.successMsg).prop('disabled', true);
            setTimeout(function() {
                $resetButton.text(originalText).prop('disabled', false);
            }, 2000);
        });
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initLogoUploader();
        initColorSelectPreview();
        initAddCustomQuicklink();
        initRemoveCustomQuicklink();
        initQuicklinkIconPreview();
        initResetButtons();
        
        // Initialize sortables with a small delay to ensure DOM is ready
        setTimeout(function() {
            initStatsSortable();
            initQuicklinksSortable();
        }, 150);
        
        // Re-initialize sortables when switching tabs
        $(document).on('click', '.sfx-dashboard-tabs .nav-tab, .nav-tab-wrapper .nav-tab', function() {
            setTimeout(function() {
                initStatsSortable();
                initQuicklinksSortable();
            }, 200);
        });
    });

})(jQuery);

