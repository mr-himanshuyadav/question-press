jQuery(document).ready(function($) {
    var wrapper = $('#qp-practice-app-wrapper');
    var subjectSelect = $('#qp-progress-subject');
    var sourceSelect = $('#qp-progress-source');
    var resultsContainer = $('#qp-progress-results-container');
    var $body = $('body');
    var $sidebarToggle = $('.qp-sidebar-toggle');
    var $sidebarOverlay = $('.qp-sidebar-overlay');
    var $sidebar = $('.qp-sidebar');
    var $sidebarLinks = $('.qp-sidebar-nav a');

    // --- NEW: Off-Canvas Sidebar Toggle Logic ---
    $sidebarToggle.on('click', function() {
        $body.toggleClass('qp-sidebar-open qp-mobile-dashboard'); // Add mobile class dynamically
        // Update aria-expanded attribute for accessibility
        var isExpanded = $body.hasClass('qp-sidebar-open');
        $(this).attr('aria-expanded', isExpanded);
    });

    // Close sidebar when overlay is clicked
    $sidebarOverlay.on('click', function(e) { // Add the event object 'e'
    // Check if the clicked element is the overlay itself
    if (e.target === this) {
        $body.removeClass('qp-sidebar-open');
        $sidebarToggle.attr('aria-expanded', 'false');
    }
});
// --- NEW: Handler for the dedicated close button ---
    var $sidebarCloseBtn = $('.qp-sidebar-close-btn');

    $sidebarCloseBtn.on('click', function() {
        $body.removeClass('qp-sidebar-open');
        $sidebarToggle.attr('aria-expanded', 'false'); // Sync the toggle button state
    });

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
        checkAttemptsBeforeAction(function() {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'qp_start_review_session', nonce: qp_ajax_object.nonce },
                beforeSend: function() { button.text('Starting...').prop('disabled', true); },
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
        }, button, originalText, 'Starting...');
    });

    // --- NEW: Handler for starting a section practice from the Progress tab ---
    wrapper.on('click', '.qp-progress-start-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var sectionId = button.data('section-id');

        // This AJAX call now sends only the essential data to create a default
        // section-wise practice session, matching your required snapshot.
        checkAttemptsBeforeAction(function() {
            $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'start_practice_session',
                nonce: qp_ajax_object.nonce,
                practice_mode: 'Section Wise Practice',
                qp_section: sectionId
            },
            beforeSend: function() {
                button.text('Starting...').prop('disabled', true);
            },
            success: function(response) {
                if (response.success && response.data.redirect_url) {
                    window.location.href = response.data.redirect_url;
                } else {
                    Swal.fire('Error!', response.data.message || 'Could not start the session.', 'error');
                    button.text('Start').prop('disabled', false);
                }
            },
            error: function() {
                Swal.fire('Error!', 'A server error occurred. Please try again.', 'error');
                button.text('Start').prop('disabled', false);
            }
        });
        }, button, button.text(), 'Starting...');
    });

    // --- Check Attempts Before Resuming ---
wrapper.on('click', 'a.qp-button-primary[href*="session_id="]', function(e) {
     // Check if this is specifically a "Resume" link from the history or active list
     var linkText = $(this).text().trim();
     if (linkText !== 'Resume' && linkText !== 'Continue') {
         return; // Allow other links (like Review) to pass through
     }

     e.preventDefault(); // Stop the link from navigating immediately
     var resumeUrl = $(this).attr('href');
     var button = $(this); // Treat the link like a button for the helper
     var originalText = button.text();

     checkAttemptsBeforeAction(function() {
         // If check passes, navigate to the resume URL
         window.location.href = resumeUrl;
     }, button, originalText, 'Checking...'); // Pass link element
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
        checkAttemptsBeforeAction(function() {
            $.ajax({
                url: qp_ajax_object.ajax_url, type: 'POST',
                data: { action: 'qp_start_incorrect_practice_session', nonce: qp_ajax_object.nonce, include_all_incorrect: includeAllIncorrect },
                beforeSend: function() { button.text('Preparing Session...').prop('disabled', true); },
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
        });}, button, originalText, 'Preparing Session...');
    });

    // --- Progress Tab AJAX ---
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
    const coursesSection = $('#qp-dashboard-courses'); // Still useful for other actions

    // Click handler for "View Course" / "Continue Course" button in the list
    // *** MODIFIED: Delegated from the main wrapper ***
    wrapper.on('click', '.qp-view-course-btn', function(e) {
        e.preventDefault(); // Prevent default button action
        var courseSlug = $(this).data('course-slug');
        var courseId = $(this).data('course-id'); // Keep courseId in case slug is missing
        var baseDashboardUrl = qp_ajax_object.dashboard_page_url || '/'; // Get base URL

        // Ensure base URL ends with a slash
        if (baseDashboardUrl.slice(-1) !== '/') {
            baseDashboardUrl += '/';
        }

        if (courseSlug) {
            // Construct the URL: baseDashboardUrl + 'courses/' + courseSlug + '/'
            var courseUrl = baseDashboardUrl + 'courses/' + courseSlug + '/';
            console.log("Navigating to:", courseUrl); // Add console log for debugging
            window.location.href = courseUrl; // Navigate to the course page
        } else {
            console.error('Course slug missing for course ID:', courseId);
            Swal.fire('Error', 'Could not navigate to the course. Slug is missing.', 'error');
        }
    });

    // Click handler for the "Back to Courses" button (delegated from wrapper)
    wrapper.on('click', '.qp-back-to-courses-btn', function(e) {
        e.preventDefault(); // Prevent default button action
        var baseDashboardUrl = qp_ajax_object.dashboard_page_url || '/'; // Get base URL
         // Ensure base URL ends with a slash
        if (baseDashboardUrl.slice(-1) !== '/') {
            baseDashboardUrl += '/';
        }
        var coursesListUrl = baseDashboardUrl + 'courses/'; // URL for the main courses list
        console.log("Navigating back to:", coursesListUrl); // Add console log
        window.location.href = coursesListUrl; // Navigate back to the courses list
    });

    // Click handler for "Review Results" button (delegated from wrapper)
    wrapper.on('click', '.view-test-results-btn', function(e) {
        e.preventDefault();
        var button = $(this);
        var sessionId = button.data('session-id');
        var reviewPageUrl = qp_ajax_object.review_page_url; // Get review page URL

        if (sessionId && reviewPageUrl) {
            var url = new URL(reviewPageUrl);
            url.searchParams.set('session_id', sessionId);
             console.log("Navigating to review:", url.href); // Add console log
            window.location.href = url.href;
        } else if (!sessionId) {
            Swal.fire('Error', 'Could not find the session data associated with this item.', 'error');
        } else {
            Swal.fire('Error', 'Review page URL is not configured.', 'error');
        }
    });

    // --- Start Test Series from Course Item ---
    coursesSection.on('click', '.start-course-test-btn', function() {
        var button = $(this);
        var itemId = button.data('item-id');
        var originalText = button.text();

        // Use the checkAttemptsBeforeAction helper
        checkAttemptsBeforeAction(function() {
            // This code runs ONLY if the attempt check is successful
            $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'start_course_test_series',
                    nonce: qp_ajax_object.start_course_test_nonce,
                    item_id: itemId
                },
                beforeSend: function() {
                    button.text('Starting Test...');
                },
                success: function(response) {
                    if (response.success && response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        Swal.fire('Error!', response.data.message || 'Could not start the test session.', 'error');
                        button.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'A server error occurred while starting the test.', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            });
        }, button, originalText, 'Checking Access...');
    });

    // --- Enroll Button Handler ---
    coursesSection.on('click', '.qp-enroll-course-btn', function() {
        var button = $(this);
        var courseId = button.data('course-id');
        var originalText = button.text();

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'enroll_in_course',
                nonce: qp_ajax_object.enroll_nonce,
                course_id: courseId
            },
            beforeSend: function() {
                button.text('Enrolling...').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Enrolled!',
                        text: response.data.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                         // Simple page reload to refresh the course list
                         window.location.reload();
                    });
                } else {
                    Swal.fire('Error', response.data.message || 'Could not enroll.', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                Swal.fire('Error', 'A server error occurred during enrollment.', 'error');
                button.text(originalText).prop('disabled', false);
            }
        });
    });
    // --- End Course Navigation & Actions ---

    // --- MODIFIED: Store initial HTML ---
    var initialCourseListHtml = coursesSection.html(); // Store the initial content

    // Click handler for the "Back to Courses" button
    coursesSection.on('click', '.qp-back-to-courses-btn', function() {
        renderInitialCourseList(coursesSection);
    });

    // Placeholder for handling clicks on non-test items
    coursesSection.on('click', '.view-course-content-btn', function() {
        var itemId = $(this).data('item-id');
        // Implement logic for PDF/Video/Lesson display later
        Swal.fire('Coming Soon!', 'Support for this content type will be added later.', 'info');
    });

    // --- Review Completed Test Button Handler ---
    coursesSection.on('click', '.view-test-results-btn', function() {
        var button = $(this);
        var sessionId = button.data('session-id');
        var reviewPageUrl = qp_ajax_object.review_page_url; // Get review page URL from localized data

        if (sessionId && reviewPageUrl) {
            // Construct the URL and redirect
            var url = new URL(reviewPageUrl);
            url.searchParams.set('session_id', sessionId);
            window.location.href = url.href;
        } else if (!sessionId) {
            Swal.fire('Error', 'Could not find the session data associated with this item.', 'error');
        } else {
            Swal.fire('Error', 'Review page URL is not configured.', 'error');
        }
    });

    // --- Start Test Series from Course Item ---
    coursesSection.on('click', '.start-course-test-btn', function() {
        var button = $(this);
        var itemId = button.data('item-id');
        var originalText = button.text();

        // Use the checkAttemptsBeforeAction helper
        checkAttemptsBeforeAction(function() {
            // This code runs ONLY if the attempt check is successful
            $.ajax({
                url: qp_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'start_course_test_series',
                    nonce: qp_ajax_object.start_course_test_nonce,
                    item_id: itemId
                },
                beforeSend: function() {
                    // Button state is already 'Checking...' or similar from checkAttemptsBeforeAction
                    button.text('Starting Test...'); // Update text
                },
                success: function(response) {
                    if (response.success && response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                        // Don't reset the button text here as we are redirecting
                    } else {
                        // Handle errors specifically from start_course_test_series
                        Swal.fire('Error!', response.data.message || 'Could not start the test session.', 'error');
                        button.text(originalText).prop('disabled', false); // Reset button on failure
                    }
                },
                error: function() {
                    // Handle general AJAX errors
                    Swal.fire('Error!', 'A server error occurred while starting the test.', 'error');
                    button.text(originalText).prop('disabled', false); // Reset button on failure
                }
                // No 'complete' needed here as success handles redirect or error handles reset
            });
        }, button, originalText, 'Checking Access...'); // Pass button details to the helper
    });

    // --- Enroll Button Handler ---
    coursesSection.on('click', '.qp-enroll-course-btn', function() {
        var button = $(this);
        var courseId = button.data('course-id');
        var originalText = button.text();

        $.ajax({
            url: qp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'enroll_in_course',
                nonce: qp_ajax_object.enroll_nonce,
                course_id: courseId
            },
            beforeSend: function() {
                button.text('Enrolling...').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Enrolled!',
                        text: response.data.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        // Refresh the courses tab content to show updated lists
                        // A simple way is to re-render, though more complex updates are possible
                        renderInitialCourseList(coursesSection); // Re-render the list
                    });
                } else {
                    Swal.fire('Error', response.data.message || 'Could not enroll.', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                Swal.fire('Error', 'A server error occurred during enrollment.', 'error');
                button.text(originalText).prop('disabled', false);
            }
        });
    });

}); // End jQuery ready

/**
 * Checks if the user has attempts before executing a callback.
 * Shows an error alert if access is denied.
 * @param {function} callback - The function to execute if access is granted.
 * @param {jQuery} [button] - Optional: The button triggering the action.
 * @param {string} [originalText] - Optional: The button's original text.
 * @param {string} [loadingText] - Optional: Text to show while checking.
 */
function checkAttemptsBeforeAction(callback, button, originalText, loadingText = 'Checking access...') {
    // Use global qp_ajax_object defined elsewhere
    if (typeof qp_ajax_object === 'undefined') {
         console.error('qp_ajax_object is not defined.');
         Swal.fire('Error!', 'Configuration missing. Cannot check access.', 'error');
         return;
    }

    if (button) {
        // Determine if it's an input button or a regular button
        if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
            button.prop('disabled', true).val(loadingText);
        } else {
            button.prop('disabled', true).text(loadingText);
        }
    }

    jQuery.ajax({ // Use jQuery.ajax since this is outside ready block
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: {
            action: 'qp_check_remaining_attempts',
            // No nonce needed here as per PHP function
        },
        success: function(response) {
            // --- NEW SIMPLIFIED LOGIC ---
            if (response.success && response.data.has_access) {
                // Access granted by PHP, execute the original action
                if (typeof callback === 'function') {
                    callback();
                }
                // Note: Don't re-enable the button here if the callback redirects or leads to another action
            } else {
                 // Access denied by PHP (or AJAX failed partially), show alert
                 var practiceInProgress = false; // Allow redirect (safe to declare here)
                 var reasonCode = (response.data && response.data.reason_code) ? response.data.reason_code : 'unknown';
                 var alertTitle = 'Access Denied!';
                 var alertMessage = '';

                 // Customize message based on reason code
                 switch (reasonCode) {
                    case 'expired_or_inactive':
                        alertTitle = 'Plan Expired or Inactive';
                        alertMessage = 'Your access plan has expired or is currently inactive.';
                        break;
                    case 'out_of_attempts':
                        alertTitle = 'Out of Attempts!';
                        alertMessage = 'You have run out of attempts for your current plan(s).';
                        break;
                    case 'no_entitlements':
                         alertTitle = 'No Active Plan';
                         alertMessage = 'You do not have an active plan granting access.';
                         break;
                    case 'not_logged_in':
                        alertTitle = 'Not Logged In';
                        alertMessage = 'You must be logged in to access this feature.';
                        // Hide purchase link if not logged in
                        purchaseUrl = ''; // Clear purchase URL
                        break;
                    default: // Includes 'unknown' or unexpected codes
                        alertMessage = 'Access denied. Please check your plan details or contact support.';
                 }

                 // Construct purchase link URL using localized object
                 var purchaseUrl = (typeof qp_ajax_object !== 'undefined' && qp_ajax_object.shop_page_url) ? qp_ajax_object.shop_page_url : '';
                 var purchaseLinkHtml = purchaseUrl ? ' <a href="' + purchaseUrl + '">Purchase Access</a>' : '';

                 Swal.fire({
                    title: alertTitle,
                    html: alertMessage + purchaseLinkHtml, // Append purchase link if available
                    icon: 'error',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                });

                // Reset button if provided (remains the same)
                if (button && originalText) {
                   if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                       button.prop('disabled', false).val(originalText);
                   } else {
                       button.prop('disabled', false).text(originalText);
                   }
                }
            }
            // --- END NEW LOGIC ---
        },
        error: function() {
            // Handle AJAX error during check
            Swal.fire('Error!', 'Could not verify your access. Please try again.', 'error');
             if (button && originalText) {
                 if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                     button.prop('disabled', false).val(originalText);
                 } else {
                     button.prop('disabled', false).text(originalText);
                 }
             }
        }
    });
}