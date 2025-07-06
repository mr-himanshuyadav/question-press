jQuery(document).ready(function ($) {
  var wrapper = $("#qp-practice-app-wrapper");

  // Session state variables
  var sessionID = 0;
  var sessionQuestionIDs = [];
  var currentQuestionIndex = 0;
  var sessionSettings = {};
  var score = 0;
  var correctCount = 0;
  var incorrectCount = 0;
  var skippedCount = 0;
  var questionTimer;
  var answeredStates = {}; // Stores the state for each question ID
  var practiceInProgress = false;

  // --- Event Handlers ---

  // Logic for the timer checkbox on the settings form
  wrapper.on("change", "#qp_timer_enabled_cb", function () {
    if ($(this).is(":checked")) {
      $("#qp-timer-input-wrapper").slideDown();
    } else {
      $("#qp-timer-input-wrapper").slideUp();
    }
  });

  // Handles form submission to start a session
  wrapper.on("submit", "#qp-start-practice-form", function (e) {
    e.preventDefault();
    practiceInProgress = true;
    var formData = $(this).serialize();
    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "start_practice_session",
        nonce: qp_ajax_object.nonce,
        settings: formData,
      },
      beforeSend: function () {
        wrapper.html(
          '<p style="text-align:center; padding: 50px;">Setting up your session...</p>'
        );
      },
      success: function (response) {
        practiceInProgress = false; // Reset flag on response
        if (response.success) {
          practiceInProgress = true; // Set flag only on successful session start
          wrapper.html(response.data.ui_html);
          sessionID = response.data.session_id;
          sessionQuestionIDs = response.data.question_ids;
          sessionSettings = response.data.settings;
          currentQuestionIndex = 0;
          score = 0;
          correctCount = 0;
          incorrectCount = 0;
          skippedCount = 0;
          answeredStates = {};
          updateHeaderStats();
          loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
        } else {
          var errorCode = response.data.error_code;
          var errorMessage = '';

          if (errorCode === 'ALL_ATTEMPTED') {
            errorMessage = `
              <div class="qp-practice-form-wrapper" style="text-align: center;">
                <h2>You've Mastered It!</h2>
                <p style="margin: 20px 0;">You have attempted all of the available questions for this criteria. Try Revision Mode to practice them again.</p>
                <button id="qp-try-revision-btn" class="qp-button qp-button-primary">Try Revision Mode</button>
              </div>`;
          } else if (errorCode === 'NO_QUESTIONS_EXIST') {
            errorMessage = `
              <div class="qp-practice-form-wrapper" style="text-align: center;">
                <h2>Fresh Questions Coming Soon!</h2>
                <p style="margin: 20px 0;">We are adding more questions to your selected practice area soon. Try different options and subjects.</p>
                <button id="qp-go-back-btn" class="qp-button qp-button-secondary">Back to Practice Form</button>
              </div>`;
          } else {
            errorMessage = '<p style="text-align:center; color: red;">Error: ' + (response.data.message || 'An unknown error occurred.') + "</p>";
          }
          wrapper.html(errorMessage);
        }
      },
      error: function() {
        practiceInProgress = false;
        wrapper.html('<p style="text-align:center; color: red;">A server error occurred. Please try again later.</p>');
      }
    });
  });

  // *** NEW: Handler for the dynamic topic dropdown ***
  wrapper.on('change', '#qp_subject', function() {
    var subjectId = $(this).val();
    var topicGroup = $('#qp-topic-group');
    var topicSelect = $('#qp_topic');

    if (subjectId && subjectId !== 'all') {
        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_topics_for_subject',
                nonce: qp_ajax_object.nonce,
                subject_id: subjectId
            },
            beforeSend: function() {
                topicSelect.prop('disabled', true).html('<option>Loading topics...</option>');
                topicGroup.slideDown();
            },
            success: function(response) {
                if (response.success && response.data.topics.length > 0) {
                    topicSelect.prop('disabled', false).empty();
                    topicSelect.append('<option value="all">All Topics</option>');
                    $.each(response.data.topics, function(index, topic) {
                        topicSelect.append($('<option></option>').val(topic.topic_id).text(topic.topic_name));
                    });
                } else {
                    topicSelect.prop('disabled', true).html('<option value="all">No topics found</option>');
                }
            }
        });
    } else {
        topicGroup.slideUp();
        topicSelect.prop('disabled', true).html('<option>-- Select a subject first --</option>');
    }
  });
  
  // Handlers for the new error screen buttons
  wrapper.on('click', '#qp-try-revision-btn, #qp-go-back-btn', function() {
    var isRevision = $(this).attr('id') === 'qp-try-revision-btn';
    $.ajax({
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: { action: 'get_practice_form_html', nonce: qp_ajax_object.nonce },
        beforeSend: function() {
            wrapper.html('<p style="text-align:center; padding: 50px;">Loading form...</p>');
        },
        success: function(response) {
            if (response.success) {
                wrapper.html(response.data.form_html);
                if (isRevision) {
                    wrapper.find('input[name="qp_revise_mode"]').prop('checked', true);
                }
            }
        }
    });
  });


  // Handles clicking an answer option
  wrapper.on("click", ".qp-options-area .option:not(.disabled)", function () {
    clearInterval(questionTimer);
    var selectedOption = $(this);
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    
    // Disable all options immediately
    $('.qp-options-area .option').addClass('disabled');
    $('.qp-options-area .option input[type="radio"]').prop('disabled', true);
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

  // Handles navigation clicks
  wrapper.on("click", "#qp-next-btn, #qp-prev-btn", function () {
    clearInterval(questionTimer);
    if ($(this).attr("id") === "qp-next-btn") {
      loadNextQuestion();
    } else {
      if (currentQuestionIndex > 0) {
        currentQuestionIndex--;
        loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
      }
    }
  });

  // UNIFIED HANDLER for Skip and all Report buttons
  wrapper.on("click", "#qp-skip-btn, .qp-report-button", function () {
      clearInterval(questionTimer);
      var $button = $(this);
      var questionID = sessionQuestionIDs[currentQuestionIndex];
      var isAdminAction = $button.closest('.qp-admin-report-area').length > 0;
      var isSkipAction = $button.attr("id") === "qp-skip-btn";
      var ajaxAction;

      if(isSkipAction) {
        ajaxAction = "skip_question";
      } else if (isAdminAction) {
        ajaxAction = "report_question_issue";
      } else {
        ajaxAction = "report_and_skip_question";
      }

      $button.prop("disabled", true);
      if ($button.data("label")) $button.text("Processing...");

      // If a user reports a question they already answered, we must reverse the score.
      if (
        !isAdminAction && !isSkipAction &&
        answeredStates[questionID] &&
        answeredStates[questionID].type === "answered"
      ) {
        if (answeredStates[questionID].is_correct) {
          score -= parseFloat(sessionSettings.marks_correct);
          correctCount--;
        } else {
          score -= parseFloat(sessionSettings.marks_incorrect);
          incorrectCount--;
        }
      }

      var ajaxData = {
        action: ajaxAction,
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
        question_id: questionID,
        label_name: $button.data("label") || "",
      };

      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: ajaxData,
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
              answeredStates[questionID].reported_as.push(
                $button.data("label")
              );
            }

            if (isAdminAction) {
              // Admin action just labels. Reload the same question to show the disabled button.
              loadQuestion(questionID);
            } else {
              // User action always counts as a skip.
              if (
                !answeredStates[questionID] ||
                answeredStates[questionID].type !== "skipped"
              ) {
                if (answeredStates[questionID].type !== "answered")
                  skippedCount++;
              }
              answeredStates[questionID].type = "skipped";
              updateHeaderStats();
              loadNextQuestion();
            }
          } else {
            alert("Error: " + (response.data.message || "An error occurred."));
            $button
              .prop("disabled", false)
              .text($button.data("label") || "Skip");
          }
        },
      });
    }
  );

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
  function loadQuestion(questionID) {
    if (!questionID) return;

    // Reset UI elements for the new question
    $("#qp-question-text-area").html("Loading...");
    $(".qp-options-area").empty();
    $("#qp-revision-indicator, .qp-direction, #qp-reported-indicator, #qp-question-source").hide();
    $(".qp-report-button").text(function () {
      return $(this).data("label");
    });
    $(".qp-footer-nav button, .qp-report-button").prop(
      "disabled",
      false
    ); 

    var previousState = answeredStates[questionID];

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "get_question_data",
        nonce: qp_ajax_object.nonce,
        question_id: questionID,
      },
      success: function (response) {
        if(response.success) {
            var questionData = response.data.question;

            if (sessionSettings.revise_mode && response.data.is_revision) { $('#qp-revision-indicator').show(); }

            var directionEl = $('.qp-direction');
            directionEl.empty().hide(); 
            if (questionData.direction_text) {
                directionEl.html($('<p>').html(questionData.direction_text));
            }
            if (questionData.direction_image_url) {
                directionEl.append($('<img>').attr('src', questionData.direction_image_url).css('max-width', '100%'));
            }
            if (questionData.direction_text || questionData.direction_image_url) {
                directionEl.show();
            }


            $('#qp-question-subject').text('Subject: ' + questionData.subject_name);
            $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id);

            var subjectHtml = 'Subject: ' + questionData.subject_name;
            if (questionData.topic_name) {
                subjectHtml += ' / ' + questionData.topic_name;
            }
            $('#qp-question-subject').html(subjectHtml);
            
            // Handle source display for admins
            if(response.data.is_admin && (questionData.source_file || questionData.source_page || questionData.source_number)) {
                var sourceInfo = [];
                if (questionData.source_file) sourceInfo.push('File: ' + questionData.source_file);
                if (questionData.source_page) sourceInfo.push('Page: ' + questionData.source_page);
                if (questionData.source_number) sourceInfo.push('No: ' + questionData.source_number);

                if (sourceInfo.length > 0) {
                    $('#qp-question-source').html(sourceInfo.join(' | ')).show();
                }
            }

            $('#qp-question-text-area').html(questionData.question_text);

            var optionsArea = $('.qp-options-area');
            optionsArea.empty();
            $.each(questionData.options, function(index, option) {

                var optionHtml = $('<label class="option"></label>')
                    .append($('<input type="radio" name="qp_option">').val(option.option_id), ' ')
                    .append($('<span>').html(option.option_text)); 

                if (
                  previousState &&
                  previousState.type === "answered" &&
                  previousState.selected_option_id == option.option_id
                ) {
                  optionHtml.find("input").prop("checked", true);
                }
                optionsArea.append(optionHtml);
            });

            if (typeof renderMathInElement !== 'undefined') {
                var elementsToRender = [
                    directionEl.get(0),
                    document.getElementById('qp-question-text-area'),
                    document.getElementById('qp-question-source'),
                    ...optionsArea.find('.option').toArray()
                ];

                elementsToRender.forEach(function(element) {
                    if (element) { 
                         renderMathInElement(element, {
                            delimiters: [
                                {left: '$$', right: '$$', display: true},
                                {left: '$', right: '$', display: false},
                                {left: '\\[', right: '\\]', display: true},
                                {left: '\\(', right: '\\)', display: false}
                            ],
                            throwOnError: false
                        });
                    }
                });
            }

              if (previousState) {
                $("#qp-next-btn").prop("disabled", false);
                $(".qp-options-area .option").addClass("disabled");
                $('.qp-options-area .option input[type="radio"]').prop('disabled', true);

                if (previousState.type === "answered") {
                  $("#qp-skip-btn").prop("disabled", true); 
                  if (previousState.is_correct) {
                    $('input[value="' + previousState.selected_option_id + '"]')
                      .closest(".option")
                      .addClass("correct");
                  } else {
                    $('input[value="' + previousState.selected_option_id + '"]')
                      .closest(".option")
                      .addClass("incorrect");
                    $('input[value="' + previousState.correct_option_id + '"]')
                      .closest(".option")
                      .addClass("correct");
                  }
                } else if (previousState.type === "skipped") {
                  $("#qp-skip-btn, .qp-report-button").prop(
                    "disabled",
                    true
                  ); 
                }
                
                if (
                  previousState.reported_as &&
                  previousState.reported_as.length > 0
                ) {
                  $("#qp-reported-indicator").show();
                  previousState.reported_as.forEach(function (labelName) {
                    $('.qp-report-button[data-label="' + labelName + '"]')
                      .prop("disabled", true)
                      .text("Reported");
                  });
                }
              } else {
                $("#qp-next-btn").prop("disabled", true);
                if (sessionSettings.timer_enabled) {
                  startTimer(sessionSettings.timer_seconds);
                }
              }

              $("#qp-prev-btn").prop("disabled", currentQuestionIndex === 0);
        }
      },
    });
}

  function loadNextQuestion() {
    currentQuestionIndex++;
    if (currentQuestionIndex >= sessionQuestionIDs.length) {
      practiceInProgress = false;
      clearInterval(questionTimer);
      if (
        confirm(
          "Congratulations, you've completed all available questions! Click OK to end this session and see your summary."
        )
      ) {
        $("#qp-end-practice-btn").click();
      }
      return;
    }
    loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
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
        if (
          confirm(
            "Time's up for this question! Click OK to move to the next question."
          )
        ) {
          $("#qp-skip-btn").click();
        }
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
                    <a href="${qp_ajax_object.dashboard_page_url}" class="qp-button qp-button-secondary">View Dashboard</a>
                    <a href="${qp_ajax_object.practice_page_url}" class="qp-button qp-button-primary">Start Another Practice</a>
                </div>
            </div>`;
    wrapper.html(summaryHtml);
  }
});