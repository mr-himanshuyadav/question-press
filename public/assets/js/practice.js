jQuery(document).ready(function ($) {
  var wrapper = $("#qp-practice-app-wrapper");
  var isAutoCheckEnabled = false;
  var mockTestTimer; // Specific timer for mock tests
  var isMockTest = false;

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

  // --- MOCK TEST TIMER ---
  function startMockTestTimer(endTimeUTC) {
    var timerEl = $("#qp-mock-test-timer");
    mockTestTimer = setInterval(function () {
      // Get the current time in seconds since epoch, synced with UTC
      var nowUTC = Math.floor(new Date().getTime() / 1000);
      var secondsRemaining = endTimeUTC - nowUTC;

      if (secondsRemaining <= 0) {
        clearInterval(mockTestTimer);
        timerEl.text("00:00:00");
        alert("Time's up! Your test will be submitted automatically.");
        endSession(true); // Automatically end the session
        return;
      }
      var hours = Math.floor(secondsRemaining / 3600);
      var minutes = Math.floor((secondsRemaining % 3600) / 60);
      var seconds = secondsRemaining % 60;
      timerEl.text(
        String(hours).padStart(2, "0") +
          ":" +
          String(minutes).padStart(2, "0") +
          ":" +
          String(seconds).padStart(2, "0")
      );
    }, 1000);
  }

  // --- NEW: Helper function to update mock test question status ---
  function updateMockStatus(questionID, newStatus) {
    if (!isMockTest) return;

    // Update local state immediately for instant UI feedback
    if (!answeredStates[questionID]) {
      answeredStates[questionID] = {};
    }
    answeredStates[questionID].mock_status = newStatus;

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
    });
  }
  // --- LOGIC FOR REVISION FORM DROPDOWNS ---
  // --- LOGIC FOR MOCK TEST FORM DROPDOWNS ---
  $("#qp_subject_dropdown_mock").on(
    "change",
    'input[type="checkbox"]',
    function () {
      var $this = $(this);
      var $list = $this.closest(".qp-multi-select-list");

      // Handle "All Subjects" logic
      if ($this.val() === "all") {
        if ($this.is(":checked")) {
          $list
            .find('input[value!="all"]')
            .prop("checked", false)
            .prop("disabled", true);
        } else {
          $list.find('input[value!="all"]').prop("disabled", false);
        }
      } else {
        if ($this.is(":checked")) {
          $list.find('input[value="all"]').prop("checked", false);
        }
      }

      var selectedSubjects = [];
      $("#qp_subject_dropdown_mock input:checked").each(function () {
        selectedSubjects.push($(this).val());
      });

      var $topicGroup = $("#qp-topic-group-mock");
      var $topicListContainer = $("#qp_topic_list_container_mock");
      var $topicButton = $("#qp_topic_dropdown_mock .qp-multi-select-button");

      // Update the Subject button text
      updateButtonText(
        $("#qp_subject_dropdown_mock .qp-multi-select-button"),
        "-- Please select --",
        "Subject"
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
              $topicListContainer.append(
                '<label><input type="checkbox" name="mock_topics[]" value="all"> All Topics</label>'
              );
              $.each(response.data.topics, function (subjectName, topics) {
                $topicListContainer.append(
                  '<div class="qp-topic-group-header">' + subjectName + "</div>"
                );
                $.each(topics, function (i, topic) {
                  $topicListContainer.append(
                    '<label><input type="checkbox" name="mock_topics[]" value="' +
                      topic.topic_id +
                      '"> ' +
                      topic.topic_name +
                      "</label>"
                  );
                });
              });
              updateButtonText($topicButton, "-- Select Topic(s) --", "Topic");
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
  );

  // Handle the "All Topics" checkbox for the mock test form
  $("#qp_topic_list_container_mock").on(
    "change",
    'input[value="all"]',
    function () {
      var $this = $(this);
      var $list = $this.closest(".qp-multi-select-list");
      if ($this.is(":checked")) {
        $list
          .find('input[value!="all"]')
          .prop("checked", false)
          .prop("disabled", true);
      } else {
        $list.find('input[value!="all"]').prop("disabled", false);
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
  // --- CONSOLIDATED LOGIC FOR REVISION FORM SUBJECT DROPDOWN ---
  $("#qp_subject_dropdown_revision").on(
    "change",
    'input[type="checkbox"]',
    function () {
      var $this = $(this);
      var $list = $this.closest(".qp-multi-select-list");

      // Handle the "All Subjects" logic first
      if ($this.val() === "all") {
        if ($this.is(":checked")) {
          // If "All Subjects" is checked, uncheck and disable all others
          $list
            .find('input[value!="all"]')
            .prop("checked", false)
            .prop("disabled", true);
        } else {
          // If "All Subjects" is unchecked, enable all others
          $list.find('input[value!="all"]').prop("disabled", false);
        }
      } else {
        // If any other checkbox is checked, uncheck "All Subjects"
        if ($this.is(":checked")) {
          $list.find('input[value="all"]').prop("checked", false);
        }
      }

      // Now, proceed with updating the topics dropdown based on the current selection
      var selectedSubjects = [];
      $("#qp_subject_dropdown_revision input:checked").each(function () {
        selectedSubjects.push($(this).val());
      });

      var $topicGroup = $("#qp-topic-group-revision");
      var $topicListContainer = $("#qp_topic_list_container_revision");
      var $topicButton = $(
        "#qp_topic_dropdown_revision .qp-multi-select-button"
      );

      // Update the Subject button text
      updateButtonText(
        $("#qp_subject_dropdown_revision .qp-multi-select-button"),
        "-- Please select --",
        "Subject"
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
              $topicListContainer.append(
                '<label><input type="checkbox" name="revision_topics[]" value="all"> All Topics</label>'
              );
              $.each(response.data.topics, function (subjectName, topics) {
                $topicListContainer.append(
                  '<div class="qp-topic-group-header">' + subjectName + "</div>"
                );
                $.each(topics, function (i, topic) {
                  $topicListContainer.append(
                    '<label><input type="checkbox" name="revision_topics[]" value="' +
                      topic.topic_id +
                      '"> ' +
                      topic.topic_name +
                      "</label>"
                  );
                });
              });
              updateButtonText($topicButton, "-- Select Topic(s) --", "Topic");
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
  );
  // --- NEW: Add handler for the "All Topics" checkbox behavior ---
  $("#qp_topic_list_container_revision").on(
    "change",
    'input[value="all"]',
    function () {
      var $this = $(this);
      var $list = $this.closest(".qp-multi-select-list");
      if ($this.is(":checked")) {
        // If "All Topics" is checked, uncheck and disable all others
        $list
          .find('input[value!="all"]')
          .prop("checked", false)
          .prop("disabled", true);
      } else {
        // If "All Topics" is unchecked, enable all others
        $list.find('input[value!="all"]').prop("disabled", false);
      }
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
    $list.find("input:checked").each(function () {
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
        updateButton.Text($button, "-- Please select --", "Topic");
      }
    });
  });

  // Specific logic for the "All Subjects" checkbox
  $("#qp_subject_dropdown .qp-multi-select-list").on(
    "change",
    'input[value="all"]',
    function () {
      var $list = $(this).closest(".qp-multi-select-list");
      if ($(this).is(":checked")) {
        $list
          .find('input[value!="all"]')
          .prop("checked", false)
          .prop("disabled", true);
      } else {
        $list.find('input[value!="all"]').prop("disabled", false);
      }
    }
  );

  // Logic to fetch topics when subjects change
  $("#qp_subject_dropdown").on("change", 'input[type="checkbox"]', function () {
    var selectedSubjects = [];
    $("#qp_subject_dropdown input:checked").each(function () {
      selectedSubjects.push($(this).val());
    });

    var $topicGroup = $("#qp-topic-group");
    var $topicListContainer = $("#qp_topic_list_container");
    var $topicButton = $("#qp_topic_dropdown .qp-multi-select-button");
    var $sectionGroup = $("#qp-section-group");

    // Update the Subject button text
    updateButtonText(
      $("#qp_subject_dropdown .qp-multi-select-button"),
      "-- Please select --",
      "Subject"
    );

    // Always hide section group when subjects change
    $sectionGroup.slideUp();

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
            $.each(response.data.topics, function (subjectName, topics) {
              $topicListContainer.append(
                '<div class="qp-topic-group-header">' + subjectName + "</div>"
              );
              $.each(topics, function (i, topic) {
                $topicListContainer.append(
                  '<label><input type="checkbox" name="qp_topic[]" value="' +
                    topic.topic_id +
                    '"> ' +
                    topic.topic_name +
                    "</label>"
                );
              });
            });
            updateButtonText($topicButton, "-- Select Topic(s) --", "Topic");
            $topicGroup.slideDown();
          } else {
            updateButtonText($topicButton, "No Topics Found", "Topic");
            $topicGroup.slideUp();
          }
        },
      });
    } else {
      $topicGroup.slideUp();
    }
  });

  // **THE FIX**: This single handler listens for changes on BOTH dropdowns.
  wrapper.on(
    "change",
    '#qp_subject_dropdown input[type="checkbox"], #qp_topic_dropdown input[type="checkbox"]',
    function () {
      // Update the button text for whichever dropdown was changed
      var $dropdown = $(this).closest(".qp-multi-select-dropdown");
      if ($dropdown.attr("id") === "qp_subject_dropdown") {
        updateButtonText(
          $dropdown.find(".qp-multi-select-button"),
          "-- Please select --",
          "Subject"
        );
      } else {
        updateButtonText(
          $dropdown.find(".qp-multi-select-button"),
          "-- Select Topic(s) --",
          "Topic"
        );
      }

      // Now, check the visibility logic for the Section dropdown
      var $sectionGroup = $("#qp-section-group");
      var $sectionSelect = $("#qp_section");
      var selectedSubjects = $("#qp_subject_dropdown input:checked")
        .map((_, el) => $(el).val())
        .get();
      var selectedTopics = $("#qp_topic_list_container input:checked")
        .map((_, el) => $(el).val())
        .get();

      // Show Section select only if exactly ONE subject and ONE topic are selected
      if (
        selectedSubjects.length === 1 &&
        selectedSubjects[0] !== "all" &&
        selectedTopics.length === 1
      ) {
        // **THE FIX**: Add the AJAX call to populate the sections
        $.ajax({
          url: qp_ajax_object.ajax_url,
          type: "POST",
          data: {
            action: "get_sections_for_subject", // Reuse the existing AJAX handler
            nonce: qp_ajax_object.nonce,
            subject_id: selectedSubjects[0], // Pass the single selected subject ID
            topic_id: selectedTopics[0], // Pass the single selected topic ID
          },
          beforeSend: function () {
            $sectionSelect
              .prop("disabled", true)
              .html("<option>Loading sections...</option>");
            $sectionGroup.slideDown(); // Show the group with the loading message
          },
          success: function (response) {
            if (response.success && response.data.sections.length > 0) {
              $sectionSelect
                .prop("disabled", false)
                .empty()
                .append('<option value="all">All Sections</option>');
              $.each(response.data.sections, function (index, sec) {
                var optionText = sec.source_name + " / " + sec.section_name;
                $sectionSelect.append(
                  $("<option></option>").val(sec.section_id).text(optionText)
                );
              });
            } else {
              // If no sections are found, hide the dropdown again
              $sectionGroup.slideUp();
            }
          },
          error: function () {
            $sectionGroup.slideUp(); // Also hide on error
          },
        });
      } else {
        $sectionGroup.slideUp();
      }
    }
  );

  // Hide dropdowns when clicking outside
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".qp-multi-select-dropdown").length) {
      $(".qp-multi-select-list").hide();
    }
  });

  // --- MULTI-STEP FORM LOGIC ---
  if ($(".qp-multi-step-container").length) {
    var multiStepContainer = $(".qp-multi-step-container");

    // Enable the next button as soon as a mode is selected
    wrapper.on("change", 'input[name="practice_mode_selection"]', function () {
      if ($(this).is(":checked")) {
        $("#qp-step1-next-btn").prop("disabled", false);
      }
    });

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
          // 1. Update the local state for the current question
          var questionID = sessionQuestionIDs[currentQuestionIndex];
          if (typeof answeredStates[questionID] === "undefined") {
            answeredStates[questionID] = {};
          }
          answeredStates[questionID].reported = true;

          // 2. Manually update the UI to reflect the "reported" state
          $("#qp-reported-indicator").show();
          $(".qp-indicator-bar").show();
          $(".qp-options-area")
            .addClass("disabled")
            .find('input[type="radio"]')
            .prop("disabled", true);
          $("#qp-skip-btn, #qp-report-btn").prop("disabled", true);
          $("#qp-next-btn").prop("disabled", false);

          // 3. Close the modal
          $("#qp-report-modal-backdrop").fadeOut(200);

          // 4. Automatically move to the next question after a short delay
          setTimeout(function () {
            loadNextQuestion();
          }, 500); // 0.5 second delay before loading next question
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

    // Check if the topic dropdown is visible and if any topic is selected
    if ($("#qp-topic-group").is(":visible")) {
      var selectedTopics = $("#qp_topic_list_container input:checked").length;
      if (selectedTopics === 0) {
        alert(
          "Please select at least one topic to start the practice session."
        );
        return; // Stop the form submission
      }
    }
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
          // ** MODIFICATION START **
          // Check if the specific HTML block was sent in the response data.
          if (response.data && response.data.html) {
            // If so, replace the entire wrapper content with our friendly error page.
            wrapper.html(response.data.html);
          } else {
            // Otherwise, show a generic error message within the form.
            var errorMessage =
              '<p class="qp-error-message" style="color: red; text-align: center; margin-top: 1rem;">' +
              (response.data.message || "An unknown error occurred.") +
              "</p>";
            form.find(".qp-error-message").remove(); // Remove old errors
            form.append(errorMessage);
            submitButton.val(originalButtonText).prop("disabled", false);
          }
          // ** MODIFICATION END **
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

    // Check if the topic dropdown is visible and if any topic is selected
    if ($("#qp-topic-group-revision").is(":visible")) {
      var selectedTopics = $(
        "#qp_topic_list_container_revision input:checked"
      ).length;
      if (selectedTopics === 0) {
        alert(
          "Please select at least one topic to start the revision session."
        );
        return; // Stop the form submission
      }
    }
    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();

    var formData = $("#qp-start-revision-form").serialize(); // This will now correctly get all inputs from this specific form

    $.ajax({
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

  // Handler for MOCK TEST Mode Form
  wrapper.on("submit", "#qp-start-mock-test-form", function (e) {
    e.preventDefault();

    // Basic validation
    var selectedSubjects = $("#qp_subject_dropdown_mock input:checked").length;
    if (selectedSubjects === 0) {
      alert("Please select at least one subject to start the mock test.");
      return;
    }

    var form = $(this);
    var submitButton = form.find('input[type="submit"]');
    var originalButtonText = submitButton.val();
    var formData = form.serialize();

    $.ajax({
      url: qp_ajax_object.ajax_url,
      type: "POST",
      // Add the new action and nonce to the form data
      data:
        formData +
        "&action=qp_start_mock_test_session&nonce=" +
        qp_ajax_object.nonce,
      beforeSend: function () {
        submitButton.val("Building your test...").prop("disabled", true);
      },
      success: function (response) {
        if (response.success && response.data.redirect_url) {
          // If successful, redirect to the session page
          window.location.href = response.data.redirect_url;
        } else {
          // If there's an error, display it
          var errorMessage = response.data.html
            ? response.data.html
            : "<p>" +
              (response.data.message || "An unknown error occurred.") +
              "</p>";
          wrapper.html(errorMessage);
        }
      },
      error: function () {
        alert("A server error occurred. Please try again later.");
        submitButton.val(originalButtonText).prop("disabled", false);
      },
    });
  });

  // --- MOCK TEST TIMER ---
  function startMockTestTimer(endTimeUTC) {
    var timerEl = $("#qp-mock-test-timer");
    mockTestTimer = setInterval(function () {
      // Get the current time in UTC seconds every interval to avoid clock drift
      var nowUTC = Math.floor(new Date().getTime() / 1000);
      var secondsRemaining = endTimeUTC - nowUTC;

      if (secondsRemaining <= 0) {
        clearInterval(mockTestTimer);
        timerEl.text("00:00:00");
        alert("Time's up! Your test will be submitted automatically.");
        endSession(true); // Automatically end the session
        return;
      }
      // Format the remaining time into HH:MM:SS
      var hours = Math.floor(secondsRemaining / 3600);
      var minutes = Math.floor((secondsRemaining % 3600) / 60);
      var seconds = secondsRemaining % 60;
      timerEl.text(
        String(hours).padStart(2, "0") +
          ":" +
          String(minutes).padStart(2, "0") +
          ":" +
          String(seconds).padStart(2, "0")
      );
    }, 1000);
  }

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
  var remainingTime = 0;

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
  // Session Initialization
  if (typeof qp_session_data !== "undefined") {
    practiceInProgress = true;
    sessionID = qp_session_data.session_id;
    sessionQuestionIDs = qp_session_data.question_ids;
    sessionSettings = qp_session_data.settings;
    isMockTest = sessionSettings.practice_mode === "mock_test"; // Set our global flag

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

    // Restore user's previous answers from the database
    if (qp_session_data.attempt_history) {
      var lastAttemptedIndex = -1;
      for (var i = 0; i < sessionQuestionIDs.length; i++) {
        var qid = sessionQuestionIDs[i];
        if (qp_session_data.attempt_history[qid]) {
          var attempt = qp_session_data.attempt_history[qid];
          lastAttemptedIndex = i;

          // Store the raw attempt data. We will use it differently depending on the mode.
          answeredStates[qid] = {
            type: attempt.status,
            is_correct: parseInt(attempt.is_correct, 10) === 1,
            selected_option_id: attempt.selected_option_id,
            correct_option_id: attempt.correct_option_id,
            remainingTime: attempt.remaining_time,
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
      var potentialIndex = lastAttemptedIndex >= 0 ? lastAttemptedIndex + 1 : 0;
      currentQuestionIndex = Math.min(
        potentialIndex,
        sessionQuestionIDs.length - 1
      );
    }

    // Restore reported questions state
    if (
      qp_session_data.reported_ids &&
      qp_session_data.reported_ids.length > 0
    ) {
      $.each(qp_session_data.reported_ids, function (index, qid) {
        if (typeof answeredStates[qid] === "undefined") {
          answeredStates[qid] = {};
        }
        answeredStates[qid].reported = true;
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
    }
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

  function shuffleArray(array) {
    for (let i = array.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [array[i], array[j]] = [array[j], array[i]];
    }
    return array;
  }

  function renderQuestion(data, questionID) {
    if (!isMockTest) {
      clearInterval(questionTimer);
    }

    var questionData = data.question;
    var previousState = answeredStates[questionID] || {};
    var optionsArea = $(".qp-options-area").empty().removeClass("disabled");

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
    $("#qp-question-subject").html(
      "Subject: " +
        questionData.subject_name +
        (questionData.topic_name ? " / " + questionData.topic_name : "")
    );
    $("#qp-question-id").text(
      "Question ID: " + questionData.custom_question_id
    );
    // --- RESTORED: Source Metadata Display ---
    var sourceDisplayArea = $("#qp-question-source").hide().empty();
    if (
      data.is_admin &&
      (questionData.source_name ||
        questionData.section_name ||
        questionData.question_number_in_section)
    ) {
      var sourceInfo = [];
      if (questionData.source_name)
        sourceInfo.push("<strong>Source:</strong> " + questionData.source_name);
      if (questionData.section_name)
        sourceInfo.push(
          "<strong>Section:</strong> " + questionData.section_name
        );
      if (questionData.question_number_in_section)
        sourceInfo.push(
          "<strong>Q:</strong> " + questionData.question_number_in_section
        );
      if (sourceInfo.length > 0) {
        sourceDisplayArea.html(sourceInfo.join(" | ")).show();
      }
    }
    $("#qp-question-text-area").html(questionData.question_text);
    $("#qp-report-btn").prop("disabled", previousState.reported);

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
      $("#qp-mock-mark-review-cb")
        .prop("checked", isMarked)
        .closest("label")
        .toggleClass("checked", isMarked);

      // If the question has no mock_status yet, it's the first time it's being viewed.
      if (!previousState.mock_status) {
        updateMockStatus(questionID, "viewed");
      }
    } else {
      // Logic for Normal/Revision modes
      $("#qp-revision-indicator, #qp-question-source").hide();
      $("#qp-skip-btn").prop("disabled", false);
      $("#qp-next-btn").prop("disabled", true);
      if (isAutoCheckEnabled) {
        $("#qp-check-answer-btn").hide();
      } else {
        $("#qp-check-answer-btn").show().prop("disabled", true);
      }
      optionsArea.data("correct-option-id", data.correct_option_id);
      if (data.is_revision && !previousState.answered_in_session) {
        $("#qp-revision-indicator").show();
        $(".qp-indicator-bar").show();
      }
      $("#qp-mark-for-review-cb").prop("checked", data.is_marked_for_review);

      var isReported = previousState.reported;
      var isAnswered = previousState.type === "answered";
      var isExpired = previousState.type === "expired";
      var indicatorBar = $(".qp-indicator-bar").hide();
      if (isReported) {
        $("#qp-reported-indicator").show();
        indicatorBar.show();
      }
      if (isAnswered || isExpired || isReported) {
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
          indicatorBar.show();
        }
      } else {
        if (sessionSettings.timer_enabled) {
          var startTime =
            typeof previousState.remainingTime !== "undefined"
              ? previousState.remainingTime
              : sessionSettings.timer_seconds;
          $("#qp-timer-indicator").removeClass("expired").show();
          indicatorBar.show();
          startTimer(startTime);
        }
      }
    }

    $("#qp-prev-btn").prop("disabled", currentQuestionIndex === 0);
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
    // First, check if we are ALREADY on the last question.
    if (currentQuestionIndex >= sessionQuestionIDs.length - 1) {
      clearInterval(questionTimer);
      // If so, ask the user if they want to finish.
      if (
        confirm(
          "Congratulations, you've completed all available questions! Click OK to end this session and see your summary."
        )
      ) {
        practiceInProgress = false; // It's now safe to allow the page to be left
        $("#qp-end-practice-btn").click();
      }
      // If the user clicks "Cancel", we simply do nothing. They remain on the last question, and the UI is still responsive.
      return;
    }

    // If we are not on the last question, it's safe to increment the index and load the next question.
    currentQuestionIndex++;
    loadQuestion(sessionQuestionIDs[currentQuestionIndex], "next");
  }

  function updateHeaderStats() {
    $("#qp-score").text(score.toFixed(2));
    $("#qp-correct-count").text(correctCount);
    $("#qp-incorrect-count").text(incorrectCount);
    $("#qp-skipped-count").text(skippedCount);
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
    closeFullscreen();
    practiceInProgress = false;

    // Determine if the session was a mock test and if it was scored
    var settings = summaryData.settings || {};
    var isMockTest = settings.practice_mode === 'mock_test';
    var isScoredSession = settings.marks_correct !== null;

    var mainDisplayHtml = "";
    if (isScoredSession) {
        mainDisplayHtml = `<div class="qp-summary-score"><div class="label">Final Score</div>${parseFloat(summaryData.final_score).toFixed(2)}</div>`;
    } else {
        var accuracy = summaryData.total_attempted > 0 ? (summaryData.correct_count / summaryData.total_attempted) * 100 : 0;
        mainDisplayHtml = `<div class="qp-summary-score"><div class="label">Accuracy</div>${accuracy.toFixed(2)}%</div>`;
    }

    // --- NEW: Build the stats display based on the mode ---
    var statsHtml = '';
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
  wrapper.on("click", ".qp-options-area .option", function () {
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
        success: function () {
          // After successfully saving, update the detailed mock_status.
          updateMockStatus(questionID, newStatus);
        },
      });
    } else if (isAutoCheckEnabled) {
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
    $("#qp-next-btn").prop("disabled", false);

    // Sync attempt with the server in the background
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
    });
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

  wrapper.on("click", "#qp-next-btn, #qp-prev-btn", function () {
    clearInterval(questionTimer); // Stop the timer immediately
    var questionID = sessionQuestionIDs[currentQuestionIndex];
    var direction = $(this).attr("id") === "qp-next-btn" ? "next" : "prev";

    // **THE FIX**: Always ensure the state object exists, then save the timer's value.
    if (typeof answeredStates[questionID] === "undefined") {
      answeredStates[questionID] = {};
    }
    answeredStates[questionID].remainingTime = remainingTime;

    // Now, proceed with the AJAX call and navigation
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
      loadNextQuestion();
    }
  });

  function endSession(isAutoSubmit = false) {
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
          displaySummary(response.data);
        }
      },
      error: function () {
        alert("An error occurred while submitting the session.");
      },
    });
  }

  // A single handler for all session-ending buttons
  wrapper.on("click", "#qp-end-practice-btn, #qp-submit-test-btn", function () {
    var confirmMsg = isMockTest
      ? "Are you sure you want to submit your test? You cannot make any more changes."
      : "Are you sure you want to end this practice session?";

    if (confirm(confirmMsg)) {
      endSession(false);
    }
  });

  $(window).on("beforeunload", function () {
    if (practiceInProgress) {
      return "Are you sure you want to leave? Your practice session is in progress.";
    }
  });

  wrapper.on("click", "#qp-pause-btn", function () {
    if (
      confirm(
        "Are you sure you want to pause this session? You can resume it later from your dashboard."
      )
    ) {
      practiceInProgress = false; // Prevent the "are you sure?" popup on redirect

      $.ajax({
        url: qp_ajax_object.ajax_url,
        type: "POST",
        data: {
          action: "qp_pause_session",
          nonce: qp_ajax_object.nonce,
          session_id: sessionID,
        },
        beforeSend: function () {
          // Disable all footer buttons to prevent double-clicks
          $(".qp-footer-controls .qp-button")
            .prop("disabled", true)
            .text("Pausing...");
        },
        success: function (response) {
          if (response.success) {
            // Redirect to the dashboard page on success
            window.location.href = qp_ajax_object.dashboard_page_url;
          } else {
            alert(
              "Error: " +
                (response.data.message || "Could not pause the session.")
            );
            // Re-enable buttons if it fails
            $(".qp-footer-controls .qp-button").prop("disabled", false);
            $("#qp-pause-btn").text("Pause & Save"); // Restore text
          }
        },
        error: function () {
          alert("An unknown server error occurred.");
          $(".qp-footer-controls .qp-button").prop("disabled", false);
          $("#qp-pause-btn").text("Pause & Save");
        },
      });
    }
  });

  // --- NEW: Handler for the Fullscreen Start Button ---
  wrapper.on("click", "#qp-fullscreen-start-btn", function () {
    // 1. Enter fullscreen
    openFullscreen();

    // 2. Hide the overlay
    $("#qp-start-session-overlay").fadeOut(200);

    // 3. Show the now-prepared practice wrapper
    $(".qp-practice-wrapper").css("visibility", "visible");

    // 4. Start the timer if it's enabled for the first question
    if (sessionSettings.timer_enabled) {
      var firstQuestionID = sessionQuestionIDs[0];
      var firstQuestionState = answeredStates[firstQuestionID] || {};
      if (!firstQuestionState.type) {
        // Only start timer if first question isn't already answered/skipped
        startTimer(sessionSettings.timer_seconds);
      }
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

      // Clear the radio button selection in the UI
      $(".qp-options-area .option").removeClass("selected");
      $('input[name="qp_option"]').prop("checked", false);

      // Update the status based on whether "Mark for Review" is currently checked
      const isMarked = $("#qp-mock-mark-review-cb").is(":checked");
      const newStatus = isMarked ? "marked_for_review" : "viewed";

      // This AJAX call will set selected_option_id to NULL in the database
      // because its status is 'viewed', which our backend handler for clearing is looking for.
      updateMockStatus(questionID, "viewed");

      // If it was marked for review, we need to send a second update to keep that status
      if (isMarked) {
        setTimeout(function () {
          updateMockStatus(questionID, "marked_for_review");
        }, 100); // Small delay to ensure requests don't collide
      }
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
    });
  }


  // --- NEW: Mock Test Scoring Checkbox UI Toggle ---
wrapper.on('change', '#qp_mock_scoring_enabled_cb', function() {
    var isChecked = $(this).is(':checked');
    var marksWrapper = $('#qp-mock-marks-group-wrapper');
    if (isChecked) {
        marksWrapper.slideDown();
        marksWrapper.find('input').prop('disabled', false);
    } else {
        marksWrapper.slideUp();
        marksWrapper.find('input').prop('disabled', true);
    }
});


    // --- NEW: Question Palette Activation Logic ---
if (typeof qp_session_data !== "undefined") {
    var wrapper = $('.qp-practice-wrapper');
    var isMockTest = qp_session_data.settings.practice_mode === 'mock_test';
    var isSectionWise = qp_session_data.settings.practice_mode === 'Section Wise Practice';

    // If the palette is mandatory for the current mode, show it on page load.
    if (isMockTest || isSectionWise) {
        wrapper.addClass('palette-visible');
    }

    // Handle clicks on the toggle button (for non-mandatory modes)
    $('#qp-palette-toggle-btn').on('click', function() {
        wrapper.toggleClass('palette-visible');
    });
}


});
