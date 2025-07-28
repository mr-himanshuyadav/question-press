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

    // Add this new section for the update checker
    $('#qp-check-for-updates').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $message = $('#qp-update-message');

        $button.text('Checking...');
        $message.hide();

        $.post(ajaxurl, {
            action: 'qp_check_for_updates'
        }, function(response) {
            $button.text('Check for Updates');
            if (response.success) {
                $message.html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>').show();
            } else {
                $message.html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>').show();
            }
        });
    });
});