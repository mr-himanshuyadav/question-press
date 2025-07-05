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

  // NEWLY RESTORED: Logic for the timer checkbox on the settings form
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
        if (response.success) {
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
          practiceInProgress = false;
          wrapper.html(
            '<p style="text-align:center; color: red;">Error: ' +
              response.data.message +
              "</p>"
          );
        }
      },
    });
  });

  // Handles clicking an answer option
  wrapper.on("click", ".qp-options-area .option:not(.disabled)", function () {
    clearInterval(questionTimer);
    var selectedOption = $(this);
    var selectedOptionID = selectedOption.find('input[type="radio"]').val();
    var questionID = sessionQuestionIDs[currentQuestionIndex];

    $(".qp-options-area .option, #qp-skip-btn").prop("disabled", true);
    $("#qp-next-btn").prop("disabled", false);

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "check_answer",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
        question_id: questionID,
        option_id: selectedOptionID,
      },
      success: function (response) {
        if (response.success) {
          answeredStates[questionID] = {
            type: "answered",
            is_correct: response.data.is_correct,
            correct_option_id: response.data.correct_option_id,
            selected_option_id: selectedOptionID,
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
  wrapper.on(
    "click",
    "#qp-skip-btn, .qp-user-report-btn, .qp-admin-report-btn",
    function () {
      clearInterval(questionTimer);
      var $button = $(this);
      var questionID = sessionQuestionIDs[currentQuestionIndex];
      var isAdminAction = $button.hasClass("qp-admin-report-btn");
      var isSkipAction = $button.attr("id") === "qp-skip-btn";
      var ajaxAction = "report_and_skip_question"; // Default action

      if (isAdminAction) ajaxAction = "report_question_issue";
      if (isSkipAction) ajaxAction = "skip_question";

      $button.prop("disabled", true);
      if ($button.data("label")) $button.text("Processing...");

      // If a user reports a question they already answered, we must reverse the score.
      if (
        !isAdminAction &&
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
    $("#qp-revision-indicator, .qp-direction, #qp-reported-indicator").hide();
    $(".qp-user-report-btn, .qp-admin-report-btn").text(function () {
      return $(this).data("label");
    });
    $(".qp-footer-nav button, .qp-user-report-btn, .qp-admin-report-btn").prop(
      "disabled",
      false
    ); // Enable all buttons by default

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

        // --- Step 1: Insert the new, clean content into the page ---
        if (sessionSettings.revise_mode && response.data.is_revision) { $('#qp-revision-indicator').show(); }

        // Use .html() to ensure raw LaTeX is preserved
        var directionEl = $('.qp-direction');
        if (questionData.direction_text) {
            directionEl.html($('<p>').html(questionData.direction_text)).show();
        }
        if (questionData.direction_image_url) {
            directionEl.append($('<img>').attr('src', questionData.direction_image_url).css('max-width', '100%'));
        }

        $('#qp-question-subject').text('Subject: ' + questionData.subject_name);
        $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id);
        $('#qp-question-text-area').html(questionData.question_text);

        var optionsArea = $('.qp-options-area');
        optionsArea.empty();
        $.each(questionData.options, function(index, option) {
            var optionHtml = $('<label class="option"></label>')
                .append($('<input type="radio" name="qp_option">').val(option.option_id), ' ')
                .append($('<span>').html(option.option_text)); // Use .html() here too
            optionsArea.append(optionHtml);
        });

        // --- Step 2: Render LaTeX in each specific, newly added element ---
        if (typeof renderMathInElement !== 'undefined') {
            // This is the key change: render each part individually.
            var elementsToRender = [
                directionEl.get(0),
                document.getElementById('qp-question-text-area'),
                ...optionsArea.find('.option').toArray()
            ];

            elementsToRender.forEach(function(element) {
                if (element) { // Ensure the element exists before trying to render
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

          var optionsArea = $(".qp-options-area");
          optionsArea.empty();
          $.each(questionData.options, function (index, option) {
            // --- FIX: Un-escape backslashes for KaTeX ---
            if (option.option_text) {
              option.option_text = option.option_text.replace(/\\\\/g, "\\");
            }
            // --- END OF FIX ---
            var optionHtml = $('<label class="option"></label>')
              .append(
                $('<input type="radio" name="qp_option">').val(
                  option.option_id
                ),
                " "
              )
              .append($("<span>").html(option.option_text));
            if (
              previousState &&
              previousState.type === "answered" &&
              previousState.selected_option_id == option.option_id
            ) {
              optionHtml.find("input").prop("checked", true);
            }
            optionsArea.append(optionHtml);
          });

          // Manage button states and display previous results
          if (previousState) {
            $("#qp-next-btn").prop("disabled", false);
            $(".qp-options-area .option").addClass("disabled");

            if (previousState.type === "answered") {
              $("#qp-skip-btn").prop("disabled", true); // Can't skip after answering
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
              $("#qp-skip-btn, .qp-user-report-btn, .qp-admin-report-btn").prop(
                "disabled",
                true
              ); // Disable all actions if skipped
            }

            // ONLY show the reported indicator if a report was actually filed
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
            $("#qp-next-btn").prop("disabled", true); // Next is disabled for new questions
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
                    <a href="/dashboard/" class="qp-button qp-button-secondary">View Dashboard</a>
                    <a href="" class="qp-button qp-button-primary">Start Another Practice</a>
                </div>
            </div>`;
    wrapper.html(summaryHtml);
  }
});
