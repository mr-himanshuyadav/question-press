jQuery(document).ready(function ($) {
    const sectionsList = $('#qp-sections-list');
    const structureContainer = $('#qp-course-structure-container');

    // --- Helper Function: Get Next Index ---
    // Calculates the next available index for naming inputs correctly
    function getNextIndex(containerSelector, elementSelector) {
        const items = $(containerSelector).find(elementSelector);
        return items.length;
    }

    // --- Helper Function: Re-index Inputs ---
    // Updates name attributes after sorting or deleting
    function reindexInputs() {
        sectionsList.find('.qp-section').each(function (sectionIndex, sectionEl) {
            const $section = $(sectionEl);
            const sectionBaseName = `course_sections[${sectionIndex}]`;

            $section.find('> .qp-section-header').find('.qp-section-title-input').attr('name', `${sectionBaseName}[title]`);
            // Add other section-level fields here if needed (like description)

            $section.find('.qp-course-item').each(function (itemIndex, itemEl) {
                const $item = $(itemEl);
                const itemBaseName = `${sectionBaseName}[items][${itemIndex}]`;

                $item.find('.qp-item-title-input').attr('name', `${itemBaseName}[title]`);
                $item.find('.qp-item-content-type-select').attr('name', `${itemBaseName}[content_type]`);

                // Re-index config fields based on content type
                const contentType = $item.find('.qp-item-content-type-select').val();
                if (contentType === 'test_series') {
                    $item.find('.qp-test-series-config').find('[data-config-key]').each(function () {
                        const key = $(this).data('config-key');
                        $(this).attr('name', `${itemBaseName}[config][${key}]`);
                    });
                     // Handle multi-selects (subjects/topics) - ensure names are updated
                    $item.find('.qp-test-series-config').find('select[multiple][data-config-key]').each(function() {
                        const key = $(this).data('config-key');
                        $(this).attr('name', `${itemBaseName}[config][${key}][]`); // Add [] for array
                    });
                    $item.find('.qp-test-series-config').find('input[type="checkbox"][data-config-key]').each(function() {
                        const key = $(this).data('config-key');
                         $(this).attr('name', `${itemBaseName}[config][${key}]`);
                    });
                }
                // Add re-indexing for other content types here later
            });
        });
    }

    // --- Helper Function: Render Test Series Config ---
    function renderTestSeriesConfig(itemIndex, sectionIndex, configData = {}) {
        const itemBaseName = `course_sections[${sectionIndex}][items][${itemIndex}][config]`;
        const allSubjectTerms = qpCourseEditorData.testSeriesOptions.allSubjectTerms || [];

        // Build subject options first
        let subjectOptionsHtml = '';
        allSubjectTerms.filter(term => term.parent == 0).forEach(subject => {
            const isSelected = configData.subjects && configData.subjects.includes(String(subject.id));
            subjectOptionsHtml += `<option value="${subject.id}" ${isSelected ? 'selected' : ''}>${subject.name}</option>`;
        });

        // Build topic options dynamically later based on subject selection

        // --- Basic Structure ---
        let configHtml = `
            <div class="qp-test-series-config">
                <div class="qp-config-row">
                    <div>
                        <label>Subjects</label>
                        <select name="${itemBaseName}[subjects][]" data-config-key="subjects" class="qp-config-subjects" multiple style="height: 100px;">
                           ${subjectOptionsHtml}
                        </select>
                    </div>
                     <div>
                        <label>Topics</label>
                        <select name="${itemBaseName}[topics][]" data-config-key="topics" class="qp-config-topics" multiple style="height: 100px;" disabled>
                            <option value="">— Select Subjects First —</option>
                        </select>
                    </div>
                </div>
                 <div class="qp-config-row">
                    <div>
                        <label for="${itemBaseName}[num_questions]">Number of Questions</label>
                        <input type="number" name="${itemBaseName}[num_questions]" data-config-key="num_questions" value="${configData.num_questions || 10}" min="1">
                    </div>
                    <div>
                        <label for="${itemBaseName}[time_limit]">Time Limit (Minutes, 0=None)</label>
                        <input type="number" name="${itemBaseName}[time_limit]" data-config-key="time_limit" value="${configData.time_limit || 0}" min="0">
                    </div>
                </div>
                 <div class="qp-config-row">
                     <div>
                        <label style="display:inline-block; margin-right: 10px;">
                            <input type="checkbox" name="${itemBaseName}[scoring_enabled]" data-config-key="scoring_enabled" value="1" class="qp-config-scoring-enabled" ${configData.scoring_enabled ? 'checked' : ''}>
                            Enable Scoring
                        </label>
                     </div>
                 </div>
                 <div class="qp-config-row qp-marks-group" style="${configData.scoring_enabled ? '' : 'display: none;'}">
                     <div>
                        <label>Marks Correct</label>
                        <input type="number" name="${itemBaseName}[marks_correct]" data-config-key="marks_correct" value="${configData.marks_correct || 1}" step="0.1" min="0">
                     </div>
                     <div>
                        <label>Marks Incorrect (Penalty)</label>
                        <input type="number" name="${itemBaseName}[marks_incorrect]" data-config-key="marks_incorrect" value="${configData.marks_incorrect || 0}" step="0.1" min="0">
                    </div>
                 </div>

                 <hr style="margin: 1.5rem 0;">
                <div>
                    <label>Manually Selected Questions</label>
                    <div id="selected-questions-display-${sectionIndex}-${itemIndex}" class="qp-selected-questions-display" style="min-height: 40px; background: #f0f0f1; border: 1px solid #ddd; padding: 8px; border-radius: 4px; margin-bottom: 8px;">
                        <span style="color: #777;">No questions selected manually. Criteria above will be used.</span>
                    </div>
                    <button type="button" class="button qp-select-questions-btn" data-section-index="${sectionIndex}" data-item-index="${itemIndex}">
                        <span class="dashicons dashicons-editor-ul" style="vertical-align: text-top;"></span> Select Questions
                    </button>
                    <input type="hidden" name="${itemBaseName}[selected_questions]" data-config-key="selected_questions" class="qp-selected-questions-input" value="${configData.selected_questions ? configData.selected_questions.join(',') : ''}">
                    <p class="description" style="margin-top: 5px;">If you manually select questions, the Subject/Topic/Number criteria above will be ignored for this item.</p>
                </div>
            </div>`;
        return configHtml;
    }

    // --- Helper Function: Render Single Item ---
    function renderItem(itemData, itemIndex, sectionIndex) {
        const itemHtml = `
            <div class="qp-course-item" data-item-id="${itemData.id || 0}">
                 <div class="qp-item-header">
                     <span class="dashicons dashicons-menu handle"></span>
                     <input type="text" class="qp-item-title-input" value="${itemData.title || ''}" placeholder="Item Title">
                     <div class="qp-item-controls">
                         <select class="qp-item-content-type-select" disabled> <option value="test_series" selected>Test Series</option>
                             </select>
                         <button type="button" class="button button-link-delete qp-remove-item-btn"><span class="dashicons dashicons-trash"></span></button>
                    </div>
                 </div>
                 <div class="qp-item-config">
                     ${renderTestSeriesConfig(itemIndex, sectionIndex, itemData.content_config)}
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

        const sectionHtml = `
            <div class="qp-section" data-section-id="${sectionData.id || 0}">
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
            // Make sure dynamically loaded selects trigger their updates
            $('.qp-config-subjects').trigger('change');
            $('.qp-config-scoring-enabled').trigger('change');
            makeSortable();
            reindexInputs(); // Ensure initial names are correct
        }
    }

    // --- Add Section ---
    $('#qp-add-section-btn').on('click', function () {
        const sectionIndex = getNextIndex('#qp-sections-list', '.qp-section');
        sectionsList.append(renderSection({}, sectionIndex));
        makeSortable(); // Re-initialize sortable
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
        const sectionIndex = $section.index(); // Get current index
        const itemIndex = getNextIndex($itemsList, '.qp-course-item');

        $itemsList.append(renderItem({}, itemIndex, sectionIndex));
        makeSortable(); // Re-initialize sortable
        reindexInputs();
    });

    // --- Remove Item ---
    sectionsList.on('click', '.qp-remove-item-btn', function () {
         $(this).closest('.qp-course-item').fadeOut(300, function() {
             $(this).remove();
             reindexInputs();
        });
    });

    // --- Dynamic Topic Loading for Test Series Config ---
    sectionsList.on('change', '.qp-config-subjects', function() {
        const $subjectSelect = $(this);
        const $item = $subjectSelect.closest('.qp-course-item');
        const $topicSelect = $item.find('.qp-config-topics');
        const selectedSubjectIds = $subjectSelect.val() || [];
        const allSubjectTerms = qpCourseEditorData.testSeriesOptions.allSubjectTerms || [];
        const currentConfig = $item.data('initial-config') || {}; // Get initial config if stored

        $topicSelect.empty().prop('disabled', true);

        if (selectedSubjectIds.length > 0) {
            let availableTopics = [];
            selectedSubjectIds.forEach(subjectId => {
                // Find topics whose parent is the selected subject
                availableTopics = availableTopics.concat(
                    allSubjectTerms.filter(term => term.parent == subjectId)
                );
            });

            if (availableTopics.length > 0) {
                 $topicSelect.prop('disabled', false);
                 availableTopics.sort((a, b) => a.name.localeCompare(b.name));
                 availableTopics.forEach(topic => {
                     const isSelected = currentConfig.topics && currentConfig.topics.includes(String(topic.id));
                     $topicSelect.append(`<option value="${topic.id}" ${isSelected ? 'selected' : ''}>${topic.name}</option>`);
                 });
            } else {
                 $topicSelect.append('<option value="">— No topics for selected subject(s) —</option>');
            }
        } else {
            $topicSelect.append('<option value="">— Select Subjects First —</option>');
        }
    });

     // --- Toggle Scoring Fields ---
    sectionsList.on('change', '.qp-config-scoring-enabled', function() {
        const $checkbox = $(this);
        const $marksGroup = $checkbox.closest('.qp-test-series-config').find('.qp-marks-group');
        if ($checkbox.is(':checked')) {
            $marksGroup.slideDown(200);
        } else {
            $marksGroup.slideUp(200);
            $marksGroup.find('input').val(0); // Optionally reset values when hiding
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
            connectWith: '.qp-items-list', // Allow moving between sections
            placeholder: 'qp-item-placeholder',
            forcePlaceholderSize: true,
            update: function (event, ui) {
                reindexInputs();
            }
        });
    }

    // --- Load initial data and make sortable ---
    loadInitialStructure();

}); // End jQuery ready