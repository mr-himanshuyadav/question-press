<?php
if (!defined('ABSPATH')) exit;

class QP_Shortcodes
{

    // A static property to temporarily hold session data for the script.
    private static $session_data_for_script = null;

    public static function render_practice_form()
    {
        if (!is_user_logged_in()) {
            // Keep the login message HTML here for now, or move to its own template later
            return '<div style="text-align:center; padding: 40px 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin-top:0; font-size: 22px;">Please Log In to Begin</h3>
                        <p style="font-size: 16px; color: #555; margin-bottom: 25px;">You need to be logged in to start a new practice session and track your progress.</p>
                        <a href="' . wp_login_url(get_permalink()) . '" class="qp-button qp-button-primary" style="text-decoration: none;">Click Here to Log In</a>
                    </div>';
        }

        // --- Keep pre-fill logic ---
        if (isset($_GET['start_section_practice']) && $_GET['start_section_practice'] === 'true') {
            // ... (keep existing pre-fill script logic) ...
            // Directly render the settings form using its *new* template loader call
             return '<div id="qp-practice-app-wrapper">' . self::render_settings_form() . '</div>';
        }

        // --- Prepare data for the wrapper template ---
        $options = get_option('qp_settings');
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
        $dashboard_page_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : '';

        // --- Load template parts ---
        // Step 1 data is just the dashboard URL
        $step_1_html = qp_get_template_html('practice-form-step-1-mode', 'frontend', ['dashboard_page_url' => $dashboard_page_url]);

        // Call the other render methods which will now also use qp_get_template_html
        $step_2_html = self::render_settings_form();
        $step_3_html = self::render_revision_mode_form();
        $step_4_html = self::render_mock_test_form();
        $step_5_html = self::render_section_wise_practice_form();

        // --- Load the main wrapper template ---
        return qp_get_template_html('practice-form-wrapper', 'frontend', [
            'dashboard_page_url' => $dashboard_page_url, // Pass again if needed directly in wrapper
            'step_1_html' => $step_1_html,
            'step_2_html' => $step_2_html,
            'step_3_html' => $step_3_html,
            'step_4_html' => $step_4_html,
            'step_5_html' => $step_5_html,
        ]);
    }

    // --- TEMPORARY: Keep render_settings_form etc., but remove their HTML output ---
    public static function render_settings_form()
    {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $subjects = [];
        if ($subject_tax_id) {
             $subjects = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                $subject_tax_id
            ));
        }

        // --- Filter subjects based on user scope ---
        $user_id = get_current_user_id();
        $allowed_subjects_or_all = qp_get_allowed_subject_ids_for_user($user_id); // Get 'all' or array of IDs
        $allowed_subjects_array = []; // Used for filtering below
        $multiSelectDisabled = false;

        if ($allowed_subjects_or_all !== 'all' && is_array($allowed_subjects_or_all)) {
            $allowed_subjects_array = $allowed_subjects_or_all;
            $subjects = array_filter($subjects, function($subject) use ($allowed_subjects_array) {
                return isset($subject->subject_id) && in_array($subject->subject_id, $allowed_subjects_array);
            });
             // Check if the filtered list is empty AND user has restrictions
             if (empty($subjects)) {
                $multiSelectDisabled = true;
             }
        }

        // Prepare arguments for the template
        $args = [
            'subjects'            => $subjects,
            'allowed_subjects'    => $allowed_subjects_or_all === 'all' ? 'all' : wp_json_encode($allowed_subjects_array), // Pass 'all' or JSON array
            'multiSelectDisabled' => $multiSelectDisabled
        ];

        // Load and return the template HTML
        return qp_get_template_html('practice-form-step-2-normal', 'frontend', $args);
    }

    public static function render_revision_mode_form()
    {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $subjects = [];
        if ($subject_tax_id) {
             $subjects = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                $subject_tax_id
            ));
        }

        // --- Filter subjects based on user scope ---
        $user_id = get_current_user_id();
        $allowed_subjects_or_all = qp_get_allowed_subject_ids_for_user($user_id);
        $allowed_subjects_array = [];
        $multiSelectDisabled = false;

        if ($allowed_subjects_or_all !== 'all' && is_array($allowed_subjects_or_all)) {
            $allowed_subjects_array = $allowed_subjects_or_all;
            $subjects = array_filter($subjects, function($subject) use ($allowed_subjects_array) {
                return isset($subject->subject_id) && in_array($subject->subject_id, $allowed_subjects_array);
            });
            if (empty($subjects)) {
                $multiSelectDisabled = true;
            }
        }

        // Prepare arguments for the template
        $args = [
            'subjects'            => $subjects,
            'allowed_subjects'    => $allowed_subjects_or_all === 'all' ? 'all' : wp_json_encode($allowed_subjects_array),
            'multiSelectDisabled' => $multiSelectDisabled
        ];

        // Load and return the template HTML
        return qp_get_template_html('practice-form-step-3-revision', 'frontend', $args);
    }

    public static function render_mock_test_form()
    {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $subjects = [];
        if ($subject_tax_id) {
             $subjects = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                $subject_tax_id
            ));
        }

        // --- Filter subjects based on user scope ---
        $user_id = get_current_user_id();
        $allowed_subjects_or_all = qp_get_allowed_subject_ids_for_user($user_id);
        $allowed_subjects_array = [];
        $multiSelectDisabled = false;

        if ($allowed_subjects_or_all !== 'all' && is_array($allowed_subjects_or_all)) {
            $allowed_subjects_array = $allowed_subjects_or_all;
            $subjects = array_filter($subjects, function($subject) use ($allowed_subjects_array) {
                return isset($subject->subject_id) && in_array($subject->subject_id, $allowed_subjects_array);
            });
             if (empty($subjects)) {
                $multiSelectDisabled = true;
             }
        }

        // Prepare arguments for the template
        $args = [
            'subjects'            => $subjects,
            'allowed_subjects'    => $allowed_subjects_or_all === 'all' ? 'all' : wp_json_encode($allowed_subjects_array),
            'multiSelectDisabled' => $multiSelectDisabled
        ];

        // Load and return the template HTML
        return qp_get_template_html('practice-form-step-4-mock', 'frontend', $args);
    }

    public static function render_section_wise_practice_form()
    {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $subjects = [];
        if ($subject_tax_id) {
             $subjects = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                $subject_tax_id
            ));
        }

        // --- Filter subjects based on user scope ---
        $user_id = get_current_user_id();
        $allowed_subjects_or_all = qp_get_allowed_subject_ids_for_user($user_id);
        $allowed_subjects_array = [];
        $sectionWiseDisabled = false; // Changed variable name

        if ($allowed_subjects_or_all !== 'all' && is_array($allowed_subjects_or_all)) {
            $allowed_subjects_array = $allowed_subjects_or_all;
            $subjects = array_filter($subjects, function($subject) use ($allowed_subjects_array) {
                return isset($subject->subject_id) && in_array($subject->subject_id, $allowed_subjects_array);
            });
            if (empty($subjects)) {
                $sectionWiseDisabled = true; // Use the new variable name
            }
        }

        // Prepare arguments for the template
        $args = [
            'subjects'            => $subjects,
            'allowed_subjects'    => $allowed_subjects_or_all === 'all' ? 'all' : wp_json_encode($allowed_subjects_array),
            'sectionWiseDisabled' => $sectionWiseDisabled // Use the new variable name
        ];

        // Load and return the template HTML
        return qp_get_template_html('practice-form-step-5-section', 'frontend', $args);
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

            // Attention! There is no immediate return back for unauthorised access.
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

        // --- NEW: Fetch detailed report info, including the type ---
$reports_table = $wpdb->prefix . 'qp_question_reports';
$terms_table = $wpdb->prefix . 'qp_terms';
$meta_table = $wpdb->prefix . 'qp_term_meta';

// Get all individual open reports for the user
$all_user_reports = $wpdb->get_results($wpdb->prepare("
    SELECT
        r.question_id,
        r.reason_term_ids
    FROM {$reports_table} r
    WHERE r.user_id = %d AND r.status = 'open'
", $user_id));

// Process the raw reports into the structured format JS expects
$reported_info = [];
foreach ($all_user_reports as $report) {
    if (!isset($reported_info[$report->question_id])) {
        $reported_info[$report->question_id] = [
            'has_report' => false,
            'has_suggestion' => false,
        ];
    }
    
    // Get the types for the reasons in this specific report
    $reason_ids = array_filter(explode(',', $report->reason_term_ids));
    if (!empty($reason_ids)) {
        $ids_placeholder = implode(',', array_map('absint', $reason_ids));
        $reason_types = $wpdb->get_col("
            SELECT m.meta_value 
            FROM {$terms_table} t
            JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'type'
            WHERE t.term_id IN ($ids_placeholder)
        ");

        if (in_array('report', $reason_types)) {
            $reported_info[$report->question_id]['has_report'] = true;
        }
        if (in_array('suggestion', $reason_types)) {
            $reported_info[$report->question_id]['has_suggestion'] = true;
        }
    }
}

$session_data['reported_info'] = $reported_info;

        self::$session_data_for_script = $session_data;

        $preloader_html = '<div id="qp-preloader"><div class="qp-spinner"></div></div>';
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

    public static function render_practice_ui()
    {
        // Get the settings for the current session to determine the mode
        // Ensure session data is available (it should be set by render_session_page)
        $session_data = self::$session_data_for_script;
        if (!$session_data || !isset($session_data['settings'])) {
             // Handle error: Session data not found
             return '<div class="qp-container"><p>Error: Practice session data is missing or corrupt. Cannot render UI.</p></div>';
        }
        $session_settings = $session_data['settings'];

        // Determine mode flags
        $is_mock_test = isset($session_settings['practice_mode']) && $session_settings['practice_mode'] === 'mock_test';
        $is_section_wise = isset($session_settings['practice_mode']) && $session_settings['practice_mode'] === 'Section Wise Practice';
        $is_palette_mandatory = $is_mock_test || $is_section_wise;

        // Determine mode class and name
        $mode_class = 'mode-normal';
        $mode_name = 'Practice Session';
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
                    $mode_name = 'Section Wise Practice';
                    break;
            }
        } elseif (isset($session_settings['subject_id']) && $session_settings['subject_id'] === 'review') {
            $mode_class = 'mode-review';
            $mode_name = 'Review Mode';
        }

        // Check user permission for source meta
        $options = get_option('qp_settings');
        $user = wp_get_current_user();
        $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
        $user_can_view_source = !empty(array_intersect((array)($user->roles ?? []), (array)$allowed_roles));

        // Prepare arguments for the template
        $args = [
            'mode_class'           => $mode_class,
            'mode_name'            => $mode_name,
            'is_mock_test'         => $is_mock_test,
            'is_section_wise'      => $is_section_wise,
            'user_can_view_source' => $user_can_view_source,
            'session_settings'     => $session_settings, // Pass the whole settings array
            'is_palette_mandatory' => $is_palette_mandatory,
        ];

        // Load and return the template HTML
        return qp_get_template_html('practice-ui', 'frontend', $args);
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

        $settings = json_decode($session->settings_snapshot, true);
        $marks_correct = $settings['marks_correct'] ?? 1;
        $marks_incorrect = $settings['marks_incorrect'] ?? 0;

        $accuracy = ($session->total_attempted > 0) ? ($session->correct_count / $session->total_attempted) * 100 : 0;
        $avg_time_per_question = 'N/A';
        if ($session->total_attempted > 0 && isset($session->total_active_seconds)) {
            $avg_seconds = round($session->total_active_seconds / $session->total_attempted);
            $avg_time_per_question = sprintf('%02d:%02d', floor($avg_seconds / 60), $avg_seconds % 60);
        }

        // Get all unique group IDs from the attempts in this session
        $group_ids_in_session = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT q.group_id
            FROM {$wpdb->prefix}qp_user_attempts a
            JOIN {$wpdb->prefix}qp_questions q ON a.question_id = q.question_id
            WHERE a.session_id = %d
        ", $session_id));

        $topics_in_session = [];
        if (!empty($group_ids_in_session)) {
            $group_ids_placeholder = implode(',', $group_ids_in_session);
            // Get the names of the terms in the 'subject' taxonomy linked to those groups
            $topics_in_session = $wpdb->get_col("
                SELECT DISTINCT t.name
                FROM {$wpdb->prefix}qp_terms t
                JOIN {$wpdb->prefix}qp_term_relationships r ON t.term_id = r.term_id
                WHERE r.object_id IN ($group_ids_placeholder)
                  AND r.object_type = 'group'
                  AND t.taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject')
                ORDER BY t.name ASC
            ");
        }

        // --- NEW, CORRECTED QUERY TO FETCH ALL ATTEMPT DATA ---
        $attempts_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                a.attempt_id, a.question_id, a.selected_option_id, a.is_correct, a.mock_status,
                q.question_text, q.question_number_in_section,
                g.group_id, g.direction_text
            FROM {$wpdb->prefix}qp_user_attempts a
            JOIN {$wpdb->prefix}qp_questions q ON a.question_id = q.question_id
            LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
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

        // --- NEW: Fetch all lineage data in fewer queries for efficiency ---
        $lineage_cache = [];
        if (!function_exists('get_term_lineage')) {
            function get_term_lineage($term_id, &$lineage_cache, $wpdb)
            {
                if (isset($lineage_cache[$term_id])) {
                    return $lineage_cache[$term_id];
                }
                $lineage = [];
                $current_id = $term_id;
                for ($i = 0; $i < 10; $i++) {
                    if (!$current_id) break;
                    $term = $wpdb->get_row($wpdb->prepare("SELECT name, parent FROM {$wpdb->prefix}qp_terms WHERE term_id = %d", $current_id));
                    if ($term) {
                        array_unshift($lineage, $term->name);
                        $current_id = $term->parent;
                    } else {
                        break;
                    }
                }
                $lineage_cache[$term_id] = $lineage;
                return $lineage;
            }
        }

        $attempts = [];
        foreach ($attempts_raw as $attempt) {
            $attempt->options = $all_options[$attempt->question_id] ?? [];
            $attempt->selected_answer = '';
            $attempt->correct_answer = '';
            foreach ($attempt->options as $option) {
                if ($option->is_correct) $attempt->correct_answer = $option->option_text;
                if ($option->option_id == $attempt->selected_option_id) $attempt->selected_answer = $option->option_text;
            }

            // Get group and term relationships
            $group_id = $attempt->group_id;
            $subject_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->prefix}qp_term_relationships WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'))", $group_id));
            $source_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->prefix}qp_term_relationships WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'source'))", $group_id));

            $attempt->subject_lineage = $subject_term_id ? get_term_lineage($subject_term_id, $lineage_cache, $wpdb) : [];
            $attempt->source_lineage = $source_term_id ? get_term_lineage($source_term_id, $lineage_cache, $wpdb) : [];

            $attempts[] = $attempt;
        }

        $is_course_item_deleted = false;
        if (isset($settings['course_id']) && isset($settings['item_id'])) {
            $items_table = $wpdb->prefix . 'qp_course_items';
            $item_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_table} WHERE item_id = %d AND course_id = %d",
                absint($settings['item_id']),
                absint($settings['course_id'])
            ));
            if (!$item_exists) {
                $is_course_item_deleted = true;
            }
        }

        ob_start();
        echo '<div id="qp-practice-app-wrapper">';
        $is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';
        $is_section_wise_practice = isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice'; // *** THIS IS THE FIX ***
        $reported_qids_for_user = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$wpdb->prefix}qp_question_reports WHERE user_id = %d AND status = 'open'",
            $user_id
        ));

        $mode_class = 'mode-normal';
        $mode = 'Practice';

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
                    $mode = 'Section Wise Practice';
                    break;
            }
        } elseif (isset($settings['subject_id']) && $settings['subject_id'] === 'review') {
            $mode_class = 'mode-review';
            $mode = 'Review Mode';
        }
    ?>
        <div class="qp-container qp-review-wrapper <?php echo esc_attr($mode_class); ?>">
            <div style="display: flex; flex-direction: column;justify-content: space-between; margin-bottom: 1.5rem;">
                <div style="display: flex;flex-direction: row; justify-content: space-between;">
                    <h2>Review</h2>
                    <a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-secondary" style="align-self: center; padding: 12px 14px;">&laquo; Dashboard</a>
                </div>
                <div style="display: flex; align-items: center; gap: 15px; margin-top: 5px;">
                    <span class="qp-session-mode-indicator" style="padding: 5px 12px; font-size: 12px;"><?php echo esc_html($mode); ?></span>
                    <p style="margin: 0; color: #50575e; font-size: 14px;"><strong>Session ID:</strong> <?php echo esc_html($session_id); ?></p>
                    <?php if ($is_course_item_deleted): ?>
                        <em style="color:#777; font-size:13px;">(Original course item removed)</em>
                     <?php endif; ?>
                </div>
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

                    <?php if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test') : ?>
                        <div class="stat">
                            <div class="value"><?php echo (int)$session->skipped_count; ?></div>
                            <div class="label">Unattempted</div>
                        </div>
                        <div class="stat">
                            <div class="value"><?php echo (int)$session->not_viewed_count; ?></div>
                            <div class="label">Not Viewed</div>
                        </div>
                    <?php elseif (!$is_section_wise_practice) : // *** THIS IS THE FIX *** ?>
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
                    $is_skipped = empty($attempt->selected_option_id);
                    $answer_display_text = 'Skipped';
                    $answer_class = $is_skipped ? 'skipped' : ($attempt->is_correct ? 'correct' : 'incorrect');

                    if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test') {
                        if ($attempt->mock_status === 'not_viewed' || $attempt->mock_status === 'viewed' || $attempt->mock_status === 'marked_for_review') {
                            $answer_display_text = 'Unattempted';
                            $answer_class = 'unattempted';
                        }
                    }
                ?>
                    <div class="qp-review-question-item">
                        <div class="qp-review-question-meta" style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div class="meta-left" style="display: flex; flex-direction: column; gap: 5px;">
                                <span><strong>Question ID: </strong><?php echo esc_html($attempt->question_id); ?><?php if (!empty($attempt->attempt_id)) { echo ' | <strong>Attempt ID: </strong>' . esc_html($attempt->attempt_id); } ?></span>
                                <span>
                                    <strong>Topic: </strong>
                                    <?php echo esc_html(implode(' / ', $attempt->subject_lineage)); ?>
                                </span>
                            </div>
                            <div class="meta-right">
                                <?php $is_reported = in_array($attempt->question_id, $reported_qids_for_user); ?>
                                <button class="qp-report-button qp-report-btn-review" data-question-id="<?php echo esc_attr($attempt->question_id); ?>" <?php echo $is_reported ? 'disabled' : ''; ?>>
                                    <span>&#9888;</span> <?php echo $is_reported ? 'Reported' : 'Report'; ?>
                                </button>
                            </div>
                        </div>
                        <?php
                        $user_can_view_source = !empty(array_intersect((array)wp_get_current_user()->roles, (array)($options['show_source_meta_roles'] ?? [])));
                        if ($mode === 'Section Wise Practice' && $user_can_view_source && !empty($attempt->source_lineage)):
                            $source_parts = $attempt->source_lineage;
                            if ($attempt->question_number_in_section) $source_parts[] = 'Q ' . esc_html($attempt->question_number_in_section);
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
                                <?php
                                    if ($is_skipped) {
                                        echo esc_html($answer_display_text);
                                    } else {
                                        echo esc_html($attempt->selected_answer);
                                    }
                                ?>
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
                                            <span style="font-weight: normal; color: #888; font-size: 0.6em; margin-left: 5px;">(ID: <?php echo esc_html($option->option_id); ?>)</span>
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
                    <label for="qp-report-comment-review" style="font-size: .8em;">Comment<span style="color: red;">*</span></label>
                    <textarea id="qp-report-comment-review" name="report_comment" rows="3" placeholder="Add a comment to explain the issue..." required></textarea>
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