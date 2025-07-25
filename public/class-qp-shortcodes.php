<?php
if (!defined('ABSPATH')) exit;

class QP_Shortcodes
{

    // A static property to temporarily hold session data for the script.
    private static $session_data_for_script = null;

    public static function render_practice_form()
    {
        if (!is_user_logged_in()) {
            return '<div style="text-align:center; padding: 40px 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin-top:0; font-size: 22px;">Please Log In to Begin</h3>
                        <p style="font-size: 16px; color: #555; margin-bottom: 25px;">You need to be logged in to start a new practice session and track your progress.</p>
                        <a href="' . wp_login_url(get_permalink()) . '" class="qp-button qp-button-primary" style="text-decoration: none;">Click Here to Log In</a>
                    </div>';
        }

        // --- NEW: Handle pre-filled section practice ---
        if (isset($_GET['start_section_practice']) && $_GET['start_section_practice'] === 'true') {
            $subject_id = isset($_GET['subject']) ? absint($_GET['subject']) : 0;
            $topic_id = isset($_GET['topic']) ? absint($_GET['topic']) : 0;
            $section_id = isset($_GET['section']) ? absint($_GET['section']) : 0;

            // Pass these IDs to a script that will select them after the form loads
            wp_register_script('qp-prefill-script', '', [], '', true);
            wp_enqueue_script('qp-prefill-script');
            $prefill_script = sprintf(
                "
                document.addEventListener('DOMContentLoaded', function() {
                    const subjectDropdown = document.querySelector('#qp_subject_dropdown .qp-multi-select-list');
                    if (subjectDropdown) {
                        const subjectCheckbox = subjectDropdown.querySelector('input[value=\"%d\"]');
                        if (subjectCheckbox) {
                            subjectCheckbox.click(); // Use click to trigger the AJAX for topics
                        }
                    }
                    // We need to wait for the topic/section AJAX calls to complete
                    const observer = new MutationObserver(function(mutations, me) {
                        const topicDropdown = document.querySelector('#qp_topic_list_container');
                        const sectionDropdown = document.querySelector('#qp_section');
                        
                        if (topicDropdown && topicDropdown.children.length > 1) {
                            const topicCheckbox = topicDropdown.querySelector('input[value=\"%d\"]');
                            if (topicCheckbox) {
                                topicCheckbox.click();
                            }
                        }
                        if (sectionDropdown && sectionDropdown.options.length > 1) {
                            sectionDropdown.value = '%d';
                            me.disconnect(); // Stop observing once we've set the section
                        }
                    });
                    observer.observe(document.getElementById('qp-practice-app-wrapper'), { childList: true, subtree: true });
                });
                ",
                $subject_id,
                $topic_id,
                $section_id
            );
            wp_add_inline_script('qp-prefill-script', $prefill_script);

            // Directly render the settings form, bypassing the mode selection
            return '<div id="qp-practice-app-wrapper">' . self::render_settings_form() . '</div>';
        }

        // Get the question order setting
        $options = get_option('qp_settings');
        $question_order_setting = isset($options['question_order']) ? $options['question_order'] : 'random';
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
        $dashboard_page_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : '';

        ob_start();
?>
        <div id="qp-practice-app-wrapper">
            <div class="qp-multi-step-container">
                <div id="qp-step-1" class="qp-form-step active">
                    <div class="qp-step-content">
                        <h2>Select Practice Mode</h2>
                        <div class="qp-mode-selection-group">
                            <label class="qp-mode-radio-label">
                                <input type="radio" name="practice_mode_selection" value="2">
                                <span class="qp-mode-radio-button">Normal Practice</span>
                            </label>
                            <label class="qp-mode-radio-label">
                                <input type="radio" name="practice_mode_selection" value="3">
                                <span class="qp-mode-radio-button">Revision Mode</span>
                            </label>
                            <label class="qp-mode-radio-label">
                                <input type="radio" name="practice_mode_selection" value="4">
                                <span class="qp-mode-radio-button">Mock Test</span>
                            </label>
                        </div>

                        <div class="qp-step-1-footer">
                            <button id="qp-step1-next-btn" class="qp-button qp-button-primary" disabled>Next</button>
                            <?php if ($dashboard_page_url) : ?>
                                <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary">Go to Dashboard</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="qp-step-2" class="qp-form-step">
                    <div class="qp-step-content">
                        <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                        <?php echo self::render_settings_form(); // Re-use the existing form function 
                        ?>
                    </div>
                </div>

                <div id="qp-step-3" class="qp-form-step">
                    <div class="qp-step-content">
                        <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                        <?php echo self::render_revision_mode_form(); // New function for the revision form 
                        ?>
                    </div>
                </div>

                <div id="qp-step-4" class="qp-form-step">
                    <div class="qp-step-content">
                        <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                        <?php echo self::render_mock_test_form(); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    public static function render_revision_mode_form()
    {
        global $wpdb;

        // Fetch all subjects that have at least one question associated with them
        $subjects = $wpdb->get_results(
            "SELECT DISTINCT s.subject_id, s.subject_name
         FROM {$wpdb->prefix}qp_subjects s
         JOIN {$wpdb->prefix}qp_question_groups g ON s.subject_id = g.subject_id
         JOIN {$wpdb->prefix}qp_questions q ON g.group_id = q.group_id
         WHERE s.subject_name != 'Uncategorized'
         ORDER BY s.subject_name ASC"
        );

        ob_start();
    ?>
        <form id="qp-start-revision-form" method="post" action="">
            <input type="hidden" name="practice_mode" value="revision">
            <h2>Revision Mode</h2>

            <div class="qp-form-group">
                <label for="qp_subject_dropdown_revision">Select Subject(s):</label>
                <div class="qp-multi-select-dropdown" id="qp_subject_dropdown_revision">
                    <button type="button" class="qp-multi-select-button">-- Please select --</button>
                    <div class="qp-multi-select-list">
                        <label><input type="checkbox" name="revision_subjects[]" value="all"> All Subjects</label>
                        <?php foreach ($subjects as $subject) : ?>
                            <label><input type="checkbox" name="revision_subjects[]" value="<?php echo esc_attr($subject->subject_id); ?>"> <?php echo esc_html($subject->subject_name); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="qp-form-group" id="qp-topic-group-revision" style="display: none;">
                <label for="qp_topic_dropdown_revision">Select Topic(s):</label>
                <div class="qp-multi-select-dropdown" id="qp_topic_dropdown_revision">
                    <button type="button" class="qp-multi-select-button">-- Select subject(s) first --</button>
                    <div class="qp-multi-select-list" id="qp_topic_list_container_revision">
                    </div>
                </div>
            </div>
            <div class="qp-form-group">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" name="exclude_pyq" value="1" checked>
                    <span></span>
                    Exclude PYQs
                </label>
            </div>
            <div class="qp-form-group">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" name="choose_random" value="0">
                    <span></span>
                    Choose Random Questions
                </label>
                <p class="description">Check this to get random questions from your selected topics, ignoring the default source order.</p>
            </div>

            <div class="qp-form-group">
                <label for="qp_revision_questions_per_topic">Number of Questions from each Topic</label>
                <input type="number" name="qp_revision_questions_per_topic" id="qp_revision_questions_per_topic" value="2" min="1">
            </div>

            <div class="qp-form-group">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" name="scoring_enabled" id="qp_revision_scoring_enabled_cb">
                    <span></span>
                    Enable Scoring
                </label>
            </div>

            <div class="qp-form-group qp-marks-group" id="qp-revision-marks-group-wrapper" style="display: none;">
                <div>
                    <label for="qp_revision_marks_correct">Marks for Correct Answer:</label>
                    <input type="number" name="qp_marks_correct" id="qp_revision_marks_correct" value="4" step="0.01">
                </div>
                <div>
                    <label for="qp_revision_marks_incorrect">Penalty for Incorrect Answer:</label>
                    <input type="number" name="qp_marks_incorrect" id="qp_revision_marks_incorrect" value="1" step="0.01" min="0">
                </div>
            </div>

            <div class="qp-form-group">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" name="qp_timer_enabled">
                    <span></span>
                    Enable Timer per Question
                </label>
                <div id="qp-revision-timer-input-wrapper" style="display: none; margin-top: 15px;">
                    <label for="qp_revision_timer_seconds">Time in Seconds:</label>
                    <input type="number" name="qp_timer_seconds" id="qp_revision_timer_seconds" value="60" min="10">
                </div>
            </div>

            <div class="qp-form-group qp-action-buttons">
                <input type="submit" name="qp_start_revision" value="Start Revision" class="qp-button qp-button-primary">
            </div>
        </form>
    <?php
        return ob_get_clean();
    }

    public static function render_mock_test_form()
    {
        global $wpdb;

        // Fetch all subjects that have at least one question associated with them
        $subjects = $wpdb->get_results(
            "SELECT DISTINCT s.subject_id, s.subject_name
     FROM {$wpdb->prefix}qp_subjects s
     JOIN {$wpdb->prefix}qp_question_groups g ON s.subject_id = g.subject_id
     JOIN {$wpdb->prefix}qp_questions q ON g.group_id = q.group_id
     WHERE s.subject_name != 'Uncategorized'
     ORDER BY s.subject_name ASC"
        );

        ob_start();
    ?>
        <form id="qp-start-mock-test-form" method="post" action="">
            <input type="hidden" name="practice_mode" value="mock_test">
            <h2>Mock Test</h2>

            <div class="qp-form-group">
                <label for="qp_subject_dropdown_mock">Select Subject(s):</label>
                <div class="qp-multi-select-dropdown" id="qp_subject_dropdown_mock">
                    <button type="button" class="qp-multi-select-button">-- Please select --</button>
                    <div class="qp-multi-select-list">
                        <label><input type="checkbox" name="mock_subjects[]" value="all"> All Subjects</label>
                        <?php foreach ($subjects as $subject) : ?>
                            <label><input type="checkbox" name="mock_subjects[]" value="<?php echo esc_attr($subject->subject_id); ?>"> <?php echo esc_html($subject->subject_name); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="qp-form-group" id="qp-topic-group-mock" style="display: none;">
                <label for="qp_topic_dropdown_mock">Select Topic(s):</label>
                <div class="qp-multi-select-dropdown" id="qp_topic_dropdown_mock">
                    <button type="button" class="qp-multi-select-button">-- Select subject(s) first --</button>
                    <div class="qp-multi-select-list" id="qp_topic_list_container_mock">
                    </div>
                </div>
            </div>

            <div class="qp-form-group">
                <label for="qp_mock_num_questions">Number of Questions</label>
                <input type="number" name="qp_mock_num_questions" id="qp_mock_num_questions" value="20" min="5">
            </div>

            <div class="qp-form-group">
                <label>Question Distribution</label>
                <div class="qp-mode-selection-group" style="flex-direction: row; gap: 1rem;">
                    <label class="qp-mode-radio-label" style="flex: 1;">
                        <input type="radio" name="question_distribution" value="random" checked>
                        <span class="qp-mode-radio-button" style="font-size: 14px; padding: 10px;">Random</span>
                    </label>
                    <label class="qp-mode-radio-label" style="flex: 1;">
                        <input type="radio" name="question_distribution" value="equal">
                        <span class="qp-mode-radio-button" style="font-size: 14px; padding: 10px;">Equal per Topic</span>
                    </label>
                </div>
            </div>

            <div class="qp-form-group">
                <label for="qp_mock_timer_minutes">Total Time (in minutes)</label>
                <input type="number" name="qp_mock_timer_minutes" id="qp_mock_timer_minutes" value="30" min="1">
            </div>

            <div class="qp-form-group">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" name="scoring_enabled" id="qp_mock_scoring_enabled_cb">
                    <span></span>
                    Enable Scoring
                </label>
            </div>

            <div class="qp-form-group qp-marks-group" id="qp-mock-marks-group-wrapper" style="display: none;">
                <div>
                    <label for="qp_mock_marks_correct">Marks for Correct Answer:</label>
                    <input type="number" name="qp_marks_correct" id="qp_mock_marks_correct" value="4" step="0.01" disabled>
                </div>
                <div>
                    <label for="qp_mock_marks_incorrect">Penalty for Incorrect Answer:</label>
                    <input type="number" name="qp_marks_incorrect" id="qp_mock_marks_incorrect" value="1" step="0.01" min="0" disabled>
                </div>
            </div>

            <div class="qp-form-group qp-action-buttons">
                <input type="submit" name="qp_start_mock_test" value="Start Mock Test" class="qp-button qp-button-primary">
            </div>
        </form>
    <?php
        return ob_get_clean();
    }


    public static function render_session_page()
    {
        if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
            return '<div class="qp-container"><p>Error: No valid practice session was found. Please start a new session.</p></div>';
        }

        $session_id = absint($_GET['session_id']);
        $user_id = get_current_user_id();

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $pauses_table = $wpdb->prefix . 'qp_session_pauses';
        $session_data_from_db = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE session_id = %d", $session_id));

        // **THE FIX**: This is the new, simplified error handling logic.
        // **THE FIX**: This is the new, simplified error handling logic.
        if (!$session_data_from_db) {
            // Session does not exist at all.
            $options = get_option('qp_settings');
            $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');
            return '<div class="qp-container" style="text-align: center; padding: 40px 20px;">
            <h3 style="margin-top:0; font-size: 22px;">Session Not Found</h3>
            <p style="font-size: 16px; color: #555; margin-bottom: 25px;">This session is either invalid, has been completed, or was abandoned and has been removed.</p>
            <a href="' . esc_url($dashboard_page_url) . '" class="qp-button qp-button-primary" style="text-decoration: none;">View Dashboard</a>
        </div>';
        }

        if ((int)$session_data_from_db->user_id !== $user_id) {
            // --- NEW: Handle sessions that are paused after the last question is answered ---
            $question_ids = json_decode($session_data_from_db->question_ids_snapshot, true);
            $attempts_table = $wpdb->prefix . 'qp_user_attempts';
            $attempt_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT question_id) FROM {$attempts_table} WHERE session_id = %d",
                $session_id
            ));



            if ($session_data_from_db->status !== 'completed' && count($question_ids) > 0 && $attempt_count >= count($question_ids)) {
                // If all questions have been attempted but the session isn't marked as 'completed',
                // it means the user paused on the very last question. Treat it as completed.
                $summary_data = [
                    'final_score' => $session_data_from_db->marks_obtained,
                    'total_attempted' => $session_data_from_db->total_attempted,
                    'correct_count' => $session_data_from_db->correct_count,
                    'incorrect_count' => $session_data_from_db->incorrect_count,
                    'skipped_count' => $session_data_from_db->skipped_count,
                ];
                $session_settings = json_decode($session_data_from_db->settings_snapshot, true);
                // Force the summary UI to render, preventing the user from getting stuck.
                return '<div id="qp-practice-app-wrapper">' . self::render_summary_ui($summary_data, $session_id, $session_settings) . '</div>';
            }
            $options = get_option('qp_settings');
            $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');
            $accuracy = 0;
            if ($session_data_from_db && $session_data_from_db->total_attempted > 0) {
                $accuracy = ($session_data_from_db->correct_count / $session_data_from_db->total_attempted) * 100;
            }

            return '<div class="qp-container" style="text-align: center; padding: 40px 20px;">
                    <h3 style="margin-top:0; font-size: 22px;">Session Not Found</h3>
                    <p style="font-size: 16px; color: #555; margin-bottom: 25px;">This session is either invalid or was abandoned and has been removed.</p>
                    <a href="' . esc_url($dashboard_page_url) . '" class="qp-button qp-button-primary" style="text-decoration: none;">View Dashboard</a>
                </div>';
        }

        // --- Handle Resuming a Paused Session ---
        if ($session_data_from_db->status === 'paused') {
            // Find the last open pause record for this session
            $last_pause_id = $wpdb->get_var($wpdb->prepare(
                "SELECT pause_id FROM {$pauses_table} WHERE session_id = %d AND resume_time IS NULL ORDER BY pause_time DESC LIMIT 1",
                $session_id
            ));

            // If an open pause record is found, update it with the current time
            if ($last_pause_id) {
                $wpdb->update(
                    $pauses_table,
                    ['resume_time' => current_time('mysql')],
                    ['pause_id' => $last_pause_id]
                );
            }

            // Set the main session status back to 'active'
            $wpdb->update(
                $sessions_table,
                [
                    'status' => 'active',
                    'last_activity' => current_time('mysql')
                ],
                ['session_id' => $session_id]
            );

            // Re-fetch the session data to reflect the 'active' status
            $session_data_from_db->status = 'active';
        }

        // --- Check if the session is already completed ---
        if ($session_data_from_db->status === 'completed') {
            $summary_data = [
                'final_score' => $session_data_from_db->marks_obtained,
                'total_attempted' => $session_data_from_db->total_attempted,
                'correct_count' => $session_data_from_db->correct_count,
                'incorrect_count' => $session_data_from_db->incorrect_count,
                'skipped_count' => $session_data_from_db->skipped_count,
            ];
            $session_settings = json_decode($session_data_from_db->settings_snapshot, true);
            return '<div id="qp-practice-app-wrapper">' . self::render_summary_ui($summary_data, $session_id, $session_settings) . '</div>';
        }

        // --- Calculate Initial Elapsed Active Time for the Stopwatch ---
        $pauses = $wpdb->get_results($wpdb->prepare(
            "SELECT pause_time, resume_time FROM {$pauses_table} WHERE session_id = %d",
            $session_id
        ));

        $total_paused_duration = 0;
        foreach ($pauses as $pause) {
            // Only count completed pause intervals
            if ($pause->resume_time) {
                $total_paused_duration += strtotime($pause->resume_time) - strtotime($pause->pause_time);
            }
        }

        $initial_elapsed_time = (strtotime(current_time('mysql')) - strtotime($session_data_from_db->start_time)) - $total_paused_duration;
        $initial_elapsed_time = max(0, $initial_elapsed_time);

        // --- If the session is active, proceed as normal ---
        $session_settings = json_decode($session_data_from_db->settings_snapshot, true);
        $session_data = [
            'session_id'    => $session_id,
            'question_ids'  => json_decode($session_data_from_db->question_ids_snapshot, true),
            'settings'      => $session_settings,
            'initial_elapsed_seconds' => $initial_elapsed_time,
        ];

        // If it's a mock test, calculate the absolute end time based on start time and duration
        if (isset($session_settings['practice_mode']) && $session_settings['practice_mode'] === 'mock_test') {
            // Get the start time (which was saved in WP's timezone) and convert it to a proper UTC timestamp.
            // This is the correct way to handle timezones in WordPress.
            $start_time_gmt_string = get_gmt_from_date($session_data_from_db->start_time);
            $start_time_timestamp = strtotime($start_time_gmt_string);

            $duration_seconds = $session_settings['timer_seconds'];

            // The end time is passed as a UTC timestamp (seconds since epoch) for JavaScript
            $session_data['test_end_timestamp'] = $start_time_timestamp + $duration_seconds;
        }

        $attempt_history = $wpdb->get_results($wpdb->prepare(
            "SELECT a.question_id, a.selected_option_id, a.is_correct, a.status, a.mock_status, a.remaining_time, o.option_id as correct_option_id
         FROM {$wpdb->prefix}qp_user_attempts a
         LEFT JOIN {$wpdb->prefix}qp_options o ON a.question_id = o.question_id AND o.is_correct = 1
         WHERE a.session_id = %d",
            $session_id
        ), OBJECT_K);

        $session_data['attempt_history'] = $attempt_history;

        $reports_table = $wpdb->prefix . 'qp_question_reports';
        $reported_qids_for_user = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$reports_table} WHERE user_id = %d AND status = 'open'",
            $user_id
        ));

        $session_data['reported_ids'] = $reported_qids_for_user;

        self::$session_data_for_script = $session_data;

        return '<div id="qp-practice-app-wrapper">' . self::render_practice_ui() . '</div>';
    }

    // In public/class-qp-shortcodes.php

    public static function render_summary_ui($summaryData, $session_id = 0, $settings = [])
    {
        $options = get_option('qp_settings');
        $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');
        $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/');
        $session_review_url = $review_page_url ? add_query_arg('session_id', $session_id, $review_page_url) : '#';

        $accuracy = 0;
        if (isset($summaryData['total_attempted']) && $summaryData['total_attempted'] > 0) {
            $accuracy = ($summaryData['correct_count'] / $summaryData['total_attempted']) * 100;
        }

        // Determine if the session was scored
        $is_scored_session = isset($settings['marks_correct']);

        ob_start();
    ?>
        <div class="qp-summary-wrapper">
            <h2>Session Summary</h2>

            <?php if ($is_scored_session) : ?>
                <div class="qp-summary-score">
                    <div class="label">Final Score</div><?php echo number_format($summaryData['final_score'], 2); ?>
                </div>
            <?php else : ?>
                <div class="qp-summary-score">
                    <div class="label">Accuracy</div><?php echo round($accuracy, 2); ?>%
                </div>
            <?php endif; ?>

            <div class="qp-summary-stats">
                <div class="stat">
                    <div class="value"><?php echo (int)$summaryData['correct_count']; ?></div>
                    <div class="label">Correct</div>
                </div>
                <div class="stat">
                    <div class="value"><?php echo (int)$summaryData['incorrect_count']; ?></div>
                    <div class="label">Incorrect</div>
                </div>
                <div class="stat">
                    <div class="value"><?php echo (int)$summaryData['skipped_count']; ?></div>
                    <div class="label">Skipped</div>
                </div>
                <div class="stat accuracy">
                    <div class="value"><?php echo round($accuracy, 2); ?>%</div>
                    <div class="label">Accuracy</div>
                </div>
            </div>
            <div class="qp-summary-actions">
                <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary">View Dashboard</a>
                <?php if ($session_id && $review_page_url !== '#'): ?>
                    <a href="<?php echo esc_url($session_review_url); ?>" class="qp-button qp-button-primary">Review Session</a>
                <?php endif; ?>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    // Helper function to get the session data
    public static function get_session_data_for_script()
    {
        return self::$session_data_for_script;
    }

    public static function render_settings_form()
    {
        global $wpdb;
        $subjects = $wpdb->get_results(
            "SELECT DISTINCT s.subject_id, s.subject_name
     FROM {$wpdb->prefix}qp_subjects s
     JOIN {$wpdb->prefix}qp_question_groups g ON s.subject_id = g.subject_id
     WHERE s.subject_name != 'Uncategorized'
     ORDER BY s.subject_name ASC"
        );

        // Get the dynamic URL for the dashboard page
        $options = get_option('qp_settings');
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
        $dashboard_page_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : '';

        ob_start();
    ?>
        <div class="qp-container qp-practice-form-wrapper">
            <h2>Start a New Practice Session</h2>
            <form id="qp-start-practice-form" method="post" action="">
                <input type="hidden" name="practice_mode" value="normal">
                <input type="hidden" name="question_order" value="incrementing">

                <div class="qp-form-group">
                    <label for="qp_subject_dropdown">Select Subject(s):</label>
                    <div class="qp-multi-select-dropdown" id="qp_subject_dropdown">
                        <button type="button" class="qp-multi-select-button">-- Please select --</button>
                        <div class="qp-multi-select-list">
                            <label><input type="checkbox" name="qp_subject[]" value="all"> All Subjects</label>
                            <?php foreach ($subjects as $subject) : ?>
                                <label><input type="checkbox" name="qp_subject[]" value="<?php echo esc_attr($subject->subject_id); ?>"> <?php echo esc_html($subject->subject_name); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="qp-form-group" id="qp-topic-group" style="display: none;">
                    <label for="qp_topic_dropdown">Select Topic(s):</label>
                    <div class="qp-multi-select-dropdown" id="qp_topic_dropdown">
                        <button type="button" class="qp-multi-select-button">-- Select subject(s) first --</button>
                        <div class="qp-multi-select-list" id="qp_topic_list_container">
                        </div>
                    </div>
                </div>

                <div class="qp-form-group" id="qp-section-group" style="display: none;">
                    <label for="qp_section">Select Section (Optional):</label>
                    <select name="qp_section" id="qp_section" disabled>
                        <option value="">-- Select a subject first --</option>
                    </select>
                </div>

                <div class="qp-form-group qp-checkbox-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_pyq_only" value="1">
                        <span></span>
                        PYQ Only
                    </label>
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_include_attempted" value="1">
                        <span></span>
                        Include previously attempted questions
                    </label>
                </div>
                <div class="qp-form-group-description">
                    <p>Subject<strong>(number)</strong>: The number in front of subject, topic, section shows unattempted questions.</p>
                </div>

                <div class="qp-form-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="scoring_enabled" id="qp_scoring_enabled_cb">
                        <span></span>
                        Enable Scoring
                    </label>
                </div>

                <div class="qp-form-group qp-marks-group" id="qp-marks-group-wrapper" style="display: none;">
                    <div style="width: 48%">
                        <label for="qp_marks_correct">Correct Marks:</label>
                        <input type="number" name="qp_marks_correct" id="qp_marks_correct" value="4" step="0.01" required>
                    </div>
                    <div style="width: 48%">
                        <label for="qp_marks_incorrect">Negative Marks:</label>
                        <input type="number" name="qp_marks_incorrect" id="qp_marks_incorrect" value="1" step="0.01" min="0" required>
                    </div>
                </div>

                <div class="qp-form-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_timer_enabled" id="qp_timer_enabled_cb">
                        <span></span>
                        Question Timer
                    </label>
                    <div id="qp-timer-input-wrapper" style="display: none; margin-top: 15px;">
                        <label for="qp_timer_seconds">Time in Seconds:</label>
                        <input type="number" name="qp_timer_seconds" id="qp_timer_seconds" value="60" min="10">
                    </div>
                </div>

                <div class="qp-form-group qp-action-buttons">
                    <input type="submit" name="qp_start_practice" value="Start Practice" class="qp-button qp-button-primary">
                </div>
            </form>
        </div>
    <?php
        return ob_get_clean();
    }

    public static function render_practice_ui()
    {
        // Get the settings for the current session to determine the mode
        $session_settings = self::$session_data_for_script['settings'] ?? [];
        $is_mock_test = isset($session_settings['practice_mode']) && $session_settings['practice_mode'] === 'mock_test';
        $is_section_wise = isset($session_settings['practice_mode']) && $session_settings['practice_mode'] === 'Section Wise Practice';
        $is_palette_mandatory = $is_mock_test || $is_section_wise;

        // --- THIS IS THE CORRECTED/RESTORED LOGIC ---
        $mode_class = 'mode-normal';
        $mode_name = 'Practice Session'; // A generic default
        $options = get_option('qp_settings');
        $user = wp_get_current_user();
        $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
        $user_can_view_source = !empty(array_intersect((array)$user->roles, (array)$allowed_roles));

        if ($is_mock_test) {
            $mode_class = 'mode-mock-test';
            $mode_name = 'Mock Test';
        } elseif (isset($session_settings['practice_mode'])) {
            switch ($session_settings['practice_mode']) {
                case 'revision':
                    $mode_class = 'mode-revision';
                    $mode_name = 'Revision Mode';
                    break;
                case 'Incorrect Que. Practice':
                    $mode_class = 'mode-incorrect';
                    $mode_name = 'Incorrect Practice';
                    break;
                case 'Section Wise Practice':
                    $mode_class = 'mode-section-wise';
                    $mode_name = 'Section Practice';
                    break;
            }
        } elseif (isset($session_settings['subject_id']) && $session_settings['subject_id'] === 'review') {
            $mode_class = 'mode-review';
            $mode_name = 'Review Mode';
        }
        // --- END OF CORRECTION ----

        ob_start();
    ?>
        <div id="qp-palette-overlay">
            <div id="qp-palette-sliding">
                <div class="qp-palette-header">
                    <h4>Question Palette</h4>
                    <button id="qp-palette-close-btn">&times;</button>
                </div>
                <?php if (!$is_mock_test) : ?>
                    <div class="qp-header-bottom-row qp-palette-stats">
                        <div class="qp-header-stat score"><span class="value" id="qp-score">0.00</span><span class="label">Score</span></div>
                        <div class="qp-header-stat correct"><span class="value" id="qp-correct-count">0</span><span class="label">Correct</span></div>
                        <div class="qp-header-stat incorrect"><span class="value" id="qp-incorrect-count">0</span><span class="label">Incorrect</span></div>
                        <div class="qp-header-stat skipped"><span class="value" id="qp-skipped-count">0</span><span class="label">Skipped</span></div>
                    </div>
                <?php endif; ?>
                <div class="qp-palette-grid"></div>
                <div class="qp-palette-legend">
                    <?php if ($is_mock_test) : ?>
                        <div class="legend-item" data-status="answered"><span class="swatch status-answered"></span><span class="legend-text">Answered</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="viewed"><span class="swatch status-viewed"></span><span class="legend-text">Not Answered</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="not_viewed"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Visited</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="marked_for_review"><span class="swatch status-marked_for_review"></span><span class="legend-text">Marked for Review</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="answered_and_marked_for_review"><span class="swatch status-answered_and_marked_for_review"></span><span class="legend-text">Answered & Marked</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
                    <?php else : ?>
                        <div class="legend-item" data-status="correct"><span class="swatch status-correct"></span><span class="legend-text">Correct</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="incorrect"><span class="swatch status-incorrect"></span><span class="legend-text">Incorrect</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="skipped"><span class="swatch status-skipped"></span><span class="legend-text">Skipped</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
                        <?php if ($is_section_wise) : ?>
                            <div class="legend-item" data-status="not_attempted"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Attempted</span><span class="legend-count">(0)</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <div class="qp-container qp-practice-wrapper <?php echo $mode_class; ?>">
            <div id="qp-palette-docked">
                <div class="qp-palette-header">
                    <h4>Question Palette</h4>
                </div>
                <?php if (!$is_mock_test) : ?>
                    <div class="qp-header-bottom-row qp-palette-stats">
                        <div class="qp-header-stat score"><span class="value" id="qp-score">0.00</span><span class="label">Score</span></div>
                        <div class="qp-header-stat correct"><span class="value" id="qp-correct-count">0</span><span class="label">Correct</span></div>
                        <div class="qp-header-stat incorrect"><span class="value" id="qp-incorrect-count">0</span><span class="label">Incorrect</span></div>
                        <div class="qp-header-stat skipped"><span class="value" id="qp-skipped-count">0</span><span class="label">Skipped</span></div>
                    </div>
                <?php endif; ?>
                <div class="qp-palette-grid"></div>
                <div class="qp-palette-legend">
                    <?php if ($is_mock_test) : ?>
                        <div class="legend-item" data-status="answered"><span class="swatch status-answered"></span><span class="legend-text">Answered</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="viewed"><span class="swatch status-viewed"></span><span class="legend-text">Not Answered</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="not_viewed"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Visited</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="marked_for_review"><span class="swatch status-marked_for_review"></span><span class="legend-text">Marked for Review</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="answered_and_marked_for_review"><span class="swatch status-answered_and_marked_for_review"></span><span class="legend-text">Answered & Marked</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
                    <?php else : ?>
                        <div class="legend-item" data-status="correct"><span class="swatch status-correct"></span><span class="legend-text">Correct</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="incorrect"><span class="swatch status-incorrect"></span><span class="legend-text">Incorrect</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="skipped"><span class="swatch status-skipped"></span><span class="legend-text">Skipped</span><span class="legend-count">(0)</span></div>
                        <div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
                        <?php if ($is_section_wise) : ?>
                            <div class="legend-item" data-status="not_attempted"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Attempted</span><span class="legend-count">(0)</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>

            <div id="qp-main-content">
                <div class="qp-header">
                    <div class="qp-header-top-row">
                        <div class="qp-session-mode-indicator"><?php echo esc_html($mode_name); ?></div>
                        <button id="qp-palette-toggle-btn" title="Toggle Question Palette">
                            <span class="dashicons dashicons-layout"></span>
                        </button>
                    </div>

                    <?php if ($is_mock_test) : ?>
                        <div class="qp-header-bottom-row">
                            <div class="qp-header-stat">
                                <span class="value" id="qp-mock-test-timer">--:--</span>
                                <span class="label">Time Remaining</span>
                            </div>
                            <div class="qp-header-stat">
                                <span class="value" id="qp-question-counter">--/--</span>
                                <span class="label">Questions</span>
                            </div>
                        </div>
                        <p id="qp-timer-warning-message" style="color: #c62828; font-size: 0.8em;text-align: center; font-weight: 500; margin: 0; display: none;">
                            The test will be submitted automatically when the time expires.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="qp-animatable-area-container">
                    <div class="qp-animatable-area">
                        <div class="question-meta">
                            <div class="qp-question-meta-left">
                                <div id="qp-question-subject-line"><span id="qp-question-subject"></span> | <span id="qp-question-id"></span></div>
                                <?php if ($user_can_view_source): ?>
                                    <div id="qp-question-source"></div>
                                <?php endif; ?>
                            </div>
                            <div class="qp-question-meta-right">
                                <div class="qp-question-counter-box" style="display: none;">
                                    <span class="qp-counter-label">Question</span>
                                    <span class="qp-counter-value" id="qp-question-counter">1/1</span>
                                </div>
                                <button id="qp-report-btn" class="qp-report-button qp-button-secondary"><span>&#9888;</span> Report</button>
                            </div>
                        </div>


                        <div class="qp-indicator-bar" style="display: none;">
                            <?php if (!$is_mock_test) : ?><div id="qp-timer-indicator" class="timer-stat" style="display: none;">--:--</div><?php endif; ?>
                            <div id="qp-revision-indicator" style="display: none;">&#9851; Revision</div>
                            <div id="qp-reported-indicator" style="display: none;">&#9888; Reported</div>
                        </div>

                        <div class="qp-question-area">
                            <div class="qp-direction" style="display: none;"></div>
                            <div class="question-text" id="qp-question-text-area">
                                <p>Loading question...</p>
                            </div>
                        </div>

                        <div class="qp-options-area"></div>

                        <?php if ($is_mock_test) : ?>
                            <div class="qp-mock-test-actions">
                                <button type="button" id="qp-clear-response-btn" class="qp-button qp-button-secondary">Clear Response</button>
                                <label class="qp-button qp-button-secondary qp-review-later-checkbox"><input type="checkbox" id="qp-mock-mark-review-cb"><span>Mark for Review</span></label>
                            </div>
                        <?php else : ?>
                            <div class="qp-review-later" style="text-align:center;margin-bottom: 5px;"><label class="qp-review-later-checkbox qp-button qp-button-secondary"><input type="checkbox" id="qp-mark-for-review-cb"><span>Add to Review List</span></label><button id="qp-check-answer-btn" class="qp-button qp-button-primary" disabled>Check Answer</button><label class="qp-custom-checkbox" style="margin-left: 15px;"><input type="checkbox" id="qp-auto-check-cb"><span></span>Auto Check</label></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="qp-footer-nav">
                    <button id="qp-prev-btn" class="qp-button qp-button-primary" disabled><span>&#9664;</span></button>
                    <?php if (!$is_mock_test) : ?>
                        <button id="qp-skip-btn" class="qp-button qp-button-secondary">Skip</button>
                    <?php endif; ?>
                    <button id="qp-next-btn" class="qp-button qp-button-primary"><span>&#9654;</span></button>
                </div>

                <hr class="qp-footer-divider">

                <div class="qp-footer-controls">
                    <?php if ($is_mock_test) : ?>
                        <button id="qp-submit-test-btn" class="qp-button qp-button-danger">Submit Test</button>
                    <?php else : ?>
                        <button id="qp-pause-btn" class="qp-button qp-button-secondary">Pause & Save</button>
                        <?php if ($session_settings['practice_mode'] !== 'Section Wise Practice') : ?>
                            <button id="qp-end-practice-btn" class="qp-button qp-button-danger">End Session</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div id="qp-report-modal-backdrop" style="display: none;">
            <div id="qp-report-modal-content"><button class="qp-modal-close-btn">&times;</button>
                <h3>Report an Issue</h3>
                <p>Please select all issues that apply to the current question.</p>
                <form id="qp-report-form">
                    <div id="qp-report-options-container"></div>
                    <div class="qp-modal-footer"><button type="submit" class="qp-button qp-button-primary">Submit Report</button></div>
                </form>
            </div>
        </div>
        <div id="qp-start-session-overlay">
            <div class="qp-start-session-content">
                <h3>Your session is ready.</h3>
                <p>Click the button below to begin in an immersive, fullscreen environment.</p><button id="qp-fullscreen-start-btn" class="qp-button qp-button-primary">Start & Enter Fullscreen</button>
            </div>
        </div>
    <?php
        return ob_get_clean();
    }

    public static function render_review_page()
    {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to review a session. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
            return '<div class="qp-container"><p>Error: No valid session ID was provided.</p></div>';
        }

        $session_id = absint($_GET['session_id']);
        $user_id = get_current_user_id();

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE session_id = %d AND user_id = %d", $session_id, $user_id));

        if (!$session) {
            return '<div class="qp-container"><p>Error: Session not found or you do not have permission to view it.</p></div>';
        }

        $options = get_option('qp_settings');
        $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');

        // --- NEW: Extract marking scheme from settings ---
        $settings = json_decode($session->settings_snapshot, true);
        $marks_correct = $settings['marks_correct'] ?? 1;
        $marks_incorrect = $settings['marks_incorrect'] ?? 0;

        $accuracy = ($session->total_attempted > 0) ? ($session->correct_count / $session->total_attempted) * 100 : 0;

        // --- Calculate Average Time Per Question ---
        $avg_time_per_question = 'N/A';
        if ($session->total_attempted > 0 && isset($session->total_active_seconds)) {
            $avg_seconds = round($session->total_active_seconds / $session->total_attempted);
            // Format seconds into H:i:s
            $avg_time_per_question = sprintf('%02d:%02d', floor($avg_seconds / 60), $avg_seconds % 60);
        }

        // NEW: Get all unique topics for this session's questions
        $session_question_ids = json_decode($session->question_ids_snapshot);
        // NEW: Get all unique topics for the questions ATTEMPTED in this session
        $topics_in_session = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT t.topic_name
            FROM {$wpdb->prefix}qp_topics t
            JOIN {$wpdb->prefix}qp_questions q ON t.topic_id = q.topic_id
            JOIN {$wpdb->prefix}qp_user_attempts a ON q.question_id = a.question_id
            WHERE a.session_id = %d
            ORDER BY t.topic_name ASC
        ", $session_id));

        // -- START: Replacement Code --
        $attempts_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT 
        a.question_id, a.selected_option_id, a.is_correct, a.mock_status,
        q.question_text, q.custom_question_id, q.question_number_in_section,
        g.direction_text, s.subject_name, t.topic_name,
        src.source_name, sec.section_name
     FROM {$wpdb->prefix}qp_user_attempts a
     JOIN {$wpdb->prefix}qp_questions q ON a.question_id = q.question_id
     LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
     LEFT JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
     LEFT JOIN {$wpdb->prefix}qp_topics t ON q.topic_id = t.topic_id
     LEFT JOIN {$wpdb->prefix}qp_sources src ON q.source_id = src.source_id
     LEFT JOIN {$wpdb->prefix}qp_source_sections sec ON q.section_id = sec.section_id
     WHERE a.session_id = %d
     ORDER BY a.attempt_id ASC",
            $session_id
        ));

        $attempted_question_ids = wp_list_pluck($attempts_raw, 'question_id');
        $all_options = [];
        if (!empty($attempted_question_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $attempted_question_ids));
            $options_results = $wpdb->get_results("SELECT question_id, option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id IN ($ids_placeholder)");
            foreach ($options_results as $option) {
                $all_options[$option->question_id][] = $option;
            }
        }

        // Combine attempts with their options
        $attempts = [];
        foreach ($attempts_raw as $attempt) {
            $attempt->options = $all_options[$attempt->question_id] ?? [];
            $attempt->selected_answer = '';
            $attempt->correct_answer = '';
            foreach ($attempt->options as $option) {
                if ($option->is_correct) {
                    $attempt->correct_answer = $option->option_text;
                }
                if ($option->option_id == $attempt->selected_option_id) {
                    $attempt->selected_answer = $option->option_text;
                }
            }
            $attempts[] = $attempt;
        }

        ob_start();
        echo '<div id="qp-practice-app-wrapper">';
        // --- Determine Session Mode ---
        $is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';
        // Get a list of all open reports for the current user to disable buttons
        $reported_qids_for_user = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$wpdb->prefix}qp_question_reports WHERE user_id = %d AND status = 'open'",
            $user_id
        ));

        $mode_class = 'mode-normal';
        $mode = 'Practice'; // A generic default

        if ($is_mock_test) {
            $mode_class = 'mode-mock-test';
            $mode = 'Mock Test';
        } elseif (isset($settings['practice_mode'])) {
            switch ($settings['practice_mode']) {
                case 'revision':
                    $mode_class = 'mode-revision';
                    $mode = 'Revision Mode';
                    break;
                case 'Incorrect Que. Practice':
                    $mode_class = 'mode-incorrect';
                    $mode = 'Incorrect Practice';
                    break;
                case 'Section Wise Practice':
                    $mode_class = 'mode-section-wise';
                    $mode = 'Section Practice';
                    break;
            }
        } elseif (isset($settings['subject_id']) && $settings['subject_id'] === 'review') {
            $mode_class = 'mode-review';
            $mode = 'Review Mode';
        }
    ?>
        <div class="qp-container qp-review-wrapper <?php echo esc_attr($mode_class); ?>">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h2>Session Review</h2>
                    <div style="display: flex; align-items: center; gap: 15px; margin-top: 5px;">
                        <span class="qp-session-mode-indicator" style="padding: 5px 12px; font-size: 12px;"><?php echo esc_html($mode); ?></span>
                        <p style="margin: 0; color: #50575e; font-size: 14px;"><strong>Session ID:</strong> <?php echo esc_html($session_id); ?></p>
                    </div>

                </div>
                <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary" style="text-decoration: none;">&laquo; Dashboard</a>
            </div>

            <div class="qp-summary-wrapper qp-review-summary">
                <div class="qp-summary-stats">
                    <?php if (isset($settings['marks_correct'])): ?>
                        <div class="stat">
                            <div class="value"><?php echo number_format($session->marks_obtained, 2); ?></div>
                            <div class="label">Final Score</div>
                        </div>
                    <?php endif; ?>
                    <div class="stat">
                        <div class="value"><?php echo esc_html($avg_time_per_question); ?></div>
                        <div class="label">Avg. Time / Q</div>
                    </div>
                    <div class="stat accuracy">
                        <div class="value"><?php echo round($accuracy, 2); ?>%</div>
                        <div class="label">Accuracy</div>
                    </div>
                    <div class="stat">
                        <div class="value"><?php echo (int)$session->correct_count; ?></div>
                        <div class="label">Correct<?php if (isset($settings['marks_correct'])) echo ' (+' . esc_html($marks_correct) . '/Q)'; ?></div>
                    </div>
                    <div class="stat">
                        <div class="value"><?php echo (int)$session->incorrect_count; ?></div>
                        <div class="label">Incorrect<?php if (isset($settings['marks_correct'])) echo ' (' . esc_html($marks_incorrect) . '/Q)'; ?></div>
                    </div>

                    <?php // --- NEW: Conditional Stat Display ---
                    if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test') : ?>
                        <div class="stat">
                            <div class="value"><?php echo (int)$session->skipped_count; ?></div>
                            <div class="label">Unattempted</div>
                        </div>
                        <div class="stat">
                            <div class="value"><?php echo (int)$session->not_viewed_count; ?></div>
                            <div class="label">Not Viewed</div>
                        </div>
                    <?php else : ?>
                        <div class="stat">
                            <div class="value"><?php echo (int)$session->skipped_count; ?></div>
                            <div class="label">Skipped</div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($topics_in_session)): ?>
                    <div class="qp-review-topics-list">
                        <strong>Topics in this session:</strong> <?php echo implode(', ', array_map('esc_html', $topics_in_session)); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="qp-review-questions-list">
                <?php foreach ($attempts as $index => $attempt) :
                    $is_skipped = !$attempt->selected_answer;
                    $answer_display_text = 'Skipped';
                    $answer_class = $is_skipped ? 'skipped' : ($attempt->is_correct ? 'correct' : 'incorrect');

                    if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test') {
                        if ($attempt->mock_status === 'not_viewed' || $attempt->mock_status === 'viewed' || $attempt->mock_status === 'marked_for_review') {
                            $answer_display_text = 'Unattempted';
                            $answer_class = 'unattempted'; // Apply our new CSS class
                        }
                    }
                ?>
                    <div class="qp-review-question-item">
                        <div class="qp-review-question-meta" style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div class="meta-left" style="display: flex; flex-direction: column; gap: 5px;">
                                <span><strong>ID: </strong><?php echo esc_html($attempt->custom_question_id); ?></span>
                                <span>
                                    <strong>Topic: </strong>
                                    <?php
                                    $topic_display = esc_html($attempt->subject_name);
                                    if (!empty($attempt->topic_name)) {
                                        $topic_display .= ' / ' . esc_html($attempt->topic_name);
                                    }
                                    echo $topic_display; ?>
                                </span>
                            </div>
                            <div class="meta-right">
                                <?php
                                $is_reported = in_array($attempt->question_id, $reported_qids_for_user);
                                ?>
                                <button class="qp-report-button qp-report-btn-review"
                                    data-question-id="<?php echo esc_attr($attempt->question_id); ?>"
                                    <?php echo $is_reported ? 'disabled' : ''; ?>>
                                    <span>&#9888;</span> <?php echo $is_reported ? 'Reported' : 'Report'; ?>
                                </button>
                            </div>
                        </div>
                        <?php
                        $user_can_view_source = !empty(array_intersect((array)wp_get_current_user()->roles, (array)($options['show_source_meta_roles'] ?? [])));
                        if ($mode === 'Section Wise Practice' && $user_can_view_source):
                            $source_parts = [];
                            if ($attempt->source_name) $source_parts[] = esc_html($attempt->source_name);
                            // CHANGE #1: The line for topic_name has been removed from here.
                            if ($attempt->section_name) $source_parts[] = esc_html($attempt->section_name);
                            if ($attempt->question_number_in_section) $source_parts[] = 'Q. ' . esc_html($attempt->question_number_in_section);
                        ?>
                            <div class="qp-review-source-meta">
                                <?php echo implode(' / ', $source_parts); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($attempt->direction_text)): ?>
                            <div class="qp-review-direction-text">
                                <?php echo wp_kses_post(nl2br($attempt->direction_text)); ?>
                            </div>
                        <?php endif; ?>

                        <div class="qp-review-question-text">
                            <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo wp_kses_post(nl2br($attempt->question_text)); ?>
                        </div>

                        <div class="qp-review-answer-row">
                            <span class="qp-review-label">Your Answer:</span>
                            <span class="qp-review-answer <?php echo $answer_class; ?>">
                                <?php echo esc_html($attempt->selected_answer ?: $answer_display_text); ?>
                            </span>
                        </div>

                        <?php if ($is_skipped || !$attempt->is_correct) : ?>
                            <div class="qp-review-answer-row">
                                <span class="qp-review-label">Correct Answer:</span>
                                <span class="qp-review-answer correct">
                                    <?php echo esc_html($attempt->correct_answer); ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="qp-review-all-options-wrapper" style="margin-top: 0.5rem; padding-top: 0.5rem;">
                            <details>
                                <summary style="cursor: pointer; font-weight: bold; color: #2271b1; font-size: 13px; list-style-position: inside; outline: none;">
                                    Show All Options
                                </summary>
                                <ul style="margin: 10px 0 0 0; padding-left: 20px; list-style-type: upper-alpha;">
                                    <?php foreach ($attempt->options as $option): ?>
                                        <li style="padding: 2px 0; <?php echo $option->is_correct ? 'font-weight: bold; color: #2e7d32;' : ''; ?>">
                                            <?php echo esc_html($option->option_text); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="qp-report-modal-backdrop" style="display: none;">
            <div id="qp-report-modal-content">
                <button class="qp-modal-close-btn">&times;</button>
                <h3>Report an Issue</h3>
                <p>Please select all issues that apply to the current question.</p>
                <form id="qp-report-form">
                    <input type="hidden" id="qp-report-question-id-field" value="">
                    <div id="qp-report-options-container"></div>
                    <div class="qp-modal-footer">
                        <button type="submit" class="qp-button qp-button-primary">Submit Report</button>
                    </div>
                </form>
            </div>
        </div>
<?php
        echo '</div>';
        return ob_get_clean();
    }
}
