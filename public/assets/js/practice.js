jQuery(document).ready(function ($) {
  var wrapper = $("#qp-practice-app-wrapper");
  var isAutoCheckEnabled = false;
  var mockTestTimer; // Specific timer for mock tests
  var isMockTest = false;
  var isRevisionMode = false;
  var paletteGrids = $(
    "#qp-palette-docked .qp-palette-grid, #qp-palette-sliding .qp-palette-grid"
  );
  var unattemptedCounts = {};

  // --- Fetch unattempted counts if the setting is enabled ---
  if (typeof qp_practice_settings !== "undefined" && qp_practice_settings.show_counts) {
    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "get_unattempted_counts",
        nonce: qp_ajax_object.nonce,
      },
      success: function (response) {
        if (response.success) {
          unattemptedCounts = response.data.counts;
          // Re-render the initial subject list with counts
          var subjectList = $(
            "#qp_subject_dropdown .qp-multi-select-list, #qp_subject_dropdown_revision .qp-multi-select-list, #qp_subject_dropdown_mock .qp-multi-select-list"
          );
          subjectList.find("label").each(function () {
            var $label = $(this);
            var $checkbox = $label.find("input");
            var subjectId = $checkbox.val();
            var count = unattemptedCounts.by_subject[subjectId] || 0;
            if (subjectId !== "all" && count > 0) {
              $label.append(" (" + count + ")");
            }
          });
        }
      },
    });
  }

  function openFullscreen() {
    var elem = document.documentElement; // Get the root element (the whole page)
    if (elem.requestFullscreen) {
      elem.requestFullscreen();
    } else if (elem.mozRequestFullScreen) {
      /* Firefox */
      elem.mozRequestFullScreen();
    } else if (elem.webkitRequestFullscreen) {
      /* Chrome, Safari and Opera */
      elem.webkitRequestFullscreen();
    } else if (elem.msRequestFullscreen) {
      /* IE/Edge */
      elem.msRequestFullscreen();
    }
  }

  function closeFullscreen() {
    if (document.exitFullscreen) {
      document.exitFullscreen();
    } else if (document.mozCancelFullScreen) {
      /* Firefox */
      document.mozCancelFullScreen();
    } else if (document.webkitExitFullscreen) {
      /* Chrome, Safari and Opera */
      document.webkitExitFullscreen();
    } else if (document.msExitFullscreen) {
      /* IE/Edge */
      document.msExitFullscreen();
    }
  }

  // --- NEW: Fullscreen Button State and Tooltip Updater ---
  function updateFullscreenButton() {
    var $btn = $("#qp-fullscreen-btn");
    var $icon = $btn.find(".dashicons");

    // Check the browser's fullscreen status
    if (
      document.fullscreenElement ||
      document.webkitFullscreenElement ||
      document.mozFullScreenElement ||
      document.msFullscreenElement
    ) {
      $btn.attr("title", "Exit Fullscreen");
      $icon
        .removeClass("dashicons-fullscreen-alt")
        .addClass("dashicons-fullscreen-exit-alt");
    } else {
      $btn.attr("title", "Enter Fullscreen");
      $icon
        .removeClass("dashicons-fullscreen-exit-alt")
        .addClass("dashicons-fullscreen-alt");
    }
  }

  // Listen for any change in the browser's fullscreen state
  $(document).on(
    "fullscreenchange webkitfullscreenchange mozfullscreenchange MSFullscreenChange",
    function () {
      updateFullscreenButton();
    }
  );

  // Call the function once on load to set the correct initial state
  updateFullscreenButton();

  function startMockTestTimer(endTimeUTC) {
    var timerEl = $("#qp-mock-test-timer");
    var warningMessage = $("#qp-timer-warning-message"); // Get the warning message element

    // Clear any previous timer to prevent duplicates
    if (mockTestTimer) {
      clearInterval(mockTestTimer);
    }

    mockTestTimer = setInterval(function () {
      var nowUTC = Math.floor(new Date().getTime() / 1000);
      var secondsRemaining = endTimeUTC - nowUTC;

      if (secondsRemaining < 0) {
        secondsRemaining = 0; // Prevent negative display
      }

      // *** THIS IS THE FIX ***
      // Show/hide the warning message and apply the warning class to the timer
      if (secondsRemaining <= 300) {
        // 5 minutes = 300 seconds
        timerEl.addClass("timer-warning");
        warningMessage.show(); // This line makes the message appear
      } else {
        timerEl.removeClass("timer-warning");
        warningMessage.hide(); // This line hides it when there's more than 5 mins left
      }

      // Format the time conditionally
      var hours = Math.floor(secondsRemaining / 3600);
      var minutes = Math.floor((secondsRemaining % 3600) / 60);
      var seconds = secondsRemaining % 60;
      var timeString = "";

      if (hours > 0) {
        timeString += String(hours).padStart(2, "0") + ":";
      }

      timeString +=
        String(minutes).padStart(2, "0") +
        ":" +
        String(seconds).padStart(2, "0");

      timerEl.text(timeString);

      // Stop the timer and auto-submit when time is up
      if (secondsRemaining === 0) {
        clearInterval(mockTestTimer);

        // Check if any questions have been answered in this mock test
        var totalAttempts = Object.values(answeredStates).filter(function (
          state
        ) {
          return state.selected_option_id;
        }).length;

        if (totalAttempts === 0) {
          // If no attempts, show the "not saved" message
          Swal.fire({
            title: "Time's Up!",
            text: "You didn't attempt any questions, so this session will not be saved.",
            icon: "info",
            timer: 5000,
            timerProgressBar: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
          }).then(() => {
            endSession(true); // This will trigger the backend to delete the session
          });
        } else {
          // If there are attempts, show the standard submission message
          Swal.fire({
            title: "Time's Up!",
            text: "Your test will be submitted automatically.",
            icon: "warning",
            timer: 5000,
            timerProgressBar: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
          }).then(() => {
            endSession(true);
          });
        }
      }
    }, 1000);
  }

  // --- NEW: Helper function to update mock test question status ---
  function updateMockStatus(questionID, newStatus) {
    if (!isMockTest) return;

    // --- MODIFICATION START ---
    // Disable the palette buttons for this question to prevent rapid clicks
    var paletteBtn = $(
      '.qp-palette-btn[data-question-index="' +
        sessionQuestionIDs.indexOf(questionID) +
        '"]'
    );
    paletteBtn.css("pointer-events", "none").css("opacity", "0.7");

    // Send the update to the backend
    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "qp_update_mock_status",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
        question_id: questionID,
        status: newStatus,
      },
      success: function (response) {
        if (response.success) {
          // Update local state and re-render the palette ONLY on success
          if (!answeredStates[questionID]) {
            answeredStates[questionID] = {};
          }
          answeredStates[questionID].mock_status = newStatus;
          updatePaletteButton(questionID, newStatus);
          scrollPaletteToCurrent();
          updateLegendCounts();
        } else {
          // If the server returns an error, alert the user
          Swal.fire({
            title: "Error!",
            text: "Could not save your progress. Please check your connection and try again.",
            icon: "error",
          });
        }
      },
      error: function () {
        // If the AJAX call itself fails, alert the user
        Swal.fire({
          title: "Network Error",
          text: "Could not save your progress. Please check your connection.",
          icon: "error",
        });
      },
      complete: function () {
        // Re-enable the button regardless of success or failure
        paletteBtn.css("pointer-events", "auto").css("opacity", "1");
      },
    });
    // --- MODIFICATION END ---
  }

  // --- NEW: Mode-Aware Palette Rendering Function ---
  function renderPalette() {
    paletteGrids.empty();

    // Determine the upper limit for the loop.
    let loopLimit = sessionQuestionIDs.length; // Default to all questions
    const isSectionWise =
      sessionSettings.practice_mode === "Section Wise Practice";

    // For Normal or Revision mode (but NOT section-wise), only show questions up to the furthest point reached.
    if (!isMockTest && !isSectionWise) {
      loopLimit = highestQuestionIndexReached + 1;
      // Safeguard: Don't try to render more buttons than there are questions.
      if (loopLimit > sessionQuestionIDs.length) {
        loopLimit = sessionQuestionIDs.length;
      }
    }

    // Loop up to the determined limit.
    for (let index = 0; index < loopLimit; index++) {
      const questionID = sessionQuestionIDs[index];
      const questionState = answeredStates[questionID] || {};
      let statusClass = "status-not_viewed";

      if (
        questionState.reported_info &&
        questionState.reported_info.has_report
      ) {
        statusClass = "status-reported";
      } else if (isMockTest && questionState.mock_status) {
        statusClass = "status-" + questionState.mock_status;
      } else if (!isMockTest && questionState.type) {
        if (questionState.type === "answered") {
          statusClass = questionState.is_correct
            ? "status-correct"
            : "status-incorrect";
        } else if (questionState.type === "skipped") {
          statusClass = "status-skipped";
        }
      }

      const paletteBtn = $("<button></button>")
        .addClass("qp-palette-btn")
        .attr("data-question-index", index)
        .attr("data-question-id", questionID)
        .text(
          sessionSettings.practice_mode === "Section Wise Practice" &&
            sessionSettings.question_numbers &&
            sessionSettings.question_numbers[questionID]
            ? sessionSettings.question_numbers[questionID]
            : index + 1
        )
        .addClass(statusClass);

      if (index === currentQuestionIndex) {
        paletteBtn.addClass("current");
      }
      paletteGrids.append(paletteBtn);
    }
  }

  // Find and REPLACE the updateLegendCounts function
  function updateLegendCounts() {
    var counts = {
      answered: 0,
      viewed: 0,
      not_viewed: 0,
      marked_for_review: 0,
      answered_and_marked_for_review: 0,
      correct: 0,
      incorrect: 0,
      skipped: 0,
      not_attempted: 0,
      reported: 0, // Initialize reported count to 0
    };

    sessionQuestionIDs.forEach(function (qid) {
      var state = answeredStates[qid];
      if (!state) {
        counts.not_viewed++;
        counts.not_attempted++;
      } else {
        if (state.reported) {
          counts.reported++;
        } else if (isMockTest && state.mock_status) {
          counts[state.mock_status]++;
        } else if (!isMockTest && state.type) {
          if (state.type === "answered") {
            if (state.is_correct) counts.correct++;
            else counts.incorrect++;
          } else if (state.type === "skipped") {
            counts.skipped++;
          }
        }
      }
    });

    for (var status in counts) {
      if (counts.hasOwnProperty(status)) {
        $('.qp-palette-legend .legend-item[data-status="' + status + '"]')
          .find(".legend-count")
          .text("(" + counts[status] + ")");
      }
    }
  }

  // --- NEW: Function to update a single palette button ---
  function updatePaletteButton(questionID, newStatus) {
    // Find the buttons corresponding to this question ID
    const paletteBtns = $(
      '.qp-palette-btn[data-question-id="' + questionID + '"]'
    );

    // Remove all old status classes
    paletteBtns.removeClass(function (index, className) {
      return (className.match(/(^|\s)status-\S+/g) || []).join(" ");
    });

    // Add the new status class
    paletteBtns.addClass("status-" + newStatus);
  }

  // --- NEW: Function to update the highlighted button ---
  function updateCurrentPaletteButton(newIndex, oldIndex) {
    if (oldIndex !== null) {
      $('.qp-palette-btn[data-question-index="' + oldIndex + '"]').removeClass(
        "current"
      );
    }
    $('.qp-palette-btn[data-question-index="' + newIndex + '"]').addClass(
      "current"
    );
  }

  // Handle the "All Topics" checkbox for the mock test form
  $("#qp_topic_list_container_mock").on(
    "change",
    'input[value="all"]',
    function () {
      var $this = $(this);
      var $list = $this.closest(".qp-multi-select-list");
      if ($this.is(":checked")) {
        // Now, it only unchecks the other options, it doesn't disable them.
        $list.find('input[value!="all"]').prop("checked", false);
      }
    }
  );

  // Update button text when a topic is selected in the mock test form
  $("#qp_topic_dropdown_mock").on(
    "change",
    'input[type="checkbox"]',
    function () {
      updateButtonText(
        $("#qp_topic_dropdown_mock .qp-multi-select-button"),
        "-- Select Topic(s) --",
        "Topic"
      );
    }
  );

  // Update button text when a topic is selected in the revision form
  $("#qp_topic_dropdown_revision").on(
    "change",
    'input[type="checkbox"]',
    function () {
      updateButtonText(
        $("#qp_topic_dropdown_revision .qp-multi-select-button"),
        "-- Select Topic(s) --",
        "Topic"
      );
    }
  );

  // Function to update the text on a multi-select button
  function updateButtonText($button, placeholder, singularLabel) {
    var $list = $button.next(".qp-multi-select-list");
    var selected = [];
    $list
      .find('input:checked[value!="all"]')
      .not(".qp-subject-topic-toggle")
      .each(function () {
        selected.push($(this).parent().text().trim());
      });

    if (selected.length === 0) {
      $button.text(placeholder);
    } else if (selected.length === 1) {
      $button.text(selected[0]);
    } else {
      $button.text(selected.length + " " + singularLabel + "s selected");
    }
  }

  // General setup for all multi-select dropdowns
  $(".qp-multi-select-dropdown").each(function () {
    var $dropdown = $(this);
    var $button = $dropdown.find(".qp-multi-select-button");
    var $list = $dropdown.find(".qp-multi-select-list");

    $button.on("click", function (e) {
      e.stopPropagation();
      $(".qp-multi-select-list").not($list).hide(); // Hide others
      $list.toggle();
    });

    $list.on("change", 'input[type="checkbox"]', function () {
      if ($dropdown.attr("id") === "qp_subject_dropdown") {
        updateButtonText($button, "-- Please select --", "Subject");
      } else {
        updateButtonText($button, "-- Please select --", "Topic");
      }
    });
  });

  // --- NEW: FULLY SYNCHRONIZED DROPDOWN LOGIC ---
  wrapper.on(
    "change",
    '.qp-multi-select-list input[type="checkbox"]',
    function () {
      var $this = $(this);
      var $list = $this.closest(".qp-multi-select-list");
      var $dropdown = $this.closest(".qp-multi-select-dropdown");
      var $button = $dropdown.find(".qp-multi-select-button");
      var $form = $dropdown.closest("form");

      // --- Part 1: Handle User Actions ---
      if ($this.val() === "all") {
        // If main "All" is clicked, check/uncheck everything else
        $list
          .find('input[type="checkbox"]')
          .prop("checked", $this.is(":checked"));
      } else if ($this.hasClass("qp-subject-topic-toggle")) {
        // If a subject-level toggle is clicked, check/uncheck its topics
        $this
          .closest("label")
          .nextUntil(".qp-topic-group-header")
          .find('input[type="checkbox"]')
          .prop("checked", $this.is(":checked"));
      }

      // --- Part 2: Synchronize Parent Checkboxes ---
      // Sync all subject-level toggles based on their topics
      $list.find(".qp-subject-topic-toggle").each(function () {
        var $subjectToggle = $(this);
        var $topicCheckboxes = $subjectToggle
          .closest("label")
          .nextUntil(".qp-topic-group-header")
          .find('input[type="checkbox"]');
        var allTopicsChecked =
          $topicCheckboxes.length > 0 &&
          $topicCheckboxes.filter(":checked").length ===
            $topicCheckboxes.length;
        $subjectToggle.prop("checked", allTopicsChecked);
      });

      // Sync the main "All" toggle based on all other checkboxes
      var $allCheckboxes = $list.find('input[type="checkbox"][value!="all"]');
      var allChecked =
        $allCheckboxes.length > 0 &&
        $allCheckboxes.filter(":checked").length === $allCheckboxes.length;
      $list.find('input[value="all"]').prop("checked", allChecked);

      // --- Part 3: Update Button Text & Dynamic Dropdowns (No changes here) ---
      var placeholder = $dropdown.attr("id").includes("subject")
        ? "-- Please select --"
        : "-- Select Topic(s) --";
      var singularLabel = $dropdown.attr("id").includes("subject")
        ? "Subject"
        : "Topic";
      updateButtonText($button, placeholder, singularLabel);

      if ($dropdown.attr("id").includes("subject")) {
        var selectedSubjects = [];
        $list.find('input:checked[value!="all"]').each(function () {
          selectedSubjects.push($(this).val());
        });
        var $topicGroup = $form.find('[id^="qp-topic-group"]');
        var $topicListContainer = $form.find('[id^="qp_topic_list_container"]');
        var $topicButton = $form.find(
          '[id^="qp_topic_dropdown"] .qp-multi-select-button'
        );
        if (selectedSubjects.length > 0) {
          $.ajax({
            url: qp_ajax_object.ajax_url,
            type: "POST",
            data: {
              action: "get_topics_for_subject",
              nonce: qp_ajax_object.nonce,
              subject_id: selectedSubjects,
            },
            beforeSend: function () {
              $topicButton.text("Loading Topics...").prop("disabled", true);
              $topicListContainer.empty();
            },
            success: function (response) {
              $topicButton.prop("disabled", false);
              if (
                response.success &&
                Object.keys(response.data.topics).length > 0
              ) {
                var formId = $form.attr("id");
                var topicNameAttr = "qp_topic[]"; // Default for normal practice
                if (formId === "qp-start-revision-form") {
                  topicNameAttr = "revision_topics[]";
                } else if (formId === "qp-start-mock-test-form") {
                  topicNameAttr = "mock_topics[]";
                }
                $topicListContainer.append(
                  '<label><input type="checkbox" name="' +
                    topicNameAttr +
                    '" value="all"> All Topics</label>'
                );
                $.each(response.data.topics, function (subjectName, topics) {
                  $topicListContainer.append(
                    '<label class="qp-topic-group-header"><input type="checkbox" class="qp-subject-topic-toggle"> ' +
                      subjectName +
                      "</label>"
                  );
                  $.each(topics, function (i, topic) {
                    var count =
                      unattemptedCounts.by_topic &&
                      unattemptedCounts.by_topic[topic.topic_id]
                        ? " (" +
                          unattemptedCounts.by_topic[topic.topic_id] +
                          ")"
                        : "";
                    $topicListContainer.append(
                      '<label><input type="checkbox" name="' +
                        topicNameAttr +
                        '" value="' +
                        topic.topic_id +
                        '" data-subject-id="' +
                        topic.subject_id +
                        '"> ' +
                        topic.topic_name +
                        count +
                        "</label>"
                    );
                  });
                });
                updateButtonText(
                  $topicButton,
                  "-- Select Topic(s) --",
                  "Topic"
                );
                $topicGroup.slideDown();
              } else {
                updateButtonText($topicButton, "No Topics Found", "Topic");
                $topicGroup.slideUp();
              }
            },
          });
        } else {
          $topicGroup.slideUp();
          updateButtonText(
            $topicButton,
            "-- Select subject(s) first --",
            "Topic"
          );
        }
      }
    }
  );

  // --- NEW: CASCADING DROPDOWNS FOR SECTION WISE PRACTICE ---
  var cascadingContainer = $("#qp-section-cascading-dropdowns-container");

  // When the Subject changes
  wrapper.on("change", "#qp_section_subject", function () {
    var subjectId = $(this).val();
    cascadingContainer.empty(); // Clear all child dropdowns
    $('#qp-start-section-wise-form input[type="submit"]').prop("disabled", true);


    if (!subjectId) return;

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "get_sources_for_subject",
        nonce: qp_ajax_object.nonce,
        subject_id: subjectId,
      },
      beforeSend: function () {
        cascadingContainer.html("<p>Loading Sources...</p>");
      },
      success: function (response) {
        if (response.success && response.data.sources.length > 0) {
          var html =
            '<div class="qp-form-group" style="display: none;">' + // Add display:none
            '<label>Select Source:</label>' +
            '<select name="cascading_term" class="qp-cascading-select" data-level="1">' +
            '<option value="">— Select a Source —</option>';
          $.each(response.data.sources, function (i, source) {
            html += `<option value="${source.term_id}">${source.name}</option>`;
          });
          html += "</select></div>";
          cascadingContainer.html(html);
          cascadingContainer.find('.qp-form-group').slideDown(); // Use slideDown()
        } else {
          cascadingContainer.html("<p>No sources found for this subject.</p>");
        }
      },
    });
  });

  // When any Source or Section dropdown changes
  wrapper.on("change", ".qp-cascading-select", function () {
    var parentId = $(this).val();
    var level = parseInt($(this).data("level"), 10);
    var submitButton = $('#qp-start-section-wise-form input[type="submit"]');

    // Remove all subsequent dropdowns
    cascadingContainer.find(".qp-form-group").slice(level).remove();
    submitButton.prop("disabled", true);


    if (!parentId) return;

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "get_child_terms",
        nonce: qp_ajax_object.nonce,
        parent_id: parentId,
      },
      success: function (response) {
        if (response.success && response.data.children.length > 0) {
          var html =
            '<div class="qp-form-group" style="display: none;">' + // Add display:none
            `<label>Select Section (Level ${level + 1}):</label>` +
            `<select name="cascading_term" class="qp-cascading-select" data-level="${
              level + 1
            }">` +
             '<option value="">— Select a Section —</option>';
          $.each(response.data.children, function (i, child) {
            html += `<option value="${child.term_id}">${child.name}</option>`;
          });
          html += "</select></div>";
          var $newDropdown = $(html);
          cascadingContainer.append($newDropdown);
          $newDropdown.slideDown(); // Use slideDown()
        } else {
          // This is the last possible child, enable the submit button
          submitButton.prop("disabled", false);
        }
      },
    });
  });
  
  // --- NEW: Section Wise Practice Form Submission ---
  wrapper.on("submit", "#qp-start-section-wise-form", function(e) {
      e.preventDefault();
      var form = $(this);
      var submitButton = form.find('input[type="submit"]');
      var originalButtonText = submitButton.val();
      
      // Find the value of the LAST dropdown in the container
      var lastSelectedTerm = form.find('.qp-cascading-select').last().val();

      if (!lastSelectedTerm) {
          Swal.fire('Selection Incomplete', 'Please select an item from the final dropdown.', 'warning');
          return;
      }

      // Add the final selected section to the form data
      var formData = form.serialize() + '&qp_section=' + lastSelectedTerm;

      checkAttemptsBeforeAction(function() {
        $.ajax({
          url: qp_ajax_object.ajax_url,
          type: "POST",
          data: formData + '&action=start_practice_session&nonce=' + qp_ajax_object.nonce,
          beforeSend: function () {
              submitButton.val("Setting up session...").prop("disabled", true);
          },
          success: function (response) {
              if (response.success && response.data.redirect_url) {
                  window.location.href = response.data.redirect_url;
              } else {
                if (response.data && response.data.code === 'duplicate_session_exists') {
                    var resumeUrl = new URL(qp_ajax_object.session_page_url);
                    resumeUrl.searchParams.set('session_id', response.data.session_id);

                    Swal.fire({
                        title: "Session Already Active",
                        text: "You already have an active or paused session for this section. Would you like to resume it?",
                        icon: "info",
                        showCancelButton: true,
                        confirmButtonText: "Resume Session",
                        cancelButtonText: "Cancel",
                        confirmButtonColor: '#2e7d32',
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = resumeUrl.href;
                        }
                    });

                } else {
                    Swal.fire('Could Not Start Session', response.data.message || 'An unknown error occurred.', 'warning');
                }
                submitButton.val(originalButtonText).prop("disabled", false);
              }
          },
          error: function() {
              Swal.fire('Error!', 'A server error occurred.', 'error');
              submitButton.val(originalButtonText).prop("disabled", false);
          }
      });
      }, submitButton, originalButtonText, 'Setting up session...');
  });

  // --- NEW: Generic handlers for scoring/timer toggles in the new form ---
  wrapper.on('change', '#qp-start-section-wise-form .qp-scoring-enabled-cb', function () {
      var marksWrapper = $(this).closest('form').find('.qp-marks-group');
      if ($(this).is(':checked')) {
          marksWrapper.slideDown();
      } else {
          marksWrapper.slideUp();
      }
  });

  wrapper.on('change', '#qp-start-section-wise-form .qp-timer-enabled-cb', function () {
      var timerWrapper = $(this).closest('form').find('.qp-timer-input-wrapper');
      if ($(this).is(':checked')) {
          timerWrapper.slideDown();
      } else {
          timerWrapper.slideUp();
      }
  });

  // Hide dropdowns when clicking outside
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".qp-multi-select-dropdown").length) {
      $(".qp-multi-select-list").hide();
    }
  });

  // --- MULTI-STEP FORM LOGIC ---
  if ($(".qp-multi-step-container").length) {
    // Function to navigate between steps with animation
    // Function to navigate between steps with animation
    function navigateToStep(targetStepNumber, direction) {
      var currentStep = $(".qp-form-step.active");
      var targetStep = $("#qp-step-" + targetStepNumber);

      if (targetStep.length && currentStep.attr("id") !== targetStep.attr("id")) {
        // --- FIX: Handle URL Hash Correctly ---
        if (targetStepNumber === 1) {
            // Clear the hash without reloading the page when returning to the first step
            history.pushState("", document.title, window.location.pathname + window.location.search);
        } else {
            window.location.hash = 'step-' + targetStepNumber;
        }

        // --- FIX: Animate correctly based on direction ---
        if (direction === "next") {
          currentStep.removeClass("active").addClass("is-exiting-left");
          // Reset target step's position before making it active
          targetStep.removeClass("is-exiting-left is-exiting-right").addClass("active");
        } else { // direction === 'back'
          currentStep.removeClass("active").addClass("is-exiting-right");
          // Reset target step's position before making it active
          targetStep.removeClass("is-exiting-left is-exiting-right").addClass("active");
        }
      }
    }

    // Enable the "Next" button when a mode is selected
    wrapper.on("change", 'input[name="practice_mode_selection"]', function () {
      $("#qp-step1-next-btn").prop("disabled", !$(this).is(":checked"));
    });

    // Handler for "Next" button click
    wrapper.on("click", "#qp-step1-next-btn", function () {
      var targetStep = $('input[name="practice_mode_selection"]:checked').val();
      if (targetStep) {
        navigateToStep(targetStep, "next");
      }
    });

    // Handler for "Back" button clicks
    wrapper.on("click", ".qp-back-btn", function () {
      var targetStep = $(this).data("target-step");
      navigateToStep(targetStep, "back");
    });

    // Check URL hash on page load to restore state
    if (window.location.hash) {
      var hash = window.location.hash;
      var targetStepNumber = hash.split('-')[1];
      var targetStep = $("#qp-step-" + targetStepNumber);
      
      if (targetStep.length && targetStepNumber !== '1') {
        // Deactivate default step 1 and position it off-screen
        $("#qp-step-1").removeClass("active is-exiting-right").addClass("is-exiting-left");
        // Activate the target step
        targetStep.addClass("active");
      } else {
        // If hash is #step-1 or invalid, default to a clean state
        $("#qp-step-1").addClass('active');
      }
    } else {
        // If no hash, ensure step 1 is active by default
        $("#qp-step-1").addClass('active');
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
        if (response.success && response.data && response.data.html) {
          reportContainer.html(response.data.html);
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

  // --- Report Modal on REVIEW PAGE---
  wrapper.on("click", ".qp-report-btn-review", function () {
    var questionID = $(this).data("question-id");
    $("#qp-report-question-id-field").val(questionID); // Set the hidden field

    var reportContainer = $("#qp-report-options-container");
    reportContainer.html("<p>Loading reasons...</p>");

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: { action: "get_report_reasons", nonce: qp_ajax_object.nonce },
      success: function (response) {
        if (response.success && response.data.html) {
          reportContainer.html(response.data.html);
        } else {
          reportContainer.html("<p>Could not load reporting options.</p>");
        }
      },
      error: function () {
        reportContainer.html("<p>An error occurred.</p>");
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
    var questionID =
      $("#qp-report-question-id-field").val() ||
      (typeof sessionQuestionIDs !== "undefined"
        ? sessionQuestionIDs[currentQuestionIndex]
        : 0);
    var selectedReasons = form
      .find('input[name="report_reasons[]"]:checked')
      .map(function () {
        return $(this).val();
      })
      .get();

    var reportComment = form
      .find('textarea[name="report_comment"]')
      .val()
      .trim();

    if (selectedReasons.length === 0) {
      Swal.fire(
        "No Reason Selected",
        "Please select at least one reason for the report.",
        "warning"
      );
      return;
    }

    // --- THIS IS THE FIX ---
    // Check if the comment is empty
    if (reportComment === "") {
      Swal.fire(
        "Comment Required",
        "Please provide a comment to explain the issue.",
        "warning"
      );
      return; // Stop the submission
    }
    // --- END FIX ---

    var reportComment = form.find('textarea[name="report_comment"]').val();

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "submit_question_report",
        nonce: qp_ajax_object.nonce,
        question_id: questionID,
        session_id: sessionID,
        reasons: selectedReasons,
        comment: reportComment,
      },
      beforeSend: function () {
        submitButton.text("Submitting...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success) {
          $("#qp-report-modal-backdrop").fadeOut(200);
          Swal.fire({
            title: "Report Submitted!",
            text: "Thank you for your feedback. The question has been flagged for review.",
            icon: "success",
            timer: 1500,
            showConfirmButton: false,
          }).then(() => {
            var reviewPageQuestionId = $("#qp-report-question-id-field").val();

            if (reviewPageQuestionId) {
              // Logic for the Review Page
              var buttonToDisable = $(
                '.qp-report-btn-review[data-question-id="' +
                  reviewPageQuestionId +
                  '"]'
              );
              buttonToDisable.prop("disabled", true).text("Reported");
              $("#qp-report-question-id-field").val("");
              form.find('textarea[name="report_comment"]').val(''); // Reset comment textarea
            } else {
              // --- NEW, CONSOLIDATED LOGIC for the Practice Session Page ---
              var questionID = sessionQuestionIDs[currentQuestionIndex];

              // 1. Update the local state with the new report info from the server
              if (typeof answeredStates[questionID] === "undefined") {
                answeredStates[questionID] = {};
              }
              answeredStates[questionID].reported_info =
                response.data.reported_info;

              // 2. Hide the report button immediately, regardless of type
              $("#qp-report-btn").hide();

              // 3. Update the palette to the gray "reported" state
              if (response.data.reported_info.has_report) {
                updatePaletteButton(questionID, "reported");
              }
              scrollPaletteToCurrent();
              updateLegendCounts(); // Update the legend count for reported items

              // 4. Update the indicators
              var showIndicatorBar = false;
              $("#qp-reported-indicator, #qp-suggestion-indicator").hide();
              if (response.data.reported_info.has_report) {
                $("#qp-reported-indicator").show();
                showIndicatorBar = true;
              }
              if (response.data.reported_info.has_suggestion) {
                $("#qp-suggestion-indicator").show();
                showIndicatorBar = true;
              }
              if (showIndicatorBar) {
                $(".qp-indicator-bar").show();
              }

              // 5. Conditionally lock the UI only if a critical 'report' was submitted
              if (response.data.reported_info.has_report) {
                $(".qp-options-area")
                  .addClass("disabled")
                  .find('input[type="radio"]')
                  .prop("disabled", true);
                $("#qp-skip-btn, #qp-check-answer-btn").prop("disabled", true);
                $("#qp-next-btn").prop("disabled", false);
                $('#qp-next-btn').click();
              }
              // If it was only a suggestion, the UI remains active as desired.

              form.find('textarea[name="report_comment"]').val(''); // Reset comment textarea
            }
          });
        } else {
          Swal.fire(
            "Error!",
            response.data.message || "Could not submit the report.",
            "error"
          );
        }
      },
      error: function () {
        Swal.fire("Error!", "An unknown server error occurred.", "error");
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

// Validation for topic selection (Keep this)
if ($("#qp-topic-group").is(":visible")) {
    var selectedTopics = $("#qp_topic_list_container input:checked").length;
    if (selectedTopics === 0) {
        Swal.fire({
            title: "No Topics Selected",
            text: "Please select at least one topic to start the practice session.",
            icon: "warning",
            confirmButtonText: "OK",
        });
        return; // Stop the form submission
    }
}
var formData = form.serialize(); // Get form data first

// --- Use the Check Function ---
checkAttemptsBeforeAction(function() {
    // This code runs ONLY if the user has attempts
    $.ajax({
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: formData + '&action=start_practice_session&nonce=' + qp_ajax_object.nonce, // Use formData
        beforeSend: function() {
            // Button state is already 'Setting up...' from check function
            submitButton.val('Setting up session...'); // Keep or adjust text
        },
        success: function(response) {
            if (response.success && response.data.redirect_url) {
                window.location.href = response.data.redirect_url;
            } else {
                // Keep your existing error handling for duplicate sessions etc.
                if (response.data && response.data.code === 'duplicate_session_exists') {
                     var resumeUrl = new URL(qp_ajax_object.session_page_url);
                     resumeUrl.searchParams.set('session_id', response.data.session_id);
                     Swal.fire({ /* ... duplicate session alert ... */ }).then((result) => { /* ... */ });
                } else {
                     Swal.fire('Could Not Start Session', response.data.message || 'An unknown error occurred.', 'warning');
                }
                // Reset button ONLY if not redirecting
                submitButton.val(originalButtonText).prop('disabled', false);
            }
        },
        error: function() {
            Swal.fire('Error!', 'A server error occurred.', 'error');
            submitButton.val(originalButtonText).prop('disabled', false); // Reset button on error
        }
        // No 'complete' needed here
    });
}, submitButton, originalButtonText, 'Setting up session...'); // Pass button states & loading text
  });

  // Handler for REVISION Mode Form
  wrapper.on("submit", "#qp-start-revision-form", function (e) {
    e.preventDefault();

    if ($("#qp-topic-group-revision").is(":visible")) {
      var selectedTopics = $(
        "#qp_topic_list_container_revision input:checked"
      ).length;
      if (selectedTopics === 0) {
        Swal.fire(
          "No Topics Selected",
          "Please select at least one topic to start the revision session.",
          "warning"
        );
        return;
      }
    }
    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();
    var formData = form.serialize();

    checkAttemptsBeforeAction(function() {$.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data:
        formData +
        "&action=start_revision_session&nonce=" +
        qp_ajax_object.nonce,
      beforeSend: function () {
        submitButton.val("Setting up session...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success && response.data.redirect_url) {
          window.location.href = response.data.redirect_url;
        } else {
          Swal.fire({
            title: "Could Not Start Session",
            text:
              response.data.message ||
              "An unknown error occurred. Please try adjusting your selections.",
            icon: "warning",
            confirmButtonText: "OK",
          });
          submitButton.val(originalButtonText).prop("disabled", false);
        }
      },
      error: function () {
        Swal.fire(
          "Error!",
          "A server error occurred. Please try again later.",
          "error"
        );
        submitButton.val(originalButtonText).prop("disabled", false);
      },
    });}, submitButton, originalButtonText, 'Setting up session...');
  });

  // Handler for MOCK TEST Mode Form
  wrapper.on("submit", "#qp-start-mock-test-form", function (e) {
    e.preventDefault();

    var selectedSubjects = $("#qp_subject_dropdown_mock input:checked").length;
    if (selectedSubjects === 0) {
      Swal.fire(
        "No Subject Selected",
        "Please select at least one subject to start the mock test.",
        "warning"
      );
      return;
    }

    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();
    var formData = form.serialize();

    checkAttemptsBeforeAction(function() {
      $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data:
        formData +
        "&action=qp_start_mock_test_session&nonce=" +
        qp_ajax_object.nonce,
      beforeSend: function () {
        submitButton.val("Building your test...").prop("disabled", true);
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
          wrapper.html(errorMessage);
        }
      },
      error: function () {
        Swal.fire(
          "Error!",
          "A server error occurred. Please try again later.",
          "error"
        );
        submitButton.val(originalButtonText).prop("disabled", false);
      },
    });}, submitButton, originalButtonText, 'Setting up session...');
  });

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
    // Session state variables
  var sessionID = 0;
  var sessionQuestionIDs = [];
  var currentQuestionIndex = 0;
  var highestQuestionIndexReached =
    sessionStorage.getItem(
      "qp_session_" + qp_session_data.session_id + "_highest_index"
    ) || 0;
  highestQuestionIndexReached = parseInt(highestQuestionIndexReached, 10);
  var sessionSettings = {};
  var score = 0;
  var correctCount = 0;
  var incorrectCount = 0;
  var skippedCount = 0;
  var questionTimer;
  var answeredStates = {};
  var practiceInProgress = false;
  var questionCache = {};
  var remainingTime = 0;
  // --- NEW: Check Attempts on Page Load ---
    $.ajax({
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        async: false, // Make this check synchronous to block page loading if needed
        data: { action: 'qp_check_remaining_attempts' },
        success: function(response) {
            if (!(response.success && response.data.has_access)) {
                practiceInProgress = false; // Allow redirect
                // Access denied, show alert and redirect
                Swal.fire({
        title: 'Out of Attempts!',
        html: 'You do not have enough attempts remaining to continue this session. Pausing session. <a href="' + qp_ajax_object.shop_page_url + '">Purchase More</a>',
        icon: 'error',
        confirmButtonText: 'Pause & Go to Dashboard', // Changed button text
        allowOutsideClick: false,
        allowEscapeKey: false,
        showLoaderOnConfirm: true, // Show loader when pausing
        preConfirm: () => { // Use preConfirm to perform action before closing
            return new Promise((resolve) => {
                $.ajax({
                    url: qp_ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'qp_pause_session',
                        nonce: qp_ajax_object.nonce,
                        session_id: qp_session_data.session_id // Use session ID from localized data
                    },
                    success: function(pauseResponse) {
                        if (pauseResponse.success) {
                            resolve(); // Resolve the promise on success
                        } else {
                            Swal.showValidationMessage(`Failed to pause: ${pauseResponse.data.message || 'Unknown error'}`);
                            resolve(); // Still resolve to close the alert, but show error
                        }
                    },
                    error: function() {
                        Swal.showValidationMessage('Failed to pause: Server error.');
                        resolve(); // Still resolve on server error
                    }
                });
            });
        }
    }).then((result) => {
        // This runs *after* preConfirm finishes
        if (result.isConfirmed) {
             window.location.href = qp_ajax_object.dashboard_page_url; // Redirect after pausing
        }
        // No need for an else, preConfirm handles errors by showing validation message
    });
    // --- END MODIFICATION ---

    // Stop further initialization of the session page
    throw new Error("Access denied due to insufficient attempts.");
}
            // If access granted, continue initializing...
        },
        error: function() {
            practiceInProgress = false; // Allow redirect
            Swal.fire('Error!', 'Could not verify your access. Please try again.', 'error').then(() => {
                 window.location.href = qp_ajax_object.dashboard_page_url;
            });
            throw new Error("Failed to verify access.");
        }
    });
    // Hide preloader and show content after a delay
    setTimeout(function () {
      $("#qp-preloader").fadeOut(300, function () {
        $(this).remove();
      });
      $(".qp-practice-wrapper").addClass("loaded");
    }, 400); // 350ms load time + 50ms buffer
    practiceInProgress = true;
    sessionID = qp_session_data.session_id;
    if (sessionID) {
        $('#qp-session-id-display').html('<strong>Session ID:</strong> ' + sessionID).show();
    }
    sessionQuestionIDs = qp_session_data.question_ids;
    sessionSettings = qp_session_data.settings;
    isMockTest = sessionSettings.practice_mode === "mock_test"; // Set our global flag
    isRevisionMode = sessionSettings.practice_mode === "revision";

    // Mode-specific UI setup
    if (isMockTest) {
      // For mock tests, start the master timer
      if (qp_session_data.test_end_timestamp) {
        startMockTestTimer(qp_session_data.test_end_timestamp);
      }
      // Set the question counter
      $("#qp-question-counter").text(
        currentQuestionIndex + 1 + "/" + sessionQuestionIDs.length
      );
    } else {
      // For other modes, set up scoring and auto-check
      var savedAutoCheckState = sessionStorage.getItem("qpAutoCheckEnabled");
      if (savedAutoCheckState !== null) {
        isAutoCheckEnabled = savedAutoCheckState === "true";
        $("#qp-auto-check-cb").prop("checked", isAutoCheckEnabled);
      }
      if (sessionSettings.marks_correct === null) {
        $(".qp-header-stat.score").hide();
      }
      if (sessionSettings.practice_mode === "revision") {
        $(".qp-question-counter-box").show();
      }
    }

    if (qp_session_data.attempt_history) {
      // Restore answered states from DB (this part remains the same)
      for (var i = 0; i < sessionQuestionIDs.length; i++) {
        var qid = sessionQuestionIDs[i];
        if (qp_session_data.attempt_history[qid]) {
          var attempt = qp_session_data.attempt_history[qid];
          answeredStates[qid] = {
            type: attempt.status,
            is_correct: parseInt(attempt.is_correct, 10) === 1,
            selected_option_id: attempt.selected_option_id,
            correct_option_id: attempt.correct_option_id,
            remainingTime: attempt.remaining_time,
            mock_status: attempt.mock_status,
          };

          // For non-mock tests, calculate the score as we load
          if (attempt.status === "answered" && !isMockTest) {
            if (answeredStates[qid].is_correct) {
              correctCount++;
              score += parseFloat(sessionSettings.marks_correct);
            } else {
              incorrectCount++;
              score += parseFloat(sessionSettings.marks_incorrect);
            }
          } else if (attempt.status === "skipped") {
            skippedCount++;
          }
        }
      }
      // Try to restore the exact index from sessionStorage
      var savedIndex = sessionStorage.getItem(
        "qp_session_" + sessionID + "_index"
      );
      if (savedIndex !== null && !isNaN(savedIndex)) {
        currentQuestionIndex = parseInt(savedIndex, 10);
      } else {
        // Fallback for the very first load (or if sessionStorage is cleared)
        var lastAttemptedIndex = -1;
        for (var i = 0; i < sessionQuestionIDs.length; i++) {
          if (answeredStates[sessionQuestionIDs[i]]) {
            lastAttemptedIndex = i;
          }
        }
        currentQuestionIndex =
          lastAttemptedIndex >= 0 ? lastAttemptedIndex + 1 : 0;
      }
      currentQuestionIndex = Math.min(
        currentQuestionIndex,
        sessionQuestionIDs.length - 1
      );
      highestQuestionIndexReached = Math.max(
        highestQuestionIndexReached,
        currentQuestionIndex
      );
      if (isMockTest || isRevisionMode) {
        $("#qp-question-counter").text(
          currentQuestionIndex + 1 + "/" + sessionQuestionIDs.length
        );
      }
    }

    // Restore reported questions state from the new detailed object
    if (qp_session_data.reported_info) {
      $.each(qp_session_data.reported_info, function (qid, info) {
        // Ensure the question ID is treated as a number/string consistently
        qid = parseInt(qid, 10);
        if (typeof answeredStates[qid] === "undefined") {
          answeredStates[qid] = {};
        }
        // Store the detailed info
        answeredStates[qid].reported_info = info;
      });
    }

    // Update the header stats only for non-mock tests
    if (!isMockTest) {
      updateHeaderStats();
    }

    // Load the first (or current) question
    if (currentQuestionIndex >= sessionQuestionIDs.length) {
      endSession(false);
    } else {
      loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
      renderPalette();
      scrollPaletteToCurrent();
    }

    updateLegendCounts();
  }

  // --- Optional Scoring UI Toggle ---
  wrapper.on(
    "change",
    "#qp_scoring_enabled_cb, #qp_revision_scoring_enabled_cb",
    function () {
      var isChecked = $(this).is(":checked");
      // Find the wrapper for the marks inputs within the same form
      var marksWrapper = $(this).closest("form").find(".qp-marks-group");

      if (isChecked) {
        marksWrapper.slideDown();
        // **MODIFICATION START**: Re-enable the inputs when shown
        marksWrapper.find("input").prop("disabled", false);
        // **MODIFICATION END**
      } else {
        marksWrapper.slideUp();
        // **MODIFICATION START**: Disable the inputs when hidden
        marksWrapper.find("input").prop("disabled", true);
        // **MODIFICATION END**
      }
    }
  );

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

  function renderQuestion(data, questionID) {
    if (!isMockTest) {
      clearInterval(questionTimer);
    }

    var questionData = data.question;
    var previousState = answeredStates[questionID] || {};
    var reportInfo = previousState.reported_info || {};
    var optionsArea = $(".qp-options-area").empty().removeClass("disabled");

    // --- THIS IS THE FIX ---
    // Reset all indicators for EVERY question load, regardless of mode.
    var indicatorBar = $(".qp-indicator-bar");
    indicatorBar.hide();
    $(
      "#qp-revision-indicator, #qp-reported-indicator, #qp-suggestion-indicator, #qp-timer-indicator"
    ).hide();
    // --- END FIX ---

    // Common UI rendering for all modes
    var directionEl = $(".qp-direction").empty();
    if (questionData.direction_text || questionData.direction_image_url) {
      if (questionData.direction_text)
        directionEl.html($("<p>").html(questionData.direction_text));
      if (questionData.direction_image_url)
        directionEl.append(
          $("<img>")
            .attr("src", questionData.direction_image_url)
            .css("max-width", "100%")
        );
      directionEl.show();
    }
    // --- NEW: Hierarchical Subject/Topic Display ---
    var showTopic = qp_practice_settings.show_topic_meta &&
                    questionData.subject_lineage &&
                    Array.isArray(questionData.subject_lineage) &&
                    questionData.subject_lineage.length > 0;

    var questionIdText = "Question ID: " + questionData.question_id;
    if (data.attempt_id) {
        questionIdText += " | Attempt ID: " + data.attempt_id;
    }
    $('#qp-question-id').html(questionIdText);

    if (showTopic) {
        $("#qp-question-subject").html(
            "<strong>Topic: </strong>" + questionData.subject_lineage.join(" / ") + ' | '
        ).show();
    } else {
        $("#qp-question-subject").hide().html('');
    }

    // --- NEW: Hierarchical Source/Section Display ---
    var sourceDisplayArea = $("#qp-question-source");
    if (sourceDisplayArea.length) {
      var sourceInfoParts = [];
      if (
        questionData.source_lineage &&
        Array.isArray(questionData.source_lineage) &&
        questionData.source_lineage.length > 0
      ) {
        var sourceString =
          "<strong>Source:</strong> " + questionData.source_lineage.join(" / ");
        sourceInfoParts.push(sourceString);
      }

      if (questionData.question_number_in_section) {
        sourceInfoParts.push(
          "<strong>Q:</strong> " + questionData.question_number_in_section
        );
      }

      sourceDisplayArea.html(sourceInfoParts.join(" | "));
    }
    $("#qp-question-text-area").html(questionData.question_text);
    
    // Directly check the detailed report_info from the backend data.
    var reportInfoForButton = previousState.reported_info || data.reported_info || {};
    if (reportInfoForButton.has_report || reportInfoForButton.has_suggestion) {
        $("#qp-report-btn").hide();
    } else {
        $("#qp-report-btn").show();
    }

    $.each(questionData.options, function (index, option) {
      optionsArea.append(
        $('<label class="option"></label>')
          .append(
            $('<input type="radio" name="qp_option">').val(option.option_id)
          )
          .append($("<span>").html(option.option_text))
      );
    });

    // Mode-specific logic
    if (isMockTest) {
      // In a mock test, restore the user's previously selected answer if it exists
      if (previousState.selected_option_id) {
        $('input[value="' + previousState.selected_option_id + '"]')
          .prop("checked", true)
          .closest(".option")
          .addClass("selected");
      }

      // Set the state of the "Mark for Review" checkbox based on the detailed status
      const isMarked =
        previousState.mock_status === "marked_for_review" ||
        previousState.mock_status === "answered_and_marked_for_review";

      var reviewButtonLabel = $("#qp-mock-mark-review-cb")
        .closest("label")
        .find("span");
      if (isMarked) {
        reviewButtonLabel.text("Marked for Review");
      } else {
        reviewButtonLabel.text("Mark for Review");
      }
      $("#qp-mock-mark-review-cb")
        .prop("checked", isMarked)
        .closest("label")
        .toggleClass("checked", isMarked);

      // If the question has no mock_status yet, it's the first time it's being viewed.
      if (!previousState.mock_status) {
        updateMockStatus(questionID, "viewed");
      }
    } else {
      // --- CORRECTED LOGIC FOR NORMAL/REVISION MODES ---
      var isSectionWise =
        sessionSettings.practice_mode === "Section Wise Practice";

      $("#qp-next-btn").prop("disabled", !isSectionWise);
      $("#qp-skip-btn").prop("disabled", false);
      optionsArea.data("correct-option-id", data.correct_option_id);
      $("#qp-mark-for-review-cb").prop("checked", data.is_marked_for_review);

      if (isAutoCheckEnabled) {
        $("#qp-check-answer-btn").hide();
      } else {
        $("#qp-check-answer-btn").show().prop("disabled", true);
      }

      // The indicator reset logic has been moved out of this block.
      // Now we just handle showing the indicators.
      var showIndicatorBar = false;

      if (data.is_revision) {
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        var countToShow = 0;
        if (
          answeredStates[questionID] &&
          typeof answeredStates[questionID].historical_attempts !== "undefined"
        ) {
          countToShow = answeredStates[questionID].historical_attempts;
        } else {
          countToShow = data.previous_attempt_count;
          if (!answeredStates[questionID]) {
            answeredStates[questionID] = {};
          }
          answeredStates[questionID].historical_attempts = countToShow;
        }

        $("#qp-revision-indicator")
          .text("🔄 Revision (" + countToShow + ")")
          .show();
        showIndicatorBar = true;
      }

      var isAnswered = previousState.type === "answered";
      var isExpired = previousState.type === "expired";

      if (isAnswered || isExpired) {
        optionsArea
          .addClass("disabled")
          .find('input[type="radio"]')
          .prop("disabled", true);
        $("#qp-skip-btn").prop("disabled", true);
        $("#qp-next-btn").prop("disabled", false);

        if (isAnswered) {
          $('input[value="' + previousState.selected_option_id + '"]')
            .prop("checked", true)
            .closest(".option")
            .addClass(previousState.is_correct ? "correct" : "incorrect");
          if (!previousState.is_correct) {
            $('input[value="' + previousState.correct_option_id + '"]')
              .closest(".option")
              .addClass("correct");
          }
        }
        if (isExpired) {
          $("#qp-timer-indicator")
            .html("&#9201; Time Expired")
            .addClass("expired")
            .show();
          showIndicatorBar = true;
        }
      } else {
        if (sessionSettings.timer_enabled) {
          var startTime =
            typeof previousState.remainingTime !== "undefined"
              ? previousState.remainingTime
              : sessionSettings.timer_seconds;
          $("#qp-timer-indicator").removeClass("expired").show();
          showIndicatorBar = true;
          startTimer(startTime);
        }
      }

      if (showIndicatorBar) {
        indicatorBar.show();
      }
    }
    
    // This logic now runs for ALL modes, after the specific mode logic.
    var combinedReportInfo = previousState.reported_info || data.reported_info || {};
    var showIndicatorBarAfterReportCheck = false;

    if(combinedReportInfo.has_report) {
        $("#qp-reported-indicator").show();
        showIndicatorBarAfterReportCheck = true;
    }
    if(combinedReportInfo.has_suggestion) {
        $("#qp-suggestion-indicator").show();
        showIndicatorBarAfterReportCheck = true;
    }
    if(showIndicatorBarAfterReportCheck) {
        $(".qp-indicator-bar").show();
    }

    $("#qp-prev-btn").prop("disabled", currentQuestionIndex === 0);
    
    var isQuestionReported = combinedReportInfo.has_report; 

    if (isQuestionReported) {
      optionsArea
        .addClass("disabled")
        .find('input[type="radio"]')
        .prop("disabled", true);

      if (isMockTest) {
        $("#qp-clear-response-btn, #qp-mock-mark-review-cb").prop(
          "disabled",
          true
        );
        if (previousState.selected_option_id) {
          clearMockTestAnswer(questionID);
        }
      } else {
        $("#qp-skip-btn, #qp-check-answer-btn").prop("disabled", true);
        $("#qp-next-btn").prop("disabled", false);
      }
    } else {
      if (isMockTest) {
        $("#qp-clear-response-btn, #qp-mock-mark-review-cb").prop(
          "disabled",
          false
        );
      }
    }
    
    if (typeof renderMathInElement !== "undefined") {
      renderMathInElement(document.getElementById("qp-practice-app-wrapper"), {
        delimiters: [
          { left: "$$", right: "$$", display: true },
          { left: "$", right: "$", display: false },
          { left: "\\[", right: "\\]", display: true },
          { left: "\\(", right: "\\)", display: false },
        ],
        throwOnError: false,
      });
    }
  }

  function loadQuestion(questionID, direction) {
    var animatableArea = $(".qp-animatable-area");

    function doRender(data) {
      renderQuestion(data, questionID);
      sessionStorage.setItem(
        "qp_session_" + sessionID + "_index",
        currentQuestionIndex
      );
      if (direction) {
        var slideInClass =
          direction === "next" ? "slide-in-from-right" : "slide-in-from-left";
        animatableArea
          .removeClass("slide-out-to-left slide-out-to-right")
          .addClass(slideInClass);
      }
    }

    if (direction) {
      var slideOutClass =
        direction === "next" ? "slide-out-to-left" : "slide-out-to-right";
      animatableArea
        .removeClass("slide-in-from-left slide-in-from-right")
        .addClass(slideOutClass);
    }

    setTimeout(
      function () {
        $.ajax({
          url: qp_ajax_object.ajax_url,
          type: "POST",
          data: {
            action: "get_question_data",
            nonce: qp_ajax_object.nonce,
            question_id: questionID,
            session_id: sessionID, // Pass the session_id to get correct revision status
          },
          success: function (response) {
            if (response.success) {
              // Shuffle options before rendering
              response.data.question.options = shuffleArray(
                response.data.question.options
              );
              doRender(response.data);
            }
          },
        });
      },
      direction ? 300 : 0
    );
  }

  function loadNextQuestion() {
    // --- NEW: Intelligent Palette Button Appending ---
    // Check if the user is advancing to a question for which a button doesn't exist yet.
    var newIndex = currentQuestionIndex + 1;
    if (
      newIndex > highestQuestionIndexReached &&
      newIndex < sessionQuestionIDs.length
    ) {
      highestQuestionIndexReached = newIndex;
      // --- THIS IS THE FIX ---
      // Save the new highest index to sessionStorage.
      sessionStorage.setItem(
        "qp_session_" + sessionID + "_highest_index",
        highestQuestionIndexReached
      );
      // --- END FIX ---

      // Only append a new button if it's a mode where the palette is not fully pre-rendered.
      const isSectionWise =
        sessionSettings.practice_mode === "Section Wise Practice";
      if (!isMockTest && !isSectionWise) {
        const questionID = sessionQuestionIDs[newIndex];
        const paletteBtn = $("<button></button>")
          .addClass("qp-palette-btn status-not_viewed") // New buttons are always 'not_viewed' initially
          .attr("data-question-index", newIndex)
          .attr("data-question-id", questionID)
          .text(newIndex + 1);

        // Append the new button to both the docked and sliding palettes.
        paletteGrids.append(paletteBtn);
      }
    }

    // First, check if we are ALREADY on the last question.
    if (currentQuestionIndex >= sessionQuestionIDs.length - 1) {
      clearInterval(questionTimer);

      // Check if this is a section-wise practice session
      if (sessionSettings.practice_mode === "Section Wise Practice") {
        // Count how many non-reported questions have NOT been answered
        let unattemptedCount = 0;
        sessionQuestionIDs.forEach(function (qid) {
          const state = answeredStates[qid] || {};
          // A question is unattempted if it's NOT reported AND has NOT been answered.
          if (state.type !== "answered" && (!state.reported_info || !state.reported_info.has_report)) {
            unattemptedCount++;
          }
        });

        if (unattemptedCount > 0) {
          // If there are still questions to answer, show a warning and do not end the session.
          Swal.fire({
            title: "Session Not Complete",
            text: `You must attempt all non-reported questions in a section practice. You still have ${unattemptedCount} question(s) left.`,
            icon: "warning",
            confirmButtonText: "OK",
          });
          // We return here to prevent the "Congratulations" message from showing.
          return;
        }
      }

      // If it's not a section practice, or if all questions are complete, show the standard completion message.
      Swal.fire({
        title: "Congratulations!",
        text: "You've completed all available questions.",
        icon: "success",
        showCancelButton: true,
        confirmButtonColor: "#2e7d32",
        cancelButtonText: "Stay on Last Question",
        confirmButtonText: "End & See Summary",
      }).then((result) => {
        if (result.isConfirmed) {
          practiceInProgress = false;
          endSession(false);
        }
      });
      return;
    }

    var oldIndex = currentQuestionIndex;
    // If we are not on the last question, it's safe to increment the index and load the next question.
    currentQuestionIndex++;
    if (isMockTest || isRevisionMode) {
      $("#qp-question-counter").text(
        currentQuestionIndex + 1 + "/" + sessionQuestionIDs.length
      );
    }
    loadQuestion(sessionQuestionIDs[currentQuestionIndex], "next");
    updateCurrentPaletteButton(currentQuestionIndex, oldIndex);
    scrollPaletteToCurrent();
  }

  function updateHeaderStats() {
    $(".qp-header-stat.score .value").text(score.toFixed(2));
    $(".qp-header-stat.correct .value").text(correctCount);
    $(".qp-header-stat.incorrect .value").text(incorrectCount);
    var isSectionWise =
      sessionSettings.practice_mode === "Section Wise Practice";
    if (isSectionWise) {
      var notAttemptedCount =
        sessionQuestionIDs.length - (correctCount + incorrectCount);
      $(".qp-header-stat.skipped .value").text(notAttemptedCount);
    } else {
      $(".qp-header-stat.skipped .value").text(skippedCount);
    }
  }

  function startTimer(seconds) {
    // This function now assumes it's always given the correct starting time.
    remainingTime = seconds;

    function updateDisplay() {
      var minutes = Math.floor(remainingTime / 60);
      var secs = remainingTime % 60;
      $("#qp-timer-indicator").text(
        String(minutes).padStart(2, "0") + ":" + String(secs).padStart(2, "0")
      );
    }
    updateDisplay(); // Call it once immediately to show the initial time

    questionTimer = setInterval(function () {
      remainingTime--;
      updateDisplay();
      if (remainingTime <= 0) {
        clearInterval(questionTimer);

        // Update the UI to show the "Time Expired" state
        var timerIndicator = $("#qp-timer-indicator");
        timerIndicator.html("&#9201; Time Expired").addClass("expired");

        // Lock the options so the user cannot answer
        $(".qp-options-area").addClass("disabled");
        $("#qp-next-btn").prop("disabled", true);

        // **THE FIX**: Enable the skip button and then programmatically click it.
        // This reuses your existing skip logic to correctly log the attempt.
        var skipButton = $("#qp-skip-btn");
        skipButton.prop("disabled", false);
      }
    }, 1000);
  }

  function displaySummary(summaryData) {
    // Determine if the session was a mock test and if it was scored
    var settings = summaryData.settings || {};
    var isMockTest = settings.practice_mode === "mock_test";
    if (isMockTest && qp_ajax_object.review_page_url) {
      var reviewUrl = new URL(qp_ajax_object.review_page_url);
      reviewUrl.searchParams.set("session_id", sessionID);
      window.location.href = reviewUrl.href;
      return; // Stop the rest of the function from running
    }

    closeFullscreen();
    practiceInProgress = false;

    var isScoredSession = settings.marks_correct !== null;
    var mainDisplayHtml = "";
    if (isScoredSession) {
      mainDisplayHtml = `<div class="qp-summary-score"><div class="label">Final Score</div>${parseFloat(
        summaryData.final_score
      ).toFixed(2)}</div>`;
    } else {
      var accuracy =
        summaryData.total_attempted > 0
          ? (summaryData.correct_count / summaryData.total_attempted) * 100
          : 0;
      mainDisplayHtml = `<div class="qp-summary-score"><div class="label">Accuracy</div>${accuracy.toFixed(
        2
      )}%</div>`;
    }

    // --- NEW: Build the stats display based on the mode ---
    var statsHtml = "";
    if (isMockTest) {
      // For mock tests, show the new detailed stats
      statsHtml = `
            <div class="stat"><div class="value">${summaryData.correct_count}</div><div class="label">Correct</div></div>
            <div class="stat"><div class="value">${summaryData.incorrect_count}</div><div class="label">Incorrect</div></div>
            <div class="stat"><div class="value">${summaryData.skipped_count}</div><div class="label">Unattempted</div></div>
            <div class="stat"><div class="value">${summaryData.not_viewed_count}</div><div class="label">Not Viewed</div></div>
        `;
    } else {
      // For other modes, show the original stats
      statsHtml = `
            <div class="stat"><div class="value">${summaryData.total_attempted}</div><div class="label">Attempted</div></div>
            <div class="stat"><div class="value">${summaryData.correct_count}</div><div class="label">Correct</div></div>
            <div class="stat"><div class="value">${summaryData.incorrect_count}</div><div class="label">Incorrect</div></div>
            <div class="stat"><div class="value">${summaryData.skipped_count}</div><div class="label">Skipped</div></div>
        `;
    }

    var actionButtonsHtml = `<a href="${qp_ajax_object.dashboard_page_url}" class="qp-button qp-button-secondary">View Dashboard</a>`;
    if (qp_ajax_object.review_page_url) {
      var reviewUrl = new URL(qp_ajax_object.review_page_url);
      reviewUrl.searchParams.set("session_id", sessionID);
      actionButtonsHtml += `<a href="${reviewUrl.href}" class="qp-button qp-button-primary">Review Session</a>`;
    } else {
      actionButtonsHtml += `<a href="${qp_ajax_object.practice_page_url}" class="qp-button qp-button-primary">Start Another Practice</a>`;
    }

    var summaryHtml = `
      <div class="qp-summary-wrapper">
          <h2>Session Summary</h2>
          ${mainDisplayHtml}
          <div class="qp-summary-stats">
              ${statsHtml}
          </div>
          <div class="qp-summary-actions">
              ${actionButtonsHtml}
          </div>
      </div>`;

    wrapper.html(summaryHtml);
  }

  // Handles clicking an answer option to select it
  wrapper.off('click', '.qp-options-area .option').on('click', '.qp-options-area .option', function () {
    console.log('Option clicked! isMockTest:', isMockTest);

    var selectedOption = $(this);
    var optionsArea = selectedOption.closest(".qp-options-area");
    if (optionsArea.hasClass("disabled")) return;

    optionsArea.find(".option").removeClass("selected");
    selectedOption.addClass("selected");
    selectedOption.find('input[type="radio"]').prop("checked", true);

    if (isMockTest) {
      // For mock tests, save the attempt and update the status
      var questionID = sessionQuestionIDs[currentQuestionIndex];
      var selectedOptionId = selectedOption.find('input[type="radio"]').val();

      // Update local state first
      if (!answeredStates[questionID]) answeredStates[questionID] = {};
      answeredStates[questionID].selected_option_id = selectedOptionId;

      // Determine the new status based on the "Mark for Review" checkbox
      const isMarked = $("#qp-mock-mark-review-cb").is(":checked");
      const newStatus = isMarked
        ? "answered_and_marked_for_review"
        : "answered";

      console.log('DEBUG: Mock test logic - preparing qp_save_mock_attempt AJAX');
      // First, save the answer. The backend sets the main status to 'answered'.
      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: {
          action: "qp_save_mock_attempt",
          nonce: qp_ajax_object.nonce,
          session_id: sessionID,
          question_id: questionID,
          option_id: selectedOptionId,
        },
        success: function(response) {
        if (response.success) {
            // --- Keep existing success logic ---
            updateMockStatus(questionID, newStatus); // Assuming this is called on success
            // --- End existing success logic ---
        } else if (response.data && response.data.code === 'access_denied') {
    // --- Handle Access Denied ---
    practiceInProgress = false; // Allow redirects from buttons
    var alertConfig = {
        title: 'Out of Attempts!',
        html: response.data.message || 'Please purchase more attempts to continue.',
        icon: 'error',
        allowOutsideClick: false, // Prevent closing by clicking outside
        allowEscapeKey: false,   // Prevent closing with Esc key
        showConfirmButton: false, // Hide default OK button
        showCancelButton: false   // Hide default Cancel button
    };

    // Check if it's NOT section wise practice to add custom buttons
    if (sessionSettings && sessionSettings.practice_mode !== 'Section Wise Practice') {
        alertConfig.html += `
            <div style="margin-top: 20px; display: flex; justify-content: center; gap: 10px;">
                <button id="swal-end-practice" class="qp-button qp-button-secondary">End Practice</button>
                <button id="swal-purchase-more" class="qp-button qp-button-primary">Purchase More</button>
            </div>`;

        alertConfig.didOpen = () => {
            const endBtn = Swal.getPopup().querySelector('#swal-end-practice');
            const purchaseBtn = Swal.getPopup().querySelector('#swal-purchase-more');

            endBtn.addEventListener('click', () => {
                endSession(false); // Call your existing endSession function
                Swal.close();
            });

            purchaseBtn.addEventListener('click', () => {
                if (qp_ajax_object.shop_page_url) {
                    window.location.href = qp_ajax_object.shop_page_url;
                }
                Swal.close();
            });
        };
    } else {
        // For Section Wise Practice, just show an OK button to go to dashboard
        alertConfig.confirmButtonText = 'Go to Dashboard';
        alertConfig.showConfirmButton = true; // Show the button for this case
        alertConfig.preConfirm = () => {
             window.location.href = qp_ajax_object.dashboard_page_url;
        };
    }


    Swal.fire(alertConfig);

    // Disable interactions for the current question (keep this)
    $('.qp-options-area').addClass('disabled').find('input[type="radio"]').prop('disabled', true);
    $('#qp-check-answer-btn, #qp-skip-btn, #qp-next-btn').prop('disabled', true);
} else {
            // --- Keep existing generic error handling here ---
            Swal.fire('Error!', response.data.message || 'Could not save answer.', 'error');
        }
    },
    error: function() { // Keep existing network error handling
        Swal.fire('Error!', 'An unknown server error occurred.', 'error');
    }
      });
      return;
    } else if (!isMockTest && isAutoCheckEnabled) {
      console.log('DEBUG: Auto-check logic - calling checkSelectedAnswer()');
      // For other modes with auto-check on
      checkSelectedAnswer();
    } else {
      // For other modes with auto-check off
      $("#qp-check-answer-btn").prop("disabled", false);
    }
  });
  // --- NEW: Reusable function to check the selected answer ---
  function checkSelectedAnswer() {
    var optionsArea = $(".qp-options-area");
    var selectedOption = optionsArea.find(".option.selected");

    // Do nothing if no option is selected or if it's already answered
    if (!selectedOption.length || optionsArea.hasClass("disabled")) {
      return;
    }

    // Lock the UI
    optionsArea.addClass("disabled");
    $("#qp-check-answer-btn").hide();
    $("#qp-skip-btn").prop("disabled", true);

    // Stop the timer
    clearInterval(questionTimer);

    // Get all necessary data
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var selectedOptionId = selectedOption.find('input[type="radio"]').val();
    var correctOptionId = optionsArea.data("correct-option-id");
    var isCorrect = selectedOptionId == correctOptionId;

    // Remove temporary selection style and apply final feedback style
    selectedOption.removeClass("selected");
    if (isCorrect) {
      selectedOption.addClass("correct");
      correctCount++;
      score += parseFloat(sessionSettings.marks_correct);
    } else {
      selectedOption.addClass("incorrect");
      optionsArea
        .find('input[value="' + correctOptionId + '"]')
        .closest(".option")
        .addClass("correct");
      incorrectCount++;
      score += parseFloat(sessionSettings.marks_incorrect);
    }

    // Update session state
    if (
      answeredStates[questionID] &&
      answeredStates[questionID].type === "skipped"
    ) {
      skippedCount--;
    }
    answeredStates[questionID] = {
      type: "answered",
      is_correct: isCorrect,
      correct_option_id: correctOptionId,
      selected_option_id: selectedOptionId,
      reported: answeredStates[questionID]?.reported || false,
      answered_in_session: true,
    };

    // Update UI
    updateHeaderStats();
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var newStatus = answeredStates[questionID].is_correct
      ? "correct"
      : "incorrect";
    updatePaletteButton(questionID, newStatus);
    scrollPaletteToCurrent();
    $("#qp-next-btn").prop("disabled", false);

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "check_answer",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
        question_id: questionID,
        option_id: selectedOptionId,
        remaining_time: remainingTime,
      },
      success: function(response) {
        if (response.success) {
            // --- Keep existing success logic here ---
            if (response.data.attempt_id) {
                // Update the UI with the new attempt ID
                var questionIdText = "Question ID: " + questionID + " | Attempt ID: " + response.data.attempt_id;
                $('#qp-question-id').text(questionIdText);
            }
            // --- End existing success logic ---
        } else if (response.data && response.data.code === 'access_denied') {
    // --- Handle Access Denied ---
    practiceInProgress = false; // Allow redirects from buttons
    var alertConfig = {
        title: 'Out of Attempts!',
        html: response.data.message || 'Please purchase more attempts to continue.',
        icon: 'error',
        allowOutsideClick: false, // Prevent closing by clicking outside
        allowEscapeKey: false,   // Prevent closing with Esc key
        showConfirmButton: false, // Hide default OK button
        showCancelButton: false   // Hide default Cancel button
    };

    // Check if it's NOT section wise practice to add custom buttons
    if (sessionSettings && sessionSettings.practice_mode !== 'Section Wise Practice') {
        alertConfig.html += `
            <div style="margin-top: 20px; display: flex; justify-content: center; gap: 10px;">
                <button id="swal-end-practice" class="qp-button qp-button-secondary">End Practice</button>
                <button id="swal-purchase-more" class="qp-button qp-button-primary">Purchase More</button>
            </div>`;

        alertConfig.didOpen = () => {
            const endBtn = Swal.getPopup().querySelector('#swal-end-practice');
            const purchaseBtn = Swal.getPopup().querySelector('#swal-purchase-more');

            endBtn.addEventListener('click', () => {
                endSession(false); // Call your existing endSession function
                Swal.close();
            });

            purchaseBtn.addEventListener('click', () => {
                if (qp_ajax_object.shop_page_url) {
                    window.location.href = qp_ajax_object.shop_page_url;
                }
                Swal.close();
            });
        };
    } else {
    // --- NEW: Section Wise Practice - Show Pause & Purchase ---
    practiceInProgress = false; // Allow redirects
    alertConfig.html += `
        <div style="margin-top: 20px; display: flex; justify-content: center; gap: 10px;">
            <button id="swal-pause-session" class="qp-button qp-button-secondary">Pause Session</button>
            <button id="swal-purchase-more-section" class="qp-button qp-button-primary">Purchase More</button>
        </div>`;

    alertConfig.didOpen = () => {
        const pauseBtn = Swal.getPopup().querySelector('#swal-pause-session');
        const purchaseBtn = Swal.getPopup().querySelector('#swal-purchase-more-section');

        pauseBtn.addEventListener('click', () => {
            // --- Call AJAX to pause the session ---
            $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'qp_pause_session', // Use the existing pause action
                    nonce: qp_ajax_object.nonce,
                    session_id: sessionID // Make sure sessionID is accessible here
                },
                beforeSend: function() {
                   Swal.showLoading(); // Show loading indicator on the alert
                   pauseBtn.disabled = true;
                   purchaseBtn.disabled = true;
                },
                success: function(pauseResponse) {
                    if (pauseResponse.success) {
                        window.location.href = qp_ajax_object.dashboard_page_url; // Redirect to dashboard after pausing
                    } else {
                        Swal.update({
                            title: 'Error Pausing',
                            html: pauseResponse.data.message || 'Could not pause session.',
                            icon: 'error',
                            showConfirmButton: true, // Show OK button on error
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function() {
                    Swal.update({
                        title: 'Error Pausing',
                        html: 'A server error occurred while trying to pause.',
                        icon: 'error',
                        showConfirmButton: true,
                        confirmButtonText: 'OK'
                    });
                }
            });
        });

        purchaseBtn.addEventListener('click', () => {
            if (qp_ajax_object.shop_page_url) {
                window.location.href = qp_ajax_object.shop_page_url;
            }
            Swal.close();
        });
    };
    // --- END NEW Section Wise Logic ---
}


    Swal.fire(alertConfig);

    // Disable interactions for the current question (keep this)
    $('.qp-options-area').addClass('disabled').find('input[type="radio"]').prop('disabled', true);
    $('#qp-check-answer-btn, #qp-skip-btn, #qp-next-btn').prop('disabled', true);
} else {
            // --- Keep existing generic error handling here ---
            Swal.fire('Error!', response.data.message || 'Could not check answer.', 'error');
        }
    },
    error: function() { // Keep existing network error handling
        Swal.fire('Error!', 'An unknown server error occurred.', 'error');
    }
    });

    updateLegendCounts();
  }

  // --- UPDATED: Simplified click handler ---
  wrapper.on("click", "#qp-check-answer-btn", function () {
    checkSelectedAnswer();
  });

  wrapper.on("change", "#qp-auto-check-cb", function () {
    isAutoCheckEnabled = $(this).is(":checked");
    sessionStorage.setItem("qpAutoCheckEnabled", isAutoCheckEnabled);

    var checkButton = $("#qp-check-answer-btn");
    var optionsArea = $(".qp-options-area");
    var isAnswerSelected = optionsArea.find(".option.selected").length > 0;

    if (isAutoCheckEnabled) {
      checkButton.hide();
      // If an answer is already selected when the user enables auto-check, check it immediately.
      if (isAnswerSelected) {
        checkSelectedAnswer();
      }
    } else {
      // If auto-check is disabled, show the button.
      // It should be enabled only if an answer has been selected.
      checkButton.show().prop("disabled", !isAnswerSelected);
    }
  });

  wrapper.on('click', '#qp-next-btn, #qp-prev-btn', function () {
    var $button = $(this);
    var direction = $button.attr('id') === 'qp-next-btn' ? 'next' : 'prev';
    var questionID = sessionQuestionIDs[currentQuestionIndex];

    // Stop the timer immediately for both prev/next
    clearInterval(questionTimer);

    // --- Save remaining time (keep this) ---
    if (typeof answeredStates[questionID] === 'undefined') {
        answeredStates[questionID] = {};
    }
    answeredStates[questionID].remainingTime = remainingTime;

    // --- Update session activity (keep this) ---
    $.ajax({
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: {
            action: 'update_session_activity',
            nonce: qp_ajax_object.nonce,
            session_id: sessionID,
        },
    });

    // --- Conditional Check for NEXT button ---
    if (direction === 'next') {
         var originalButtonText = $button.find('span').html(); // Get the arrow icon
         checkAttemptsBeforeAction(function() {
             // This runs ONLY if the check passes
             loadNextQuestion(); // Call the existing function to load next question
             // Button state will be handled by loadNextQuestion or if the alert pops up
         }); // Pass button for state management

    } else { // Handle PREV button directly (no check needed)
         if (currentQuestionIndex > 0) {
             var oldIndex = currentQuestionIndex;
             currentQuestionIndex--;
             if (isMockTest || isRevisionMode) {
                 $('#qp-question-counter').text(currentQuestionIndex + 1 + '/' + sessionQuestionIDs.length);
             }
             loadQuestion(sessionQuestionIDs[currentQuestionIndex], 'prev');
             updateCurrentPaletteButton(currentQuestionIndex, oldIndex);
             scrollPaletteToCurrent();
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

      // Send the skip event to the server with the remaining time
      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: {
          action: "skip_question",
          nonce: qp_ajax_object.nonce,
          session_id: sessionID,
          question_id: questionID,
          remaining_time: remainingTime,
        },
      });

      // Update local state
      if (typeof answeredStates[questionID] === "undefined") {
        answeredStates[questionID] = {};
      }
      answeredStates[questionID].type = "skipped";
      answeredStates[questionID].remainingTime = remainingTime;

      updateHeaderStats();
      var questionID = sessionQuestionIDs[currentQuestionIndex];
      updatePaletteButton(questionID, "skipped");
      scrollPaletteToCurrent();
      loadNextQuestion();
      updateLegendCounts();
    }
  });

  function endSession(isAutoSubmit = false) {
    // --- NEW LOGIC START ---
    var totalAttempts = correctCount + incorrectCount;

    // Check for mock test answers specifically
    if (isMockTest) {
      totalAttempts = Object.values(answeredStates).filter(function (state) {
        return state.selected_option_id;
      }).length;
    }

    if (!isAutoSubmit && totalAttempts === 0) {
      practiceInProgress = false; // Allow redirect without warning
      Swal.fire({
        title: "Session Not Saved",
        text: "You haven't answered any questions, so this session will not be saved in your history.",
        icon: "info",
        confirmButtonText: "OK, Go to Dashboard",
      }).then(() => {
        // Call the AJAX action to delete the empty session in the background
        $.ajax({
          url: qp_ajax_object.ajax_url,
          type: "POST",
          data: {
            action: "delete_empty_session",
            nonce: qp_ajax_object.nonce,
            session_id: sessionID,
            is_auto_submit: isAutoSubmit,
          },
          complete: function () {
            // Redirect after the alert is closed and AJAX is complete
            window.location.href = qp_ajax_object.dashboard_page_url;
          },
        });
      });
      return;
    }

    practiceInProgress = false; // Allow user to leave the page
    if (isMockTest) {
      clearInterval(mockTestTimer);
    }

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      data: {
        action: "end_practice_session",
        nonce: qp_ajax_object.nonce,
        session_id: sessionID,
      },
      beforeSend: function () {
        var message = "Generating your results...";
        if (isMockTest && isAutoSubmit) {
          message = "Time's up! Submitting your test...";
        } else if (isMockTest) {
          message = "Submitting your test...";
        }
        wrapper.html(
          `<p style="text-align:center; padding: 50px;">${message}</p>`
        );
      },
      success: function (response) {
        if (response.success) {
          // START: New code to add
          // Check if the backend deleted the session because it was empty
          if (response.data.status && response.data.status === "no_attempts") {
            // Redirect to the dashboard without showing a summary.
            window.location.href = qp_ajax_object.dashboard_page_url;
            return;
          }
          // END: New code to add
          displaySummary(response.data);
        }
      },
      error: function () {
        Swal.fire({
          title: "Submission Error!",
          text: "An error occurred while submitting the session. Please check your connection.",
          icon: "error",
        });
      },
    });
  }

  // A single handler for all session-ending buttons
  wrapper.on("click", "#qp-end-practice-btn, #qp-submit-test-btn", function () {
    var confirmMsg = isMockTest
      ? "Are you sure you want to submit your test? You cannot make any more changes."
      : "Are you sure you want to end this practice session?";

    Swal.fire({
      title: "Are you sure?",
      text: confirmMsg,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#2271b1",
      cancelButtonColor: "#d33",
      confirmButtonText: isMockTest
        ? "Yes, submit the test!"
        : "Yes, end session!",
    }).then((result) => {
      if (result.isConfirmed) {
        endSession(false);
      }
    });
  });

  wrapper.on("click", "#qp-pause-btn", function () {
    Swal.fire({
      title: "Pause Session?",
      text: "You can resume this session later from your dashboard.",
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, pause it!",
      cancelButtonText: "Cancel",
    }).then((result) => {
      if (result.isConfirmed) {
        practiceInProgress = false;
        $.ajax({
          url: qp_ajax_object.ajax_url,
          type: "POST",
          data: {
            action: "qp_pause_session",
            nonce: qp_ajax_object.nonce,
            session_id: sessionID,
          },
          beforeSend: function () {
            $(".qp-footer-controls .qp-button").prop("disabled", true);
            $("#qp-pause-btn").text("Pausing...");
            Swal.fire({
              title: "Pausing...",
              text: "Your session is being saved.",
              allowOutsideClick: false,
              didOpen: () => {
                Swal.showLoading();
              },
            });
          },
          success: function (response) {
            if (response.success) {
              window.location.href = qp_ajax_object.dashboard_page_url;
            } else {
              Swal.fire(
                "Error!",
                response.data.message || "Could not pause the session.",
                "error"
              );
              $(".qp-footer-controls .qp-button").prop("disabled", false);
              $("#qp-pause-btn").text("Pause & Save");
            }
          },
          error: function () {
            Swal.fire("Error!", "An unknown server error occurred.", "error");
            $(".qp-footer-controls .qp-button").prop("disabled", false);
            $("#qp-pause-btn").text("Pause & Save");
          },
        });
      }
    });
  });

  // --- NEW: On-Demand Fullscreen Button Handler ---
  wrapper.on("click", "#qp-fullscreen-btn", function () {
    if (document.fullscreenElement) {
      closeFullscreen();
    } else {
      openFullscreen();
    }
    scrollPaletteToCurrent();
  });

  // --- FINAL: Draggable, Resizable, Touch-Enabled Rough Work Popup with Undo/Redo ---
  var overlay = $("#qp-rough-work-overlay");
  var popup = $("#qp-rough-work-popup");
  var header = popup.find(".qp-popup-header");
  var resizeHandle = popup.find(".qp-popup-resize-handle");
  var canvasEl = $("#qp-rough-work-canvas");
  var canvas = canvasEl[0]; // The VISIBLE canvas
  var ctx; // The VISIBLE canvas context

  // --- NEW: Off-screen canvas for persistent drawing ---
  var masterCanvas = document.createElement("canvas");
  var masterCtx = masterCanvas.getContext("2d");
  masterCanvas.width = 2000; // A large fixed size
  masterCanvas.height = 2000;

  var isDrawing = false,
    isDragging = false,
    isResizing = false;
  var lastX, lastY, initialX, initialY, initialWidth, initialHeight;
  var currentTool = "pencil";

  var undoStack = [];
  var redoStack = [];
  var undoBtn = $("#qp-undo-btn");
  var redoBtn = $("#qp-redo-btn");

  function getEventCoords(e) {
    var evt =
      e.originalEvent && e.originalEvent.touches
        ? e.originalEvent.touches[0]
        : e;
    return { x: evt.clientX, y: evt.clientY };
  }

  function updateVisibleCanvas() {
    if (ctx) {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(masterCanvas, 0, 0);
    }
  }

  function saveCanvasState() {
    redoStack = [];
    undoStack.push(masterCanvas.toDataURL());
    updateUndoRedoButtons();
  }

  function updateUndoRedoButtons() {
    undoBtn.prop("disabled", undoStack.length <= 1);
    redoBtn.prop("disabled", redoStack.length === 0);
  }

  function restoreCanvasState(popStack, pushStack) {
    if (popStack.length > 0) {
      pushStack.push(masterCanvas.toDataURL());
      var restoreData = popStack.pop();

      var img = new Image();
      img.onload = function () {
        masterCtx.globalCompositeOperation = "source-over";
        masterCtx.clearRect(0, 0, masterCanvas.width, masterCanvas.height);
        masterCtx.drawImage(img, 0, 0);
        updateVisibleCanvas(); // Update the view
      };
      img.src = restoreData;
      updateUndoRedoButtons();
    }
  }

  undoBtn.on("click", function () {
    restoreCanvasState(undoStack, redoStack);
  });
  redoBtn.on("click", function () {
    restoreCanvasState(redoStack, undoStack);
  });

  function resizeCanvas() {
    if (canvas) {
      var contentArea = popup.find(".qp-popup-content");
      canvas.width = contentArea.width();
      canvas.height = contentArea.height();
      updateVisibleCanvas(); // Redraw from master canvas after resize
    }
  }

  function draw(e) {
    if (!isDrawing) return;
    var coords = getEventCoords(e);
    var rect = canvas.getBoundingClientRect();
    var currentX = coords.x - rect.left;
    var currentY = coords.y - rect.top;

    if (currentTool === "eraser") {
      masterCtx.globalCompositeOperation = "destination-out";
      masterCtx.lineWidth = 20;
    } else {
      masterCtx.globalCompositeOperation = "source-over";
      masterCtx.strokeStyle = $(".qp-color-btn.active").data("color");
      masterCtx.lineWidth = 2;
    }

    masterCtx.beginPath();
    masterCtx.moveTo(lastX, lastY);
    masterCtx.lineTo(currentX, currentY);
    masterCtx.stroke();
    [lastX, lastY] = [currentX, currentY];

    updateVisibleCanvas(); // Update the view after drawing on master
    e.preventDefault();
  }

  // Show Popup and Overlay
  wrapper.on("click", "#qp-rough-work-btn", function () {
    var savedOpacityValue = localStorage.getItem("qpCanvasOpacityValue");
    var initialSliderValue = savedOpacityValue
      ? parseFloat(savedOpacityValue)
      : 90;
    var popupOpacity = 0.3 + (initialSliderValue / 100) * 0.7;
    var overlayOpacity = 0.1 + (initialSliderValue / 100) * 0.5;
    popup.css("background-color", `rgba(255, 255, 255, ${popupOpacity})`);
    overlay.css("background-color", `rgba(0, 0, 0, ${overlayOpacity})`);
    $("#qp-canvas-opacity-slider").val(initialSliderValue);
    // Check for saved position in localStorage
    var savedTop = localStorage.getItem("qpCanvasTop");
    var savedLeft = localStorage.getItem("qpCanvasLeft");

    if (savedTop && savedLeft) {
      // If a position is saved, apply it directly
      popup.css({
        top: parseFloat(savedTop) + "px",
        left: parseFloat(savedLeft) + "px",
        transform: "none", // Important to override the centering transform
      });
    } else {
      // Otherwise, open it in the center for the first time
      popup.css({
        top: "50%",
        left: "50%",
        transform: "translate(-50%, -50%)",
      });
    }
    overlay.fadeIn(200);
    var offset = popup.offset();
    popup.css({ top: offset.top, left: offset.left, transform: "none" });

    if (!ctx) {
      ctx = canvas.getContext("2d");
      masterCtx.lineJoin = "round";
      masterCtx.lineCap = "round";
      resizeCanvas();
      if (undoStack.length === 0) {
        undoStack = [masterCanvas.toDataURL()];
        updateUndoRedoButtons();
      }
    }
  });

  // Dragging, Resizing, and Drawing events (unchanged)
  function onInteractionMove(e) {
    if (isDragging) {
      var coords = getEventCoords(e);

      // --- THIS IS THE FIX ---
      // Calculate the potential new top and left positions
      var newTop = coords.y - initialY;
      var newLeft = coords.x - initialX;

      // Get window and popup dimensions
      var windowWidth = $(window).width();
      var windowHeight = $(window).height();
      var popupWidth = popup.outerWidth();
      var popupHeight = popup.outerHeight();

      // Constrain the new position within the viewport boundaries
      newTop = Math.max(0, Math.min(newTop, windowHeight - popupHeight));
      newLeft = Math.max(0, Math.min(newLeft, windowWidth - popupWidth));

      // Apply the constrained position
      popup.offset({ top: newTop, left: newLeft });
      // --- END FIX ---
    }
    if (isResizing) {
      var coords = getEventCoords(e);
      var controlsWidth = popup.find(".qp-rough-work-controls").outerWidth();
      var titleWidth = popup.find(".qp-popup-title").outerWidth();
      var closeBtnWidth = popup.find(".qp-popup-close-btn").outerWidth();
      var minWidth = controlsWidth + titleWidth + closeBtnWidth + 40;
      var newWidth = initialWidth + (coords.x - initialX);
      var newHeight = initialHeight + (coords.y - initialY);
      if (newWidth < minWidth) newWidth = minWidth;
      popup.width(newWidth);
      popup.height(newHeight);
      resizeCanvas();
    }
  }

  function onInteractionEnd() {
    if (isDragging) {
      // --- THIS IS THE FIX ---
      // Save the final position to localStorage
      var finalOffset = popup.offset();
      localStorage.setItem("qpCanvasTop", finalOffset.top);
      localStorage.setItem("qpCanvasLeft", finalOffset.left);
      // --- END FIX ---
    }
    if (isResizing) saveCanvasState();
    isDragging = isResizing = false;
    $(document).off("mousemove touchmove", onInteractionMove);
    $(document).off("mouseup touchend", onInteractionEnd);
  }

  header.on("mousedown touchstart", function (e) {
    if (
      $(e.target).closest(".qp-rough-work-controls, .qp-popup-close-btn").length
    )
      return;
    isDragging = true;
    var coords = getEventCoords(e);
    initialX = coords.x - popup.offset().left;
    initialY = coords.y - popup.offset().top;
    $(document).on("mousemove touchmove", onInteractionMove);
    $(document).on("mouseup touchend", onInteractionEnd);
    e.preventDefault();
  });

  resizeHandle.on("mousedown touchstart", function (e) {
    isResizing = true;
    var coords = getEventCoords(e);
    initialX = coords.x;
    initialY = coords.y;
    initialWidth = popup.width();
    initialHeight = popup.height();
    $(document).on("mousemove touchmove", onInteractionMove);
    $(document).on("mouseup touchend", onInteractionEnd);
    e.preventDefault();
  });

  // --- Corrected and Robust Drawing Events ---
  canvasEl.on("mousedown touchstart", function (e) {
    isDrawing = true;
    saveCanvasState();
    var coords = getEventCoords(e);
    var rect = canvas.getBoundingClientRect();
    [lastX, lastY] = [coords.x - rect.left, coords.y - rect.top];
    e.preventDefault();
  });

  // Stop drawing if the mouse is released anywhere on the page
  $(document).on("mouseup touchend", function () {
    if (isDrawing) {
      isDrawing = false;
    }
  });

  // Reset the line start point when the mouse re-enters the canvas while drawing.
  canvasEl.on("mouseenter", function (e) {
    if (isDrawing) {
      // Only act if the mouse button is still held down
      var coords = getEventCoords(e);
      var rect = canvas.getBoundingClientRect();
      // Update the last known coordinates to the new entry point.
      [lastX, lastY] = [coords.x - rect.left, coords.y - rect.top];
    }
  });

  canvasEl.on("mousemove touchmove", draw);

  // Tool selection, color, etc. (unchanged)
  popup.on("click", ".qp-tool-btn", function () {
    if ($(this).is("#qp-undo-btn, #qp-redo-btn")) return;
    $(".qp-tool-btn").removeClass("active");
    $(this).addClass("active");
    currentTool = $(this).attr("id") === "qp-tool-eraser" ? "eraser" : "pencil";
    canvasEl
      .removeClass("cursor-pencil cursor-eraser")
      .addClass("cursor-" + currentTool);
  });

  popup.on("click", ".qp-color-btn", function () {
    $(".qp-color-btn").removeClass("active");
    $(this).addClass("active");
    $("#qp-tool-pencil").click();
    // Update the CSS variable for the border color
    var activeColor = $(this).data("color");
    document.documentElement.style.setProperty(
      "--qp-drawing-color",
      activeColor
    );
  });

  // --- REFINED: Opacity Slider Logic with localStorage ---
  popup.on("input", "#qp-canvas-opacity-slider", function () {
    var sliderValue = $(this).val(); // Get value from 0-100

    // Save the raw slider value to localStorage
    localStorage.setItem("qpCanvasOpacityValue", sliderValue);

    var popupOpacity = 0.3 + (sliderValue / 100) * 0.7;
    popup.css("background-color", `rgba(255, 255, 255, ${popupOpacity})`);

    var overlayOpacity = 0.1 + (sliderValue / 100) * 0.5;
    overlay.css("background-color", `rgba(0, 0, 0, ${overlayOpacity})`);
  });

  // Close and Clear (unchanged)
  popup.on("click", "#qp-close-canvas-btn", function () {
    overlay.fadeOut(200);
  });
  // --- Updated Clear Button ---
  popup.on("click", "#qp-clear-canvas-btn", function () {
    if (ctx) {
      saveCanvasState();
      masterCtx.clearRect(0, 0, masterCanvas.width, masterCanvas.height);
      updateVisibleCanvas();
    }
  });

  // --- NEW: Mock Test Specific Event Handlers ---
  if (
    typeof qp_session_data !== "undefined" &&
    qp_session_data.settings.practice_mode === "mock_test"
  ) {
    // Handler for the "Clear Response" button
    wrapper.on("click", "#qp-clear-response-btn", function () {
      var questionID = sessionQuestionIDs[currentQuestionIndex];
      clearMockTestAnswer(questionID);

      // Clear the radio button selection in the UI first
      $(".qp-options-area .option").removeClass("selected");
      $('input[name="qp_option"]').prop("checked", false);

      // Determine the correct final status
      const isMarkedForReview = $("#qp-mock-mark-review-cb").is(":checked");
      const newStatus = isMarkedForReview ? "marked_for_review" : "viewed";

      // Send a single, definitive update to the backend
      updateMockStatus(questionID, newStatus);
    });

    // Handler for the "Mark for Review" checkbox
    wrapper.on("change", "#qp-mock-mark-review-cb", function () {
      var questionID = sessionQuestionIDs[currentQuestionIndex];
      const isChecked = $(this).is(":checked");
      const currentState =
        (answeredStates[questionID] &&
          answeredStates[questionID].mock_status) ||
        "viewed";
      let newStatus = "";

      if (isChecked) {
        // When checking the box
        newStatus =
          currentState === "answered" ||
          currentState === "answered_and_marked_for_review"
            ? "answered_and_marked_for_review"
            : "marked_for_review";
      } else {
        // When unchecking the box
        newStatus =
          currentState === "answered_and_marked_for_review" ||
          currentState === "answered"
            ? "answered"
            : "viewed";
      }

      // Visually update the button style immediately
      $(this).closest("label").toggleClass("checked", isChecked);

      updateMockStatus(questionID, newStatus);
      var reviewButtonLabel = $(this).closest("label").find("span");
      if (isChecked) {
        reviewButton - Label.text("Marked for Review");
      } else {
        reviewButtonLabel.text("Mark for Review");
      }
    });
  }

  // ADD THIS NEW, REUSABLE FUNCTION anywhere in the main scope of the script
  function clearMockTestAnswer(questionID) {
    // Clear the radio button selection in the UI
    $(".qp-options-area .option").removeClass("selected");
    $('input[name="qp_option"]').prop("checked", false);

    // Determine the correct final status based on the review checkbox
    const isMarkedForReview = $("#qp-mock-mark-review-cb").is(":checked");
    const newStatus = isMarkedForReview ? "marked_for_review" : "viewed";

    // Send a single, definitive update to the backend
    updateMockStatus(questionID, newStatus);
  }

  // --- NEW: Mock Test Scoring Checkbox UI Toggle ---
  wrapper.on("change", "#qp_mock_scoring_enabled_cb", function () {
    var isChecked = $(this).is(":checked");
    var marksWrapper = $("#qp-mock-marks-group-wrapper");
    if (isChecked) {
      marksWrapper.slideDown();
      marksWrapper.find("input").prop("disabled", false);
    } else {
      marksWrapper.slideUp();
      marksWrapper.find("input").prop("disabled", true);
    }
  });

  // --- NEW: Advanced Palette Activation Logic ---
  if (typeof qp_session_data !== "undefined") {
    var isMockTest = qp_session_data.settings.practice_mode === "mock_test";
    var isSectionWise =
      qp_session_data.settings.practice_mode === "Section Wise Practice";
    var isPaletteMandatory = isMockTest || isSectionWise;

    // For mandatory modes, add the permanent layout class.
    if (isPaletteMandatory) {
      $("body").addClass("palette-mandatory");
    }

    // Handler for the buttons: This will always toggle the palette.
    $("#qp-palette-toggle-btn, #qp-palette-close-btn").on("click", function () {
      $("body").toggleClass("palette-overlay-open");
    });

    // Handler for the overlay: This closes the palette ONLY if the dark backdrop itself is clicked.
    $("#qp-palette-overlay").on("click", function (e) {
      if (e.target === this) {
        // Check if the click is on the overlay div, not its children.
        $("body").removeClass("palette-overlay-open");
      }
    });
  }

  // --- NEW: Palette Button Click Handler ---
  // Use event delegation on the document in case palettes are not visible on load
  $(document).on("click", ".qp-palette-btn", function () {
    var newIndex = parseInt($(this).data("question-index"), 10);
    if (newIndex === currentQuestionIndex) {
      return; // Do nothing if clicking the current question
    }

    var direction = newIndex > currentQuestionIndex ? "next" : "prev";
    var oldIndex = currentQuestionIndex;
    currentQuestionIndex = newIndex;

    if (isMockTest || isRevisionMode) {
      $("#qp-question-counter").text(
        currentQuestionIndex + 1 + "/" + sessionQuestionIDs.length
      );
    }

    loadQuestion(sessionQuestionIDs[currentQuestionIndex], direction);
    updateCurrentPaletteButton(currentQuestionIndex, oldIndex);
    scrollPaletteToCurrent();
  });

  /**
   * Robustly checks if an element is fully visible within its scrollable container.
   *
   * @param {HTMLElement} element - The element to check (the button).
   * @param {HTMLElement} container - The scrollable container (the palette grid).
   * @returns {boolean} - True if the element is fully visible, false otherwise.
   */
  function isElementFullyVisibleInContainer(element, container) {
    // Ensure we have the raw DOM elements
    const elem = $(element)[0];
    const cont = $(container)[0];

    if (!elem || !cont) return false;

    // 1. Get positions relative to the viewport.
    const elemRect = elem.getBoundingClientRect();
    const containerRect = cont.getBoundingClientRect();

    // 2. Define Tolerance (1px) to ignore sub-pixel rendering issues.
    const tolerance = 1;

    // 3. Calculate the precise top boundary of the visible content area.
    // cont.clientTop is the width of the top border. We exclude it because
    // getBoundingClientRect includes the border, but scrolling happens inside it.
    const containerVisibleTop = containerRect.top + cont.clientTop;

    // 4. Calculate the precise bottom boundary.
    // cont.clientHeight is the visible inner height (excludes borders/scrollbars).
    const containerVisibleBottom = containerVisibleTop + cont.clientHeight;

    // 5. Check Visibility against the tolerance.

    // Is the element's top edge below the container's top edge? (Forgiving minor top overflow)
    const isTopVisible = elemRect.top + tolerance >= containerVisibleTop;

    // Is the element's bottom edge above the container's bottom edge? (Forgiving minor bottom overflow)
    const isBottomVisible =
      elemRect.bottom - tolerance <= containerVisibleBottom;

    // The element is only considered visible if both the top and bottom are visible within tolerance.
    return isTopVisible && isBottomVisible;
  }

  function scrollPaletteToCurrent() {
    // Use double requestAnimationFrame (rAF) instead of setTimeout.
    // This guarantees that the browser has finished all layout calculations
    // after the .current class moved, ensuring we measure the final positions.
    setTimeout(function () {
      requestAnimationFrame(function () {
        // Select only visible palette grids (handles docked vs. sliding views)
        var $paletteGrids = $(
          "#qp-palette-docked .qp-palette-grid:visible, #qp-palette-sliding .qp-palette-grid:visible"
        );

        $paletteGrids.each(function () {
          // 'this' is the grid container DOM element.
          var gridElement = this;
          // Find the current button within this grid.
          var $currentBtn = $(gridElement).find(".qp-palette-btn.current");

          if ($currentBtn.length) {
            var btnElement = $currentBtn[0];
            // If (and ONLY if) the manual check fails, initiate the scroll.
            if (!isElementFullyVisibleInContainer(btnElement, gridElement)) {
              // If (and ONLY if) the check fails, initiate the scroll.
              btnElement.scrollIntoView({
                behavior: "smooth",
                block: "center",
              });
            }
            // If it returns true, we do absolutely nothing, preventing the erratic jumps.
          }
        });
      });
    }, 200);
  }
});

/**
 * Checks if the user has attempts before executing a callback.
 * Shows an error alert if access is denied.
 * @param {function} callback - The function to execute if access is granted.
 * @param {jQuery} [button] - Optional: The button triggering the action.
 * @param {string} [originalText] - Optional: The button's original text.
 * @param {string} [loadingText] - Optional: Text to show while checking.
 */
function checkAttemptsBeforeAction(callback, button, originalText, loadingText = 'Checking access...') {
    // Use global qp_ajax_object defined elsewhere
    if (typeof qp_ajax_object === 'undefined') {
         console.error('qp_ajax_object is not defined.');
         Swal.fire('Error!', 'Configuration missing. Cannot check access.', 'error');
         return;
    }

    if (button) {
        // Determine if it's an input button or a regular button
        if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
            button.prop('disabled', true).val(loadingText);
        } else {
            button.prop('disabled', true).text(loadingText);
        }
    }

    jQuery.ajax({ // Use jQuery.ajax since this is outside ready block
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: {
            action: 'qp_check_remaining_attempts',
            // No nonce needed here as per PHP function
        },
        success: function(response) {
            // --- NEW SIMPLIFIED LOGIC ---
            if (response.success && response.data.has_access) {
                // Access granted by PHP, execute the original action
                if (typeof callback === 'function') {
                    callback();
                }
                // Note: Don't re-enable the button here if the callback redirects or leads to another action
            } else {
                 // Access denied by PHP (or AJAX failed partially), show alert
                 var practiceInProgress = false; // Allow redirect (safe to declare here)
                 var reasonCode = (response.data && response.data.reason_code) ? response.data.reason_code : 'unknown';
                 var alertTitle = 'Access Denied!';
                 var alertMessage = '';

                 // Customize message based on reason code
                 switch (reasonCode) {
                    case 'expired_or_inactive':
                        alertTitle = 'Plan Expired or Inactive';
                        alertMessage = 'Your access plan has expired or is currently inactive.';
                        break;
                    case 'out_of_attempts':
                        alertTitle = 'Out of Attempts!';
                        alertMessage = 'You have run out of attempts for your current plan(s).';
                        break;
                    case 'no_entitlements':
                         alertTitle = 'No Active Plan';
                         alertMessage = 'You do not have an active plan granting access.';
                         break;
                    case 'not_logged_in':
                        alertTitle = 'Not Logged In';
                        alertMessage = 'You must be logged in to access this feature.';
                        // Hide purchase link if not logged in
                        purchaseUrl = ''; // Clear purchase URL
                        break;
                    default: // Includes 'unknown' or unexpected codes
                        alertMessage = 'Access denied. Please check your plan details or contact support.';
                 }

                 // Construct purchase link URL using localized object
                 var purchaseUrl = (typeof qp_ajax_object !== 'undefined' && qp_ajax_object.shop_page_url) ? qp_ajax_object.shop_page_url : '';
                 var purchaseLinkHtml = purchaseUrl ? ' <a href="' + purchaseUrl + '">Purchase Access</a>' : '';

                 Swal.fire({
                    title: alertTitle,
                    html: alertMessage + purchaseLinkHtml, // Append purchase link if available
                    icon: 'error',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                });

                // Reset button if provided (remains the same)
                if (button && originalText) {
                   if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                       button.prop('disabled', false).val(originalText);
                   } else {
                       button.prop('disabled', false).text(originalText);
                   }
                }
            }
            // --- END NEW LOGIC ---
        },
        error: function() {
            // Handle AJAX error during check
            Swal.fire('Error!', 'Could not verify your access. Please try again.', 'error');
             if (button && originalText) {
                 if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                     button.prop('disabled', false).val(originalText);
                 } else {
                     button.prop('disabled', false).text(originalText);
                 }
             }
        }
    });
}