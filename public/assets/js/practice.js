// In public/assets/js/practice.js

jQuery(document).ready(function($) {

    $('#qp-practice-app-wrapper').on('submit', '#qp-start-practice-form', function(e) {
        e.preventDefault(); // Stop the form from submitting the traditional way

        var formData = $(this).serialize();
        var wrapper = $('#qp-practice-app-wrapper');

        $.ajax({
            url: qp_ajax_object.ajax_url, // URL provided by WordPress
            type: 'POST',
            data: {
                action: 'start_practice_session', // Our custom action name
                nonce: qp_ajax_object.nonce,
                settings: formData
            },
            beforeSend: function() {
                // Show a loading message
                wrapper.html('<p>Loading your session...</p>');
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