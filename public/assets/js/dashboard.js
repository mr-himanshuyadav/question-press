jQuery(document).ready(function($) {
    var wrapper = $('.qp-dashboard-wrapper');

    // Handler for deleting a single session row
    wrapper.on('click', '.qp-delete-session-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var sessionID = button.data('session-id');
        if (confirm('Are you sure you want to permanently delete this session history? This cannot be undone.')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'delete_user_session', nonce: qp_ajax_object.nonce, session_id: sessionID },
                beforeSend: function() { button.text('Deleting...').prop('disabled', true); },
                success: function(response) {
                    if (response.success) {
                        button.closest('tr').fadeOut(500, function() { $(this).remove(); });
                    } else {
                        alert('Error: ' + response.data.message);
                        button.text('Delete').prop('disabled', false);
                    }
                },
                error: function() { alert('An unknown error occurred.'); button.text('Delete').prop('disabled', false); }
            });
        }
    });

    // Handler for deleting all revision history
    wrapper.on('click', '#qp-delete-history-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        if (confirm('Are you sure you want to delete ALL of your practice session and revision history? This action cannot be undone.')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'delete_revision_history', nonce: qp_ajax_object.nonce },
                beforeSend: function() {
                    button.text('Deleting History...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        var tableBody = wrapper.find('.qp-dashboard-table tbody');
                        // Fade out the table body, empty it, add the success message, and fade it back in.
                        tableBody.fadeOut(400, function() {
                            $(this).empty().html('<tr><td colspan="8" style="text-align: center;">Your history has been cleared.</td></tr>').fadeIn(400);
                        });
                        // Update the button state to reflect the action.
                        button.text('History Deleted').css('opacity', 0.7);
                    } else {
                        alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
                        button.text('Delete All Revision History').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('An unknown server error occurred.');
                    button.text('Delete All Revision History').prop('disabled', false);
                }
            });
        }
    });
});
