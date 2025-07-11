<?php
if (!defined('ABSPATH')) exit;

class QP_Dashboard {

    public static function render() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        global $wpdb;
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        
        // --- Fetch all necessary data upfront ---
        $options = get_option('qp_settings');
        $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');
        
        // Fetch Review Later questions
        $review_question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT question_id FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d",
            $user_id
        ));
        $review_questions = [];
        if (!empty($review_question_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $review_question_ids));
            $review_questions = $wpdb->get_results(
                "SELECT q.question_id, q.question_text, s.subject_name 
                 FROM {$wpdb->prefix}qp_questions q
                 LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
                 LEFT JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
                 WHERE q.question_id IN ({$ids_placeholder})"
            );
        }

        ob_start();
        ?>
        <div class="qp-container qp-dashboard-wrapper">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <h2>Welcome, <?php echo esc_html($current_user->display_name); ?>!</h2>
                <a href="<?php echo wp_logout_url(wp_login_url()); ?>" style="font-size: 14px;">(Logout)</a>
            </div>
            
            <div class="qp-dashboard-actions">
                <a href="<?php echo esc_url($practice_page_url); ?>" class="qp-button qp-button-primary">Start New Practice</a>
            </div>

            <div class="qp-dashboard-tabs">
                <button class="qp-tab-link active" data-tab="sessions">Practice History</button>
                <button class="qp-tab-link" data-tab="review">Review List (<?php echo count($review_questions); ?>)</button>
            </div>

            <div id="sessions" class="qp-tab-content active">
                <?php self::render_sessions_tab_content(); // We'll move the session history logic here ?>
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
                                    <small><?php echo esc_html($q->subject_name); ?></small>
                                </div>
                                <div class="qp-review-list-actions">
                                    <button class="qp-review-list-remove-btn">Remove</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; padding: 2rem;">You haven't marked any questions for review yet. You can mark them during any practice session.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // NEW: Helper function to render the session history
    public static function render_sessions_tab_content() {
        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $subjects_table = $wpdb->prefix . 'qp_subjects';

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        $review_page_id = isset($options['review_page']) ? absint($options['review_page']) : 0;
        $session_page_url = $session_page_id ? get_permalink($session_page_id) : home_url('/');
        $review_page_url = $review_page_id ? get_permalink($review_page_id) : home_url('/');

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
                            <span class="qp-card-subject">'.esc_html($subject_display).'</span>
                            <span class="qp-card-date">Started: '.date_format(date_create($session->start_time), 'M j, Y, g:i a').'</span>
                        </div>
                        <a href="'.esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)).'" class="qp-button qp-button-secondary">Continue</a>
                      </div>';
            }
            echo '</div>';
        }
        
        // Session History
        echo '<h3 style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e0e0e0;">Practice History</h3>';
        echo '<table class="qp-dashboard-table">
                <thead><tr><th>Date</th><th>Subject</th><th>Status</th><th>Score</th><th>Actions</th></tr></thead>
                <tbody>';
        if (!empty($session_history)) {
            foreach ($session_history as $session) {
                $settings = json_decode($session->settings_snapshot, true);
                $subject_id = $settings['subject_id'] ?? 'all';
                $subject_display = ($subject_id === 'all') ? 'All Subjects' : ($subjects_map[$subject_id] ?? 'N/A');
                echo '<tr>
                        <td data-label="Date">'.date_format(date_create($session->start_time), 'M j, Y, g:i a').'</td>
                        <td data-label="Subject">'.esc_html($subject_display).'</td>
                        <td data-label="Status"><span class="qp-status-badge qp-status-'.esc_attr($session->status).'">'.esc_html(ucfirst($session->status)).'</span></td>
                        <td data-label="Score"><strong>'.number_format($session->marks_obtained, 2).'</strong></td>
                        <td data-label="Actions">
                            <a href="'.esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)).'" class="qp-button qp-button-secondary" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">Review</a>
                            <button class="qp-delete-session-btn" data-session-id="'.esc_attr($session->session_id).'">Delete</button>
                        </td>
                      </tr>';
            }
        } else {
            echo '<tr><td colspan="5" style="text-align: center;">You have no completed practice sessions yet.</td></tr>';
        }
        echo '</tbody></table>';
    }
}