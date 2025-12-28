jQuery(document).ready(function($) {
    $('#qp-regenerate-api-key').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var nonce = button.data('nonce');

        Swal.fire({
            title: 'Regenerate Secret Key?',
            text: "This will invalidate any existing tokens and require the mobile app to be updated. This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, regenerate it!'
        }).then((result) => {
            if (result.isConfirmed) {
                button.text('Regenerating...').prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'regenerate_api_key',
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#qp-api-secret-key-field').val(response.data.new_key);
                            Swal.fire('Success!', 'New secret key generated successfully.', 'success');
                        } else {
                            Swal.fire('Error!', response.data.message || 'Could not regenerate the key.', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'An unknown server error occurred.', 'error');
                    },
                    complete: function() {
                        button.text('Regenerate Secret Key').prop('disabled', false);
                    }
                });
            }
        });
    });
    /**
     * APK/App File Uploader Logic
     */
    $('.qp-upload-file-btn').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var targetInput = $(button.data('target'));
        var custom_uploader;

        // If the uploader object has already been created, reopen the dialog
        if (custom_uploader) {
            custom_uploader.open();
            return;
        }

        // Extend the wp.media object
        custom_uploader = wp.media({
            title: 'Select App File',
            button: {
                text: 'Use this File'
            },
            multiple: false // Set to true to allow multiple files to be selected
        });

        // When a file is selected, grab the URL and set it as the text field's value
        custom_uploader.on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            targetInput.val(attachment.url);
        });

        // Open the uploader dialog
        custom_uploader.open();
    });
});