jQuery(document).ready(function($) {

    var wrapper = $('#qp-practice-app-wrapper');
    var sessionQuestions = [];
    var currentQuestionIndex = 0;

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
                    // Inject the main UI shell
                    wrapper.html(response.data.ui_html);
                    // Store the session questions and load the first one
                    sessionQuestions = response.data.questions;
                    currentQuestionIndex = 0;
                    loadQuestion(sessionQuestions[currentQuestionIndex]);
                } else {
                    wrapper.html('<p style="text-align:center; color: red;">Error: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                wrapper.html('<p style="text-align:center; color: red;">An unknown error occurred. Please try again.</p>');
            }
        });
    });

    /**
     * Loads a given question's data into the UI.
     * @param {object} questionData The question data object from the server.
     */
    function loadQuestion(questionData) {
        if (!questionData) return;

        // Update direction
        var directionBox = $('.qp-direction');
        if (questionData.direction_text) {
            directionBox.html('<p>' + questionData.direction_text + '</p>').show();
        } else {
            directionBox.hide();
        }

        // Update question meta
        $('#qp-question-subject').text('Subject: ' + questionData.subject_name);
        $('#qp-question-id').text('Question ID: ' + questionData.custom_question_id);
        $('#qp-question-text-area').html(questionData.question_text);
        
        // Update options
        var optionsArea = $('.qp-options-area');
        optionsArea.empty(); // Clear previous options
        
        $.each(questionData.options, function(index, option) {
            var optionHtml = '<label class="option">' +
                                '<input type="radio" name="qp_option" value="' + option.option_id + '"> ' +
                                option.option_text +
                             '</label>';
            optionsArea.append(optionHtml);
        });
    }
});