<?php
namespace QuestionPress\Ajax; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use QuestionPress\Database\Terms_DB;
use QuestionPress\Database\Questions_DB;
use QuestionPress\Utils\User_Access;
use WP_Error; // Use statement for WP_Error
use WP_Query; // Use statement for WP_Query

/**
 * Handles AJAX requests related to practice sessions.
 */
class Session_Ajax {

    /**
     * AJAX handler to start a standard or section-wise practice session.
     */
    public static function start_practice_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $pauses_table = $wpdb->prefix . 'qp_session_pauses';

        // --- NEW: Scope Validation ---
        $allowed_subjects = User_Access::get_allowed_subject_ids($user_id);

        if ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            $subjects_raw = isset($_POST['qp_subject']) && is_array($_POST['qp_subject']) ? $_POST['qp_subject'] : [];
            $topics_raw = isset($_POST['qp_topic']) && is_array($_POST['qp_topic']) ? $_POST['qp_topic'] : [];
            $section_id = isset($_POST['qp_section']) && is_numeric($_POST['qp_section']) ? absint($_POST['qp_section']) : 'all'; // Needed for section-wise

            $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function($id){ return $id > 0; });
            $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function($id){ return $id > 0; });

            $practice_mode = ($section_id !== 'all') ? 'Section Wise Practice' : 'normal'; // Determine mode early

            // Validate requested subjects directly (only if 'all' wasn't selected)
            if (!in_array('all', $subjects_raw)) {
                foreach ($requested_subject_ids as $req_subj_id) {
                    if (!in_array($req_subj_id, $allowed_subjects)) {
                        wp_send_json_error(['message' => __('You do not have permission to practice the selected subject.', 'question-press')]);
                        return; // Stop execution
                    }
                }
            }

            // Validate parent subject of requested topics (only if 'all' wasn't selected)
            if (!empty($requested_topic_ids) && !in_array('all', $topics_raw)) {
                $topic_ids_placeholder = implode(',', $requested_topic_ids);
                $parent_subject_ids = $wpdb->get_col("SELECT DISTINCT parent FROM {$wpdb->prefix}qp_terms WHERE term_id IN ($topic_ids_placeholder) AND parent != 0");
                foreach ($parent_subject_ids as $parent_subj_id) {
                    if (!in_array($parent_subj_id, $allowed_subjects)) {
                         wp_send_json_error(['message' => __('You do not have permission to practice the selected topic(s).', 'question-press')]);
                         return; // Stop execution
                    }
                }
            }
            // For Section Wise Practice, check the parent subject of the selected section's topic
             if ($practice_mode === 'Section Wise Practice' && $section_id > 0) {
                 // Find the group linked to the section, then the topic linked to the group, then the subject
                 $group_id = $wpdb->get_var($wpdb->prepare("SELECT object_id FROM {$wpdb->prefix}qp_term_relationships WHERE term_id = %d AND object_type = 'group' LIMIT 1", $section_id));
                 if ($group_id) {
                    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'");
                    $topic_term = $wpdb->get_row($wpdb->prepare("SELECT t.term_id, t.parent FROM {$wpdb->prefix}qp_term_relationships r JOIN {$wpdb->prefix}qp_terms t ON r.term_id = t.term_id WHERE r.object_id = %d AND r.object_type = 'group' AND t.taxonomy_id = %d AND t.parent != 0 LIMIT 1", $group_id, $subject_tax_id));
                    if ($topic_term && !in_array($topic_term->parent, $allowed_subjects)) {
                        wp_send_json_error(['message' => __('You do not have permission to practice this section based on your allowed subjects.', 'question-press')]);
                        return; // Stop execution
                    }
                 }
             }

        }

        // --- Session Settings ---
        $subjects_raw = isset($_POST['qp_subject']) && is_array($_POST['qp_subject']) ? $_POST['qp_subject'] : [];
        $topics_raw = isset($_POST['qp_topic']) && is_array($_POST['qp_topic']) ? $_POST['qp_topic'] : [];
        $section_id = isset($_POST['qp_section']) && is_numeric($_POST['qp_section']) ? absint($_POST['qp_section']) : 'all';

        $practice_mode = ($section_id !== 'all') ? 'Section Wise Practice' : 'normal';

        if ($practice_mode === 'normal' && empty($subjects_raw)) {
            wp_send_json_error(['message' => 'Please select at least one subject.']);
            return;
        }

        $session_settings = [
            'practice_mode'    => $practice_mode,
            'subjects'         => $subjects_raw,
            'topics'           => $topics_raw,
            'section_id'       => $section_id,
            'pyq_only'         => isset($_POST['qp_pyq_only']),
            'include_attempted' => isset($_POST['qp_include_attempted']),
            'marks_correct'    => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
            'marks_incorrect'  => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
            'timer_enabled'    => isset($_POST['qp_timer_enabled']),
            'timer_seconds'    => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
        ];

        // --- Table Names ---
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $a_table = $wpdb->prefix . 'qp_user_attempts';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // *** NEW LOGIC: Find Existing Session BEFORE Building Query ***
        $session_id = 0;
        $is_updating_session = false;
        if ($practice_mode === 'Section Wise Practice') {
            $existing_sessions = $wpdb->get_results($wpdb->prepare("SELECT session_id, settings_snapshot FROM {$sessions_table} WHERE user_id = %d AND status IN ('completed', 'paused')", $user_id));
            foreach ($existing_sessions as $session) {
                $settings = json_decode($session->settings_snapshot, true);
                if (isset($settings['section_id']) && (int)$settings['section_id'] === $section_id) {
                    $session_id = $session->session_id;
                    $is_updating_session = true; // Set our flag
                    break;
                }
            }
        }

        // --- Build Question Pool based on NEW Group Hierarchy ---
        $joins = " FROM {$q_table} q JOIN {$g_table} g ON q.group_id = g.group_id";
        $where_conditions = ["q.status = 'publish'"];

        // 1. Determine the set of TOPIC term IDs to filter by.
        $topic_term_ids_to_filter = [];
        $subjects_selected = !empty($subjects_raw) && !in_array('all', $subjects_raw);
        $topics_selected = !empty($topics_raw) && !in_array('all', $topics_raw);

        if ($topics_selected) {
            $topic_term_ids_to_filter = array_map('absint', $topics_raw);
        } elseif ($subjects_selected) {
            $subject_ids_placeholder = implode(',', array_map('absint', $subjects_raw));
            $topic_term_ids_to_filter = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subject_ids_placeholder)");
        }

        // 2. Find all groups linked to the selected topics (or all topics if no selection).
        if (!empty($topic_term_ids_to_filter)) {
            $topic_ids_placeholder = implode(',', $topic_term_ids_to_filter);
            $where_conditions[] = "g.group_id IN (SELECT object_id FROM {$rel_table} WHERE object_type = 'group' AND term_id IN ($topic_ids_placeholder))";
        }

        // 3. Handle Section selection (which is a type of source term).
        if ($practice_mode === 'Section Wise Practice') {
            $where_conditions[] = $wpdb->prepare("g.group_id IN (SELECT object_id FROM {$rel_table} WHERE object_type = 'group' AND term_id = %d)", $section_id);
        }

        // 4. Apply PYQ filter.
        if ($session_settings['pyq_only']) {
            $where_conditions[] = "g.is_pyq = 1";
        }

        // 5. Exclude previously attempted questions if specified, UNLESS we are updating a session.
        if (!$session_settings['include_attempted'] && !$is_updating_session) {
            $attempted_q_ids_sql = $wpdb->prepare("SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d AND status = 'answered'", $user_id);
            $where_conditions[] = "q.question_id NOT IN ($attempted_q_ids_sql)";
        }

        // 6. Exclude questions with open reports.
        $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
        if (!empty($reported_question_ids)) {
            $reported_ids_placeholder = implode(',', $reported_question_ids);
            $where_conditions[] = "q.question_id NOT IN ($reported_ids_placeholder)";
        }

        // --- Determine Order and Finalize Query ---
        $options = get_option('qp_settings');
        $admin_order_setting = isset($options['question_order']) ? $options['question_order'] : 'random';
        $order_by_sql = '';

        if ($practice_mode === 'Section Wise Practice') {
            $order_by_sql = 'ORDER BY CAST(q.question_number_in_section AS UNSIGNED) ASC, q.question_id ASC';
        } else {
            $order_by_sql = ($admin_order_setting === 'in_order') ? 'ORDER BY q.question_id ASC' : 'ORDER BY RAND()';
        }

        $where_sql = ' WHERE ' . implode(' AND ', $where_conditions);
        $query = "SELECT q.question_id, q.question_number_in_section {$joins} {$where_sql} {$order_by_sql}";

        $question_results = $wpdb->get_results($query);
        $question_ids = wp_list_pluck($question_results, 'question_id');

        // --- Session Creation (Common Logic) ---
        if (empty($question_ids)) {
            wp_send_json_error(['message' => 'No questions were found for the selected criteria. Please try different options.']);
        }

        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
        }

        // Add question numbers to settings for section practice
        if ($practice_mode === 'Section Wise Practice') {
            $session_settings['question_numbers'] = wp_list_pluck($question_results, 'question_number_in_section', 'question_id');
        }

        if ($session_id > 0) {
            // An existing session was found, so we update it.
            // Get the last activity time to use as the pause time.
            $end_time = $wpdb->get_var($wpdb->prepare("SELECT end_time FROM {$sessions_table} WHERE session_id = %d", $session_id));

            if ($end_time) {
                // Add a pause record from the last activity until now.
                $wpdb->insert($pauses_table, [
                    'session_id' => $session_id,
                    'pause_time' => $end_time,
                    'resume_time' => current_time('mysql')
                ]);
            }

            // Now, update the session to be active again.
            $wpdb->update($sessions_table, [
                'status'                  => 'active',
                'last_activity'           => current_time('mysql'),
                'settings_snapshot'       => wp_json_encode($session_settings),
                'question_ids_snapshot'   => wp_json_encode($question_ids)
            ], ['session_id' => $session_id]);
        } else {
            // No existing session found, create a new one
            $wpdb->insert($sessions_table, [
                'user_id'                 => $user_id,
                'status'                  => 'active',
                'start_time'              => current_time('mysql'),
                'last_activity'           => current_time('mysql'),
                'settings_snapshot'       => wp_json_encode($session_settings),
                'question_ids_snapshot'   => wp_json_encode($question_ids)
            ]);
            $session_id = $wpdb->insert_id;
        }

        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    /**
     * AJAX handler to start a special session with incorrectly answered questions.
     */
    public static function start_incorrect_practice_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // Exclude questions with open reports
        $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
        $exclude_sql = !empty($reported_question_ids) ? 'AND q.question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';

        $include_all_incorrect = isset($_POST['include_all_incorrect']) && $_POST['include_all_incorrect'] === 'true';
        $question_ids = [];

        if ($include_all_incorrect) {
            // Mode 1: Get all questions the user has EVER answered incorrectly.
            $question_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT a.question_id
                 FROM {$attempts_table} a
                 JOIN {$questions_table} q ON a.question_id = q.question_id
                 WHERE a.user_id = %d AND a.is_correct = 0 AND q.status = 'publish' {$exclude_sql}",
                $user_id
            ));
        } else {
            // Mode 2: Get questions the user has NEVER answered correctly.
            $correctly_answered_qids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1",
                $user_id
            ));
            $correctly_answered_placeholder = !empty($correctly_answered_qids) ? implode(',', $correctly_answered_qids) : '0';

            $question_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT a.question_id
                 FROM {$attempts_table} a
                 JOIN {$questions_table} q ON a.question_id = q.question_id
                 WHERE a.user_id = %d AND a.status = 'answered' AND q.status = 'publish'
                 AND a.question_id NOT IN ({$correctly_answered_placeholder}) {$exclude_sql}",
                $user_id
            ));
        }

        if (empty($question_ids)) {
            wp_send_json_error(['message' => 'No incorrect questions found to practice.']);
        }

        shuffle($question_ids);

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
        }

        $session_settings = [
            'practice_mode'   => 'Incorrect Que. Practice',
            'marks_correct'   => null,
            'marks_incorrect' => null,
            'timer_enabled'   => false,
        ];

        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => $user_id,
            'status'                  => 'active',
            'start_time'              => current_time('mysql'),
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode(array_values($question_ids))
        ]);
        $session_id = $wpdb->insert_id;

        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    /**
     * AJAX handler to start a MOCK TEST session.
     */
    public static function start_mock_test_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $allowed_subjects = User_Access::get_allowed_subject_ids($user_id);

        // --- Define these variables *before* the scope check ---
        $subjects_raw = isset($_POST['mock_subjects']) && is_array($_POST['mock_subjects']) ? $_POST['mock_subjects'] : [];
        $topics_raw = isset($_POST['mock_topics']) && is_array($_POST['mock_topics']) ? $_POST['mock_topics'] : [];
        $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function($id){ return $id > 0; });
        $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function($id){ return $id > 0; });
        $subjects_selected = !empty($requested_subject_ids) && !in_array('all', $subjects_raw);
        $topics_selected = !empty($requested_topic_ids) && !in_array('all', $topics_raw);
        // --- End variable definitions ---

        // --- Scope Validation ---
        if ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            // Validate requested subjects directly (if 'all' wasn't selected)
            if ($subjects_selected) { // Use the defined variable
                foreach ($requested_subject_ids as $req_subj_id) {
                    if (!in_array($req_subj_id, $allowed_subjects)) {
                        wp_send_json_error(['message' => __('You do not have permission to include the selected subject in the mock test.', 'question-press')]);
                        return; // Stop execution
                    }
                }
            }

            // Validate parent subject of requested topics (if 'all' wasn't selected)
            if ($topics_selected) { // Use the defined variable
                $topic_ids_placeholder = implode(',', $requested_topic_ids);
                $parent_subject_ids = $wpdb->get_col("SELECT DISTINCT parent FROM {$wpdb->prefix}qp_terms WHERE term_id IN ($topic_ids_placeholder) AND parent != 0");
                foreach ($parent_subject_ids as $parent_subj_id) {
                    if (!in_array($parent_subj_id, $allowed_subjects)) {
                         wp_send_json_error(['message' => __('You do not have permission to include the selected topic(s) in the mock test.', 'question-press')]);
                         return; // Stop execution
                    }
                }
            }
        }

        // --- Settings Gathering (Remains the same) ---
        $num_questions = isset($_POST['qp_mock_num_questions']) ? absint($_POST['qp_mock_num_questions']) : 20;
        $distribution = isset($_POST['question_distribution']) ? sanitize_key($_POST['question_distribution']) : 'random';

        $session_settings = [
            'practice_mode'       => 'mock_test',
            'subjects'            => $subjects_raw, // Use raw values here for snapshot
            'topics'              => $topics_raw,   // Use raw values here for snapshot
            'num_questions'       => $num_questions,
            'distribution'        => $distribution,
            'marks_correct'       => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
            'marks_incorrect'     => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
            'timer_enabled'       => true,
            'timer_seconds'       => (isset($_POST['qp_mock_timer_minutes']) ? absint($_POST['qp_mock_timer_minutes']) : 30) * 60,
        ];

        // --- Table Names (Remains the same) ---
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // --- Build the initial query to get a pool of eligible questions ---
        $where_clauses = ["q.status = 'publish'"];
        $query_params = [];
        $joins = "FROM {$q_table} q JOIN {$g_table} g ON q.group_id = g.group_id";

        $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
        if (!empty($reported_question_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $reported_question_ids));
            $where_clauses[] = "q.question_id NOT IN ($ids_placeholder)";
        }

        // **REVISED FIX**: Incorporate scope validation AND user selection into WHERE clause
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
        $joins .= " LEFT JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
        $joins .= " LEFT JOIN {$term_table} topic_term ON topic_rel.term_id = topic_term.term_id AND topic_term.taxonomy_id = " . (int)$subject_tax_id . " AND topic_term.parent != 0";

        $final_topic_ids_to_filter = []; // Topics to actually query

        if ($topics_selected) {
            // User selected specific topics (already scope-validated)
            $final_topic_ids_to_filter = $requested_topic_ids;
        } elseif ($subjects_selected) {
            // User selected specific subjects (already scope-validated)
            // Get all topics under these subjects
            $subj_ids_placeholder = implode(',', $requested_subject_ids);
            $final_topic_ids_to_filter = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subj_ids_placeholder)");
        } elseif ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            // User selected 'All' OR nothing, but has restrictions
             if (!empty($allowed_subjects)) {
                // Get topics under allowed subjects only
                $subj_ids_placeholder = implode(',', $allowed_subjects);
                $final_topic_ids_to_filter = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subj_ids_placeholder)");
             } else {
                 // User has restrictions but no subjects meet them
                 $where_clauses[] = "1=0"; // Ensure no questions are found later
             }
        }
        // If $allowed_subjects === 'all' and user selected 'all' or nothing, $final_topic_ids_to_filter remains empty, meaning don't filter by topic.

        // Apply the topic filter if needed
        if (!empty($final_topic_ids_to_filter)) {
            $ids_placeholder = implode(',', $final_topic_ids_to_filter);
            $where_clauses[] = "topic_term.term_id IN ($ids_placeholder)";
        } elseif ($topics_selected || $subjects_selected || ($allowed_subjects !== 'all' && empty($allowed_subjects))) {
            // If specific topics/subjects were selected but yielded no topic IDs, or scope restrictions yielded no topic IDs
            $where_clauses[] = "1=0"; // Ensure no questions are found
        }
        // If $allowed_subjects === 'all' and no specific subject/topic selected, no topic WHERE clause is added.

        $base_where_sql = implode(' AND ', $where_clauses);
        $query = "SELECT q.question_id, topic_term.term_id as topic_id {$joins} WHERE {$base_where_sql}";
        // **END REVISED FIX**

        $question_pool = $wpdb->get_results($wpdb->prepare($query, $query_params));

        if (empty($question_pool)) {
            wp_send_json_error(['message' => 'No questions were found for the selected criteria. Please try different options.']);
        }

        // --- Apply distribution logic (Remains the same) ---
        $final_question_ids = [];
        $questions_by_topic = [];
        foreach ($question_pool as $q) {
            if ($q->topic_id) {
                $questions_by_topic[$q->topic_id][] = $q->question_id;
            }
        }

        if ($distribution === 'equal' && (!empty($final_topic_ids_to_filter) || ($topics_selected || $subjects_selected))) { // Ensure there's a topic context for equal distribution
            $num_topics = count($questions_by_topic);
            $questions_per_topic = $num_topics > 0 ? floor($num_questions / $num_topics) : 0;
            $remainder = $num_topics > 0 ? $num_questions % $num_topics : 0;

            foreach ($questions_by_topic as $topic_id => $q_ids) {
                shuffle($q_ids);
                $num_to_take = $questions_per_topic;
                if ($remainder > 0) {
                    $num_to_take++;
                    $remainder--;
                }
                $final_question_ids = array_merge($final_question_ids, array_slice($q_ids, 0, $num_to_take));
            }
            // If the equal distribution didn't yield enough questions (e.g., small topics), fill randomly
             $needed = $num_questions - count($final_question_ids);
             if ($needed > 0) {
                 $remaining_pool = array_diff(wp_list_pluck($question_pool, 'question_id'), $final_question_ids);
                 shuffle($remaining_pool);
                 $final_question_ids = array_merge($final_question_ids, array_slice($remaining_pool, 0, $needed));
             }

        } else { // Handle 'random' distribution
            shuffle($question_pool);
            $final_question_ids = array_slice(wp_list_pluck($question_pool, 'question_id'), 0, $num_questions);
        }

        if (empty($final_question_ids)) {
            wp_send_json_error(['html' => '<div class="qp-container"><p>Could not gather enough unique questions for the test. Please select more topics or reduce the number of questions.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
        }

        shuffle($final_question_ids); // Final shuffle for randomness

        // --- Create the session (Remains the same) ---
        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
        }

        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => get_current_user_id(),
            'status'                  => 'mock_test',
            'start_time'              => current_time('mysql'),
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode($final_question_ids) // Store only the final list
        ]);
        $session_id = $wpdb->insert_id;

        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    /**
     * AJAX handler to start a REVISION practice session.
     */
    public static function start_revision_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        global $wpdb;
        $user_id = get_current_user_id();

        // --- Table Names ---
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $revision_table = $wpdb->prefix . 'qp_revision_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $reports_table = $wpdb->prefix . 'qp_question_reports';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

        $topic_ids_to_query = []; // Define this early

        // --- Scope Validation ---
        $allowed_subjects = User_Access::get_allowed_subject_ids($user_id);

        if ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            $subjects_raw = isset($_POST['revision_subjects']) && is_array($_POST['revision_subjects']) ? $_POST['revision_subjects'] : [];
            $topics_raw = isset($_POST['revision_topics']) && is_array($_POST['revision_topics']) ? $_POST['revision_topics'] : [];

            $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function($id){ return $id > 0; });
            $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function($id){ return $id > 0; });
            $subjects_selected = !empty($requested_subject_ids) && !in_array('all', $subjects_raw);
            $topics_selected = !empty($requested_topic_ids) && !in_array('all', $topics_raw);

            // Determine final list of topics being queried based on selection AND scope
            if ($topics_selected) {
                 // Validate parent subjects of requested topics
                 $topic_ids_placeholder = implode(',', $requested_topic_ids);
                 $parent_subject_ids = $wpdb->get_col("SELECT DISTINCT parent FROM {$term_table} WHERE term_id IN ($topic_ids_placeholder) AND parent != 0");
                 $allowed_topics_in_selection = [];
                 foreach ($requested_topic_ids as $req_topic_id) {
                     $parent_subj_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM {$term_table} WHERE term_id = %d", $req_topic_id));
                     if (in_array($parent_subj_id, $allowed_subjects)) {
                          $allowed_topics_in_selection[] = $req_topic_id;
                     }
                 }
                 $topic_ids_to_query = $allowed_topics_in_selection;

            } else if ($subjects_selected) {
                // If subjects were selected, ensure those subjects are allowed
                $allowed_subjects_in_selection = array_intersect($requested_subject_ids, $allowed_subjects);
                if (empty($allowed_subjects_in_selection)) {
                     wp_send_json_error(['message' => __('None of the selected subjects are within your allowed scope.', 'question-press')]);
                     return;
                }
                // Get topics under the allowed selected subjects
                $subj_ids_placeholder = implode(',', $allowed_subjects_in_selection);
                $topic_ids_to_query = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subj_ids_placeholder)");

            } else {
                 // User selected 'All Subjects' or 'All Topics' but isn't admin/unrestricted - use only allowed subjects
                 if (!empty($allowed_subjects)) {
                    $subj_ids_placeholder = implode(',', $allowed_subjects);
                    $topic_ids_to_query = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subj_ids_placeholder)");
                 } else {
                     $topic_ids_to_query = []; // No allowed subjects means no topics
                 }
            }

            // If scope/selection resulted in no topics, show error
             if (empty($topic_ids_to_query) && ($topics_selected || $subjects_selected)) {
                 wp_send_json_error(['message' => __('No topics found within your allowed scope for the selected criteria.', 'question-press')]);
                 return;
            }

        } else {
             // User is admin or unrestricted
             $subjects_raw = isset($_POST['revision_subjects']) && is_array($_POST['revision_subjects']) ? $_POST['revision_subjects'] : [];
             $topics_raw = isset($_POST['revision_topics']) && is_array($_POST['revision_topics']) ? $_POST['revision_topics'] : [];
             $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function($id){ return $id > 0; });
             $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function($id){ return $id > 0; });
             $subjects_selected = !empty($requested_subject_ids) && !in_array('all', $subjects_raw);
             $topics_selected = !empty($requested_topic_ids) && !in_array('all', $topics_raw);

             if ($topics_selected) {
                 $topic_ids_to_query = $requested_topic_ids;
             } elseif ($subjects_selected) {
                 $subj_ids_placeholder = implode(',', $requested_subject_ids);
                 $topic_ids_to_query = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subj_ids_placeholder)");
             } else {
                 // User selected 'All' or nothing, get all topics
                 $topic_ids_to_query = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $subject_tax_id ) );
             }
        }

        // --- Settings Gathering (Remains the same) ---
        $subjects = isset($_POST['revision_subjects']) && is_array($_POST['revision_subjects']) ? $_POST['revision_subjects'] : [];
        $topics = isset($_POST['revision_topics']) && is_array($_POST['revision_topics']) ? $_POST['revision_topics'] : [];
        $questions_per_topic = isset($_POST['qp_revision_questions_per_topic']) ? absint($_POST['qp_revision_questions_per_topic']) : 2; // Default to 2
        $exclude_pyq = isset($_POST['exclude_pyq']);
        $choose_random = isset($_POST['choose_random']);

        $session_settings = [
            'practice_mode'       => 'revision',
            'subjects'            => $subjects,
            'topics'              => $topics,
            'questions_per'       => $questions_per_topic,
            'exclude_pyq'         => $exclude_pyq,
            'marks_correct'       => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
            'marks_incorrect'     => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
            'timer_enabled'       => isset($_POST['qp_timer_enabled']),
            'timer_seconds'       => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
        ];

        // --- Final check if topic list is empty ---
        if (empty($topic_ids_to_query)) {
            wp_send_json_error(['message' => 'Please select at least one subject or topic to revise.']);
        }

        // --- Main Question Selection Logic ---
        $final_question_ids = [];
        $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
        $exclude_reported_sql = !empty($reported_question_ids) ? ' AND q.question_id NOT IN (' . implode(',', array_map('absint', $reported_question_ids)) . ')' : '';

        foreach ($topic_ids_to_query as $topic_id) {
            $pyq_filter_sql = $exclude_pyq ? " AND g.is_pyq = 0" : "";

            // 1. Get the master list of ALL possible questions for this topic
            $master_pool_qids = $wpdb->get_col($wpdb->prepare(
                "SELECT q.question_id
                FROM {$questions_table} q
                JOIN {$groups_table} g ON q.group_id = g.group_id
                JOIN {$rel_table} r ON g.group_id = r.object_id
                WHERE r.term_id = %d AND r.object_type = 'group' AND q.status = 'publish'
                {$pyq_filter_sql} {$exclude_reported_sql}",
                $topic_id
            ));

            if (empty($master_pool_qids)) continue;

            // 2. Get questions already seen in revision for this topic by this user
            $revised_qids_for_topic = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $revision_table WHERE user_id = %d AND topic_id = %d", $user_id, $topic_id));

            // 3. Find the questions that have NOT yet been revised
            $available_qids = array_diff($master_pool_qids, $revised_qids_for_topic);

            // 4. If all questions have been revised, reset the history for this topic and start over
            if (empty($available_qids)) {
                $wpdb->delete($revision_table, ['user_id' => $user_id, 'topic_id' => $topic_id]);
                $available_qids = $master_pool_qids;
            }

            if (!empty($available_qids)) {
                $ids_placeholder = implode(',', array_map('absint', $available_qids));
                $order_by_sql = $choose_random ? "ORDER BY RAND()" : "ORDER BY q.question_id ASC"; // Simple order if not random

                // *** THIS IS THE FIX: Apply LIMIT correctly ***
                $q_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT q.question_id
                     FROM {$questions_table} q
                     WHERE q.question_id IN ($ids_placeholder)
                     {$order_by_sql}
                     LIMIT %d", // Apply limit here
                    $questions_per_topic // Use the variable from settings
                ));
                // *** END FIX ***
                $final_question_ids = array_merge($final_question_ids, $q_ids);
            }
        }

        // --- Create and Start the Session ---
        $question_ids = array_unique($final_question_ids); // Use the collected IDs
        if (empty($question_ids)) {
            wp_send_json_error(['message' => 'No new questions were found for the selected criteria. You may have already revised them all.']);
        }
        shuffle($question_ids); // Shuffle the final combined list

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
        }

        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => $user_id,
            'status'                  => 'active',
            'start_time'              => current_time('mysql'),
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode($question_ids) // Store the final list
        ]);
        $session_id = $wpdb->insert_id;

        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    /**
     * AJAX handler to start a special session with only the questions marked for review.
     */
    public static function start_review_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // Get all question IDs from the user's review list
        $review_question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT question_id FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d ORDER BY review_id ASC",
            $user_id
        ));

        if (empty($review_question_ids)) {
            wp_send_json_error(['message' => 'Your review list is empty.']);
        }

        // Get the Session Page URL from settings
        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
        }

        // Create a special settings snapshot for this review session
        $session_settings = [
            'subject_id'      => 'review', // Special identifier
            'topic_id'        => 'all',
            'sheet_label_id'  => 'all',
            'pyq_only'        => false,
            'revise_mode'     => true, // Treat it as revision
            'marks_correct'   => 1.0,  // Or any default you prefer
            'marks_incorrect' => 0,
            'timer_enabled'   => false,
        ];

        // Create the new session record
        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => $user_id,
            'status'                  => 'active',
            'start_time'              => current_time('mysql'),
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode($review_question_ids)
        ]);
        $session_id = $wpdb->insert_id;

        // Build the redirect URL and send it back
        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    /**
     * AJAX handler to update the last_activity timestamp for a session.
     */
    public static function update_session_activity() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if ($session_id > 0) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'qp_user_sessions',
                ['last_activity' => current_time('mysql')],
                ['session_id' => $session_id]
            );
        }
        wp_send_json_success();
    }

    /**
     * AJAX handler for ending a practice session.
     */
    public static function end_practice_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session.']);
        }

        // Determine the reason based on whether it was a timer-based auto-submission
        $is_auto_submit = isset($_POST['is_auto_submit']) && $_POST['is_auto_submit'] === 'true';
        $end_reason = $is_auto_submit ? 'autosubmitted_timer' : 'user_submitted';

        // Call the shared helper function (assuming qp_finalize_and_end_session is globally available for now)
        $summary_data = qp_finalize_and_end_session($session_id, 'completed', $end_reason);

        if (is_null($summary_data)) {
            wp_send_json_success(['status' => 'no_attempts', 'message' => 'Session deleted as no questions were attempted.']);
        } else {
            wp_send_json_success($summary_data);
        }
    }

    /**
     * AJAX handler to delete an empty/unterminated session record.
     */
    public static function delete_empty_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // Delete the session record from the database
        $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

        wp_send_json_success(['message' => 'Empty session deleted.']);
    }

    /**
     * AJAX Handler to delete a session from user history
     */
    public static function delete_user_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $options = get_option('qp_settings');
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        if (empty(array_intersect($user_roles, $allowed_roles))) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        }
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));

        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'You do not have permission to delete this session.']);
        }

        // Delete the session and its related attempts
        $wpdb->delete($attempts_table, ['session_id' => $session_id], ['%d']);
        $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

        wp_send_json_success(['message' => 'Session deleted.']);
    }

    /**
     * AJAX handler for deleting a user's entire revision and session history.
     */
    public static function delete_revision_history() {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        // **THE FIX**: Add server-side permission check
        $options = get_option('qp_settings');
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        if (empty(array_intersect($user_roles, $allowed_roles))) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        }
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'You must be logged in to do this.']);
        }

        global $wpdb;
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        $wpdb->delete($attempts_table, ['user_id' => $user_id], ['%d']);
        $wpdb->delete($sessions_table, ['user_id' => $user_id], ['%d']);

        wp_send_json_success(['message' => 'Your practice and revision history has been successfully deleted.']);
    }

    /**
     * AJAX handler to pause a session.
     */
    public static function pause_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $user_id = get_current_user_id();

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $pauses_table = $wpdb->prefix . 'qp_session_pauses';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // Update the session status to 'paused' and update the activity time
        $wpdb->update(
            $sessions_table,
            [
                'status' => 'paused',
                'last_activity' => current_time('mysql')
            ],
            ['session_id' => $session_id]
        );

        // Log this pause event in the new table
        $wpdb->insert(
            $pauses_table,
            [
                'session_id' => $session_id,
                'pause_time' => current_time('mysql') // Use GMT time for consistency
            ]
        );

        wp_send_json_success(['message' => 'Session paused successfully.']);
    }

    /**
     * AJAX handler to terminate an active session.
     */
    public static function terminate_session() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $user_id = get_current_user_id();

        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Security check: ensure the session belongs to the current user
        $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
        if ((int)$session_owner !== $user_id) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        // --- THE FIX: Delete the session and its attempts directly ---
        $wpdb->delete($attempts_table, ['session_id' => $session_id]);
        $wpdb->delete($sessions_table, ['session_id' => $session_id]);

        wp_send_json_success(['message' => 'Session terminated and removed successfully.']);
    }

    /**
     * AJAX handler to start a Test Series session launched from a course item.
     * Includes access check using entitlements table. Decrement happens on first answer.
     */
    public static function start_course_test_series() {
        check_ajax_referer('qp_start_course_test_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }
        $user_id = get_current_user_id();

        // --- Get Item ID first ---
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if (!$item_id) {
            wp_send_json_error(['message' => 'Invalid course item ID.']);
        }

        // --- NEW: Entitlement Check ONLY ---
        global $wpdb;
        $items_table = $wpdb->prefix . 'qp_course_items';

        // --- Get Course ID associated with the item ---
        $course_id = $wpdb->get_var($wpdb->prepare("SELECT course_id FROM $items_table WHERE item_id = %d", $item_id));
        if (!$course_id) {
            wp_send_json_error(['message' => 'Could not determine the course for this item.']);
            return;
        }
        // --- END Get Course ID ---


        // --- NEW: Check Course Access FIRST ---
        // CHANGED: Use the new User_Access class method
        if (!\QuestionPress\Utils\User_Access::can_access_course($user_id, $course_id)) {
            wp_send_json_error(['message' => 'You do not have access to start tests in this course.', 'code' => 'access_denied']);
            return; // Stop execution
        }
        // --- Proceed with Attempt Check (Existing Logic from previous step) ---
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $current_time = current_time('mysql');
        $has_access_for_attempt = false; // Renamed variable for clarity

        $active_entitlements_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(entitlement_id)
             FROM {$entitlements_table}
             WHERE user_id = %d
             AND status = 'active'
             AND (expiry_date IS NULL OR expiry_date > %s)
             AND (remaining_attempts IS NULL OR remaining_attempts > 0)",
            $user_id,
            $current_time
        ));

        if ($active_entitlements_count > 0) {
            $has_access_for_attempt = true;
        }

        if (!$has_access_for_attempt) {
            error_log("QP Course Test Start: User #{$user_id} denied access. No suitable active entitlement found for attempt.");
            wp_send_json_error([
                'message' => 'You have run out of attempts or your subscription has expired.',
                'code' => 'access_denied' // Keep same code, JS handles message
            ]);
            return;
        }
        // --- END NEW Entitlement Check ---

        // --- Proceed with starting the session (Original logic) ---

        // Get the item details and configuration
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT course_id, content_config FROM $items_table WHERE item_id = %d AND content_type = 'test_series'",
            $item_id
        ));

        if (!$item || empty($item->content_config)) {
            wp_send_json_error(['message' => 'Could not find test configuration for this item.']);
        }

        $config = json_decode($item->content_config, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($config)) {
             error_log("QP Course Test Start: Invalid JSON in content_config for item ID: " . $item_id . ". Error: " . json_last_error_msg());
            wp_send_json_error(['message' => 'Invalid test configuration data stored. Please contact an administrator.']);
            return;
        }

        // --- Determine Question IDs ---
        $final_question_ids = [];
        $q_table = $wpdb->prefix . 'qp_questions';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        if (isset($config['selected_questions']) && is_array($config['selected_questions']) && !empty($config['selected_questions'])) {
            $potential_ids = array_map('absint', $config['selected_questions']);
            if (!empty($potential_ids)) {
                $ids_placeholder = implode(',', $potential_ids);
                $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
                $exclude_reported_sql = !empty($reported_question_ids) ? ' AND question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';
                $verified_ids = $wpdb->get_col("SELECT question_id FROM {$q_table} WHERE question_id IN ($ids_placeholder) AND status = 'publish' {$exclude_reported_sql}");
                $final_question_ids = array_intersect($potential_ids, $verified_ids);
            }
        } else {
            wp_send_json_error(['message' => 'No questions have been manually selected for this test item. Please edit the course.']);
            return;
        }

        if (empty($final_question_ids)) {
             wp_send_json_error(['message' => 'None of the selected questions are currently available.']);
             return;
        }

        shuffle($final_question_ids);

        // --- Prepare Session Settings ---
        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
        }

        $session_settings = [
            'practice_mode'       => 'mock_test',
            'course_id'           => $item->course_id,
            'item_id'             => $item_id,
            'num_questions'       => count($final_question_ids),
            'marks_correct'       => $config['scoring_enabled'] ? ($config['marks_correct'] ?? 1) : null,
            'marks_incorrect'     => $config['scoring_enabled'] ? -abs($config['marks_incorrect'] ?? 0) : null,
            'timer_enabled'       => ($config['time_limit'] > 0),
            'timer_seconds'       => ($config['time_limit'] ?? 0) * 60,
            'original_selection'  => $config['selected_questions'] ?? [],
        ];

        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => $user_id,
            'status'                  => 'mock_test',
            'start_time'              => $current_time, // Use current time
            'last_activity'           => $current_time,
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode(array_values($final_question_ids))
        ]);
        $session_id = $wpdb->insert_id;

        if (!$session_id) {
             wp_send_json_error(['message' => 'Failed to create the session record.']);
        }

        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        wp_send_json_success(['redirect_url' => $redirect_url, 'session_id' => $session_id]);
    }


} // End class Session_Ajax