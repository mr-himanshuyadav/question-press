jQuery(document).ready(function($) {
    var wrapper = $('#the-list'); // The table body

    // UPDATED: Show/Hide the quick edit form (Toggle Behavior)
    wrapper.on('click', '.editinline', function(e) {
        e.preventDefault();
        var questionId = $(this).data('question-id');
        var nonce = $(this).data('nonce');
        var $editRow = $('#edit-' + questionId);
        var $postRow = $('#post-' + questionId);

        // Check if the form for this row is already open
        if ($editRow.is(':visible')) {
            $editRow.hide();
            $postRow.removeClass('inline-editor');
            $editRow.find('.inline-edit-col').empty();
            return; // Exit the function
        }

        // Hide any other open quick edit rows before opening a new one
        $('.quick-edit-row:visible').each(function() {
            $(this).hide();
            $(this).prev('tr').removeClass('inline-editor');
            $(this).find('.inline-edit-col').empty();
        });
        
        // Show the current form
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
                    // Manually trigger the change event to populate topics on load
                    $editRow.find('.qe-subject-select').trigger('change');
                } else {
                    $editRow.find('.inline-edit-col').html('<p style="color:red;">Could not load editor.</p>');
                }
            }
        });
    });

    // Handle the dynamic topic dropdown
    wrapper.on('change', '.qe-subject-select', function() {
        var subjectSelect = $(this);
        var topicSelect = subjectSelect.closest('.form-row-flex').find('.qe-topic-select');
        var selectedSubjectId = subjectSelect.val();

        topicSelect.empty();

        // Use the topic data that was loaded with the form
        if (typeof qp_quick_edit_topics_data !== 'undefined' && selectedSubjectId && qp_quick_edit_topics_data[selectedSubjectId]) {
            topicSelect.prop('disabled', false);
            topicSelect.append('<option value="">— Select a Topic —</option>');

            $.each(qp_quick_edit_topics_data[selectedSubjectId], function(index, topic) {
                var option = $('<option></option>').val(topic.id).text(topic.name);
                // Use qp_current_topic_id which was set when the form was loaded
                if (typeof qp_current_topic_id !== 'undefined' && topic.id == qp_current_topic_id) {
                    option.prop('selected', true);
                }
                topicSelect.append(option);
            });
        } else {
            topicSelect.prop('disabled', true);
            topicSelect.append('<option value="">— No topics —</option>');
        }
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
        
        $button.prop('disabled', true).text('Updating...');

        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'save_quick_edit_data',
                nonce: qp_quick_edit_object.save_nonce, // RESTORED: Use 'nonce' as the key
                question_id: questionId,
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