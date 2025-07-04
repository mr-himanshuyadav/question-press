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
    var questionTimer; // For the setInterval

    // --- Event Handlers ---

    wrapper.on('submit', '#qp-start-practice-form', function(e) { /* This function is unchanged */ });

    // Handle clicking an option
    wrapper.on('click', '.qp-options-area .option:not(.disabled)', function() {
        clearInterval(questionTimer); // Stop the timer
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

    wrapper.on('click', '#qp-next-btn', function() { /* This function is unchanged */ });

    // Handle Skip button click
    wrapper.on('click', '#qp-skip-btn', function() {
        clearInterval(questionTimer); // Stop the timer
        var questionID = sessionQuestionIDs[currentQuestionIndex];
        
        // Disable button to prevent multiple clicks
        $(this).prop('disabled', true);

        // Save skip to the database
        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'skip_question', nonce: qp_ajax_object.nonce, session_id: sessionID, question_id: questionID },
            success: function() {
                skippedCount++;
                updateHeaderStats();
                loadNextQuestion(); // Load the next question
            }
        });
    });


    // --- Helper Functions ---

    function loadQuestion(questionID) {
        if (!questionID) return;
        $('#qp-question-text-area').html('Loading...');
        $('.qp-options-area').empty();
        $('#qp-skip-btn').prop('disabled', false); // Re-enable skip button

        $.ajax({
            url: qp_ajax_object.ajax_url, type: 'POST',
            data: { action: 'get_question_data', nonce: qp_ajax_object.nonce, question_id: questionID },
            success: function(response) {
                if(response.success) {
                    var questionData = response.data.question;
                    // ... (rest of the UI population logic is the same)
                    // NEW: Start the timer if enabled for the session
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
            alert("Congratulations, you've completed all questions!");
            return;
        }
        loadQuestion(sessionQuestionIDs[currentQuestionIndex]);
    }
    
    function updateHeaderStats() { /* This function is unchanged */ }
    
    // NEW: Timer functionality
    function startTimer(seconds) {
        if (sessionSettings.timer_enabled) {
            $('.timer-stat').show();
            var remainingTime = seconds;
            
            function updateDisplay() {
                var minutes = Math.floor(remainingTime / 60);
                var secs = remainingTime % 60;
                $('#qp-timer').text(String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0'));
            }

            updateDisplay(); // Initial display

            questionTimer = setInterval(function() {
                remainingTime--;
                updateDisplay();
                if (remainingTime <= 0) {
                    clearInterval(questionTimer);
                    $('#qp-skip-btn').click(); // Auto-skip when time runs out
                }
            }, 1000);
        }
    }
});