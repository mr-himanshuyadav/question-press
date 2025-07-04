// In public/assets/js/practice.js

jQuery(document).ready(function($) {

    var wrapper = $('#qp-practice-app-wrapper');

    // NEW: Logic for the timer checkbox on the settings form
    wrapper.on('change', '#qp_timer_enabled_cb', function() {
        if ($(this).is(':checked')) {
            $('#qp-timer-input-wrapper').slideDown();
        } else {
            $('#qp-timer-input-wrapper').slideUp();
        }
    });

    // Logic for submitting the settings form to start the practice
    wrapper.on('submit', '#qp-start-practice-form', function(e) {
        e.preventDefault(); // Stop the form from submitting the traditional way

        var formData = $(this).serialize();
        
        $.ajax({
            url: qp_ajax_object.ajax_url, // URL provided by WordPress
            type: 'POST',
            data: {
                action: 'start_practice_session', // Our custom action name
                nonce: qp_ajax_object.nonce,
                settings: formData
            },
            beforeSend: function() {
                wrapper.html('<p style="text-align:center; padding: 50px;">Loading your session...</p>');
            },
            success: function(response) {
                if (response.success) {
                    // Replace the wrapper content with the practice UI HTML
                    wrapper.html(response.data.html);
                } else {
                    // Show an error message
                    wrapper.html('<p>Error: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                wrapper.html('<p>An unknown error occurred. Please try again.</p>');
            }
        });
    });

});