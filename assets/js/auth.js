jQuery(document).ready(function($) {
    var signupForm = $('#qp-signup-form');
    if (!signupForm.length) {
        return; // Exit if not on the signup page
    }

    // --- Get all elements ---
    var usernameInput = $('#qp_reg_username');
    var emailInput = $('#qp_reg_email');
    var passInput = $('#qp_reg_password');
    var confirmPassInput = $('#qp_reg_confirm_password');
    var examSelect = $('#qp_reg_exam');
    var subjectSelect = $('#qp_reg_subject');
    var submitButton = signupForm.find('input[type="submit"]');
    
    // --- Get all error/icon elements ---
    var usernameError = $('#qp_username_error');
    var emailError = $('#qp_email_error');
    var passLengthError = $('#qp_password_length_error');
    var passMatchError = $('#qp_password_match_error');
    var subjectError = $('#qp_subject_error');
    var scopeError = $('#qp_scope_error'); // The main error for Exam/Subject
    var usernameIcon = $('.qp-validation-icon[data-for="qp_reg_username"]');
    var emailIcon = $('.qp-validation-icon[data-for="qp_reg_email"]');

    // --- Timers, AJAX holders, and Validation State ---
    var usernameTimer, emailTimer;
    var usernameAjaxRequest = null;
    var emailAjaxRequest = null;

    var validationState = {
        username: false,
        email: false,
        passwordLength: false,
        passwordMatch: false,
        scope: false // New state for exam/subject
    };

    /**
     * Checks the complete form validity and enables/disables the submit button.
     */
    function checkFormValidity() {
        // 1. Get all current values
        var examVal = examSelect.val();
        var subjectVals = subjectSelect.val() || [];
        var subjectCount = subjectVals.length;

        var newPass = passInput.val();
        var confirmPass = confirmPassInput.val();

        // 2. Password length
        validationState.passwordLength = (newPass.length >= 8);
        if (newPass.length > 0 && !validationState.passwordLength) {
            passLengthError.show();
        } else {
            passLengthError.hide();
        }

        // 3. Password match
        validationState.passwordMatch = (newPass.length > 0 && newPass === confirmPass);
        if (confirmPass.length > 0 && !validationState.passwordMatch) {
            passMatchError.show();
        } else {
            passMatchError.hide();
        }
        
        // 4. --- THIS IS THE ROBUST MUTUAL EXCLUSIVITY LOGIC ---
        if (examVal !== '') {
            // An exam is selected.
            examSelect.prop('disabled', false); // Keep exam enabled (so it can be deselected)
            subjectSelect.prop('disabled', true).val(null).trigger('qp:update_ui'); // Disable and clear subjects, update UI

            validationState.scope = true;
            scopeError.hide();
            subjectError.hide();

        } else if (subjectCount > 0) {
            // Subject(s) are selected.
            examSelect.prop('disabled', true).val(''); // Disable and clear exam
            subjectSelect.prop('disabled', false).trigger('qp:update_ui'); // Keep subjects enabled, update UI (for 5-limit)

            // Check subject limit
            if (subjectCount > 5) {
                subjectError.text('You can select a maximum of 5 subjects.').show();
                validationState.scope = false;
            } else {
                subjectError.hide();
                validationState.scope = true;
            }
            scopeError.hide();

        } else {
            // Neither is selected. Enable both.
            examSelect.prop('disabled', false);
            subjectSelect.prop('disabled', false).trigger('qp:update_ui'); // Enable subjects, update UI
            
            validationState.scope = false;
            scopeError.show(); // Show the main scope error
            subjectError.hide();
        }
        // --- END ROBUST LOGIC ---
        
        // 5. Check all conditions (username and email are set by their own async handlers)
        if (validationState.username && validationState.email && validationState.passwordLength && validationState.passwordMatch && validationState.scope) {
            submitButton.prop('disabled', false).val('Create Account');
        } else {
            submitButton.prop('disabled', true).val('Please complete all fields');
        }
    }

    /**
     * Reusable function to validate the username. (Unchanged)
     */
    function validateUsername(isImmediate) {
        clearTimeout(usernameTimer);
        if (usernameAjaxRequest) {
            usernameAjaxRequest.abort();
            usernameAjaxRequest = null;
        }

        var username = usernameInput.val();
        usernameIcon.removeClass('qp-valid qp-invalid').addClass('qp-loading');
        usernameError.hide();
        validationState.username = false; // Set to invalid during check

        if (username.length < 4) {
            usernameIcon.removeClass('qp-loading');
            usernameError.text('Must be at least 4 characters.').show();
            checkFormValidity();
            return;
        }

        var runValidation = function() {
            usernameAjaxRequest = $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: { action: 'qp_check_username', username: username },
                success: function(response) {
                    if (response.success) {
                        usernameIcon.removeClass('qp-loading qp-invalid').addClass('qp-valid');
                        usernameError.hide();
                        validationState.username = true;
                    } else {
                        usernameIcon.removeClass('qp-loading qp-valid').addClass('qp-invalid');
                        usernameError.text(response.data.message).show();
                        validationState.username = false;
                    }
                    checkFormValidity();
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus === 'abort') return;
                    usernameIcon.removeClass('qp-loading');
                    usernameError.text('Error checking username.').show();
                    validationState.username = false;
                    checkFormValidity();
                },
                complete: function() {
                    usernameAjaxRequest = null;
                }
            });
        };

        if (isImmediate) {
            runValidation();
        } else {
            usernameTimer = setTimeout(runValidation, 800);
        }
    }

    /**
     * Reusable function to validate the email. (Unchanged)
     */
    function validateEmail(isImmediate) {
        clearTimeout(emailTimer);
        if (emailAjaxRequest) {
            emailAjaxRequest.abort();
            emailAjaxRequest = null;
        }

        var email = emailInput.val();
        emailIcon.removeClass('qp-valid qp-invalid').addClass('qp-loading');
        emailError.hide();
        validationState.email = false; // Set to invalid during check

        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
             emailIcon.removeClass('qp-loading');
             if (email.length > 0) { // Only show error if user started typing
                emailError.text('Please enter a valid email.').show();
             }
             checkFormValidity();
             return;
        }

        var runValidation = function() {
            emailAjaxRequest = $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: { action: 'qp_check_email', email: email },
                success: function(response) {
                    if (response.success) {
                        emailIcon.removeClass('qp-loading qp-invalid').addClass('qp-valid');
                        emailError.hide();
                        validationState.email = true;
                    } else {
                        emailIcon.removeClass('qp-loading qp-valid').addClass('qp-invalid');
                        emailError.text(response.data.message).show();
                        validationState.email = false;
                    }
                    checkFormValidity();
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus === 'abort') return;
                    emailIcon.removeClass('qp-loading');
                    emailError.text('Error checking email.').show();
                    validationState.email = false;
                    checkFormValidity();
                },
                complete: function() {
                    emailAjaxRequest = null;
                }
            });
        };

        if (isImmediate) {
            runValidation();
        } else {
            emailTimer = setTimeout(runValidation, 800);
        }
    }

    // --- Bind the event listeners ---
    usernameInput.on('keyup', function() { validateUsername(false); });
    usernameInput.on('blur', function() { validateUsername(true); });

    emailInput.on('keyup', function() { validateEmail(false); });
    emailInput.on('blur', function() { validateEmail(true); });

    // --- Add listeners for new fields ---
    passInput.on('keyup', checkFormValidity);
    confirmPassInput.on('keyup', checkFormValidity);
    examSelect.on('change', checkFormValidity);
    subjectSelect.on('change', checkFormValidity); // This is the original <select>

    // Set initial state of the button on page load
    checkFormValidity();
});