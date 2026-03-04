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
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        
        $user_id = absint($user_id);

        // 1. Aggregate Core Metrics
        
        // A. Get Time from Sessions (include paused/completed/abandoned to be as accurate as possible)
        $total_time = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_active_seconds) 
             FROM {$sessions_table} 
             WHERE user_id = %d AND status IN ('completed', 'abandoned', 'paused')",
            $user_id
        ));

        // B. Get Attempts & Accuracy from Attempts Table (The true source of truth)
        // This ensures parity with Dashboard_Manager::get_overview_data
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(CASE WHEN status = 'answered' THEN 1 END) as total_attempted, 
                COUNT(CASE WHEN is_correct = 1 THEN 1 END) as total_correct
             FROM {$attempts_table} 
             WHERE user_id = %d AND status = 'answered'",
            $user_id
        ));

        $total_attempts = (int) ($stats->total_attempted ?? 0);
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