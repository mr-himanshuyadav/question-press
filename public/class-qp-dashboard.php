<?php
if (!defined('ABSPATH')) exit;

class QP_Dashboard {

    public static function render() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        $subjects_raw = $wpdb->get_results("SELECT subject_id, subject_name FROM {$wpdb->prefix}qp_subjects");
        $subjects_map = [];
        foreach ($subjects_raw as $subject) {
            $subjects_map[$subject->subject_id] = $subject->subject_name;
        }

        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE user_id = %d AND end_time IS NOT NULL ORDER BY start_time DESC",
            $user_id
        ));

        ob_start();
        ?>
        <div class="qp-dashboard-wrapper">
            <h2>My Practice History</h2>
            <table class="qp-dashboard-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Subject</th>
                        <th>Timer</th>
                        <th>Score</th>
                        <th>Correct</th>
                        <th>Incorrect</th>
                        <th>Skipped</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sessions)) : ?>
                        <?php foreach ($sessions as $session) : 
                            $settings = json_decode($session->settings_snapshot, true);
                            $subject_id = isset($settings['qp_subject']) ? $settings['qp_subject'] : 'all';
                            $subject_display = ($subject_id === 'all') ? 'All Subjects' : (isset($subjects_map[$subject_id]) ? $subjects_map[$subject_id] : 'N/A');
                            
                            $timer_enabled = isset($settings['qp_timer_enabled']);
                            $timer_seconds = isset($settings['qp_timer_seconds']) ? $settings['qp_timer_seconds'] : '-';
                            $timer_display = $timer_enabled ? esc_html($timer_seconds) . 's' : 'Off';
                        ?>
                            <tr>
                                <td><?php echo date_format(date_create($session->start_time), 'M j, Y, g:i a'); ?></td>
                                <td><?php echo esc_html($subject_display); ?></td>
                                <td><?php echo esc_html($timer_display); ?></td>
                                <td><strong><?php echo number_format($session->marks_obtained, 2); ?></strong></td>
                                <td><?php echo esc_html($session->correct_count); ?></td>
                                <td><?php echo esc_html($session->incorrect_count); ?></td>
                                <td><?php echo esc_html($session->skipped_count); ?></td>
                                <td>
                                    <button class="qp-delete-session-btn" data-session-id="<?php echo esc_attr($session->session_id); ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">You have not completed any practice sessions yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="qp-dashboard-actions">
                 <a href="/practice-zone/" class="button-primary">Start a New Practice</a> </div>
        </div>
        <?php
        return ob_get_clean();
    }
}