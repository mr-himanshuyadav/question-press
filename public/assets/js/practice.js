jQuery(document).ready(function ($) {
  var wrapper = $("#qp-practice-app-wrapper");

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


  // --- Session Initialization ---
  if (typeof qp_session_data !== 'undefined') {
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

      highestQuestionIndexReached = lastAttemptedIndex >= 0 ? lastAttemptedIndex + 1 : 0;
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

  // --- NEW: Handler for the "Mark for Review" checkbox ---
wrapper.on("change", "#qp-mark-for-review-cb", function () {
    var checkbox = $(this);
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var isMarked = checkbox.is(':checked');

    // Temporarily disable the checkbox to prevent rapid clicking
    checkbox.prop('disabled', true);

    $.ajax({
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: {
            action: 'qp_toggle_review_later',
            nonce: qp_ajax_object.nonce,
            question_id: questionID,
            is_marked: isMarked
        },
        // --- THIS SUCCESS CALLBACK IS THE FIX ---
        success: function() {
            // Update the cache to reflect the change immediately.
            if (questionCache[questionID]) {
                questionCache[questionID].is_marked_for_review = isMarked;
            }
        },
        // --- END OF FIX ---
        complete: function() {
            // Re-enable the checkbox after the request is complete
            checkbox.prop('disabled', false);
        }
    });
});


  // --- UPDATED: Form submission now handles a redirect ---
  wrapper.on("submit", "#qp-start-practice-form", function (e) {
    e.preventDefault();
    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "start_practice_session",
        nonce: qp_ajax_object.nonce,
        settings: form.serialize(),
      },
      beforeSend: function () {
        submitButton.val("Setting up session...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success && response.data.redirect_url) {
          window.location.href = response.data.redirect_url;
        } else {
          // --- UPDATED ERROR HANDLING ---
          // The backend now sends HTML directly for errors
          if (response.data.html) {
            wrapper.html(response.data.html);
          } else {
            alert(
              "Error: " +
                (response.data.message || "An unknown error occurred.")
            );
            submitButton.val(originalButtonText).prop("disabled", false);
          }
        }
      },
      error: function () {
        alert("A server error occurred. Please try again later.");
        submitButton.val(originalButtonText).prop("disabled", false);
      },
    });
  });

  // Handler for the dynamic topic dropdown
  wrapper.on("change", "#qp_subject", function () {
    var subjectId = $(this).val();
    var topicGroup = $("#qp-topic-group");
    var topicSelect = $("#qp_topic");
    var sectionGroup = $("#qp-section-group");
    var sectionSelect = $("#qp_section");

    // Always hide and reset the dependent dropdowns first.
    topicGroup.slideUp();
    sectionGroup.slideUp(); // This is key to hiding it again
    topicSelect.prop("disabled", true).html("<option value=''>-- Select a subject first --</option>");
    sectionSelect.prop("disabled", true).html("<option value=''>-- Select a subject first --</option>");

    // Only proceed if a specific subject is chosen
    if (subjectId && subjectId !== "all") {
        
        // --- Populate the TOPIC dropdown ---
        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: "POST",
            data: { action: "get_topics_for_subject", nonce: qp_ajax_object.nonce, subject_id: subjectId },
            beforeSend: function () {
                topicSelect.html("<option>Loading topics...</option>");
            },
            success: function (response) {
                // Show topics if they exist
                if (response.success && response.data.topics.length > 0) {
                    topicGroup.slideDown();
                    topicSelect.prop("disabled", false).empty().append('<option value="all">All Topics</option>');
                    $.each(response.data.topics, function (index, topic) {
                        topicSelect.append($("<option></option>").val(topic.topic_id).text(topic.topic_name));
                    });
                } else {
                    // If there are no topics, we can hide the dropdown
                    topicGroup.slideUp();
                }
            },
        });

        // --- NEW: This AJAX call populates the SECTION dropdown ---
        // It runs in parallel to the topics call, but its container is hidden by default.
        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: "POST",
            data: { action: "get_sections_for_subject", nonce: qp_ajax_object.nonce, subject_id: subjectId },
            success: function(response) {
                if (response.success && response.data.sections.length > 0) {
                    // We only enable the dropdown here, but we don't show it yet.
                    sectionSelect.prop("disabled", false).empty()
                        // Use "All Sections" for clarity
                        .append('<option value="all">All Sections</option>');

                    $.each(response.data.sections, function(index, sec) {
                        var optionText = sec.source_name + ' / ' + sec.section_name;
                        sectionSelect.append($("<option></option>").val(sec.section_id).text(optionText));
                    });
                }
            }
        });
    }
});


  // This will control the visibility of the Section dropdown.
wrapper.on("change", "#qp_topic", function() {
    var topicId = $(this).val();
    var sectionGroup = $("#qp-section-group");

    // If a specific topic is selected, show the section dropdown.
    if (topicId && topicId !== 'all') {
        sectionGroup.slideDown();
    } else {
        // Otherwise, hide it.
        sectionGroup.slideUp();
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

  // Handles clicking an answer option
  wrapper.on("click", ".qp-options-area .option:not(.disabled)", function () {
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    if (answeredStates[questionID] && answeredStates[questionID].type === 'skipped') {
        skippedCount--;
    }
    clearInterval(questionTimer);
    var selectedOption = $(this);
    $('.qp-options-area .option').addClass('disabled');
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
        option_id: selectedOption.find('input[type="radio"]').val()
      },
      success: function (response) {
        if (response.success) {
          answeredStates[questionID] = {
            type: "answered",
            is_correct: response.data.is_correct,
            correct_option_id: response.data.correct_option_id,
            selected_option_id: selectedOption.find('input[type="radio"]').val(),
            reported_as: answeredStates[questionID]?.reported_as || [],
          };
          if (response.data.is_correct) {
            selectedOption.addClass("correct");
            score += parseFloat(sessionSettings.marks_correct);
            correctCount++;
          } else {
            selectedOption.addClass("incorrect");
            $('input[value="' + response.data.correct_option_id + '"]').closest(".option").addClass("correct");
            score += parseFloat(sessionSettings.marks_incorrect);
            incorrectCount++;
          }
          updateHeaderStats();
        }
      },
    });
  });

  // --- Helper Functions ---
  function shuffleArray(array) {
    for (let i = array.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [array[i], array[j]] = [array[j], array[i]];
    }
    return array;
  }


  // Handles navigation clicks
  wrapper.on("click", "#qp-next-btn, #qp-prev-btn", function () {
    clearInterval(questionTimer);
    var direction = $(this).attr("id") === "qp-next-btn" ? 'next' : 'prev';
    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "update_session_activity",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
      }
    });

    if (direction === 'next') {
      loadNextQuestion();
    } else {
      if (currentQuestionIndex > 0) {
        currentQuestionIndex--;
        loadQuestion(sessionQuestionIDs[currentQuestionIndex], 'prev');
      }
    }
  });

  // --- CORRECTED: Handler for Skip and Report buttons ---
  wrapper.on("click", "#qp-skip-btn, .qp-report-button", function () {
    clearInterval(questionTimer);
    var $button = $(this);
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var isSkipAction = $button.attr("id") === "qp-skip-btn";

    if (isSkipAction) {
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
      return;
    }

    var isAdminAction = $button.closest(".qp-admin-report-area").length > 0;
    var ajaxAction = isAdminAction
      ? "report_question_issue"
      : "report_and_skip_question";

    $button.prop("disabled", true).text("Processing...");

    if (!isAdminAction && answeredStates[questionID]?.type === "answered") {
      if (answeredStates[questionID].is_correct) {
        score -= parseFloat(sessionSettings.marks_correct);
        correctCount--;
      } else {
        score -= parseFloat(sessionSettings.marks_incorrect);
        incorrectCount--;
      }
    }

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: ajaxAction,
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
        question_id: questionID,
        label_name: $button.data("label") || "",
      },
      success: function (response) {
        if (response.success) {
          answeredStates[questionID] = answeredStates[questionID] || {};
          answeredStates[questionID].reported_as =
            answeredStates[questionID].reported_as || [];
          if (
            $button.data("label") &&
            answeredStates[questionID].reported_as.indexOf(
              $button.data("label")
            ) === -1
          ) {
            answeredStates[questionID].reported_as.push($button.data("label"));
          }

          if (isAdminAction) {
            loadQuestion(questionID);
          } else {
            if (answeredStates[questionID]?.type !== "skipped") {
              if (answeredStates[questionID]?.type !== "answered") {
                skippedCount++;
              }
            }
            answeredStates[questionID].type = "skipped";
            updateHeaderStats();
            loadNextQuestion();
          }
        } else {
          alert("Error: " + (response.data.message || "An error occurred."));
          $button
            .prop("disabled", false)
            .text($button.data("label") || "Report");
        }
      },
    });
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

  // --- Helper Functions ---

  // --- NEW STATE VARIABLE FOR CACHING ---
  var questionCache = {};

  // --- Helper Functions ---
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
    
    $("#qp-revision-indicator, .qp-direction, #qp-reported-indicator, #qp-question-source").hide();
    $(".qp-report-button").text(function () { return $(this).data("label"); }).prop("disabled", false);
    $(".qp-footer-nav button").prop("disabled", false);

    var previousState = answeredStates[questionID];
    
    if (sessionSettings.revise_mode && data.is_revision) { $('#qp-revision-indicator').show(); }

    $('#qp-mark-for-review-cb').prop('checked', data.is_marked_for_review);

    var directionEl = $('.qp-direction');
    directionEl.empty().hide();
    if (questionData.direction_text || questionData.direction_image_url) {
        if (questionData.direction_text) directionEl.html($('<p>').html(questionData.direction_text));
        if (questionData.direction_image_url) directionEl.append($('<img>').attr('src', questionData.direction_image_url).css('max-width', '100%'));
        directionEl.show();
    }
    var subjectHtml = 'Subject: ' + questionData.subject_name;
    if (questionData.topic_name) subjectHtml += ' / ' + questionData.topic_name;
    $('#qp-question-subject').html(subjectHtml);
    $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id);

    var sourceDisplayArea = $('#qp-question-source').hide().empty();
    if (data.is_admin && (questionData.source_name || questionData.section_name || questionData.question_number_in_section)) {
        var sourceInfo = [];
        if (questionData.source_name) sourceInfo.push('<strong>Source:</strong> ' + questionData.source_name);
        if (questionData.section_name) sourceInfo.push('<strong>Section:</strong> ' + questionData.section_name);
        if (questionData.question_number_in_section) sourceInfo.push('<strong>Q:</strong> ' + questionData.question_number_in_section);
        if (sourceInfo.length > 0) sourceDisplayArea.html(sourceInfo.join(' | ')).show();
    }
    $('#qp-question-text-area').html(questionData.question_text);

    var optionsArea = $('.qp-options-area');
    optionsArea.empty();
    $.each(questionData.options, function (index, option) {
        var optionHtml = $('<label class="option"></label>')
            .append($('<input type="radio" name="qp_option">').val(option.option_id), ' ')
            .append($('<span>').html(option.option_text));
        if (previousState && previousState.type === "answered" && previousState.selected_option_id == option.option_id) {
            optionHtml.find("input").prop("checked", true);
        }
        optionsArea.append(optionHtml);
    });

    if (previousState && previousState.type === "answered") {
        $("#qp-next-btn").prop("disabled", false);
        $("#qp-skip-btn").prop("disabled", true);
        $(".qp-options-area .option").addClass("disabled");
        $('.qp-options-area .option input[type="radio"]').prop('disabled', true);
        if (previousState.is_correct) {
            $('input[value="' + previousState.selected_option_id + '"]').closest(".option").addClass("correct");
        } else {
            $('input[value="' + previousState.selected_option_id + '"]').closest(".option").addClass("incorrect");
            $('input[value="' + previousState.correct_option_id + '"]').closest(".option").addClass("correct");
        }
    } else {
        $("#qp-next-btn").prop("disabled", true);
        $("#qp-skip-btn").prop("disabled", false);
        if (sessionSettings.timer_enabled && (!previousState || previousState.type !== 'skipped')) {
            startTimer(sessionSettings.timer_seconds);
        }
    }

    if (previousState && previousState.reported_as && previousState.reported_as.length > 0) {
        $("#qp-reported-indicator").show();
        previousState.reported_as.forEach(function (labelName) {
            $('.qp-report-button[data-label="' + labelName + '"]').prop("disabled", true).text("Reported");
        });
    }

    $("#qp-prev-btn").prop("disabled", currentQuestionIndex === 0);
    
    if (typeof renderMathInElement !== 'undefined') {
        var elementsToRender = [directionEl.get(0), document.getElementById('qp-question-text-area'), document.getElementById('qp-question-source'), ...optionsArea.find('.option').toArray()];
        elementsToRender.forEach(function(element) {
            if (element) { 
                renderMathInElement(element, { delimiters: [{left: '$$', right: '$$', display: true}, {left: '$', right: '$', display: false}, {left: '\\[', right: '\\]', display: true}, {left: '\\(', right: '\\)', display: false}], throwOnError: false });
            }
        });
    }
  }

  function loadQuestion(questionID, direction) {
    var animatableArea = $('.qp-animatable-area');
    function doRender(data) {
        renderQuestion(data, questionID);
        if (direction) {
            var slideInClass = direction === 'next' ? 'slide-in-from-right' : 'slide-in-from-left';
            animatableArea.removeClass('slide-out-to-left slide-out-to-right').addClass(slideInClass);
        }
    }
    if (direction) {
        var slideOutClass = direction === 'next' ? 'slide-out-to-left' : 'slide-out-to-right';
        animatableArea.removeClass('slide-in-from-left slide-in-from-right').addClass(slideOutClass);
    }
    setTimeout(function() {
        if (questionCache[questionID]) {
            doRender(questionCache[questionID]);
        } else {
            $.ajax({
                url: qp_ajax_object.ajax_url,
                type: "POST",
                data: { action: "get_question_data", nonce: qp_ajax_object.nonce, question_id: questionID },
                success: function (response) {
                    if (response.success) {
                        var dataToCache = response.data;
                        dataToCache.question.options = shuffleArray(dataToCache.question.options);
                        questionCache[questionID] = dataToCache;
                        doRender(dataToCache);
                    }
                },
            });
        }
    }, direction ? 300 : 0);
  }

  function loadNextQuestion() {
    currentQuestionIndex++;
    if (currentQuestionIndex >= sessionQuestionIDs.length) {
      practiceInProgress = false;
      clearInterval(questionTimer);
      currentQuestionIndex--;
      if (confirm("Congratulations, you've completed all available questions! Click OK to end this session and see your summary.")) {
        $("#qp-end-practice-btn").click();
      }
      return;
    }
    loadQuestion(sessionQuestionIDs[currentQuestionIndex], 'next');
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
        // Automatically skip to next question on timeout
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
});
