jQuery(document).ready(function($) {
    var wrapper = $('.qp-dashboard-wrapper');
    var subjectSelect = $('#qp-progress-subject');
    var sourceSelect = $('#qp-progress-source');
    var resultsContainer = $('#qp-progress-results-container');

    // --- NEW: Handler for removing an item from the review list ---
    wrapper.on('click', '.qp-review-list-remove-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var listItem = button.closest('li');
        var questionID = listItem.data('question-id');

        Swal.fire({
            title: 'Remove from Review?',
            text: "This question will be removed from your review list.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, remove it!'
        }).then((result) => {
            if (result.isConfirmed) {
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
                                var reviewTab = $('.qp-tab-link[data-tab="review"]');
                                var currentCountText = reviewTab.text();
                                var currentCountMatch = currentCountText.match(/\d+/);
                                if (currentCountMatch) {
                                    var currentCount = parseInt(currentCountMatch[0], 10);
                                    reviewTab.text('Review List (' + (currentCount - 1) + ')');
                                }
                            });
                        } else {
                            Swal.fire('Error!', 'Could not remove the item. Please try again.', 'error');
                            button.text('Remove').prop('disabled', false);
                        }
                    },
                    error: function() {
                         Swal.fire('Error!', 'A network error occurred. Please try again.', 'error');
                         button.text('Remove').prop('disabled', false);
                    }
                });
            }
        });
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
                        Swal.fire('Error!', response.data.message || 'Could not start review session.', 'error');
                        button.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'A server error occurred.', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            });
    });


    // --- NEW: Tab Switching Logic with URL Hash ---
    function switchTab(tab_id) {
        $('.qp-tab-link').removeClass('active');
        $('.qp-tab-link[data-tab="' + tab_id + '"]').addClass('active');

        $('.qp-tab-content').removeClass('active');
        $("#" + tab_id).addClass('active');
    }

    // Handle tab clicks
    wrapper.on('click', '.qp-tab-link', function(e) {
        e.preventDefault();
        var tab_id = $(this).data('tab');
        switchTab(tab_id);
        // Update the URL hash without reloading the page
        window.location.hash = tab_id;
    });

    // On page load, check for a hash and switch to that tab
    if (window.location.hash) {
        // Remove the '#' from the hash to get the tab ID
        var hash = window.location.hash.substring(1);
        var targetTab = $('.qp-tab-link[data-tab="' + hash + '"]');
        
        // If a tab with that ID exists, switch to it
        if (targetTab.length) {
            switchTab(hash);
        }
    }

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
                    html += '<h4>' + data.subject_name + ' (ID: ' + data.question_id + ')</h4>';
                    if (data.direction_text || data.direction_image_url) {
                        html += '<div class="qp-direction">';
                        if(data.direction_text) {
                            html += '<div class="qp-direction-text-wrapper">' + data.direction_text + '</div>';
                        }
                        if(data.direction_image_url) {
                            html += '<div class="qp-direction-image-wrapper"><img src="' + data.direction_image_url + '" style="max-width: 50%; max-height: 150px; display: block; margin: 10px auto 0 auto;" /></div>';
                        }
                        html += '</div>';
                    }


                    html += '<div class="question-text">' + data.question_text + '</div>';
                    
                    html += '<div class="qp-options-area qp-modal-options" style="margin-top: 1.5rem;">';
                    
                    data.options.forEach(function(opt) {
                        // Add data-option-id to each option div
                        html += '<div class="option" data-option-id="' + opt.option_id + '">' + opt.option_text + '</div>';
                        if (opt.is_correct == 1) {
                            correctOptionId = opt.option_id;
                        }
                    });
                    html += '</div>';

                    html += '<div class="qp-modal-footer">';
                    html += '<label><input type="checkbox" id="qp-modal-show-answer-cb"> Show Answer</label>';
                    html += '</div>';

                    modalContent.html(html);
                    modalContent.find('.qp-modal-options').data('correct-option-id', correctOptionId);

                    // Manually trigger KaTeX rendering on the new modal content.
                    if (typeof renderMathInElement === 'function') {
                        renderMathInElement(modalContent[0], {
                            delimiters: [
                                {left: '$$', right: '$$', display: true},
                                {left: '$', right: '$', display: false},
                                {left: '\\\\[', right: '\\\\]', display: true},
                                {left: '\\\\(', right: '\\\\)', display: false}
                            ],
                            throwOnError: false
                        });
                    }

                } else {
                    modalContent.html('<p>Could not load question.</p><button class="qp-modal-close-btn">&times;</button>');
                }
            }
        });
    });


    // --- Handler for the "Terminate" button ---
    wrapper.on('click', '.qp-terminate-session-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var sessionID = button.data('session-id');

        Swal.fire({
            title: 'Terminate Session?',
            text: "This active session will be removed permanently. This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, terminate it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: qp_ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'qp_terminate_session',
                        nonce: qp_ajax_object.nonce,
                        session_id: sessionID
                    },
                    beforeSend: function() {
                        button.text('...').prop('disabled', true);
                        button.closest('.qp-card-actions').find('.qp-button-secondary').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload the page to see the session removed
                            location.reload();
                        } else {
                            Swal.fire('Error!', response.data.message || 'Could not terminate the session.', 'error');
                            button.text('Terminate').prop('disabled', false);
                            button.closest('.qp-card-actions').find('.qp-button-secondary').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'A network error occurred. Please try again.', 'error');
                        button.text('Terminate').prop('disabled', false);
                        button.closest('.qp-card-actions').find('.qp-button-secondary').prop('disabled', false);
                    }
                });
            }
        });
    });

    // --- UPDATED: Handler for the "Show Answer" checkbox in the modal ---
    wrapper.on('change', '#qp-modal-show-answer-cb', function(e) {
        e.stopPropagation(); // Prevent the modal from closing
        var isChecked = $(this).is(':checked');
        var optionsArea = $('#qp-review-modal-content .qp-modal-options');
        var correctOptionId = optionsArea.data('correct-option-id');

        if (isChecked) {
            optionsArea.find('.option[data-option-id="' + correctOptionId + '"]').addClass('correct');
        } else {
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

        Swal.fire({
            title: 'Delete Session?',
            text: "This session history will be permanently deleted. This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: qp_ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'delete_user_session',
                        nonce: qp_ajax_object.nonce,
                        session_id: sessionID
                    },
                    beforeSend: function() {
                        button.text('Deleting...').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            button.closest('tr').fadeOut(500, function() {
                                $(this).remove();
                            });
                        } else {
                            Swal.fire('Error!', response.data.message || 'Could not delete the session.', 'error');
                            button.text('Delete').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'An unknown server error occurred.', 'error');
                        button.text('Delete').prop('disabled', false);
                    }
                });
            }
        });
    });

    // Handler for deleting all revision history
    wrapper.on('click', '#qp-delete-history-btn', function(e) {
        e.preventDefault();
        var button = $(this);

        Swal.fire({
            title: 'Delete All History?',
            text: "This will permanently delete all of your practice sessions. This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete everything!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: qp_ajax_object.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'delete_revision_history',
                        nonce: qp_ajax_object.nonce
                    },
                    beforeSend: function() {
                        button.text('Deleting...').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Deleted!', 'Your history has been cleared.', 'success');
                            var tableBody = wrapper.find('.qp-dashboard-table tbody');
                            tableBody.fadeOut(400, function() {
                                $(this).empty().html('<tr><td colspan="5" style="text-align: center;">Your history has been cleared.</td></tr>').fadeIn(400);
                            });
                            button.text('History Deleted').css('opacity', 0.7);
                        } else {
                            Swal.fire('Error!', response.data.message || 'An unknown error occurred.', 'error');
                            button.text('Clear History').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'An unknown server error occurred.', 'error');
                        button.text('Clear History').prop('disabled', false);
                    }
                });
            }
        });
    });

    // --- NEW: Dynamically update the "Practice Your Mistakes" counter ---
    wrapper.on('change', '#qp-include-all-incorrect-cb', function() {
        var isChecked = $(this).is(':checked');
        var heading = $('#qp-incorrect-practice-heading');
        var counterSpan = heading.find('span');

        if (isChecked) {
            // If checked, show the total count of all past mistakes
            var totalCount = heading.data('total-incorrect-count');
            counterSpan.text(totalCount);
        } else {
            // If unchecked, show the count of questions never answered correctly
            var neverCorrectCount = heading.data('never-correct-count');
            counterSpan.text(neverCorrectCount);
        }
    });

    // --- NEW: Handler for starting an incorrect questions practice session ---
    wrapper.on('click', '#qp-start-incorrect-practice-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var originalText = button.text();
        var includeAllIncorrect = $('#qp-include-all-incorrect-cb').is(':checked');

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'qp_start_incorrect_practice_session',
                nonce: qp_ajax_object.nonce,
                include_all_incorrect: includeAllIncorrect
            },
            beforeSend: function() {
                button.text('Preparing Session...').prop('disabled', true);
            },
            success: function(response) {
                if (response.success && response.data.redirect_url) {
                    window.location.href = response.data.redirect_url;
                } else {
                    Swal.fire('Error!', response.data.message || 'Could not start practice session. You may not have any incorrect questions to practice.', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                Swal.fire('Error!', 'A server error occurred.', 'error');
                button.text(originalText).prop('disabled', false);
            }
        });
    });

    subjectSelect.on('change', function() {
        var subjectId = $(this).val();
        sourceSelect.val(''); // Reset source selection
        resultsContainer.html(''); // Clear previous results

        if (!subjectId) {
            sourceSelect.html('<option value="">— Select a Subject First —</option>').prop('disabled', true);
            return;
        }

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_sources_for_subject_progress',
                nonce: qp_ajax_object.nonce,
                subject_id: subjectId // The PHP handler now expects 'subject_id' which is the term_id
            },
            beforeSend: function() {
                sourceSelect.html('<option value="">Loading Sources...</option>').prop('disabled', true);
            },
            success: function(response) {
                if (response.success && response.data.sources && response.data.sources.length > 0) {
                    var optionsHtml = '<option value="">— Select a Source —</option>';
                    // The new AJAX handler returns an array of objects with source_id and source_name
                    $.each(response.data.sources, function(index, source) {
                        optionsHtml += `<option value="${source.source_id}">${source.source_name}</option>`;
                    });
                    sourceSelect.html(optionsHtml).prop('disabled', false);
                } else {
                    sourceSelect.html('<option value="">— No Sources Found —</option>').prop('disabled', true);
                }
            },
            error: function() {
                sourceSelect.html('<option value="">— Error Loading —</option>').prop('disabled', true);
            }
        });
    });

    sourceSelect.on('change', function() {
        var sourceId = $(this).val();
        var subjectId = subjectSelect.val(); // Get the selected subject ID
        if (!sourceId) {
            resultsContainer.html('');
            return;
        }

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_progress_data',
                nonce: qp_ajax_object.nonce,
                subject_id: subjectId, // Add this line
                source_id: sourceId,
                exclude_incorrect: $('#qp-exclude-incorrect-cb').is(':checked')
            },
            beforeSend: function() {
                resultsContainer.html('<div class="qp-loader-spinner"></div>');
            },
            success: function(response) {
                if (response.success) {
                    resultsContainer.html(response.data.html);
                } else {
                    resultsContainer.html('<p>Could not load progress data.</p>');
                }
            }
        });
    });

    // --- NEW: Handler for the "Exclude Incorrect" checkbox ---
    wrapper.on('change', '#qp-exclude-incorrect-cb', function() {
        // When the checkbox changes, just re-trigger the source dropdown's change event.
        // This will automatically re-run the progress calculation with the new checkbox state.
        sourceSelect.trigger('change');
    });

    // --- Collapsible Progress Sections Logic ---
    wrapper.on('click', '.qp-topic-toggle', function() {
        var $clickedItem = $(this);
        var topicId = $clickedItem.data('topic-id');
        var $sectionsContainer = wrapper.find('.qp-topic-sections-container[data-parent-topic="' + topicId + '"]');

        // Toggle the arrow icon and the active group highlight
        $clickedItem.toggleClass('is-open is-active-group');

        // Toggle the visibility of the direct children container
        $sectionsContainer.slideToggle(200);

        // Apply active group class to children when opening
        if ($clickedItem.hasClass('is-open')) {
            $sectionsContainer.find('.qp-progress-item').addClass('is-active-group');
        } else {
            // When closing, also close any nested children and remove their active states
            $sectionsContainer.find('.qp-topic-sections-container').slideUp(200);
            $sectionsContainer.find('.qp-topic-toggle').removeClass('is-open is-active-group');
            $sectionsContainer.find('.qp-progress-item').removeClass('is-active-group');
        }
    });
});