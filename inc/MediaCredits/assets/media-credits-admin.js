/* Media Credits — seal picker. One media frame per field, reused. */
(function ($) {
    'use strict';

    $(document).on('click', '.sfx-mc-seal-choose', function (event) {
        event.preventDefault();

        var $cell = $(this).closest('.sfx-mc-seal');
        var frame = $cell.data('frame');

        if (!frame) {
            frame = wp.media({
                title: (window.sfxMediaCredits && window.sfxMediaCredits.frameTitle) || 'Choose an image',
                button: { text: (window.sfxMediaCredits && window.sfxMediaCredits.frameButton) || 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var url = (attachment.sizes && attachment.sizes.thumbnail)
                    ? attachment.sizes.thumbnail.url
                    : attachment.url;

                $cell.find('.sfx-mc-seal-input').val(attachment.id);
                $cell.find('.sfx-mc-seal-preview').attr('src', url).show();
                $cell.find('.sfx-mc-seal-remove').show();
            });

            $cell.data('frame', frame);
        }

        frame.open();
    });

    // Without this a chosen seal could never be unchosen.
    $(document).on('click', '.sfx-mc-seal-remove', function (event) {
        event.preventDefault();

        var $cell = $(this).closest('.sfx-mc-seal');

        $cell.find('.sfx-mc-seal-input').val('0');
        $cell.find('.sfx-mc-seal-preview').attr('src', '').hide();
        $(this).hide();
    });
}(jQuery));
