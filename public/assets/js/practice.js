jQuery(document).ready(function($) {

    var wrapper = $('#qp-practice-app-wrapper');
    
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

    // --- Event Handlers ---

    // Logic for the timer checkbox on the settings form
    wrapper.on('change', '#qp_timer_enabled_cb', function() {
        if ($(this).is(':checked')) {
            $('#qp-timer-input-wrapper').slideDown();
        } else {
            $('#qp-timer-input-wrapper').slideUp();
        }
    });

    // Logic for submitting the settings form to start the practice
    wrapper.on('submit', '#qp-start-practice-form', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        
        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'start_practice_session',
                nonce: qp_ajax_object.nonce,
                settings: formData
            },
            beforeSend: function() {
                wrapper.html('<p style="text-align:center; padding: 50px;">Setting up your session...</p>');
            },
            success: function(response) {
                if (response.success) {
                    wrapper.html(response.data.ui_html);
                    sessionID = response.data.session_id;
                    sessionQuestionIDs = response.data.question_ids;
                    sessionSettings = response.data.settings;
                    currentQuestionIndex = 0;
                    loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
                } else {
                    wrapper.html('<p style="text-align:center; color: red;">Error: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                wrapper.html('<p style="text-align:center; color: red;">An unknown error occurred. Please try again.</p>');
            }
        });
    });

    // Handle clicking an option
    wrapper.on('click', '.qp-options-area .option:not(.disabled)', function() {
        clearInterval(questionTimer);
        var selectedOption = $(this);
        var selectedOptionID = selectedOption.find('input[type="radio"]').val();
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        $('.qp-options-area .option').addClass('disabled');

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'check_answer', nonce: qp_ajax_object.nonce, session_id: sessionID, question_id: questionID, option_id: selectedOptionID },
            success: function(response) {
                if (response.success) {
                    if (response.data.is_correct) {
                        selectedOption.addClass('correct');
                        score += parseFloat(sessionSettings.marks_correct);
                        correctCount++;
                    } else {
                        selectedOption.addClass('incorrect');
                        $('input[value="' + response.data.correct_option_id + '"]').closest('.option').addClass('correct');
                        score += parseFloat(sessionSettings.marks_incorrect);
                        incorrectCount++;
                    }
                    updateHeaderStats();
                }
            }
        });
    });

    // Handle Next button click
    wrapper.on('click', '#qp-next-btn', function() {
        loadNextQuestion();
    });

    // Handle Skip button click
    wrapper.on('click', '#qp-skip-btn', function() {
        clearInterval(questionTimer);
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        $(this).prop('disabled', true);

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'skip_question', nonce: qp_ajax_object.nonce, session_id: sessionID, question_id: questionID },
            success: function() {
                skippedCount++;
                updateHeaderStats();
                loadNextQuestion();
            }
        });
    });

    // NEW: Handle End Practice button click
    wrapper.on('click', '#qp-end-practice-btn', function() {
        clearInterval(questionTimer);
        if (confirm('Are you sure you want to end this practice session?')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'end_practice_session', nonce: qp_ajax_object.nonce, session_id: sessionID },
                beforeSend: function() {
                    wrapper.html('<p style="text-align:center; padding: 50px;">Generating your results...</p>');
                },
                success: function(response) {
                    if (response.success) {
                        displaySummary(response.data);
                    } else {
                        alert('Could not end session. Please try again.');
                    }
                }
            });
        }
    });

    // Handle clicking an admin report button
wrapper.on('click', '.qp-button-admin-report', function() {
    var button = $(this);
    var labelNameToAdd = button.data('label');
    var questionID = sessionQuestionIDs[currentQuestionIndex];

    button.prop('disabled', true).css('opacity', 0.5);

    $.ajax({
        url: qp_ajax_object.ajax_url, type: 'POST',
        data: { 
            action: 'report_question_issue', 
            nonce: qp_ajax_object.nonce, 
            question_id: questionID,
            label_name: labelNameToAdd // Send the specific label name
        },
        success: function(response) {
            if (response.success) {
                // Give visual feedback without an alert for admins
                button.text('Labelled!');
            } else {
                alert('Error: ' + response.data.message);
                button.prop('disabled', false).css('opacity', 1);
            }
        },
        error: function() {
            alert('An error occurred.');
            button.prop('disabled', false).css('opacity', 1);
        }
    });
});

    // --- Helper Functions ---
    function loadQuestion(questionID) {
        if (!questionID) return;
        $('#qp-question-text-area').html('Loading...');
        $('.qp-options-area').empty();
        $('#qp-skip-btn').prop('disabled', false);

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'get_question_data', nonce: qp_ajax_object.nonce, question_id: questionID },
            success: function(response) {
                if(response.success) {
                    var questionData = response.data.question;
                    var directionBox = $('.qp-direction');
                    if (questionData.direction_text) {
                        directionBox.html($('<p>').text(questionData.direction_text)).show();
                    } else {
                        directionBox.hide();
                    }
                    $('#qp-question-subject').text('Subject: ' + questionData.subject_name);
                    $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id);
                    $('#qp-question-text-area').html(questionData.question_text);
                    var optionsArea = $('.qp-options-area');
                    optionsArea.empty();
                    $.each(questionData.options, function(index, option) {
                        var optionHtml = $('<label class="option"></label>').append($('<input type="radio" name="qp_option">').val(option.option_id), ' ' + option.option_text);
                        optionsArea.append(optionHtml);
                    });
                    if (sessionSettings.timer_enabled) {
                        startTimer(sessionSettings.timer_seconds);
                    }
                }
            }
        });
    }
    
    function loadNextQuestion() {
    currentQuestionIndex++;
    if (currentQuestionIndex >= sessionQuestionIDs.length) {
        // Use a more user-friendly confirmation dialog
        if (confirm("Congratulations, you've completed all available questions! Click OK to end this session and see your summary.")) {
            // If user clicks OK, trigger the existing end practice logic
            $('#qp-end-practice-btn').click();
        }
        return; 
    }
    loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
}
    
    function updateHeaderStats() {
        $('#qp-score').text(score.toFixed(2));
        $('#qp-correct-count').text(correctCount);
        $('#qp-incorrect-count').text(incorrectCount);
        $('#qp-skipped-count').text(skippedCount);
    }
    
    function startTimer(seconds) {
        $('.timer-stat').show();
        var remainingTime = seconds;
        function updateDisplay() {
            var minutes = Math.floor(remainingTime / 60);
            var secs = remainingTime % 60;
            $('#qp-timer').text(String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0'));
        }
        updateDisplay();
        questionTimer = setInterval(function() {
            remainingTime--;
            updateDisplay();
            if (remainingTime <= 0) {
                clearInterval(questionTimer);
                $('#qp-skip-btn').click();
            }
        }, 1000);
    }

    // NEW: Function to display the summary screen
    // In public/assets/js/practice.js

function displaySummary(summaryData) {
    // Note the added 'qp-button' class to both links
    var summaryHtml = `
        <div class="qp-summary-wrapper">
            <h2>Session Summary</h2>
            <div class="qp-summary-score">
                <div class="label">Final Score</div>
                ${parseFloat(summaryData.final_score).toFixed(2)}
            </div>
            <div class="qp-summary-stats">
                <div class="stat"><div class="value">${summaryData.total_attempted}</div><div class="label">Attempted</div></div>
                <div class="stat"><div class="value">${summaryData.correct_count}</div><div class="label">Correct</div></div>
                <div class="stat"><div class="value">${summaryData.incorrect_count}</div><div class="label">Incorrect</div></div>
                <div class="stat"><div class="value">${summaryData.skipped_count}</div><div class="label">Skipped</div></div>
            </div>
            <div class="qp-summary-actions">
                <a href="/dashboard/" class="qp-button qp-button-secondary">View Dashboard</a>
                <a href="" class="qp-button qp-button-primary">Start Another Practice</a>
            </div>
        </div>
    `;
    wrapper.html(summaryHtml);
}
});