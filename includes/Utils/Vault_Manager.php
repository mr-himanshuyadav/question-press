<?php

namespace QuestionPress\Utils;

use QuestionPress\Database\DB;

/**
 * Manages user vault data and mastery progress.
 * Centralized logic for user state and SRS metadata.
 */
class Vault_Manager
{

    /**
     * Ensures a vault entry exists for the user.
     *
     * @param int $user_id
     * @return bool
     */
    public static function ensure_vault_exists(int $user_id): bool
    {
        global $wpdb;
        $table = $wpdb->get_blog_prefix() . 'qp_user_vault';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $table WHERE user_id = %d",
            $user_id
        ));

        if (!$exists) {
            return (bool) $wpdb->insert($table, [
                'user_id'              => $user_id,
                'access_scope'         => '{}',
                'revision_config'      => wp_json_encode([
                    'daily_count'          => 20,
                    'weekly_count'         => 50,
                    'monthly_count'        => 100,
                    'weekly_day'           => 'Monday',
                    'monthly_date'         => 1,
                    'alert_time'           => '09:00',
                    'session_min_questions' => 10
                ]),
                'performance_snapshot' => '{}',
                'streak_data'          => '{}'
            ]);
        }

        return true;
    }

    /**
     * Fetches user vault data as a structured object.
     *
     * @param int $user_id
     * @return \stdClass|null
     */
    public static function get_vault(int $user_id): ?\stdClass
    {
        global $wpdb;
        $table = $wpdb->get_blog_prefix() . 'qp_user_vault';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ));

        if (!$row) {
            return null;
        }

        // Decode JSON fields and merge revision_config with system defaults for backward compatibility
        $row->access_scope         = json_decode($row->access_scope, true) ?: [];

        $defaults = [
            'daily_count'           => 20,
            'weekly_count'          => 50,
            'monthly_count'         => 100,
            'weekly_day'            => 'Monday',
            'monthly_date'          => 1,
            'alert_time'            => '09:00',
            'session_min_questions' => 10
        ];

        // Decode JSON fields for business logic use
        $current_config = json_decode($row->revision_config, true) ?: [];
        $row->revision_config = wp_parse_args($current_config, $defaults);
        unset($row->revision_config['daily_practice_time']);
        $row->performance_snapshot = json_decode($row->performance_snapshot, true) ?: [];
        $row->streak_data          = json_decode($row->streak_data, true) ?: [];

        return $row;
    }

    /**
     * Synchronizes missing user vaults using a set-based lookup.
     *
     * @return int Number of users synced.
     */
    public static function sync_all_vaults(): int
    {
        global $wpdb;
        $vault_table = $wpdb->get_blog_prefix() . 'qp_user_vault';

        // Find users who exist in WP but don't have a vault row yet
        $missing_user_ids = $wpdb->get_col("
            SELECT u.ID 
            FROM {$wpdb->users} u 
            LEFT JOIN {$vault_table} v ON u.ID = v.user_id 
            WHERE v.user_id IS NULL
        ");

        if (empty($missing_user_ids)) return 0;

        $count = 0;
        foreach ($missing_user_ids as $user_id) {
            if (self::ensure_vault_exists((int) $user_id)) {
                $count++;
            }
        }
        return $count;
    }
    /**
     * Recalculates mastery data using a high-performance single query.
     * Incorporates warm-start logic to initialize SRS metadata for all attempts.
     *
     * @return int Number of mastery records affected.
     */
    public static function recalculate_mastery_from_history(): int
    {
        global $wpdb;
        $attempts_table = $wpdb->get_blog_prefix() . 'qp_user_attempts';
        $mastery_table  = $wpdb->get_blog_prefix() . 'qp_user_mastery';
        $now = current_time('mysql');

        // Optimized INSERT...SELECT: Initializes SRS values to ensure immediate revision eligibility
        $affected = $wpdb->query("
            INSERT INTO $mastery_table (user_id, question_id, box_number, ease_factor, last_result, next_review_date)
            SELECT 
                a.user_id, 
                a.question_id, 
                IF(a.is_correct, 2, 1), 
                2.50, 
                a.is_correct, 
                NULL
            FROM $attempts_table a
            INNER JOIN (
                SELECT MAX(attempt_id) as max_id
                FROM $attempts_table
                GROUP BY user_id, question_id
            ) b ON a.attempt_id = b.max_id
            ON DUPLICATE KEY UPDATE 
                last_result = VALUES(last_result),
                next_review_date = IFNULL(next_review_date, NULL)
        ");

        return (int) $affected;
    }

    /**
     * Updates the mastery rating for a specific question based on user confidence.
     * Implements SRS logic: next_review = NOW + (Box * Ease Factor * 2 days).
     *
     * @param int    $user_id
     * @param int    $question_id
     * @param string $rating 'again', 'hard', 'good', 'easy'
     * @return string|null The next review date (ISO 8601) or null on failure.
     */
    public static function update_mastery_rating(int $user_id, int $question_id, string $rating): ?string
    {
        global $wpdb;
        $table = $wpdb->get_blog_prefix() . 'qp_user_mastery';

        $mastery = $wpdb->get_row($wpdb->prepare(
            "SELECT box_number, ease_factor FROM $table WHERE user_id = %d AND question_id = %d",
            $user_id,
            $question_id
        ));

        $box  = $mastery ? (int) $mastery->box_number : 0;
        $ease = $mastery ? (float) $mastery->ease_factor : 2.50;

        switch ($rating) {
            case 'again':
                $box = 1;
                $ease -= 0.20;
                break;
            case 'hard':
                $box += 1;
                $ease -= 0.15;
                break;
            case 'good':
                $box += 1;
                break;
            case 'easy':
                $box += 2;
                $ease += 0.15;
                break;
            default:
                return null;
        }

        $box = max(1, min(5, $box));
        $ease = max(1.30, $ease);

        $days = round($box * $ease * 2);
        $next_review = gmdate('Y-m-d H:i:s', strtotime("+{$days} days"));

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (user_id, question_id, box_number, ease_factor, next_review_date)
             VALUES (%d, %d, %d, %f, %s)
             ON DUPLICATE KEY UPDATE 
                box_number = VALUES(box_number), 
                ease_factor = VALUES(ease_factor), 
                next_review_date = VALUES(next_review_date)",
            $user_id,
            $question_id,
            $box,
            $ease,
            $next_review
        ));

        return $next_review;
    }

    /**
     * Determines the priority task for a user based on their revision schedule.
     * * @param int $user_id
     * @return string
     */
    public static function get_today_priority_task(int $user_id): string
    {
        $vault = self::get_vault($user_id);
        if (!$vault || empty($vault->revision_config)) {
            return 'Daily Review';
        }

        $config = $vault->revision_config;

        $today_day_name = gmdate('l'); // Monday, Tuesday, etc.
        $today_date = (int) gmdate('j'); // 1–31

        if (isset($config['monthly_date']) && (int) $config['monthly_date'] === $today_date) {
            return 'Monthly Review';
        }

        if (
            isset($config['weekly_day']) &&
            strcasecmp($config['weekly_day'], $today_day_name) === 0
        ) {
            return 'Weekly Review';
        }

        return 'Daily Review';
    }

    /**
     * Updates the user's revision configuration within the vault.
     * * @param int   $user_id
     * @param array $settings Map containing weekly_day, monthly_date, session_min_questions, or focus_subjects.
     * @return bool
     */
    public static function update_revision_settings(int $user_id, array $settings): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'qp_user_vault';

        self::ensure_vault_exists($user_id);
        $vault = self::get_vault($user_id);

        if (!$vault) return false;

        // revision_config is already decoded as an array by get_vault()
        $config = (array) $vault->revision_config;

        $valid_keys = [
            'weekly_day',
            'monthly_date',
            'session_min_questions',
            'focus_subjects',
            'alert_time',
            'daily_count',
            'weekly_count',
            'monthly_count'
        ];
        foreach ($valid_keys as $key) {
            if (isset($settings[$key])) {
                $config[$key] = $settings[$key];
            }
        }

        $result = $wpdb->update(
            $table,
            ['revision_config' => wp_json_encode($config)],
            ['user_id' => $user_id]
        );

        return $result !== false;
    }

    /**
     * Synchronizes mastery data from a session's attempts using full SRS math.
     *
     * @param int $session_id
     * @return void
     */
    public static function sync_mastery_from_session(int $session_id): void
    {
        global $wpdb;
        $attempts_table = $wpdb->get_blog_prefix() . 'qp_user_attempts';
        $mastery_table  = $wpdb->get_blog_prefix() . 'qp_user_mastery';
        $sessions_table = $wpdb->get_blog_prefix() . 'qp_user_sessions';

        $user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $sessions_table WHERE session_id = %d",
            $session_id
        ));

        if (!$user_id) return;

        // Fetch distinct results for questions answered in this session
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT question_id, is_correct FROM $attempts_table WHERE session_id = %d AND status = 'answered'",
            $session_id
        ));

        if (empty($results)) return;

        foreach ($results as $row) {
            $question_id = (int) $row->question_id;
            $is_correct  = (bool) $row->is_correct;

            // Fetch CURRENT mastery state or initialize defaults (Box 0, Ease 2.5)
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT box_number, ease_factor FROM $mastery_table WHERE user_id = %d AND question_id = %d",
                $user_id,
                $question_id
            ), ARRAY_A) ?: ['box_number' => 0, 'ease_factor' => 2.50];

            $new_state = self::get_updated_mastery_state($current, $is_correct);

            $wpdb->query($wpdb->prepare(
                "INSERT INTO $mastery_table (user_id, question_id, box_number, ease_factor, next_review_date, last_result)
                 VALUES (%d, %d, %d, %f, %s, %d)
                 ON DUPLICATE KEY UPDATE 
                    box_number = VALUES(box_number), 
                    ease_factor = VALUES(ease_factor), 
                    next_review_date = VALUES(next_review_date),
                    last_result = VALUES(last_result)",
                $user_id,
                $question_id,
                $new_state['box_number'],
                $new_state['ease_factor'],
                $new_state['next_review_date'],
                $new_state['last_result']
            ));
        }
    }

    /**
     * PRIVATE: Calculates the next SRS state based on performance.
     * Logic: Box resets to 0 on incorrect; Box increments on correct (max 5).
     *
     * @param array $current_state ['box_number' => int, 'ease_factor' => float]
     * @param bool  $is_correct
     * @return array Updated state values.
     */
    private static function get_updated_mastery_state(array $current_state, bool $is_correct): array
    {
        $box  = (int) $current_state['box_number'];
        $ease = (float) $current_state['ease_factor'];

        if ($is_correct) {
            $box = min(5, $box + 1);
            $ease += 0.10; // Slight ease increase for success
        } else {
            $box = max(0, $box - 1); // Immediate reset on failure
            $ease = max(1.30, $ease - 0.20); // Significant ease penalty
        }

        // Standardized Interval Formula: Interval = Box * Ease * 2 days
        $days = (int) round($box * $ease * 2);
        $next_review = gmdate('Y-m-d H:i:s', strtotime("+{$days} days"));

        return [
            'box_number'       => $box,
            'ease_factor'      => $ease,
            'next_review_date' => $next_review,
            'last_result'      => $is_correct ? 1 : 0
        ];
    }
}
