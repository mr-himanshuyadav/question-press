<?php
// Use the correct namespace
namespace QuestionPress\Core;

use QuestionPress\Ajax\Admin_Ajax;
use QuestionPress\Modules\Session\Session_Manager; // For session handling

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles WordPress cron events for Question Press.
 *
 * @package QuestionPress\Core
 */
class Cron
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Constructor can be used for setup if needed
    }

    public function init()
    {
        // Schedule the entitlement expiration check on WP initialization
        add_action('init', [$this, 'ensure_cron_scheduled']);

        // Hook the entitlement expiration check to our custom cron event
        add_action('qp_check_entitlement_expiration_hook', [$this, 'run_entitlement_expiration_check']);

        // Schedule the session cleanup event
        add_action('init', [$this, 'schedule_session_cleanup']);

        // Hook the session cleanup function to our custom cron event
        add_action('qp_cleanup_abandoned_sessions_event', [$this, 'cleanup_abandoned_sessions']);

        // Hook the course expiration check to its custom cron event
        add_action('qp_check_course_expiration_hook', [$this, 'run_course_expiration_check']);

        // Register the daily hardness calculation hook
        add_action('qp_daily_auto_hardness_calc', [$this, 'calculate_question_auto_hardness']);
    }

    /**
     * Ensures the entitlement expiration cron job is scheduled.
     * Runs on WordPress initialization.
     *
     * @return void
     */
    public function ensure_cron_scheduled()
    {
        if (! wp_next_scheduled('qp_check_entitlement_expiration_hook')) {
            wp_schedule_event(time(), 'daily', 'qp_check_entitlement_expiration_hook');
            error_log("QP Cron: Re-scheduled entitlement expiration check on init.");
        }

        if (! wp_next_scheduled('qp_check_course_expiration_hook')) {
            wp_schedule_event(time(), 'daily', 'qp_check_course_expiration_hook');
            error_log("QP Cron: Scheduled course expiration check.");
        }

        // Schedule Auto-Hardness Calculation at Midnight
        if (!wp_next_scheduled('qp_daily_auto_hardness_calc')) {
            // Calculate the timestamp for the upcoming midnight (server time)
            $midnight = strtotime('tomorrow 00:00:00');
            wp_schedule_event($midnight, 'daily', 'qp_daily_auto_hardness_calc');
            error_log("QP Cron: Scheduled daily auto-hardness calculation for midnight.");
        }

        // Schedule Daily Subject Mastery Update at Midnight
        if (!wp_next_scheduled('qp_daily_mastery_calc')) {
            $midnight = strtotime('tomorrow 00:00:00');
            wp_schedule_event($midnight, 'daily', 'qp_daily_mastery_calc');
            error_log("QP Cron: Scheduled daily subject mastery calculation for midnight.");
        }
    }

    /**
     * The callback function executed by the WP-Cron job to update expired entitlements.
     *
     * @return void
     */
    public function run_entitlement_expiration_check()
    {
        error_log("QP Cron: Running entitlement expiration check...");
        global $wpdb;
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $current_time       = current_time('mysql');

        // Find entitlement records that are 'active' but whose expiry date is in the past
        $expired_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT entitlement_id
             FROM {$entitlements_table}
             WHERE status = 'active'
             AND expiry_date IS NOT NULL
             AND expiry_date <= %s",
            $current_time
        ));

        if (! empty($expired_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $expired_ids));

            // Update the status of these records to 'expired'
            $updated_count = $wpdb->query(
                "UPDATE {$entitlements_table}
                 SET status = 'expired'
                 WHERE entitlement_id IN ($ids_placeholder)"
            );

            if ($updated_count !== false) {
                error_log("QP Cron: Marked {$updated_count} entitlements as expired.");
            } else {
                error_log("QP Cron: Error updating expired entitlements. DB Error: " . $wpdb->last_error);
            }
        } else {
            error_log("QP Cron: No expired entitlements found to update.");
        }
    }

    /**
     * Schedules the session cleanup event if it's not already scheduled.
     *
     * @return void
     */
    public function schedule_session_cleanup()
    {
        if (! wp_next_scheduled('qp_cleanup_abandoned_sessions_event')) {
            wp_schedule_event(time(), 'hourly', 'qp_cleanup_abandoned_sessions_event');
        }
    }

    /**
     * The function that runs on the scheduled cron event to clean up old sessions.
     *
     * @return void
     */
    public function cleanup_abandoned_sessions()
    {
        global $wpdb;
        $options         = get_option('qp_settings');
        $timeout_minutes = isset($options['session_timeout']) ? absint($options['session_timeout']) : 20;

        if ($timeout_minutes < 5) {
            $timeout_minutes = 20;
        }

        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // --- 1. Handle Expired Mock Tests ---

        // TODO: Check correctness of mock_test identification
        $active_mock_tests = $wpdb->get_results(
            "SELECT session_id, start_time, settings_snapshot FROM {$sessions_table} WHERE session_type = 'mock_test' and status = 'active'"
        );

        foreach ($active_mock_tests as $test) {
            $settings         = json_decode($test->settings_snapshot, true);
            $duration_seconds = $settings['timer_seconds'] ?? 0;

            if ($duration_seconds <= 0) {
                continue;
            }

            $start_time_gmt  = get_gmt_from_date($test->start_time);
            $start_timestamp = strtotime($start_time_gmt);
            $end_timestamp   = $start_timestamp + $duration_seconds;

            // If the current time is past the test's official end time, finalize it as abandoned.
            if (time() > $end_timestamp) {
                // Our updated function will delete it if empty, or mark as abandoned if there are attempts.
                Session_Manager::finalize_and_end_session($test->session_id, 'abandoned', 'abandoned_by_system');
            }
        }

        // --- 2. Handle Abandoned 'active' sessions ---
        $abandoned_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, settings_snapshot FROM {$sessions_table}
             WHERE status = 'active' AND last_activity < NOW() - INTERVAL %d MINUTE",
            $timeout_minutes
        ));

        if (! empty($abandoned_sessions)) {
            foreach ($abandoned_sessions as $session) {
                $settings            = json_decode($session->settings_snapshot, true);
                // TODO: Fix this pratice_mode reference for session_name and session_type
                $is_section_practice = isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice';

                if ($is_section_practice) {
                    // For section practice, just pause the session instead of abandoning it.
                    $wpdb->update(
                        $sessions_table,
                        ['status' => 'paused'],
                        ['session_id' => $session->session_id]
                    );
                } else {
                    // For all other modes, use the standard abandon/delete logic.
                    Session_Manager::finalize_and_end_session($session->session_id, 'abandoned', 'abandoned_by_system');
                }
            }
        }
    }

    /**
     * The callback function executed by WP-Cron to check for and expire courses.
     *
     * @return void
     */
    public function run_course_expiration_check()
    {
        error_log("QP Cron: Running course expiration check...");
        global $wpdb;
        $current_time = current_time('mysql', true); // Get GMT time for comparison
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $expired_course_count = 0;

        // 1. Find all 'published' courses that have an expiry date in the past
        $args = [
            'post_type' => 'qp_course',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_qp_course_expiry_date',
                    'value' => $current_time,
                    'compare' => '<=',
                    'type' => 'DATETIME'
                ]
            ]
        ];
        $expired_courses = new \WP_Query($args);

        if (! $expired_courses->have_posts()) {
            error_log("QP Cron: No published courses found past their expiry date.");
            return;
        }

        // 2. Loop through each expired course and take action
        while ($expired_courses->have_posts()) {
            $expired_courses->the_post();
            $course_id = get_the_ID();

            // 2a. Set the Course status to 'draft'
            wp_update_post([
                'ID' => $course_id,
                'post_status' => 'expired'
            ]);
            update_post_meta($course_id, '_qp_status_reason', 'expired');

            // 2b. Set the auto-linked Product to 'draft'
            $auto_product_id = get_post_meta($course_id, '_qp_linked_product_id', true);
            if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
                wp_update_post([
                    'ID' => $auto_product_id,
                    'post_status' => 'draft'
                ]);
            }

            // 2c. Expire all 'active' entitlements for this course's auto-plan
            $auto_plan_id = get_post_meta($course_id, '_qp_course_auto_plan_id', true);
            if (! empty($auto_plan_id)) {
                $wpdb->update(
                    $entitlements_table,
                    ['status' => 'expired'], // Data to set
                    [
                        'plan_id' => $auto_plan_id,
                        'status' => 'active'
                    ] // WHERE clauses
                );
            }
            $expired_course_count++;
        }
        wp_reset_postdata();

        error_log("QP Cron: Successfully expired {$expired_course_count} courses and their associated products/entitlements.");
    }

    /**
     * Calculates and updates the auto_hardness for all questions based on user attempts.
     * Runs daily via WP Cron.
     */
    /**
     * Calculates and updates the auto_hardness for all questions.
     * Relies on global_attempts and global_correct updated by Session_Manager.
     * Runs daily via WP Cron.
     */
    public static function calculate_question_auto_hardness($is_cron = true) {
        global $wpdb;
        $questions_table = $wpdb->prefix . 'qp_questions';
        $attempts_table  = $wpdb->prefix . 'qp_user_attempts';
        $mastery_table   = $wpdb->prefix . 'qp_user_subject_mastery';
        $terms_table     = $wpdb->prefix . 'qp_terms';
        $tax_table       = $wpdb->prefix . 'qp_taxonomies';

        try {
            // ==========================================
            // STAGE 1: DYNAMIC HIERARCHY & DEPTH MAP
            // ==========================================
            $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
            $terms = $wpdb->get_results($wpdb->prepare("SELECT term_id, parent FROM {$terms_table} WHERE taxonomy_id = %d", $subject_tax_id));

            $parent_map = []; $children_map = []; $depths = [];
            foreach ($terms as $t) {
                $tid = (int)$t->term_id; $pid = (int)$t->parent;
                $parent_map[$tid] = $pid;
                if ($pid > 0) $children_map[$pid][] = $tid;
            }

            foreach ($parent_map as $tid => $pid) {
                $d = 0; $curr = $tid;
                while (isset($parent_map[$curr]) && $parent_map[$curr] > 0) {
                    $d++; $curr = $parent_map[$curr];
                    if ($d > 50) break; // Circuit breaker
                }
                $depths[$tid] = $d;
            }
            arsort($depths);

            // ==========================================
            // STAGE 2: EXTRACT ATTEMPTS
            // ==========================================
            $where_clause = "a.status = 'answered'";

            if ($is_cron) {
                $where_clause .= " AND a.is_processed_for_hardness = 0";
            } else {
                // If Full Rebuild: Reset all questions to base 500 Elo, reset processing flags.
                $wpdb->query("UPDATE {$questions_table} SET auto_hardness = 500");
                $wpdb->query("UPDATE {$attempts_table} SET is_processed_for_hardness = 0");
            }

            $sql = "
                SELECT 
                    a.attempt_id, a.user_id, a.question_id, a.is_correct, a.attempt_time,
                    q.subject_lineage, q.auto_hardness 
                FROM {$attempts_table} a
                JOIN {$questions_table} q ON a.question_id = q.question_id
                WHERE {$where_clause}
                ORDER BY a.attempt_time ASC
            ";
            $raw_attempts = $wpdb->get_results($sql);

            if (empty($raw_attempts)) {
                return ['success' => true, 'message' => 'No new attempts to process for hardness.'];
            }

            // ==========================================
            // STAGE 3: FETCH USERS' TARGET ABILITY (Theta)
            // ==========================================
            $users_touched = [];
            foreach ($raw_attempts as $att) { $users_touched[$att->user_id] = true; }
            
            $users_str = implode(',', array_keys($users_touched));
            $existing_mastery = $wpdb->get_results("SELECT user_id, term_id, mastery_depth FROM {$mastery_table} WHERE user_id IN ($users_str)", ARRAY_A);
            
            $user_mastery_dict = [];
            foreach ($existing_mastery as $row) {
                $user_mastery_dict[$row['user_id']][$row['term_id']] = (float)$row['mastery_depth'];
            }

            // ==========================================
            // STAGE 4: ELO CALIBRATION TRANSFORM
            // ==========================================
            $question_states = [];
            $processed_attempt_ids = [];
            $k_factor = 16; // Elo Volatility Factor

            foreach ($raw_attempts as $att) {
                $lineage = json_decode($att->subject_lineage, true);
                if (!is_array($lineage) || empty($lineage)) {
                    $processed_attempt_ids[] = (int)$att->attempt_id; // Mark processed so it doesn't jam cron
                    continue; 
                }

                // 1. Find the deepest Target Term (L2) to judge the user against
                $target_term = null;
                $max_depth = -1;
                foreach ($lineage as $tid) {
                    if (isset($depths[$tid]) && $depths[$tid] > $max_depth) {
                        $max_depth = $depths[$tid];
                        $target_term = $tid;
                    }
                }

                if (!$target_term) {
                    $processed_attempt_ids[] = (int)$att->attempt_id;
                    continue;
                }

                // 2. Fetch the Combatants' Stats
                // User's $\theta$ (Defaults to 400 if they have no history)
                $user_ability = $user_mastery_dict[$att->user_id][$target_term] ?? 400.0;
                
                // Question's $H_q$ (Defaults to 500)
                $question_hardness = $question_states[$att->question_id] ?? ($att->auto_hardness ?? 500.0);
                
                $is_correct = (int)$att->is_correct;

                // 3. The Math (Probability of user getting it right)
                $expected_prob = 1 / (1 + pow(10, ($question_hardness - $user_ability) / 400));
                
                // 4. The Calibration Shift
                // If user is correct (1), (P_E - 1) is negative -> Hardness goes DOWN (Easier).
                // If user is wrong (0), (P_E - 0) is positive -> Hardness goes UP (Harder).
                $question_hardness += $k_factor * ($expected_prob - $is_correct);

                // 5. Clamp Elo between 0 and 1000
                $question_states[$att->question_id] = max(0, min(1000, $question_hardness));
                $processed_attempt_ids[] = (int)$att->attempt_id;
            }

            // ==========================================
            // STAGE 5: LOAD TO DB (BULK CASE UPDATE)
            // ==========================================
            $records_updated = 0;
            
            // Chunking into groups of 500 to prevent massive SQL strings from hitting MariaDB limits
            foreach (array_chunk($question_states, 500, true) as $chunk) {
                $cases = "";
                $q_ids = [];
                foreach ($chunk as $q_id => $hq) {
                    $cases .= $wpdb->prepare("WHEN %d THEN %f ", $q_id, $hq);
                    $q_ids[] = (int)$q_id;
                }
                
                $id_list = implode(',', $q_ids);
                $sql_update = "UPDATE {$questions_table} SET auto_hardness = CASE question_id {$cases} END WHERE question_id IN ({$id_list})";
                $wpdb->query($sql_update);
                $records_updated += count($q_ids);
            }

            if (!empty($processed_attempt_ids)) {
                $chunks = array_chunk($processed_attempt_ids, 2000);
                foreach ($chunks as $chunk) {
                    $ids = implode(',', $chunk);
                    $wpdb->query("UPDATE {$attempts_table} SET is_processed_for_hardness = 1 WHERE attempt_id IN ($ids)");
                }
            }

            return ['success' => true, 'message' => sprintf('Auto-Hardness calibrated for %d questions from %d attempts.', $records_updated, count($processed_attempt_ids))];

        } catch (\Exception $e) {
            error_log("QP Hardness Cron Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Finds users active in the last 24 hours and delegates their 
     * recalculation to the unified Practice_Manager function.
     * Runs daily via WP Cron.
     */
    public function calculate_daily_subject_mastery() {
        error_log("QP Cron: Running daily targeted subject mastery calculation...");
        
        global $wpdb;
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // 1. Isolate users active in the last 24 hours
        $active_users = $wpdb->get_col("
            SELECT DISTINCT user_id 
            FROM {$attempts_table} 
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        if (empty($active_users)) {
            error_log("QP Cron: No recent activity found. Mastery sync skipped.");
            return;
        }

        // 2. Delegate to the unified logic engine (Pass array of IDs, and set is_cron to true)
        // Make sure to include the Practice_Manager if it isn't already included in this file
        $result = Admin_Ajax::sync_subject_mastery_data($active_users, true);

        if ($result['success']) {
            error_log("QP Cron: " . $result['message']);
        } else {
            error_log("QP Cron Error: " . $result['message']);
        }
    }
}
