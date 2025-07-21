jQuery(document).ready(function($) {
    var subjectSelect = $('#subject_id');
    var topicSelect = $('#topic_id');
    var sourceSelect = $('#source_id');
    var sectionSelect = $('#section_id');
    var isPyqCheckbox = $('#is_pyq_checkbox');
    var pyqFieldsWrapper = $('#pyq_fields_wrapper');

    // --- Function to update Topic dropdown based on Subject ---
    function updateTopics() {
        var selectedSubjectId = subjectSelect.val();
        var currentTopicId = qp_editor_data.current_topic_id;
        
        topicSelect.empty().prop('disabled', true);

        if (selectedSubjectId && qp_editor_data.topics_by_subject[selectedSubjectId]) {
            topicSelect.prop('disabled', false).append('<option value="">— Select a Topic —</option>');
            $.each(qp_editor_data.topics_by_subject[selectedSubjectId], function(index, topic) {
                var option = $('<option></option>').val(topic.id).text(topic.name);
                if (topic.id == currentTopicId) {
                    option.prop('selected', true);
                }
                topicSelect.append(option);
            });
        } else {
            topicSelect.append('<option value="">— No topics for this subject —</option>');
        }
    }

    // --- Function to update Section dropdown based on Source ---
    function updateSections() {
        var selectedSourceId = sourceSelect.val();
        var currentSectionId = qp_editor_data.current_section_id;

        sectionSelect.empty().prop('disabled', true);

        if (selectedSourceId && qp_editor_data.sections_by_source[selectedSourceId]) {
            sectionSelect.prop('disabled', false).append('<option value="">— Select a Section —</option>');
            $.each(qp_editor_data.sections_by_source[selectedSourceId], function(index, section) {
                var option = $('<option></option>').val(section.id).text(section.name);
                if (section.id == currentSectionId) {
                    option.prop('selected', true);
                }
                sectionSelect.append(option);
            });
        } else {
            sectionSelect.append('<option value="">— No sections for this source —</option>');
        }
    }

    // --- Function to toggle PYQ fields ---
    function togglePyqFields() {
        if (isPyqCheckbox.is(':checked')) {
            pyqFieldsWrapper.slideDown();
        } else {
            pyqFieldsWrapper.slideUp();
        }
    }

    // --- Bind Event Handlers ---
    subjectSelect.on('change', function() {
        qp_editor_data.current_topic_id = ''; 
        updateTopics();
    });

    sourceSelect.on('change', function() {
        qp_editor_data.current_section_id = '';
        updateSections();
    });

    isPyqCheckbox.on('change', togglePyqFields);

    // --- Initial population on page load ---
    updateTopics();
    updateSections();
    togglePyqFields();


    // --- Logic for adding/removing question blocks ---
    $('#add-new-question-block').on('click', function(e) {
        e.preventDefault();

        // --- FIX START: Remember the original correct answer ---
        var firstBlock = $('.qp-question-block:first');
        var originalCorrectOptionValue = firstBlock.find('input[name="questions[0][correct_option_id]"]:checked').val();
        // --- FIX END ---

        var newBlock = firstBlock.clone().removeClass('status-publish status-draft').addClass('status-new');

        // --- FIX: Clean up the cloned editor before appending ---
        var editorWrapper = newBlock.find('.wp-editor-wrap');
        var textarea = newBlock.find('textarea.wp-editor-area');
        var oldEditorId = textarea.attr('id');

        // Destroy the cloned tinymce instance
        if (tinymce.get(oldEditorId)) {
            tinymce.get(oldEditorId).remove();
        }
        
        // Remove the HTML elements created by TinyMCE
        editorWrapper.find('.mce-container').remove();
        editorWrapper.removeClass('tmce-active').addClass('html-active');
        textarea.show();

        // Reset values
        newBlock.find('textarea, input[type="text"]').val('');
        newBlock.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);
        newBlock.find('input[type="radio"]:first').prop('checked', true);
        newBlock.find('.question-id-input').val('0');
        newBlock.find('.hndle span').html('<span>Question (ID: New - Save group to add options)</span>');

        // Hide the options and labels for this new, unsaved block
        newBlock.find('.qp-options-and-labels-wrapper').hide();

        $('#qp-question-blocks-container').append(newBlock);
        reindexQuestionBlocks();

        // --- FIX START: Restore the original correct answer ---
        if (originalCorrectOptionValue) {
            firstBlock.find('input[name="questions[0][correct_option_id]"][value="' + originalCorrectOptionValue + '"]').prop('checked', true);
        }
    });

    $('#qp-question-blocks-container').on('click', '.remove-question-block', function(e) {
        e.preventDefault();
        if ($('.qp-question-block').length > 1) {
            var blockToRemove = $(this).closest('.qp-question-block');
            var editorId = blockToRemove.find('textarea.wp-editor-area').attr('id');

            // --- FIX: Properly remove the editor instance before removing the element ---
            if (tinymce.get(editorId)) {
                tinymce.get(editorId).remove();
            }
            blockToRemove.remove();
            reindexQuestionBlocks();
        } else {
            alert('You must have at least one question.');
        }
    });

    function reindexQuestionBlocks() {
        $('.qp-question-block').each(function(questionIndex, questionElement) {
            var $questionBlock = $(questionElement);
            var baseName = 'questions[' + questionIndex + ']';
            
            // --- FIX: Update all names, IDs, and for attributes ---
            $questionBlock.find('[name^="questions["]').each(function() {
                var currentName = $(this).attr('name');
                if (currentName) {
                    var newName = currentName.replace(/questions\[\d+\]/, baseName);
                    $(this).attr('name', newName);
                }
            });

            // Re-index editor related elements
            var $editorWrapper = $questionBlock.find('.wp-editor-wrap');
            var $textarea = $questionBlock.find('textarea.wp-editor-area');
            var newEditorId = 'question_text_editor_' + questionIndex;

            // Only proceed if the ID needs changing (i.e., it's a new or re-indexed block)
            if ($textarea.length > 0 && $textarea.attr('id') !== newEditorId) {
                // Update wrapper and textarea IDs
                $editorWrapper.attr('id', 'wp-' + newEditorId + '-wrap');
                $textarea.attr('id', newEditorId);

                // Update quicktags toolbar ID
                $editorWrapper.find('.quicktags-toolbar').attr('id', 'qt_' + newEditorId + '_toolbar');

                // Update editor switch button IDs and data attributes
                $editorWrapper.find('.wp-switch-editor').each(function() {
                    var mode = $(this).hasClass('switch-tmce') ? 'tmce' : 'html';
                    $(this).attr('id', newEditorId + '-' + mode);
                    $(this).attr('data-editor', newEditorId);
                });

                // Re-initialize the editor using the WordPress API
                if (typeof wp.editor.initialize === 'function') {
                     wp.editor.initialize(newEditorId, {
                        tinymce: true,
                        quicktags: true
                    });
                }
            }
        });
    }

    // --- NEW: SweetAlert Validation on Submit ---
    $('form[method="post"]').on('submit', function(e) {
        // Only run this validation if the options section is visible on the page.
        if ($('.qp-option-row').length === 0) {
            return; // This is Step 1, so we allow submission without validation.
        }

        var allQuestionsValid = true;
        
        $('.qp-question-block').each(function() {
            var $block = $(this);
            var questionIndex = $block.index('.qp-question-block'); // Get the index of the block
            
            // Construct the name attribute for the radio buttons in this block
            var radioName = 'questions[' + questionIndex + '][correct_option_id]';
            
            // Check if any radio button with that name is checked
            if ($('input[name="' + radioName + '"]:checked').length === 0) {
                allQuestionsValid = false;
                // Add a red border to highlight the invalid question block
                $block.css('border', '2px solid #d33'); 
            } else {
                // Remove the border if it was previously invalid but is now fixed
                $block.css('border', ''); 
            }
        });

        // If any question block is invalid, prevent form submission and show an alert.
        if (!allQuestionsValid) {
            e.preventDefault(); 
            Swal.fire({
                title: 'Incomplete Options',
                text: 'You must select a correct answer for every question before saving the group.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });
});