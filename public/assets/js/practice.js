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
    var answeredStates = {}; // Stores the state for each question ID {type: 'answered'/'skipped'/'reported'/'admin_labelled', ...}
    var practiceInProgress = false;

    // --- Event Handlers ---

    // Handles form submission to start a session
    wrapper.on('submit', '#qp-start-practice-form', function(e) {
        e.preventDefault();
        practiceInProgress = true; // Set the flag to prevent accidental page refresh
        var formData = $(this).serialize();
        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'start_practice_session', nonce: qp_ajax_object.nonce, settings: formData },
            beforeSend: function() { wrapper.html('<p style="text-align:center; padding: 50px;">Setting up your session...</p>'); },
            success: function(response) {
                if (response.success) {
                    wrapper.html(response.data.ui_html);
                    sessionID = response.data.session_id; sessionQuestionIDs = response.data.question_ids; sessionSettings = response.data.settings;
                    currentQuestionIndex = 0; score = 0; correctCount = 0; incorrectCount = 0; skippedCount = 0; answeredStates = {};
                    updateHeaderStats(); loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
                } else { 
                    practiceInProgress = false; // Reset on error
                    wrapper.html('<p style="text-align:center; color: red;">Error: ' + response.data.message + '</p>'); 
                }
            }
        });
    });

    // Handles clicking an answer option
    wrapper.on('click', '.qp-options-area .option:not(.disabled)', function() {
        clearInterval(questionTimer);
        var selectedOption = $(this);
        var selectedOptionID = selectedOption.find('input[type="radio"]').val();
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        
        $('.qp-options-area .option, #qp-skip-btn, .qp-user-report-btn, .qp-admin-report-btn').prop('disabled', true);
        $('#qp-next-btn').prop('disabled', false);

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'check_answer', nonce: qp_ajax_object.nonce, session_id: sessionID, question_id: questionID, option_id: selectedOptionID },
            success: function(response) {
                if (response.success) {
                    answeredStates[questionID] = { type: 'answered', is_correct: response.data.is_correct, correct_option_id: response.data.correct_option_id, selected_option_id: selectedOptionID };
                    if (response.data.is_correct) {
                        selectedOption.addClass('correct'); score += parseFloat(sessionSettings.marks_correct); correctCount++;
                    } else {
                        selectedOption.addClass('incorrect'); $('input[value="' + response.data.correct_option_id + '"]').closest('.option').addClass('correct'); score += parseFloat(sessionSettings.marks_incorrect); incorrectCount++;
                    }
                    updateHeaderStats();
                }
            }
        });
    });

    // Handles navigation clicks
    wrapper.on('click', '#qp-next-btn, #qp-prev-btn', function() {
        clearInterval(questionTimer);
        if ($(this).attr('id') === 'qp-next-btn') {
            loadNextQuestion();
        } else {
            if(currentQuestionIndex > 0) { currentQuestionIndex--; loadQuestion(sessionQuestionIDs[currentQuestionIndex]); }
        }
    });

    // UNIFIED HANDLER for Skip and all Report buttons
    wrapper.on('click', '#qp-skip-btn, .qp-user-report-btn, .qp-admin-report-btn', function() {
        clearInterval(questionTimer);
        var $button = $(this);
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        var isAdminAction = $button.hasClass('qp-admin-report-btn');
        var ajaxAction;

        if (isAdminAction) {
            ajaxAction = 'report_question_issue';
        } else {
            ajaxAction = $button.attr('id') === 'qp-skip-btn' ? 'skip_question' : 'report_and_skip_question';
        }
        
        $('.qp-footer-nav button, .qp-user-report-btn, .qp-admin-report-btn').prop('disabled', true);
        if ($button.data('label')) $button.text('Processing...');

        // If the question was already answered, reverse the score before user reports/skips it
        if (!isAdminAction && answeredStates[questionID] && answeredStates[questionID].type === 'answered') {
            if (answeredStates[questionID].is_correct) { score -= parseFloat(sessionSettings.marks_correct); correctCount--; }
            else { score -= parseFloat(sessionSettings.marks_incorrect); incorrectCount--; }
        }

        var ajaxData = {
            action: ajaxAction, nonce: qp_ajax_object.nonce, session_id: sessionID,
            question_id: questionID, label_name: $button.data('label') || ''
        };

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: ajaxData,
            success: function(response) {
                if(response.success) {
                    if (isAdminAction) {
                        // For admin, just mark that it was labelled and reload the question to show disabled report buttons
                        answeredStates[questionID] = answeredStates[questionID] || { type: 'viewed' };
                        answeredStates[questionID].reported_as = answeredStates[questionID].reported_as || [];
                        if (answeredStates[questionID].reported_as.indexOf($button.data('label')) === -1) {
                            answeredStates[questionID].reported_as.push($button.data('label'));
                        }
                        loadQuestion(questionID); // Reload the same question to update button states
                    } else {
                        // For user skip/report, it always becomes a "skipped" question
                        if (!answeredStates[questionID] || answeredStates[questionID].type === 'answered') {
                            skippedCount++;
                        }
                        answeredStates[questionID] = { type: 'skipped', reported: true };
                        updateHeaderStats();
                        loadNextQuestion();
                    }
                } else {
                    alert('Error: ' + (response.data.message || 'An error occurred.'));
                    // Re-enable buttons if the server call fails
                    $('.qp-footer-nav button, .qp-user-report-btn, .qp-admin-report-btn').prop('disabled', false);
                }
            }
        });
    });
    
    wrapper.on('click', '#qp-end-practice-btn', function() {
        practiceInProgress = false; // Session is ending
        clearInterval(questionTimer);
        if (confirm('Are you sure you want to end this practice session?')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'end_practice_session', nonce: qp_ajax_object.nonce, session_id: sessionID },
                beforeSend: function() { wrapper.html('<p style="text-align:center; padding: 50px;">Generating your results...</p>'); },
                success: function(response) { if (response.success) { displaySummary(response.data); } }
            });
        } else {
            practiceInProgress = true; // User cancelled, so session continues
        }
    });

    $(window).on('beforeunload', function() {
        if (practiceInProgress) {
            return "Are you sure you want to leave? Your practice session is in progress and will be lost.";
        }
    });

    // --- Helper Functions ---
    function loadQuestion(questionID) {
        if (!questionID) return;
        
        $('#qp-question-text-area').html('Loading...');
        $('.qp-options-area').empty();
        $('#qp-revision-indicator, .qp-direction, #qp-reported-indicator').hide();
        $('.qp-user-report-btn').text(function() { return $(this).data('label'); });
        $('.qp-admin-report-btn').text(function() { return $(this).data('label'); });
        $('.qp-footer-nav button, .qp-user-report-btn, .qp-admin-report-btn').prop('disabled', false);
        
        var previousState = answeredStates[questionID];

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'get_question_data', nonce: qp_ajax_object.nonce, question_id: questionID },
            success: function(response) {
                if(response.success) {
                    var questionData = response.data.question;
                    if (sessionSettings.revise_mode && response.data.is_revision) { $('#qp-revision-indicator').show(); }
                    if (questionData.direction_text) { $('.qp-direction').html($('<p>').text(questionData.direction_text)).show(); }
                    if (questionData.direction_image_url) { $('.qp-direction').append($('<img>').attr('src', questionData.direction_image_url).css('max-width', '100%')); }
                    $('#qp-question-subject').text('Subject: ' + questionData.subject_name);
                    $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id);
                    $('#qp-question-text-area').html(questionData.question_text);
                    
                    var optionsArea = $('.qp-options-area');
                    optionsArea.empty();
                    $.each(questionData.options, function(index, option) {
                        var optionHtml = $('<label class="option"></label>').append($('<input type="radio" name="qp_option">').val(option.option_id), ' ' + option.option_text);
                        if (previousState && previousState.type === 'answered' && previousState.selected_option_id == option.option_id) {
                            optionHtml.find('input').prop('checked', true);
                        }
                        optionsArea.append(optionHtml);
                    });

                    // Manage button states based on history
                    if (previousState) {
                        $('.qp-options-area .option, #qp-skip-btn').prop('disabled', true);
                        if (previousState.type === 'answered') {
                            if (previousState.is_correct) { $('input[value="' + previousState.selected_option_id + '"]').closest('.option').addClass('correct'); }
                            else { $('input[value="' + previousState.selected_option_id + '"]').closest('.option').addClass('incorrect'); $('input[value="' + previousState.correct_option_id + '"]').closest('.option').addClass('correct'); }
                        } else if (previousState.type === 'skipped') {
                            $('#qp-reported-indicator').show();
                        }
                        // Disable any report buttons that have been used for this question
                        if (previousState.reported_as && previousState.reported_as.length > 0) {
                            previousState.reported_as.forEach(function(labelName) {
                                $('.qp-report-button[data-label="' + labelName + '"]').prop('disabled', true).text('Reported');
                            });
                        }
                    } else {
                        if (sessionSettings.timer_enabled) { startTimer(sessionSettings.timer_seconds); }
                    }
                    
                    $('#qp-prev-btn').prop('disabled', currentQuestionIndex === 0);
                    $('#qp-next-btn').prop('disabled', !previousState);
                }
            }
        });
    }
    
    function loadNextQuestion() {
        currentQuestionIndex++;
        if (currentQuestionIndex >= sessionQuestionIDs.length) {
            if (confirm("Congratulations, you've completed all available questions! Click OK to end this session and see your summary.")) {
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
                if (confirm("Time's up for this question! Click OK to move to the next question.")) {
                    $('#qp-skip-btn').click();
                }
            }
        }, 1000);
    }

    function displaySummary(summaryData) {
        practiceInProgress = false;
        var summaryHtml = `
            <div class="qp-summary-wrapper">
                <h2>Session Summary</h2>
                <div class="qp-summary-score"><div class="label">Final Score</div>${parseFloat(summaryData.final_score).toFixed(2)}</div>
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
            </div>`;
        wrapper.html(summaryHtml);
    }
});