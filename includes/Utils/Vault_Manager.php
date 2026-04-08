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
                    'sessions' => [] // Empty triggers the frontend walkthrough
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

        $row->access_scope = json_decode($row->access_scope, true) ?: [];

        $current_config = json_decode($row->revision_config, true) ?: [];

        // Handle legacy root-level configs safely
        if (!isset($current_config['sessions']) && !empty($current_config)) {
            // Old data exists at root, but no sessions array -> Needs Upgrade
            $row->revision_config = ['sessions' => []];
            $row->needs_config_walkthrough = true;
            $row->walkthrough_type = 'update';
        } else {
            // Either brand new user or already updated user
            $row->revision_config = $current_config;
            $row->needs_config_walkthrough = empty($current_config['sessions']);
            $row->walkthrough_type = 'new';
        }

        $row->performance_snapshot = json_decode($row->performance_snapshot, true) ?: [];
        $row->streak_data          = json_decode($row->streak_data, true) ?: [];

        return $row;
    }

    /**
     * Retrieves only the access_scope column for a user.
     *
     * @param int $user_id
     * @return array
     */
    public static function get_access_scope(int $user_id): array
    {
        global $wpdb;
        $table = $wpdb->get_blog_prefix() . 'qp_user_vault';

        $scope_json = $wpdb->get_var($wpdb->prepare(
            "SELECT access_scope FROM $table WHERE user_id = %d",
            $user_id
        ));

        if (!$scope_json) {
            return [];
        }

        return json_decode($scope_json, true) ?: [];
    }

    /**
     * Adds or updates a custom revision session configuration.
     * Enforces the max 3 limit and the 15-day lock on subjects/exams.
     *
     * @param int $user_id
     * @param array $session_data
     * @return array|\WP_Error Returns the updated sessions array or WP_Error
     */
    public static function add_or_update_revision_session(int $user_id, array $session_data)
    {
        $vault = self::get_vault($user_id);
        if (!$vault) return new \WP_Error('vault_not_found', 'User vault not found.');

        $config = (array) $vault->revision_config;
        $sessions = $config['sessions'];

        $session_id = $session_data['id'] ?? uniqid('rev_');
        $is_new = true;
        $existing_index = -1;

        foreach ($sessions as $index => $s) {
            if (isset($s['id']) && $s['id'] === $session_id) {
                $is_new = false;
                $existing_index = $index;
                break;
            }
        }

        // Enforce the limit of 3 configs
        if ($is_new && count($sessions) >= 3) {
            return new \WP_Error('limit_reached', 'You can only have up to 3 revision configurations.');
        }

        $current_time = time();

        // -------------------------------------------------------------
        // FIX: PROCESS EXAMS & SUBJECTS FOR BOTH NEW AND UPDATED CONFIGS
        // -------------------------------------------------------------
        $final_subjects = $session_data['subjects'] ?? [];
        $exam_id = isset($session_data['exam_id']) ? absint($session_data['exam_id']) : null;

        if ($exam_id && empty($final_subjects)) {
            global $wpdb;
            // Find subjects linked to this exam
            $linked_subjects = $wpdb->get_col($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->prefix}qp_term_relationships WHERE object_id = %d AND object_type = 'exam_subject_link'",
                $exam_id
            ));

            // Fallback: Check meta if relationship fails
            if (empty($linked_subjects)) {
                $linked_subjects = get_term_meta($exam_id, '_qp_linked_subjects', true) ?: [];
            }

            if (!empty($linked_subjects)) {
                $weight = floor(100 / count($linked_subjects));
                $remainder = 100 % count($linked_subjects);
                foreach ($linked_subjects as $index => $sub_id) {
                    $final_subjects[] = [
                        'subject_id' => (int)$sub_id,
                        'weightage'  => $weight + ($index === 0 ? $remainder : 0) // Give remainder to the first one to equal 100%
                    ];
                }
            }
        }

        if (!$is_new) {
            $existing_session = $sessions[$existing_index];
            $last_updated = isset($existing_session['last_updated_date']) ? strtotime($existing_session['last_updated_date']) : 0;
            $days_since_update = ($current_time - $last_updated) / (60 * 60 * 24);

            // Check if locked (15 days) and trying to change core config
            if ($days_since_update < 15) {
                $exam_changed = isset($session_data['exam_id']) && $session_data['exam_id'] !== $existing_session['exam_id'];
                $subjects_changed = isset($session_data['subjects']) && wp_json_encode($final_subjects) !== wp_json_encode($existing_session['subjects']);

                if ($exam_changed || $subjects_changed) {
                    $days_left = ceil(15 - $days_since_update);
                    return new \WP_Error('config_locked', "Core subjects are locked for $days_left more day(s). You can only edit name, color, limits, and alert time.");
                }
            }

            // Always allow updates to appearance and limits (non-core logic)
            $sessions[$existing_index]['name'] = sanitize_text_field($session_data['name'] ?? $existing_session['name']);
            $sessions[$existing_index]['color'] = sanitize_hex_color($session_data['color'] ?? $existing_session['color']);
            $sessions[$existing_index]['alert_time'] = sanitize_text_field($session_data['alert_time'] ?? $existing_session['alert_time']);
            $sessions[$existing_index]['daily_count'] = absint($session_data['daily_count'] ?? $existing_session['daily_count'] ?? 20);
            $sessions[$existing_index]['weekly_count'] = absint($session_data['weekly_count'] ?? $existing_session['weekly_count'] ?? 50);
            $sessions[$existing_index]['monthly_count'] = absint($session_data['monthly_count'] ?? $existing_session['monthly_count'] ?? 100);
            $sessions[$existing_index]['weekly_day'] = sanitize_text_field($session_data['weekly_day'] ?? $existing_session['weekly_day'] ?? 'Monday');
            $sessions[$existing_index]['monthly_date'] = absint($session_data['monthly_date'] ?? $existing_session['monthly_date'] ?? 1);
            $sessions[$existing_index]['session_min_questions'] = absint($session_data['session_min_questions'] ?? $existing_session['session_min_questions'] ?? 10);

            // If past the 15-day lock, allow full subject/exam update and reset the timer
            if ($days_since_update >= 15) {
                $sessions[$existing_index]['exam_id'] = $exam_id;
                $sessions[$existing_index]['subjects'] = $final_subjects;

                if (array_key_exists('exam_id', $session_data) || isset($session_data['subjects'])) {
                    $sessions[$existing_index]['last_updated_date'] = gmdate('Y-m-d H:i:s', $current_time);
                }
            }
        } else {
            // Generate a completely new session config
            $sessions[] = [
                'id' => $session_id,
                'name' => sanitize_text_field($session_data['name'] ?? 'New Revision'),
                'color' => sanitize_hex_color($session_data['color'] ?? '#4F46E5'),
                'alert_time' => sanitize_text_field($session_data['alert_time'] ?? '09:00'),
                'exam_id' => $exam_id, // Use resolved exam_id
                'subjects' => $final_subjects, // Use resolved final_subjects
                'daily_count' => absint($session_data['daily_count'] ?? 20),
                'weekly_count' => absint($session_data['weekly_count'] ?? 50),
                'monthly_count' => absint($session_data['monthly_count'] ?? 100),
                'weekly_day' => sanitize_text_field($session_data['weekly_day'] ?? 'Monday'),
                'monthly_date' => absint($session_data['monthly_date'] ?? 1),
                'session_min_questions' => absint($session_data['session_min_questions'] ?? 10),
                'last_updated_date' => gmdate('Y-m-d H:i:s', $current_time),
            ];
        }

        $config['sessions'] = array_values($sessions);

        global $wpdb;
        $table = $wpdb->prefix . 'qp_user_vault';
        $updated = $wpdb->update($table, ['revision_config' => wp_json_encode($config)], ['user_id' => $user_id]);

        if ($updated === false) {
            return new \WP_Error('db_error', 'Failed to update the revision configuration.');
        }

        return $config['sessions'];
    }

    /**
     * Deletes a specific revision config for a user.
     */
    public static function delete_revision_session(int $user_id, string $session_id): bool
    {
        $vault = self::get_vault($user_id);
        if (!$vault) return false;

        $config = (array) $vault->revision_config;
        $sessions = $config['sessions'];

        $initial_count = count($sessions);
        $sessions = array_filter($sessions, function ($s) use ($session_id) {
            return isset($s['id']) && $s['id'] !== $session_id;
        });

        if (count($sessions) === $initial_count) return false;

        $config['sessions'] = array_values($sessions);
        global $wpdb;
        $table = $wpdb->prefix . 'qp_user_vault';

        return $wpdb->update($table, ['revision_config' => wp_json_encode($config)], ['user_id' => $user_id]) !== false;
    }

    /**
     * Utility to push updated Exam subject requirements to all subscribed users.
     */
    public static function sync_exam_to_vaults(int $exam_id, array $new_subjects): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'qp_user_vault';

        $query = $wpdb->prepare(
            "SELECT user_id, revision_config FROM {$table} WHERE revision_config LIKE %s",
            '%"exam_id":' . $exam_id . '%'
        );

        $vaults = $wpdb->get_results($query);
        $updated_count = 0;

        foreach ($vaults as $vault) {
            $config = json_decode($vault->revision_config, true);
            $modified = false;

            if (isset($config['sessions']) && is_array($config['sessions'])) {
                foreach ($config['sessions'] as &$session) {
                    if (isset($session['exam_id']) && (int)$session['exam_id'] === $exam_id) {
                        $session['subjects'] = $new_subjects;
                        $modified = true;
                    }
                }
            }

            if ($modified) {
                $wpdb->update($table, ['revision_config' => wp_json_encode($config)], ['user_id' => $vault->user_id]);
                $updated_count++;
            }
        }

        return $updated_count;
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
     * Determines the priority task for a specific session based on its schedule.
     * Evaluates against the specific session provided.
     *
     * @param int $user_id
     * @param string|null $session_id Specific session to check.
     * @return string 'Daily Review', 'Weekly Review', or 'Monthly Review'
     */
    public static function get_today_priority_task(int $user_id, ?string $session_id = null): string
    {
        $vault = self::get_vault($user_id);
        if (!$vault || empty($vault->revision_config['sessions'])) {
            return 'Daily Review';
        }

        $sessions = $vault->revision_config['sessions'];
        $target_session = $sessions[0]; // Default to first

        if ($session_id) {
            foreach ($sessions as $s) {
                if (isset($s['id']) && $s['id'] === $session_id) {
                    $target_session = $s;
                    break;
                }
            }
        }

        $today_day_name = gmdate('l');
        $today_date = (int) gmdate('j');

        if (isset($target_session['monthly_date']) && (int) $target_session['monthly_date'] === $today_date) {
            return 'Monthly Review';
        }

        if (isset($target_session['weekly_day']) && strcasecmp($target_session['weekly_day'], $today_day_name) === 0) {
            return 'Weekly Review';
        }

        return 'Daily Review';
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


    /**
     * Updates access scope and resolves exams to subjects immediately.
     */
    public static function update_access_scope(int $user_id, array $exam_ids, array $manual_subject_ids): bool
    {
        global $wpdb;
        self::ensure_vault_exists($user_id);

        // Resolve Exam -> Subject links now so we don't have to later
        $resolved_subject_ids = $manual_subject_ids;
        if (!empty($exam_ids)) {
            $exam_placeholders = implode(',', array_map('absint', $exam_ids));
            $subjects_from_exams = $wpdb->get_col(
                "SELECT DISTINCT term_id FROM {$wpdb->prefix}qp_term_relationships 
             WHERE object_type = 'exam_subject_link' AND object_id IN ($exam_placeholders)"
            );
            $resolved_subject_ids = array_unique(array_merge($resolved_subject_ids, $subjects_from_exams));
        }

        $scope = [
            'exams'             => array_values(array_unique(array_map('absint', $exam_ids))),
            'manual_subjects'   => array_values(array_unique(array_map('absint', $manual_subject_ids))),
            'resolved_subjects' => array_values(array_map('absint', $resolved_subject_ids))
        ];

        return false !== $wpdb->update(
            $wpdb->prefix . 'qp_user_vault',
            ['access_scope' => wp_json_encode($scope)],
            ['user_id' => $user_id]
        );
    }

    /**
     * Global utility to refresh all user scopes if an Exam link changes.
     */
    public static function refresh_all_user_scopes()
    {
        global $wpdb;
        $vault_table = $wpdb->prefix . 'qp_user_vault';
        $users = $wpdb->get_results("SELECT user_id, access_scope FROM $vault_table");

        foreach ($users as $u) {
            $scope = json_decode($u->access_scope, true);
            if (!empty($scope['exams'])) {
                self::update_access_scope((int)$u->user_id, $scope['exams'], $scope['manual_subjects'] ?? []);
            }
        }
    }

    /**
     * Persists aggregated performance data to the vault.
     */
    public static function update_performance(int $user_id, array $stats): bool
    {
        global $wpdb;
        self::ensure_vault_exists($user_id);
        return false !== $wpdb->update(
            $wpdb->prefix . 'qp_user_vault',
            ['performance_snapshot' => wp_json_encode($stats)],
            ['user_id' => $user_id]
        );
    }

    /**
     * Persists streak data to the vault.
     */
    public static function update_streak(int $user_id, array $streak): bool
    {
        global $wpdb;
        self::ensure_vault_exists($user_id);
        return false !== $wpdb->update(
            $wpdb->prefix . 'qp_user_vault',
            ['streak_data' => wp_json_encode($streak)],
            ['user_id' => $user_id]
        );
    }
}
