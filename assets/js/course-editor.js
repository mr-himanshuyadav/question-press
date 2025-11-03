jQuery(document).ready(function ($) {
    // Initialize the datepicker on our new field
    if ($('.qp-datepicker').length > 0) {
        $('.qp-datepicker').datepicker({
            dateFormat: 'yy-mm-dd' // WordPress standard
        });
    }
    
    const sectionsList = $('#qp-sections-list');
    const structureContainer = $('#qp-course-structure-container');

    // --- Add Modal HTML to the page FIRST ---
    const modalHtml = `
    <div id="qp-question-select-modal" style="display: none;">
        <div class="qp-modal-overlay"></div>
        <div class="qp-modal-content">
            <button type="button" class="button-link qp-modal-close-btn">&times;</button>
            <h2>Select Questions</h2>
            <div class="qp-modal-filters">
                <input type="text" id="qp-modal-search-input" placeholder="Search by ID or text...">
                <select id="qp-modal-subject-filter"><option value="">All Subjects</option></select>
                <select id="qp-modal-topic-filter" disabled><option value="">All Topics</option></select>
                <select id="qp-modal-source-filter" disabled><option value="">All Sources</option></select>
                <button type="button" class="button" id="qp-modal-filter-btn">Filter</button>
            </div>
            <div class="qp-modal-results-container">
                <div id="qp-modal-search-results" class="qp-modal-column">
                    <h3>Available Questions</h3>
                    <div class="qp-results-list"><p class="qp-modal-message">Use filters above to find questions.</p></div>
                    <div class="qp-pagination"></div>
                </div>
                <div id="qp-modal-selected-questions" class="qp-modal-column">
                    <h3>Selected (<span class="qp-selected-count">0</span>)</h3>
                    <div class="qp-selected-list"></div>
                </div>
            </div>
            <div class="qp-modal-actions">
                <button type="button" class="button button-secondary qp-modal-cancel-btn">Cancel</button>
                <button type="button" class="button button-primary qp-modal-done-btn">Done</button>
            </div>
        </div>
    </div>
    `;
    if ($('#qp-question-select-modal').length === 0) {
        $('body').append(modalHtml);
    }
    const modal = $('#qp-question-select-modal');
    const resultsList = modal.find('.qp-results-list'); // Cache results list element
    const selectedList = modal.find('.qp-selected-list'); // Cache selected list element
    const selectedCountSpan = modal.find('.qp-selected-count'); // Cache count element

    // --- Variables to store context when modal opens ---
    let currentModalTargetInput = null;
    let currentModalTargetDisplay = null;
    let currentlySelectedInModal = [];
    let currentAjaxRequest = null; // To handle aborting previous requests

    // --- Pre-populate Subject Filter in Modal ---
    const subjectDropdown = $('#qp-modal-subject-filter');
    if (subjectDropdown.children('option').length <= 1 && typeof qpCourseEditorData !== 'undefined' && qpCourseEditorData.testSeriesOptions && qpCourseEditorData.testSeriesOptions.allSubjectTerms) {
        qpCourseEditorData.testSeriesOptions.allSubjectTerms
            .filter(term => term.parent == 0)
            .sort((a,b) => a.name.localeCompare(b.name))
            .forEach(subject => {
                subjectDropdown.append(`<option value="${subject.id}">${subject.name}</option>`);
        });
    }
     // --- Dynamic dropdown logic for modal filters ---
     $('#qp-modal-subject-filter').off('change.qpsmodal').on('change.qpsmodal', function() {
        var subjectId = $(this).val();
        var $topicSelect = $('#qp-modal-topic-filter');
        var $sourceSelect = $('#qp-modal-source-filter');

        $topicSelect.empty().prop('disabled', true).append('<option value="">All Topics</option>');
        $sourceSelect.empty().prop('disabled', true).append('<option value="">All Sources</option>');

        if (subjectId && typeof qpCourseEditorData !== 'undefined') {
            // Populate Topics
            const topics = qpCourseEditorData.testSeriesOptions.allSubjectTerms
                           .filter(term => term.parent == subjectId);
            if(topics.length > 0) {
                $topicSelect.prop('disabled', false);
                 topics.sort((a,b) => a.name.localeCompare(b.name))
                       .forEach(topic => {
                            $topicSelect.append(`<option value="${topic.id}">${topic.name}</option>`);
                       });
            }

            // Populate Sources
             if (qpCourseEditorData.testSeriesOptions.sourceSubjectLinks && qpCourseEditorData.testSeriesOptions.allSourceTerms) {
                 const linkedSourceIds = qpCourseEditorData.testSeriesOptions.sourceSubjectLinks
                                         .filter(link => link.subject_id == subjectId)
                                         .map(link => link.source_id);
                 const availableSources = qpCourseEditorData.testSeriesOptions.allSourceTerms
                                         .filter(term => term.parent == 0 && linkedSourceIds.includes(String(term.id)));

                 if (availableSources.length > 0) {
                      $sourceSelect.prop('disabled', false);
                      availableSources.sort((a,b) => a.name.localeCompare(b.name))
                                      .forEach(source => {
                                         $sourceSelect.append(`<option value="${source.id}">${source.name}</option>`);
                                      });
                 }
            }
        }
    });

    // --- Helper Function: Get Next Index ---
    function getNextIndex(containerSelector, elementSelector) {
        const items = $(containerSelector).find(elementSelector);
        return items.length;
    }

    // --- Helper Function: Re-index Inputs ---
    function reindexInputs() {
        sectionsList.find('.qp-section').each(function (sectionIndex, sectionEl) {
            const $section = $(sectionEl);
            const sectionBaseName = `course_sections[${sectionIndex}]`;

            // Re-index section title and hidden ID
            $section.find('> .qp-section-header').find('.qp-section-title-input').attr('name', `${sectionBaseName}[title]`);
            $section.find('> input[name$="[section_id]"]').attr('name', `${sectionBaseName}[section_id]`); // Re-index hidden section_id

            $section.find('.qp-course-item').each(function (itemIndex, itemEl) {
                const $item = $(itemEl);
                const itemBaseName = `${sectionBaseName}[items][${itemIndex}]`;

                // Re-index item title, hidden ID, and content type
                $item.find('.qp-item-title-input').attr('name', `${itemBaseName}[title]`);
                $item.find('input[name$="[item_id]"]').attr('name', `${itemBaseName}[item_id]`); // Re-index hidden item_id
                $item.find('.qp-item-content-type-select').attr('name', `${itemBaseName}[content_type]`);

                const contentType = $item.find('.qp-item-content-type-select').val();
                if (contentType === 'test_series') {
                    // Re-index all config inputs
                    $item.find('.qp-test-series-config').find('[data-config-key]').each(function () {
                        const $input = $(this);
                        const key = $input.data('config-key');
                        const isMultiple = $input.is('select[multiple]'); // Check if it's a multi-select
                        let inputName = `${itemBaseName}[config][${key}]`;
                        if (isMultiple) {
                            inputName += '[]'; // Append brackets for array submission
                        }
                        $input.attr('name', inputName);
                    });
                     // Specifically re-index the hidden selected_questions input
                    $item.find('.qp-selected-questions-input').attr('name', `${itemBaseName}[config][selected_questions]`);
                }
                 // Add similar blocks here if you add other content types with config fields
            });
        });
    }

    // --- Helper Function: Render Test Series Config ---
    function renderTestSeriesConfig(itemIndex, sectionIndex, configData = {}) {
        const itemBaseName = `course_sections[${sectionIndex}][items][${itemIndex}][config]`;
        const selectedQuestionsArray = configData.selected_questions || [];

        // ALL fields related to time/scoring are REMOVED from here.
        let configHtml = `
            <div class="qp-test-series-config">
                 <hr>
                <div>
                    <label>Selected Questions<span style="color:red;margin-bottom:5px;">*</span></label>
                     <div id="selected-questions-display-${sectionIndex}-${itemIndex}" class="qp-selected-questions-display" style="min-height: 40px; background: #f0f0f1; border: 1px solid #ddd; padding: 8px; border-radius: 4px; margin-bottom: 8px;">
                        ${selectedQuestionsArray.length > 0
                            ? `<span style="font-weight: bold;">${selectedQuestionsArray.length} questions selected:</span> ${selectedQuestionsArray.join(',')}`
                            : '<span style="color: #777;">No questions selected.</span>'}
                    </div>
                    <button type="button" class="button qp-select-questions-btn" data-section-index="${sectionIndex}" data-item-index="${itemIndex}">
                        <span class="dashicons dashicons-editor-ul" style="vertical-align: text-top;"></span> Select Questions
                    </button>
                    <input type="hidden" name="${itemBaseName}[selected_questions]" data-config-key="selected_questions" class="qp-selected-questions-input" value="${selectedQuestionsArray.join(',') || ''}">
                </div>
            </div>`;
        return configHtml;
    }

    // --- Helper Function: Render Single Item ---
function renderItem(itemData, itemIndex, sectionIndex) {
    const config = itemData.content_config || {};
    const itemId = itemData.item_id || 0; // Get existing ID or 0 for new
    const itemBaseName = `course_sections[${sectionIndex}][items][${itemIndex}]`; // Base name for inputs
    const configBaseName = `${itemBaseName}[config]`; // Config base name

    // --- NEW: Define scoring/time variables ---
    const timeLimit = config.time_limit || 5;
    const isScoringEnabled = config.scoring_enabled;
    const marksCorrect = config.marks_correct || 1;
    const marksIncorrect = config.marks_incorrect || 0;

    // --- THIS IS THE FIX ---
    // Set visibility style based on isScoringEnabled to prevent layout shift
    const scoringFieldsVisibility = isScoringEnabled ? 'visibility: visible;' : 'visibility: hidden;';

    const itemHtml = `
        <div class="qp-course-item" data-item-id="${itemId}" data-initial-config='${JSON.stringify(config)}'>
             <input type="hidden" name="${itemBaseName}[item_id]" value="${itemId}">
             <div class="qp-item-header">
                 <span class="dashicons dashicons-menu handle"></span>
                 <input type="text" class="qp-item-title-input" value="${itemData.title || ''}" name="${itemBaseName}[title]" placeholder="Item Title">

                 <div class="qp-item-controls">
                    <div class="qp-item-header-config">
                        <div class="qp-header-field">
                            <label for="time-limit-${sectionIndex}-${itemIndex}">Time (In Minutes)</label>
                            <input type="number" id="time-limit-${sectionIndex}-${itemIndex}" name="${configBaseName}[time_limit]" data-config-key="time_limit" value="${timeLimit}" min="5" title="Time Limit (Minutes, 0=None)">
                        </div>

                        <div class="qp-header-field qp-header-field-scoring">
                            <label>
                                <input type="checkbox" name="${configBaseName}[scoring_enabled]" data-config-key="scoring_enabled" value="1" class="qp-config-scoring-enabled" ${isScoringEnabled ? 'checked' : ''}>
                                Scoring
                            </label>
                        </div>

                        <div class="qp-header-field qp-marks-group" style="${scoringFieldsVisibility}">
                            <div class="qp-marks-field-subgroup">
                                <label for="marks-correct-${sectionIndex}-${itemIndex}">Correct</label>
                                <input type="number" id="marks-correct-${sectionIndex}-${itemIndex}" name="${configBaseName}[marks_correct]" data-config-key="marks_correct" value="${marksCorrect}" step="0.1" min="0" title="Marks Correct">
                            </div>
                            <div class="qp-marks-field-subgroup">
                                <label for="marks-incorrect-${sectionIndex}-${itemIndex}">Incorrect</label>
                                <input type="number" id="marks-incorrect-${sectionIndex}-${itemIndex}" name="${configBaseName}[marks_incorrect]" data-config-key="marks_incorrect" value="${marksIncorrect}" step="0.1" min="0" title="Marks Incorrect (Penalty)">
                            </div>
                        </div>
                    </div>
                    <select class="qp-item-content-type-select" name="${itemBaseName}[content_type]" disabled>
                         <option value="test_series" selected>Test Series</option>
                    </select>
                    <button type="button" class="button button-link-delete qp-remove-item-btn"><span class="dashicons dashicons-trash"></span></button>
                </div>
             </div>
             <div class="qp-item-config">
                 ${renderTestSeriesConfig(itemIndex, sectionIndex, config)}
             </div>
        </div>`;
    return itemHtml;
}

    // --- Helper Function: Render Single Section ---
    function renderSection(sectionData, sectionIndex) {
        let itemsHtml = '';
        if (sectionData.items && sectionData.items.length > 0) {
            sectionData.items.forEach((item, itemIndex) => {
                itemsHtml += renderItem(item, itemIndex, sectionIndex);
            });
        }
        const sectionId = sectionData.id || 0; // Get existing ID or 0 for new
        const sectionBaseName = `course_sections[${sectionIndex}]`; // Base name for inputs

        const sectionHtml = `
            <div class="qp-section" data-section-id="${sectionId}">
                <input type="hidden" name="${sectionBaseName}[section_id]" value="${sectionId}">
                <div class="qp-section-header">
                     <span style="display: flex; align-items: center;">
                         <span class="dashicons dashicons-menu handle"></span>
                         <input type="text" class="qp-section-title-input" value="${sectionData.title || ''}" placeholder="Section Title">
                     </span>
                     <div class="qp-section-controls">
                        <button type="button" class="button button-secondary button-small qp-add-item-btn">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-top;"></span> Add Item
                        </button>
                        <button type="button" class="button button-link-delete qp-remove-section-btn"><span class="dashicons dashicons-trash"></span></button>
                    </div>
                </div>
                <div class="qp-section-content">
                    <div class="qp-items-list">
                       ${itemsHtml}
                    </div>
                </div>
            </div>`;
        return sectionHtml;
    }

    // --- Initial Load ---
    function loadInitialStructure() {
        if (qpCourseEditorData && qpCourseEditorData.structure && qpCourseEditorData.structure.sections) {
            qpCourseEditorData.structure.sections.forEach((section, index) => {
                sectionsList.append(renderSection(section, index));
            });
            sectionsList.find('.qp-config-subjects').each(function() {
                $(this).trigger('change');
            });
            $('.qp-config-scoring-enabled').trigger('change');
            makeSortable();
            reindexInputs();
        }
    }

    // --- Add Section ---
    $('#qp-add-section-btn').on('click', function () {
        const sectionIndex = getNextIndex('#qp-sections-list', '.qp-section');
        sectionsList.append(renderSection({}, sectionIndex));
        makeSortable();
        reindexInputs();
    });

    // --- Remove Section ---
    sectionsList.on('click', '.qp-remove-section-btn', function () {
        $(this).closest('.qp-section').fadeOut(300, function() {
             $(this).remove();
             reindexInputs();
        });
    });

    // --- Add Item ---
    sectionsList.on('click', '.qp-add-item-btn', function () {
        const $sectionContent = $(this).closest('.qp-section-header').next('.qp-section-content');
        const $itemsList = $sectionContent.find('.qp-items-list');
        const $section = $(this).closest('.qp-section');
        const sectionIndex = $section.index();
        const itemIndex = getNextIndex($itemsList, '.qp-course-item');

        $itemsList.append(renderItem({}, itemIndex, sectionIndex));
        makeSortable();
        reindexInputs();
        $itemsList.find('.qp-course-item:last-child .qp-config-subjects').trigger('change');
        $itemsList.find('.qp-course-item:last-child .qp-config-scoring-enabled').trigger('change');
    });

    // --- Remove Item ---
    sectionsList.on('click', '.qp-remove-item-btn', function () {
         $(this).closest('.qp-course-item').fadeOut(300, function() {
             $(this).remove();
             reindexInputs();
        });
    });

    // --- Toggle Scoring Fields ---
    sectionsList.on('change', '.qp-config-scoring-enabled', function() {
        const $checkbox = $(this);

        // --- THIS IS THE FIX ---
        // Find the marks group, which is now inside the same config block
        const $marksGroup = $checkbox.closest('.qp-item-header-config').find('.qp-marks-group');
        // --- END FIX ---

        if ($checkbox.is(':checked')) {
            $marksGroup.css('visibility', 'visible'); // Use visibility
        } else {
            $marksGroup.css('visibility', 'hidden'); // Use visibility
            // Note: We don't reset values, preserving user input if they toggle it off/on
        }
    });

    // --- Make Elements Sortable ---
    function makeSortable() {
        sectionsList.sortable({
            handle: '.qp-section-header .handle',
            axis: 'y',
            placeholder: 'qp-section-placeholder',
            forcePlaceholderSize: true,
            update: function (event, ui) {
                reindexInputs();
            }
        });

        sectionsList.find('.qp-items-list').sortable({
            handle: '.qp-item-header .handle',
            axis: 'y',
            connectWith: '.qp-items-list',
            placeholder: 'qp-item-placeholder',
            forcePlaceholderSize: true,
            update: function (event, ui) {
                reindexInputs();
            }
        });
    }

    // =============================================
    // --- Modal Interaction Logic Start ---
    // =============================================

    // --- Open Modal ---
    structureContainer.on('click', '.qp-select-questions-btn', function() {
        const $button = $(this);
        const $itemConfig = $button.closest('.qp-item-config');
        currentModalTargetInput = $itemConfig.find('.qp-selected-questions-input');
        currentModalTargetDisplay = $itemConfig.find('.qp-selected-questions-display');

        const currentIdsString = currentModalTargetInput.val();
        currentlySelectedInModal = currentIdsString ? currentIdsString.split(',').map(id => parseInt(id.trim(), 10)).filter(id => !isNaN(id)) : [];

        updateModalSelectedList();

        $('#qp-modal-search-input').val('');
        $('#qp-modal-subject-filter').val('').trigger('change');
        resultsList.html('<p class="qp-modal-message">Use filters above to find questions.</p>'); // Reset results
        $('.qp-pagination').empty();

        modal.fadeIn(200);
        // Trigger initial fetch when modal opens (optional, can wait for filter button)
        // fetchQuestionsForModal();
    });

    // --- Close Modal (Cancel, X button, Overlay) ---
    modal.on('click', '.qp-modal-cancel-btn, .qp-modal-close-btn, .qp-modal-overlay', function(e) {
        if ($(e.target).hasClass('qp-modal-overlay') || $(e.target).hasClass('qp-modal-cancel-btn') || $(e.target).hasClass('qp-modal-close-btn')) {
            modal.fadeOut(200);
            currentModalTargetInput = null;
            currentModalTargetDisplay = null;
            currentlySelectedInModal = [];
             // Abort ongoing AJAX request if modal is closed
             if (currentAjaxRequest) {
                currentAjaxRequest.abort();
                currentAjaxRequest = null;
            }
        }
    });

     // --- Close Modal (Done button) ---
     modal.on('click', '.qp-modal-done-btn', function() {
        if (currentModalTargetInput && currentModalTargetDisplay) {
            const selectedIdsString = currentlySelectedInModal.join(',');
            currentModalTargetInput.val(selectedIdsString);

            if (currentlySelectedInModal.length > 0) {
                 currentModalTargetDisplay.html(`<span style="font-weight: bold;">${currentlySelectedInModal.length} questions selected:</span> ${selectedIdsString}`);
            } else {
                 currentModalTargetDisplay.html('<span style="color: #777;">No questions selected manually. Criteria above will be used.</span>');
            }
        }
        modal.fadeOut(200);
        currentModalTargetInput = null;
        currentModalTargetDisplay = null;
        currentlySelectedInModal = [];
         if (currentAjaxRequest) {
            currentAjaxRequest.abort();
            currentAjaxRequest = null;
        }
    });

    // --- Function to update the "Selected" list inside the modal ---
    function updateModalSelectedList() {
        selectedList.empty();
        selectedCountSpan.text(currentlySelectedInModal.length);

        if (currentlySelectedInModal.length === 0) {
            selectedList.html('<p class="qp-modal-message" style="text-align: center; color: #777; padding: 10px;">Click "Add" on available questions to select them.</p>');
            return;
        }

        currentlySelectedInModal.forEach(qId => {
             // Maybe fetch text later? For now, just ID.
            selectedList.append(`
                <div class="qp-selected-item" data-id="${qId}">
                    <span>ID: ${qId}</span>
                    <button type="button" class="qp-remove-selected-btn" data-id="${qId}" title="Remove">&times;</button>
                </div>
            `);
        });
         // Update 'Add'/'Remove' buttons in results list based on changes
        updateResultsListButtons();
    }

     // --- Function to update Add/Remove buttons in the results list ---
    function updateResultsListButtons() {
        resultsList.find('.qp-result-item').each(function() {
            const $item = $(this);
            const qId = parseInt($item.data('id'), 10);
            const $button = $item.find('.qp-add-question-btn, .qp-remove-question-btn'); // Find either button

            if (currentlySelectedInModal.includes(qId)) {
                // If selected, show 'Remove' button
                $button.removeClass('qp-add-question-btn button-primary')
                       .addClass('qp-remove-question-btn button-secondary')
                       .text('Remove');
            } else {
                // If not selected, show 'Add' button
                $button.removeClass('qp-remove-question-btn button-secondary')
                       .addClass('qp-add-question-btn button-primary')
                       .text('Add');
            }
        });
    }


    // --- Remove from Selected List (inside modal) ---
    modal.on('click', '.qp-remove-selected-btn', function() {
        const qIdToRemove = parseInt($(this).data('id'), 10);
        currentlySelectedInModal = currentlySelectedInModal.filter(id => id !== qIdToRemove);
        updateModalSelectedList();
    });

    // --- Add Question from Results to Selected List ---
    resultsList.on('click', '.qp-add-question-btn', function() {
        const $button = $(this);
        const $item = $button.closest('.qp-result-item');
        const qId = parseInt($item.data('id'), 10);
        const qText = $item.find('.qp-result-text').text(); // Get text if needed later

        if (!currentlySelectedInModal.includes(qId)) {
            currentlySelectedInModal.push(qId);
            updateModalSelectedList();
        }
        // Change button state handled by updateModalSelectedList -> updateResultsListButtons
    });

    // --- Remove Question from Selected List via Results List Button ---
    resultsList.on('click', '.qp-remove-question-btn', function() {
        const $button = $(this);
        const $item = $button.closest('.qp-result-item');
        const qIdToRemove = parseInt($item.data('id'), 10);

        currentlySelectedInModal = currentlySelectedInModal.filter(id => id !== qIdToRemove);
        updateModalSelectedList();
        // Change button state handled by updateModalSelectedList -> updateResultsListButtons
    });


    // --- AJAX Function to Fetch Questions ---
    function fetchQuestionsForModal() {
        // Abort previous request if it's still running
        if (currentAjaxRequest) {
            currentAjaxRequest.abort();
        }

        const search = $('#qp-modal-search-input').val();
        const subject = $('#qp-modal-subject-filter').val();
        const topic = $('#qp-modal-topic-filter').val();
        const source = $('#qp-modal-source-filter').val();

        // Show loading state
        resultsList.html('<p class="qp-modal-message"><i>Loading questions...</i></p>');
        $('.qp-pagination').empty(); // Clear pagination

        currentAjaxRequest = $.ajax({
            url: qpCourseEditorData.ajax_url,
            type: 'POST',
            data: {
                action: 'qp_search_questions_for_course',
                nonce: qpCourseEditorData.select_nonce,
                search: search,
                subject_id: subject,
                topic_id: topic,
                source_id: source
                // Add page number later for pagination
            },
            success: function(response) {
                resultsList.empty(); // Clear loading message
                if (response.success && response.data.questions && response.data.questions.length > 0) {
                    response.data.questions.forEach(q => {
                        resultsList.append(`
                            <div class="qp-result-item" data-id="${q.id}">
                                <span class="qp-result-text">ID: ${q.id} - ${q.text}</span>
                                <button type="button" class="button button-small qp-add-question-btn button-primary">Add</button>
                            </div>
                        `);
                    });
                     // Update button states after adding results
                     updateResultsListButtons();
                    // Add pagination controls later if response includes pagination data
                } else if (response.success) {
                    resultsList.html('<p class="qp-modal-message">No questions found matching your criteria.</p>');
                } else {
                    resultsList.html('<p class="qp-modal-message" style="color: red;">Error: ' + (response.data.message || 'Could not fetch questions.') + '</p>');
                }
            },
            error: function(jqXHR, textStatus) {
                 if (textStatus !== 'abort') { // Don't show error if we aborted it intentionally
                    resultsList.html('<p class="qp-modal-message" style="color: red;">An AJAX error occurred. Please try again.</p>');
                 }
            },
            complete: function() {
                currentAjaxRequest = null; // Clear the request variable
            }
        });
    }

    // --- Trigger AJAX Fetch on Filter Button Click ---
    modal.on('click', '#qp-modal-filter-btn', fetchQuestionsForModal);

    // --- Optional: Trigger AJAX on Enter key in search input ---
    modal.on('keypress', '#qp-modal-search-input', function(e) {
        if (e.which === 13) { // Enter key pressed
            fetchQuestionsForModal();
        }
    });

    // ===========================================
    // --- Modal Interaction Logic End ---
    // ===========================================

    // --- NEW: Client-side validation for New Course Price ---
    $('form#post').on('submit', function(e) {
        // Check if "Paid" mode is selected
        if ( $('#qp_access_mode_paid').is(':checked') ) {
            
            // Check if we are creating a NEW product (i.e., the "new price" fields are visible)
            var $newPriceFields = $('#qp-new-product-price-fields');
            
            if ($newPriceFields.length > 0 && $newPriceFields.is(':visible')) {
                var $regularPrice = $('#_qp_new_product_regular_price');
                
                if ( $regularPrice.val() === '' || parseFloat($regularPrice.val()) <= 0 ) {
                    // Stop the form from submitting
                    e.preventDefault(); 
                    
                    // Show an alert
                    Swal.fire({
                        title: 'Price Required',
                        text: 'You must set a "Regular Price" for this paid course before saving.',
                        icon: 'warning'
                    });
                    
                    // Highlight the field and stop the spinner
                    $regularPrice.css('border-color', 'red');
                    $('#publishing-action .spinner').removeClass('is-active');
                    $('#publish').prop('disabled', false);
                }
            }
        }
    });

    // --- Load initial data and make sortable ---
    loadInitialStructure();

}); // End jQuery ready