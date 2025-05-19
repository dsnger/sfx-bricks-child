jQuery(document).ready(function($){
    $('.sfx-logo-upload-btn').on('click', function(e){
        e.preventDefault();
        var button = $(this);
        var target = button.data('target');
        var card = button.closest('.sfx-card');
        var preview = card.find('.sfx-logo-preview');
        var removeBtn = card.find('.sfx-logo-remove-btn');
        
        var custom_uploader = wp.media({
            title: 'Select Logo',
            button: { text: 'Use this image' },
            multiple: false
        }).on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            $('#' + target).val(attachment.url);
            preview.attr('src', attachment.url).show();
            removeBtn.show();
        }).open();
    });

    $('.sfx-logo-remove-btn').on('click', function(e){
        e.preventDefault();
        var button = $(this);
        var target = button.data('target');
        var card = button.closest('.sfx-card');
        var preview = card.find('.sfx-logo-preview');
        
        $('#' + target).val('');
        preview.hide();
        button.hide();
    });
});
