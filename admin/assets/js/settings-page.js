jQuery(document).ready(function($) {
    $('#qp-regenerate-api-key').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to regenerate the secret key? This will invalidate any existing tokens and require the mobile app to be updated.')) {
            return;
        }

        var button = $(this);
        var nonce = button.data('nonce');

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
                    alert('New secret key generated successfully.');
                } else {
                    alert('Error: ' + response.data.message);
                }
                button.text('Regenerate Secret Key').prop('disabled', false);
            },
            error: function() {
                alert('An unknown server error occurred.');
                button.text('Regenerate Secret Key').prop('disabled', false);
            }
        });
    });
});