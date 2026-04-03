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

    // Inside includes/Utils/Analytics_Manager.php

    /**
     * Updates the aggregated user statistics and optionally updates streak data.
     *
     * @param int  $user_id       The ID of the user.
     * @param bool $update_streak Whether to evaluate and update the user's streak. Defaults to true.
     */
    public static function update_user_stats($user_id, $update_streak = true)
    {
        $wpdb = self::$wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $user_id = absint($user_id);

        // 1. Calculate Core Metrics
        $total_time = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_active_seconds) FROM {$sessions_table} 
             WHERE user_id = %d AND status IN ('completed', 'abandoned', 'paused')",
            $user_id
        ));

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(CASE WHEN status = 'answered' THEN 1 END) as total_attempted, 
                    COUNT(CASE WHEN is_correct = 1 THEN 1 END) as total_correct
             FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'",
            $user_id
        ));

        $total_attempts = (int) ($stats->total_attempted ?? 0);
        $correct_count  = (int) ($stats->total_correct ?? 0);
        $accuracy       = ($total_attempts > 0) ? round(($correct_count / $total_attempts) * 100, 2) : 0;

        // 2. Shift persistence to Vault
        Vault_Manager::update_performance($user_id, [
            'total_time'     => $total_time,
            'total_attempts' => $total_attempts,
            'correct_count'  => $correct_count,
            'accuracy'       => $accuracy
        ]);

        // 3. Resolve Streaks - ONLY IF ACTIVITY WAS DETECTED AND FLAG IS TRUE
        // We can determine activity by checking if any attempts were logged today.
        $activity_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(attempt_id) FROM {$attempts_table} 
             WHERE user_id = %d AND DATE(attempt_time) = %s AND status = 'answered'",
            $user_id, current_time('Y-m-d')
        ));

        if ($update_streak && $activity_today > 0) {
             $today = current_time('Y-m-d');
             $vault = Vault_Manager::get_vault($user_id);
             $current_streak = (int) ($vault->streak_data['current_streak'] ?? 0);
             $last_act_date  = $vault->streak_data['last_activity_date'] ?? null;
     
             if (empty($last_act_date)) {
                 $current_streak = 1;
             } elseif ($last_act_date !== $today) {
                 $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));
                 $current_streak = ($last_act_date === $yesterday) ? $current_streak + 1 : 1;
             }

             Vault_Manager::update_streak($user_id, [
                 'current_streak'     => $current_streak,
                 'last_activity_date' => $today
             ]);
        }
    }
}
