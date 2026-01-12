/**
 * Custom Dashboard Admin Settings Scripts
 *
 * @package SFX_Bricks_Child_Theme
 */

(function($) {
    'use strict';

    // Constants
    const ANIMATION_DURATION = 1000; // milliseconds
    const SLIDE_DURATION = 200; // milliseconds
    const FADE_DURATION = 300; // milliseconds
    const FEEDBACK_TIMEOUT = 2000; // milliseconds
    const VALIDATION_CLEAR_DELAY = 3000; // milliseconds

    /**
     * Escape HTML special characters for safe insertion
     */
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Escape text for use in HTML attributes
     */
    function escapeAttr(text) {
        if (typeof text !== 'string') return '';
        return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

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
            
            // Update group inputs (including nested role checkboxes)
            $group.find('.sfx-quicklink-group-header input').each(function() {
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
            
            // Build role checkboxes HTML (escape role names for XSS prevention)
            var rolesHtml = '';
            $.each(availableRoles, function(roleSlug, roleName) {
                rolesHtml += '<label class="sfx-role-checkbox">' +
                    '<input type="checkbox" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][roles][]" value="' + escapeAttr(roleSlug) + '" class="sfx-role-individual-checkbox" disabled />' +
                    '<span>' + escapeHtml(roleName) + '</span>' +
                '</label>';
            });
            
            var groupHtml = 
                '<div class="sfx-quicklink-group sfx-quicklink-group-new" data-group-id="' + newGroupId + '">' +
                    '<div class="sfx-quicklink-group-header">' +
                        '<span class="sfx-group-drag-handle">☰</span>' +
                        '<input type="hidden" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][id]" value="' + newGroupId + '" class="sfx-group-id" />' +
                        '<input type="text" name="' + optionName + '[quicklinks_sortable][groups][' + groupIndex + '][title]" value="" class="sfx-group-title-input" placeholder="' + escapeAttr(strings.groupTitle || 'Group Title') + '" />' +
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
            }, ANIMATION_DURATION);
            
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
                $group.fadeOut(FADE_DURATION, function() {
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
            
            $content.slideToggle(SLIDE_DURATION);
            
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
            
            // Build role checkboxes HTML (escape role names for XSS prevention)
            var rolesHtml = '';
            $.each(availableRoles, function(roleSlug, roleName) {
                rolesHtml += '<label class="sfx-role-checkbox">' +
                    '<input type="checkbox" name="' + namePrefix + '[roles][]" value="' + escapeAttr(roleSlug) + '" class="sfx-role-individual-checkbox" disabled />' +
                    '<span>' + escapeHtml(roleName) + '</span>' +
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
                                '<input type="text" name="' + namePrefix + '[title]" value="" class="sfx-quicklink-title-input" placeholder="' + escapeAttr(strings.linkTitle || 'Link Title') + '" />' +
                            '</div>' +
                            '<div class="sfx-edit-field">' +
                                '<label>' + (strings.url || 'URL') + '</label>' +
                                '<div class="sfx-url-field-wrapper">' +
                                    '<input type="text" name="' + namePrefix + '[url]" value="" class="sfx-quicklink-url-input" placeholder="admin.php?page=example" />' +
                                    '<button type="button" class="sfx-browse-url-button" title="' + (strings.browseLinks || 'Browse WordPress Links') + '">' +
                                        '<span class="dashicons dashicons-search"></span>' +
                                    '</button>' +
                                '</div>' +
                            '</div>' +
                            '<div class="sfx-edit-field">' +
                                '<label>' + (strings.openIn || 'Open in') + '</label>' +
                                '<select name="' + namePrefix + '[target]" class="sfx-quicklink-target-input">' +
                                    '<option value="_self">' + (strings.sameTab || 'Same Tab') + '</option>' +
                                    '<option value="_blank">' + (strings.newTab || 'New Tab') + '</option>' +
                                '</select>' +
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
            }, ANIMATION_DURATION);
            
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
                $item.fadeOut(FADE_DURATION, function() {
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
                $editForm.slideDown(SLIDE_DURATION, function() {
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
        $editForm.slideUp(SLIDE_DURATION);
        
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
        }, VALIDATION_CLEAR_DELAY);
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
        
        // Title and URL validation - both are required for a quicklink
        if (!title || !url) {
            result.valid = false;
            result.errors.push({
                field: !title ? 'title' : 'url',
                message: sfxDashboardAdmin.strings.errorTitleAndUrl || 'Both title and URL are required'
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
            // Check for dangerous protocols (but allow placeholders)
            var urlLower = url.toLowerCase();
            // Only check for dangerous protocols if URL doesn't contain placeholders
            var hasPlaceholder = /\{admin_url\}|\{site_url\}|\{home_url\}/.test(url);
            
            if (!hasPlaceholder && (
                urlLower.indexOf('javascript:') === 0 || 
                urlLower.indexOf('data:') === 0 || 
                urlLower.indexOf('vbscript:') === 0)) {
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
     * Initialize sortable widgets
     */
    function initWidgetsSortable() {
        var $sortable = $('#sfx-widgets-sortable');
        
        if (!$sortable.length) {
            return;
        }
        
        // Check if sortable is available
        if (typeof $.fn.sortable === 'undefined') {
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
            items: '> li.sfx-widget-item',
            placeholder: 'sfx-widget-placeholder',
            axis: 'y',
            cursor: 'move',
            opacity: 0.8,
            revert: 150,
            update: function(event, ui) {
                updateWidgetsIndices();
            }
        });
    }

    /**
     * Update widgets item indices after reorder
     */
    function updateWidgetsIndices() {
        $('#sfx-widgets-sortable .sfx-widget-item').each(function(index) {
            var $item = $(this);
            $item.find('input').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                if (name) {
                    // Replace the index number in enabled_dashboard_widgets[X]
                    name = name.replace(/\[enabled_dashboard_widgets\]\[\d+\]/, '[enabled_dashboard_widgets][' + index + ']');
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
                }
            } else {
                if (!defaults || typeof defaults !== 'object') {
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
                    }
                });
            }
            
            // Show feedback
            var originalText = $resetButton.text();
            $resetButton.text(config.successMsg).prop('disabled', true);
            setTimeout(function() {
                $resetButton.text(originalText).prop('disabled', false);
            }, FEEDBACK_TIMEOUT);
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
     * Initialize URL Suggestions Modal
     */
    function initUrlSuggestionsModal() {
        var $modal = null;
        var $currentItem = null;
        var suggestions = sfxDashboardAdmin.urlSuggestions || {};
        var strings = sfxDashboardAdmin.strings || {};
        
        // Create modal HTML if not exists
        function createModal() {
            if ($('#sfx-url-suggestions-modal').length) {
                return $('#sfx-url-suggestions-modal');
            }
            
            var categoriesHtml = '';
            $.each(suggestions, function(categoryKey, category) {
                if (!category.items || category.items.length === 0) {
                    return; // skip empty categories
                }
                
                var itemsHtml = '';
                $.each(category.items, function(i, item) {
                    itemsHtml += 
                        '<div class="sfx-suggestion-item" data-url="' + escapeAttr(item.url) + '" data-title="' + escapeAttr(item.title) + '" data-icon="' + escapeAttr(item.icon) + '">' +
                            '<span class="sfx-suggestion-icon">' + item.icon + '</span>' +
                            '<span class="sfx-suggestion-title">' + escapeHtml(item.title) + '</span>' +
                        '</div>';
                });
                
                categoriesHtml += 
                    '<div class="sfx-suggestion-category" data-category="' + categoryKey + '">' +
                        '<h4 class="sfx-suggestion-category-title">' + escapeHtml(category.label) + '</h4>' +
                        '<div class="sfx-suggestion-items">' + itemsHtml + '</div>' +
                    '</div>';
            });
            
            var modalHtml = 
                '<div id="sfx-url-suggestions-modal" class="sfx-modal-overlay" style="display: none;">' +
                    '<div class="sfx-modal-container">' +
                        '<div class="sfx-modal-header">' +
                            '<h3 class="sfx-modal-title">' + escapeHtml(strings.selectLink || 'Select a WordPress Link') + '</h3>' +
                            '<button type="button" class="sfx-modal-close" aria-label="' + escapeAttr(strings.close || 'Close') + '">&times;</button>' +
                        '</div>' +
                        '<div class="sfx-modal-search">' +
                            '<input type="text" class="sfx-modal-search-input" placeholder="' + escapeAttr(strings.searchLinks || 'Search links...') + '" />' +
                        '</div>' +
                        '<div class="sfx-modal-body">' +
                            '<div class="sfx-suggestions-grid">' + categoriesHtml + '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            
            $('body').append(modalHtml);
            return $('#sfx-url-suggestions-modal');
        }
        
        // Note: escapeHtml and escapeAttr functions are defined globally at the top of this file
        
        // Open modal
        function openModal($item) {
            $modal = createModal();
            $currentItem = $item;
            
            // Clear search
            $modal.find('.sfx-modal-search-input').val('');
            $modal.find('.sfx-suggestion-item, .sfx-suggestion-category').show();
            
            // Show modal with animation
            $modal.fadeIn(SLIDE_DURATION);
            $modal.find('.sfx-modal-search-input').focus();
            
            // Prevent body scroll
            $('body').addClass('sfx-modal-open');
        }
        
        // Close modal
        function closeModal() {
            if ($modal) {
                $modal.fadeOut(SLIDE_DURATION);
                $('body').removeClass('sfx-modal-open');
            }
            $currentItem = null;
        }
        
        // Select suggestion
        function selectSuggestion($suggestion) {
            if (!$currentItem) {
                return;
            }
            
            var url = $suggestion.data('url');
            var title = $suggestion.data('title');
            var icon = $suggestion.data('icon');
            
            // Fill the form fields
            var $titleInput = $currentItem.find('.sfx-quicklink-title-input');
            var $urlInput = $currentItem.find('.sfx-quicklink-url-input');
            var $iconInput = $currentItem.find('.sfx-quicklink-icon-input');
            
            $titleInput.val(title).trigger('input');
            $urlInput.val(url).trigger('input');
            $iconInput.val(icon).trigger('input');
            
            // Update preview
            $currentItem.find('.sfx-quicklink-title-display').text(title);
            $currentItem.find('.sfx-quicklink-url-display').text(url);
            $currentItem.find('.sfx-quicklink-icon-preview').html(icon);
            
            closeModal();
        }
        
        // Filter suggestions by search
        function filterSuggestions(searchTerm) {
            if (!$modal) return;
            
            searchTerm = searchTerm.toLowerCase().trim();
            
            if (!searchTerm) {
                $modal.find('.sfx-suggestion-item, .sfx-suggestion-category').show();
                return;
            }
            
            $modal.find('.sfx-suggestion-category').each(function() {
                var $category = $(this);
                var hasVisibleItems = false;
                
                $category.find('.sfx-suggestion-item').each(function() {
                    var $item = $(this);
                    var title = $item.data('title').toLowerCase();
                    var url = $item.data('url').toLowerCase();
                    
                    if (title.indexOf(searchTerm) !== -1 || url.indexOf(searchTerm) !== -1) {
                        $item.show();
                        hasVisibleItems = true;
                    } else {
                        $item.hide();
                    }
                });
                
                // Hide category if no items match
                if (hasVisibleItems) {
                    $category.show();
                } else {
                    $category.hide();
                }
            });
        }
        
        // Event handlers
        
        // Open modal on browse button click
        $(document).on('click', '.sfx-browse-url-button', function(e) {
            e.preventDefault();
            var $item = $(this).closest('.sfx-quicklink-item');
            openModal($item);
        });
        
        // Close modal on overlay click
        $(document).on('click', '.sfx-modal-overlay', function(e) {
            if ($(e.target).hasClass('sfx-modal-overlay')) {
                closeModal();
            }
        });
        
        // Close modal on close button click
        $(document).on('click', '.sfx-modal-close', function(e) {
            e.preventDefault();
            closeModal();
        });
        
        // Close modal on escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $modal && $modal.is(':visible')) {
                closeModal();
            }
        });
        
        // Select suggestion on click
        $(document).on('click', '.sfx-suggestion-item', function(e) {
            e.preventDefault();
            selectSuggestion($(this));
        });
        
        // Search filter
        $(document).on('input', '.sfx-modal-search-input', function() {
            filterSuggestions($(this).val());
        });
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
        initUrlSuggestionsModal();
        
        // Initialize sortables with a small delay to ensure DOM is ready
        setTimeout(function() {
            initStatsSortable();
            initWidgetsSortable();
            initQuicklinkGroupsSortable();
        }, 150);

        // Re-initialize sortables when switching tabs
        $(document).on('click', '.sfx-dashboard-tabs .nav-tab, .nav-tab-wrapper .nav-tab', function() {
            setTimeout(function() {
                initStatsSortable();
                initWidgetsSortable();
                initQuicklinkGroupsSortable();
            }, SLIDE_DURATION);
        });
    });

})(jQuery);
