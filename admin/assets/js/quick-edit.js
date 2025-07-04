jQuery(document).ready(function($) {
    var wrapper = $('#the-list'); // The table body

    // Show the quick edit form
    wrapper.on('click', '.editinline', function(e) {
        e.preventDefault();
        var questionId = $(this).data('question-id');
        var $editRow = $('#edit-' + questionId);

        $('.quick-edit-row').hide();
        $('.inline-editor').empty();
        
        $editRow.show();
        $editRow.find('.inline-edit-col').html('<p>Loading...</p>');

        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'get_quick_edit_form', nonce: qp_quick_edit_object.nonce, question_id: questionId },
            success: function(response) {
                if (response.success) {
                    $editRow.find('.inline-edit-col').html(response.data.form);
                } else {
                    $editRow.find('.inline-edit_col').html('<p style="color:red;">Could not load editor.</p>');
                }
            }
        });
    });

    // Handle cancelling the quick edit
    wrapper.on('click', '.cancel', function(e) {
        e.preventDefault();
        $(this).closest('.quick-edit-row').hide();
        $(this).closest('.inline-editor').empty();
    });

    // NEW: Handle updating the quick edit form
    wrapper.on('click', '.save', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $form = $button.closest('form');
        var questionId = $form.find('input[name="question_id"]').val();

        $button.prop('disabled', true).text('Updating...');

        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'save_quick_edit_data',
                nonce: qp_quick_edit_object.nonce,
                form_data: $form.serialize() // Send all form data
            },
            success: function(response) {
                if (response.success) {
                    // Replace the original row's content with the updated view
                    $('#post-' + questionId).replaceWith(response.data.row_html);
                    // Hide the editor
                    $('#edit-' + questionId).hide().find('.inline-edit-col').empty();
                } else {
                    alert('Error: Could not save changes.');
                    $button.prop('disabled', false).text('Update');
                }
            }
        });
    });
});