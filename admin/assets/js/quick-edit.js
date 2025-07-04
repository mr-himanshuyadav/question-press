// In admin/assets/js/quick-edit.js
jQuery(document).ready(function($) {
    // Use a delegated event handler for the quick edit link
    $('#the-list').on('click', '.editinline', function(e) {
        e.preventDefault();

        var questionId = $(this).data('question-id');
        var $row = $('#post-' + questionId);
        var $editRow = $('#edit-' + questionId);

        // Hide any other open quick-edit rows
        $('.quick-edit-row').hide();
        $('.inline-editor').empty(); // Clear content of other editors

        // Show the edit row and add a loading message
        $editRow.show();
        $editRow.find('.inline-edit-col').html('<p>Loading...</p>');

        // Fetch the editor form via AJAX
        $.ajax({
            url: ajaxurl, // ajaxurl is a global variable in the WP admin
            type: 'POST',
            data: {
                action: 'get_quick_edit_form',
                nonce: qp_quick_edit_object.nonce,
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
    $('#the-list').on('click', '.cancel', function(e) {
        e.preventDefault();
        $(this).closest('.quick-edit-row').hide();
    });
});