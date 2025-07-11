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
        $output = '<div id="qp-practice-app-wrapper">';
        $output .= self::render_settings_form();
        $output .= '</div>';
        return $output;
    }

    // In public/class-qp-shortcodes.php

    

public static function render_session_page() {
    if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
        return '<div class="qp-container"><p>Error: No valid practice session was found. Please start a new session.</p></div>';
    }

    $session_id = absint($_GET['session_id']);
    $user_id = get_current_user_id();

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $session_data_from_db = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE session_id = %d", $session_id));

    if (!$session_data_from_db || (int)$session_data_from_db->user_id !== $user_id) {
        return '<div class="qp-container"><p>Error: You do not have permission to access this session or it is invalid.</p></div>';
    }

    // --- NEW: Check if the session is already finished ---
    if (in_array($session_data_from_db->status, ['completed', 'abandoned'])) {
        $summary_data = [
            'final_score' => $session_data_from_db->marks_obtained,
            'total_attempted' => $session_data_from_db->total_attempted,
            'correct_count' => $session_data_from_db->correct_count,
            'incorrect_count' => $session_data_from_db->incorrect_count,
            'skipped_count' => $session_data_from_db->skipped_count,
        ];
        // We can reuse the summary UI generating function from the JS by wrapping it in a simple class
        return '<div id="qp-practice-app-wrapper">' . self::render_summary_ui($summary_data) . '</div>';
    }

    // --- If the session is active, proceed as normal ---
    $session_data = [
        'session_id'    => $session_id,
        'question_ids'  => json_decode($session_data_from_db->question_ids_snapshot, true),
        'settings'      => json_decode($session_data_from_db->settings_snapshot, true)
    ];

    $attempt_history = $wpdb->get_results($wpdb->prepare(
        "SELECT a.question_id, a.selected_option_id, a.is_correct, o.option_id as correct_option_id
         FROM {$wpdb->prefix}qp_user_attempts a
         LEFT JOIN {$wpdb->prefix}qp_options o ON a.question_id = o.question_id AND o.is_correct = 1
         WHERE a.session_id = %d",
        $session_id
    ), OBJECT_K);

    $session_data['attempt_history'] = $attempt_history;
    self::$session_data_for_script = $session_data;

    return '<div id="qp-practice-app-wrapper">' . self::render_practice_ui() . '</div>';
}

public static function render_summary_ui($summaryData) {
    $options = get_option('qp_settings');
    $dashboard_page_url = isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/');
    $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');

    ob_start();
    ?>
    <div class="qp-summary-wrapper">
        <h2>Session Summary</h2>
        <div class="qp-summary-score"><div class="label">Final Score</div><?php echo number_format($summaryData['final_score'], 2); ?></div>
        <div class="qp-summary-stats">
            <div class="stat"><div class="value"><?php echo (int)$summaryData['total_attempted']; ?></div><div class="label">Attempted</div></div>
            <div class="stat"><div class="value"><?php echo (int)$summaryData['correct_count']; ?></div><div class="label">Correct</div></div>
            <div class="stat"><div class="value"><?php echo (int)$summaryData['incorrect_count']; ?></div><div class="label">Incorrect</div></div>
            <div class="stat"><div class="value"><?php echo (int)$summaryData['skipped_count']; ?></div><div class="label">Skipped</div></div>
        </div>
        <div class="qp-summary-actions">
            <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary">View Dashboard</a>
            <a href="<?php echo esc_url($practice_page_url); ?>" class="qp-button qp-button-primary">Start Another Practice</a>
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
        $subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");

        // Get the dynamic URL for the dashboard page
        $options = get_option('qp_settings');
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
        $dashboard_page_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : '';

        ob_start();
?>
        <div class="qp-container qp-practice-form-wrapper">
            <h2>Start a New Practice Session</h2>
            <form id="qp-start-practice-form" method="post" action="">

                <div class="qp-form-group">
                    <label for="qp_subject">Select Subject:</label>
                    <select name="qp_subject" id="qp_subject" required>
                        <option value="" disabled selected>-- Please select a subject --</option>
                        <option value="all">All Subjects</option>
                        <?php foreach ($subjects as $subject) : ?>
                            <option value="<?php echo esc_attr($subject->subject_id); ?>"><?php echo esc_html($subject->subject_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="qp-form-group" id="qp-topic-group" style="display: none;">
                    <label for="qp_topic">Select Topic:</label>
                    <select name="qp_topic" id="qp_topic" disabled>
                        <option value="">-- Select a subject first --</option>
                    </select>
                </div>
                <?php
                // Find all labels that start with "Sheet"
                $sheet_labels = $wpdb->get_results(
                    $wpdb->prepare("SELECT label_id, label_name FROM {$wpdb->prefix}qp_labels WHERE label_name LIKE %s ORDER BY label_name ASC", 'Sheet%')
                );

                if (!empty($sheet_labels)) : ?>
                    <div class="qp-form-group" id="qp-sheet-group" style="display: none;">
                        <label for="qp_sheet_label">Select Sheet:</label>
                        <select name="qp_sheet_label" id="qp_sheet_label">
                            <option value="all">All Sheets</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="qp-form-group qp-checkbox-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_pyq_only" value="1">
                        <span></span>
                        PYQ Only
                    </label>
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_revise_mode" value="1">
                        <span></span>
                        Revision Mode
                    </label>
                </div>
                <div class="qp-form-group-description">
                    <p><strong>PYQ Only:</strong> Only include questions that are marked as a "Previous Year Question".</p>
                    <p><strong>Revision Mode:</strong> Previously answered questions will reappear in your session to help you revise.</p>
                </div>

                <div class="qp-form-group qp-marks-group">
                    <div>
                        <label for="qp_marks_correct">Marks for Correct Answer:</label>
                        <input type="number" name="qp_marks_correct" id="qp_marks_correct" value="4" step="0.1" required>
                    </div>
                    <div>
                        <label for="qp_marks_incorrect">Penalty for Incorrect Answer:</label>
                        <input type="number" name="qp_marks_incorrect" id="qp_marks_incorrect" value="1" step="0.1" min="0" required>
                    </div>
                </div>

                <div class="qp-form-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_timer_enabled" id="qp_timer_enabled_cb">
                        <span></span>
                        Enable Timer per Question
                    </label>
                    <div id="qp-timer-input-wrapper" style="display: none; margin-top: 15px;">
                        <label for="qp_timer_seconds">Time in Seconds:</label>
                        <input type="number" name="qp_timer_seconds" id="qp_timer_seconds" value="60" min="10">
                    </div>
                </div>

                <div class="qp-form-group qp-action-buttons">
                    <input type="submit" name="qp_start_practice" value="Start Practice" class="qp-button qp-button-primary">
                    <?php if ($dashboard_page_url) : ?>
                        <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary">Go to Dashboard</a>
                    <?php endif; ?>
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
                <div class="qp-header-top-row">
                    <div class="qp-header-stat score">
                        <div class="label">Score</div>
                        <div class="value" id="qp-score">0.00</div>
                    </div>
                    <div class="qp-header-stat timer-stat">
                        <div class="label">Timer</div>
                        <div class="value" id="qp-timer">--:--</div>
                    </div>
                </div>
                <div class="qp-header-bottom-row">
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
                    <div class="question-meta" style="font-size: 12px; color: #777; margin-bottom: 10px;">
                        <span id="qp-question-subject"></span> | <span id="qp-question-id"></span>
                    </div>
                    <div id="qp-question-source" style="font-size: 12px; color: #777; margin-bottom: 10px; display: none;"></div>

                    <div class="qp-direction" style="display: none;"></div>

                    <div class="qp-question-area">
                        <div id="qp-revision-indicator" style="display: none; margin-bottom: 15px; background-color: #fffbe6; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; font-weight: bold; color: #856404;">
                        &#9851; This is a Revision Question
                    </div>
                    <div id="qp-reported-indicator" style="display: none; margin-bottom: 15px; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 4px; font-weight: bold; color: #856404;">
                        &#9888; You have reported an issue with this question.
                    </div>
                        <div class="question-text" id="qp-question-text-area">
                            <p>Loading question...</p>
                        </div>
                    </div>

                    <div class="qp-options-area"></div>
                </div>
            </div>

            <div class="qp-footer-nav">
                <button id="qp-prev-btn" class="qp-button qp-button-secondary" disabled>&laquo; Previous</button>
                <button id="qp-skip-btn" class="qp-button qp-button-secondary">Skip</button>
                <button id="qp-next-btn" class="qp-button qp-button-primary">Next &raquo;</button>
            </div>

            <div class="qp-footer-controls" style="margin-top: 20px; text-align: center;">
                <button id="qp-end-practice-btn" class="qp-button qp-button-danger">End Practice</button>
            </div>

            <div class="qp-user-report-area">
                <h4>Report an Issue</h4>
                <div class="button-group">
                     <button class="qp-report-button qp-report-color-error" data-label="Wrong Answer">Wrong Answer</button>
                     <button class="qp-report-button qp-report-color-warning" data-label="No Answer">No Answer</button>
                </div>
            </div>

            <?php if (current_user_can('manage_options')) : // Admin-only tools ?>
            <div class="qp-admin-report-area">
                <h4>For Admins Only (Label As)</h4>
                <div class="button-group">
                    <button class="qp-report-button qp-report-color-info" data-label="Incorrect Formatting">Incorrect Formatting</button>
                    <button class="qp-report-button qp-report-color-neutral" data-label="Wrong Subject">Wrong Subject</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
<?php
        return ob_get_clean();
    }

    public static function render_review_page() {
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

        // --- CORRECTED QUERY ---
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
            <h2>Session Review</h2>
            
            <div class="qp-summary-wrapper qp-review-summary">
                <div class="qp-summary-stats">
                     <div class="stat"><div class="value"><?php echo number_format($session->marks_obtained, 2); ?></div><div class="label">Final Score</div></div>
                    <div class="stat"><div class="value"><?php echo (int)$session->correct_count; ?></div><div class="label">Correct</div></div>
                    <div class="stat"><div class="value"><?php echo (int)$session->incorrect_count; ?></div><div class="label">Incorrect</div></div>
                    <div class="stat"><div class="value"><?php echo (int)$session->skipped_count; ?></div><div class="label">Skipped</div></div>
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
