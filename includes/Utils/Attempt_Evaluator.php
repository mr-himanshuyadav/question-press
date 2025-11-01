<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Database\DB; // <-- Import the base DB class

/**
 * Handles logic for evaluating or re-evaluating user attempts.
 */
class Attempt_Evaluator extends DB { // <-- Extend DB to get self::$wpdb

	/**
	 * Re-evaluates all attempts for a specific question after its correct answer has changed.
	 * It also recalculates and updates the stats for all affected sessions.
	 * (Moved from global function qp_re_evaluate_question_attempts)
	 *
	 * @param int $question_id The ID of the question that was updated.
	 * @param int $new_correct_option_id The ID of the new correct option.
	 */
	public static function re_evaluate_question_attempts($question_id, $new_correct_option_id)
	{
		$wpdb = self::$wpdb; // <-- Use self::$wpdb
		$attempts_table = $wpdb->prefix . 'qp_user_attempts';
		$sessions_table = $wpdb->prefix . 'qp_user_sessions';

		// 1. Find all session IDs that have an attempt for this question.
		$affected_session_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT session_id FROM {$attempts_table} WHERE question_id = %d",
			$question_id
		));

		if (empty($affected_session_ids)) {
			return; // No attempts to update.
		}

		// 2. Update the is_correct status for all attempts of this question.
		// Set is_correct = 1 where the selected option matches the new correct option.
		$wpdb->query($wpdb->prepare(
			"UPDATE {$attempts_table} SET is_correct = 1 WHERE question_id = %d AND selected_option_id = %d",
			$question_id,
			$new_correct_option_id
		));
		// Set is_correct = 0 for all other attempts of this question.
		$wpdb->query($wpdb->prepare(
			"UPDATE {$attempts_table} SET is_correct = 0 WHERE question_id = %d AND selected_option_id != %d",
			$question_id,
			$new_correct_option_id
		));

		// 3. Loop through each affected session and recalculate its score.
		foreach ($affected_session_ids as $session_id) {
			$session = $wpdb->get_row($wpdb->prepare("SELECT settings_snapshot FROM {$sessions_table} WHERE session_id = %d", $session_id));
			if (!$session) continue;

			$settings = json_decode($session->settings_snapshot, true);
			$marks_correct = $settings['marks_correct'] ?? 0;
			$marks_incorrect = $settings['marks_incorrect'] ?? 0;

			// Recalculate counts directly from the attempts table for this session
			$correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 1", $session_id));
			$incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 0", $session_id));

			$final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

			// Update the session record with the new, accurate counts and score.
			$wpdb->update(
				$sessions_table,
				[
					'correct_count' => $correct_count,
					'incorrect_count' => $incorrect_count,
					'marks_obtained' => $final_score
				],
				['session_id' => $session_id]
			);
		}
	}
}