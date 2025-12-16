/**
 * SFX Import/Export Admin Script
 * 
 * Handles export/import functionality with AJAX and file handling.
 */
(function($) {
    'use strict';

    // Store imported data for later use
    let importedData = null;

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initSelectControls();
        initExportForm();
        initImportForm();
        initFileUpload();
        initResultsClose();
    });

    /**
     * Initialize results close button
     */
    function initResultsClose() {
        $(document).on('click', '.sfx-results-close', function() {
            $('#sfx-results').slideUp();
        });
    }

    /**
     * Initialize select all/deselect all controls
     */
    function initSelectControls() {
        $('.sfx-select-all').on('click', function(e) {
            e.preventDefault();
            const $container = $(this).closest('.sfx-card, .sfx-import-preview');
            $container.find('input[type="checkbox"]').prop('checked', true);
            updateImportButton();
        });

        $('.sfx-deselect-all').on('click', function(e) {
            e.preventDefault();
            const $container = $(this).closest('.sfx-card, .sfx-import-preview');
            $container.find('input[type="checkbox"]').prop('checked', false);
            updateImportButton();
        });
    }

    /**
     * Initialize export form
     */
    function initExportForm() {
        $('#sfx-export-form').on('submit', function(e) {
            e.preventDefault();
            handleExport();
        });
    }

    /**
     * Initialize import form
     */
    function initImportForm() {
        $('#sfx-import-form').on('submit', function(e) {
            e.preventDefault();
            handleImport();
        });

        // Update button state when checkboxes change
        $(document).on('change', '#sfx-import-preview input[type="checkbox"]', updateImportButton);
    }

    /**
     * Initialize file upload handling
     */
    function initFileUpload() {
        $('#sfx-import-file').on('change', function(e) {
            const file = e.target.files[0];
            if (!file) {
                hideImportPreview();
                return;
            }

            // Validate file
            if (!validateFile(file)) {
                return;
            }

            // Read and preview file
            readAndPreviewFile(file);
        });
    }

    /**
     * Validate uploaded file
     */
    function validateFile(file) {
        // Check file type
        if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
            showNotice('error', sfxImportExport.strings.invalidFile);
            return false;
        }

        // Check file size (2MB max)
        if (file.size > 2097152) {
            showNotice('error', sfxImportExport.strings.fileTooLarge);
            return false;
        }

        return true;
    }

    /**
     * Read file and show preview
     */
    function readAndPreviewFile(file) {
        const reader = new FileReader();

        reader.onload = function(e) {
            try {
                const data = JSON.parse(e.target.result);
                importedData = data;
                showImportPreview(data);
            } catch (error) {
                showNotice('error', sfxImportExport.strings.invalidFile);
                hideImportPreview();
            }
        };

        reader.onerror = function() {
            showNotice('error', sfxImportExport.strings.importError);
            hideImportPreview();
        };

        reader.readAsText(file);
    }

    /**
     * Show import preview
     */
    function showImportPreview(data) {
        const $preview = $('#sfx-import-preview');
        const $settingsGroup = $('#sfx-import-settings-group');
        const $posttypesGroup = $('#sfx-import-posttypes-group');
        const $settingsCheckboxes = $('#sfx-import-settings-checkboxes');
        const $posttypesCheckboxes = $('#sfx-import-posttypes-checkboxes');

        // Show metadata
        $('.sfx-preview-version').text('Export Version: ' + (data.version || 'unknown'));
        $('.sfx-preview-date').text('Exported: ' + formatDate(data.exported_at));
        $('.sfx-preview-user').text('By: ' + (data.exported_by || 'unknown'));

        // Clear existing checkboxes
        $settingsCheckboxes.empty();
        $posttypesCheckboxes.empty();

        // Show settings checkboxes
        if (data.data && data.data.settings && Object.keys(data.data.settings).length > 0) {
            for (const [key, value] of Object.entries(data.data.settings)) {
                const label = getSettingsLabel(key);
                if (label) {
                    $settingsCheckboxes.append(createCheckbox('import_settings[]', key, label, true));
                }
            }
            $settingsGroup.show();
        } else {
            $settingsGroup.hide();
        }

        // Show post type checkboxes
        if (data.data && data.data.post_types && Object.keys(data.data.post_types).length > 0) {
            for (const [postType, posts] of Object.entries(data.data.post_types)) {
                const label = getPostTypeLabel(postType);
                if (label) {
                    const count = Array.isArray(posts) ? posts.length : 0;
                    $posttypesCheckboxes.append(createCheckbox('import_posttypes[]', postType, label + ' (' + count + ' items)', true));
                }
            }
            $posttypesGroup.show();
        } else {
            $posttypesGroup.hide();
        }

        // Show preview and other sections
        $preview.slideDown();
        $('#sfx-import-mode-section').slideDown();
        $('#sfx-import-warning').slideDown();

        // Enable import button
        updateImportButton();
    }

    /**
     * Hide import preview
     */
    function hideImportPreview() {
        importedData = null;
        $('#sfx-import-preview').slideUp();
        $('#sfx-import-mode-section').slideUp();
        $('#sfx-import-warning').slideUp();
        $('.sfx-import-btn').prop('disabled', true);
    }

    /**
     * Update import button state
     */
    function updateImportButton() {
        const hasSelection = $('#sfx-import-preview input[type="checkbox"]:checked').length > 0;
        $('.sfx-import-btn').prop('disabled', !hasSelection || !importedData);
    }

    /**
     * Get settings group label (uses localized data from PHP - single source of truth)
     */
    function getSettingsLabel(key) {
        return sfxImportExport.settingsLabels[key] || null;
    }

    /**
     * Get post type label (uses localized data from PHP - single source of truth)
     */
    function getPostTypeLabel(postType) {
        return sfxImportExport.postTypeLabels[postType] || null;
    }

    /**
     * Create checkbox HTML
     */
    function createCheckbox(name, value, label, checked) {
        const id = 'import_' + value.replace(/[^a-z0-9]/gi, '_');
        return `
            <label class="sfx-checkbox-label">
                <input type="checkbox" name="${name}" value="${value}" id="${id}" ${checked ? 'checked' : ''}>
                <span class="sfx-checkbox-text">
                    <strong>${escapeHtml(label)}</strong>
                </span>
            </label>
        `;
    }

    /**
     * Handle export
     */
    function handleExport() {
        const $form = $('#sfx-export-form');
        const $btn = $form.find('.sfx-export-btn');
        const $spinner = $form.find('.spinner');

        // Get selected items
        const settings = [];
        const posttypes = [];

        $form.find('input[name="export_settings[]"]:checked').each(function() {
            settings.push($(this).val());
        });

        $form.find('input[name="export_posttypes[]"]:checked').each(function() {
            posttypes.push($(this).val());
        });

        if (settings.length === 0 && posttypes.length === 0) {
            showNotice('error', sfxImportExport.strings.noSelection);
            return;
        }

        // Show loading state
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        // Send AJAX request
        $.ajax({
            url: sfxImportExport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sfx_export_settings',
                nonce: sfxImportExport.exportNonce,
                settings: settings,
                posttypes: posttypes
            },
            success: function(response) {
                if (response.success) {
                    // Trigger file download
                    downloadJSON(response.data.data, response.data.filename);
                    // Show export summary
                    showExportSummary(response.data.data, response.data.filename, settings, posttypes);
                } else {
                    showNotice('error', response.data.message || sfxImportExport.strings.exportError);
                }
            },
            error: function() {
                showNotice('error', sfxImportExport.strings.exportError);
            },
            complete: function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    }

    /**
     * Handle import
     */
    function handleImport() {
        if (!importedData) {
            showNotice('error', sfxImportExport.strings.invalidFile);
            return;
        }

        const $form = $('#sfx-import-form');
        const $btn = $form.find('.sfx-import-btn');
        const $spinner = $form.find('.spinner');

        // Get selected items
        const settings = [];
        const posttypes = [];

        $('#sfx-import-preview input[name="import_settings[]"]:checked').each(function() {
            settings.push($(this).val());
        });

        $('#sfx-import-preview input[name="import_posttypes[]"]:checked').each(function() {
            posttypes.push($(this).val());
        });

        if (settings.length === 0 && posttypes.length === 0) {
            showNotice('error', sfxImportExport.strings.noSelection);
            return;
        }

        // Get import mode
        const mode = $form.find('input[name="import_mode"]:checked').val() || 'merge';

        // Confirm action
        const confirmMessage = mode === 'replace' 
            ? sfxImportExport.strings.confirmReplace 
            : sfxImportExport.strings.confirmMerge;

        if (!confirm(confirmMessage)) {
            return;
        }

        // Show loading state
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        // Send AJAX request
        $.ajax({
            url: sfxImportExport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sfx_import_settings',
                nonce: sfxImportExport.importNonce,
                import_data: JSON.stringify(importedData),
                settings: settings,
                posttypes: posttypes,
                mode: mode
            },
            success: function(response) {
                if (response.success) {
                    showResults(response.data.results);
                    showNotice('success', response.data.message || sfxImportExport.strings.importSuccess);
                    
                    // Reset form
                    hideImportPreview();
                    $('#sfx-import-file').val('');
                } else {
                    showNotice('error', response.data.message || sfxImportExport.strings.importError);
                }
            },
            error: function() {
                showNotice('error', sfxImportExport.strings.importError);
            },
            complete: function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    }

    /**
     * Download JSON file
     */
    function downloadJSON(data, filename) {
        const json = JSON.stringify(data, null, 2);
        const blob = new Blob([json], { type: 'application/json' });
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /**
     * Show export summary
     */
    function showExportSummary(data, filename, selectedSettings, selectedPostTypes) {
        const $results = $('#sfx-results');
        const $content = $results.find('.sfx-results-content');

        let html = `
            <div class="sfx-results-header">
                <h3><span class="dashicons dashicons-yes-alt"></span> Export Completed Successfully</h3>
                <button type="button" class="sfx-results-close" title="Close">&times;</button>
            </div>
            <div class="sfx-results-meta">
                <span><strong>File:</strong> ${escapeHtml(filename)}</span>
                <span><strong>Date:</strong> ${formatDate(data.exported_at)}</span>
                <span><strong>Theme Version:</strong> ${escapeHtml(data.theme_version || 'unknown')}</span>
            </div>
        `;

        html += '<ul class="sfx-results-list">';

        // Settings exported
        if (selectedSettings.length > 0 && data.data && data.data.settings) {
            for (const key of selectedSettings) {
                if (data.data.settings[key] !== undefined) {
                    const label = getSettingsLabel(key) || key;
                    html += `<li class="sfx-result-item sfx-result-success">
                        <span class="dashicons dashicons-yes"></span>
                        ${escapeHtml(label)} exported
                    </li>`;
                }
            }
        }

        // Post types exported
        if (selectedPostTypes.length > 0 && data.data && data.data.post_types) {
            for (const postType of selectedPostTypes) {
                if (data.data.post_types[postType] !== undefined) {
                    const label = getPostTypeLabel(postType) || postType;
                    const count = Array.isArray(data.data.post_types[postType]) ? data.data.post_types[postType].length : 0;
                    html += `<li class="sfx-result-item sfx-result-success">
                        <span class="dashicons dashicons-yes"></span>
                        ${escapeHtml(label)}: ${count} item(s) exported
                    </li>`;
                }
            }
        }

        html += '</ul>';
        $content.html(html);
        $results.slideDown();

        // Scroll to results
        $('html, body').animate({
            scrollTop: $results.offset().top - 50
        }, 300);
    }

    /**
     * Show import results
     */
    function showResults(results) {
        const $results = $('#sfx-results');
        const $content = $results.find('.sfx-results-content');

        let html = `
            <div class="sfx-results-header">
                <h3><span class="dashicons dashicons-yes-alt"></span> Import Completed</h3>
                <button type="button" class="sfx-results-close" title="Close">&times;</button>
            </div>
        `;

        html += '<ul class="sfx-results-list">';

        // Settings results
        if (results.settings && Object.keys(results.settings).length > 0) {
            for (const [key, result] of Object.entries(results.settings)) {
                const icon = result.status === 'success' ? 'yes' : 'no';
                const className = result.status === 'success' ? 'success' : 'error';
                html += `<li class="sfx-result-item sfx-result-${className}">
                    <span class="dashicons dashicons-${icon}"></span>
                    ${escapeHtml(result.message)}
                </li>`;
            }
        }

        // Post types results
        if (results.post_types && Object.keys(results.post_types).length > 0) {
            for (const [postType, result] of Object.entries(results.post_types)) {
                const icon = result.status === 'success' ? 'yes' : (result.status === 'partial' ? 'warning' : 'no');
                const className = result.status === 'success' ? 'success' : (result.status === 'partial' ? 'warning' : 'error');
                html += `<li class="sfx-result-item sfx-result-${className}">
                    <span class="dashicons dashicons-${icon}"></span>
                    ${escapeHtml(result.message)}
                </li>`;
            }
        }

        html += '</ul>';
        $content.html(html);
        $results.slideDown();

        // Scroll to results
        $('html, body').animate({
            scrollTop: $results.offset().top - 50
        }, 300);
    }

    /**
     * Show notice
     */
    function showNotice(type, message) {
        // Remove existing notices
        $('.sfx-admin-notice').remove();

        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const $notice = $(`
            <div class="notice ${noticeClass} is-dismissible sfx-admin-notice">
                <p>${escapeHtml(message)}</p>
            </div>
        `);

        $('.sfx-import-export-wrap > h1').after($notice);

        // Make dismissible work
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(200, function() {
                $(this).remove();
            });
        });

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(200, function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Format date string
     */
    function formatDate(dateString) {
        if (!dateString || dateString === 'unknown') {
            return 'Unknown date';
        }
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        } catch (e) {
            return dateString;
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);

