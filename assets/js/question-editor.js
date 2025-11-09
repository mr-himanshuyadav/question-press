jQuery(document).ready(function ($) {
  var subjectSelect = $("#subject_id");
  var topicSelect = $("#topic_id");
  var sourceSelect = $("#source_id");
  var sectionSelect = $("#section_id");
  var isPyqCheckbox = $("#is_pyq_checkbox");
  var pyqFieldsWrapper = $("#pyq_fields_wrapper");
  let initialFormState = {};

  function getCurrentState() {
    var questions = [];
    $(".qp-question-block").each(function () {
      var $block = $(this);
      var editorId = $block.find("textarea.wp-editor-area").attr("id");
      var questionText =
        typeof tinymce !== "undefined" && tinymce.get(editorId)
          ? tinymce.get(editorId).getContent()
          : "";

      var options = [];
      $block.find(".qp-option-row").each(function () {
        var $row = $(this);
        options.push({
          id: $row.find('input[name$="[option_ids][]"]').val(),
          text: $row.find(".option-text-input").val(),
        });
      });

      var labels = [];
      $block.find(".label-checkbox:checked").each(function () {
        labels.push($(this).val());
      });

      questions.push({
        id: $block.find(".question-id-input").val(),
        text: questionText,
        options: options,
        correctOptionId:
          $block.find('input[name$="[correct_option_id]"]:checked').val() ||
          null,
        labels: labels,
      });
    });

    var directionEditor =
      typeof tinymce !== "undefined"
        ? tinymce.get("direction_text_editor")
        : null;

    return {
      groupId: $('input[name="group_id"]').val(),
      subjectId: $("#subject_id").val(),
      topicId: $("#topic_id").val(),
      sourceId: $("#source_id").val(),
      sectionId: $("#section_id").val(),
      isPyq: $("#is_pyq_checkbox").is(":checked"),
      examId: $("#exam_id").val(),
      pyqYear: $('input[name="pyq_year"]').val(),
      directionText: directionEditor ? directionEditor.getContent() : "",
      directionImageId: $("#direction-image-id").val(),
      questions: questions,
    };
  }

  // Use $(window).on('load') to ensure all scripts, including TinyMCE, are fully loaded.
  $(window).on("load", function () {
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

    topicSelect.empty().prop("disabled", true);

    // Recursive helper function to build the indented options
    function buildTopicHierarchy(
      parentElement,
      allTerms,
      parentId,
      level,
      selectedId
    ) {
      var prefix = "— ".repeat(level);

      // Find all direct children of the current parentId and sort them
      var children = allTerms.filter((term) => term.parent == parentId);
      children.sort((a, b) => a.name.localeCompare(b.name));

      children.forEach(function (term) {
        var option = $("<option></option>")
          .val(term.id)
          .text(prefix + term.name);

        if (term.id == selectedId) {
          option.prop("selected", true);
        }
        parentElement.append(option);

        // Recurse for grandchildren
        buildTopicHierarchy(
          parentElement,
          allTerms,
          term.id,
          level + 1,
          selectedId
        );
      });
    }

    if (selectedSubjectId) {
      topicSelect
        .prop("disabled", false)
        .append('<option value="">— Select a Topic —</option>');
      // Start the recursive build from the selected subject, using all subject terms
      buildTopicHierarchy(
        topicSelect,
        qp_editor_data.all_subject_terms,
        selectedSubjectId,
        0,
        qp_editor_data.current_topic_id
      );
    } else {
      topicSelect.append(
        '<option value="">— Select a Subject First —</option>'
      );
    }
    // Clear the global variable after it's been used to set the initial state
    qp_editor_data.current_topic_id = "";
  }

  // --- Function to update Source dropdown based on Subject ---
  function updateSources() {
    var selectedSubjectId = subjectSelect.val();
    var previouslySelectedSourceId =
      sourceSelect.val() || qp_editor_data.current_source_id;

    sourceSelect.empty().prop("disabled", true);

    if (selectedSubjectId) {
      // Find all top-level source IDs linked to the selected subject
      var linkedSourceIds = qp_editor_data.source_subject_links
        .filter((link) => link.subject_id == selectedSubjectId)
        .map((link) => link.source_id);

      // Filter all source terms to get only the linked, top-level ones
      var availableSources = qp_editor_data.all_source_terms.filter(
        (term) =>
          term.parent_id == 0 && linkedSourceIds.includes(String(term.id))
      );

      if (availableSources.length > 0) {
        sourceSelect
          .prop("disabled", false)
          .append('<option value="">— Select a Source —</option>');
        availableSources.sort((a, b) => a.name.localeCompare(b.name)); // Sort them alphabetically

        $.each(availableSources, function (index, source) {
          var option = $("<option></option>").val(source.id).text(source.name);
          if (source.id == previouslySelectedSourceId) {
            option.prop("selected", true);
          }
          sourceSelect.append(option);
        });
      } else {
        sourceSelect.append(
          '<option value="">— No sources for this subject —</option>'
        );
      }
    } else {
      sourceSelect.append(
        '<option value="">— Select a Subject First —</option>'
      );
    }

    sourceSelect.trigger("change"); // IMPORTANT: This will trigger updateSections()
    qp_editor_data.current_source_id = ""; // Clear after use
  }

  // --- Function to update Section dropdown based on Source ---
  function updateSections() {
    var selectedSourceId = sourceSelect.val();
    var previouslySelectedSectionId =
      sectionSelect.val() || qp_editor_data.current_section_id;

    sectionSelect.empty().prop("disabled", true);

    // Recursive helper function to build the indented options
    function buildTermHierarchy(
      parentElement,
      terms,
      parentId,
      level,
      selectedId
    ) {
      var prefix = "— ".repeat(level);

      var children = terms.filter((term) => term.parent_id == parentId);
      children.sort((a, b) => a.name.localeCompare(b.name)); // Sort alphabetically

      children.forEach(function (term) {
        var option = $("<option></option>")
          .val(term.id)
          .text(prefix + term.name);

        if (term.id == selectedId) {
          option.prop("selected", true);
        }
        parentElement.append(option);

        // Recursive call for children of the current term
        buildTermHierarchy(
          parentElement,
          terms,
          term.id,
          level + 1,
          selectedId
        );
      });
    }

    if (selectedSourceId && qp_editor_data.all_source_terms) {
      sectionSelect
        .prop("disabled", false)
        .append('<option value="">— Select a Section —</option>');
      buildTermHierarchy(
        sectionSelect,
        qp_editor_data.all_source_terms,
        selectedSourceId,
        0,
        previouslySelectedSectionId
      );
    } else {
      sectionSelect.append(
        '<option value="">— Select a source first —</option>'
      );
    }
    qp_editor_data.current_section_id = ""; // Clear after use
  }

  // --- Function to toggle PYQ fields ---
  function togglePyqFields() {
    var $form = $(this).closest(".qp-question-editor-form-wrapper");
    var $examSelect = $form.find('select[name="exam_id"]');
    var $yearInput = $form.find('input[name="pyq_year"]');
    if (isPyqCheckbox.is(":checked")) {
      pyqFieldsWrapper.slideDown();
    } else {
      pyqFieldsWrapper.slideUp();
      $examSelect.val("");
      $yearInput.val("");
    }

    qp_editor_data.current_exam_id = null; // Reset current exam ID
    qp_editor_data.current_pyq_year = null; // Reset current year
  }

  // --- Bind Event Handlers ---
  subjectSelect.on("change", function () {
    // Clear all dependent dropdowns first
    topicSelect.val("");
    sourceSelect.val("");
    sectionSelect
      .val("")
      .empty()
      .append('<option value="">— Select a source first —</option>')
      .prop("disabled", true);

    // Update the topic and source dropdowns (these functions are already correct from previous steps)
    updateTopics();
    updateSources();

    // --- START: Corrected Exam Filtering Logic ---
    var selectedSubjectId = $(this).val();
    var $examSelect = $('select[name="exam_id"]');
    var previouslySelectedExamId = qp_editor_data.current_exam_id;

    $examSelect.empty().prop("disabled", true);

    if (selectedSubjectId) {
      // Find all exam IDs linked to the selected subject
      var linkedExamIds = qp_editor_data.exam_subject_links
        .filter((link) => link.subject_id == selectedSubjectId)
        .map((link) => link.exam_id);

      // Filter the master list of all exams to get the available ones
      var availableExams = qp_editor_data.all_exams.filter((exam) =>
        linkedExamIds.includes(String(exam.exam_id))
      );

      if (availableExams.length > 0) {
        $examSelect
          .prop("disabled", false)
          .append('<option value="">— Select an Exam —</option>');
        availableExams.sort((a, b) => a.exam_name.localeCompare(b.exam_name));

        $.each(availableExams, function (index, exam) {
          var option = $("<option></option>")
            .val(exam.exam_id)
            .text(exam.exam_name);
          if (exam.exam_id == previouslySelectedExamId) {
            option.prop("selected", true);
          }
          $examSelect.append(option);
        });
      } else {
        $examSelect.append(
          '<option value="">— No exams for this subject —</option>'
        );
      }
    } else {
      $examSelect.append(
        '<option value="">— Select a Subject First —</option>'
      );
    }

    // Reset PYQ year input and clear the global variables after use
    $('input[name="pyq_year"]').val("");
    qp_editor_data.current_exam_id = null;
    qp_editor_data.current_pyq_year = null;
    // --- END: Corrected Exam Filtering Logic ---
  });

  sourceSelect.on("change", function () {
    updateSections();
  });

  isPyqCheckbox.on("change", togglePyqFields);

  // --- Logic for adding/removing question blocks ---
  // --- Logic for adding/removing question blocks ---
  $("#add-new-question-block").on("click", function (e) {
    e.preventDefault();

    var firstBlock = $(".qp-question-block:first");
    var newBlock = firstBlock
      .clone()
      .removeClass("status-publish status-draft status-reported")
      .addClass("status-new");

    // --- Hard Reset All Fields in the Cloned Block ---

    // Destroy the cloned TinyMCE instance to prevent conflicts
    var editorWrapper = newBlock.find(".wp-editor-wrap");
    var textarea = newBlock.find("textarea.wp-editor-area");
    var oldEditorId = textarea.attr("id");
    if (tinymce.get(oldEditorId)) {
      tinymce.get(oldEditorId).remove();
    }
    editorWrapper.find(".mce-container, .mce-widget").remove(); // Remove all TinyMCE generated elements
    editorWrapper.removeClass("tmce-active").addClass("html-active");
    textarea.show().val(""); // Clear the textarea content

    // --- ADD THIS BLOCK TO DESTROY THE EXPLANATION EDITOR ---
    var explanationEditorWrapper = newBlock.find(".wp-editor-wrap").last(); // Get explanation editor
    var explanationTextarea = newBlock.find("textarea.wp-editor-area").last();
    var oldExplanationEditorId = explanationTextarea.attr("id");
    if (tinymce.get(oldExplanationEditorId)) {
      tinymce.get(oldExplanationEditorId).remove();
    }
    explanationEditorWrapper.find(".mce-container, .mce-widget").remove();
    explanationEditorWrapper.removeClass("tmce-active").addClass("html-active");
    explanationTextarea.show().val(""); // Clear the textarea content

    // Remove the previous question's labels from the editor header
    newBlock.find(".qp-editor-labels-container").remove();

    // Reset basic inputs
    newBlock.find(".question-id-input").val("0");
    newBlock.find('input[name$="[question_number_in_section]"]').val("");

    // Reset the title, remove status indicators, and remove the old DB ID
    newBlock.find(".qp-question-title").text("Question (ID: New)");
    newBlock.find(".qp-status-indicator").remove();
    newBlock.find(".qp-question-db-id").remove();

    // Reset all options and ensure none are checked
    newBlock.find(".qp-option-row").each(function (index) {
      var $optionRow = $(this);
      $optionRow.find('input[type="radio"]').prop("checked", false); // Ensure no option is checked
      $optionRow.find('input[type="hidden"]').val("0"); // Reset hidden option ID
      $optionRow.find(".option-text-input").val(""); // Clear the option text
      $optionRow.find('.option-explanation-input').val('');
      $optionRow.find(".option-id-display").remove(); // Remove the DB ID display for options
    });

    // Reset all labels in the dropdown
    newBlock
      .find('.qp-dropdown-panel input[type="checkbox"]')
      .prop("checked", false);
    newBlock.find(".qp-dropdown-toggle span:first-child").text("Select Labels");

    // *** MODIFICATION 1: The following line has been DELETED ***
    // newBlock.find('.qp-options-and-labels-wrapper').hide();

    $("#qp-question-blocks-container").append(newBlock);
    reindexQuestionBlocks();
  });

  $("#qp-question-blocks-container").on(
    "click",
    ".remove-question-block",
    function (e) {
      e.preventDefault();
      if ($(".qp-question-block").length > 1) {
        var blockToRemove = $(this).closest(".qp-question-block");
        var editorId = blockToRemove.find("textarea.wp-editor-area").attr("id");

        // --- FIX: Properly remove the editor instance before removing the element ---
        if (tinymce.get(editorId)) {
          tinymce.get(editorId).remove();
        }
        blockToRemove.remove();
        reindexQuestionBlocks();
      } else {
        Swal.fire({
          title: "Action Not Allowed",
          text: "You must have at least one question in a group.",
          icon: "warning",
        });
      }
    }
  );

  function reindexQuestionBlocks() {
    $(".qp-question-block").each(function (questionIndex, questionElement) {
      var $questionBlock = $(questionElement);
      var baseName = "questions[" + questionIndex + "]";

      // --- FIX: Update all names, IDs, and for attributes ---
      $questionBlock.find('[name^="questions["]').each(function () {
        var currentName = $(this).attr("name");
        if (currentName) {
          var newName = currentName.replace(/questions\[\d+\]/, baseName);
          $(this).attr("name", newName);
        }
      });

      // Re-index editor related elements
      var $editorWrapper = $questionBlock.find(".wp-editor-wrap");
      var $textarea = $questionBlock.find("textarea.wp-editor-area");
      var newEditorId = "question_text_editor_" + questionIndex;

      // Only proceed if the ID needs changing (i.e., it's a new or re-indexed block)
      if ($textarea.length > 0 && $textarea.attr("id") !== newEditorId) {
        // Update wrapper and textarea IDs
        $editorWrapper.attr("id", "wp-" + newEditorId + "-wrap");
        $textarea.attr("id", newEditorId);

        // Update quicktags toolbar ID
        $editorWrapper
          .find(".quicktags-toolbar")
          .attr("id", "qt_" + newEditorId + "_toolbar");

        // Update editor switch button IDs and data attributes
        $editorWrapper.find(".wp-switch-editor").each(function () {
          var mode = $(this).hasClass("switch-tmce") ? "tmce" : "html";
          $(this).attr("id", newEditorId + "-" + mode);
          $(this).attr("data-editor", newEditorId);
        });

        // Re-initialize the editor using the WordPress API
        if (typeof wp.editor.initialize === "function") {
          wp.editor.initialize(newEditorId, {
            tinymce: true,
            quicktags: true,
          });
        }
      }

      var $explEditorWrapper = $questionBlock.find('.wp-editor-wrap').last();
        var $explTextarea = $questionBlock.find('textarea.wp-editor-area').last();
        var newExplEditorId = 'explanation_text_editor_' + questionIndex;

        if ($explTextarea.length > 0 && $explTextarea.attr('id') !== newExplEditorId) {
            $explEditorWrapper.attr('id', 'wp-' + newExplEditorId + '-wrap');
            $explTextarea.attr('id', newExplEditorId);
            $explEditorWrapper.find('.quicktags-toolbar').attr('id', 'qt_' + newExplEditorId + '_toolbar');
            $explEditorWrapper.find('.wp-switch-editor').each(function() {
                var mode = $(this).hasClass('switch-tmce') ? 'tmce' : 'html';
                $(this).attr('id', newExplEditorId + '-' + mode);
                $(this).attr('data-editor', newExplEditorId);
            });

            if (typeof wp.editor.initialize === 'function') {
                 wp.editor.initialize(newExplEditorId, {
                    tinymce: {
                        toolbar1: 'bold,italic,underline,link,unlink,bullist,numlist',
                        textarea_rows: 4
                    },
                    quicktags: true
                });
            }
        }
    });
  }

  // --- NEW: SweetAlert Validation on Submit ---
  $('form[method="post"]').on("submit", function (e) {
    // *** MODIFICATION: This check is no longer needed as options are always present ***
    // if ($('.qp-option-row').length === 0) {
    //     return;
    // }

    var allQuestionsValid = true;

    $(".qp-question-block").each(function () {
      var $block = $(this);
      var questionIndex = $block.index(".qp-question-block"); // Get the index of the block

      // Construct the name attribute for the radio buttons in this block
      var radioName = "questions[" + questionIndex + "][correct_option_id]";

      // Check if any radio button with that name is checked
      if ($('input[name="' + radioName + '"]:checked').length === 0) {
        allQuestionsValid = false;
        // Add a red border to highlight the invalid question block
        $block.css("border", "2px solid #d33");
      } else {
        // Remove the border if it was previously invalid but is now fixed
        $block.css("border", "");
      }
    });

    // If any question block is invalid, prevent form submission and show an alert.
    if (!allQuestionsValid) {
      e.preventDefault();
      Swal.fire({
        title: "Incomplete Options",
        text: "You must select a correct answer for every question before saving the group.",
        icon: "error",
        confirmButtonText: "OK",
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
      hasChanges: false,
    };

    // Use stringify for a simple, top-level check first.
    if (JSON.stringify(initialState) !== JSON.stringify(currentState)) {
      stats.hasChanges = true;
    }

    const initialQuestionIds = initialState.questions.map((q) => q.id);
    const currentQuestionIds = currentState.questions.map((q) => q.id);

    stats.added = currentQuestionIds.filter((id) =>
      id.toString().startsWith("0")
    ).length;
    stats.deleted = initialQuestionIds.filter(
      (id) => id !== "0" && !currentQuestionIds.includes(id)
    ).length;

    currentState.questions.forEach((currentQ) => {
      if (currentQ.id === "0") return; // Already counted in 'added'

      const initialQ = initialState.questions.find((q) => q.id === currentQ.id);
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
    stats.remainsDraft = currentState.questions.filter(
      (q) => !q.correctOptionId
    ).length;

    // Final check if any stat is non-zero
    if (stats.added || stats.deleted || stats.updated) {
      stats.hasChanges = true;
    }

    return stats;
  }

  // --- NEW: AJAX-powered Save Logic ---
  $("#qp-save-group-btn").on("click", function (e) {
    e.preventDefault();

    var subjectId = $("#subject_id").val();
    if (!subjectId) {
      Swal.fire({
        title: "Subject Required",
        text: "You must select a subject before saving the question group.",
        icon: "error",
        confirmButtonText: "OK",
      });
      return; // Exit the function immediately
    }

    var $button = $(this);
    var $form = $('form[method="post"]');

    // We still need to get current state to analyze changes
    var currentState = getCurrentState();
    var changes = analyzeChanges(initialFormState, currentState);

    if (!changes.hasChanges) {
      Swal.fire({
        title: "No Changes Detected",
        text: "The analysis didn't find any changes. This can sometimes happen if you only edited text. Would you like to save the form anyway?",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#aaa",
        confirmButtonText: "Yes, Save Anyway",
        cancelButtonText: "Cancel",
      }).then((result) => {
        if (result.isConfirmed) {
          // This reuses the same logic from the main confirmation modal's preConfirm block
          Swal.fire({
            title: "Saving...",
            text: "Your changes are being saved.",
            allowOutsideClick: false,
            didOpen: () => {
              Swal.showLoading();
              if (typeof tinymce !== "undefined") {
                tinymce.triggerSave();
              }
              $.ajax({
                url: $form.attr("action") || window.location.href,
                type: "POST",
                data: $form.serialize() + "&save_group=1",
              })
                .done(function () {
                  Swal.fire({
                    title: "Success!",
                    text: "Your changes have been saved.",
                    icon: "success",
                    timer: 2000,
                    showConfirmButton: false,
                  }).then(() => {
                    location.reload();
                  });
                })
                .fail(function (jqXHR, textStatus, errorThrown) {
                  Swal.fire(
                    "Save Failed",
                    `Request failed: ${errorThrown}`,
                    "error"
                  );
                });
            },
          });
        }
      });
      return; // Stop execution to prevent the main confirmation modal from showing
    }

    let changesHtml =
      '<div style="text-align: left; display: inline-block;"><ul>';
    if (changes.added > 0)
      changesHtml += `<li><strong>New Questions:</strong> ${changes.added} will be added.</li>`;
    if (changes.deleted > 0)
      changesHtml += `<li><strong>Deleted Questions:</strong> ${changes.deleted} will be removed.</li>`;
    if (changes.updated > 0)
      changesHtml += `<li><strong>Updated Questions:</strong> ${changes.updated} have been modified.</li>`;
    if (changes.promotedToPublish > 0)
      changesHtml += `<li><strong>Status Change:</strong> ${changes.promotedToPublish} question(s) will be published.</li>`;
    if (changes.remainsDraft > 0)
      changesHtml += `<li><strong>Draft Status:</strong> ${changes.remainsDraft} question(s) will remain as drafts.</li>`;
    changesHtml += "</ul></div>";

    Swal.fire({
      title: "Confirm Your Changes",
      html: changesHtml,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "Yes, save changes!",
      showLoaderOnConfirm: true, // <-- Add loader
      preConfirm: () => {
        // Trigger an update on all TinyMCE editors before serializing the form
        if (typeof tinymce !== "undefined") {
          tinymce.triggerSave();
        }
        return $.ajax({
          url: ajaxurl,
          type: "POST",
          data: $form.serialize() + "&save_group=1", // Add the save_group flag
          // We don't need success/error here, preConfirm handles it
        }).fail(function (jqXHR, textStatus, errorThrown) {
          Swal.showValidationMessage(`Request failed: ${errorThrown}`);
        });
      },
      allowOutsideClick: () => !Swal.isLoading(),
    }).then((result) => {
      if (result.isConfirmed) {
        var groupId = $form.find('input[name="group_id"]').val();
        var isEditing = groupId && groupId !== "0";

        if (result.value && result.value.success) {
          if (isEditing) {
            // User was editing, just show a success message and reload.
            Swal.fire({
              title: "Updated!",
              text: "Your changes have been saved.",
              icon: "success",
              timer: 1500,
              showConfirmButton: false,
            }).then(() => {
              location.reload();
            });
          } else if (result.value.data.redirect_url) {
            // User was creating, show redirect message and go to the new URL.
            Swal.fire({
              title: "Success!",
              // *** MODIFICATION 2: Changed text from "Redirecting to Step 2..." ***
              text: "Your changes have been saved.",
              icon: "success",
              timer: 1500,
              showConfirmButton: false,
            }).then(() => {
              window.location.href = result.value.data.redirect_url;
            });
          }
        } else {
          // Fallback for unexpected errors
          Swal.fire(
            "Save Failed",
            "Something went wrong. Please reload the page.",
            "error"
          );
        }
      }
    });
  });

  $(window).on("load", function () {
    $(".qp-editor-labels-container").each(function () {
      var $labelsContainer = $(this);
      var editorId = $labelsContainer.data("editor-id");
      var $editorWrapper = $("#wp-" + editorId + "-wrap");
      var $toolbar = $editorWrapper.find(".wp-editor-tools");

      if ($toolbar.length > 0) {
        // Prepend the labels to the toolbar area
        $toolbar.prepend($labelsContainer);
      }
    });
  });

  // --- Add New Option Logic ---
  $("#qp-question-blocks-container").on(
    "click",
    ".add-new-option-btn",
    function (e) {
      e.preventDefault();
      var $button = $(this);
      var $questionBlock = $button.closest(".qp-question-block");
      var $optionsContainer = $questionBlock.find(".qp-options-grid-container");
      var optionCount = $optionsContainer.find(".qp-option-row").length;

      if (optionCount < 6) {
        var questionIndex = $questionBlock.index();
        var newOptionIndex = optionCount;

        // Create the new option row from a string template
        var newOptionHtml = `
                <div class="qp-option-row">
                    <input type="radio" name="questions[${questionIndex}][correct_option_id]" value="new_${newOptionIndex}">
                    <input type="hidden" name="questions[${questionIndex}][option_ids][]" value="0">
                    <input type="text" name="questions[${questionIndex}][options][]" class="option-text-input" placeholder="Option ${newOptionIndex + 1}">
                    <input type="text" name="questions[${questionIndex}][option_explanations][]" class="option-explanation-input" value="" placeholder="Option ${newOptionIndex + 1} Explanation">
                </div>
            `;

        $optionsContainer.append(newOptionHtml);

        // Hide the button if we've reached the max limit of 6
        if (optionCount + 1 >= 6) {
          $button.hide();
        }
      }
    }
  );
  // --- Collapsible Question Block Logic ---
  $("#qp-question-blocks-container").on(
    "click",
    ".qp-toggle-question-block",
    function () {
      var $button = $(this);
      var $block = $button.closest(".qp-question-block");
      var $content = $block.find(".inside");

      $content.slideToggle(200);
      $button.toggleClass("is-closed");
    }
  );

  // --- Custom Dropdown Logic for Labels ---
  var $container = $("#qp-question-blocks-container");

  // Toggle dropdown panel
  $container.on("click", ".qp-dropdown-toggle", function (e) {
    e.stopPropagation();
    // Close other dropdowns first
    $(".qp-dropdown-panel").not($(this).next()).hide();
    $(this).next(".qp-dropdown-panel").toggle();
  });

  // Stop propagation inside the panel
  $container.on("click", ".qp-dropdown-panel", function (e) {
    e.stopPropagation();
  });

  // Update button text on change
  $container.on(
    "change",
    '.qp-dropdown-panel input[type="checkbox"]',
    function () {
      var $panel = $(this).closest(".qp-dropdown-panel");
      var $buttonSpan = $panel
        .prev(".qp-dropdown-toggle")
        .find("span:first-child");
      var count = $panel.find("input:checked").length;

      if (count > 0) {
        $buttonSpan.text(count + " Label(s)");
      } else {
        $buttonSpan.text("Select Labels");
      }
    }
  );

  // Close dropdowns when clicking outside
  $(document).on("click", function () {
    $(".qp-dropdown-panel").hide();
  });
});
