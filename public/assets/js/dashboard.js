// In public/assets/js/dashboard.js
jQuery(document).ready(function($) {
    $('.qp-dashboard-wrapper').on('click', '.qp-delete-session-btn', function(e) {
        e.preventDefault();

        var button = $(this);
        var sessionID = button.data('session-id');
        var nonce = button.data('nonce');

        if (confirm('Are you sure you want to permanently delete this session history? This cannot be undone.')) {
            $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_user_session',
                    nonce: nonce,
                    session_id: sessionID
                },
                beforeSend: function() {
                    button.text('Deleting...');
                    button.prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        // Fade out and remove the table row for instant feedback
                        button.closest('tr').fadeOut(500, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error: ' + response.data.message);
                        button.text('Delete');
                        button.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('An unknown error occurred.');
                    button.text('Delete');
                    button.prop('disabled', false);
                }
            });
        }
    });
});