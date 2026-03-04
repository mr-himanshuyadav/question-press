<?php

namespace QuestionPress\Rest_Api; // PSR-4 Namespace

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_Error;
use WP_REST_Response;
use QuestionPress\Database\Questions_DB; // Use our DB class
use QuestionPress\Database\Terms_DB;   // Use our DB class
use QuestionPress\Utils\Session_Manager;
use QuestionPress\Utils\Practice_Manager;

/**
 * Handles REST API requests for creating and managing practice sessions.
 */
class SessionController
{

    // In qp-upload/includes/Rest_Api/SessionController.php

    public static function get_session_data(\WP_REST_Request $request)
    {
        $session_id = $request->get_param('id');
        if (! $session_id) {
            return new \WP_Error('invalid_session_id', 'Invalid session ID.', ['status' => 400]);
        }

        $user_id = get_current_user_id();
        if (! $user_id) {
            return new \WP_Error('rest_not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $session_data = Practice_Manager::get_active_session_data($session_id, $user_id);

        if (!$session_data) {
            return new \WP_Error('session_not_found', 'Session data could not be loaded.', ['status' => 404]);
        }

        // --- CLEANED UP ---
        // The $session_data *is* the manifest. 
        // Just return it directly. The app will handle fetching the question.

        return new \WP_REST_Response(['success' => true, 'data' => $session_data], 200);
    }

    /**
     * Records a user's attempt for a single question.
     */
    public static function record_attempt(\WP_REST_Request $request)
    {
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
     * Starts a new mock test session via the REST API.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function start_mock_test_session(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $result = Practice_Manager::start_mock_test_session($params);

        if (is_wp_error($result)) {
            return $result;
        }

        if (is_array($result) && isset($result['redirect_url'])) {

            // 3. Parse the URL to extract the session_id
            $url = $result['redirect_url'];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            if (isset($query_params['session_id'])) {
                // 4. Add the session_id to the result
                // The $result array now contains *both* keys.
                $result['session_id'] = absint($query_params['session_id']);
            }
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * Starts a new revision session via the REST API.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function start_revision_session(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $result = Practice_Manager::start_revision_session($params);


        if (is_wp_error($result)) {
            error_log('PRACTICE MANAGER ERROR: ' . $result->get_error_message());
            return $result;
        }

        if (is_array($result) && isset($result['redirect_url'])) {

            // 3. Parse the URL to extract the session_id
            $url = $result['redirect_url'];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            if (isset($query_params['session_id'])) {
                // 4. Add the session_id to the result
                // The $result array now contains *both* keys.
                $result['session_id'] = absint($query_params['session_id']);
            }
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * Starts a new practice session via the REST API.
     * MODIFIED to also return 'session_id' for the app.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function start_practice_session(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        // 1. Get the result from the Practice Manager (which contains the redirect_url)
        $result = Practice_Manager::start_practice_session($params);

        if (is_wp_error($result)) {
            return $result;
        }

        // --- THIS IS THE FIX ---
        // 2. Check if we got the redirect URL
        if (is_array($result) && isset($result['redirect_url'])) {

            // 3. Parse the URL to extract the session_id
            $url = $result['redirect_url'];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            if (isset($query_params['session_id'])) {
                // 4. Add the session_id to the result
                // The $result array now contains *both* keys.
                $result['session_id'] = absint($query_params['session_id']);
            }
        }
        // --- END FIX ---

        // 5. Return the enhanced result
        // Web will find: response.data.data.redirect_url
        // App will find: response.data.data.session_id
        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * Starts a new incorrect practice session via the REST API.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function start_incorrect_practice_session(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $result = Practice_Manager::start_incorrect_practice_session($params);

        if (is_wp_error($result)) {
            return $result;
        }

        if (is_array($result) && isset($result['redirect_url'])) {

            // 3. Parse the URL to extract the session_id
            $url = $result['redirect_url'];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            if (isset($query_params['session_id'])) {
                // 4. Add the session_id to the result
                // The $result array now contains *both* keys.
                $result['session_id'] = absint($query_params['session_id']);
            }
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * Starts a new review session via the REST API.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function start_review_session(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $result = Practice_Manager::start_review_session($params);

        if (is_wp_error($result)) {
            return $result;
        }

        // 2. Check if we got the redirect URL
        if (is_array($result) && isset($result['redirect_url'])) {

            // 3. Parse the URL to extract the session_id
            $url = $result['redirect_url'];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            if (isset($query_params['session_id'])) {
                // 4. Add the session_id to the result
                // The $result array now contains *both* keys.
                $result['session_id'] = absint($query_params['session_id']);
            }
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * Starts a new course test series session via the REST API.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function start_course_test_series(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $result = Practice_Manager::start_course_test_series($params);

        if (is_wp_error($result)) {
            return $result;
        }

        if (is_array($result) && isset($result['redirect_url'])) {

            // 3. Parse the URL to extract the session_id
            $url = $result['redirect_url'];
            $query_str = parse_url($url, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            if (isset($query_params['session_id'])) {
                // 4. Add the session_id to the result
                // The $result array now contains *both* keys.
                $result['session_id'] = absint($query_params['session_id']);
            }
        }

        return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * Ends a session via the REST API.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function end_session(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        if (!$session_id) {
            return new \WP_Error('invalid_session_id', 'Invalid session ID.', ['status' => 400]);
        }

        $is_auto_submit = isset($params['is_auto_submit']) && $params['is_auto_submit'] === true;
        $end_reason = $is_auto_submit ? 'autosubmitted_timer' : 'user_submitted';

        $summary_data = Session_Manager::finalize_and_end_session($session_id, 'completed', $end_reason);

        if (is_null($summary_data)) {
            return new \WP_REST_Response(['status' => 'no_attempts', 'message' => 'Session deleted as no questions were attempted.'], 200);
        } else {
            return new \WP_REST_Response($summary_data, 200);
        }
    }

    /**
     * Deletes a session via the REST API.
     */
    public static function delete_session($request) {
        $session_id = absint($request->get_param('id'));
        $user_id = get_current_user_id();

        if (!$session_id) {
            return new WP_Error('rest_invalid_id', 'Invalid session ID.', ['status' => 400]);
        }

        $success = Session_Manager::delete_session($session_id, $user_id);

        if (!$success) {
            return new WP_Error('rest_forbidden', 'You do not have permission to delete this session.', ['status' => 403]);
        }

        return new WP_REST_Response(['success' => true, 'message' => 'Session deleted.'], 200);
    }
} // End class SessionController