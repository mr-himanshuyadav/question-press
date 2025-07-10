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


    // --- Logic for adding/removing question blocks (Unchanged) ---
    $('#add-new-question-block').on('click', function(e) {
        e.preventDefault();
        var newBlock = $('.qp-question-block:first').clone();
        newBlock.find('textarea, input[type="text"]').val('');
        newBlock.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);
        newBlock.find('input[type="radio"]:first').prop('checked', true);
        newBlock.find('.question-id-input').val('0');
        $('#qp-question-blocks-container').append(newBlock);
        reindexQuestionBlocks();
    });

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
            var baseName = 'questions[' + questionIndex + ']';
            $questionBlock.find('.question-id-input').attr('name', baseName + '[question_id]');
            $questionBlock.find('.question-text-area').attr('name', baseName + '[question_text]');
            $questionBlock.find('input[type="radio"]').attr('name', baseName + '[is_correct_option]');
            $questionBlock.find('.option-text-input').each(function() {
                $(this).attr('name', baseName + '[options][]');
            });
            $questionBlock.find('.label-checkbox').each(function() {
                $(this).attr('name', baseName + '[labels][]');
            });
        });
    }
});