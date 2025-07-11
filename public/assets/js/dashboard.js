jQuery(document).ready(function($) {
    var wrapper = $('.qp-dashboard-wrapper');

    // --- NEW: Handler for removing an item from the review list ---
    wrapper.on('click', '.qp-review-list-remove-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var listItem = button.closest('li');
        var questionID = listItem.data('question-id');

        if (confirm('Are you sure you want to remove this question from your review list?')) {
            $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'qp_toggle_review_later',
                    nonce: qp_ajax_object.nonce,
                    question_id: questionID,
                    is_marked: 'false' // Send 'false' to remove it
                },
                beforeSend: function() {
                    button.text('Removing...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        listItem.fadeOut(400, function() {
                            $(this).remove();
                            // Update the count in the tab
                            var reviewTab = $('.qp-tab-link[data-tab="review"]');
                            var currentCount = parseInt(reviewTab.text().match(/\d+/)[0], 10);
                            reviewTab.text('Review List (' + (currentCount - 1) + ')');
                        });
                    } else {
                        alert('Could not remove the item. Please try again.');
                        button.text('Remove').prop('disabled', false);
                    }
                }
            });
        }
    });

    // --- NEW: Handler for starting a review session ---
    wrapper.on('click', '#qp-start-reviewing-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var originalText = button.text();

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'qp_start_review_session',
                nonce: qp_ajax_object.nonce,
            },
            beforeSend: function() {
                button.text('Starting...').prop('disabled', true);
            },
            success: function(response) {
                if (response.success && response.data.redirect_url) {
                    window.location.href = response.data.redirect_url;
                } else {
                    alert('Error: ' + (response.data.message || 'Could not start review session.'));
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                alert('A server error occurred.');
                button.text(originalText).prop('disabled', false);
            }
        });
    });


    // --- NEW: Tab Switching Logic ---
    wrapper.on('click', '.qp-tab-link', function(e) {
        e.preventDefault();
        var tab_id = $(this).data('tab');
        $('.qp-tab-link').removeClass('active');
        $(this).addClass('active');
        $('.qp-tab-content').removeClass('active');
        $("#" + tab_id).addClass('active');
    });

    // --- UPDATED: Handler for the "View" button to open the modal ---
    wrapper.on('click', '.qp-review-list-view-btn', function() {
        var button = $(this);
        var questionID = button.closest('li').data('question-id');

        var modalBackdrop = $('#qp-review-modal-backdrop');
        var modalContent = $('#qp-review-modal-content');

        modalContent.html('<p>Loading question...</p>');
        modalBackdrop.show();

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_single_question_for_review',
                nonce: qp_ajax_object.nonce,
                question_id: questionID
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var correctOptionId = null;

                    var html = '<button class="qp-modal-close-btn">&times;</button>';
                    html += '<h4>' + data.subject_name + ' (ID: ' + data.custom_question_id + ')</h4>';
                    if (data.direction_text) {
                        html += '<div class="qp-direction">' + data.direction_text + '</div>';
                    }
                    html += '<div class="question-text">' + data.question_text + '</div>';
                    
                    // Add a data attribute to the options container to hold the correct answer's ID
                    html += '<div class="qp-options-area qp-modal-options" style="margin-top: 1.5rem;" data-correct-option-id="">';
                    
                    data.options.forEach(function(opt) {
                        // Don't add the 'correct' class here anymore
                        html += '<div class="option" data-option-id="' + opt.option_id + '">' + opt.option_text + '</div>';
                        if (opt.is_correct == 1) {
                            correctOptionId = opt.option_id;
                        }
                    });
                    html += '</div>';

                    // NEW: Add the "Show Answer" checkbox at the bottom
                    html += '<div class="qp-modal-footer">';
                    html += '<label><input type="checkbox" id="qp-modal-show-answer-cb"> Show Answer</label>';
                    html += '</div>';

                    modalContent.html(html);

                    // Now that the HTML is in the DOM, set the data attribute
                    modalContent.find('.qp-modal-options').data('correct-option-id', correctOptionId);

                    // Manually trigger KaTeX rendering if it's loaded
                    if (typeof renderMathInElement === 'function') {
                        renderMathInElement(modalContent[0]);
                    }

                } else {
                    modalContent.html('<p>Could not load question.</p><button class="qp-modal-close-btn">&times;</button>');
                }
            }
        });
    });

    // ... inside document.ready, after the other handlers ...

    // --- NEW: Handler for the "Show Answer" checkbox in the modal ---
    wrapper.on('change', '#qp-modal-show-answer-cb', function() {
        var isChecked = $(this).is(':checked');
        var optionsArea = $('.qp-modal-options');
        var correctOptionId = optionsArea.data('correct-option-id');

        if (isChecked) {
            // Find the correct option using the data-attribute and highlight it
            optionsArea.find('.option[data-option-id="' + correctOptionId + '"]').addClass('correct');
        } else {
            // Remove the highlight
            optionsArea.find('.option').removeClass('correct');
        }
    });

    // This handler stops clicks inside the white modal content from closing it.
wrapper.on('click', '#qp-review-modal-content', function(e) {
    e.stopPropagation();
});

// This handler now ONLY closes the modal if the dark backdrop or the close button is clicked.
wrapper.on('click', '#qp-review-modal-backdrop, .qp-modal-close-btn', function(e) {
    $('#qp-review-modal-backdrop').hide();
});

    // Handler for deleting a single session row
    wrapper.on('click', '.qp-delete-session-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var sessionID = button.data('session-id');
        if (confirm('Are you sure you want to permanently delete this session history? This cannot be undone.')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'delete_user_session', nonce: qp_ajax_object.nonce, session_id: sessionID },
                beforeSend: function() { button.text('Deleting...').prop('disabled', true); },
                success: function(response) {
                    if (response.success) {
                        button.closest('tr').fadeOut(500, function() { $(this).remove(); });
                    } else {
                        alert('Error: ' + response.data.message);
                        button.text('Delete').prop('disabled', false);
                    }
                },
                error: function() { alert('An unknown error occurred.'); button.text('Delete').prop('disabled', false); }
            });
        }
    });

    // Handler for deleting all revision history
    wrapper.on('click', '#qp-delete-history-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        if (confirm('Are you sure you want to delete ALL of your practice session and revision history? This action cannot be undone.')) {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'delete_revision_history', nonce: qp_ajax_object.nonce },
                beforeSend: function() {
                    button.text('Deleting History...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        var tableBody = wrapper.find('.qp-dashboard-table tbody');
                        // Fade out the table body, empty it, add the success message, and fade it back in.
                        tableBody.fadeOut(400, function() {
                            $(this).empty().html('<tr><td colspan="8" style="text-align: center;">Your history has been cleared.</td></tr>').fadeIn(400);
                        });
                        // Update the button state to reflect the action.
                        button.text('History Deleted').css('opacity', 0.7);
                    } else {
                        alert('Error: ' + (response.data.message || 'An unknown error occurred.'));
                        button.text('Delete All Revision History').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('An unknown server error occurred.');
                    button.text('Delete All Revision History').prop('disabled', false);
                }
            });
        }
    });
});
