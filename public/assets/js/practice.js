jQuery(document).ready(function ($) {
  var wrapper = $("#qp-practice-app-wrapper");

  // --- MULTI-STEP FORM LOGIC ---
  if ($(".qp-multi-step-container").length) {
    var multiStepContainer = $(".qp-multi-step-container");

    function updateStep1NextButton() {
      var modeSelected =
        $('input[name="practice_mode_selection"]:checked').length > 0;
      var orderSelectionVisible = $(".qp-order-selection").is(":visible");
      var orderSelected = $(".qp-order-btn.active").length > 0;

      if (modeSelected && (!orderSelectionVisible || orderSelected)) {
        $("#qp-step1-next-btn").prop("disabled", false);
      } else {
        $("#qp-step1-next-btn").prop("disabled", true);
      }
    }

    // Call the update function whenever a selection is made
    wrapper.on(
      "change",
      'input[name="practice_mode_selection"]',
      updateStep1NextButton
    );
    wrapper.on("click", ".qp-order-btn", function () {
      // First, handle the button's active state
      $(".qp-order-btn").removeClass("active");
      $(this).addClass("active");
      $('#qp-start-practice-form input[name="question_order"]').val(
        $(this).data("order")
      );

      // Then, check if the Next button can be enabled
      updateStep1NextButton();
    });

    // Initial check on page load
    updateStep1NextButton();

    // Function to navigate between steps
    function navigateToStep(targetStepNumber) {
      var currentStep = $(".qp-form-step.active");
      var targetStep = $("#qp-step-" + targetStepNumber);

      if (targetStep.length) {
        currentStep.removeClass("active").css("left", "-100%");
        targetStep.addClass("active").css("left", "0");
      }
    }

    // Handlers for Mode Selection (Step 1 -> 2 or 3)
    wrapper.on("click", "#qp-step1-next-btn", function () {
      var targetStep = $('input[name="practice_mode_selection"]:checked').val();
      if (targetStep) {
        navigateToStep(targetStep);
      }
    });

    // Handler for Back buttons
    wrapper.on("click", ".qp-back-btn", function () {
      var targetStep = $(this).data("target-step");
      navigateToStep(targetStep);
    });

    // Logic for conditional Question Order display
    if (
      typeof qp_ajax_object.question_order_setting !== "undefined" &&
      qp_ajax_object.question_order_setting !== "user_input"
    ) {
      $(".qp-order-selection").hide();
      // Set the hidden input to the admin-defined value
      $('#qp-start-practice-form input[name="question_order"]').val(
        qp_ajax_object.question_order_setting
      );
    }

    // --- Revision Mode UI Logic ---
    wrapper.on("click", ".qp-revision-type-btn", function () {
      var type = $(this).data("type");
      $(".qp-revision-type-btn").removeClass("active");
      $(this).addClass("active");

      if (type === "manual") {
        $("#qp-revision-manual-selection").slideDown();
      } else {
        $("#qp-revision-manual-selection").slideUp();
      }
    });

    // Revision Form Timer Checkbox
    wrapper.on(
      "change",
      '#qp-start-revision-form input[name="qp_timer_enabled"]',
      function () {
        if ($(this).is(":checked")) {
          $("#qp-revision-timer-input-wrapper").slideDown();
        } else {
          $("#qp-revision-timer-input-wrapper").slideUp();
        }
      }
    );

    // Revision Tree Checkbox Logic
    wrapper.on(
      "change",
      '.qp-revision-tree input[type="checkbox"]',
      function () {
        var $this = $(this);
        // If a subject is checked/unchecked, do the same for its topics
        if (
          $this.closest("li").parent().parent().hasClass("qp-revision-tree")
        ) {
          $this
            .closest("li")
            .find('ul input[type="checkbox"]')
            .prop("checked", $this.prop("checked"));
        }
        // If a topic is unchecked, uncheck its parent subject
        else {
          if (!$this.prop("checked")) {
            $this
              .closest("ul")
              .closest("li")
              .find('> label > input[type="checkbox"]')
              .prop("checked", false);
          }
        }
      }
    );
  }

  // --- Report Modal ---
  wrapper.on("click", "#qp-report-btn", function () {
    var reportContainer = $("#qp-report-options-container");
    reportContainer.html("<p>Loading reasons...</p>"); // Show loading state

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "get_report_reasons",
        nonce: qp_ajax_object.nonce,
      },
      success: function (response) {
        if (response.success && response.data.reasons.length > 0) {
          reportContainer.empty(); // Clear loading message
          $.each(response.data.reasons, function (index, reason) {
            var checkboxHtml = `
                        <label class="qp-custom-checkbox">
                            <input type="checkbox" name="report_reasons[]" value="${reason.reason_id}">
                            <span></span>
                            ${reason.reason_text}
                        </label>`;
            reportContainer.append(checkboxHtml);
          });
        } else {
          reportContainer.html(
            "<p>Could not load reporting options. Please try again later.</p>"
          );
        }
      },
      error: function () {
        reportContainer.html(
          "<p>An error occurred. Please try again later.</p>"
        );
      },
    });

    $("#qp-report-modal-backdrop").fadeIn(200);
  });

  // Handle the report form submission
  wrapper.on("submit", "#qp-report-form", function (e) {
    e.preventDefault();
    var form = $(this);
    var submitButton = form.find('button[type="submit"]');
    var originalButtonText = submitButton.text();
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var selectedReasons = form
      .find('input[name="report_reasons[]"]:checked')
      .map(function () {
        return $(this).val();
      })
      .get();

    if (selectedReasons.length === 0) {
      alert("Please select at least one reason for the report.");
      return;
    }

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "submit_question_report",
        nonce: qp_ajax_object.nonce,
        question_id: questionID,
        session_id: sessionID,
        reasons: selectedReasons,
      },
      beforeSend: function () {
        submitButton.text("Submitting...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success) {
          alert(
            "Report submitted successfully. The question has been skipped."
          );

          // --- FIX: Manually update the question's state ---
          var questionID = sessionQuestionIDs[currentQuestionIndex];
          answeredStates[questionID] = answeredStates[questionID] || {}; // Ensure the object exists
          answeredStates[questionID].reported = true; // Set the reported flag

          $("#qp-report-modal-backdrop").fadeOut(200);
          // Manually trigger the skip logic after a successful report
          $("#qp-skip-btn").click();
        } else {
          alert(
            "Error: " + (response.data.message || "Could not submit report.")
          );
        }
      },
      error: function () {
        alert("An unknown server error occurred.");
      },
      complete: function () {
        submitButton.text(originalButtonText).prop("disabled", false);
      },
    });
  });

  // Close the modal
  wrapper.on(
    "click",
    ".qp-modal-close-btn, #qp-report-modal-backdrop",
    function (e) {
      if (e.target === this) {
        // Only close if the click is on the backdrop itself or the close button
        $("#qp-report-modal-backdrop").fadeOut(200);
      }
    }
  );

  // Prevent modal from closing when clicking inside its content
  wrapper.on("click", "#qp-report-modal-content", function (e) {
    e.stopPropagation();
  });

  // --- FORM SUBMISSION HANDLERS (SEPARATED FOR RELIABILITY) ---

  // Handler for NORMAL Practice Form
  wrapper.on("submit", "#qp-start-practice-form", function (e) {
    e.preventDefault();
    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();

    var formData = $("#qp-start-practice-form").serialize(); // This will now correctly get all inputs from this specific form

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data:
        formData +
        "&action=start_practice_session&nonce=" +
        qp_ajax_object.nonce,
      beforeSend: function () {
        submitButton.val("Setting up session...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success && response.data.redirect_url) {
          window.location.href = response.data.redirect_url;
        } else {
          // It's better to show the error inside the form step for context
          var errorMessage =
            '<p class="qp-error-message" style="color: red; text-align: center; margin-top: 1rem;">' +
            (response.data.message || "An unknown error occurred.") +
            "</p>";
          form.find(".qp-error-message").remove(); // Remove old errors
          form.append(errorMessage);
          submitButton.val(originalButtonText).prop("disabled", false);
        }
      },
      error: function () {
        alert("A server error occurred. Please try again later.");
        submitButton.val(originalButtonText).prop("disabled", false);
      },
    });
  });

  // Handler for REVISION Mode Form
  wrapper.on("submit", "#qp-start-revision-form", function (e) {
    e.preventDefault();
    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();

    var formData = $("#qp-start-revision-form").serialize(); // This will now correctly get all inputs from this specific form

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data:
        formData +
        "&action=start_practice_session&nonce=" +
        qp_ajax_object.nonce,
      beforeSend: function () {
        submitButton.val("Setting up session...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success && response.data.redirect_url) {
          window.location.href = response.data.redirect_url;
        } else {
          var errorMessage = response.data.html
            ? response.data.html
            : "<p>" +
              (response.data.message || "An unknown error occurred.") +
              "</p>";
          wrapper.html(errorMessage); // This mode has a different error display
        }
      },
      error: function () {
        alert("A server error occurred. Please try again later.");
        submitButton.val(originalButtonText).prop("disabled", false);
      },
    });
  });

  // Session state variables
  var sessionID = 0;
  var sessionQuestionIDs = [];
  var currentQuestionIndex = 0;
  var highestQuestionIndexReached = 0;
  var sessionSettings = {};
  var score = 0;
  var correctCount = 0;
  var incorrectCount = 0;
  var skippedCount = 0;
  var questionTimer;
  var answeredStates = {};
  var practiceInProgress = false;
  var questionCache = {};

  // --- NEW: SWIPE GESTURE HANDLING ---
  // Check if we are on the actual practice screen
  if (wrapper.find(".qp-practice-wrapper").length > 0) {
    var practiceArea = document.querySelector(".qp-practice-wrapper");
    var hammer = new Hammer(practiceArea);

    hammer.on("swipeleft", function (ev) {
      $("#qp-next-btn:not(:disabled)").trigger("click");
    });

    hammer.on("swiperight", function (ev) {
      $("#qp-prev-btn:not(:disabled)").trigger("click");
    });
  }

  // Session Initialization
  if (typeof qp_session_data !== "undefined") {
    practiceInProgress = true;
    sessionID = qp_session_data.session_id;
    sessionQuestionIDs = qp_session_data.question_ids;
    sessionSettings = qp_session_data.settings;

    if (qp_session_data.attempt_history) {
      var lastAttemptedIndex = -1;
      for (var i = 0; i < sessionQuestionIDs.length; i++) {
        var qid = sessionQuestionIDs[i];
        if (qp_session_data.attempt_history[qid]) {
          var attempt = qp_session_data.attempt_history[qid];
          lastAttemptedIndex = i;

          if (attempt.is_correct === null) {
            answeredStates[qid] = { type: "skipped" };
            skippedCount++;
          } else {
            var isCorrect = parseInt(attempt.is_correct, 10) === 1;
            answeredStates[qid] = {
              type: "answered",
              is_correct: isCorrect,
              selected_option_id: attempt.selected_option_id,
              correct_option_id: attempt.correct_option_id,
            };
            if (isCorrect) {
              correctCount++;
              score += parseFloat(sessionSettings.marks_correct);
            } else {
              incorrectCount++;
              score += parseFloat(sessionSettings.marks_incorrect);
            }
          }
        }
      }
      currentQuestionIndex = lastAttemptedIndex + 1;

      highestQuestionIndexReached =
        lastAttemptedIndex >= 0 ? lastAttemptedIndex + 1 : 0;
    }
    updateHeaderStats();

    if (currentQuestionIndex >= sessionQuestionIDs.length) {
      $("#qp-end-practice-btn").click();
    } else {
      loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
    }
  }

  // Logic for the timer checkbox on the settings form
  wrapper.on("change", "#qp_timer_enabled_cb", function () {
    if ($(this).is(":checked")) {
      $("#qp-timer-input-wrapper").slideDown();
    } else {
      $("#qp-timer-input-wrapper").slideUp();
    }
  });

  wrapper.on("change", "#qp_topic", function () {
    var topicId = $(this).val();
    var subjectId = $("#qp_subject").val();
    var sectionGroup = $("#qp-section-group");
    var sectionSelect = $("#qp_section");

    // Always hide the section dropdown first.
    sectionGroup.slideUp();

    // Only proceed if a specific topic is selected.
    if (topicId && topicId !== "all") {
      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: {
          action: "get_sections_for_subject",
          nonce: qp_ajax_object.nonce,
          subject_id: subjectId,
          topic_id: topicId,
        },
        beforeSend: function () {
          sectionSelect
            .prop("disabled", true)
            .html("<option>Loading sections...</option>");
          // Show the group immediately with the loading message.
          sectionGroup.slideDown();
        },
        success: function (response) {
          if (response.success && response.data.sections.length > 0) {
            // If sections are found, populate and enable the dropdown.
            sectionSelect
              .prop("disabled", false)
              .empty()
              .append('<option value="all">All Sections</option>');
            $.each(response.data.sections, function (index, sec) {
              var optionText = sec.source_name + " / " + sec.section_name;
              sectionSelect.append(
                $("<option></option>").val(sec.section_id).text(optionText)
              );
            });
          } else {
            // THE FIX: If no sections are found, show the message and keep it disabled.
            sectionSelect
              .prop("disabled", true)
              .empty()
              .append('<option value="all">No separate sections</option>');
          }
        },
      });
    }
  });

  // Handlers for the new error screen buttons
  wrapper.on("click", "#qp-try-revision-btn, #qp-go-back-btn", function () {
    var isRevision = $(this).attr("id") === "qp-try-revision-btn";
    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: { action: "get_practice_form_html", nonce: qp_ajax_object.nonce },
      beforeSend: function () {
        wrapper.html(
          '<p style="text-align:center; padding: 50px;">Loading form...</p>'
        );
      },
      success: function (response) {
        if (response.success) {
          wrapper.html(response.data.form_html);
          if (isRevision) {
            wrapper.find('input[name="qp_revise_mode"]').prop("checked", true);
          }
        }
      },
    });
  });

  wrapper.on("change", "#qp-mark-for-review-cb", function () {
    var checkbox = $(this);
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var isMarked = checkbox.is(":checked");
    checkbox.prop("disabled", true);

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "qp_toggle_review_later",
        nonce: qp_ajax_object.nonce,
        question_id: questionID,
        is_marked: isMarked,
      },
      success: function () {
        if (questionCache[questionID]) {
          questionCache[questionID].is_marked_for_review = isMarked;
        }
      },
      complete: function () {
        checkbox.prop("disabled", false);
      },
    });
  });

  wrapper.on("change", "#qp_subject", function () {
    var subjectId = $(this).val();
    var topicGroup = $("#qp-topic-group");
    var topicSelect = $("#qp_topic");
    var sectionGroup = $("#qp-section-group");
    topicGroup.slideUp();
    sectionGroup.slideUp();
    topicSelect
      .prop("disabled", true)
      .html("<option value=''>-- Select a subject first --</option>");
    if (subjectId && subjectId !== "all") {
      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: {
          action: "get_topics_for_subject",
          nonce: qp_ajax_object.nonce,
          subject_id: subjectId,
        },
        beforeSend: function () {
          topicSelect.html("<option>Loading topics...</option>");
        },
        success: function (response) {
          if (response.success && response.data.topics.length > 0) {
            topicGroup.slideDown();
            topicSelect
              .prop("disabled", false)
              .empty()
              .append('<option value="all">All Topics</option>');
            $.each(response.data.topics, function (index, topic) {
              topicSelect.append(
                $("<option></option>")
                  .val(topic.topic_id)
                  .text(topic.topic_name)
              );
            });
          } else {
            topicGroup.slideUp();
          }
        },
      });
    }
  });

  // --- Helper Functions ---
  function shuffleArray(array) {
    for (let i = array.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [array[i], array[j]] = [array[j], array[i]];
    }
    return array;
  }

  var questionCache = {};

  function shuffleArray(array) {
    for (let i = array.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [array[i], array[j]] = [array[j], array[i]];
    }
    return array;
  }

  function renderQuestion(data, questionID) {
    clearInterval(questionTimer);
    var questionData = data.question;
    var previousState = answeredStates[questionID] || {}; // Use empty object as default

    // 1. Reset UI from a clean slate
    $("#qp-revision-indicator, #qp-reported-indicator, .qp-direction, #qp-question-source").hide();
    var optionsArea = $('.qp-options-area').empty().removeClass('disabled');
    $("#qp-skip-btn, #qp-report-btn").prop("disabled", false);
    $("#qp-next-btn").prop("disabled", true);

    // 2. Render all static content, including the meta that was disappearing
    if (data.is_revision) {
        $('#qp-revision-indicator').show();
    }
    $('#qp-mark-for-review-cb').prop('checked', data.is_marked_for_review);

    var directionEl = $('.qp-direction').empty();
    if (questionData.direction_text || questionData.direction_image_url) {
        if (questionData.direction_text) directionEl.html($('<p>').html(questionData.direction_text));
        if (questionData.direction_image_url) directionEl.append($('<img>').attr('src', questionData.direction_image_url).css('max-width', '100%'));
        directionEl.show();
    }

    var subjectHtml = 'Subject: ' + questionData.subject_name;
    if (questionData.topic_name) subjectHtml += ' / ' + questionData.topic_name;
    $('#qp-question-subject').html(subjectHtml); // This was missing
    $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id); // This was missing

    var sourceDisplayArea = $('#qp-question-source').hide().empty();
    if (data.is_admin && (questionData.source_name || questionData.section_name || questionData.question_number_in_section)) {
        var sourceInfo = [];
        if(questionData.source_name) sourceInfo.push('<strong>Source:</strong> ' + questionData.source_name);
        if(questionData.section_name) sourceInfo.push('<strong>Section:</strong> ' + questionData.section_name);
        if(questionData.question_number_in_section) sourceInfo.push('<strong>Q:</strong> ' + questionData.question_number_in_section);
        if(sourceInfo.length > 0) sourceDisplayArea.html(sourceInfo.join(' | ')).show();
    }

    $('#qp-question-text-area').html(questionData.question_text);

    $.each(questionData.options, function (index, option) {
        optionsArea.append($('<label class="option"></label>').append($('<input type="radio" name="qp_option">').val(option.option_id)).append($('<span>').html(option.option_text)));
    });

    // 3. Apply the correct UI state based on server and local data
    // 3. Apply State-Based UI
if (previousState.reported) {
    $("#qp-reported-indicator").show();
    // --- THE FIX: Add the 'disabled' class to the individual option labels ---
    optionsArea.find('.option').addClass('disabled'); 
    optionsArea.find('input[type="radio"]').prop('disabled', true);
    $("#qp-skip-btn, #qp-report-btn").prop("disabled", true);
    $("#qp-next-btn").prop("disabled", false);
} else if (previousState.type === "answered") {
        $('input[value="' + previousState.selected_option_id + '"]').prop('checked', true).closest(".option").addClass(previousState.is_correct ? "correct" : "incorrect");
        if (!previousState.is_correct) {
            $('input[value="' + previousState.correct_option_id + '"]').closest(".option").addClass("correct");
        }
        optionsArea.addClass('disabled').find('input[type="radio"]').prop('disabled', true);
        $("#qp-skip-btn, #qp-report-btn").prop("disabled", true);
        $("#qp-next-btn").prop("disabled", false);
    } else {
        // Default state for a new question
        if (sessionSettings.timer_enabled && (!previousState || previousState.type !== 'skipped')) {
            startTimer(sessionSettings.timer_seconds);
        }
    }

    $("#qp-prev-btn").prop("disabled", currentQuestionIndex === 0);

    // 4. Render Math
    if (typeof renderMathInElement !== 'undefined') {
        renderMathInElement(document.getElementById('qp-practice-app-wrapper'));
    }
}

  function loadQuestion(questionID, direction) {
    var animatableArea = $(".qp-animatable-area");
    function doRender(data) {
      renderQuestion(data, questionID);
      if (direction) {
        var slideInClass = direction === "next" ? "slide-in-from-right" : "slide-in-from-left";
        animatableArea.removeClass("slide-out-to-left slide-out-to-right").addClass(slideInClass);
      }
    }

    if (direction) {
      var slideOutClass = direction === "next" ? "slide-out-to-left" : "slide-out-to-right";
      animatableArea.removeClass("slide-in-from-left slide-in-from-right").addClass(slideOutClass);
    }

    setTimeout(function () {
        // Always fetch fresh data from server to ensure state is correct
        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: "POST",
            data: {
                action: "get_question_data",
                nonce: qp_ajax_object.nonce,
                question_id: questionID,
            },
            success: function (response) {
                if (response.success) {
                    // **THIS IS THE CRITICAL CHANGE**
                    // Update the client-side state from the server's authoritative response
                    if (typeof answeredStates[questionID] === 'undefined') {
                        answeredStates[questionID] = {};
                    }
                    if (response.data.is_reported_by_user) {
                        answeredStates[questionID].reported = true;
                    }

                    // Shuffle options and cache the data
                    response.data.question.options = shuffleArray(response.data.question.options);
                    questionCache[questionID] = response.data;
                    doRender(response.data);
                }
            },
        });
    }, direction ? 300 : 0);
}

  function loadNextQuestion() {
    currentQuestionIndex++;
    if (currentQuestionIndex >= sessionQuestionIDs.length) {
      practiceInProgress = false;
      clearInterval(questionTimer);
      currentQuestionIndex--;
      if (
        confirm(
          "Congratulations, you've completed all available questions! Click OK to end this session and see your summary."
        )
      ) {
        $("#qp-end-practice-btn").click();
      }
      return;
    }
    loadQuestion(sessionQuestionIDs[currentQuestionIndex], "next");
  }

  function updateHeaderStats() {
    $("#qp-score").text(score.toFixed(2));
    $("#qp-correct-count").text(correctCount);
    $("#qp-incorrect-count").text(incorrectCount);
    $("#qp-skipped-count").text(skippedCount);
  }

  function startTimer(seconds) {
    $(".timer-stat").show();
    var remainingTime = seconds;
    function updateDisplay() {
      var minutes = Math.floor(remainingTime / 60);
      var secs = remainingTime % 60;
      $("#qp-timer").text(
        String(minutes).padStart(2, "0") + ":" + String(secs).padStart(2, "0")
      );
    }
    updateDisplay();
    questionTimer = setInterval(function () {
      remainingTime--;
      updateDisplay();
      if (remainingTime <= 0) {
        clearInterval(questionTimer);
        $("#qp-skip-btn").click();
      }
    }, 1000);
  }

  function displaySummary(summaryData) {
    practiceInProgress = false;
    var summaryHtml = `
      <div class="qp-summary-wrapper">
          <h2>Session Summary</h2>
          <div class="qp-summary-score"><div class="label">Final Score</div>${parseFloat(
            summaryData.final_score
          ).toFixed(2)}</div>
          <div class="qp-summary-stats">
              <div class="stat"><div class="value">${
                summaryData.total_attempted
              }</div><div class="label">Attempted</div></div>
              <div class="stat"><div class="value">${
                summaryData.correct_count
              }</div><div class="label">Correct</div></div>
              <div class="stat"><div class="value">${
                summaryData.incorrect_count
              }</div><div class="label">Incorrect</div></div>
              <div class="stat"><div class="value">${
                summaryData.skipped_count
              }</div><div class="label">Skipped</div></div>
          </div>
          <div class="qp-summary-actions">
              <a href="${
                qp_ajax_object.dashboard_page_url
              }" class="qp-button qp-button-secondary">View Dashboard</a>
              <a href="${
                qp_ajax_object.practice_page_url
              }" class="qp-button qp-button-primary">Start Another Practice</a>
          </div>
      </div>`;
    wrapper.html(summaryHtml);
  }

  // Handles clicking an answer option
  wrapper.on("click", ".qp-options-area .option", function () {
    // --- THE FIX: Check if this specific option is disabled before proceeding ---
    if ($(this).hasClass('disabled')) {
        return; // Do nothing if the option is disabled
    }
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    if (
      answeredStates[questionID] &&
      answeredStates[questionID].type === "skipped"
    ) {
      skippedCount--;
    }
    clearInterval(questionTimer);
    var selectedOption = $(this);
    $(".qp-options-area .option").addClass("disabled");
    $("#qp-skip-btn").prop("disabled", true);
    $("#qp-next-btn").prop("disabled", false);

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "check_answer",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
        question_id: questionID,
        option_id: selectedOption.find('input[type="radio"]').val(),
      },
      success: function (response) {
        if (response.success) {
          answeredStates[questionID] = {
            type: "answered",
            is_correct: response.data.is_correct,
            correct_option_id: response.data.correct_option_id,
            selected_option_id: selectedOption
              .find('input[type="radio"]')
              .val(),
            reported_as: answeredStates[questionID]?.reported_as || [],
          };
          if (response.data.is_correct) {
            selectedOption.addClass("correct");
            score += parseFloat(sessionSettings.marks_correct);
            correctCount++;
          } else {
            selectedOption.addClass("incorrect");
            $('input[value="' + response.data.correct_option_id + '"]')
              .closest(".option")
              .addClass("correct");
            score += parseFloat(sessionSettings.marks_incorrect);
            incorrectCount++;
          }
          updateHeaderStats();
        }
      },
    });
  });

  wrapper.on("click", "#qp-next-btn, #qp-prev-btn", function () {
    clearInterval(questionTimer);
    var direction = $(this).attr("id") === "qp-next-btn" ? "next" : "prev";
    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "update_session_activity",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
      },
    });

    if (direction === "next") {
      loadNextQuestion();
    } else {
      if (currentQuestionIndex > 0) {
        currentQuestionIndex--;
        loadQuestion(sessionQuestionIDs[currentQuestionIndex], "prev");
      }
    }
  });

  wrapper.on("click", "#qp-skip-btn", function () {
    clearInterval(questionTimer);
    var questionID = sessionQuestionIDs[currentQuestionIndex];

    if (
      !answeredStates[questionID] ||
      answeredStates[questionID].type !== "answered"
    ) {
      if (
        !answeredStates[questionID] ||
        answeredStates[questionID].type !== "skipped"
      ) {
        skippedCount++;
      }
      answeredStates[questionID] = { type: "skipped" };
      updateHeaderStats();
      loadNextQuestion();
    }
  });

  wrapper.on("click", "#qp-end-practice-btn", function () {
    practiceInProgress = false;
    clearInterval(questionTimer);
    if (confirm("Are you sure you want to end this practice session?")) {
      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: {
          action: "end_practice_session",
          nonce: qp_ajax_object.nonce,
          session_id: sessionID,
        },
        beforeSend: function () {
          wrapper.html(
            '<p style="text-align:center; padding: 50px;">Generating your results...</p>'
          );
        },
        success: function (response) {
          if (response.success) {
            displaySummary(response.data);
          }
        },
      });
    } else {
      practiceInProgress = true;
    }
  });

  $(window).on("beforeunload", function () {
    if (practiceInProgress) {
      return "Are you sure you want to leave? Your practice session is in progress and will be lost.";
    }
  });
});
