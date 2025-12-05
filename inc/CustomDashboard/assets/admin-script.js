/**
 * Custom Dashboard Admin Settings Scripts
 *
 * @package SFX_Bricks_Child_Theme
 */

(function($) {
    'use strict';

    /**
     * Initialize sortable quicklink groups
     */
    function initQuicklinkGroupsSortable() {
        var $groupsContainer = $('#sfx-quicklink-groups-sortable');
        
        if (!$groupsContainer.length) {
            return;
        }
        
        // Check if sortable is available
        if (typeof $.fn.sortable === 'undefined') {
            console.error('SFX: jQuery UI Sortable not loaded!');
            return;
        }
        
        // Destroy existing sortable if it exists
        try {
            if ($groupsContainer.data('ui-sortable')) {
                $groupsContainer.sortable('destroy');
            }
        } catch(e) {}
        
        // Initialize groups sortable
        $groupsContainer.sortable({
            items: '> .sfx-quicklink-group',
            placeholder: 'sfx-quicklink-group-placeholder',
            axis: 'y',
            cursor: 'move',
            opacity: 0.8,
            revert: 150,
            handle: '.sfx-group-drag-handle',
            update: function(event, ui) {
                updateAllIndices();
            }
        });
        
        // Initialize quicklinks sortable within each group with cross-group support
        initQuicklinksSortableInGroups();
    }

    /**
     * Initialize sortable quicklinks within groups with cross-group dragging
     */
    function initQuicklinksSortableInGroups() {
        var $sortables = $('.sfx-quicklinks-sortable');
        
        $sortables.each(function() {
            var $sortable = $(this);
            
            // Destroy existing sortable if it exists
            try {
                if ($sortable.data('ui-sortable')) {
                    $sortable.sortable('destroy');
                }
            } catch(e) {}
            
            // Initialize sortable with connectWith for cross-group dragging
            $sortable.sortable({
                items: '> li.sfx-quicklink-item',
                placeholder: 'sfx-quicklink-placeholder',
                connectWith: '.sfx-quicklinks-sortable',
                cursor: 'move',
                opacity: 0.8,
                revert: 150,
                handle: '.sfx-quicklink-drag-handle',
                update: function(event, ui) {
                    // Only update indices if this is the receiving list
                    if (this === ui.item.parent()[0]) {
                        updateAllIndices();
                    }
                }
            });
        });
    }

    /**
     * Update all indices for groups and quicklinks
     */
    function updateAllIndices() {
        var optionName = sfxDashboardAdmin.optionName;
        
        $('#sfx-quicklink-groups-sortable .sfx-quicklink-group').each(function(groupIndex) {
            var $group = $(this);
            
            // Update group inputs
            $group.find('> .sfx-quicklink-group-header input').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                if (name) {
                    name = name.replace(/\[groups\]\[\d+\]/, '[groups][' + groupIndex + ']');
                    $input.attr('name', name);
                }
            });
            
            // Update quicklinks within this group
            $group.find('.sfx-quicklinks-sortable').attr('data-group-index', groupIndex);
            $group.find('.sfx-quicklinks-sortable .sfx-quicklink-item').each(function(linkIndex) {
                var $item = $(this);
                $item.find('input, textarea').each(function() {
                    var $input = $(this);
                    var name = $input.attr('name');
                    if (name) {
                        // Replace both group and quicklink indices
                        name = name.replace(/\[groups\]\[\d+\]\[quicklinks\]\[\d+\]/, '[groups][' + groupIndex + '][quicklinks][' + linkIndex + ']');
                        $input.attr('name', name);
                    }
                });
            });
        });
    }

    /**
     * Initialize add group button
     */
    function initAddGroup() {
        $(document).on('click', '#sfx-add-quicklink-group', function(e) {
            e.preventDefault();
            
            var $container = $('#sfx-quicklink-groups-sortable');
            var groupIndex = $container.find('.sfx-quicklink-group').length;
            var newGroupId = 'group_' + Date.now();
            var optionName = sfxDashboardAdmin.optionName;
            var strings = sfxDashboardAdmin.strings || {};
            var availableRoles = sfxDashboardAdmin.availableRoles || {};
            
            // Build role checkboxes HTML
            var rolesHtml = '';
            $.each(availableRoles, function(roleSlug, roleName) {
                rolesHtml += '<label class="sfx-role-checkbox">' +
                    '<input type="checkbox" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][roles][]" value="' + roleSlug + '" class="sfx-role-individual-checkbox" disabled />' +
                    '<span>' + roleName + '</span>' +
                '</label>';
            });
            
            var groupHtml = 
                '<div class="sfx-quicklink-group sfx-quicklink-group-new" data-group-id="' + newGroupId + '">' +
                    '<div class="sfx-quicklink-group-header">' +
                        '<span class="sfx-group-drag-handle">☰</span>' +
                        '<input type="hidden" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][id]" value="' + newGroupId + '" class="sfx-group-id" />' +
                        '<input type="text" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][title]" value="" class="sfx-group-title-input" placeholder="' + (strings.groupTitle || 'Group Title') + '" />' +
                        '<div class="sfx-quicklink-roles sfx-group-roles">' +
                            '<button type="button" class="sfx-quicklink-roles-toggle" aria-expanded="false">' +
                                '<span class="sfx-roles-toggle-icon">▼</span>' +
                                '<span class="sfx-roles-toggle-label">' + (strings.allRoles || 'All Roles') + '</span>' +
                            '</button>' +
                            '<div class="sfx-quicklink-roles-dropdown" style="display: none;">' +
                                '<label class="sfx-role-checkbox sfx-role-checkbox-all">' +
                                    '<input type="checkbox" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][roles][]" value="all" class="sfx-role-all-checkbox" checked />' +
                                    '<span>' + (strings.allRoles || 'All Roles') + '</span>' +
                                '</label>' +
                                '<div class="sfx-roles-divider"></div>' +
                                rolesHtml +
                            '</div>' +
                        '</div>' +
                        '<button type="button" class="button sfx-toggle-group" title="' + (strings.collapseExpand || 'Collapse/Expand') + '">' +
                            '<span class="dashicons dashicons-arrow-up-alt2"></span>' +
                        '</button>' +
                        '<button type="button" class="button sfx-remove-group" title="' + (strings.removeGroup || 'Remove Group') + '">' +
                            '<span class="dashicons dashicons-trash"></span>' +
                        '</button>' +
                    '</div>' +
                    '<div class="sfx-quicklink-group-content">' +
                        '<ul class="sfx-quicklinks-sortable" data-group-index="' + groupIndex + '"></ul>' +
                        '<div class="sfx-quicklinks-actions">' +
                            '<button type="button" class="button button-secondary sfx-add-custom-quicklink">' +
                                (strings.addLink || '+ Add Link') +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            
            var $newGroup = $(groupHtml);
            $container.append($newGroup);
            
            // Focus on title input
            $newGroup.find('.sfx-group-title-input').focus();
            
            // Remove highlight after animation
            setTimeout(function() {
                $newGroup.removeClass('sfx-quicklink-group-new');
            }, 1000);
            
            // Re-initialize sortables
            initQuicklinksSortableInGroups();
            $('#sfx-quicklink-groups-sortable').sortable('refresh');
        });
    }

    /**
     * Initialize remove group button
     */
    function initRemoveGroup() {
        $(document).on('click', '.sfx-remove-group', function(e) {
            e.preventDefault();
            
            var $group = $(this).closest('.sfx-quicklink-group');
            var strings = sfxDashboardAdmin.strings || {};
            
            if (confirm(strings.confirmRemoveGroup || 'Are you sure you want to remove this group and all its links?')) {
                $group.fadeOut(300, function() {
                    $(this).remove();
                    updateAllIndices();
                });
            }
        });
    }

    /**
     * Initialize toggle group (collapse/expand)
     */
    function initToggleGroup() {
        $(document).on('click', '.sfx-toggle-group', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $group = $button.closest('.sfx-quicklink-group');
            var $content = $group.find('.sfx-quicklink-group-content');
            var $icon = $button.find('.dashicons');
            
            $content.slideToggle(200);
            
            if ($icon.hasClass('dashicons-arrow-up-alt2')) {
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            } else {
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            }
        });
    }

    /**
     * Initialize add custom quicklink button (within groups)
     */
    function initAddCustomQuicklink() {
        $(document).on('click', '.sfx-add-custom-quicklink', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $group = $button.closest('.sfx-quicklink-group');
            var $sortable = $group.find('.sfx-quicklinks-sortable');
            var groupIndex = $sortable.data('group-index');
            var linkIndex = $sortable.find('.sfx-quicklink-item').length;
            var newId = 'custom_' + Date.now();
            
            var optionName = sfxDashboardAdmin.optionName;
            var strings = sfxDashboardAdmin.strings || {};
            var availableRoles = sfxDashboardAdmin.availableRoles || {};
            var defaultSvgIcon = sfxDashboardAdmin.defaultIcon || '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>';
            
            var namePrefix = optionName + '[quicklinks_sortable][groups][' + groupIndex + '][quicklinks][' + linkIndex + ']';
            
            // Build role checkboxes HTML
            var rolesHtml = '';
            $.each(availableRoles, function(roleSlug, roleName) {
                rolesHtml += '<label class="sfx-role-checkbox">' +
                    '<input type="checkbox" name="' + namePrefix + '[roles][]" value="' + roleSlug + '" class="sfx-role-individual-checkbox" disabled />' +
                    '<span>' + roleName + '</span>' +
                '</label>';
            });
            
            var itemHtml = 
                '<li class="sfx-quicklink-item sfx-quicklink-item-custom sfx-quicklink-item-new is-editing" data-id="' + newId + '" data-type="custom">' +
                    '<span class="sfx-quicklink-drag-handle">☰</span>' +
                    '<label class="sfx-quicklink-checkbox">' +
                        '<input type="checkbox" name="' + namePrefix + '[enabled]" value="1" checked />' +
                        '<input type="hidden" name="' + namePrefix + '[id]" value="' + newId + '" class="sfx-quicklink-id" />' +
                        '<input type="hidden" name="' + namePrefix + '[type]" value="custom" class="sfx-quicklink-type" />' +
                    '</label>' +
                    '<span class="sfx-quicklink-icon-preview">' + defaultSvgIcon + '</span>' +
                    '<span class="sfx-quicklink-label sfx-quicklink-title-display">' + (strings.untitled || 'Untitled') + '</span>' +
                    '<code class="sfx-quicklink-url sfx-quicklink-url-display">—</code>' +
                    '<span class="sfx-quicklink-badge">' + (strings.custom || 'Custom') + '</span>' +
                    '<div class="sfx-quicklink-roles">' +
                        '<button type="button" class="sfx-quicklink-roles-toggle" aria-expanded="false">' +
                            '<span class="sfx-roles-toggle-icon">▼</span>' +
                            '<span class="sfx-roles-toggle-label">' + (strings.allRoles || 'All Roles') + '</span>' +
                        '</button>' +
                        '<div class="sfx-quicklink-roles-dropdown" style="display: none;">' +
                            '<label class="sfx-role-checkbox sfx-role-checkbox-all">' +
                                '<input type="checkbox" name="' + namePrefix + '[roles][]" value="all" class="sfx-role-all-checkbox" checked />' +
                                '<span>' + (strings.allRoles || 'All Roles') + '</span>' +
                            '</label>' +
                            '<div class="sfx-roles-divider"></div>' +
                            rolesHtml +
                        '</div>' +
                    '</div>' +
                    '<div class="sfx-quicklink-actions">' +
                        '<button type="button" class="button sfx-edit-quicklink" title="' + (strings.edit || 'Edit') + '">' +
                            '<span class="dashicons dashicons-edit"></span>' +
                        '</button>' +
                        '<button type="button" class="button sfx-remove-quicklink" title="' + (strings.remove || 'Remove') + '">' +
                            '<span class="dashicons dashicons-trash"></span>' +
                        '</button>' +
                    '</div>' +
                    '<div class="sfx-quicklink-edit-form">' +
                        '<div class="sfx-quicklink-edit-fields">' +
                            '<div class="sfx-edit-field">' +
                                '<label>' + (strings.title || 'Title') + '</label>' +
                                '<input type="text" name="' + namePrefix + '[title]" value="" class="sfx-quicklink-title-input" placeholder="' + (strings.linkTitle || 'Link Title') + '" />' +
                            '</div>' +
                            '<div class="sfx-edit-field">' +
                                '<label>' + (strings.url || 'URL') + '</label>' +
                                '<input type="text" name="' + namePrefix + '[url]" value="" class="sfx-quicklink-url-input" placeholder="admin.php?page=example" />' +
                            '</div>' +
                            '<div class="sfx-edit-field sfx-edit-field-full">' +
                                '<label>' + (strings.svgIcon || 'SVG Icon') + '</label>' +
                                '<textarea name="' + namePrefix + '[icon]" class="sfx-quicklink-icon-input" placeholder="<svg>...</svg>" rows="3">' + defaultSvgIcon + '</textarea>' +
                            '</div>' +
                        '</div>' +
                        '<div class="sfx-quicklink-edit-actions">' +
                            '<button type="button" class="button button-primary sfx-save-quicklink">' + (strings.done || 'Done') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</li>';
            
            var $newItem = $(itemHtml);
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
                    updateAllIndices();
                });
            }
        });
    }

    /**
     * Initialize edit custom quicklink functionality
     */
    function initEditCustomQuicklink() {
        // Toggle edit form
        $(document).on('click', '.sfx-edit-quicklink', function(e) {
            e.preventDefault();
            
            var $item = $(this).closest('.sfx-quicklink-item');
            var $editForm = $item.find('.sfx-quicklink-edit-form');
            
            if ($item.hasClass('is-editing')) {
                // Close edit form
                closeEditForm($item);
            } else {
                // Close any other open edit forms first
                $('.sfx-quicklink-item.is-editing').each(function() {
                    closeEditForm($(this));
                });
                
                // Open this edit form
                $item.addClass('is-editing');
                $editForm.slideDown(200, function() {
                    $editForm.find('.sfx-quicklink-title-input').focus();
                });
            }
        });
        
        // Save/Done button - close the form and update display
        $(document).on('click', '.sfx-save-quicklink', function(e) {
            e.preventDefault();
            
            var $item = $(this).closest('.sfx-quicklink-item');
            closeEditForm($item);
        });
        
        // Update display when inputs change with real-time validation
        $(document).on('input', '.sfx-quicklink-title-input', function() {
            var $item = $(this).closest('.sfx-quicklink-item');
            var value = $(this).val();
            // Strip HTML for display preview
            var displayValue = value ? $('<div>').html(value).text() : (sfxDashboardAdmin.strings.untitled || 'Untitled');
            $item.find('.sfx-quicklink-title-display').text(displayValue);
            
            // Real-time validation
            validateFieldRealtime($(this), 'title');
        });
        
        $(document).on('input', '.sfx-quicklink-url-input', function() {
            var $item = $(this).closest('.sfx-quicklink-item');
            var value = $(this).val() || '—';
            $item.find('.sfx-quicklink-url-display').text(value);
            
            // Real-time validation
            validateFieldRealtime($(this), 'url');
        });
        
        $(document).on('input', '.sfx-quicklink-icon-input', function() {
            // Real-time validation
            validateFieldRealtime($(this), 'icon');
            
            // Live preview of icon if valid
            var iconHtml = $(this).val();
            if (iconHtml && iconHtml.indexOf('<svg') !== -1 && iconHtml.indexOf('</svg>') !== -1) {
                var $item = $(this).closest('.sfx-quicklink-item');
                $item.find('.sfx-quicklink-icon-preview').html(iconHtml);
            }
        });
    }
    
    /**
     * Real-time field validation
     */
    function validateFieldRealtime($field, fieldType) {
        var value = $field.val().trim();
        var hasError = false;
        var errorMessage = '';
        
        // Clear previous error state for this field
        $field.removeClass('sfx-field-error');
        $field.siblings('.sfx-field-error-message').remove();
        
        switch (fieldType) {
            case 'title':
                // Check length (text content only, without HTML)
                if (value) {
                    var textOnly = $('<div>').html(value).text();
                    if (textOnly.length > 100) {
                        hasError = true;
                        errorMessage = sfxDashboardAdmin.strings.errorTitleTooLong || 'Title is too long (max 100 characters)';
                    }
                }
                break;
                
            case 'url':
                if (value) {
                    var urlLower = value.toLowerCase();
                    if (urlLower.indexOf('javascript:') === 0 || 
                        urlLower.indexOf('data:') === 0 || 
                        urlLower.indexOf('vbscript:') === 0) {
                        hasError = true;
                        errorMessage = sfxDashboardAdmin.strings.errorInvalidUrl || 'Invalid URL protocol';
                    }
                }
                break;
                
            case 'icon':
                if (value && (value.indexOf('<svg') === -1 || value.indexOf('</svg>') === -1)) {
                    hasError = true;
                    errorMessage = sfxDashboardAdmin.strings.errorInvalidSvg || 'Invalid SVG (must include <svg> tags)';
                }
                break;
        }
        
        if (hasError) {
            $field.addClass('sfx-field-error');
            $field.after('<span class="sfx-field-error-message">' + errorMessage + '</span>');
        }
    }
    
    /**
     * Close edit form and update display
     */
    function closeEditForm($item) {
        var $editForm = $item.find('.sfx-quicklink-edit-form');
        
        // Validate before closing
        var validation = validateQuicklinkFields($item);
        
        if (!validation.valid) {
            // Show validation errors but still allow closing
            showValidationFeedback($item, validation);
        }
        
        $item.removeClass('is-editing');
        $editForm.slideUp(200);
        
        // Update the display values
        var title = $item.find('.sfx-quicklink-title-input').val();
        var url = $item.find('.sfx-quicklink-url-input').val();
        var iconHtml = $item.find('.sfx-quicklink-icon-input').val();
        
        // Strip HTML for display text (HTML will be rendered properly after save)
        var displayTitle = title ? $('<div>').html(title).text() : (sfxDashboardAdmin.strings.untitled || 'Untitled');
        $item.find('.sfx-quicklink-title-display').text(displayTitle);
        $item.find('.sfx-quicklink-url-display').text(url || '—');
        
        // Update icon preview if valid SVG
        if (iconHtml && iconHtml.indexOf('<svg') !== -1 && iconHtml.indexOf('</svg>') !== -1) {
            $item.find('.sfx-quicklink-icon-preview').html(iconHtml);
        }
        
        // Clear validation states after a delay
        setTimeout(function() {
            clearValidationFeedback($item);
        }, 3000);
    }
    
    /**
     * Validate quicklink fields
     */
    function validateQuicklinkFields($item) {
        var result = {
            valid: true,
            errors: []
        };
        
        var $titleInput = $item.find('.sfx-quicklink-title-input');
        var $urlInput = $item.find('.sfx-quicklink-url-input');
        var $iconInput = $item.find('.sfx-quicklink-icon-input');
        
        var title = $titleInput.val().trim();
        var url = $urlInput.val().trim();
        var icon = $iconInput.val().trim();
        
        // Title validation
        if (!title && !url) {
            result.valid = false;
            result.errors.push({
                field: 'title',
                message: sfxDashboardAdmin.strings.errorTitleOrUrl || 'Title or URL is required'
            });
        }
        
        // Title length validation (max 100 chars, counting text without HTML)
        if (title) {
            var textOnly = $('<div>').html(title).text();
            if (textOnly.length > 100) {
                result.valid = false;
                result.errors.push({
                    field: 'title',
                    message: sfxDashboardAdmin.strings.errorTitleTooLong || 'Title is too long (max 100 characters)'
                });
            }
        }
        
        // URL validation
        if (url) {
            // Check for dangerous protocols
            var urlLower = url.toLowerCase();
            if (urlLower.indexOf('javascript:') === 0 || 
                urlLower.indexOf('data:') === 0 || 
                urlLower.indexOf('vbscript:') === 0) {
                result.valid = false;
                result.errors.push({
                    field: 'url',
                    message: sfxDashboardAdmin.strings.errorInvalidUrl || 'Invalid URL protocol'
                });
            }
        }
        
        // Icon validation
        if (icon && (icon.indexOf('<svg') === -1 || icon.indexOf('</svg>') === -1)) {
            result.valid = false;
            result.errors.push({
                field: 'icon',
                message: sfxDashboardAdmin.strings.errorInvalidSvg || 'Invalid SVG (must include <svg> tags)'
            });
        }
        
        return result;
    }
    
    /**
     * Show validation feedback on fields
     */
    function showValidationFeedback($item, validation) {
        // Clear previous feedback
        clearValidationFeedback($item);
        
        validation.errors.forEach(function(error) {
            var $field;
            switch (error.field) {
                case 'title':
                    $field = $item.find('.sfx-quicklink-title-input');
                    break;
                case 'url':
                    $field = $item.find('.sfx-quicklink-url-input');
                    break;
                case 'icon':
                    $field = $item.find('.sfx-quicklink-icon-input');
                    break;
            }
            
            if ($field && $field.length) {
                $field.addClass('sfx-field-error');
                $field.after('<span class="sfx-field-error-message">' + error.message + '</span>');
            }
        });
    }
    
    /**
     * Clear validation feedback
     */
    function clearValidationFeedback($item) {
        $item.find('.sfx-field-error').removeClass('sfx-field-error');
        $item.find('.sfx-field-error-message').remove();
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
            },
            {
                buttonId: 'sfx-reset-custom-css',
                varName: 'sfxDefaultDashboardCSS',
                confirmMsg: 'Are you sure you want to clear all additional CSS?',
                successMsg: 'CSS Cleared!',
                isCodeEditor: true,
                targetField: 'dashboard_custom_css'
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
            
            // Handle code editor (string value) vs regular settings (object)
            if (config.isCodeEditor) {
                // defaults can be empty string for "clear" action
                if (typeof defaults !== 'string') {
                    console.error('SFX: Invalid default value for code editor');
                    alert('Error: Could not process default value.');
                    return;
                }
                
                var $textarea = $('#' + config.targetField);
                if ($textarea.length) {
                    $textarea.val(defaults);
                    
                    // If CodeMirror is initialized, update it too
                    if (window.sfxCodeMirrorEditor) {
                        window.sfxCodeMirrorEditor.codemirror.setValue(defaults);
                    }
                    
                    $textarea.trigger('change');
                    console.log('SFX: CSS field updated');
                }
            } else {
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
            }
            
            // Show feedback
            var originalText = $resetButton.text();
            $resetButton.text(config.successMsg).prop('disabled', true);
            setTimeout(function() {
                $resetButton.text(originalText).prop('disabled', false);
            }, 2000);
        });
    }

    /**
     * Initialize role selector toggles
     */
    function initRoleSelectors() {
        // Toggle dropdown visibility
        $(document).on('click', '.sfx-quicklink-roles-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $toggle = $(this);
            var $dropdown = $toggle.siblings('.sfx-quicklink-roles-dropdown');
            var isExpanded = $toggle.attr('aria-expanded') === 'true';
            
            // Close all other dropdowns first
            $('.sfx-quicklink-roles-toggle').not($toggle).attr('aria-expanded', 'false');
            $('.sfx-quicklink-roles-dropdown').not($dropdown).hide();
            
            // Toggle current dropdown
            $toggle.attr('aria-expanded', !isExpanded);
            $dropdown.toggle(!isExpanded);
        });
        
        // Close dropdowns when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.sfx-quicklink-roles').length) {
                $('.sfx-quicklink-roles-toggle').attr('aria-expanded', 'false');
                $('.sfx-quicklink-roles-dropdown').hide();
            }
        });
        
        // Handle "All Roles" checkbox toggle
        $(document).on('change', '.sfx-role-all-checkbox', function() {
            var $allCheckbox = $(this);
            var $dropdown = $allCheckbox.closest('.sfx-quicklink-roles-dropdown');
            var $individualCheckboxes = $dropdown.find('.sfx-role-individual-checkbox');
            var $toggleLabel = $allCheckbox.closest('.sfx-quicklink-roles').find('.sfx-roles-toggle-label');
            
            if ($allCheckbox.is(':checked')) {
                // Disable and uncheck individual checkboxes
                $individualCheckboxes.prop('disabled', true).prop('checked', false);
                $toggleLabel.text(sfxDashboardAdmin.strings.allRoles || 'All Roles');
            } else {
                // Enable individual checkboxes
                $individualCheckboxes.prop('disabled', false);
                updateRoleToggleLabel($allCheckbox.closest('.sfx-quicklink-roles'));
            }
        });
        
        // Handle individual role checkbox changes
        $(document).on('change', '.sfx-role-individual-checkbox', function() {
            var $checkbox = $(this);
            var $rolesContainer = $checkbox.closest('.sfx-quicklink-roles');
            updateRoleToggleLabel($rolesContainer);
        });
    }
    
    /**
     * Update the role toggle label based on selected roles
     */
    function updateRoleToggleLabel($rolesContainer) {
        var $toggleLabel = $rolesContainer.find('.sfx-roles-toggle-label');
        var $allCheckbox = $rolesContainer.find('.sfx-role-all-checkbox');
        var $checkedIndividual = $rolesContainer.find('.sfx-role-individual-checkbox:checked');
        var strings = sfxDashboardAdmin.strings || {};
        
        if ($allCheckbox.is(':checked')) {
            $toggleLabel.text(strings.allRoles || 'All Roles');
        } else if ($checkedIndividual.length === 0) {
            // If no individual roles selected and "All" not checked, show "All Roles" anyway
            $toggleLabel.text(strings.allRoles || 'All Roles');
        } else if ($checkedIndividual.length === 1) {
            $toggleLabel.text(strings.oneRole || '1 Role');
        } else {
            $toggleLabel.text($checkedIndividual.length + ' ' + (strings.roles || 'Roles'));
        }
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initLogoUploader();
        initColorSelectPreview();
        initAddGroup();
        initRemoveGroup();
        initToggleGroup();
        initAddCustomQuicklink();
        initRemoveCustomQuicklink();
        initEditCustomQuicklink();
        initQuicklinkIconPreview();
        initResetButtons();
        initRoleSelectors();
        
        // Initialize sortables with a small delay to ensure DOM is ready
        setTimeout(function() {
            initStatsSortable();
            initQuicklinkGroupsSortable();
        }, 150);
        
        // Re-initialize sortables when switching tabs
        $(document).on('click', '.sfx-dashboard-tabs .nav-tab, .nav-tab-wrapper .nav-tab', function() {
            setTimeout(function() {
                initStatsSortable();
                initQuicklinkGroupsSortable();
            }, 200);
        });
    });

})(jQuery);
