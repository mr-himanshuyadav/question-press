jQuery(document).ready(function($) {
    var wrapper = $('#the-list'); // The table body

    // Show the quick edit form
    wrapper.on('click', '.editinline', function(e) {
        e.preventDefault();
        var questionId = $(this).data('question-id');
        var nonce = $(this).data('nonce');
        var $editRow = $('#edit-' + questionId);
        var $postRow = $('#post-' + questionId);

        $('.quick-edit-row').hide();
        $('tr.inline-editor').removeClass('inline-editor');
        
        $editRow.show();
        $postRow.addClass('inline-editor');
        $editRow.find('.inline-edit-col').html('<p>Loading...</p>');

        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'get_quick_edit_form',
                nonce: nonce,
                question_id: questionId
            },
            success: function(response) {
                if (response.success) {
                    $editRow.find('.inline-edit-col').html(response.data.form);
                } else {
                    $editRow.find('.inline-edit-col').html('<p style="color:red;">Could not load editor.</p>');
                }
            }
        });
    });

    // Handle cancelling the quick edit
    wrapper.on('click', '.cancel', function(e) {
        e.preventDefault();
        var $editRow = $(this).closest('.quick-edit-row');
        $editRow.hide();
        $editRow.prev('tr').removeClass('inline-editor');
        $editRow.find('.inline-edit-col').empty();
    });

    // Handle updating the quick edit form
    wrapper.on('click', '.save', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $formWrapper = $button.closest('.quick-edit-form-wrapper');
        var questionId = $formWrapper.find('input[name="question_id"]').val();
        var nonce = qp_quick_edit_object.save_nonce;

        $button.prop('disabled', true).text('Updating...');

        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'save_quick_edit_data',
                nonce: nonce,
                question_id: questionId, // Sending the ID explicitly to prevent errors
                form_data: $formWrapper.find(':input').serialize()
            },
            success: function(response) {
                if (response.success && response.data.row_html) {
                    $('#post-' + questionId).replaceWith(response.data.row_html);
                    $('#edit-' + questionId).hide().find('.inline-edit-col').empty();
                } else {
                    alert('Error: ' + (response.data.message || 'Could not save changes.'));
                    $button.prop('disabled', false).text('Update');
                }
            },
            error: function() {
                alert('An unknown server error occurred.');
                $button.prop('disabled', false).text('Update');
            }
        });
    });
});