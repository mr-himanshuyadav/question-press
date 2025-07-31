jQuery(document).ready(function($) {
    var subjectSelect = $('#subject_id');
    var topicSelect = $('#topic_id');
    var sourceSelect = $('#source_id');
    var sectionSelect = $('#section_id');
    var isPyqCheckbox = $('#is_pyq_checkbox');
    var pyqFieldsWrapper = $('#pyq_fields_wrapper');
    let initialFormState = {};

    function getCurrentState() {
        var questions = [];
        $('.qp-question-block').each(function() {
            var $block = $(this);
            var editorId = $block.find('textarea.wp-editor-area').attr('id');
            var questionText = (typeof tinymce !== "undefined" && tinymce.get(editorId)) ? tinymce.get(editorId).getContent() : '';

            var options = [];
            $block.find('.qp-option-row').each(function() {
                var $row = $(this);
                options.push({
                    id: $row.find('input[name$="[option_ids][]"]').val(),
                    text: $row.find('.option-text-input').val()
                });
            });

            var labels = [];
            $block.find('.label-checkbox:checked').each(function() {
                labels.push($(this).val());
            });

            questions.push({
                id: $block.find('.question-id-input').val(),
                text: questionText,
                options: options,
                correctOptionId: $block.find('input[name$="[correct_option_id]"]:checked').val() || null,
                labels: labels
            });
        });
        
        var directionEditor = (typeof tinymce !== "undefined") ? tinymce.get('direction_text_editor') : null;

        return {
            groupId: $('input[name="group_id"]').val(),
            subjectId: $('#subject_id').val(),
            topicId: $('#topic_id').val(),
            sourceId: $('#source_id').val(),
            sectionId: $('#section_id').val(),
            isPyq: $('#is_pyq_checkbox').is(':checked'),
            examId: $('#exam_id').val(),
            pyqYear: $('input[name="pyq_year"]').val(),
            directionText: directionEditor ? directionEditor.getContent() : '',
            directionImageId: $('#direction-image-id').val(),
            questions: questions
        };
    }

    // Use $(window).on('load') to ensure all scripts, including TinyMCE, are fully loaded.
    $(window).on('load', function() {
        initialFormState = getCurrentState();
    });

    // --- Initial population on page load ---
    updateTopics();
    updateSources();
    updateSections();
    togglePyqFields();

    // --- Function to update Topic dropdown based on Subject ---
    function updateTopics() {
        var selectedSubjectId = subjectSelect.val();
        // Capture the currently selected topic before clearing
        var previouslySelectedTopicId = topicSelect.val();
        
        topicSelect.empty().prop('disabled', true);

        if (selectedSubjectId && qp_editor_data.topics_by_subject[selectedSubjectId]) {
            topicSelect.prop('disabled', false).append('<option value="">— Select a Topic —</option>');
            $.each(qp_editor_data.topics_by_subject[selectedSubjectId], function(index, topic) {
                var option = $('<option></option>').val(topic.id).text(topic.name);
                // Prioritize previously selected value, then initial load value
                if (topic.id == previouslySelectedTopicId || topic.id == qp_editor_data.current_topic_id) {
                    option.prop('selected', true);
                }
                topicSelect.append(option);
            });
            // After initial load, clear current_topic_id to prevent interference
            qp_editor_data.current_topic_id = '';
        } else {
            topicSelect.append('<option value="">— No topics for this subject —</option>');
        }
    }

function updateSources() {
    var selectedSubjectId = subjectSelect.val();
    var previouslySelectedSourceId = sourceSelect.val() || qp_editor_data.current_source_id;

    sourceSelect.empty().prop('disabled', true);

    var addedSourceIds = {};

    if (selectedSubjectId && qp_editor_data.sources_by_subject[selectedSubjectId]) {
        sourceSelect.prop('disabled', false).append('<option value="">— Select a Source —</option>');
        $.each(qp_editor_data.sources_by_subject[selectedSubjectId], function(index, source) {
            var option = $('<option></option>').val(source.id).text(source.name);
            if (source.id == previouslySelectedSourceId) {
                option.prop('selected', true);
            }
            sourceSelect.append(option);
            addedSourceIds[source.id] = true;
        });
    } else {
        sourceSelect.append('<option value="">— No sources for this subject —</option>');
    }

    // --- Always add the current source if not present ---
    if (
        previouslySelectedSourceId &&
        !addedSourceIds[previouslySelectedSourceId] &&
        qp_editor_data.all_source_terms
    ) {
        var found = qp_editor_data.all_source_terms.find(function(term) {
            return term.id == previouslySelectedSourceId;
        });
        if (found) {
            var option = $('<option></option>').val(found.id).text(found.name).prop('selected', true);
            sourceSelect.append(option);
        }
    }

    sourceSelect.trigger('change');
    qp_editor_data.current_source_id = '';
}


    // --- Function to update Section dropdown based on Source ---
    function updateSections() {
        var selectedSourceId = sourceSelect.val();
        // Use the saved value from the initial page load if it exists
        var previouslySelectedSectionId = sectionSelect.val() || qp_editor_data.current_section_id;

        sectionSelect.empty().prop('disabled', true);

        // Helper function to recursively build the dropdown options
        function buildTermHierarchy(parentElement, terms, parentId, level, selectedId) {
            var prefix = '— '.repeat(level);
            terms.forEach(function(term) {
                if (term.parent_id == parentId) {
                    var option = $('<option></option>')
                        .val(term.id)
                        .text(prefix + term.name);
                    
                    // Set the 'selected' property if this term matches the one to be selected
                    if (term.id == selectedId) {
                        option.prop('selected', true);
                    }
                    parentElement.append(option);
                    // Recursive call for children of the current term
                    buildTermHierarchy(parentElement, terms, term.id, level + 1, selectedId);
                }
            });
        }

        if (selectedSourceId && qp_editor_data.all_source_terms) {
            sectionSelect.prop('disabled', false).append('<option value="">— Select a Section —</option>');
            // Start the recursive function to build the hierarchy under the selected source
            buildTermHierarchy(sectionSelect, qp_editor_data.all_source_terms, selectedSourceId, 0, previouslySelectedSectionId);
            
            // Clear the initial value after it has been used to prevent conflicts
            qp_editor_data.current_section_id = '';
        } else {
            sectionSelect.append('<option value="">— Select a source first —</option>');
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
        updateTopics();
        updateSources();
    });

    sourceSelect.on('change', function() {
        updateSections();
    });

    isPyqCheckbox.on('change', togglePyqFields);


    // --- Logic for adding/removing question blocks ---
    $('#add-new-question-block').on('click', function(e) {
        e.preventDefault();

        // --- FIX START: Remember the original correct answer ---
        var firstBlock = $('.qp-question-block:first');
        var originalCorrectOptionValue = firstBlock.find('input[name="questions[0][correct_option_id]"]:checked').val();
        // --- FIX END ---

        var newBlock = firstBlock.clone().removeClass('status-publish status-draft status-reported').addClass('status-new');

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
        newBlock.find('textarea.wp-editor-area').val(''); // Clear only the main editor textarea
        newBlock.find('input[name$="[question_number_in_section]"]').val(''); // Specifically clear the Q.No input
        newBlock.find('input[type="checkbox"], input[type="radio"]').prop('checked', false);
        newBlock.find('input[type="radio"]:first').prop('checked', true);
        newBlock.find('.question-id-input').val('0');
        newBlock.find('.qp-question-title').text('Question (ID: New - Save group to add options)'); 

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

    function analyzeChanges(initialState, currentState) {
        let stats = {
            added: 0,
            deleted: 0,
            updated: 0,
            promotedToPublish: 0,
            remainsDraft: 0,
            hasChanges: false
        };

        // Use stringify for a simple, top-level check first.
        if (JSON.stringify(initialState) !== JSON.stringify(currentState)) {
            stats.hasChanges = true;
        }

        const initialQuestionIds = initialState.questions.map(q => q.id);
        const currentQuestionIds = currentState.questions.map(q => q.id);

        stats.added = currentQuestionIds.filter(id => id.toString().startsWith('0')).length;
        stats.deleted = initialQuestionIds.filter(id => id !== '0' && !currentQuestionIds.includes(id)).length;

        currentState.questions.forEach(currentQ => {
            if (currentQ.id === '0') return; // Already counted in 'added'

            const initialQ = initialState.questions.find(q => q.id === currentQ.id);
            if (!initialQ) return; // Should not happen, but for safety

            // Check for updates
            if (JSON.stringify(initialQ) !== JSON.stringify(currentQ)) {
                stats.updated++;
            }

            // Check for status changes
            const wasDraft = !initialQ.correctOptionId;
            const isPublished = !!currentQ.correctOptionId;

            if (wasDraft && isPublished) {
                stats.promotedToPublish++;
            }
        });

        // Count how many will remain as draft
        stats.remainsDraft = currentState.questions.filter(q => !q.correctOptionId).length;
        
        // Final check if any stat is non-zero
        if (stats.added || stats.deleted || stats.updated) {
            stats.hasChanges = true;
        }

        return stats;
    }

    // --- NEW: AJAX-powered Save Logic ---
    $('#qp-save-group-btn').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $form = $('form[method="post"]');

        // We still need to get current state to analyze changes
        var currentState = getCurrentState();
        var changes = analyzeChanges(initialFormState, currentState);

        if (!changes.hasChanges) {
            Swal.fire({
                title: 'No Changes Detected',
                text: "The analysis didn't find any changes. This can sometimes happen if you only edited text. Would you like to save the form anyway?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, Save Anyway',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // This reuses the same logic from the main confirmation modal's preConfirm block
                    Swal.fire({
                        title: 'Saving...',
                        text: 'Your changes are being saved.',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                            if (typeof tinymce !== "undefined") {
                                tinymce.triggerSave();
                            }
                            $.ajax({
                                url: $form.attr('action') || window.location.href,
                                type: 'POST',
                                data: $form.serialize() + '&save_group=1',
                            })
                            .done(function() {
                                Swal.fire({
                                    title: 'Success!',
                                    text: 'Your changes have been saved.',
                                    icon: 'success',
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    location.reload();
                                });
                            })
                            .fail(function(jqXHR, textStatus, errorThrown) {
                                Swal.fire('Save Failed', `Request failed: ${errorThrown}`, 'error');
                            });
                        }
                    });
                }
            });
            return; // Stop execution to prevent the main confirmation modal from showing
        }

        let changesHtml = '<div style="text-align: left; display: inline-block;"><ul>';
        if (changes.added > 0) changesHtml += `<li><strong>New Questions:</strong> ${changes.added} will be added.</li>`;
        if (changes.deleted > 0) changesHtml += `<li><strong>Deleted Questions:</strong> ${changes.deleted} will be removed.</li>`;
        if (changes.updated > 0) changesHtml += `<li><strong>Updated Questions:</strong> ${changes.updated} have been modified.</li>`;
        if (changes.promotedToPublish > 0) changesHtml += `<li><strong>Status Change:</strong> ${changes.promotedToPublish} question(s) will be published.</li>`;
        if (changes.remainsDraft > 0) changesHtml += `<li><strong>Draft Status:</strong> ${changes.remainsDraft} question(s) will remain as drafts.</li>`;
        changesHtml += '</ul></div>';

        Swal.fire({
            title: 'Confirm Your Changes',
            html: changesHtml,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, save changes!',
            showLoaderOnConfirm: true, // <-- Add loader
            preConfirm: () => {
                // Trigger an update on all TinyMCE editors before serializing the form
                if (typeof tinymce !== "undefined") {
                    tinymce.triggerSave();
                }
                return $.ajax({
                    url: $form.attr('action') || window.location.href,
                    type: 'POST',
                    data: $form.serialize() + '&save_group=1', // Add the save_group flag
                    // We don't need success/error here, preConfirm handles it
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    Swal.showValidationMessage(`Request failed: ${errorThrown}`);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Success!',
                    text: 'Your changes have been saved.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    // Reload the page to see the changes
                    location.reload();
                });
            }
        });
    });

    $(window).on('load', function() {
        $('.qp-editor-labels-container').each(function() {
            var $labelsContainer = $(this);
            var editorId = $labelsContainer.data('editor-id');
            var $editorWrapper = $('#wp-' + editorId + '-wrap');
            var $toolbar = $editorWrapper.find('.wp-editor-tools');

            if ($toolbar.length > 0) {
                // Prepend the labels to the toolbar area
                $toolbar.prepend($labelsContainer);
            }
        });
    });

    // --- Add New Option Logic ---
    $('#qp-question-blocks-container').on('click', '.add-new-option-btn', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $questionBlock = $button.closest('.qp-question-block');
        var $optionsContainer = $questionBlock.find('.qp-options-grid-container');
        var optionCount = $optionsContainer.find('.qp-option-row').length;

        if (optionCount < 6) {
            var questionIndex = $questionBlock.index();
            var newOptionIndex = optionCount;

            // Create the new option row from a string template
            var newOptionHtml = `
                <div class="qp-option-row">
                    <input type="radio" name="questions[${questionIndex}][correct_option_id]" value="new_${newOptionIndex}">
                    <input type="hidden" name="questions[${questionIndex}][option_ids][]" value="0">
                    <input type="text" name="questions[${questionIndex}][options][]" class="option-text-input" placeholder="Option ${newOptionIndex + 1}">
                </div>
            `;

            $optionsContainer.append(newOptionHtml);

            // Hide the button if we've reached the max limit of 6
            if (optionCount + 1 >= 6) {
                $button.hide();
            }
        }
    });
    // --- Collapsible Question Block Logic ---
    $('#qp-question-blocks-container').on('click', '.qp-toggle-question-block', function() {
        var $button = $(this);
        var $block = $button.closest('.qp-question-block');
        var $content = $block.find('.inside');

        $content.slideToggle(200);
        $button.toggleClass('is-closed');
    });

    // --- Custom Dropdown Logic for Labels ---
    var $container = $('#qp-question-blocks-container');

    // Toggle dropdown panel
    $container.on('click', '.qp-dropdown-toggle', function(e) {
        e.stopPropagation();
        // Close other dropdowns first
        $('.qp-dropdown-panel').not($(this).next()).hide();
        $(this).next('.qp-dropdown-panel').toggle();
    });

    // Stop propagation inside the panel
    $container.on('click', '.qp-dropdown-panel', function(e) {
        e.stopPropagation();
    });

    // Update button text on change
    $container.on('change', '.qp-dropdown-panel input[type="checkbox"]', function() {
        var $panel = $(this).closest('.qp-dropdown-panel');
        var $buttonSpan = $panel.prev('.qp-dropdown-toggle').find('span:first-child');
        var count = $panel.find('input:checked').length;

        if (count > 0) {
            $buttonSpan.text(count + ' Label(s)');
        } else {
            $buttonSpan.text('Select Labels');
        }
    });

    // Close dropdowns when clicking outside
    $(document).on('click', function() {
        $('.qp-dropdown-panel').hide();
    });
});