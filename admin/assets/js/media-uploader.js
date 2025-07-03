jQuery(document).ready(function($) {
    var mediaUploader;

    $('#qp-upload-image-button').on('click', function(e) {
        e.preventDefault();
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Choose Direction Image',
            button: {
                text: 'Choose Image'
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#direction-image-id').val(attachment.id);
            $('#qp-direction-image-preview').html('<img src="' + attachment.url + '" style="max-width:100%; height:auto;">');
            $('#qp-remove-image-button').show();
        });

        mediaUploader.open();
    });

    $('#qp-remove-image-button').on('click', function(e) {
        e.preventDefault();
        $('#direction-image-id').val('');
        $('#qp-direction-image-preview').html('');
        $(this).hide();
    });
});