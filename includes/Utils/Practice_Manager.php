<?php

namespace QuestionPress\Utils;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use WP_Error;
use QuestionPress\Utils\User_Access;

/**
 * Handles the core business logic for creating and managing practice sessions.
 */
class Practice_Manager
{

    /**
     * Starts a MOCK TEST session.
     *
     * @param array $params The parameters for the session, typically from $_POST.
     * @return array|WP_Error An array with the redirect URL on success, or a WP_Error on failure.
     */
    public static function start_mock_test_session($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        // --- BEGIN NEW: MOCK TEST ATTEMPT CHECK ---
        $current_time = current_time('mysql');
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $num_questions = isset($params['qp_mock_num_questions']) ? absint($params['qp_mock_num_questions']) : 20;

        // Check for general practice entitlements
        $active_entitlements = $wpdb->get_results($wpdb->prepare(
            "SELECT entitlement_id, remaining_attempts
			 FROM {$entitlements_table}
			 WHERE user_id = %d AND status = 'active' AND (expiry_date IS NULL OR expiry_date > %s)
			 AND (remaining_attempts IS NULL OR remaining_attempts > 0)",
            $user_id,
            $current_time
        ));

        $has_access = false;
        $has_unlimited_attempts = false;
        $total_remaining = 0;

        if (!empty($active_entitlements)) {
            foreach ($active_entitlements as $entitlement) {
                if (is_null($entitlement->remaining_attempts)) {
                    $has_unlimited_attempts = true;
                    $has_access = true;
                    break; // Found unlimited plan, stop checking
                }
                $total_remaining += (int)$entitlement->remaining_attempts;
            }

            if (!$has_unlimited_attempts && $total_remaining > 0) {
                $has_access = true; // Has a finite number of attempts
            }
        }

        // If user has no access at all (no plans)
        if (!$has_access) {
            return new WP_Error('no_plan', __('You do not have an active plan to start a mock test.', 'question-press'));
        }

        // If user has finite attempts, check if they have enough
        if (!$has_unlimited_attempts && $total_remaining < $num_questions) {
            return new WP_Error('insufficient_attempts', sprintf(
                __('You do not have enough attempts remaining for this test. You need %d attempts but only have %d.', 'question-press'),
                $num_questions,
                $total_remaining
            ));
        }
        // --- END NEW: MOCK TEST ATTEMPT CHECK ---
        $allowed_subjects = User_Access::get_allowed_subject_ids($user_id);

        // --- Define these variables *before* the scope check ---
        $subjects_raw = isset($params['mock_subjects']) && is_array($params['mock_subjects']) ? $params['mock_subjects'] : [];
        $topics_raw = isset($params['mock_topics']) && is_array($params['mock_topics']) ? $params['mock_topics'] : [];
        $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function ($id) {
            return $id > 0;
        });
        $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function ($id) {
            return $id > 0;
        });
        $subjects_selected = !empty($requested_subject_ids) && !in_array('all', $subjects_raw);
        $topics_selected = !empty($requested_topic_ids) && !in_array('all', $topics_raw);
        // --- End variable definitions ---

        // --- Scope Validation ---
        if ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            // Validate requested subjects directly (if 'all' wasn't selected)
            if ($subjects_selected) { // Use the defined variable
                foreach ($requested_subject_ids as $req_subj_id) {
                    if (!in_array($req_subj_id, $allowed_subjects)) {
                        return new WP_Error('permission_denied', __('You do not have permission to include the selected subject in the mock test.', 'question-press'));
                    }
                }
            }

            // Validate parent subject of requested topics (if 'all' wasn't selected)
            if ($topics_selected) { // Use the defined variable
                $topic_ids_placeholder = implode(',', $requested_topic_ids);
                $parent_subject_ids = $wpdb->get_col("SELECT DISTINCT parent FROM {$wpdb->prefix}qp_terms WHERE term_id IN ($topic_ids_placeholder) AND parent != 0");
                foreach ($parent_subject_ids as $parent_subj_id) {
                    if (!in_array($parent_subj_id, $allowed_subjects)) {
                        return new WP_Error('permission_denied', __('You do not have permission to include the selected topic(s) in the mock test.', 'question-press'));
                    }
                }
            }
        }

        // --- Settings Gathering (Remains the same) ---
        $num_questions = isset($params['qp_mock_num_questions']) ? absint($params['qp_mock_num_questions']) : 20;
        $distribution = isset($params['question_distribution']) ? sanitize_key($params['question_distribution']) : 'random';

        $session_settings = [
            'practice_mode'       => 'mock_test',
            'subjects'            => $subjects_raw, // Use raw values here for snapshot
            'topics'              => $topics_raw,   // Use raw values here for snapshot
            'num_questions'       => $num_questions,
            'distribution'        => $distribution,
            'marks_correct'       => isset($params['scoring_enabled']) ? floatval($params['qp_marks_correct']) : null,
            'marks_incorrect'     => isset($params['scoring_enabled']) ? -abs(floatval($params['qp_marks_incorrect'])) : null,
            'timer_enabled'       => true,
            'timer_seconds'       => (isset($params['qp_mock_timer_minutes']) ? absint($params['qp_mock_timer_minutes']) : 30) * 60,
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
        $query = "SELECT DISTINCT q.question_id, topic_term.term_id as topic_id {$joins} WHERE {$base_where_sql}";

        $question_pool = $wpdb->get_results($wpdb->prepare($query, $query_params));

        if (empty($question_pool)) {
            return new WP_Error('no_questions_found', 'No questions were found for the selected criteria. Please try different options.');
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
            return new WP_Error('not_enough_questions', '<div class="qp-container"><p>Could not gather enough unique questions for the test. Please select more topics or reduce the number of questions.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>', ['is_html' => true]);
        }

        $final_question_ids = array_unique($final_question_ids);
        shuffle($final_question_ids); // Final shuffle for randomness

        // --- Create the session (Remains the same) ---
        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            return new WP_Error('no_session_page', 'The administrator has not configured a session page.');
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
        return ['redirect_url' => $redirect_url];
    }

    /**
     * Starts a REVISION practice session.
     *
     * @param array $params The parameters for the session, typically from $_POST.
     * @return array|WP_Error An array with the redirect URL on success, or a WP_Error on failure.
     */
    public static function start_revision_session($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // --- Table Names ---
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $revision_table = $wpdb->prefix . 'qp_revision_attempts';
        $options = get_option('qp_settings');
        $global_question_limit = isset($options['normal_practice_limit']) ? absint($options['normal_practice_limit']) : 100;
        if ($global_question_limit <= 0) $global_question_limit = 100; // Failsafe
        $questions_table = $wpdb->prefix . 'qp_questions';
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $reports_table = $wpdb->prefix . 'qp_question_reports';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

        $topic_ids_to_query = []; // Define this early

        // --- Scope Validation ---
        $allowed_subjects = User_Access::get_allowed_subject_ids($user_id);

        if ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            $subjects_raw = isset($params['revision_subjects']) && is_array($params['revision_subjects']) ? $params['revision_subjects'] : [];
            $topics_raw = isset($params['revision_topics']) && is_array($params['revision_topics']) ? $params['revision_topics'] : [];

            $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function ($id) {
                return $id > 0;
            });
            $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function ($id) {
                return $id > 0;
            });
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
                    return new WP_Error('no_scoped_subjects', __('None of the selected subjects are within your allowed scope.', 'question-press'));
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
                return new WP_Error('no_scoped_topics', __('No topics found within your allowed scope for the selected criteria.', 'question-press'));
            }
        } else {
            // User is admin or unrestricted
            $subjects_raw = isset($params['revision_subjects']) && is_array($params['revision_subjects']) ? $params['revision_subjects'] : [];
            $topics_raw = isset($params['revision_topics']) && is_array($params['revision_topics']) ? $params['revision_topics'] : [];
            $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function ($id) {
                return $id > 0;
            });
            $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function ($id) {
                return $id > 0;
            });
            $subjects_selected = !empty($requested_subject_ids) && !in_array('all', $subjects_raw);
            $topics_selected = !empty($requested_topic_ids) && !in_array('all', $topics_raw);

            if ($topics_selected) {
                $topic_ids_to_query = $requested_topic_ids;
            } elseif ($subjects_selected) {
                $subj_ids_placeholder = implode(',', $requested_subject_ids);
                $topic_ids_to_query = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subj_ids_placeholder)");
            } else {
                // User selected 'All' or nothing, get all topics
                $topic_ids_to_query = $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $subject_tax_id));
            }
        }

        // --- Settings Gathering (Remains the same) ---
        $subjects = isset($params['revision_subjects']) && is_array($params['revision_subjects']) ? $params['revision_subjects'] : [];
        $topics = isset($params['revision_topics']) && is_array($params['revision_topics']) ? $params['revision_topics'] : [];
        $questions_per_topic = isset($params['qp_revision_questions_per_topic']) ? absint($params['qp_revision_questions_per_topic']) : 2; // Default to 2
        $exclude_pyq = isset($params['exclude_pyq']);
        $choose_random = isset($params['choose_random']);

        $session_settings = [
            'practice_mode'       => 'revision',
            'subjects'            => $subjects,
            'topics'              => $topics,
            'questions_per'       => $questions_per_topic,
            'exclude_pyq'         => $exclude_pyq,
            'marks_correct'       => isset($params['scoring_enabled']) ? floatval($params['qp_marks_correct']) : null,
            'marks_incorrect'     => isset($params['scoring_enabled']) ? -abs(floatval($params['qp_marks_incorrect'])) : null,
            'timer_enabled'       => isset($params['qp_timer_enabled']),
            'timer_seconds'       => isset($params['qp_timer_seconds']) ? absint($params['qp_timer_seconds']) : 60
        ];

        // --- Final check if topic list is empty ---
        if (empty($topic_ids_to_query)) {
            return new WP_Error('no_topics_selected', 'Please select at least one subject or topic to revise.');
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
                // Remove ORDER BY RAND() and LIMIT from the SQL query
                $order_by_sql = $choose_random ? "" : "ORDER BY q.question_id ASC";

                // Get ALL available q_ids for this topic
                $q_ids = $wpdb->get_col(
                    "SELECT q.question_id
                         FROM {$questions_table} q
                         WHERE q.question_id IN ($ids_placeholder)
                         {$order_by_sql}"
                );

                // Shuffle in PHP if requested
                if ($choose_random) {
                    shuffle($q_ids);
                }

                // Apply the limit *after* shuffling (or ordering)
                $q_ids_to_add = array_slice($q_ids, 0, $questions_per_topic);
                $final_question_ids = array_merge($final_question_ids, $q_ids_to_add);
            }
        }

        $question_ids = array_unique($final_question_ids); // Use the collected IDs
        if (empty($question_ids)) {
            return new WP_Error('no_new_questions', 'No new questions were found for the selected criteria. You may have already revised them all.');
        }

        // --- NEW: Shuffle the list first ---
        shuffle($question_ids);

        // --- NEW: Apply the global limit to the final shuffled list ---
        if (count($question_ids) > $global_question_limit) {
            $question_ids = array_slice($question_ids, 0, $global_question_limit);
        }
        // --- END NEW ---

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            return new WP_Error('no_session_page', 'The administrator has not configured a session page.');
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
        return ['redirect_url' => $redirect_url];
    }

    /**
     * Starts a standard or section-wise practice session.
     *
     * @param array $params The parameters for the session, typically from $_POST.
     * @return array|WP_Error An array with the redirect URL on success, or a WP_Error on failure.
     */
    public static function start_practice_session($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $pauses_table = $wpdb->prefix . 'qp_session_pauses';

        // --- NEW: Scope Validation ---
        $allowed_subjects = User_Access::get_allowed_subject_ids($user_id);

        if ($allowed_subjects !== 'all' && is_array($allowed_subjects)) {
            $subjects_raw = isset($params['qp_subject']) && is_array($params['qp_subject']) ? $params['qp_subject'] : [];
            $topics_raw = isset($params['qp_topic']) && is_array($params['qp_topic']) ? $params['qp_topic'] : [];
            $section_id = isset($params['qp_section']) && is_numeric($params['qp_section']) ? absint($params['qp_section']) : 'all'; // Needed for section-wise

            $requested_subject_ids = array_filter(array_map('absint', $subjects_raw), function ($id) {
                return $id > 0;
            });
            $requested_topic_ids = array_filter(array_map('absint', $topics_raw), function ($id) {
                return $id > 0;
            });

            $practice_mode = ($section_id !== 'all') ? 'Section Wise Practice' : 'normal'; // Determine mode early

            // Validate requested subjects directly (only if 'all' wasn't selected)
            if (!in_array('all', $subjects_raw)) {
                foreach ($requested_subject_ids as $req_subj_id) {
                    if (!in_array($req_subj_id, $allowed_subjects)) {
                        return new WP_Error('permission_denied', __('You do not have permission to practice the selected subject.', 'question-press'));
                    }
                }
            }

            // Validate parent subject of requested topics (only if 'all' wasn't selected)
            if (!empty($requested_topic_ids) && !in_array('all', $topics_raw)) {
                $topic_ids_placeholder = implode(',', $requested_topic_ids);
                $parent_subject_ids = $wpdb->get_col("SELECT DISTINCT parent FROM {$wpdb->prefix}qp_terms WHERE term_id IN ($topic_ids_placeholder) AND parent != 0");
                foreach ($parent_subject_ids as $parent_subj_id) {
                    if (!in_array($parent_subj_id, $allowed_subjects)) {
                        return new WP_Error('permission_denied', __('You do not have permission to practice the selected topic(s).', 'question-press'));
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
                        return new WP_Error('permission_denied', __('You do not have permission to practice this section based on your allowed subjects.', 'question-press'));
                    }
                }
            }
        }

        // --- Session Settings ---
        $subjects_raw = isset($params['qp_subject']) && is_array($params['qp_subject']) ? $params['qp_subject'] : [];
        $topics_raw = isset($params['qp_topic']) && is_array($params['qp_topic']) ? $params['qp_topic'] : [];
        $section_id = isset($params['qp_section']) && is_numeric($params['qp_section']) ? absint($params['qp_section']) : 'all';

        $practice_mode = ($section_id !== 'all') ? 'Section Wise Practice' : 'normal';

        if ($practice_mode === 'normal' && empty($subjects_raw)) {
            return new WP_Error('no_subject_selected', 'Please select at least one subject.');
        }

        $session_settings = [
            'practice_mode'    => $practice_mode,
            'subjects'         => $subjects_raw,
            'topics'           => $topics_raw,
            'section_id'       => $section_id,
            'pyq_only'         => isset($params['qp_pyq_only']),
            'marks_correct'    => isset($params['scoring_enabled']) ? floatval($params['qp_marks_correct']) : null,
            'marks_incorrect'  => isset($params['scoring_enabled']) ? -abs(floatval($params['qp_marks_incorrect'])) : null,
            'timer_enabled'    => isset($params['qp_timer_enabled']),
            'timer_seconds'    => isset($params['qp_timer_seconds']) ? absint($params['qp_timer_seconds']) : 60
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

        // --- Get Admin Settings ---
        $options = get_option('qp_settings');
        $admin_order_setting = isset($options['question_order']) ? $options['question_order'] : 'random';
        $admin_max_limit = isset($options['normal_practice_limit']) ? absint($options['normal_practice_limit']) : 100;

        $question_results = null; // Used for section-wise mode later
        $question_ids = [];

        if ($practice_mode === 'normal') {
            // --- NEW LOGIC FOR NORMAL PRACTICE ---

            // 1. Determine final question limit
            $user_requested_limit = isset($params['qp_normal_practice_limit']) ? absint($params['qp_normal_practice_limit']) : $admin_max_limit;
            $final_limit = min($user_requested_limit, $admin_max_limit);
            if ($final_limit <= 0) $final_limit = 100; // Failsafe

            // 2. Build args for the new DB function
            $db_args = [
                // Handle 'all' by sending an empty array
                'subject_ids' => (!empty($subjects_raw) && !in_array('all', $subjects_raw)) ? array_map('absint', $subjects_raw) : [],
                'topic_ids'   => (!empty($topics_raw) && !in_array('all', $topics_raw)) ? array_map('absint', $topics_raw) : [],
                'pyq_only'    => $session_settings['pyq_only']
            ];

            // Prioritize topics over subjects if both are somehow selected
            if (!empty($db_args['topic_ids'])) {
                $db_args['subject_ids'] = [];
            }

            // 3. Get the full question pool using the new denormalized function
            $full_question_pool = \QuestionPress\Database\Questions_DB::get_all_question_ids_for_practice($db_args);

            if (!empty($full_question_pool)) {
                // 4. Randomize the full pool in PHP
                if ($admin_order_setting === 'random') {
                    shuffle($full_question_pool);
                }
                // (If 'in_order', we respect the DB order which is likely question_id ASC)

                // 5. Slice to get the final set of questions
                $question_ids = array_slice($full_question_pool, 0, $final_limit);
            }
        } else {
            // --- UPDATED LOGIC FOR SECTION WISE PRACTICE ---
            $joins = " FROM {$q_table} q JOIN {$g_table} g ON q.group_id = g.group_id";
            $where_conditions = ["q.status = 'publish'"];

            // 1. Find all groups linked to the selected topics (if any)
            $topic_term_ids_to_filter = [];
            $subjects_selected = !empty($subjects_raw) && !in_array('all', $subjects_raw);
            $topics_selected = !empty($topics_raw) && !in_array('all', $topics_raw);

            if ($topics_selected) {
                $topic_term_ids_to_filter = array_map('absint', $topics_raw);
            } elseif ($subjects_selected) {
                $subject_ids_placeholder = implode(',', array_map('absint', $subjects_raw));
                $topic_term_ids_to_filter = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subject_ids_placeholder)");
            }
            if (!empty($topic_term_ids_to_filter)) {
                $topic_ids_placeholder = implode(',', $topic_term_ids_to_filter);
                $where_conditions[] = "g.group_id IN (SELECT object_id FROM {$rel_table} WHERE object_type = 'group' AND term_id IN ($topic_ids_placeholder))";
            }

            // 2. Handle Section selection
            $where_conditions[] = $wpdb->prepare("g.group_id IN (SELECT object_id FROM {$rel_table} WHERE object_type = 'group' AND term_id = %d)", $section_id);

            // 3. Apply PYQ filter
            if ($session_settings['pyq_only']) {
                $where_conditions[] = "g.is_pyq = 1";
            }

            // 4. Exclude questions with open reports
            $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
            if (!empty($reported_question_ids)) {
                $reported_ids_placeholder = implode(',', $reported_question_ids);
                $where_conditions[] = "q.question_id NOT IN ($reported_ids_placeholder)";
            }

            // 5. Get all questions, ordered by section number
            $order_by_sql = 'ORDER BY CAST(q.question_number_in_section AS UNSIGNED) ASC, q.question_id ASC';
            $where_sql = ' WHERE ' . implode(' AND ', $where_conditions);

            $query = "SELECT DISTINCT q.question_id, q.question_number_in_section {$joins} {$where_sql} {$order_by_sql}";

            $question_results = $wpdb->get_results($query);
            $question_ids = wp_list_pluck($question_results, 'question_id');
        }

        if (empty($question_ids)) {
            return new WP_Error('no_questions_found', 'No questions were found for the selected criteria. Please try different options.');
        }

        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            return new WP_Error('no_session_page', 'The administrator has not configured a session page.');
        }

        // Add question numbers to settings for section practice
        if ($practice_mode === 'Section Wise Practice') {
            $session_settings['question_numbers'] = wp_list_pluck($question_results, 'question_number_in_section', 'question_id');
        }

        if ($session_id > 0) {
            // An existing session was found, so we update it.
            $end_time = $wpdb->get_var($wpdb->prepare("SELECT end_time FROM {$sessions_table} WHERE session_id = %d", $session_id));

            if ($end_time) {
                $wpdb->insert($pauses_table, [
                    'session_id' => $session_id,
                    'pause_time' => $end_time,
                    'resume_time' => current_time('mysql')
                ]);
            }

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
        return ['redirect_url' => $redirect_url];
    }

    /**
     * Starts a special session with incorrectly answered questions.
     *
     * @param array $params The parameters for the session, typically from $_POST.
     * @return array|WP_Error An array with the redirect URL on success, or a WP_Error on failure.
     */
    public static function start_incorrect_practice_session($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // Exclude questions with open reports
        $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
        $exclude_sql = !empty($reported_question_ids) ? 'AND q.question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';

        $include_all_incorrect = isset($params['include_all_incorrect']) && $params['include_all_incorrect'] === 'true';
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
            return new WP_Error('no_incorrect_questions', 'No incorrect questions found to practice.');
        }

        shuffle($question_ids);

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            return new WP_Error('no_session_page', 'The administrator has not configured a session page.');
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
        return ['redirect_url' => $redirect_url];
    }

    /**
     * Starts a special session with only the questions marked for review.
     *
     * @param array $params The parameters for the session, typically from $_POST.
     * @return array|WP_Error An array with the redirect URL on success, or a WP_Error on failure.
     */
    public static function start_review_session($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // Get all question IDs from the user's review list
        $review_question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d ORDER BY review_id ASC",
            $user_id
        ));

        if (empty($review_question_ids)) {
            return new WP_Error('review_list_empty', 'Your review list is empty.');
        }

        // Get the Session Page URL from settings
        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            return new WP_Error('no_session_page', 'The administrator has not configured a session page.');
        }

        // Create a special settings snapshot for this review session
        $session_settings = [
            'subject_id'      => 'review', // Special identifier
            'topic_id'        => 'all',
            'sheet_label_id'  => 'all',
            'pyq_only'        => false,
            'revise_mode'     => true, // Treat it as revision
            'marks_correct'   => null,  // Or any default you prefer
            'marks_incorrect' => null,
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
        return ['redirect_url' => $redirect_url];
    }

    /**
     * Starts a test series session for a specific course.
     *
     * @param array $params The parameters for the session, typically from $_POST.
     * @return array|WP_Error An array with the redirect URL on success, or a WP_Error on failure.
     */
    public static function start_course_test_series($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $course_id = isset($params['course_id']) ? absint($params['course_id']) : 0;
        $test_id = isset($params['test_id']) ? absint($params['test_id']) : 0;

        if (empty($course_id) || empty($test_id)) {
            return new WP_Error('invalid_parameters', 'Invalid course or test ID.');
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // Check if the user is enrolled in the course
        if (!\QuestionPress\Utils\User_Access::is_enrolled($user_id, $course_id)) {
            return new WP_Error('not_enrolled', 'You are not enrolled in this course.');
        }

        // Get test details from course meta
        $tests = get_post_meta($course_id, 'qp_course_tests', true);
        if (empty($tests) || !isset($tests[$test_id])) {
            return new WP_Error('test_not_found', 'The selected test could not be found in this course.');
        }
        $test = $tests[$test_id];

        // Get question IDs from the test settings
        $question_ids = !empty($test['questions']) ? array_map('absint', $test['questions']) : [];
        if (empty($question_ids)) {
            return new WP_Error('no_questions_in_test', 'This test contains no questions.');
        }

        // Get the Session Page URL from settings
        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        if (!$session_page_id) {
            return new WP_Error('no_session_page', 'The administrator has not configured a session page.');
        }

        // Create the session settings snapshot
        $session_settings = [
            'practice_mode'   => 'course_test',
            'course_id'       => $course_id,
            'test_id'         => $test_id,
            'test_name'       => $test['name'],
            'marks_correct'   => isset($test['marks_correct']) ? floatval($test['marks_correct']) : 1,
            'marks_incorrect' => isset($test['marks_incorrect']) ? -abs(floatval($test['marks_incorrect'])) : 0,
            'timer_enabled'   => isset($test['timer_enabled']) && $test['timer_enabled'],
            'timer_seconds'   => isset($test['duration']) ? absint($test['duration']) * 60 : 0,
        ];

        // Create the new session record
        $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
            'user_id'                 => $user_id,
            'status'                  => 'active',
            'start_time'              => current_time('mysql'),
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode($question_ids)
        ]);
        $session_id = $wpdb->insert_id;

        // Build the redirect URL
        $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
        return ['redirect_url' => $redirect_url];
    }

    /**
     * Checks an answer for a non-mock test session.
     *
     * @param array $params The parameters for the check, typically from $_POST.
     * @return array|WP_Error An array with the result on success, or a WP_Error on failure.
     */
    public static function check_answer($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $current_time = current_time('mysql');

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        if (!$session_id) {
            return new WP_Error('invalid_session_id', 'Invalid session ID.');
        }

        // 1. Get the session settings
        $session_settings_json = $wpdb->get_var($wpdb->prepare("SELECT settings_snapshot FROM {$wpdb->prefix}qp_user_sessions WHERE session_id = %d", $session_id));
        $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $entitlement_to_decrement = null;
        $has_access = false;

        // 2. Check what kind of session this is
        if (isset($settings['course_id']) && $settings['course_id'] > 0) {
            if (User_Access::can_access_course($user_id, $settings['course_id'])) {
                $has_access = true;
            }
        } else {
            $active_entitlements = $wpdb->get_results($wpdb->prepare(
                "SELECT entitlement_id, remaining_attempts
                                     FROM {$entitlements_table}
                                     WHERE user_id = %d AND status = 'active' AND (expiry_date IS NULL OR expiry_date > %s)
                                     ORDER BY remaining_attempts ASC, expiry_date ASC",
                $user_id,
                $current_time
            ));

            if (!empty($active_entitlements)) {
                foreach ($active_entitlements as $entitlement) {
                    if (!is_null($entitlement->remaining_attempts)) {
                        if ((int)$entitlement->remaining_attempts > 0) {
                            $entitlement_to_decrement = $entitlement;
                            $has_access = true;
                            break;
                        }
                    } else {
                        $has_access = true; // Unlimited plan
                        break;
                    }
                }
            }
        }

        if (!$has_access) {
            return new WP_Error('access_denied', 'You do not have access to perform this action. Your plan may have expired or you may be out of attempts.');
        }

        if ($entitlement_to_decrement) {
            $new_attempts = max(0, (int)$entitlement_to_decrement->remaining_attempts - 1);
            $wpdb->update(
                $entitlements_table,
                ['remaining_attempts' => $new_attempts],
                ['entitlement_id' => $entitlement_to_decrement->entitlement_id]
            );

            if ($new_attempts === 0) {
                $wpdb->update(
                    $entitlements_table,
                    ['status' => 'expired'],
                    ['entitlement_id' => $entitlement_to_decrement->entitlement_id]
                );
            }
        }

        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        $option_id = isset($params['option_id']) ? absint($params['option_id']) : 0;

        if (!$question_id || !$option_id) {
            return new WP_Error('invalid_data', 'Invalid data submitted.');
        }

        $o_table = $wpdb->prefix . 'qp_options';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $revision_table = $wpdb->prefix . 'qp_revision_attempts';

        $wpdb->update($sessions_table, ['last_activity' => $current_time], ['session_id' => $session_id]);

        $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
        $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));

        $wpdb->replace(
            $attempts_table,
            [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'question_id' => $question_id,
                'selected_option_id' => $option_id,
                'is_correct' => $is_correct ? 1 : 0,
                'status' => 'answered',
                'mock_status' => null,
                'remaining_time' => isset($params['remaining_time']) ? absint($params['remaining_time']) : null,
                'attempt_time' => $current_time
            ]
        );
        $attempt_id = $wpdb->insert_id;

        if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'revision') {
            $q_table = $wpdb->prefix . 'qp_questions';
            $rel_table = $wpdb->prefix . 'qp_term_relationships';
            $term_table = $wpdb->prefix . 'qp_terms';
            $tax_table = $wpdb->prefix . 'qp_taxonomies';
            $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

            $topic_id = $wpdb->get_var($wpdb->prepare(
                "SELECT r.term_id
                                          FROM {$q_table} q
                                          JOIN {$rel_table} r ON q.group_id = r.object_id AND r.object_type = 'group'
                                          JOIN {$term_table} t ON r.term_id = t.term_id
                                          WHERE q.question_id = %d AND t.taxonomy_id = %d AND t.parent != 0",
                $question_id,
                $subject_tax_id
            ));

            if ($topic_id) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$revision_table} (user_id, question_id, topic_id) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE attempt_date = NOW()",
                    $user_id,
                    $question_id,
                    $topic_id
                ));
            }
        }

        return [
            'is_correct' => $is_correct,
            'correct_option_id' => $correct_option_id,
            'attempt_id' => $attempt_id
        ];
    }

    /**
     * Saves a user's selected answer during a mock test.
     *
     * @param array $params The parameters for the save, typically from $_POST.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function save_mock_attempt($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $current_time = current_time('mysql');

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        if (!$session_id) {
            return new WP_Error('invalid_session_id', 'Invalid session ID.');
        }

        // 1. Get the session settings
        $session_settings_json = $wpdb->get_var($wpdb->prepare("SELECT settings_snapshot FROM {$wpdb->prefix}qp_user_sessions WHERE session_id = %d", $session_id));
        $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $has_access = false;

        // 2. Check what kind of session this is
        if (isset($settings['course_id']) && $settings['course_id'] > 0) {
            if (User_Access::can_access_course($user_id, $settings['course_id'])) {
                $has_access = true;
            }
        } else {
            $active_entitlements = $wpdb->get_results($wpdb->prepare(
                "SELECT entitlement_id, remaining_attempts
                                         FROM {$entitlements_table}
                                         WHERE user_id = %d AND status = 'active' AND (expiry_date IS NULL OR expiry_date > %s)
                                         ORDER BY expiry_date ASC, remaining_attempts ASC",
                $user_id,
                $current_time
            ));

            if (!empty($active_entitlements)) {
                foreach ($active_entitlements as $entitlement) {
                    if (!is_null($entitlement->remaining_attempts)) {
                        if ((int)$entitlement->remaining_attempts > 0) {
                            $has_access = true;
                            break;
                        }
                    } else {
                        $has_access = true; // Unlimited plan
                        break;
                    }
                }
            }
        }

        if (!$has_access) {
            return new WP_Error('access_denied', 'You do not have access to perform this action. Your plan may have expired or you may be out of attempts.');
        }

        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        $option_id = isset($params['option_id']) ? absint($params['option_id']) : 0; // Can be 0 if clearing response

        if (!$question_id) {
            return new WP_Error('invalid_data', 'Invalid data submitted.');
        }

        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        $existing_attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT attempt_id, mock_status FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
            $session_id,
            $question_id
        ));

        $current_mock_status = $existing_attempt ? $existing_attempt->mock_status : 'viewed';
        $new_mock_status = $current_mock_status;

        if ($option_id > 0) {
            if ($current_mock_status == 'marked_for_review' || $current_mock_status == 'answered_and_marked_for_review') {
                $new_mock_status = 'answered_and_marked_for_review';
            } else {
                $new_mock_status = 'answered';
            }
        }

        $attempt_data = [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'question_id' => $question_id,
            'selected_option_id' => $option_id > 0 ? $option_id : null,
            'is_correct' => null,
            'status' => $option_id > 0 ? 'answered' : 'viewed',
            'mock_status' => $new_mock_status,
            'attempt_time' => $current_time
        ];

        if ($existing_attempt) {
            $wpdb->update($attempts_table, $attempt_data, ['attempt_id' => $existing_attempt->attempt_id]);
        } else {
            $wpdb->insert($attempts_table, $attempt_data);
        }

        $wpdb->update($wpdb->prefix . 'qp_user_sessions', ['last_activity' => $current_time], ['session_id' => $session_id]);

        return ['message' => 'Answer saved.'];
    }

    /**
     * Updates the status of a mock test question.
     *
     * @param array $params The parameters for the update, typically from $_POST.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function update_mock_status($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        $new_status = isset($params['status']) ? sanitize_key($params['status']) : '';

        if (!$session_id || !$question_id || empty($new_status)) {
            return new WP_Error('invalid_data', 'Invalid data provided for status update.');
        }

        $allowed_statuses = ['viewed', 'answered', 'marked_for_review', 'answered_and_marked_for_review', 'not_viewed'];
        if (!in_array($new_status, $allowed_statuses)) {
            return new WP_Error('invalid_status', 'Invalid status provided.');
        }

        global $wpdb;
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $user_id = get_current_user_id();

        $existing_attempt_id = $wpdb->get_var($wpdb->prepare(
            "SELECT attempt_id FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
            $session_id,
            $question_id
        ));

        $data_to_update = ['mock_status' => $new_status];

        if ($new_status === 'viewed' || $new_status === 'marked_for_review') {
            $data_to_update['selected_option_id'] = null;
        }

        if ($existing_attempt_id) {
            $wpdb->update($attempts_table, $data_to_update, ['attempt_id' => $existing_attempt_id]);
        } else {
            $data_to_update['session_id'] = $session_id;
            $data_to_update['user_id'] = $user_id;
            $data_to_update['question_id'] = $question_id;
            $data_to_update['status'] = 'viewed';
            $wpdb->insert($attempts_table, $data_to_update);
        }

        return ['message' => 'Status updated.'];
    }

    /**
     * Marks a question as 'expired' for a session.
     *
     * @param array $params The parameters for the expiration, typically from $_POST.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function expire_question($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;

        if (!$session_id || !$question_id) {
            return new WP_Error('invalid_data', 'Invalid data submitted.');
        }

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'question_id' => $question_id,
            'is_correct' => null,
            'status' => 'expired',
            'remaining_time' => 0
        ]);

        return ['message' => 'Question expired.'];
    }

    /**
     * Skips a question in a session.
     *
     * @param array $params The parameters for skipping, typically from $_POST.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function skip_question($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        if (!$session_id || !$question_id) {
            return new WP_Error('invalid_data', 'Invalid data submitted.');
        }

        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}qp_user_attempts", [
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'question_id' => $question_id,
            'selected_option_id' => null,
            'is_correct' => null,
            'status' => 'skipped',
            'mock_status' => null,
            'remaining_time' => isset($params['remaining_time']) ? absint($params['remaining_time']) : null,
            'attempt_time' => current_time('mysql')
        ]);

        return ['message' => 'Question skipped.'];
    }

    /**
     * Adds or removes a question from the user's review list.
     *
     * @param array $params The parameters for toggling review, typically from $_POST.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function toggle_review_later($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        $is_marked = isset($params['is_marked']) && $params['is_marked'] === true;
        $user_id = get_current_user_id();

        if (!$question_id) {
            return new WP_Error('invalid_question_id', 'Invalid question ID.');
        }

        global $wpdb;
        $review_table = $wpdb->prefix . 'qp_review_later';

        if ($is_marked) {
            $wpdb->insert(
                $review_table,
                ['user_id' => $user_id, 'question_id' => $question_id],
                ['%d', '%d']
            );
        } else {
            $wpdb->delete(
                $review_table,
                ['user_id' => $user_id, 'question_id' => $question_id],
                ['%d', '%d']
            );
        }

        return ['message' => 'Review status updated.'];
    }

    /**
     * Submits a new question report.
     *
     * @param array $params The parameters for the report, typically from $_POST.
     * @return array|WP_Error An array with a success message and report info, or a WP_Error on failure.
     */
    public static function submit_question_report($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        $reasons = isset($params['reasons']) && is_array($params['reasons']) ? array_map('absint', $params['reasons']) : [];
        $comment = isset($params['comment']) ? sanitize_textarea_field($params['comment']) : '';
        $user_id = get_current_user_id();

        if (empty($question_id) || empty($reasons)) {
            return new WP_Error('invalid_data', 'Invalid data provided.');
        }

        global $wpdb;
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        $reason_ids_string = implode(',', $reasons);

        $wpdb->insert(
            $reports_table,
            [
                'question_id'     => $question_id,
                'user_id'         => $user_id,
                'reason_term_ids' => $reason_ids_string,
                'comment'         => $comment,
                'report_date'     => current_time('mysql'),
                'status'          => 'open'
            ]
        );

        $wpdb->insert("{$wpdb->prefix}qp_logs", [
            'log_type'    => 'User Report',
            'log_message' => sprintf('User reported question #%s.', $question_id),
            'log_data'    => wp_json_encode(['user_id' => $user_id, 'session_id' => $session_id, 'question_id' => $question_id, 'reasons' => $reasons, 'comment' => $comment])
        ]);

        $terms_table = $wpdb->prefix . 'qp_terms';
        $meta_table = $wpdb->prefix . 'qp_term_meta';
        $ids_placeholder = implode(',', $reasons);

        $reason_types = $wpdb->get_col("
                                                            SELECT m.meta_value
                                                            FROM {$terms_table} t
                                                            JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'type'
                                                            WHERE t.term_id IN ($ids_placeholder)
                                                        ");

        $report_info = [
            'has_report' => in_array('report', $reason_types),
            'has_suggestion' => in_array('suggestion', $reason_types),
        ];

        if ($report_info['has_report']) {
            $wpdb->update(
                "{$wpdb->prefix}qp_questions",
                ['status' => 'reported'],
                ['question_id' => $question_id],
                ['%s'],
                ['%d']
            );
        }

        return ['message' => 'Report submitted.', 'reported_info' => $report_info];
    }

    /**
     * Retrieves data for a single question for review purposes.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array with question data, or a WP_Error on failure.
     */
    public static function get_single_question_for_review($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        if (!$question_id) {
            return new WP_Error('invalid_question_id', 'Invalid question ID.');
        }

        global $wpdb;

        $question_data = $wpdb->get_row($wpdb->prepare(
            "SELECT
                                                                    q.question_id,
                                                                    q.question_text,
                                                                    g.direction_text,
                                                                    g.direction_image_id,
                                                                    parent_term.name AS subject_name
                                                                 FROM {$wpdb->prefix}qp_questions q
                                                                 LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
                                                                 LEFT JOIN {$wpdb->prefix}qp_term_relationships r ON g.group_id = r.object_id AND r.object_type = 'group'
                                                                 LEFT JOIN {$wpdb->prefix}qp_terms child_term ON r.term_id = child_term.term_id
                                                                 LEFT JOIN {$wpdb->prefix}qp_terms parent_term ON child_term.parent = parent_term.term_id
                                                                 WHERE q.question_id = %d
                                                                   AND parent_term.taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject')",
            $question_id
        ), ARRAY_A);

        if (!$question_data) {
            return new WP_Error('question_not_found', 'Question not found.');
        }

        if (!empty($question_data['direction_image_id'])) {
            $question_data['direction_image_url'] = wp_get_attachment_url($question_data['direction_image_id']);
        } else {
            $question_data['direction_image_url'] = null;
        }

        if (!empty($question_data['direction_text'])) {
            $question_data['direction_text'] = wp_kses_post(nl2br($question_data['direction_text']));
        }
        if (!empty($question_data['question_text'])) {
            $question_data['question_text'] = wp_kses_post(nl2br($question_data['question_text']));
        }

        $options = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC",
            $question_id
        ), ARRAY_A);

        foreach ($options as &$option) {
            if (!empty($option['option_text'])) {
                $option['option_text'] = wp_kses_post(nl2br($option['option_text']));
            }
        }
        unset($option);

        $question_data['options'] = $options;

        return $question_data;
    }

    /**
     * Retrieves all active report reasons.
     *
     * @return array|WP_Error An array of reasons by type, or a WP_Error on failure.
     */
    public static function get_report_reasons()
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';
        $meta_table = $wpdb->prefix . 'qp_term_meta';

        $reason_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'report_reason'");

        $reasons_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT
                                                                        t.term_id as reason_id,
                                                                        t.name as reason_text,
                                                                        MAX(CASE WHEN m.meta_key = 'type' THEN m.meta_value END) as type
                                                                     FROM {$term_table} t
                                                                     LEFT JOIN {$meta_table} m ON t.term_id = m.term_id
                                                                     WHERE t.taxonomy_id = %d AND (
                                                                        NOT EXISTS (SELECT 1 FROM {$meta_table} meta_active WHERE meta_active.term_id = t.term_id AND meta_active.meta_key = 'is_active')
                                                                        OR
                                                                        (SELECT meta_active.meta_value FROM {$meta_table} meta_active WHERE meta_active.term_id = t.term_id AND meta_active.meta_key = 'is_active') = '1'
                                                                     )
                                                                     GROUP BY t.term_id
                                                                     ORDER BY t.name ASC",
            $reason_tax_id
        ));

        $reasons_by_type = [
            'report' => [],
            'suggestion' => []
        ];

        $other_reasons = [];
        foreach ($reasons_raw as $reason) {
            $type = !empty($reason->type) ? $reason->type : 'report';
            if (strpos($reason->reason_text, 'Other') !== false) {
                $other_reasons[$type][] = $reason;
            } else {
                $reasons_by_type[$type][] = $reason;
            }
        }

        if (isset($other_reasons['report'])) {
            $reasons_by_type['report'] = array_merge($reasons_by_type['report'], $other_reasons['report']);
        }
        if (isset($other_reasons['suggestion'])) {
            $reasons_by_type['suggestion'] = array_merge($reasons_by_type['suggestion'], $other_reasons['suggestion']);
        }

        return $reasons_by_type;
    }

    /**
     * Retrieves the number of unattempted questions for the current user, grouped by subject and topic.
     *
     * @return array|WP_Error An array of counts, or a WP_Error on failure.
     */
    public static function get_unattempted_counts()
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $q_table = $wpdb->prefix . 'qp_questions';
        $a_table = $wpdb->prefix . 'qp_user_attempts';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        $attempted_q_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$a_table} WHERE user_id = %d AND status = 'answered'",
            $user_id
        ));
        $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

        $results = $wpdb->get_results($wpdb->prepare("
                                                                        SELECT
                                                                            t.term_id as topic_id,
                                                                            t.parent as subject_id
                                                                        FROM {$q_table} q
                                                                        JOIN {$rel_table} r ON q.group_id = r.object_id AND r.object_type = 'group'
                                                                        JOIN {$term_table} t ON r.term_id = t.term_id
                                                                        WHERE q.status = 'publish'
                                                                          AND q.question_id NOT IN ({$attempted_q_ids_placeholder})
                                                                          AND t.taxonomy_id = %d
                                                                          AND t.parent != 0
                                                                    ", $subject_tax_id));

        $counts = [
            'by_subject' => [],
            'by_topic'   => [],
        ];

        foreach ($results as $row) {
            if (!isset($counts['by_topic'][$row->topic_id])) {
                $counts['by_topic'][$row->topic_id] = 0;
            }
            $counts['by_topic'][$row->topic_id]++;

            if (!isset($counts['by_subject'][$row->subject_id])) {
                $counts['by_subject'][$row->subject_id] = 0;
            }
            $counts['by_subject'][$row->subject_id]++;
        }

        return ['counts' => $counts];
    }

    /**
     * Retrieves the full data for a single question for the practice UI.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array with question data, or a WP_Error on failure.
     */
    public static function get_question_data($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $question_id = isset($params['question_id']) ? absint($params['question_id']) : 0;
        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        $user_id = get_current_user_id();

        if (!$question_id) {
            return new WP_Error('invalid_question_id', 'Invalid Question ID.');
        }

        $result_data = \QuestionPress\Database\Questions_DB::get_question_details_for_practice($question_id, $user_id, $session_id);

        if (!$result_data) {
            return new WP_Error('question_not_found', 'Question not found or could not be loaded.');
        }

        global $wpdb;
        $session_settings_json = $wpdb->get_var($wpdb->prepare(
            "SELECT settings_snapshot FROM {$wpdb->prefix}qp_user_sessions WHERE session_id = %d AND user_id = %d",
            $session_id,
            $user_id
        ));
        $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];
        $options = get_option('qp_settings');

        $is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';
        $allow_send_answer = isset($options['send_correct_answer']) && $options['send_correct_answer'] == 1;

        if ($is_mock_test || !$allow_send_answer) {
            unset($result_data['correct_option_id']);
        }

        return $result_data;
    }

    /**
     * Retrieves topics for a given subject that have questions.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of grouped topics, or a WP_Error on failure.
     */
    public static function get_topics_for_subject($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $subject_ids_raw = isset($params['subject_id']) ? $params['subject_id'] : [];
        if (empty($subject_ids_raw)) {
            return new WP_Error('no_subjects_provided', 'No subjects provided.');
        }

        $subject_term_ids = array_filter(array_map('absint', $subject_ids_raw), function ($id) {
            return $id > 0;
        });

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        $sql = "
                                                                                SELECT parent_term.term_id as subject_id, parent_term.name as subject_name,
                                                                                       child_term.term_id as topic_id, child_term.name as topic_name
                                                                                FROM {$term_table} child_term
                                                                                JOIN {$term_table} parent_term ON child_term.parent = parent_term.term_id
                                                                            ";

        $where_clauses = [];
        $sql_params = [];

        if (!empty($subject_term_ids) && !in_array('all', $subject_ids_raw)) {
            $ids_placeholder = implode(',', array_fill(0, count($subject_term_ids), '%d'));
            $where_clauses[] = "child_term.parent IN ($ids_placeholder)";
            $sql_params = array_merge($sql_params, $subject_term_ids);
        }

        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'");
        if ($subject_tax_id) {
            $where_clauses[] = "child_term.taxonomy_id = %d";
            $sql_params[] = $subject_tax_id;
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        $sql .= " ORDER BY parent_term.name, child_term.name ASC";

        $results = $wpdb->get_results($wpdb->prepare($sql, $sql_params));

        $topics_by_subject_id = [];
        foreach ($results as $row) {
            if (!isset($topics_by_subject_id[$row->subject_id])) {
                $topics_by_subject_id[$row->subject_id] = [
                    'name' => $row->subject_name,
                    'topics' => []
                ];
            }
            $topics_by_subject_id[$row->subject_id]['topics'][] = [
                'topic_id'   => $row->topic_id,
                'topic_name' => $row->topic_name
            ];
        }

        $grouped_topics = [];
        foreach ($topics_by_subject_id as $data) {
            $grouped_topics[$data['name']] = $data['topics'];
        }

        return ['topics' => $grouped_topics];
    }

    /**
     * Retrieves sections containing questions for a given topic.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of sections with unattempted counts, or a WP_Error on failure.
     */
    public static function get_sections_for_subject($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $topic_id = isset($params['topic_id']) ? absint($params['topic_id']) : 0;
        $user_id = get_current_user_id();

        if (!$topic_id) {
            return new WP_Error('invalid_topic_id', 'Invalid topic ID.');
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

        $group_ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'group'", $topic_id));

        if (empty($group_ids)) {
            return ['sections' => []];
        }
        $group_ids_placeholder = implode(',', $group_ids);

        $source_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.term_id, t.name, t.parent
                                                                                     FROM {$term_table} t
                                                                                     JOIN {$rel_table} r ON t.term_id = r.term_id
                                                                                     WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d
                                                                                     ORDER BY t.parent, t.name ASC",
            $source_tax_id
        ));

        $attempted_q_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
        $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

        $results = [];
        foreach ($source_terms as $term) {
            if ($term->parent > 0) {
                $parent_source_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $term_table WHERE term_id = %d", $term->parent));

                $unattempted_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(q.question_id)
                                                                                             FROM {$wpdb->prefix}qp_questions q
                                                                                             JOIN {$rel_table} r_group_topic ON q.group_id = r_group_topic.object_id AND r_group_topic.object_type = 'group'
                                                                                             JOIN {$rel_table} r_group_section ON q.group_id = r_group_section.object_id AND r_group_section.object_type = 'group'
                                                                                             WHERE r_group_topic.term_id = %d
                                                                                             AND r_group_section.term_id = %d
                                                                                             AND q.question_id NOT IN ({$attempted_q_ids_placeholder})",
                    $topic_id,
                    $term->term_id
                ));

                $results[] = [
                    'section_id' => $term->term_id,
                    'source_name' => $parent_source_name,
                    'section_name' => $term->name,
                    'unattempted_count' => $unattempted_count
                ];
            }
        }

        return ['sections' => $results];
    }

    /**
     * Retrieves sources linked to a specific subject.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of sources, or a WP_Error on failure.
     */
    public static function get_sources_for_subject($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $subject_id = isset($params['subject_id']) ? absint($params['subject_id']) : 0;

        if (!$subject_id) {
            return new WP_Error('invalid_subject_id', 'Invalid subject ID.');
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        $source_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$rel_table} WHERE term_id = %d AND object_type = 'source_subject_link'",
            $subject_id
        ));

        if (empty($source_ids)) {
            return ['sources' => []];
        }

        $ids_placeholder = implode(',', $source_ids);
        $sources = $wpdb->get_results("SELECT term_id, name FROM {$term_table} WHERE term_id IN ($ids_placeholder) ORDER BY name ASC");

        return ['sources' => $sources];
    }

    /**
     * Retrieves child terms for a given parent term.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of child terms, or a WP_Error on failure.
     */
    public static function get_child_terms($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $parent_term_id = isset($params['parent_id']) ? absint($params['parent_id']) : 0;

        if (!$parent_term_id) {
            return new WP_Error('invalid_parent_id', 'Invalid parent ID.');
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        $child_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM {$term_table} WHERE parent = %d ORDER BY name ASC",
            $parent_term_id
        ));

        return ['children' => $child_terms];
    }

    /**
     * Calculates and returns the hierarchical progress data for a user.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of progress data, or a WP_Error on failure.
     */
    public static function get_progress_data($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $subject_term_id = isset($params['subject_id']) ? absint($params['subject_id']) : 0;
        $source_term_id = isset($params['source_id']) ? absint($params['source_id']) : 0;
        $user_id = get_current_user_id();

        if (!$source_term_id || !$user_id || !$subject_term_id) {
            return new WP_Error('invalid_request', 'Invalid request parameters.');
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // Step 1: Get all term IDs in both hierarchies
        $all_subject_term_ids = \QuestionPress\Database\Terms_DB::get_all_descendant_ids($subject_term_id);
        $all_source_term_ids = \QuestionPress\Database\Terms_DB::get_all_descendant_ids($source_term_id);

        $subject_terms_placeholder = implode(',', $all_subject_term_ids);
        $source_terms_placeholder = implode(',', $all_source_term_ids);

        // Step 2: Find intersecting groups
        $relevant_group_ids = $wpdb->get_col("
                                                                                                SELECT DISTINCT r1.object_id
                                                                                                FROM {$rel_table} r1
                                                                                                INNER JOIN {$rel_table} r2 ON r1.object_id = r2.object_id AND r1.object_type = 'group' AND r2.object_type = 'group'
                                                                                                WHERE r1.term_id IN ($subject_terms_placeholder)
                                                                                                  AND r2.term_id IN ($source_terms_placeholder)
                                                                                            ");

        if (empty($relevant_group_ids)) {
            return ['html' => '<p>No questions found for this subject and source combination.</p>'];
        }
        $group_ids_placeholder = implode(',', $relevant_group_ids);

        // Step 3: Get all questions in scope
        $all_qids_in_scope = $wpdb->get_col("SELECT question_id FROM {$questions_table} WHERE group_id IN ($group_ids_placeholder)");

        if (empty($all_qids_in_scope)) {
            return ['html' => '<p>No questions found for this source.</p>'];
        }
        $qids_placeholder = implode(',', $all_qids_in_scope);

        // Step 4: Get user's completed questions
        $exclude_incorrect = isset($params['exclude_incorrect']) && $params['exclude_incorrect'] === true;
        $attempt_status_clause = $exclude_incorrect ? "AND is_correct = 1" : "AND status = 'answered'";
        $completed_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND question_id IN ($qids_placeholder) $attempt_status_clause",
            $user_id
        ));

        // Step 4b: Get all section practice sessions for this user
        $section_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, status, settings_snapshot FROM {$sessions_table} WHERE user_id = %d",
            $user_id
        ));

        $session_info_by_section = [];
        foreach ($section_sessions as $session) {
            $settings = json_decode($session->settings_snapshot, true);
            if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice' && isset($settings['section_id'])) {
                $section_id = $settings['section_id'];
                if (!isset($session_info_by_section[$section_id])) {
                    $session_info_by_section[$section_id] = [
                        'session_id' => $session->session_id,
                        'status' => $session->status
                    ];
                }
            }
        }

        // Step 5: Prepare data for the tree
        $all_terms_data = $wpdb->get_results("SELECT term_id, name, parent FROM $term_table WHERE term_id IN ($source_terms_placeholder)");
        $question_group_map = $wpdb->get_results("SELECT question_id, group_id FROM {$questions_table} WHERE question_id IN ($qids_placeholder)", OBJECT_K);
        $group_term_map_raw = $wpdb->get_results("SELECT object_id, term_id FROM {$rel_table} WHERE object_id IN ($group_ids_placeholder) AND object_type = 'group' AND term_id IN ($source_terms_placeholder)");

        $group_term_map = [];
        foreach ($group_term_map_raw as $row) {
            $group_term_map[$row->object_id][] = $row->term_id;
        }

        $terms_by_id = [];
        foreach ($all_terms_data as $term) {
            $term->children = [];
            $term->total = 0;
            $term->completed = 0;
            $term->is_fully_attempted = false;
            $term->session_info = $session_info_by_section[$term->term_id] ?? null;
            $terms_by_id[$term->term_id] = $term;
        }

        foreach ($all_qids_in_scope as $qid) {
            $is_completed = in_array($qid, $completed_qids);
            $gid = $question_group_map[$qid]->group_id;

            if (isset($group_term_map[$gid])) {
                $term_ids_for_group = $group_term_map[$gid];
                $processed_parents = [];

                foreach ($term_ids_for_group as $term_id) {
                    $current_term_id = $term_id;
                    while (isset($terms_by_id[$current_term_id]) && !in_array($current_term_id, $processed_parents)) {
                        $terms_by_id[$current_term_id]->total++;
                        if ($is_completed) {
                            $terms_by_id[$current_term_id]->completed++;
                        }
                        $processed_parents[] = $current_term_id;
                        $current_term_id = $terms_by_id[$current_term_id]->parent;
                    }
                }
            }
        }

        foreach ($terms_by_id as $term) {
            if ($term->total > 0 && $term->completed >= $term->total) {
                $term->is_fully_attempted = true;
            }
        }

        $source_term_object = null;
        foreach ($terms_by_id as $term) {
            if ($term->term_id == $source_term_id) {
                $source_term_object = $term;
            }
            if (isset($terms_by_id[$term->parent])) {
                $terms_by_id[$term->parent]->children[] = $term;
            }
        }

        $options = get_option('qp_settings');
        $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : '';
        $session_page_url = isset($options['session_page']) ? get_permalink($options['session_page']) : '';

        $subject_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$term_table} WHERE term_id = %d", $subject_term_id));
        $subject_percentage = $source_term_object->total > 0 ? round(($source_term_object->completed / $source_term_object->total) * 100) : 0;

        return [
            'subject_name' => $subject_name,
            'subject_percentage' => $subject_percentage,
            'subject_completed' => $source_term_object->completed,
            'subject_total' => $source_term_object->total,
            'source_term_object' => $source_term_object,
            'review_page_url' => $review_page_url,
            'session_page_url' => $session_page_url
        ];
    }

    /**
     * Retrieves sources linked to a specific subject for cascading dropdowns.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of sources, or a WP_Error on failure.
     */
    public static function get_sources_for_subject_cascading($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $subject_id = isset($params['subject_id']) ? absint($params['subject_id']) : 0;

        if (!$subject_id) {
            return new WP_Error('invalid_subject_id', 'Invalid subject ID.');
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        $source_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$rel_table} WHERE term_id = %d AND object_type = 'source_subject_link'",
            $subject_id
        ));

        if (empty($source_ids)) {
            return ['sources' => []];
        }

        $ids_placeholder = implode(',', $source_ids);
        $sources = $wpdb->get_results("SELECT term_id, name FROM {$term_table} WHERE term_id IN ($ids_placeholder) ORDER BY name ASC");

        return ['sources' => $sources];
    }

    /**
     * Retrieves child terms for a given parent term for cascading dropdowns.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of child terms, or a WP_Error on failure.
     */
    public static function get_child_terms_cascading($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $parent_term_id = isset($params['parent_id']) ? absint($params['parent_id']) : 0;

        if (!$parent_term_id) {
            return new WP_Error('invalid_parent_id', 'Invalid parent ID.');
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        $child_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM {$term_table} WHERE parent = %d ORDER BY name ASC",
            $parent_term_id
        ));

        return ['children' => $child_terms];
    }

    /**
     * Retrieves sources linked to a specific subject for the progress tab.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array of sources, or a WP_Error on failure.
     */
    public static function get_sources_for_subject_progress($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $subject_term_id = isset($params['subject_id']) ? absint($params['subject_id']) : 0;

        if (!$subject_term_id) {
            return ['sources' => []];
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

        $topic_ids = \QuestionPress\Database\Terms_DB::get_all_descendant_ids($subject_term_id);

        if (empty($topic_ids)) {
            return ['sources' => []];
        }
        $topic_ids_placeholder = implode(',', $topic_ids);

        $group_ids = $wpdb->get_col("SELECT object_id FROM $rel_table WHERE term_id IN ($topic_ids_placeholder) AND object_type = 'group'");

        if (empty($group_ids)) {
            return ['sources' => []];
        }
        $group_ids_placeholder = implode(',', $group_ids);

        $all_linked_source_term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT r.term_id
                                                                                                             FROM {$rel_table} r
                                                                                                             JOIN {$term_table} t ON r.term_id = t.term_id
                                                                                                             WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d",
            $source_tax_id
        ));

        if (empty($all_linked_source_term_ids)) {
            return ['sources' => []];
        }

        $top_level_source_ids = [];
        foreach ($all_linked_source_term_ids as $term_id) {
            $current_id = $term_id;
            for ($i = 0; $i < 10; $i++) {
                $parent_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $current_id));
                if ($parent_id == 0) {
                    $top_level_source_ids[] = $current_id;
                    break;
                }
                $current_id = $parent_id;
            }
        }

        $unique_source_ids = array_unique($top_level_source_ids);

        if (empty($unique_source_ids)) {
            return ['sources' => []];
        }

        $source_ids_placeholder = implode(',', $unique_source_ids);

        $source_terms = $wpdb->get_results(
            "SELECT term_id as source_id, name as source_name
                                                                                                             FROM {$term_table}
                                                                                                             WHERE term_id IN ($source_ids_placeholder)
                                                                                                             ORDER BY name ASC"
        );

        $sources = [];
        foreach ($source_terms as $term) {
            $sources[] = [
                'source_id' => $term->source_id,
                'source_name' => $term->source_name
            ];
        }

        return ['sources' => $sources];
    }

    /**
     * Checks remaining attempts/access for the current user.
     *
     * @return array|WP_Error An array with access status and remaining attempts, or a WP_Error on failure.
     */
    public static function check_remaining_attempts()
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $user_id = get_current_user_id();
        $has_access = false;
        $total_remaining = 0;
        $has_unlimited_attempts = false;
        $denial_reason_code = 'no_entitlements';

        global $wpdb;
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $current_time = current_time('mysql');

        $all_user_entitlements = $wpdb->get_results($wpdb->prepare(
            "SELECT entitlement_id, remaining_attempts, expiry_date, status
                                                                                                                 FROM {$entitlements_table}
                                                                                                                 WHERE user_id = %d",
            $user_id
        ));

        if (!empty($all_user_entitlements)) {
            $denial_reason_code = 'expired_or_inactive';
            $found_active_non_expired = false;

            foreach ($all_user_entitlements as $entitlement) {
                $is_active = $entitlement->status === 'active';
                $is_expired = !is_null($entitlement->expiry_date) && $entitlement->expiry_date <= $current_time;

                if ($is_active && !$is_expired) {
                    $found_active_non_expired = true;
                    $denial_reason_code = 'out_of_attempts';

                    if (is_null($entitlement->remaining_attempts)) {
                        $has_unlimited_attempts = true;
                        $has_access = true;
                        $total_remaining = -1;
                        break;
                    } else {
                        $total_remaining += (int) $entitlement->remaining_attempts;
                    }
                }
            }

            if (!$has_unlimited_attempts && $found_active_non_expired && $total_remaining > 0) {
                $has_access = true;
            }
        } else {
            $denial_reason_code = 'no_entitlements';
        }

        return [
            'has_access' => $has_access,
            'remaining' => $has_unlimited_attempts ? -1 : $total_remaining,
            'reason_code' => $denial_reason_code
        ];
    }

    /**
     * Retrieves buffered question data for a session.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array with buffered question data, or a WP_Error on failure.
     */
    public static function get_buffered_question_data($params)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $session_id = isset($params['session_id']) ? absint($params['session_id']) : 0;
        $user_id = get_current_user_id();

        if (!$session_id) {
            return new WP_Error('invalid_session_id', 'Invalid session ID.');
        }

        global $wpdb;
        $session_table = $wpdb->prefix . 'qp_user_sessions';

        $session_data = $wpdb->get_row($wpdb->prepare(
            "SELECT buffered_questions, current_question_index, settings_snapshot FROM {$session_table} WHERE session_id = %d AND user_id = %d",
            $session_id,
            $user_id
        ), ARRAY_A);

        if (!$session_data) {
            return new WP_Error('session_not_found', 'Session not found or does not belong to user.');
        }

        $buffered_questions = json_decode($session_data['buffered_questions'], true);
        $current_question_index = (int) $session_data['current_question_index'];
        $settings = json_decode($session_data['settings_snapshot'], true);

        $question_ids_to_fetch = [];
        $buffer_size = 5; // Number of questions to buffer ahead

        for ($i = 0; $i < $buffer_size; $i++) {
            $index = $current_question_index + 1 + $i;
            if (isset($buffered_questions[$index])) {
                $question_ids_to_fetch[] = $buffered_questions[$index];
            }
        }

        $buffered_question_data = [];
        foreach ($question_ids_to_fetch as $q_id) {
            $data = \QuestionPress\Database\Questions_DB::get_question_details_for_practice($q_id, $user_id, $session_id);
            if ($data) {
                $is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';
                $options = get_option('qp_settings');
                $allow_send_answer = isset($options['send_correct_answer']) && $options['send_correct_answer'] == 1;

                if ($is_mock_test || !$allow_send_answer) {
                    unset($data['correct_option_id']);
                }
                $buffered_question_data[] = $data;
            }
        }

        return ['buffered_questions' => $buffered_question_data];
    }
}
