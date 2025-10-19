jQuery(document).ready(function($) {
    var wrapper = $('.qp-dashboard-wrapper');
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
    $sidebarOverlay.on('click', function() {
        $body.removeClass('qp-sidebar-open');
        $sidebarToggle.attr('aria-expanded', 'false');
    });

    // Close sidebar when a navigation link is clicked (especially on mobile)
    $sidebarLinks.on('click', function() {
        // Check if the sidebar is currently open (primarily for mobile)
        if ($body.hasClass('qp-sidebar-open')) {
             $body.removeClass('qp-sidebar-open');
             $sidebarToggle.attr('aria-expanded', 'false');
        }
        // Navigation/section switching logic will be added later
    });

    // --- NEW: Sidebar Navigation & Section Switching Logic ---
    var $dashboardSections = $('.qp-dashboard-section'); // Cache the section elements
    var $sidebarNavLinks = $('.qp-sidebar-nav a'); // Cache the nav links

    // Function to switch sections
    function showSection(sectionId) {
        var targetSection = $('#' + sectionId); // Find the target section by ID

        if (targetSection.length) {
            // Update sidebar link active state
            $sidebarNavLinks.removeClass('active');
            $sidebarNavLinks.filter('[href="#' + sectionId.replace('qp-dashboard-', '') + '"]').addClass('active');

            // Hide all sections, then show the target one
            $dashboardSections.removeClass('active').hide(); // Hide ensures display:none is applied
            targetSection.addClass('active').show(); // Show ensures display:block/flex etc. is applied before animation

            // Update URL hash without jumping
            history.pushState(null, null, '#' + sectionId.replace('qp-dashboard-', ''));

            // If switching to the 'History' tab, maybe trigger data loading if needed
            // if (sectionId === 'qp-dashboard-history') {
            //     loadHistoryData(); // Example: Call a function to load history via AJAX if not already loaded
            // }
            // Add similar checks for 'Review' and 'Progress' if using AJAX loading
             if (sectionId === 'qp-dashboard-progress') {
                 // Trigger change on subject dropdown to potentially load sources if a subject is pre-selected (or just enable it)
                 $('#qp-progress-subject').trigger('change');
             }

        } else {
            // Fallback to overview if the target doesn't exist
            showSection('qp-dashboard-overview');
        }
    }

    // Handle clicks on sidebar navigation links
    $sidebarNavLinks.on('click', function(e) {
        e.preventDefault(); // Prevent default anchor jump
        var targetHash = $(this).attr('href'); // Get the href (e.g., "#history")
        var sectionId = 'qp-dashboard-' + targetHash.substring(1); // Construct the section ID (e.g., "qp-dashboard-history")

        // Close sidebar if open (mobile view) - Moved logic here
         if ($body.hasClass('qp-sidebar-open')) {
             $body.removeClass('qp-sidebar-open');
             $sidebarToggle.attr('aria-expanded', 'false');
             // Add a small delay for the sidebar to close before switching content
             setTimeout(function() {
                 showSection(sectionId);
             }, 300); // Adjust delay to match CSS transition
         } else {
             showSection(sectionId); // Switch immediately on desktop
         }
    });

    // Initial load: Check URL hash and show the corresponding section
    var initialHash = window.location.hash;
    if (initialHash) {
        var initialSectionId = 'qp-dashboard-' + initialHash.substring(1);
        // Small delay to ensure CSS transitions don't clash on initial load
        setTimeout(function() {
            showSection(initialSectionId);
             // Special check for mobile: If navigating directly via hash, ensure sidebar is closed
             if ($body.hasClass('qp-mobile-dashboard')) {
                 $body.removeClass('qp-sidebar-open');
                 $sidebarToggle.attr('aria-expanded', 'false');
             }
        }, 10);
    } else {
         // Default to overview if no hash is present
         showSection('qp-dashboard-overview');
    }

     // Handle 'View Full History' link click
     wrapper.on('click', '.qp-view-full-history-link', function(e) {
         e.preventDefault();
         showSection('qp-dashboard-history');
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
        });}, button, originalText, 'Preparing Session...');
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
            if (response.success && response.data.has_access) {
                // Access granted, execute the original action
                if (typeof callback === 'function') {
                    callback();
                }
                // Note: Don't re-enable the button here if the callback redirects
            } else {
                // Access denied, show alert
                var practiceInProgress = false; // Allow redirect (safe to declare here)
                 Swal.fire({
                    title: 'Out of Attempts!',
                    html: 'You do not have enough attempts remaining to start or resume this session. <a href="' + qp_ajax_object.shop_page_url + '">Purchase More</a>',
                    icon: 'error',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                });
                // Reset button if provided
                if (button && originalText) {
                   if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                       button.prop('disabled', false).val(originalText);
                   } else {
                       button.prop('disabled', false).text(originalText);
                   }
                }
            }
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