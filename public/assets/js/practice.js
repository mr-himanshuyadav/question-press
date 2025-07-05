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
    var answeredStates = {};

    // --- Event Handlers ---

    wrapper.on('submit', '#qp-start-practice-form', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        
        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'start_practice_session', nonce: qp_ajax_object.nonce, settings: formData },
            beforeSend: function() { wrapper.html('<p style="text-align:center; padding: 50px;">Setting up your session...</p>'); },
            success: function(response) {
                if (response.success) {
                    wrapper.html(response.data.ui_html);
                    sessionID = response.data.session_id;
                    sessionQuestionIDs = response.data.question_ids;
                    sessionSettings = response.data.settings;
                    currentQuestionIndex = 0; score = 0; correctCount = 0; incorrectCount = 0; skippedCount = 0; answeredStates = {};
                    updateHeaderStats();
                    loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
                } else {
                    wrapper.html('<p style="text-align:center; color: red;">Error: ' + response.data.message + '</p>');
                }
            }
        });
    });

    wrapper.on('click', '.qp-options-area .option:not(.disabled)', function() {
        clearInterval(questionTimer);
        var selectedOption = $(this);
        var selectedOptionID = selectedOption.find('input[type="radio"]').val();
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        
        $('.qp-options-area .option').addClass('disabled');
        $('#qp-skip-btn').prop('disabled', true);
        $('#qp-next-btn').prop('disabled', false);

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'check_answer', nonce: qp_ajax_object.nonce, session_id: sessionID, question_id: questionID, option_id: selectedOptionID },
            success: function(response) {
                if (response.success) {
                    answeredStates[questionID] = { is_correct: response.data.is_correct, correct_option_id: response.data.correct_option_id, selected_option_id: selectedOptionID };
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

    wrapper.on('click', '#qp-next-btn, #qp-prev-btn', function() {
        clearInterval(questionTimer);
        var isNext = $(this).attr('id') === 'qp-next-btn';
        if (isNext) {
            loadNextQuestion();
        } else {
            if(currentQuestionIndex > 0) {
                currentQuestionIndex--;
                loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
            }
        }
    });

    wrapper.on('click', '#qp-skip-btn', function() {
        handleSkipOrReport('skip_question', $(this));
    });

    wrapper.on('click', '.qp-user-report-btn', function() {
        handleSkipOrReport('report_and_skip_question', $(this));
    });
    
    wrapper.on('click', '.qp-admin-report-btn', function() {
        handleSkipOrReport('report_question_issue', $(this));
    });
    
    wrapper.on('click', '#qp-end-practice-btn', function() {
        clearInterval(questionTimer);
        if (confirm('Are you sure you want to end this practice session?')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'end_practice_session', nonce: qp_ajax_object.nonce, session_id: sessionID },
                beforeSend: function() { wrapper.html('<p style="text-align:center; padding: 50px;">Generating your results...</p>'); },
                success: function(response) { if (response.success) { displaySummary(response.data); } else { alert('Could not end session. Please try again.'); } }
            });
        }
    });

    // --- Helper Functions ---

    /**
     * UNIFIED HANDLER FOR ALL SKIP/REPORT ACTIONS
     */
    function handleSkipOrReport(ajaxAction, $button) {
        clearInterval(questionTimer);
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        
        // Lock all action buttons
        $('.qp-footer-nav button, .qp-user-report-btn, .qp-admin-report-btn').prop('disabled', true);
        if ($button) $button.text('Processing...');

        var ajaxData = {
            action: ajaxAction,
            nonce: qp_ajax_object.nonce,
            session_id: sessionID,
            question_id: questionID,
            label_name: $button ? $button.data('label') : ''
        };

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    answeredStates[questionID] = { is_skipped: true }; // Mark as skipped so score is not affected
                    skippedCount++;
                    updateHeaderStats();
                    loadNextQuestion();
                } else {
                    alert('Error: ' + (response.data.message || 'An error occurred.'));
                    $('.qp-footer-nav button, .qp-user-report-btn, .qp-admin-report-btn').prop('disabled', false); // Re-enable on failure
                }
            }
        });
    }

    function loadQuestion(questionID) {
        if (!questionID) return;
        $('#qp-question-text-area').html('Loading...');
        $('.qp-options-area').empty();
        $('#qp-revision-indicator, .qp-direction').hide();
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
                        if (previousState && previousState.selected_option_id == option.option_id) {
                            optionHtml.find('input').prop('checked', true);
                        }
                        optionsArea.append(optionHtml);
                    });

                    if (previousState) {
                        $('.qp-options-area .option').addClass('disabled');
                        $('#qp-skip-btn').prop('disabled', true);
                        if (previousState.is_correct) { $('input[value="' + previousState.selected_option_id + '"]').closest('.option').addClass('correct'); }
                        else if(previousState.is_correct === false) { $('input[value="' + previousState.selected_option_id + '"]').closest('.option').addClass('incorrect'); $('input[value="' + previousState.correct_option_id + '"]').closest('.option').addClass('correct'); }
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
                    handleSkipOrReport('skip_question', null); // Use unified handler
                }
            }
        }, 1000);
    }

    function displaySummary(summaryData) {
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