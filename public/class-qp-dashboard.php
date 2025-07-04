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
                        <th>Score</th>
                        <th>Attempted</th>
                        <th>Correct</th>
                        <th>Incorrect</th>
                        <th>Skipped</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sessions)) : ?>
                        <?php foreach ($sessions as $session) : ?>
                            <tr>
                                <td><?php echo date_format(date_create($session->start_time), 'M j, Y, g:i a'); ?></td>
                                <td><strong><?php echo number_format($session->marks_obtained, 2); ?></strong></td>
                                <td><?php echo esc_html($session->total_attempted); ?></td>
                                <td><?php echo esc_html($session->correct_count); ?></td>
                                <td><?php echo esc_html($session->incorrect_count); ?></td>
                                <td><?php echo esc_html($session->skipped_count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">You have not completed any practice sessions yet.</td>
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