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
             // --- ADDED: Trigger rendering of courses when navigating to the tab ---
             if (sectionId === 'qp-dashboard-courses') {
                 // If the section is empty or just has a loading message, render the initial list
                 if (targetSection.children().length === 0 || targetSection.find('.qp-loader-spinner').length) {
                     renderInitialCourseList(targetSection);
                 }
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

    // <<< PASTE THE NEW COURSE STRUCTURE LOGIC HERE >>>
    // --- Course Structure Loading Logic ---
    const coursesSection = $('#qp-dashboard-courses'); // Cache the section

    // Click handler for "View Course" button in the list
    coursesSection.on('click', '.qp-view-course-btn', function() {
        var courseId = $(this).data('course-id');
        var button = $(this);
        var originalText = button.text();

        $.ajax({
            url: qp_ajax_object.ajax_url, // Use localized ajaxurl
            type: 'POST',
            data: {
                action: 'get_course_structure',
                nonce: qp_ajax_object.nonce, // Use localized nonce
                course_id: courseId
            },
            beforeSend: function() {
                button.text('Loading...').prop('disabled', true);
                // Optionally show a loading spinner over the whole section
                coursesSection.html('<div class="qp-loader-spinner" style="top: 20px;"></div>');
            },
            success: function(response) {
                if (response.success) {
                    renderCourseStructure(response.data);
                } else {
                    coursesSection.html('<p>Error loading course structure: ' + (response.data.message || 'Unknown error') + '</p><button class="qp-button qp-button-secondary qp-back-to-courses-btn">Back to Courses</button>');
                }
            },
            error: function() {
                coursesSection.html('<p>Could not load course structure due to a server error.</p><button class="qp-button qp-button-secondary qp-back-to-courses-btn">Back to Courses</button>');
            },
            complete: function() {
                // No need to reset the button text here as the button itself is replaced
            }
        });
    });

    // Function to render the fetched course structure
    function renderCourseStructure(data) {
        let html = `
            <div class="qp-course-structure-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2>${data.course_title || 'Course Details'}</h2>
                <button class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Courses</button>
            </div>
            <div class="qp-course-structure-content">`;

        if (data.sections && data.sections.length > 0) {
            data.sections.forEach(section => {
                html += `
                    <div class="qp-course-section-card qp-card">
                        <div class="qp-card-header">
                            <h3>${section.title || 'Untitled Section'}</h3>
                            ${section.description ? `<p style="font-size: 0.9em; color: var(--qp-dashboard-text-light); margin-top: 5px;">${section.description}</p>` : ''}
                        </div>
                        <div class="qp-card-content qp-course-items-list">`;

                if (section.items && section.items.length > 0) {
                    section.items.forEach(item => {
                        let statusIcon = '';
                        let itemClass = 'qp-course-item-link';
                        let buttonText = 'Start';
                        let buttonClass = 'qp-button-primary start-course-test-btn'; // Specific class for test

                        switch(item.status) {
                        case 'completed':
                            statusIcon = '<span class="dashicons dashicons-yes-alt" style="color: var(--qp-dashboard-success);"></span>';
                            buttonText = 'Review'; // Or 'Start Again' if re-attempts are desired
                            buttonClass = 'qp-button-secondary view-test-results-btn'; // Use specific class for review
                            break;
                        case 'in_progress': // Note: 'in_progress' isn't set yet, but plan for it
                            statusIcon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-warning-dark);"></span>';
                            buttonText = 'Continue';
                            buttonClass = 'qp-button-primary start-course-test-btn'; // Or a different 'continue' class if needed
                            break;
                        default: // not_started
                            statusIcon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-border);"></span>';
                            buttonText = 'Start';
                            buttonClass = 'qp-button-primary start-course-test-btn'; // Class to trigger start
                            break;
                    }

                    // Add different classes/buttons based on content_type later
                    if (item.content_type !== 'test_series') {
                       buttonClass = 'qp-button-secondary view-course-content-btn'; // Example for other types
                       buttonText = 'View';
                    }


                        html += `
                            <div class="qp-course-item-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--qp-dashboard-border-light);">
                                <span class="${itemClass}" style="display: flex; align-items: center; gap: 8px;">
                                    ${statusIcon}
                                    <span style="font-weight: 500;">${item.title || 'Untitled Item'}</span>
                                </span>
                                <button class="qp-button ${buttonClass}" data-item-id="${item.item_id}" style="padding: 4px 10px; font-size: 12px;">${buttonText}</button>
                            </div>`;
                    });
                     // Remove last border
                     html = html.replace(/border-bottom: 1px solid var\(--qp-dashboard-border-light\);(?=<\/div>\s*<\/div>\s*<\/div>$)/, '');

                } else {
                    html += '<p style="text-align: center; color: var(--qp-dashboard-text-light); font-style: italic;">No items in this section.</p>';
                }
                html += `</div></div>`; // Close card-content and card
            });
        } else {
            html += '<div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">This course has no content yet.</p></div></div>';
        }

        html += '</div>'; // Close qp-course-structure-content
        coursesSection.html(html);
    }

    // --- NEW: Function to render the initial course list (used by back button and tab switch) ---
    function renderInitialCourseList(targetSection) {
        // We need the original PHP output to re-render.
        // It's best to store this in a variable or fetch it again if needed.
        // For simplicity now, let's assume it's stored globally (though not ideal)
        // A better approach would be another AJAX call or storing in a data attribute.
        if (typeof initialCourseListHtml !== 'undefined') {
             targetSection.html(initialCourseListHtml);
        } else {
            // Fallback: If original HTML isn't stored, just show a basic message
             targetSection.html('<h2>Available Courses</h2><p>Loading course list...</p>');
             // Ideally, trigger an AJAX call here to fetch the list content again
             // $.ajax({... action: 'get_course_list_html' ...});
        }
    }

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

    // Placeholder for handling clicks on completed test review buttons
    coursesSection.on('click', '.view-test-results-btn', function() {
        var itemId = $(this).data('item-id');
        // Need to figure out how to link this item ID back to the specific session ID
        // Maybe store session_id in wp_qp_user_items_progress->result_data?
        Swal.fire('Info', 'Linking to session review will be implemented later.', 'info');
        // Example redirect (needs session ID):
        // window.location.href = qp_ajax_object.review_page_url + '?session_id=' + relevantSessionId;
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
                    nonce: qp_ajax_object.nonce,
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
                nonce: qp_ajax_object.nonce,
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