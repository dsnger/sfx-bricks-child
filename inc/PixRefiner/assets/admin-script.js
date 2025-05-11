jQuery(function($){
    function logMessage(msg) {
        $('#log').prepend(msg + "\n");
    }

    // 1. Convert/Scale
    $('#start-conversion').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Converting...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_convert_single',
            nonce: PixRefinerAjax.nonce,
            offset: 0
        }, function(response){
            $btn.prop('disabled', false).text('1. Convert/Scale');
            if(response.success) {
                logMessage(response.data && response.data.message ? response.data.message : 'Success');
            } else {
                logMessage(response.data && response.data.message ? response.data.message : 'Error');
            }
        }).fail(function(xhr){
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // 2. Cleanup Images
    $('#cleanup-originals').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Cleaning...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_cleanup_originals',
            nonce: PixRefinerAjax.nonce
        }, function(response){
            $btn.prop('disabled', false).text('2. Cleanup Images');
            logMessage(response.success ? (response.data && response.data.message ? response.data.message : 'Success') : (response.data && response.data.message ? response.data.message : 'Error'));
        }).fail(function(xhr){
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // 3. Fix URLs
    $('#convert-post-images').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Fixing...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_fix_post_image_urls',
            nonce: PixRefinerAjax.nonce
        }, function(response){
            $btn.prop('disabled', false).text('3. Fix URLs');
            logMessage(response.success ? (response.data && response.data.message ? response.data.message : 'Success') : (response.data && response.data.message ? response.data.message : 'Error'));
        }).fail(function(xhr){
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // Run All (1-3)
    $('#run-all').on('click', function(){
        $('#start-conversion').trigger('click');
        setTimeout(function(){ $('#cleanup-originals').trigger('click'); }, 2000);
        setTimeout(function(){ $('#convert-post-images').trigger('click'); }, 4000);
    });

    // Set Max Widths
    $('#set-max-width').on('click', function(){
        var $btn = $(this);
        var widths = $('#max-width-input').val();
        $btn.prop('disabled', true).text('Saving...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_set_max_widths',
            nonce: PixRefinerAjax.nonce,
            widths: widths
        }, function(response){
            $btn.prop('disabled', false).text('Set Widths');
            logMessage(response.success ? (response.data && response.data.message ? response.data.message : 'Widths updated') : (response.data && response.data.message ? response.data.message : 'Error updating widths'));
        }).fail(function(xhr){
            $btn.prop('disabled', false).text('Set Widths');
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // Set Max Heights
    $('#set-max-height').on('click', function(){
        var $btn = $(this);
        var heights = $('#max-height-input').val();
        $btn.prop('disabled', true).text('Saving...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_set_max_heights',
            nonce: PixRefinerAjax.nonce,
            heights: heights
        }, function(response){
            $btn.prop('disabled', false).text('Set Heights');
            logMessage(response.success ? (response.data && response.data.message ? response.data.message : 'Heights updated') : (response.data && response.data.message ? response.data.message : 'Error updating heights'));
        }).fail(function(xhr){
            $btn.prop('disabled', false).text('Set Heights');
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // Clear Log
    $('#clear-log').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_clear_log',
            nonce: PixRefinerAjax.nonce
        }, function(response){
            $btn.prop('disabled', false).text('Clear Log');
            if(response.success) {
                $('#log').text('');
                logMessage('Log cleared.');
            } else {
                logMessage(response.data && response.data.message ? response.data.message : 'Error clearing log');
            }
        }).fail(function(xhr){
            $btn.prop('disabled', false).text('Clear Log');
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // Reset Defaults
    $('#reset-defaults').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Resetting...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_reset_defaults',
            nonce: PixRefinerAjax.nonce
        }, function(response){
            $btn.prop('disabled', false).text('Reset Defaults');
            logMessage(response.success ? (response.data && response.data.message ? response.data.message : 'Defaults reset') : (response.data && response.data.message ? response.data.message : 'Error resetting defaults'));
        }).fail(function(xhr){
            $btn.prop('disabled', false).text('Reset Defaults');
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // Export Media as ZIP
    $('#export-media-zip').on('click', function(){
        logMessage('Exporting media as ZIP...');
        window.open(PixRefinerAjax.ajax_url + '?action=webp_export_media_zip&nonce=' + PixRefinerAjax.nonce, '_blank');
    });

    // Utility: fetch excluded image IDs from server, then fetch and render details
    function refreshExcludedImages() {
        var $list = $('#excluded-images-list');
        $list.html('<li>Loading...</li>');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_get_excluded_images',
            nonce: PixRefinerAjax.nonce
        }, function(resp){
            var ids = (resp.success && resp.data && Array.isArray(resp.data)) ? resp.data : [];
            if (ids.length === 0) {
                renderExcludedImages([]);
                return;
            }
            // Use WP REST API to fetch attachment details
            $.ajax({
                url: '/wp-json/wp/v2/media?include=' + ids.join(',') + '&per_page=100',
                method: 'GET'
            }).done(function(items){
                // Deduplicate by ID
                var seen = {};
                var deduped = [];
                items.forEach(function(item){
                    if (!seen[item.id]) {
                        seen[item.id] = true;
                        deduped.push(item);
                    }
                });
                renderExcludedImages(deduped);
            }).fail(function(){
                renderExcludedImages([]);
            });
        });
    }

    function renderExcludedImages(items) {
        var $list = $('#excluded-images-list');
        $list.empty();
        if(items.length) {
            items.forEach(function(item){
                var thumb = item.media_details && item.media_details.sizes && item.media_details.sizes.thumbnail
                    ? item.media_details.sizes.thumbnail.source_url
                    : (item.source_url || '');
                var name = item.title && item.title.rendered ? item.title.rendered : '';
                var id = item.id;
                $list.append(
                    '<li data-id="'+id+'" style="display: flex; align-items: center; gap: 16px; padding: 10px 0; border-bottom: 1px solid #eee; width: 100%;">'
                    + '<img src="'+thumb+'" alt="" style="max-width: 60px; max-height: 60px; border-radius: 4px; margin-right: 16px;" />'
                    + '<div style="flex:1; font-size: 15px;">'
                    + '<div><strong>'+name+'</strong></div>'
                    + '<div style="color: #888; font-size: 13px;">ID: '+id+'</div>'
                    + '</div>'
                    + '<button class="button remove-excluded-image" style="margin-left: auto;">Remove</button>'
                    + '</li>'
                );
            });
        } else {
            $list.append('<li>No excluded images.</li>');
        }
    }

    // Remove excluded image
    $('#excluded-images-list').on('click', '.remove-excluded-image', function(){
        var $li = $(this).closest('li');
        var id = $li.data('id');
        var $btn = $li.find('button');
        $btn.prop('disabled', true).text('Removing...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_remove_excluded_image',
            nonce: PixRefinerAjax.nonce,
            attachment_id: id
        }, function(response){
            if(response.success) {
                logMessage('Image removed from exclusion: ' + id);
            } else {
                logMessage(response.data && response.data.message ? response.data.message : 'Error removing image');
            }
            refreshExcludedImages();
        });
    });

    // Add from Media Library
    $('#open-media-library').off('click').on('click', function(e){
        e.preventDefault();
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            alert('WordPress media library not available.');
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Adding...');
        var frame = wp.media({
            title: 'Select Images to Exclude',
            button: { text: 'Exclude Selected' },
            multiple: true,
            library: { type: 'image' }
        });
        frame.on('select', function(){
            var selection = frame.state().get('selection');
            var idsToAdd = [];
            selection.each(function(attachment){
                var id = attachment.id;
                if (idsToAdd.indexOf(id) === -1) idsToAdd.push(id);
            });
            if (idsToAdd.length === 0) {
                $btn.prop('disabled', false).text('Add from Media Library');
                return;
            }
            var pending = idsToAdd.length;
            idsToAdd.forEach(function(id){
                $.post(PixRefinerAjax.ajax_url, {
                    action: 'webp_add_excluded_image',
                    nonce: PixRefinerAjax.nonce,
                    attachment_id: id
                }, function(response){
                    if(response.success) {
                        logMessage('Image excluded: ' + id);
                    } else {
                        logMessage(response.data && response.data.message ? response.data.message : 'Error excluding image');
                    }
                    pending--;
                    if (pending === 0) {
                        $btn.prop('disabled', false).text('Add from Media Library');
                        refreshExcludedImages();
                    }
                });
            });
        });
        frame.open();
    });

    // Set Min Size KB
    $('#set-min-size-kb').on('click', function(){
        var $btn = $(this);
        var minSize = $('#min-size-kb').val();
        $btn.prop('disabled', true).text('Saving...');
        $.post(PixRefinerAjax.ajax_url, {
            action: 'webp_set_min_size_kb',
            nonce: PixRefinerAjax.nonce,
            min_size_kb: minSize
        }, function(response){
            $btn.prop('disabled', false).text('Set Min Size');
            logMessage(response.success ? (response.data && response.data.message ? response.data.message : 'Min size updated') : (response.data && response.data.message ? response.data.message : 'Error updating min size'));
        }).fail(function(xhr){
            $btn.prop('disabled', false).text('Set Min Size');
            logMessage('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
        });
    });

    // Initial load: always fetch from server
    refreshExcludedImages();
}); 