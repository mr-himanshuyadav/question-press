<?php
namespace QuestionPress\Rest_Api; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_REST_Request;
use WP_Error;
use WP_REST_Response;
use QuestionPress\Database\Questions_DB; // Use our DB class
use QuestionPress\Database\Terms_DB;   // Use our DB class

/**
 * Handles REST API requests for creating and managing practice sessions.
 */
class SessionController {

    /**
     * Creates a new practice session record.
     */
    public static function create_session( \WP_REST_Request $request ) {
        $settings = $request->get_param('settings'); // App sends a JSON object of settings

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}qp_user_sessions", [
            'user_id' => get_current_user_id(),
            'status' => 'active', // Set a default status
            'start_time' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'settings_snapshot' => wp_json_encode($settings)
        ]);
        $session_id = $wpdb->insert_id;

        if ( ! $session_id ) {
             return new WP_Error('db_error', 'Could not create the session.', ['status' => 500]);
        }

        return new WP_REST_Response(['session_id' => $session_id], 200);
    }

    /**
     * Records a user's attempt for a single question.
     */
    public static function record_attempt( \WP_REST_Request $request ) {
        $session_id = $request->get_param('session_id');
        $question_id = $request->get_param('question_id'); // This is the internal DB ID
        $option_id = $request->get_param('option_id'); // Can be null if skipped

        if (!$session_id || !$question_id) {
            return new WP_Error('rest_invalid_request', 'Session ID and Question ID are required.', ['status' => 400]);
        }

        global $wpdb;
        $is_correct = null;
        $correct_option_id = null;
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $options_table = $wpdb->prefix . 'qp_options';

        if ($option_id) { // If an answer was submitted
            $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM {$options_table} WHERE option_id = %d", $option_id));
            $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$options_table} WHERE question_id = %d AND is_correct = 1", $question_id));
        }

        // Use REPLACE INTO (same as AJAX) to handle re-attempts
        $wpdb->replace(
            $attempts_table,
            [
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'question_id' => $question_id,
                'selected_option_id' => $option_id ? $option_id : null,
                'is_correct' => $is_correct,
                'status' => $option_id ? 'answered' : 'skipped',
                'attempt_time' => current_time('mysql')
            ]
        );
        $attempt_id = $wpdb->insert_id;

        return new WP_REST_Response([
            'success' => true,
            'is_correct' => $is_correct,
            'correct_option_id' => (int)$correct_option_id,
            'attempt_id' => $attempt_id
        ], 200);
    }

    /**
     * Ends a session and calculates the final results.
     */
    public static function end_session( \WP_REST_Request $request ) {
        $session_id = $request->get_param('session_id');
        if (!$session_id) {
            return new WP_Error('rest_invalid_request', 'Session ID is required.', ['status' => 400]);
        }

        // Call the global helper function (which we also use for AJAX)
        // This ensures logic is consistent
        $summary_data = qp_finalize_and_end_session($session_id, 'completed', 'api_submitted');

        if (is_null($summary_data)) {
             // Session was empty and got deleted
             return new WP_REST_Response(['message' => 'Session ended. No attempts were recorded.', 'final_score' => 0], 200);
        }

        return new WP_REST_Response([
            'message' => 'Session ended successfully.', 
            'final_score' => $summary_data['final_score'],
            'correct_count' => $summary_data['correct_count'],
            'incorrect_count' => $summary_data['incorrect_count'],
            'skipped_count' => $summary_data['skipped_count'],
        ], 200);
    }

} // End class SessionController