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

            <div class="qp-form-group qp-marks-group">
                <div>
                    <label for="qp_revision_marks_correct">Marks for Correct Answer:</label>
                    <input type="number" name="qp_marks_correct" id="qp_revision_marks_correct" value="4" step="0.01" required>
                </div>
                <div>
                    <label for="qp_revision_marks_incorrect">Penalty for Incorrect Answer:</label>
                    <input type="number" name="qp_marks_incorrect" id="qp_revision_marks_incorrect" value="1" step="0.01" min="0" required>
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


    public static function render_session_page()
    {
        if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
            return '<div class="qp-container"><p>Error: No valid practice session was found. Please start a new session.</p></div>';
        }

        $session_id = absint($_GET['session_id']);
        $user_id = get_current_user_id();

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $session_data_from_db = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE session_id = %d", $session_id));

        // **THE FIX**: This is the new, simplified error handling logic.
        if (!$session_data_from_db || (int)$session_data_from_db->user_id !== $user_id) {
            $options = get_option('qp_settings');
            $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');
            $accuracy = 0;
            if ($session->total_attempted > 0) {
                $accuracy = ($session->correct_count / $session->total_attempted) * 100;
            }

            return '<div class="qp-container" style="text-align: center; padding: 40px 20px;">
                    <h3 style="margin-top:0; font-size: 22px;">Session Not Found</h3>
                    <p style="font-size: 16px; color: #555; margin-bottom: 25px;">This session is either invalid or was abandoned and has been removed.</p>
                    <a href="' . esc_url($dashboard_page_url) . '" class="qp-button qp-button-primary" style="text-decoration: none;">View Dashboard</a>
                </div>';
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
            return '<div id="qp-practice-app-wrapper">' . self::render_summary_ui($summary_data, $session_id) . '</div>';
        }

        // --- If the session is active, proceed as normal ---
        $session_data = [
            'session_id'    => $session_id,
            'question_ids'  => json_decode($session_data_from_db->question_ids_snapshot, true),
            'settings'      => json_decode($session_data_from_db->settings_snapshot, true)
        ];

        $attempt_history = $wpdb->get_results($wpdb->prepare(
            "SELECT a.question_id, a.selected_option_id, a.is_correct, a.status, a.remaining_time, o.option_id as correct_option_id
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

    public static function render_summary_ui($summaryData, $session_id = 0)
    {
        $options = get_option('qp_settings');
        $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');
        $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/');
        $session_review_url = $review_page_url ? add_query_arg('session_id', $session_id, $review_page_url) : '#';

        $accuracy = 0;
        if (isset($summaryData['total_attempted']) && $summaryData['total_attempted'] > 0) {
            $accuracy = ($summaryData['correct_count'] / $summaryData['total_attempted']) * 100;
        }

        ob_start();
    ?>
        <div class="qp-summary-wrapper">
            <h2>Session Summary</h2>
            <div class="qp-summary-stats">
                <div class="stat">
                    <div class="value"><?php echo number_format($summaryData['final_score'], 2); ?></div>
                    <div class="label">Final Score</div>
                </div>
                <div class="stat accuracy">
                    <div class="value"><?php echo round($accuracy, 2); ?>%</div>
                    <div class="label">Accuracy</div>
                </div>
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
                    <p><strong>PYQ Only:</strong> Only include Previous Year Question.</p>
                    <p><strong>Revision Mode:</strong> Previously answered questions will reappear in your session.</p>
                </div>

                <div class="qp-form-group qp-marks-group" style="display: flex; flex-direction: row; justify-content: space-between;">
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
        ob_start();
    ?>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <div class="qp-container qp-practice-wrapper">
            <div class="qp-header">
                <div class="qp-header-bottom-row">
                    <div class="qp-header-stat score">
                        <span class="value" id="qp-score">0.00</span>
                        <span class="label">Score</span>
                    </div>
                    <div class="qp-header-stat correct">
                        <span class="value" id="qp-correct-count">0</span>
                        <span class="label">Correct</span>
                    </div>
                    <div class="qp-header-stat incorrect">
                        <span class="value" id="qp-incorrect-count">0</span>
                        <span class="label">Incorrect</span>
                    </div>
                    <div class="qp-header-stat skipped">
                        <span class="value" id="qp-skipped-count">0</span>
                        <span class="label">Skipped</span>
                    </div>
                </div>
            </div>

            <div class="qp-animatable-area-container">
                <div class="qp-animatable-area">
                    <div class="question-meta">
                        <div class="qp-question-meta-left">
                            <div id="qp-question-subject-line"><span id="qp-question-subject"></span> | <span id="qp-question-id"></span></div>
                            <div id="qp-question-source" style="display: none;"></div>
                        </div>
                        <div class="qp-question-counter-box" style="display: none;">
                            <span class="qp-counter-label">Q. No.</span>
                            <span class="qp-counter-value" id="qp-question-counter">--/--</span>
                        </div>
                    </div>

                    <div class="qp-indicator-bar" style="display: none;">
                        <div id="qp-timer-indicator" class="timer-stat" style="display: none;">--:--</div>
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
                    <div class="qp-review-later" style="text-align:center;"><label class="qp-review-later-checkbox qp-button qp-button-secondary">
                            <input type="checkbox" id="qp-mark-for-review-cb">
                            <span>Mark for Review</span>
                        </label></div>

                </div>
            </div>

            <div class="qp-footer-nav">
                <button id="qp-prev-btn" class="qp-button qp-button-secondary" disabled>
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </button>
                <button id="qp-skip-btn" class="qp-button qp-button-secondary">Skip</button>
                <button id="qp-next-btn" class="qp-button qp-button-primary">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
            </div>

            <hr class="qp-footer-divider">

            <div class="qp-footer-controls">
                <button id="qp-report-btn" class="qp-button qp-button-secondary">
                    <span class="dashicons dashicons-flag"></span> Report
                </button>

                <button id="qp-end-practice-btn" class="qp-button qp-button-danger">End Session</button>
            </div>
        </div>
        <div id="qp-report-modal-backdrop" style="display: none;">
            <div id="qp-report-modal-content">
                <button class="qp-modal-close-btn">&times;</button>
                <h3>Report an Issue</h3>
                <p>Please select all issues that apply to the current question.</p>
                <form id="qp-report-form">
                    <div id="qp-report-options-container">
                    </div>
                    <div class="qp-modal-footer">
                        <button type="submit" class="qp-button qp-button-primary">Submit Report</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="qp-start-session-overlay">
    <div class="qp-start-session-content">
        <h3>Your session is ready.</h3>
        <p>Click the button below to begin in an immersive, fullscreen environment.</p>
        <button id="qp-fullscreen-start-btn" class="qp-button qp-button-primary">Start & Enter Fullscreen</button>
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

        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT q.question_text, g.direction_text, o.option_text AS selected_answer, o_correct.option_text AS correct_answer, a.is_correct
         FROM {$wpdb->prefix}qp_user_attempts a
         JOIN {$wpdb->prefix}qp_questions q ON a.question_id = q.question_id
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
         LEFT JOIN {$wpdb->prefix}qp_options o ON a.selected_option_id = o.option_id
         LEFT JOIN {$wpdb->prefix}qp_options o_correct ON q.question_id = o_correct.question_id AND o_correct.is_correct = 1
         WHERE a.session_id = %d
         ORDER BY a.attempt_id ASC",
            $session_id
        ));

        ob_start();
    ?>
        <div class="qp-container qp-review-wrapper">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2>Session Review</h2>
                <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary" style="text-decoration: none;">&laquo; Back to Dashboard</a>
            </div>

            <div class="qp-summary-wrapper qp-review-summary">
                <div class="qp-summary-stats">
                    <div class="stat">
                        <div class="value"><?php echo number_format($session->marks_obtained, 2); ?></div>
                        <div class="label">Final Score</div>
                    </div>
                    <div class="stat accuracy">
                        <div class="value"><?php echo round($accuracy, 2); ?>%</div>
                        <div class="label">Accuracy</div>
                    </div>
                    <div class="stat">
                        <div class="value"><?php echo (int)$session->correct_count; ?></div>
                        <div class="label">Correct (+<?php echo esc_html($marks_correct); ?>/Q)</div>
                    </div>
                    <div class="stat">
                        <div class="value"><?php echo (int)$session->incorrect_count; ?></div>
                        <div class="label">Incorrect (<?php echo esc_html($marks_incorrect); ?>/Q)</div>
                    </div>
                    <div class="stat">
                        <div class="value"><?php echo (int)$session->skipped_count; ?></div>
                        <div class="label">Skipped</div>
                    </div>
                </div>
            </div>

            <div class="qp-review-questions-list">
                <h3 style="margin-top: 2rem;">Attempted Questions</h3>
                <?php foreach ($attempts as $index => $attempt) : ?>
                    <div class="qp-review-question-item">
                        <?php if (!empty($attempt->direction_text)): ?>
                            <div class="qp-review-direction-text">
                                <?php echo wp_kses_post($attempt->direction_text); ?>
                            </div>
                        <?php endif; ?>
                        <div class="qp-review-question-text">
                            <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo wp_kses_post($attempt->question_text); ?>
                        </div>
                        <div class="qp-review-answer-row">
                            <span class="qp-review-label">Your Answer:</span>
                            <span class="qp-review-answer <?php echo $attempt->is_correct ? 'correct' : 'incorrect'; ?>">
                                <?php echo esc_html($attempt->selected_answer ?: 'Skipped'); ?>
                            </span>
                        </div>
                        <?php if (!$attempt->is_correct && $attempt->selected_answer) : ?>
                            <div class="qp-review-answer-row">
                                <span class="qp-review-label">Correct Answer:</span>
                                <span class="qp-review-answer correct">
                                    <?php echo esc_html($attempt->correct_answer); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
<?php
        return ob_get_clean();
    }
}
