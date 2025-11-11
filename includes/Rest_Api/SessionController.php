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
use QuestionPress\Utils\Session_Manager;

/**
 * Handles REST API requests for creating and managing practice sessions.
 */
class SessionController {

    /**
     * Creates a new practice session record.
     * v3: Correctly builds the settings_snapshot based on web app logic.
     */
    public static function create_session( \WP_REST_Request $request ) {
        
        // 1. Get user_id
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error('rest_not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        // 2. Get test_id (which is the item_id) from the app's POST request
        $item_id = $request->get_param('test_id');
        if ( ! $item_id ) {
            return new WP_Error('rest_invalid_param', 'Test ID (item_id) is required.', ['status' => 400]);
        }

        global $wpdb;

        // 3. Get the item (test) from the database
        $items_table = $wpdb->prefix . 'qp_course_items';
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT course_id, content_type, content_config FROM {$items_table} WHERE item_id = %d",
            $item_id
        ) );

        if ( ! $item ) {
            return new WP_Error('rest_not_found', 'Test item not found.', ['status' => 404]);
        }
        if ( $item->content_type !== 'test_series' ) {
            return new WP_Error('rest_invalid_item', 'This is not a test item.', ['status' => 400]);
        }

        // 4. Decode the config to get settings and question list
        $config = json_decode( $item->content_config, true );
        $final_question_ids = [];
        
        if ( $config && isset( $config['selected_questions'] ) && is_array( $config['selected_questions'] ) ) {
            $final_question_ids = $config['selected_questions'];
        }

        if ( empty( $final_question_ids ) ) {
            return new WP_Error('rest_no_questions', 'Test is not configured correctly. No questions found.', ['status' => 500]);
        }

        // 5. --- THIS IS THE FIX ---
        // Build the settings_snapshot to match the web app's format
        $time_limit_minutes = $config['time_limit'] ?? 0;
        $timer_seconds = $time_limit_minutes > 0 ? (int)$time_limit_minutes * 60 : 0;

        $session_settings = [
            'practice_mode' => 'mock_test',
            'course_id' => (string)$item->course_id, // Match example format
            'item_id' => (int)$item_id,
            'num_questions' => count($final_question_ids),
            'marks_correct' => $config['marks_correct'] ?? 1,
            'marks_incorrect' => $config['marks_incorrect'] ?? 0,
            'timer_enabled' => $timer_seconds > 0,
            'timer_seconds' => $timer_seconds,
            'original_selection' => $final_question_ids
        ];

        // 6. Create the session in the database
        $current_time = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => $user_id,
            'status'                  => 'active',
            'start_time'              => $current_time,
            'last_activity'           => $current_time,
            'settings_snapshot'       => wp_json_encode($session_settings), // Use the new correct snapshot
            'question_ids_snapshot'   => wp_json_encode(array_values($final_question_ids))
        ]);
        $session_id = $wpdb->insert_id;

        if ( ! $session_id ) {
             return new WP_Error('db_error', 'Could not create the session record.', ['status' => 500]);
        }

        // 7. Update progress
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';
        $wpdb->query($wpdb->prepare(
            "REPLACE INTO {$progress_table} (user_id, item_id, course_id, status, last_viewed)
             VALUES (%d, %d, %d, %s, %s)",
            $user_id,
            $item_id,
            $item->course_id,
            'in_progress',
            $current_time
        ));

        // 8. Return both session_id AND the question list
        $response_data = [
            'session_id' => $session_id,
            'selected_questions' => $final_question_ids
        ];

        return new WP_REST_Response( $response_data, 200 );
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
        $summary_data = Session_Manager::finalize_and_end_session($session_id, 'completed', 'api_submitted');

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