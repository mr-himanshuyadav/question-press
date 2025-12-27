<?php

namespace QuestionPress\Utils;

use QuestionPress\Database\DB;

/**
 * Handles aggregation and persistence of user analytics data.
 *
 * @package QuestionPress\Utils
 */
class Analytics_Manager extends DB
{

    /**
     * Aggregates session data and updates user meta for fast retrieval.
     * Now includes logic for the Streak Engine.
     *
     * @param int $user_id The ID of the user to update.
     * @return void
     */
    public static function update_user_stats($user_id)
    {
        $wpdb = self::$wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // 1. Aggregate Core Metrics
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(total_active_seconds) as total_time,
                SUM(total_attempted) as total_attempts,
                SUM(correct_count) as total_correct
             FROM {$sessions_table}
             WHERE user_id = %d AND (status = 'completed' OR status = 'abandoned')",
            absint($user_id)
        ));

        $total_time     = (int) ($stats->total_time ?? 0);
        $total_attempts = (int) ($stats->total_attempts ?? 0);
        $correct_count  = (int) ($stats->total_correct ?? 0);
        $accuracy       = ($total_attempts > 0) ? round(($correct_count / $total_attempts) * 100, 2) : 0;

        // 2. Streak Engine Logic
        $today          = current_time('Y-m-d');
        $last_act_date  = get_user_meta($user_id, '_qp_last_activity_date', true);
        $current_streak = (int) get_user_meta($user_id, '_qp_current_streak', true);

        if (empty($last_act_date)) {
            // First ever activity
            $current_streak = 1;
            update_user_meta($user_id, '_qp_current_streak', $current_streak);
            update_user_meta($user_id, '_qp_last_activity_date', $today);
        } elseif ($last_act_date !== $today) {
            // New day activity
            $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));
            
            if ($last_act_date === $yesterday) {
                $current_streak++; // Continued streak
            } else {
                $current_streak = 1; // Reset streak
            }
            
            update_user_meta($user_id, '_qp_current_streak', $current_streak);
            update_user_meta($user_id, '_qp_last_activity_date', $today);
        }
        // If last_act_date == $today, we do nothing to the streak.

        // 3. Persist Aggregated Values
        update_user_meta($user_id, '_qp_total_time_spent', $total_time);
        update_user_meta($user_id, '_qp_total_attempts', $total_attempts);
        update_user_meta($user_id, '_qp_correct_count', $correct_count);
        update_user_meta($user_id, '_qp_overall_accuracy', $accuracy);
    }
}