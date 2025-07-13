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

            <div class="qp-stats-section">
                <h3 class="qp-section-header">Lifetime Stats</h3>
                <div class="qp-overall-stats">
                    <div class="stat-item">
                        <span class="stat-label">Accuracy</span>
                        <span class="stat-value"><?php echo round($overall_accuracy, 2); ?>%</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Attempted</span>
                        <span class="stat-value"><?php echo (int)$total_attempted; ?></span>
                        
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Correct</span>
                        <span class="stat-value"><?php echo (int)$total_correct; ?></span>
                        
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Incorrect</span>
                        <span class="stat-value"><?php echo (int)$total_incorrect; ?></span>
                        
                    </div>
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

    $options = get_option('qp_settings');
    $review_page_id = isset($options['review_page']) ? absint($options['review_page']) : 0;
    $review_page_url = $review_page_id ? get_permalink($review_page_id) : home_url('/');

    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
    $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

    $session_history = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC", $user_id));

    // Pre-fetch all subjects for all questions in the user's history to optimize queries
    $all_session_qids = [];
    foreach ($session_history as $session) {
        $qids = json_decode($session->question_ids_snapshot, true);
        if (is_array($qids)) {
            $all_session_qids = array_merge($all_session_qids, $qids);
        }
    }

    $subjects_by_question = [];
    if (!empty($all_session_qids)) {
        $unique_qids = array_unique(array_map('absint', $all_session_qids));
        $ids_placeholder = implode(',', $unique_qids);
        $subject_results = $wpdb->get_results(
            "SELECT q.question_id, s.subject_name
             FROM {$wpdb->prefix}qp_questions q
             JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
             JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
             WHERE q.question_id IN ($ids_placeholder)"
        );
        foreach ($subject_results as $res) {
            $subjects_by_question[$res->question_id] = $res->subject_name;
        }
    }

    $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');

    echo '<div class="qp-history-header">
        <h3 style="margin:0;">Practice History</h3>
        <div class="qp-history-actions">
            <a href="' . esc_url($practice_page_url) . '" class="qp-button qp-button-primary">Practice</a>';

    if ($can_delete) {
        echo '<button id="qp-delete-history-btn" class="qp-button qp-button-danger">Clear History</button>';
    }
    echo '</div></div>';

    echo '<table class="qp-dashboard-table">
        <thead><tr><th>Date</th><th>Mode</th><th>Subjects</th><th>Accuracy</th><th>Actions</th></tr></thead>
        <tbody>';

    if (!empty($session_history)) {
        foreach ($session_history as $session) {
            $settings = json_decode($session->settings_snapshot, true);
            $session_qids = json_decode($session->question_ids_snapshot, true);

            // Determine Mode
            $mode = 'Practice';
            if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'revision') {
                $mode = 'Revision';
            } elseif (isset($settings['section_id']) && $settings['section_id'] !== 'all' && is_numeric($settings['section_id'])) {
                $mode = 'Source Practice';
            }

            // Determine Subjects
            $session_subjects = [];
            if (is_array($session_qids)) {
                foreach ($session_qids as $qid) {
                    if (isset($subjects_by_question[$qid])) {
                        $session_subjects[$subjects_by_question[$qid]] = true;
                    }
                }
            }
            $subjects_display = !empty($session_subjects) ? implode(', ', array_keys($session_subjects)) : 'N/A';

            // Calculate Accuracy
            $accuracy = ($session->total_attempted > 0) ? round(($session->correct_count / $session->total_attempted) * 100, 2) . '%' : 'N/A';

            echo '<tr>
                <td data-label="Date">' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</td>
                <td data-label="Mode">' . esc_html($mode) . '</td>
                <td data-label="Subjects">' . esc_html($subjects_display) . '</td>
                <td data-label="Accuracy"><strong>' . $accuracy . '</strong></td>
                <td data-label="Actions">
                    <a href="' . esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)) . '" class="qp-button qp-button-secondary" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">Review</a>';
            if ($can_delete) {
                echo '<button class="qp-delete-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Delete</button>';
            }
            echo '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="5" style="text-align: center;">You have no completed practice sessions yet.</td></tr>';
    }
    echo '</tbody></table>';
}
}
