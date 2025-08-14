(function($) {
    'use strict';

    $(document).ready(function() {
        var itemList = $('#sfx-content-order-list'), // Container of item list
            maxLevel = 6,
            sort_started = {}, // For data related to the dragged element when dragging started
            sort_finished = {}; // For data related to the dragged element when dragging has finished

        // Check if we're on a content order page
        if (!itemList.length) {
            return;
        }

        // Check if post type is hierarchical
        var isHierarchical = itemList.find('li').first().attr('data-parent') !== '0';
        if (!isHierarchical) {
            maxLevel = 1;
        }

        // Make item list into nested sortable
        // Ref: https://api.jqueryui.com/sortable/
        // Ref: https://github.com/ilikenwf/nestedSortable
        itemList.nestedSortable({
            // Disable nesting if set to true
            protectRoot: true,
            // Forces the placeholder to have a size.
            forcePlaceholderSize: true,
            // Restricts sort start click to the specified element.
            // Allows for a helper element to be used for dragging display.
            // If set to "clone", then the element will be cloned and the clone will be dragged.
            helper: 'clone',
            listType: 'ul',
            items: 'li',
            toleranceElement: '> div', // Direct children of the li element
            handle: 'div', // The same <div> for toleranceElement is set as the drag handle
            // Specifies which mode to use for testing whether the item being moved is hovering over another item.
            // If set to 'pointer', is when the mouse pointer overlaps the other item.
            tolerance: 'pointer',
            // The maximum depth of nested items the list can accept.
            maxLevels: maxLevel,
            // Defines the opacity of the helper while sorting.
            opacity: 0.8,
            placeholder: 'ui-sortable-placeholder',
            // Whether the sortable items should revert to their new positions using a smooth animation.
            // Reduced from 250ms to 100ms for faster response
            revert: 100,
            // How far right or left (in pixels) the item has to travel 
            // in order to be nested or to be sent outside its current list. Default: 20
            tabSize: 20,
            // Reduce delay for better responsiveness
            delay: 0,
            // Distance before dragging starts (reduced for better responsiveness)
            distance: 5,
            // This event is triggered when sorting starts.
            start: function(event, ui) {
                sort_started.item = ui.item; // The jQuery object representing the current dragged element.
                sort_started.prev = ui.item.prev(':not(".ui-sortable-placeholder")');
                sort_started.next = ui.item.next(':not(".ui-sortable-placeholder")');
                
                // Add visual feedback immediately
                ui.item.addClass('dragging');
                
                // Optimize performance during drag
                ui.placeholder.css('height', ui.item.height());
            },
            // This event is triggered when sorting stops.
            stop: function(event, ui) {
                // Remove visual feedback
                ui.item.removeClass('dragging');
            },
            // This event is triggered when the user stopped sorting and the DOM position has changed.
            update: function(event, ui) {
                // Elements of the "Updating order..." notice
                var updateNotice = $('#updating-order-notice'), // Wrapper
                    spinner = $('#spinner-img'), // Spinner
                    updateSuccess = $('.updating-order-notice .dashicons.dashicons-saved'); // Check mark

                ui.item.find('div.row-content:first').append(updateNotice);

                // Reset the state of the "Updating order..." indicator
                $(spinner).show();
                $(updateSuccess).hide();
                $(updateNotice).removeClass('success').addClass('updating').css('background-color', '#f0f6fc').fadeIn();

                // Get the end items where the item was placed
                sort_finished.item = ui.item; // The jQuery object representing the current dragged element.
                sort_finished.prev = ui.item.prev(':not(".ui-sortable-placeholder")');
                sort_finished.next = ui.item.next(':not(".ui-sortable-placeholder")');

                var list_offset = parseInt(sort_finished.item.index());
                sort_finished.item.attr('data-menu-order', list_offset);

                // Get attributes
                var attributes = {};
                $.each(sort_finished.item[0].attributes, function() {
                    attributes[this.name] = this.value;
                });

                // Data for ajax call
                var dataArgs = {
                    action: 'save_custom_order', // AJAX action name
                    item_parent: 0, // We only deal with top-level items, not child items
                    start: 0, // Start processing menu_order update in DB from item with menu_order defined here
                    nonce: sfxContentOrder.nonce, // Nonce from wp_localize_script
                    post_id: sort_finished.item.attr('data-id'),
                    menu_order: sort_finished.item.attr('data-menu-order'),
                    excluded_items: {},
                    post_type: sort_started.item.attr('data-post-type'),
                    attributes: attributes,
                };

                // AJAX call to update menu_order for items in the list
                $.ajax({
                    type: "POST",
                    url: ajaxurl,
                    data: dataArgs,
                    success: function(response) {
                        // Update the state of the "Updating order..." indicator
                        $(spinner).hide();
                        $(updateSuccess).show();
                        $(updateNotice).removeClass('updating').addClass('success').css('background-color', '#f0f8f0');
                        
                        // Show success message briefly then fade out
                        setTimeout(function() {
                            $(updateNotice).fadeOut();
                        }, 1500);
                    },
                    error: function(xhr, status, errorThrown) {
                        console.error('Content order update failed:', errorThrown);
                        
                        // Show error state
                        $(spinner).hide();
                        $(updateNotice).removeClass('updating').addClass('error')
                            .css('background-color', '#fef7f1')
                            .css('border-color', '#d63638')
                            .css('color', '#d63638');
                        
                        // Change text to error message
                        $(updateNotice).find('.dashicons').removeClass('dashicons-saved').addClass('dashicons-warning');
                        $(updateNotice).find('span:last').text('Update failed!');
                        
                        // Fade out after longer delay for errors
                        setTimeout(function() {
                            $(updateNotice).fadeOut();
                        }, 3000);
                    }
                });
            }
        });

        // Add some additional visual enhancements
        itemList.find('li').hover(
            function() {
                $(this).addClass('hover');
            },
            function() {
                $(this).removeClass('hover');
            }
        );
    });

})(jQuery);
