jQuery(document).ready(function($) {
    // Add a new question block
    $('#add-new-question-block').on('click', function(e) {
        e.preventDefault();

        // Clone the first question block as a template
        var newBlock = $('.qp-question-block:first').clone();

        // Clear all input values in the new block
        newBlock.find('textarea, input[type="text"]').val('');
        newBlock.find('input[type="checkbox"]').prop('checked', false);
        
        // Remove the hidden question_id so it's treated as a new question
        newBlock.find('.question-id-input').val('0');

        // Set the first radio button as checked by default
        newBlock.find('input[type="radio"]').first().prop('checked', true);

        // Append the new block to the container
        $('#qp-question-blocks-container').append(newBlock);

        reindexQuestionBlocks();
    });

    // Remove a question block
    $('#qp-question-blocks-container').on('click', '.remove-question-block', function(e) {
        e.preventDefault();

        if ($('.qp-question-block').length > 1) {
            $(this).closest('.qp-question-block').remove();
            reindexQuestionBlocks();
        } else {
            alert('You must have at least one question.');
        }
    });

    function reindexQuestionBlocks() {
        $('.qp-question-block').each(function(questionIndex, questionElement) {
            var $questionBlock = $(questionElement);
            
            // Update base name attribute for all fields in this block
            var baseName = 'questions[' + questionIndex + ']';

            $questionBlock.find('.question-id-input').attr('name', baseName + '[question_id]');
            $questionBlock.find('.question-text-area').attr('name', baseName + '[question_text]');
            $questionBlock.find('input[type="radio"]').attr('name', baseName + '[is_correct_option]');
            
            $questionBlock.find('.option-text-input').each(function(optionIndex, optionElement) {
                $(optionElement).attr('name', baseName + '[options][]');
            });

            $questionBlock.find('.label-checkbox').each(function(labelIndex, labelElement) {
                $(labelElement).attr('name', baseName + '[labels][]');
            });
        });
    }
});