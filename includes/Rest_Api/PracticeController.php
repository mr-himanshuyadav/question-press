<?php

namespace QuestionPress\Rest_Api;

if (! defined('ABSPATH')) {
	exit;
}

use Error;
use QuestionPress\Modules\Practice\Practice_Manager;
use QuestionPress\Database\Questions_DB;
use QuestionPress\Utils\Vault_Manager;

/**
 * REST API endpoints for in-practice actions.
 */
class PracticeController
{

	/**
	 * Checks an answer for a non-mock test session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check_answer(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		// --- THIS IS THE FIX (Part 1) ---
		// Map the app's 'user_answer' to the backend's expected 'option_id'
		if (isset($params['user_answer']) && ! isset($params['option_id'])) {
			$params['option_id'] = $params['user_answer'];
			unset($params['user_answer']);
		}
		// --- END FIX (Part 1) ---

		$result = Practice_Manager::check_answer($params);

		if (is_wp_error($result)) {
			return $result;
		}

		// --- THIS IS THE FIX (Part 2) ---
		// Wrap the successful response
		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Saves a user's selected answer during a mock test.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_mock_attempt(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		$result = Practice_Manager::save_mock_attempt($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Updates the status of a mock test question.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_mock_status(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		$result = Practice_Manager::update_mock_status($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Marks a question as 'expired' for a session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function expire_question(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		$result = Practice_Manager::expire_question($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Skips a question in a session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function skip_question(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		$result = Practice_Manager::skip_question($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Adds or removes a question from the user's review list.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function toggle_review_later(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		$result = Practice_Manager::toggle_review_later($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Submits a new question report.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit_question_report(\WP_REST_Request $request)
	{
		$params = $request->get_json_params();
		if (empty($params)) {
			$params = $request->get_body_params();
		}

		$result = Practice_Manager::submit_question_report($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves data for a single question for review purposes.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_single_question_for_review(\WP_REST_Request $request)
	{
		$params = $request->get_query_params(); // Use query params for GET

		$result = Practice_Manager::get_single_question_for_review($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves all active report reasons.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_report_reasons(\WP_REST_Request $request)
	{
		$result = Practice_Manager::get_report_reasons();

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves the number of unattempted questions for the current user, grouped by subject and topic.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_unattempted_counts(\WP_REST_Request $request)
	{
		$result = Practice_Manager::get_unattempted_counts();

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves the full data for a single question for the practice UI.
	 * (This was fixed in the previous step, but is included here)
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_question_data(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_question_data($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves topics for a given subject that have questions.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_topics_for_subject(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_topics_for_subject($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves sections containing questions for a given topic.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_sections_for_subject(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_sections_for_subject($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves sources linked to a specific subject.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_sources_for_subject(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_sources_for_subject($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves child terms for a given parent term.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_child_terms(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_child_terms($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Calculates and returns the hierarchical progress data for a user.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_progress_data(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_progress_data($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves sources linked to a specific subject for cascading dropdowns.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_sources_for_subject_cascading(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_sources_for_subject_cascading($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves child terms for a given parent term for cascading dropdowns.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_child_terms_cascading(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_child_terms_cascading($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves sources linked to a specific subject for the progress tab.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_sources_for_subject_progress(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_sources_for_subject_progress($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Checks remaining attempts/access for the current user.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check_remaining_attempts(\WP_REST_Request $request)
	{
		$result = Practice_Manager::check_remaining_attempts();

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Retrieves buffered question data for a session.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_buffered_question_data(\WP_REST_Request $request)
	{
		$params = $request->get_query_params();

		$result = Practice_Manager::get_buffered_question_data($params);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * Updates the current question index for a session.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_index(\WP_REST_Request $request)
	{
		$user_id = get_current_user_id();
		if (! $user_id) {
			return new \WP_Error('rest_not_logged_in', 'You are not logged in.', ['status' => 401]);
		}

		$session_id = (int) $request->get_param('session_id');
		$new_index  = (int) $request->get_param('new_index');

		if (empty($session_id)) {
			return new \WP_Error('rest_invalid_param', 'Session ID is required.', ['status' => 400]);
		}

		// --- THE PERMISSION CHECK IS NOW REMOVED FROM HERE ---

		// Call the Session Manager to do the update
		// The security check is now handled INSIDE this function.
		$success = Practice_Manager::update_current_question_index($session_id, $new_index);

		if ($success) {
			return new \WP_REST_Response(['success' => true, 'message' => 'Index updated.'], 200);
		} else {
			// This now handles both "not found" and "permission denied"
			return new \WP_Error('rest_update_failed', 'Failed to update session index. Check permissions or session ID.', ['status' => 403]);
		}
	}


	/**
	 * REST API callback to retrieve today's Current Affairs status.
	 * * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function get_daily_status(\WP_REST_Request $request)
	{
		$user_id = get_current_user_id();
		$today   = date('Y-m-d');

		$result = Questions_DB::get_daily_current_affairs_status($user_id, $today);

		return new \WP_REST_Response(['success' => true, 'data' => $result], 200);
	}

	/**
	 * REST API callback to start a session from a list of IDs.
	 * * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start_from_ids(\WP_REST_Request $request)
	{
		$question_ids = $request->get_param('question_ids');
		$mode         = $request->get_param('mode') ?: "Custom Practice";
		$session_name = $request->get_param('session_name') ?: $mode;
		$session_type = $request->get_param('session_type') ?: "mock_test";

		if (empty($question_ids) || !is_array($question_ids)) {
			return new \WP_Error('rest_invalid_param', 'question_ids must be a non-empty array.', ['status' => 400]);
		}

		$session_id = Practice_Manager::start_session_from_ids($question_ids, $mode, $session_type, $session_name);

		if (is_wp_error($session_id)) {
			return $session_id;
		}

		return new \WP_REST_Response([
			'success'    => true,
			'session_id' => (int) $session_id,
			'message'    => 'Session started successfully.'
		], 200);
	}

	/**
	 * Handles confidence rating submissions via REST.
	 * POST /questionpress/v1/practice/confidence
	 */
	public static function submit_confidence_rating($request)
	{
		$user_id     = get_current_user_id();
		$question_id = $request->get_param('question_id');
		$rating      = $request->get_param('rating');

		if (!$question_id || !$rating) {
			return new \WP_REST_Response(['message' => 'Missing question_id or rating'], 400);
		}

		$next_review = Vault_Manager::update_mastery_rating($user_id, (int)$question_id, $rating);

		if (!$next_review) {
			return new \WP_REST_Response(['message' => 'Failed to update SRS data'], 500);
		}

		$days = round((strtotime($next_review) - time()) / 86400);

		return new \WP_REST_Response([
			'success'          => true,
			'next_review_date' => $next_review,
			'message'          => sprintf('See you again in %d days', $days)
		], 200);
	}

	/**
	 * REST API callback to get daily guidance information for all revision configurations.
	 * GET /questionpress/v1/practice/guidance
	 */
	public static function get_daily_guidance(\WP_REST_Request $request)
	{
		$user_id = get_current_user_id();
		$vault = Vault_Manager::get_vault($user_id);

		// 1. If no configs exist (or old structure), trigger the walkthrough overlay
		if (!empty($vault->needs_config_walkthrough)) {
			return new \WP_REST_Response([
				'success'           => true,
				'needs_walkthrough' => true,
				'walkthrough_type'  => $vault->walkthrough_type ?? 'new',
				'data'              => []
			], 200);
		}

		// 2. Fetch all configs and prepare the array
		$configs = $vault->revision_config['sessions'] ?? [];
		$response_data = [];

		foreach ($configs as $config) {
			$config_id = $config['id'];
			
			$task = Vault_Manager::get_today_priority_task($user_id, $config_id);
			$is_completed = Practice_Manager::has_completed_revision_today($user_id, $config_id);

			$mode_key_map = [
				'Daily Review'   => 'daily_count',
				'Weekly Review'  => 'weekly_count',
				'Monthly Review' => 'monthly_count',
			];
			$config_key = $mode_key_map[$task] ?? 'daily_count';
			$due_count  = (int) ($config[$config_key] ?? 20);

			$response_data[] = [
				'config_data'        => $config,
				'priority_task'      => $task,
				'due_count'          => $due_count,
				'is_completed_today' => $is_completed
			];
		}

		return new \WP_REST_Response([
			'success'           => true,
			'needs_walkthrough' => false,
            'walkthrough_type'  => $vault->walkthrough_type ?? 'new',
			'data'              => $response_data
		], 200);
	}

	/**
	 * REST API callback to start a specific revision session.
	 * POST /questionpress/v1/practice/guidance/start
	 */
	public static function start_priority_session(\WP_REST_Request $request)
	{
		$user_id = get_current_user_id();
		$params  = $request->get_json_params() ?: $request->get_body_params();
		$revision_config_id = $params['revision_config_id'] ?? null;

		if (empty($revision_config_id)) {
			return new \WP_Error('missing_param', 'revision_config_id is required to start a custom revision session.', ['status' => 400]);
		}

		$task = Vault_Manager::get_today_priority_task($user_id, $revision_config_id);
		$ids  = Practice_Manager::get_smart_revision_ids($user_id, $task, $revision_config_id);

		if (is_wp_error($ids)) {
			return $ids;
		}

		// Use the updated start_session_from_ids which accepts 5 parameters
		$session_id = Practice_Manager::start_session_from_ids($ids, $task, 'mock_test', $task, $revision_config_id);

		if (is_wp_error($session_id)) {
			return $session_id;
		}

		return new \WP_REST_Response([
			'success'    => true,
			'data'       => [
				'session_id' => (int) $session_id,
				'task'       => $task
			]
		], 200);
	}

	/**
     * Updates the user's Smart Revision (Vault) settings.
     */
    public static function update_vault_settings( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params() ?: $request->get_body_params();

        if ( empty( $params ) ) {
            return new \WP_Error( 'rest_invalid_param', 'No settings provided.', [ 'status' => 400 ] );
        }

        // Delegate to the new Vault_Manager function that handles limits and 15-day locks
        $result = Vault_Manager::add_or_update_revision_session( $user_id, $params );

        if ( is_wp_error($result) ) {
			// This safely returns the errors like "limit_reached" or "config_locked" back to the React app
            return $result; 
        }

        return new \WP_REST_Response( [ 
            'success' => true, 
            'message' => 'Configuration saved successfully.',
			'configs' => $result // Return the updated sessions array so the app can refresh its state
        ], 200 );
    }

	/**
     * Deletes a specific user configuration
     */
    public static function delete_vault_setting( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params() ?: $request->get_body_params();
		$config_id = $params['id'] ?? null;

        if ( empty( $config_id ) ) {
            return new \WP_Error( 'rest_invalid_param', 'Config ID is required.', [ 'status' => 400 ] );
        }

        $success = Vault_Manager::delete_revision_session( $user_id, $config_id );

        if ( ! $success ) {
            return new \WP_Error( 'delete_failed', 'Could not delete configuration. It may not exist.', [ 'status' => 500 ] );
        }

        return new \WP_REST_Response( [ 
            'success' => true, 
            'message' => 'Configuration deleted successfully.' 
        ], 200 );
    }
}
