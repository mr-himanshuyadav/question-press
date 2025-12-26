<?php
namespace QuestionPress\Ajax; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use QuestionPress\Utils\Session_Manager;
use QuestionPress\Utils\Practice_Manager;

/**
 * Handles AJAX requests related to practice sessions.
 */
class Session_Ajax {

    /**
     * AJAX handler to start a standard or section-wise practice session.
     */
    public static function start_practice_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = Practice_Manager::start_practice_session($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to start a special session with incorrectly answered questions.
     */
    public static function start_incorrect_practice_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = Practice_Manager::start_incorrect_practice_session($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to start a MOCK TEST session.
     */
    public static function start_mock_test_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = Practice_Manager::start_mock_test_session($_POST);

        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            // Check if the error message is intended to be HTML
            if (isset($error_data['is_html']) && $error_data['is_html']) {
                wp_send_json_error(['html' => $result->get_error_message()]);
            } else {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to start a REVISION practice session.
     */
    public static function start_revision_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = Practice_Manager::start_revision_session($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to start a special session with only the questions marked for review.
     */
    public static function start_review_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = Practice_Manager::start_review_session($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to update the last_activity timestamp for a session.
     */
    public static function update_session_activity() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if ($session_id > 0) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'qp_user_sessions',
                ['last_activity' => current_time('mysql')],
                ['session_id' => $session_id]
            );
        }
        wp_send_json_success();
    }

    /**
     * AJAX handler for ending a practice session.
     */
    public static function end_practice_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session.']);
        }

        // Determine the reason based on whether it was a timer-based auto-submission
        $is_auto_submit = isset($_POST['is_auto_submit']) && $_POST['is_auto_submit'] === 'true';
        $end_reason = $is_auto_submit ? 'autosubmitted_timer' : 'user_submitted';

        // Call the shared helper function (assuming qp_finalize_and_end_session is globally available for now)
        $summary_data = Session_Manager::finalize_and_end_session($session_id, 'completed', $end_reason);

        if (is_null($summary_data)) {
            wp_send_json_success(['status' => 'no_attempts', 'message' => 'Session deleted as no questions were attempted.']);
        } else {
            wp_send_json_success($summary_data);
        }
    }

    /**
     * AJAX handler to delete an empty/unterminated session record.
     */
    public static function delete_empty_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // Delete the session record from the database
        $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

        wp_send_json_success(['message' => 'Empty session deleted.']);
    }

    /**
     * AJAX Handler to delete a session from user history
     */
    public static function delete_user_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $options = get_option('qp_settings');
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        if (empty(array_intersect($user_roles, $allowed_roles))) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        }
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));

        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'You do not have permission to delete this session.']);
        }

        // Delete the session and its related attempts
        $wpdb->delete($attempts_table, ['session_id' => $session_id], ['%d']);
        $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

        wp_send_json_success(['message' => 'Session deleted.']);
    }

    /**
     * AJAX handler for deleting a user's entire revision and session history.
     */
    public static function delete_revision_history() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        // **THE FIX**: Add server-side permission check
        $options = get_option('qp_settings');
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        if (empty(array_intersect($user_roles, $allowed_roles))) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        }
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'You must be logged in to do this.']);
        }

        global $wpdb;
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        $wpdb->delete($attempts_table, ['user_id' => $user_id], ['%d']);
        $wpdb->delete($sessions_table, ['user_id' => $user_id], ['%d']);

        wp_send_json_success(['message' => 'Your practice and revision history has been successfully deleted.']);
    }

    /**
     * AJAX handler to pause a session.
     */
    public static function pause_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $user_id = get_current_user_id();

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $pauses_table = $wpdb->prefix . 'qp_session_pauses';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // Update the session status to 'paused' and update the activity time
        $wpdb->update(
            $sessions_table,
            [
                'status' => 'paused',
                'last_activity' => current_time('mysql')
            ],
            ['session_id' => $session_id]
        );

        // Log this pause event in the new table
        $wpdb->insert(
            $pauses_table,
            [
                'session_id' => $session_id,
                'pause_time' => current_time('mysql') // Use GMT time for consistency
            ]
        );

        wp_send_json_success(['message' => 'Session paused successfully.']);
    }

    /**
     * AJAX handler to terminate an active session.
     */
    public static function terminate_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $user_id = get_current_user_id();

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // --- THE FIX: Delete the session and its attempts directly ---
        $wpdb->delete($attempts_table, ['session_id' => $session_id]);
        $wpdb->delete($sessions_table, ['session_id' => $session_id]);

        wp_send_json_success(['message' => 'Session terminated and removed successfully.']);
    }

    /**
     * AJAX handler to start a Test Series session launched from a course item.
     * Includes access check using entitlements table. Decrement happens on first answer.
     */
    public static function start_course_test_series() {
        check_ajax_referer('qp_start_course_test_nonce', 'nonce');

        $result = Practice_Manager::start_course_test_series($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }


} // End class Session_Ajax