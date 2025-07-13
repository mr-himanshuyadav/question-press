<?php
if (!defined('ABSPATH')) exit;

class QP_Dashboard
{

    public static function render()
    {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        global $wpdb;
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;

        // --- **THE FIX**: Calculate Lifetime User Stats ---
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
            SUM(total_attempted) as total_attempted,
            SUM(correct_count) as total_correct,
            SUM(incorrect_count) as total_incorrect
         FROM {$sessions_table} 
         WHERE user_id = %d AND status = 'completed'",
            $user_id
        ));

        $total_attempted = $stats->total_attempted ?? 0;
        $total_correct = $stats->total_correct ?? 0;
        $total_incorrect = $stats->total_incorrect ?? 0;
        $overall_accuracy = ($total_attempted > 0) ? ($total_correct / $total_attempted) * 100 : 0;

        // Fetch Review Later questions (existing logic)
        $review_questions = $wpdb->get_results($wpdb->prepare(
            "SELECT q.question_id, q.custom_question_id, q.question_text, s.subject_name 
         FROM {$wpdb->prefix}qp_review_later rl
         JOIN {$wpdb->prefix}qp_questions q ON rl.question_id = q.question_id
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
         LEFT JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
         WHERE rl.user_id = %d ORDER BY rl.review_id DESC",
            $user_id
        ));

        ob_start();
?>
        <div class="qp-container qp-dashboard-wrapper">
            <div class="qp-profile-header">
                <div class="qp-user-info">
                    <span class="qp-user-name">Welcome, <?php echo esc_html($current_user->display_name); ?>!</span>
                    <a href="<?php echo wp_logout_url(wp_login_url()); ?>" class="qp-logout-link">(Logout)</a>
                </div>
            </div>

            <div class="qp-overall-stats">
                <div class="stat-item">
                    <span class="stat-value"><?php echo round($overall_accuracy, 2); ?>%</span>
                    <span class="stat-label">Overall Accuracy</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo (int)$total_attempted; ?></span>
                    <span class="stat-label">Attempted</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo (int)$total_correct; ?></span>
                    <span class="stat-label">Correct</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo (int)$total_incorrect; ?></span>
                    <span class="stat-label">Incorrect</span>
                </div>
            </div>

            <div class="qp-dashboard-tabs">
                <button class="qp-tab-link active" data-tab="sessions">Practice History</button>
                <button class="qp-tab-link" data-tab="review">Review List (<?php echo count($review_questions); ?>)</button>
            </div>

            <div id="sessions" class="qp-tab-content active">
                <?php self::render_sessions_tab_content(); // This function will be modified next 
                ?>
            </div>

            <div id="review" class="qp-tab-content">
                <?php if (!empty($review_questions)) : ?>
                    <div class="qp-review-list-header">
                        <p>Questions you've marked for later review.</p>
                        <button id="qp-start-reviewing-btn" class="qp-button qp-button-primary">Start Reviewing All</button>
                    </div>
                    <ul class="qp-review-list">
                        <?php foreach ($review_questions as $q) : ?>
                            <li data-question-id="<?php echo esc_attr($q->question_id); ?>">
                                <div class="qp-review-list-q-text">
                                    <?php echo wp_trim_words(esc_html($q->question_text), 25, '...'); ?>
                                    <small>ID: <?php echo esc_html($q->custom_question_id); ?> | Subject: <?php echo esc_html($q->subject_name); ?></small>
                                </div>
                                <div class="qp-review-list-actions">
                                    <button class="qp-review-list-view-btn">View</button>
                                    <button class="qp-review-list-remove-btn">Remove</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; padding: 2rem; background-color: #f9f9f9; border-radius: 8px;">You haven't marked any questions for review yet.</p>
                <?php endif; ?>
            </div>
            <div id="qp-review-modal-backdrop" style="display: none;">
                <div id="qp-review-modal-content"></div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }


    public static function render_sessions_tab_content()
    {
        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $subjects_table = $wpdb->prefix . 'qp_subjects';

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        $review_page_id = isset($options['review_page']) ? absint($options['review_page']) : 0;
        $session_page_url = $session_page_id ? get_permalink($session_page_id) : home_url('/');
        $review_page_url = $review_page_id ? get_permalink($review_page_id) : home_url('/');

        // **THE FIX**: Perform the permission check here on the backend
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

        $subjects_raw = $wpdb->get_results("SELECT subject_id, subject_name FROM $subjects_table");
        $subjects_map = [];
        foreach ($subjects_raw as $subject) {
            $subjects_map[$subject->subject_id] = $subject->subject_name;
        }

        $active_sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status = 'active' ORDER BY start_time DESC", $user_id));
        $session_history = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC", $user_id));

        // Active Sessions
        if (!empty($active_sessions)) {
            echo '<h3 style="margin-top: 2rem;">Active Sessions</h3>';
            echo '<div class="qp-active-sessions-list">';
            foreach ($active_sessions as $session) {
                $settings = json_decode($session->settings_snapshot, true);
                $subject_id = $settings['subject_id'] ?? 'all';
                $subject_display = ($subject_id === 'all') ? 'All Subjects' : ($subjects_map[$subject_id] ?? 'N/A');
                echo '<div class="qp-active-session-card">
                    <div class="qp-card-details">
                        <span class="qp-card-subject">' . esc_html($subject_display) . '</span>
                        <span class="qp-card-date">Started: ' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</span>
                    </div>
                    <div class="qp-card-actions">
                        <button class="qp-button qp-button-danger qp-terminate-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Terminate</button>
                        <a href="' . esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)) . '" class="qp-button qp-button-secondary">Continue</a>
                    </div>
                  </div>';
            }
            echo '</div>';
        }

        // Session History
        $options = get_option('qp_settings');
        $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');

        // **THE FIX**: Moved "Start New Practice" button here
        echo '<div class="qp-dashboard-actions">
        <a href="' . esc_url($practice_page_url) . '" class="qp-button qp-button-primary">Start New Practice</a>
      </div>';
        echo '<div class="qp-history-header">';
        echo '<h3 style="margin:0;">Practice History</h3>';
        // **THE FIX**: Only show this button if the user has permission
        if ($can_delete) {
            echo '<button id="qp-delete-history-btn" class="qp-button qp-button-danger">Delete All History</button>';
        }
        echo '</div>';
        echo '<table class="qp-dashboard-table">
            <thead><tr><th>Date</th><th>Subject</th><th>Status</th><th>Score</th><th>Actions</th></tr></thead>
            <tbody>';
        if (!empty($session_history)) {
            foreach ($session_history as $session) {
                $settings = json_decode($session->settings_snapshot, true);
                $subject_id = $settings['subject_id'] ?? 'all';
                $subject_display = ($subject_id === 'all') ? 'All Subjects' : ($subjects_map[$subject_id] ?? 'N/A');
                echo '<tr>
                    <td data-label="Date">' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</td>
                    <td data-label="Subject">' . esc_html($subject_display) . '</td>
                    <td data-label="Status"><span class="qp-status-badge qp-status-' . esc_attr($session->status) . '">' . esc_html(ucfirst($session->status)) . '</span></td>
                    <td data-label="Score"><strong>' . number_format($session->marks_obtained, 2) . '</strong></td>
                    <td data-label="Actions">
                        <a href="' . esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)) . '" class="qp-button qp-button-secondary" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">Review</a>';
                // **THE FIX**: Only show this button if the user has permission
                if ($can_delete) {
                    echo '<button class="qp-delete-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Delete</button>';
                }
                echo '</td>
                  </tr>';
            }
        } else {
            echo '<tr><td colspan="5" style="text-align: center;">You have no completed practice sessions yet.</td></tr>';
        }
        echo '</tbody></table>';
    }
}
