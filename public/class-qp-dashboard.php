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
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $subjects_table = $wpdb->prefix . 'qp_subjects';

        // Get page URLs from settings
        $options = get_option('qp_settings');
        $practice_page_id = isset($options['practice_page']) ? absint($options['practice_page']) : 0;
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        $practice_page_url = $practice_page_id ? get_permalink($practice_page_id) : home_url('/');
        $session_page_url = $session_page_id ? get_permalink($session_page_id) : home_url('/');

        // Fetch subjects for display
        $subjects_raw = $wpdb->get_results("SELECT subject_id, subject_name FROM $subjects_table");
        $subjects_map = [];
        foreach ($subjects_raw as $subject) {
            $subjects_map[$subject->subject_id] = $subject->subject_name;
        }

        // --- NEW: Query for ACTIVE sessions ---
        $active_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE user_id = %d AND status = 'active' ORDER BY start_time DESC",
            $user_id
        ));

        // --- NEW: Query for COMPLETED and ABANDONED sessions ---
        $session_history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC",
            $user_id
        ));

        ob_start();
        ?>
        <div class="qp-container qp-dashboard-wrapper">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <h2>Welcome, <?php echo esc_html($current_user->display_name); ?>!</h2>
                <a href="<?php echo wp_logout_url(wp_login_url()); ?>" style="font-size: 14px;">(Logout)</a>
            </div>
            
            <div class="qp-dashboard-actions">
                <a href="<?php echo esc_url($practice_page_url); ?>" class="qp-button qp-button-primary">Start New Practice</a>
                <button id="qp-delete-history-btn" class="qp-button qp-button-danger">Delete All History</button>
            </div>

            <?php if (!empty($active_sessions)) : ?>
                <h3 style="margin-top: 2rem;">Active Sessions</h3>
                <div class="qp-active-sessions-list">
                    <?php foreach ($active_sessions as $session) : 
                        $settings = json_decode($session->settings_snapshot, true);
                        $subject_id = $settings['subject_id'] ?? 'all';
                        $subject_display = ($subject_id === 'all') ? 'All Subjects' : ($subjects_map[$subject_id] ?? 'N/A');
                    ?>
                        <div class="qp-active-session-card">
                            <div class="qp-card-details">
                                <span class="qp-card-subject"><?php echo esc_html($subject_display); ?></span>
                                <span class="qp-card-date">Started: <?php echo date_format(date_create($session->start_time), 'M j, Y, g:i a'); ?></span>
                            </div>
                            <a href="<?php echo esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)); ?>" class="qp-button qp-button-secondary">Continue</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e0e0e0;">Practice History</h3>
            <table class="qp-dashboard-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($session_history)) : ?>
                        <?php foreach ($session_history as $session) : 
                            $settings = json_decode($session->settings_snapshot, true);
                            $subject_id = $settings['subject_id'] ?? 'all';
                            $subject_display = ($subject_id === 'all') ? 'All Subjects' : ($subjects_map[$subject_id] ?? 'N/A');
                        ?>
                            <tr>
                                <td data-label="Date"><?php echo date_format(date_create($session->start_time), 'M j, Y, g:i a'); ?></td>
                                <td data-label="Subject"><?php echo esc_html($subject_display); ?></td>
                                <td data-label="Status"><span class="qp-status-badge qp-status-<?php echo esc_attr($session->status); ?>"><?php echo esc_html(ucfirst($session->status)); ?></span></td>
                                <td data-label="Score"><strong><?php echo number_format($session->marks_obtained, 2); ?></strong></td>
                                <td data-label="Actions">
                                    <button class="qp-delete-session-btn" data-session-id="<?php echo esc_attr($session->session_id); ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">You have no completed practice sessions yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}