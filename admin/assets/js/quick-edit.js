jQuery(document).ready(function ($) {
  // =========================================================================
  // Common Variables
  // =========================================================================
  var wrapper = $("#the-list"); // The main table body for questions

  // =========================================================================
  // Quick Edit Form Logic (Show/Hide and Load)
  // =========================================================================

  // Delegated event handler for clicking the "Quick Edit" link
  wrapper.on("click", ".editinline", function (e) {
    e.preventDefault();

    var questionId = $(this).data("question-id");
    var nonce = $(this).data("nonce");
    var $editRow = $("#edit-" + questionId);
    var $postRow = $("#post-" + questionId);

    // If the form for this row is already open, close it.
    if ($editRow.is(":visible")) {
      $editRow.hide();
      $postRow.removeClass("inline-editor");
      $editRow.find(".inline-edit-col").empty(); // Clear content to ensure it reloads
      return;
    }

    // Hide any other open quick edit forms before opening a new one.
    $(".quick-edit-row:visible").each(function () {
      $(this).hide();
      $(this).prev("tr").removeClass("inline-editor");
      $(this).find(".inline-edit-col").empty();
    });

    // Show the current form container and add a loading message.
    $editRow.show();
    $postRow.addClass("inline-editor");
    $editRow.find(".inline-edit-col").html("<p>Loading form...</p>");

    

// AJAX call to fetch the form HTML from the server
$.ajax({
    url: ajaxurl,
    type: "POST",
    data: {
        action: "qp_get_quick_edit_form",
        nonce: nonce,
        question_id: questionId,
    },
    success: function (response) {
        if (response.success) {
            // Inject the form HTML
            $editRow.find(".inline-edit-col").html(response.data.form);

            // --- ADD THIS BLOCK TO RENDER LATEX ---
            if (typeof renderMathInElement === 'function') {
                renderMathInElement($editRow[0], {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '$', right: '$', display: false},
                        {left: '\\\\[', right: '\\\\]', display: true},
                        {left: '\\\\(', right: '\\\\)', display: false}
                    ],
                    throwOnError: false
                });
            }
            // --- END OF NEW BLOCK ---

            // IMPORTANT: Trigger the change event on the subject dropdown.
            // This will cascade and populate all other dependent dropdowns
            // with their correct initial values.
            $editRow.find(".qe-subject-select").trigger("change");
        } else {
          $editRow
            .find(".inline-edit-col")
            .html(
              '<p style="color:red;">Error: Could not load the editor. ' +
                (response.data.message || "") +
                "</p>"
            );
        }
      },
      error: function () {
        $editRow
          .find(".inline-edit-col")
          .html('<p style="color:red;">Error: A server error occurred.</p>');
      },
    });
  });

  // =========================================================================
  // Quick Edit Form - Dynamic Dropdown Logic
  // =========================================================================

  /**
   * Helper function to populate a <select> dropdown with new options.
   * @param {jQuery} selectElement - The jQuery object for the <select> element.
   * @param {Array} data - An array of objects, each with 'id' and 'name' properties.
   * @param {string|number} currentId - The ID of the option to be pre-selected.
   * @param {string} placeholder - The text for the first, disabled option.
   */
  function updateDropdown(selectElement, data, currentId, placeholder) {
    selectElement.empty().prop("disabled", true);

    if (data && data.length > 0) {
      selectElement.prop("disabled", false);
      selectElement.append($("<option></option>").val("").text(placeholder));
      $.each(data, function (index, item) {
        var option = $("<option></option>").val(item.id).text(item.name);
        // Use '==' for loose comparison as types might differ (e.g., string vs number)
        if (item.id == currentId) {
          option.prop("selected", true);
        }
        selectElement.append(option);
      });
    } else {
      selectElement.append(
        $("<option></option>").val("").text("— None available —")
      );
    }
  }

  // Delegated event handler for when the SUBJECT dropdown changes
wrapper.on("change", ".qe-subject-select", function () {
    // The `qp_quick_edit_data` object is available globally in this scope
    // because it's loaded in a <script> tag within the form HTML.
    if (typeof qp_quick_edit_data === "undefined") return;

    var subjectId = $(this).val();
    var $form = $(this).closest(".quick-edit-form-wrapper");
    var $topicSelect = $form.find(".qe-topic-select");
    var $sourceSelect = $form.find(".qe-source-select");
    var $sectionSelect = $form.find(".qe-section-select"); // <-- Added this line
    var $examSelect = $form.find(".qe-exam-select");

    // --- NEW: Reset source and section dropdowns ---
    $sourceSelect.val('');
    $sectionSelect.val('').empty().append('<option value="">— Select a source first —</option>').prop('disabled', true);
    // --- END NEW ---

    // 1. Update Topics dropdown based on the selected Subject
    updateDropdown(
        $topicSelect,
        qp_quick_edit_data.topics_by_subject[subjectId],
        qp_quick_edit_data.current_topic_id,
        "— Select a Topic —"
    );
    qp_quick_edit_data.current_topic_id = null; // Reset current topic ID after use

    // 2. Update Sources dropdown based on the selected Subject
    updateDropdown(
        $sourceSelect,
        qp_quick_edit_data.sources_by_subject[subjectId],
        qp_quick_edit_data.current_source_id,
        "— Select a Source —"
    );
    $sourceSelect.trigger("change"); // Trigger change to populate sections
    qp_quick_edit_data.current_source_id = null; // Reset current source ID after use

    // 3. Update Exams dropdown based on the selected Subject
    var linkedExamIds = qp_quick_edit_data.exam_subject_links
        .filter(function (link) {
            return link.subject_id == subjectId;
        })
        .map(function (link) {
            return link.exam_id;
        });

    var availableExams = qp_quick_edit_data.all_exams
        .filter(function (exam) {
            // Ensure exam.exam_id is converted to a string for includes() to work reliably
            return linkedExamIds.includes(String(exam.exam_id));
        })
        .map(function (exam) {
            // Remap properties to match what updateDropdown expects
            return { id: exam.exam_id, name: exam.exam_name };
        });

    updateDropdown(
        $examSelect,
        availableExams,
        qp_quick_edit_data.current_exam_id,
        "— Select an Exam —"
    );
    qp_quick_edit_data.current_exam_id = null; // Reset current exam ID after use
});

  // Delegated event handler for when the SOURCE dropdown changes
  wrapper.on("change", ".qe-source-select", function () {
    if (typeof qp_quick_edit_data === "undefined") return;

    var sourceId = $(this).val();
    var $form = $(this).closest(".quick-edit-form-wrapper");
    var $sectionSelect = $form.find(".qe-section-select");

    // --- NEW HIERARCHICAL LOGIC ---
    $sectionSelect.empty().prop("disabled", true);

    function buildTermHierarchy(
      parentElement,
      terms,
      parentId,
      level,
      selectedId
    ) {
      var prefix = "— ".repeat(level);
      terms.forEach(function (term) {
        if (term.parent_id == parentId) {
          var option = $("<option></option>")
            .val(term.id)
            .text(prefix + term.name);

          if (term.id == selectedId) {
            option.prop("selected", true);
          }
          parentElement.append(option);
          buildTermHierarchy(
            parentElement,
            terms,
            term.id,
            level + 1,
            selectedId
          );
        }
      });
    }

    if (sourceId && qp_quick_edit_data.all_source_terms) {
      $sectionSelect
        .prop("disabled", false)
        .append('<option value="">— Select a Section —</option>');
      buildTermHierarchy(
        $sectionSelect,
        qp_quick_edit_data.all_source_terms,
        sourceId,
        0,
        qp_quick_edit_data.current_section_id
      );
      qp_quick_edit_data.current_section_id = ""; // Clear after use
    } else {
      $sectionSelect.append(
        '<option value="">— Select a source first —</option>'
      );
    }
    // --- END NEW LOGIC ---
  });

  // Delegated event handler for the PYQ checkbox
  wrapper.on("change", ".qe-is-pyq-checkbox", function () {
    var $pyqFields = $(this)
      .closest(".qe-pyq-fields-wrapper")
      .find(".qe-pyq-fields");
    var $form = $(this).closest(".quick-edit-form-wrapper");
    var $examSelect = $form.find(".qe-exam-select");
    var $yearInput = $form.find('input[name="pyq_year"]');
    if ($(this).is(":checked")) {
      $pyqFields.slideDown();
      // Optionally: do not reset on check, only on uncheck
    } else {
      $pyqFields.slideUp();
      // Reset exam and year when unchecked
      $examSelect.val('');
      $yearInput.val('');
    }
    qp_quick_edit_data.current_exam_id = ''; // Reset current exam ID
    qp_quick_edit_data.current_year = ''; // Reset current year
});

  // =========================================================================
  // Quick Edit Form - Actions (Save, Cancel)
  // =========================================================================

  // Delegated event handler for clicking the "Cancel" button
  wrapper.on("click", ".cancel", function (e) {
    e.preventDefault();
    var $editRow = $(this).closest(".quick-edit-row");
    $editRow.hide();
    $editRow.prev("tr").removeClass("inline-editor");
    $editRow.find(".inline-edit-col").empty();
  });

  // Delegated event handler for clicking the "Update" button
wrapper.on('click', '.save', function(e) {
    e.preventDefault();
    var $button = $(this);
    var $formWrapper = $button.closest('.quick-edit-form-wrapper');
    var questionId = $formWrapper.find('input[name="question_id"]').val();

    $button.prop('disabled', true).text('Updating...');

    // Collect all active filter values from the list table controls
    var ajaxData = $formWrapper.find(':input').serialize() + '&' + $.param({
        action: 'save_quick_edit_data',
        filter_by_subject: $('#qp_filter_by_subject').val(),
        filter_by_topic: $('#qp_filter_by_topic').val(),
        filter_by_source: $('#qp_filter_by_source_section').val(),
        s: $('#question-search-input').val()
    });

    // Handle multi-select for labels
    var selectedLabels = $('#qp_label_filter_select').val();
    if (selectedLabels) {
        $.each(selectedLabels, function(index, value) {
            ajaxData += '&filter_by_label[]=' + encodeURIComponent(value);
        });
    }

    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: ajaxData,
        success: function(response) {
            var $postRow = $("#post-" + questionId);
            var $editRow = $postRow.next("tr.quick-edit-row");

            // Hide the edit form
            $editRow.hide().find(".inline-edit-col").empty();
            $postRow.removeClass("inline-editor");

            var data;
            try {
                // This robustly finds the JSON within the response string,
                // ignoring any prepended PHP notices that would break parsing.
                var jsonString = response.substring(response.indexOf('{'));
                data = JSON.parse(jsonString);
            } catch (e) {
                data = null;
            }

            if (data && data.success && data.data && typeof data.data.row_html !== 'undefined') {
                // This is the successful instant refresh.
                if (data.data.row_html.trim() === '') {
                    // If the updated question no longer matches the filters, remove it.
                    $postRow.fadeOut(400, function() { $(this).remove(); });
                    $editRow.remove();
                } else {
                    // Replace the old row's HTML with the new HTML from the server.
                    $postRow.replaceWith(data.data.row_html);
                }
            } else {
                // Fallback if JSON parsing failed.
                // Show a success message and reload the page to ensure changes are visible.
                Swal.fire({
                    title: 'Updated!',
                    text: 'Changes saved. The page will now refresh.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            }
        },
        error: function() {
            Swal.fire("Error!", "An unknown server error occurred.", "error");
            $button.prop("disabled", false).text("Update");
        },
    });
});

  // =========================================================================
  // Admin List Table - Main Filters & Bulk Edit Logic
  // =========================================================================
  // This logic is for the main filters at the top of the list table and the
  // bulk edit panel. It is preserved from the original file and assumes
  // that `qp_admin_filter_data` and `qp_bulk_edit_data` are localized
  // separately by your plugin.

  (function () {
    // Scope this logic to avoid conflicts
    var $subjectFilter = $("#qp_filter_by_subject");
    if (!$subjectFilter.length) return; // Exit if filters are not on this page

    var $topicFilter = $("#qp_filter_by_topic");
    var $sourceFilter = $("#qp_filter_by_source_section");
    var urlParams = new URLSearchParams(window.location.search);
    var currentTopic = urlParams.get("filter_by_topic");
    var currentSource = urlParams.get("filter_by_source");

    $subjectFilter
      .on("change", function () {
        var subjectId = $(this).val();
        $topicFilter.hide().val("");
        
        // MODIFICATION: Instead of hiding, we reset and will re-populate it.
        $sourceFilter.val(""); 

        if (subjectId) {
          $.ajax({
            url: qp_admin_filter_data.ajax_url,
            type: "POST",
            data: {
              action: "get_topics_for_list_table_filter",
              nonce: qp_admin_filter_data.nonce,
              subject_id: subjectId,
            },
            success: function (response) {
              if (response.success && response.data.topics.length > 0) {
                $topicFilter
                  .empty()
                  .append('<option value="">All Topics</option>');
                $.each(response.data.topics, function (index, topic) {
                  var $option = $("<option></option>")
                    .val(topic.topic_id)
                    .text(topic.topic_name);
                  if (topic.topic_id == currentTopic) {
                    $option.prop("selected", true);
                  }
                  $topicFilter.append($option);
                });
                $topicFilter.show();
                if (currentTopic) {
                  $topicFilter.trigger("change");
                  currentTopic = null; // Prevent re-triggering
                }
              }
            },
          });
        }
      })
      .trigger("change");

    $topicFilter.on("change", function () {
    var topicId = $(this).val();
    var subjectId = $subjectFilter.val(); 
    
    // MODIFICATION: Reset the source filter, but don't hide it.
    $sourceFilter.val("");

    // Only proceed if a subject is selected. Topic is now optional for this logic.
    if (subjectId) { // MODIFIED: Changed from topicId && subjectId
        $.ajax({
            url: qp_admin_filter_data.ajax_url,
            type: "POST",
            data: {
                action: "get_sources_for_list_table_filter",
                nonce: qp_admin_filter_data.nonce,
                subject_id: subjectId, 
                topic_id: topicId, // Pass the topicId, which might be empty
            },
            success: function (response) {
                if (
                response.success &&
                response.data.sources &&
                response.data.sources.length > 0
                ) {
                    $sourceFilter
                        .empty()
                        .append('<option value="">All Sources / Sections</option>');
                    $.each(response.data.sources, function (index, source) {
                        var sourceOption = $("<option></option>")
                            .val("source_" + source.source_id)
                            .text(source.source_name);
                        if ("source_" + source.source_id == currentSource) {
                            sourceOption.prop("selected", true);
                        }
                        $sourceFilter.append(sourceOption);
                        if (
                            source.sections &&
                            Object.keys(source.sections).length > 0
                        ) {
                            $.each(source.sections, function (idx, section) {
                                var sectionOption = $("<option></option>")
                                    .val("section_" + section.section_id)
                                    .text("  - " + section.section_name);
                                if ("section_" + section.section_id == currentSource) {
                                    sectionOption.prop("selected", true);
                                }
                                $sourceFilter.append(sectionOption);
                            });
                        }
                    });
                    // MODIFICATION: No longer need to .show() as it's always visible.
                } else {
                    // If no sources are found for the selection, show a relevant message.
                    $sourceFilter
                        .empty()
                        .append('<option value="">No sources for this selection</option>');
                }
            },
        });
    }
});

    // Bulk Edit Panel Logic
    var $bulkEditPanel = $("#qp-bulk-edit-panel");
    var initialSubject = urlParams.get("filter_by_subject");

    function manageBulkEditPanel() {
      var currentSubject = $subjectFilter.val();
      if (currentSubject && currentSubject === initialSubject) {
        var $sourceBulkEdit = $("#bulk_edit_source");
        var $sectionBulkEdit = $("#bulk_edit_section");
        var $topicBulkEdit = $("#bulk_edit_topic");
        var $examBulkEdit = $("#bulk_edit_exam");

        var availableTopics = qp_bulk_edit_data.topics.filter(
          (t) => t.subject_id == currentSubject
        );
        var linkedSourceIds = qp_bulk_edit_data.source_subject_links
          .filter((link) => link.subject_id == currentSubject)
          .map((link) => link.source_id);
        var availableSources = qp_bulk_edit_data.sources.filter((source) =>
          linkedSourceIds.includes(source.source_id)
        );
        var linkedExamIds = qp_bulk_edit_data.exam_subject_links
          .filter((l) => l.subject_id == currentSubject)
          .map((l) => l.exam_id);
        var availableExams = qp_bulk_edit_data.exams.filter((e) =>
          linkedExamIds.includes(e.exam_id)
        );

        $topicBulkEdit
          .html('<option value="">— Change Topic —</option>')
          .prop("disabled", availableTopics.length === 0);
        $.each(availableTopics, (i, topic) =>
          $topicBulkEdit.append(
            $("<option></option>").val(topic.topic_id).text(topic.topic_name)
          )
        );

        $sourceBulkEdit
          .html('<option value="">— Change Source —</option>')
          .prop("disabled", availableSources.length === 0);
        $.each(availableSources, (i, source) =>
          $sourceBulkEdit.append(
            $("<option></option>")
              .val(source.source_id)
              .text(source.source_name)
          )
        );

        $examBulkEdit
          .html('<option value="">— Change Exam —</option>')
          .prop("disabled", availableExams.length === 0);
        $.each(availableExams, (i, exam) =>
          $examBulkEdit.append(
            $("<option></option>").val(exam.exam_id).text(exam.exam_name)
          )
        );

        $sectionBulkEdit
          .html('<option value="">— Select Source First —</option>')
          .prop("disabled", true);
        $bulkEditPanel.slideDown();
      } else {
        $bulkEditPanel.slideUp();
      }
    }

    $bulkEditPanel.on("change", "#bulk_edit_source", function () {
      var selectedSource = $(this).val();
      var $sectionBulkEdit = $("#bulk_edit_section");

      $sectionBulkEdit.empty().prop('disabled', true);

      // Helper function to recursively build the dropdown
      function buildTermHierarchy(parentElement, terms, parentId, level) {
          var prefix = '— '.repeat(level);
          terms.forEach(function(term) {
              if (term.source_id == parentId) { // Check if it's a child of the current parent
                  var option = $("<option></option>")
                      .val(term.section_id)
                      .text(prefix + term.section_name);
                  parentElement.append(option);
                  // Recursive call to find children of this term
                  buildTermHierarchy(parentElement, terms, term.section_id, level + 1);
              }
          });
      }

      if (selectedSource && qp_bulk_edit_data.sections) {
          $sectionBulkEdit.prop('disabled', false).html('<option value="">— Change Section —</option>');
          // Start the recursive function
          buildTermHierarchy($sectionBulkEdit, qp_bulk_edit_data.sections, selectedSource, 0);
      } else {
          $sectionBulkEdit.html('<option value="">— Select Source First —</option>');
      }
    });

    $subjectFilter.on("change", manageBulkEditPanel);
    manageBulkEditPanel();
  })();

  // =========================================================================
  // View Question Modal Logic
  // =========================================================================
  (function () {
    var modalBackdrop = $("#wpbody-content").find("#qp-view-modal-backdrop");
    if (!modalBackdrop.length) return; // Exit if modal is not on page

    var modalBody = modalBackdrop.find("#qp-view-modal-body");

    wrapper.on("click", ".view-question", function (e) {
      e.preventDefault();
      var questionId = $(this).data("question-id");
      modalBody.html("<p>Loading...</p>");
      modalBackdrop.css("display", "flex");

      $.ajax({
        url: ajaxurl,
        type: "POST",
        data: {
          action: "get_single_question_for_review",
          nonce: qp_quick_edit_object.nonce,
          question_id: questionId,
        },
        success: function (response) {
          if (response.success) {
            var data = response.data;
            var html = `<h4>${data.subject_name || ""} (ID: ${
              data.custom_question_id
            })</h4>`;

            if (data.direction_text || data.direction_image_url) {
              html += `<div style="background: #f6f7f7; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">`;
              if(data.direction_text) {
                html += '<div class="qp-direction-text-wrapper">' + data.direction_text + '</div>';
              }
              if(data.direction_image_url) {
                html += '<div class="qp-direction-image-wrapper"><img src="' + data.direction_image_url + '" style="max-width: 50%; max-height: 150px; display: block; margin: 10px auto 0 auto;" /></div>';
              }
              html += `</div>`;
            }

            html += `<div class="question-text" style="margin-bottom: 1.5rem;">${data.question_text}</div>`;
            html += '<div class="qp-options-area">';
            data.options.forEach(function (opt) {
              var style =
                opt.is_correct == 1
                  ? "border-color: #2e7d32; background: #e8f5e9; font-weight: bold;"
                  : "border-color: #e0e0e0;";
              html += `<div class="option" style="border: 2px solid; padding: 1rem; margin-bottom: 0.5rem; border-radius: 8px; ${style}">${opt.option_text}</div>`;
            });
            html += "</div>";
            modalBody.html(html);
            if (typeof renderMathInElement === "function") {
              renderMathInElement(modalBody[0], {
                delimiters: [
                  { left: "$$", right: "$$", display: true },
                  { left: "$", right: "$", display: false },
                  { left: "\\\\[", right: "\\\\]", display: true },
                  { left: "\\\\(", right: "\\\\)", display: false },
                ],
                throwOnError: false,
              });
            }
          } else {
            modalBody.html(
              '<p style="color:red;">Could not load question data.</p>'
            );
          }
        },
      });
    });

    modalBackdrop.on("click", function (e) {
      if (e.target === this || $(e.target).hasClass("qp-modal-close-btn")) {
        modalBackdrop.hide();
        modalBody.empty();
      }
    });
  })();
});
