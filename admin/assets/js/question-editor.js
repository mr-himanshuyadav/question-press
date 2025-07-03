jQuery(document).ready(function($) {
    // Add a new question block
    $('#add-new-question-block').on('click', function(e) {
        e.preventDefault();

        // Clone the last question block
        var newBlock = $('.qp-question-block:last').clone();

        // Clear the input values in the new block
        newBlock.find('textarea, input[type="text"]').val('');
        newBlock.find('input[type="checkbox"]').prop('checked', false);
        
        // Set the first radio button as checked by default
        newBlock.find('input[type="radio"]').first().prop('checked', true);

        // Append the new block to the container
        $('#qp-question-blocks-container').append(newBlock);

        // Re-index all question blocks to ensure form names are correct
        reindexQuestionBlocks();
    });

    // Remove a question block
    $('#qp-question-blocks-container').on('click', '.remove-question-block', function(e) {
        e.preventDefault();

        // Don't allow removing the very last block
        if ($('.qp-question-block').length > 1) {
            $(this).closest('.qp-question-block').remove();
            reindexQuestionBlocks();
        } else {
            alert('You must have at least one question.');
        }
    });

    // Function to re-index names and IDs of the form elements
    function reindexQuestionBlocks() {
        $('.qp-question-block').each(function(questionIndex, questionElement) {
            var $questionBlock = $(questionElement);
            
            // Update question text textarea name
            $questionBlock.find('.question-text-area').attr('name', 'questions[' + questionIndex + '][question_text]');

            // Update PYQ checkbox name
            $questionBlock.find('.is-pyq-checkbox').attr('name', 'questions[' + questionIndex + '][is_pyq]');
            
            // Update option inputs
            $questionBlock.find('.qp-option-row').each(function(optionIndex, optionElement) {
                var $optionRow = $(optionElement);
                
                // Update radio button name
                $optionRow.find('input[type="radio"]').attr('name', 'questions[' + questionIndex + '][is_correct_option]');
                
                // Update option text input name
                $optionRow.find('input[type="text"]').attr('name', 'questions[' + questionIndex + '][options][]');
            });
        });
    }
});