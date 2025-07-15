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
                nonce: qp_quick_edit_object.save_nonce,
                question_id: questionId,
                form_data: $formWrapper.find(':input').serialize()
            },
            success: function(response) {
                if (response.success && response.data.row_html) {
                    // --- THIS IS THE CORRECTED LOGIC ---
                    var $postRow = $('#post-' + questionId);
                    var $editRow = $postRow.next('tr.quick-edit-row');

                    // First, hide the editor form and remove its content
                    $editRow.hide().find('.inline-edit-col').empty();
                    
                    // Then, replace the old row with the new one
                    $postRow.replaceWith(response.data.row_html);
                    
                    // **CRUCIAL FIX**: After replacing, we must find the NEW row in the DOM
                    // and remove the class from it to collapse the space.
                    $('#post-' + questionId).removeClass('inline-editor');
                    // --- END OF FIX ---

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

    // --- NEW: Logic for Dynamic Bulk Edit Dropdowns ---
    var $subjectFilter = $('#qp_filter_by_subject');
    var $sourceBulkEdit = $('#bulk_edit_source');
    var $sectionBulkEdit = $('#bulk_edit_section');

    // Keep a copy of the original options
    var allSources = $sourceBulkEdit.html();
    var allSections = $sectionBulkEdit.html();


    






    // --- NEW: LOGIC FOR ADVANCED ADMIN FILTERS ---
    var $subjectFilter = $('#qp_filter_by_subject');
    var $topicFilter = $('#qp_filter_by_topic');
    var $sourceFilter = $('#qp_filter_by_source_section');
    var urlParams = new URLSearchParams(window.location.search);
    var currentTopic = urlParams.get('filter_by_topic');
    var currentSource = urlParams.get('filter_by_source');

    // Show/hide topic filter based on subject selection
    $subjectFilter.on('change', function() {
        var subjectId = $(this).val();
        $topicFilter.hide().val('');
        $sourceFilter.hide().val('');

        if (subjectId) {
            $.ajax({
                url: qp_admin_filter_data.ajax_url,
                type: 'POST',
                data: { action: 'get_topics_for_list_table_filter', nonce: qp_admin_filter_data.nonce, subject_id: subjectId },
                success: function(response) {
                    if (response.success && response.data.topics.length > 0) {
                        $topicFilter.empty().append('<option value="">All Topics</option>');
                        $.each(response.data.topics, function(index, topic) {
                            var $option = $('<option></option>').val(topic.topic_id).text(topic.topic_name);
                            if (topic.topic_id == currentTopic) {
                                $option.prop('selected', true);
                            }
                            $topicFilter.append($option);
                        });
                        $topicFilter.show();
                        if (currentTopic) {
                            $topicFilter.trigger('change');
                        }
                    }
                }
            });
        }
    }).trigger('change');

    // Show/hide source/section filter based on topic selection
    $topicFilter.on('change', function() {
        var topicId = $(this).val();
        var subjectId = $subjectFilter.val();
        $sourceFilter.hide().val('');

        if (topicId) {
            $.ajax({
                url: qp_admin_filter_data.ajax_url,
                type: 'POST',
                data: { action: 'get_sources_for_list_table_filter', nonce: qp_admin_filter_data.nonce, subject_id: subjectId, topic_id: topicId },
                success: function(response) {
                    if (response.success && response.data.sources.length > 0) {
                        $sourceFilter.empty().append('<option value="">All Sources / Sections</option>');
                        $.each(response.data.sources, function(index, source) {
                            var sourceOption = $('<option></option>').val('source_' + source.source_id).text(source.source_name);
                            if ('source_' + source.source_id == currentSource) {
                                sourceOption.prop('selected', true);
                            }
                            $sourceFilter.append(sourceOption);
                            if (source.sections && Object.keys(source.sections).length > 0) {
                                $.each(source.sections, function(idx, section) {
                                    var sectionOption = $('<option></option>').val('section_' + section.section_id).text('  - ' + section.section_name);
                                    if ('section_' + section.section_id == currentSource) {
                                        sectionOption.prop('selected', true);
                                    }
                                    $sourceFilter.append(sectionOption);
                                });
                            }
                        });
                        $sourceFilter.show();
                    }
                }
            });
        }
    });


    // --- NEW LOGIC FOR ALL DYNAMIC DROPDOWNS IN QUICK EDIT ---

    // Function to update a dropdown's options
    function updateDropdown(selectElement, data, currentId, placeholder) {
        selectElement.empty().prop('disabled', true);
        if (data && data.length > 0) {
            selectElement.prop('disabled', false);
            selectElement.append($('<option></option>').val('').text(placeholder));
            $.each(data, function(index, item) {
                var option = $('<option></option>').val(item.id).text(item.name);
                if (item.id == currentId) {
                    option.prop('selected', true);
                }
                selectElement.append(option);
            });
        } else {
            selectElement.append($('<option></option>').val('').text('— None available —'));
        }
    }

    // Main event handler for subject changes
    wrapper.on('change', '.qe-subject-select', function() {
        var subjectId = $(this).val();
        var $form = $(this).closest('.quick-edit-form-wrapper');
        var $topicSelect = $form.find('.qe-topic-select');
        var $sourceSelect = $form.find('.qe-source-select');
        var $examSelect = $form.find('.qe-exam-select');

        // Update Topics
        updateDropdown($topicSelect, qp_quick_edit_data.topics_by_subject[subjectId], qp_quick_edit_data.current_topic_id, '— Select a Topic —');

        // Update Sources and trigger a change to update sections
        updateDropdown($sourceSelect, qp_quick_edit_data.sources_by_subject[subjectId], qp_quick_edit_data.current_source_id, '— Select a Source —');
        $sourceSelect.trigger('change');

        // **THE FIX IS HERE**: Filter exams and map properties to id/name for the updateDropdown function
        var linkedExamIds = qp_quick_edit_data.exam_subject_links
            .filter(function(link) { return link.subject_id == subjectId; })
            .map(function(link) { return link.exam_id; });

        var availableExams = qp_quick_edit_data.all_exams
            .filter(function(exam) { return linkedExamIds.includes(exam.exam_id); })
            .map(function(exam) {
                // Remap properties to match what updateDropdown expects
                return { id: exam.exam_id, name: exam.exam_name };
            });

        updateDropdown($examSelect, availableExams, qp_quick_edit_data.current_exam_id, '— Select an Exam —');
    });

    // Event handler for source changes
    wrapper.on('change', '.qe-source-select', function() {
        var sourceId = $(this).val();
        var $form = $(this).closest('.quick-edit-form-wrapper');
        var $sectionSelect = $form.find('.qe-section-select');
        updateDropdown($sectionSelect, qp_quick_edit_data.sections_by_source[sourceId], qp_quick_edit_data.current_section_id, '— Select a Section —');
    });

    // Event handler for the PYQ checkbox
    wrapper.on('change', '.qe-is-pyq-checkbox', function() {
        var $pyqFields = $(this).closest('.qe-pyq-fields-wrapper').find('.qe-pyq-fields');
        if ($(this).is(':checked')) {
            $pyqFields.slideDown();
        } else {
            $pyqFields.slideUp();
        }
    });

    // In admin/assets/js/quick-edit.js

    // --- FINAL, STATE-AWARE LOGIC FOR DYNAMIC BULK EDIT PANEL ---
    $(function() {
        var $subjectFilter = $('#qp_filter_by_subject');
        var $bulkEditPanel = $('#qp-bulk-edit-panel');

        // Get the initial subject filter value from the URL on page load
        var initialSubject = new URLSearchParams(window.location.search).get('filter_by_subject');

        function manageBulkEditPanel() {
            var currentSubject = $subjectFilter.val();

            // The panel should only be visible if a subject is selected AND it matches the initial page filter.
            if (currentSubject && currentSubject === initialSubject) {
                // --- Populate all dropdowns within the panel ---
                var $sourceBulkEdit = $('#bulk_edit_source');
                var $sectionBulkEdit = $('#bulk_edit_section');
                var $topicBulkEdit = $('#bulk_edit_topic');
                var $examBulkEdit = $('#bulk_edit_exam');

                var availableTopics = qp_bulk_edit_data.topics.filter(function(t) { return t.subject_id == currentSubject; });
                var availableSources = qp_bulk_edit_data.sources.filter(function(s) { return s.subject_id == currentSubject; });
                var linkedExamIds = qp_bulk_edit_data.exam_subject_links.filter(function(l) { return l.subject_id == currentSubject; }).map(function(l) { return l.exam_id; });
                var availableExams = qp_bulk_edit_data.exams.filter(function(e) { return linkedExamIds.includes(e.exam_id); });

                // Populate Topics
                $topicBulkEdit.html('<option value="">— Change Topic —</option>').prop('disabled', availableTopics.length === 0);
                $.each(availableTopics, function(i, topic) { $topicBulkEdit.append($('<option></option>').val(topic.topic_id).text(topic.topic_name)); });

                // Populate Sources
                $sourceBulkEdit.html('<option value="">— Change Source —</option>').prop('disabled', availableSources.length === 0);
                $.each(availableSources, function(i, source) { $sourceBulkEdit.append($('<option></option>').val(source.source_id).text(source.source_name)); });
                
                // Populate Exams
                $examBulkEdit.html('<option value="">— Change Exam —</option>').prop('disabled', availableExams.length === 0);
                $.each(availableExams, function(i, exam) { $examBulkEdit.append($('<option></option>').val(exam.exam_id).text(exam.exam_name)); });
                
                // Reset sections
                $sectionBulkEdit.html('<option value="">— Select Source First —</option>').prop('disabled', true);
                
                $bulkEditPanel.slideDown();
            } else {
                // Hide the panel if no subject is selected or if it doesn't match the initial filter
                $bulkEditPanel.slideUp();
            }
        }

        // This handler ONLY populates the section dropdown when a source is chosen
        $bulkEditPanel.on('change', '#bulk_edit_source', function() {
            var selectedSource = $(this).val();
            var $sectionBulkEdit = $('#bulk_edit_section');
            var availableSections = qp_bulk_edit_data.sections.filter(function(sec) { return sec.source_id == selectedSource; });
            
            $sectionBulkEdit.html('<option value="">— Change Section —</option>').prop('disabled', availableSections.length === 0);
            $.each(availableSections, function(i, section) { $sectionBulkEdit.append($('<option></option>').val(section.section_id).text(section.section_name)); });
        });

        // The main trigger: when the subject filter dropdown is changed by the user
        $subjectFilter.on('change', manageBulkEditPanel);

        // Run once on page load to set the correct initial state
        manageBulkEditPanel();
    });


});