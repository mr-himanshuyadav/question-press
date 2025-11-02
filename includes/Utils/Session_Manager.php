<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Database\DB;

/**
 * Handles session-related logic like finalization and state calculation.
 *
 * @package QuestionPress\Utils
 */
class Session_Manager extends DB { // <-- Extend DB to get self::$wpdb

	/**
	 * Helper function to calculate final stats and update a session record.
	 * This is the canonical method for ending any session.
	 *
	 * @param int    $session_id The ID of the session to finalize.
	 * @param string $new_status The status to set for the session (e.g., 'completed', 'abandoned').
	 * @param string|null $end_reason The reason the session ended (e.g., 'user_submitted', 'abandoned_by_system').
	 * @return array|null An array of summary data, or null if the session was empty and deleted.
	 */
	public static function finalize_and_end_session($session_id, $new_status = 'completed', $end_reason = null)
	{
		$wpdb = self::$wpdb;
		$sessions_table = $wpdb->prefix . 'qp_user_sessions';
		$attempts_table = $wpdb->prefix . 'qp_user_attempts';
		$pauses_table = $wpdb->prefix . 'qp_session_pauses';

		$session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
		if (!$session) {
			return null;
		}

		// Check for any answered attempts.
		$total_answered_attempts = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND status = 'answered'",
			$session_id
		));

		// If there are no answered attempts, delete the session immediately and stop.
		if ($total_answered_attempts === 0) {
			$wpdb->delete($sessions_table, ['session_id' => $session_id]);
			$wpdb->delete($attempts_table, ['session_id' => $session_id]); // Also clear any skipped/expired attempts
			$wpdb->delete($pauses_table, ['session_id' => $session_id]);   // Also clear any pause records
			return null; // Indicate that the session was empty and deleted
		}

		// If we are here, it means there were attempts, so we proceed to finalize.
		$settings = json_decode($session->settings_snapshot, true);
		$marks_correct = $settings['marks_correct'] ?? 0;
		$marks_incorrect = $settings['marks_incorrect'] ?? 0;
		$is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';

		if ($is_mock_test) {
			// Grade any unanswered mock test questions
			$answered_attempts = $wpdb->get_results($wpdb->prepare(
				"SELECT attempt_id, question_id, selected_option_id FROM {$attempts_table} WHERE session_id = %d AND mock_status IN ('answered', 'answered_and_marked_for_review')",
				$session_id
			));
			$options_table = $wpdb->prefix . 'qp_options';
			foreach ($answered_attempts as $attempt) {
				$is_correct = (bool) $wpdb->get_var($wpdb->prepare(
					"SELECT is_correct FROM {$options_table} WHERE option_id = %d AND question_id = %d",
					$attempt->selected_option_id,
					$attempt->question_id
				));
				$wpdb->update($attempts_table, ['is_correct' => $is_correct ? 1 : 0], ['attempt_id' => $attempt->attempt_id]);
			}
			$all_question_ids_in_session = json_decode($session->question_ids_snapshot, true);
			$interacted_question_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE session_id = %d", $session_id));
			$not_viewed_ids = array_diff($all_question_ids_in_session, $interacted_question_ids);
			foreach ($not_viewed_ids as $question_id) {
				$wpdb->insert($attempts_table, [
					'session_id' => $session_id,
					'user_id' => $session->user_id,
					'question_id' => $question_id,
					'status' => 'skipped',
					'mock_status' => 'not_viewed'
				]);
			}
			$wpdb->query($wpdb->prepare("UPDATE {$attempts_table} SET status = 'skipped' WHERE session_id = %d AND mock_status IN ('viewed', 'marked_for_review')", $session_id));
		}

		$correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 1", $session_id));
		$incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 0", $session_id));
		$total_attempted = $correct_count + $incorrect_count;
		$not_viewed_count = 0;
		if ($is_mock_test) {
			$unattempted_count = count(json_decode($session->question_ids_snapshot, true)) - $total_attempted;
			$not_viewed_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND mock_status = 'not_viewed'", $session_id));
		} else {
			$unattempted_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND status = 'skipped'", $session_id));
		}
		$skipped_count = $unattempted_count;
		$final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);
		$end_time_for_calc = ($new_status === 'abandoned' && !empty($session->last_activity) && $session->last_activity !== '0000-00-00 00:00:00') ? $session->last_activity : current_time('mysql');
		$end_time_gmt = get_gmt_from_date($end_time_for_calc);
		$start_time_gmt = get_gmt_from_date($session->start_time);
		$total_session_duration = strtotime($end_time_gmt) - strtotime($start_time_gmt);
		$total_active_seconds = max(0, $total_session_duration);
		if (!$is_mock_test) {
			$pause_records = $wpdb->get_results($wpdb->prepare("SELECT pause_time, resume_time FROM {$pauses_table} WHERE session_id = %d", $session_id));
			$total_pause_duration = 0;
			foreach ($pause_records as $pause) {
				$resume_time_gmt = $pause->resume_time ? get_gmt_from_date($pause->resume_time) : $end_time_gmt;
				$pause_time_gmt = get_gmt_from_date($pause->pause_time);
				$total_pause_duration += strtotime($resume_time_gmt) - strtotime($pause_time_gmt);
			}
			$total_active_seconds = max(0, $total_session_duration - $total_pause_duration);
		}

		$wpdb->update($sessions_table, [
			'end_time' => $end_time_for_calc,
			'status' => $new_status,
			'end_reason' => $end_reason,
			'total_active_seconds' => $total_active_seconds,
			'total_attempted' => $total_attempted,
			'correct_count' => $correct_count,
			'incorrect_count' => $incorrect_count,
			'skipped_count' => $skipped_count,
			'not_viewed_count' => $not_viewed_count,
			'marks_obtained' => $final_score
		], ['session_id' => $session_id]);

		// --- BEGIN NEW: Deduct Attempts for General Mock Tests on Finalization ---
		if (
			$is_mock_test &&
			(!isset($settings['course_id']) || $settings['course_id'] <= 0) &&
			($new_status === 'completed' || $new_status === 'abandoned') &&
			$total_attempted > 0
		) {
			$user_id = $session->user_id;
			$entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
			$attempts_to_deduct = $total_attempted;

			// Get all active, non-expired entitlements that have attempts (finite or unlimited)
			$active_entitlements = $wpdb->get_results($wpdb->prepare(
				"SELECT entitlement_id, remaining_attempts
				 FROM {$entitlements_table}
				 WHERE user_id = %d
				   AND status = 'active'
				   AND (expiry_date IS NULL OR expiry_date > %s)
				   AND (remaining_attempts IS NULL OR remaining_attempts > 0)
				 ORDER BY remaining_attempts ASC", // Process finite plans first (lowest attempts)
				$user_id,
				current_time('mysql')
			));

			$has_unlimited = false;
			$finite_entitlements = [];

			foreach ($active_entitlements as $entitlement) {
				if (is_null($entitlement->remaining_attempts)) {
					$has_unlimited = true;
					break; // User has an unlimited plan, no deduction needed.
				}
				$finite_entitlements[] = $entitlement;
			}

			// Only deduct if the user does NOT have an unlimited plan
			if (!$has_unlimited && !empty($finite_entitlements)) {
				error_log("QP Finalize: User #{$user_id} finalizing mock test. Deducting {$attempts_to_deduct} attempts.");
				
				foreach ($finite_entitlements as $entitlement) {
					if ($attempts_to_deduct <= 0) {
						break; // All attempts have been deducted
					}

					$attempts_on_this_plan = (int)$entitlement->remaining_attempts;
					
					if ($attempts_on_this_plan >= $attempts_to_deduct) {
						// This plan can cover the remaining attempts
						$new_attempts = $attempts_on_this_plan - $attempts_to_deduct;
						$attempts_deducted_from_this = $attempts_to_deduct;
						$attempts_to_deduct = 0;
					} else {
						// This plan is used up
						$new_attempts = 0;
						$attempts_deducted_from_this = $attempts_on_this_plan;
						$attempts_to_deduct = $attempts_to_deduct - $attempts_on_this_plan;
					}

					$wpdb->update(
						$entitlements_table,
						['remaining_attempts' => $new_attempts],
						['entitlement_id' => $entitlement->entitlement_id]
					);

					error_log("QP Finalize: Deducted {$attempts_deducted_from_this} attempts from Entitlement #{$entitlement->entitlement_id}. Remaining: {$new_attempts}.");
				}
			} elseif ($has_unlimited) {
				error_log("QP Finalize: User #{$user_id} has unlimited plan. No attempts deducted for mock test.");
			}
		}
		// --- END NEW: Deduct Attempts for General Mock Tests ---

		// --- NEW: Update Course Item Progress if applicable ---
		if (($new_status === 'completed' || $new_status === 'abandoned') &&
			isset($settings['course_id']) && isset($settings['item_id'])) {

			$course_id = absint($settings['course_id']);
			$item_id = absint($settings['item_id']);
			$user_id = $session->user_id;
			$progress_table = $wpdb->prefix . 'qp_user_items_progress';
			$items_table = $wpdb->prefix . 'qp_course_items';

			// Check if the course item still exists before trying to update progress
			$item_exists = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$items_table} WHERE item_id = %d AND course_id = %d",
				$item_id,
				$course_id
			));

			if ($item_exists) {
				// Prepare result data
				$result_data = json_encode([
					'score' => $final_score,
					'correct' => $correct_count,
					'incorrect' => $incorrect_count,
					'skipped' => $skipped_count,
					'not_viewed' => $not_viewed_count,
					'total_attempted' => $total_attempted,
					'session_id' => $session_id
				]);

				// Use REPLACE INTO to update or insert progress
				$wpdb->query($wpdb->prepare(
					"REPLACE INTO {$progress_table} (user_id, item_id, course_id, status, completion_date, result_data, last_viewed)
					 VALUES (%d, %d, %d, %s, %s, %s, %s)",
					$user_id,
					$item_id,
					$course_id,
					'completed',
					current_time('mysql'),
					$result_data,
					current_time('mysql')
				));

				// Calculate and Update Overall Course Progress
				$user_courses_table = $wpdb->prefix . 'qp_user_courses';

				$total_items = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(item_id) FROM $items_table WHERE course_id = %d",
					$course_id
				));

				$completed_items = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(user_item_id) FROM $progress_table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
					$user_id,
					$course_id
				));

				$progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;
				$new_course_status = ($total_items > 0 && $completed_items >= $total_items) ? 'completed' : 'in_progress';

				$current_completion_date = $wpdb->get_var($wpdb->prepare(
					"SELECT completion_date FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
					$user_id, $course_id
				));
				
				$completion_date_to_set = $current_completion_date;
				if ($new_course_status === 'completed' && is_null($current_completion_date)) {
					$completion_date_to_set = current_time('mysql');
				} elseif ($new_course_status !== 'completed') {
					 $completion_date_to_set = null;
				}

				// Update the user's overall course record
				$wpdb->update(
					$user_courses_table,
					[
						'progress_percent' => $progress_percent,
						'status'           => $new_course_status,
						'completion_date'  => $completion_date_to_set
					],
					[ 'user_id'   => $user_id, 'course_id' => $course_id ],
					['%d', '%s', '%s'],
					['%d', '%d']
				);

			} else {
				error_log("QP Session Finalize: Skipped progress update for user {$user_id}, course {$course_id}, because item {$item_id} no longer exists.");
			}
		}
		// --- END Course Item Progress Update ---

		return [
			'final_score' => $final_score,
			'total_attempted' => $total_attempted,
			'correct_count' => $correct_count,
			'incorrect_count' => $incorrect_count,
			'skipped_count' => $skipped_count,
			'not_viewed_count' => $not_viewed_count,
			'settings' => $settings,
		];
	}
}