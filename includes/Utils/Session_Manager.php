<?php

namespace QuestionPress\Utils;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

use QuestionPress\Database\DB;

/**
 * Handles session-related logic like finalization and state calculation.
 *
 * @package QuestionPress\Utils
 */
class Session_Manager extends DB
{ // <-- Extend DB to get self::$wpdb

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
		// 1. Capture Start State
		$start_time = microtime(true);
		$start_queries = get_num_queries();


		$wpdb = self::$wpdb;
		$sessions_table     = $wpdb->prefix . 'qp_user_sessions';
		$attempts_table     = $wpdb->prefix . 'qp_user_attempts';
		$pauses_table       = $wpdb->prefix . 'qp_session_pauses';
		$options_table      = $wpdb->prefix . 'qp_options';
		$entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
		$progress_table     = $wpdb->prefix . 'qp_user_items_progress';
		$items_table        = $wpdb->prefix . 'qp_course_items';
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';

		// Start Transaction
		$wpdb->query('START TRANSACTION');

		try {
			$session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
			if (!$session) {
				$wpdb->query('COMMIT');
				return null;
			}

			// Check for any answered attempts efficiently
			$has_answers = $wpdb->get_var($wpdb->prepare(
				"SELECT 1 FROM {$attempts_table} WHERE session_id = %d AND status = 'answered' LIMIT 1",
				$session_id
			));

			if (!$has_answers) {
				// Delete from all tables in one query
				$wpdb->query($wpdb->prepare(
					"DELETE s, a, p FROM $sessions_table s
                 LEFT JOIN $attempts_table a ON a.session_id = s.session_id
                 LEFT JOIN $pauses_table p ON p.session_id = s.session_id
                 WHERE s.session_id = %d",
					$session_id
				));
				$wpdb->query('COMMIT');
				return null;
			}

			$settings = json_decode($session->settings_snapshot, true);
			$marks_correct = $settings['marks_correct'] ?? 0;
			$marks_incorrect = $settings['marks_incorrect'] ?? 0;
			$is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';

			if ($is_mock_test) {
				// Grade attempts
				$wpdb->query($wpdb->prepare(
					"UPDATE {$attempts_table} a
                 LEFT JOIN {$options_table} o ON a.question_id = o.question_id AND a.selected_option_id = o.option_id AND o.is_correct = 1
                 SET a.is_correct = CASE WHEN o.option_id IS NOT NULL THEN 1 ELSE 0 END
                 WHERE a.session_id = %d AND a.mock_status IN ('answered', 'answered_and_marked_for_review')",
					$session_id
				));

				// Insert 'not_viewed' records
				$all_question_ids = json_decode($session->question_ids_snapshot, true);
				if (!is_array($all_question_ids)) $all_question_ids = [];

				$interacted_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE session_id = %d", $session_id));
				$not_viewed_ids = array_diff($all_question_ids, $interacted_ids);

				if (!empty($not_viewed_ids)) {
					$values_sql = [];
					foreach ($not_viewed_ids as $qid) {
						if (absint($qid) > 0) {
							$values_sql[] = $wpdb->prepare("(%d, %d, %d, 'skipped', 'not_viewed')", $session_id, $session->user_id, $qid);
						}
					}
					// Insert in chunks of 200 to prevent query size limits
					if (!empty($values_sql)) {
						foreach (array_chunk($values_sql, 200) as $chunk) {
							$wpdb->query("INSERT INTO {$attempts_table} (session_id, user_id, question_id, status, mock_status) VALUES " . implode(', ', $chunk));
						}
					}
				}

				// Update remaining viewed items to skipped
				$wpdb->query($wpdb->prepare(
					"UPDATE {$attempts_table} SET status = 'skipped' WHERE session_id = %d AND mock_status IN ('viewed', 'marked_for_review')",
					$session_id
				));
			}

			// Calculate Stats (Aggregated Query)
			$stats = $wpdb->get_row($wpdb->prepare(
				"SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END), 0) as correct,
                COALESCE(SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END), 0) as incorrect,
                COALESCE(SUM(CASE WHEN mock_status = 'not_viewed' THEN 1 ELSE 0 END), 0) as not_viewed,
                COALESCE(SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END), 0) as skipped
             FROM {$attempts_table} 
             WHERE session_id = %d",
				$session_id
			));

			$correct_count = (int)$stats->correct;
			$incorrect_count = (int)$stats->incorrect;
			$total_attempted = $correct_count + $incorrect_count;
			$not_viewed_count = ($is_mock_test) ? (int)$stats->not_viewed : 0;
			$skipped_count = ($is_mock_test) ? ((int)$stats->skipped - $not_viewed_count) : (int)$stats->skipped;

			$final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

			// Time Calculation
			$end_time_for_calc = ($new_status === 'abandoned' && !empty($session->last_activity) && $session->last_activity !== '0000-00-00 00:00:00') ? $session->last_activity : current_time('mysql');
			$end_time_gmt = get_gmt_from_date($end_time_for_calc);
			$start_time_gmt = get_gmt_from_date($session->start_time);
			$total_session_duration = strtotime($end_time_gmt) - strtotime($start_time_gmt);

			$total_pause_duration = 0;
			if (!$is_mock_test) {
				$total_pause_duration = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT SUM(TIMESTAMPDIFF(SECOND, pause_time, COALESCE(resume_time, %s))) FROM {$pauses_table} WHERE session_id = %d",
					$end_time_for_calc,
					$session_id
				));
			}
			$total_active_seconds = max(0, $total_session_duration - $total_pause_duration);

			// Update Session
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

			// Deduct Entitlements
			if ($is_mock_test && (!isset($settings['course_id']) || $settings['course_id'] <= 0) && ($new_status === 'completed' || $new_status === 'abandoned') && $total_attempted > 0) {
				$user_id = $session->user_id;
				$attempts_to_deduct = $total_attempted;

				$active_entitlements = $wpdb->get_results($wpdb->prepare(
					"SELECT entitlement_id, remaining_attempts FROM {$entitlements_table}
                 WHERE user_id = %d AND status = 'active' AND (expiry_date IS NULL OR expiry_date > %s) AND (remaining_attempts IS NULL OR remaining_attempts > 0)
                 ORDER BY expiry_date ASC, remaining_attempts ASC",
					$user_id,
					current_time('mysql')
				));

				$has_unlimited = false;
				$finite_entitlements = [];
				foreach ($active_entitlements as $entitlement) {
					if (is_null($entitlement->remaining_attempts)) {
						$has_unlimited = true;
						break;
					}
					$finite_entitlements[] = $entitlement;
				}

				if (!$has_unlimited && !empty($finite_entitlements)) {
					$attempt_update_cases = [];
					$status_update_cases = [];
					$ids_to_update = [];

					foreach ($finite_entitlements as $entitlement) {
						if ($attempts_to_deduct <= 0) break;

						$attempts_on_this_plan = (int)$entitlement->remaining_attempts;
						if ($attempts_on_this_plan >= $attempts_to_deduct) {
							$new_attempts = $attempts_on_this_plan - $attempts_to_deduct;
							$attempts_to_deduct = 0;
						} else {
							$new_attempts = 0;
							$attempts_to_deduct -= $attempts_on_this_plan;
						}

						$attempt_update_cases[] = $wpdb->prepare("WHEN %d THEN %d", $entitlement->entitlement_id, $new_attempts);
						if ($new_attempts === 0) {
							$status_update_cases[] = $wpdb->prepare("WHEN %d THEN 'expired'", $entitlement->entitlement_id);
						}
						$ids_to_update[] = $entitlement->entitlement_id;
					}

					if (!empty($ids_to_update)) {
						$ids_placeholder = implode(',', $ids_to_update);
						$wpdb->query("UPDATE {$entitlements_table} SET remaining_attempts = CASE entitlement_id " . implode(' ', $attempt_update_cases) . " END WHERE entitlement_id IN ({$ids_placeholder})");
						if (!empty($status_update_cases)) {
							$wpdb->query("UPDATE {$entitlements_table} SET status = CASE entitlement_id " . implode(' ', $status_update_cases) . " END WHERE entitlement_id IN ({$ids_placeholder})");
						}
					}
				}
			}

			// Update Course Progress
			if (($new_status === 'completed' || $new_status === 'abandoned') && isset($settings['course_id'], $settings['item_id'])) {
				$course_id = absint($settings['course_id']);
				$item_id = absint($settings['item_id']);
				$user_id = $session->user_id;

				// Check item exists and insert/update progress in one atomic operation
				$item_exists = $wpdb->get_var($wpdb->prepare("SELECT item_id FROM {$items_table} WHERE item_id = %d AND course_id = %d", $item_id, $course_id));

				if ($item_exists) {
					$result_data = json_encode([
						'score' => $final_score,
						'correct' => $correct_count,
						'incorrect' => $incorrect_count,
						'skipped' => $skipped_count,
						'not_viewed' => $not_viewed_count,
						'total_attempted' => $total_attempted,
						'session_id' => $session_id
					]);

					$wpdb->query($wpdb->prepare(
						"INSERT INTO {$progress_table} (user_id, item_id, course_id, status, completion_date, result_data, last_viewed, attempt_count)
                     VALUES (%d, %d, %d, 'completed', %s, %s, %s, 1)
                     ON DUPLICATE KEY UPDATE 
                        status = VALUES(status), completion_date = VALUES(completion_date), result_data = VALUES(result_data), last_viewed = VALUES(last_viewed), attempt_count = attempt_count + 1",
						$user_id,
						$item_id,
						$course_id,
						current_time('mysql'),
						$result_data,
						current_time('mysql')
					));

					// Update Course Overall Status
					$course_stats = $wpdb->get_row($wpdb->prepare(
						"SELECT 
                        (SELECT COUNT(item_id) FROM {$items_table} WHERE course_id = %d) as total,
                        (SELECT COUNT(user_item_id) FROM {$progress_table} WHERE user_id = %d AND course_id = %d AND status = 'completed') as completed",
						$course_id,
						$user_id,
						$course_id
					));

					$total_items = (int)$course_stats->total;
					$completed_items = (int)$course_stats->completed;
					$progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;
					$new_course_status = ($total_items > 0 && $completed_items >= $total_items) ? 'completed' : 'in_progress';

					$completion_date_sql = "NULL";
					if ($new_course_status === 'completed') {
						$completion_date_sql = "COALESCE(completion_date, '" . current_time('mysql') . "')";
					}

					$wpdb->query($wpdb->prepare(
						"UPDATE {$user_courses_table} SET progress_percent = %d, status = %s, completion_date = $completion_date_sql WHERE user_id = %d AND course_id = %d",
						$progress_percent,
						$new_course_status,
						$user_id,
						$course_id
					));
				}
			}

			$wpdb->query('COMMIT');

			// Architect's Path: Aggressively update aggregated user stats after session finalize
			Analytics_Manager::update_user_stats($session->user_id);

			// 3. Capture End State
			$end_time = microtime(true);
			$end_queries = get_num_queries();

			// 4. Calculate Results
			$total_time = number_format($end_time - $start_time, 5);
			$total_queries = $end_queries - $start_queries;

			error_log("Total time taken:" . $total_time . "seconds");
			error_log("Database Queries:" . $total_queries);

			return [
				'final_score' => $final_score,
				'total_attempted' => $total_attempted,
				'correct_count' => $correct_count,
				'incorrect_count' => $incorrect_count,
				'skipped_count' => $skipped_count,
				'not_viewed_count' => $not_viewed_count,
				'settings' => $settings,
			];
		} catch (\Exception $e) {
			$wpdb->query('ROLLBACK');
			error_log("QP Finalize Error: " . $e->getMessage());
			return null;
		}
	}


	/**
     * Deletes a session and its associated attempts after verifying ownership.
     * Centralized logic for AJAX and REST API.
     *
     * @param int $session_id The ID of the session to delete.
     * @param int $user_id    The ID of the user attempting the deletion.
     * @return bool True on success, false if session not found or ownership fails.
     */
    public static function delete_session($session_id, $user_id)
    {
        $wpdb = self::$wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $sessions_table WHERE session_id = %d",
            $session_id
        ));

        if (!$session_owner || (int)$session_owner !== (int)$user_id) {
            return false;
        }

        // Delete the session and its related attempts
        $wpdb->delete($attempts_table, ['session_id' => $session_id], ['%d']);
        $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

        return true;
    }
}
