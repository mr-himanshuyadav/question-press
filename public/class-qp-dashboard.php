<?php
if (!defined('ABSPATH')) exit;

class QP_Dashboard
{

    public static function render()
    {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        // --- Existing Data Fetching (Keep this part for now) ---
        global $wpdb;
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;

        // Remaining Attempts Message (Keep this)
        $remaining_attempts = get_user_meta($user_id, 'qp_remaining_attempts', true);
        $access_status_message = '';
        // --- (Keep the logic for $access_status_message as it was) ---
        if ($remaining_attempts !== '' && (int)$remaining_attempts > 0) {
             $access_status_message = 'Attempts remaining: <strong>' . number_format((int)$remaining_attempts) . '</strong>';
        } else {
             $shop_page_url = '';
             if (function_exists('wc_get_page_id')) {
                 $shop_page_id = wc_get_page_id('shop');
                 if ($shop_page_id > 0) {
                     $shop_page_url = get_permalink($shop_page_id);
                 }
             }
             if (empty($shop_page_url)) {
                 $shop_page_url = home_url('/');
                 $link_text = 'Purchase Access';
             } else {
                 $link_text = 'Purchase More';
             }
             $access_status_message = 'No attempts remaining. <a href="' . esc_url($shop_page_url) . '">' . esc_html($link_text) . '</a>';
        }


        // Lifetime Stats (Keep this)
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(CASE WHEN status = 'answered' THEN 1 END) as total_attempted,
                COUNT(CASE WHEN is_correct = 1 THEN 1 END) as total_correct,
                COUNT(CASE WHEN is_correct = 0 THEN 1 END) as total_incorrect
             FROM {$attempts_table}
             WHERE user_id = %d",
            $user_id
        ));
        $total_attempted = $stats->total_attempted ?? 0;
        $total_correct = $stats->total_correct ?? 0;
        $total_incorrect = $stats->total_incorrect ?? 0;
        $overall_accuracy = ($total_attempted > 0) ? ($total_correct / $total_attempted) * 100 : 0;

        // Active/Paused Sessions (Keep this)
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $active_sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('active', 'mock_test', 'paused') ORDER BY CASE WHEN status = 'paused' THEN 0 ELSE 1 END, start_time DESC", $user_id)); // Include paused

        // Recent History (Keep this, maybe limit results later)
        $recent_history = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC LIMIT 5", $user_id)); // Limit to 5 for overview

        // Counts for Quick Actions (Keep this)
        $review_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d", $user_id));
        $correctly_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id));
        $all_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
        $never_correct_qids = array_diff($all_answered_qids, $correctly_answered_qids);
        $never_correct_count = count($never_correct_qids);

        // Get URLs (Keep this)
        $options = get_option('qp_settings');
        $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');
        $session_page_url = isset($options['session_page']) ? get_permalink($options['session_page']) : home_url('/');
         $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/');
         $shop_page_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/');


        // --- NEW: Start Output Buffer ---
        ob_start();
        ?>
        <div id="qp-practice-app-wrapper"> <?php // Keep existing wrapper ?>
            <div class="qp-dashboard-layout">
                <?php // --- NEW: Add Toggle Button and Overlay --- ?>
                <button class="qp-sidebar-toggle" aria-label="Toggle Navigation" aria-expanded="false">
                    <span class="dashicons dashicons-menu-alt"></span>
                </button>
                <div class="qp-sidebar-overlay"></div>
                <?php // --- END NEW --- ?>

                <aside class="qp-sidebar">
                    <div class="qp-sidebar-header" style="text-align: center; padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--qp-dashboard-border);">
                         <span class="qp-user-name" style="font-size: 1.1em; font-weight: 600;"><?php echo esc_html($current_user->display_name); ?></span><br>
                         <span class="qp-access-status" style="font-size: 0.85em; color: var(--qp-dashboard-text-light);">
                             <?php echo wp_kses_post($access_status_message); ?>
                         </span>
                    </div>
                    <ul class="qp-sidebar-nav">
                        <li><a href="#overview" class="active"><span class="dashicons dashicons-chart-pie"></span><span>Overview</span></a></li>
                        <li><a href="#history"><span class="dashicons dashicons-list-view"></span><span>History</span></a></li>
                        <li><a href="#review"><span class="dashicons dashicons-star-filled"></span><span>Review Center</span></a></li>
                        <li><a href="#progress"><span class="dashicons dashicons-chart-bar"></span><span>Progress</span></a></li>
                        <?php /* Placeholder for future items */ ?>
                    </ul>
                     <div class="qp-sidebar-footer" style="position: absolute; bottom: 10px; width: 100%; text-align: center;">
                         <a href="<?php echo wp_logout_url(get_permalink()); ?>" class="qp-logout-link" style="font-size: 0.9em; color: var(--qp-dashboard-text-light); text-decoration: none;">
                             <span class="dashicons dashicons-exit" style="vertical-align: middle;"></span> Logout
                         </a>
                     </div>
                </aside>

                <main class="qp-main-content">
                    <?php settings_errors('qp_user_attempts_notices'); // Display notices if any (like from attempt updates) ?>

                    <section id="qp-dashboard-overview" class="qp-dashboard-section active">
                        <?php self::render_overview_content($stats, $overall_accuracy, $active_sessions, $recent_history, $review_count, $never_correct_count, $practice_page_url, $session_page_url, $review_page_url); ?>
                    </section>

                    <section id="qp-dashboard-history" class="qp-dashboard-section" style="display: none;">
                        <?php /* Content will be loaded by JS/AJAX or rendered by PHP function later */ ?>
                         <h2>Practice History</h2>
                         <p>Loading history...</p> <?php // Placeholder ?>
                    </section>

                    <section id="qp-dashboard-review" class="qp-dashboard-section" style="display: none;">
                         <?php /* Content will be loaded by JS/AJAX or rendered by PHP function later */ ?>
                         <h2>Review Center</h2>
                         <p>Loading review items...</p> <?php // Placeholder ?>
                    </section>

                    <section id="qp-dashboard-progress" class="qp-dashboard-section" style="display: none;">
                         <?php /* Content will be loaded by JS/AJAX or rendered by PHP function later */ ?>
                         <h2>Progress Tracker</h2>
                         <p>Loading progress data...</p> <?php // Placeholder ?>
                    </section>

                     <?php /* Hidden modal for View Question popup (Keep from old code) */ ?>
                     <div id="qp-review-modal-backdrop" style="display: none;">
                         <div id="qp-review-modal-content"></div>
                     </div>

                </main>
            </div>
        </div> <?php // End #qp-practice-app-wrapper ?>
        <?php
        return ob_get_clean();
    }

    /**
     * NEW: Renders the content specifically for the Overview section.
     */
    private static function render_overview_content($stats, $overall_accuracy, $active_sessions, $recent_history, $review_count, $never_correct_count, $practice_page_url, $session_page_url, $review_page_url) {
         global $wpdb; // Ensure $wpdb is available
         $user_id = get_current_user_id();
         $sessions_table = $wpdb->prefix . 'qp_user_sessions';
         $attempts_table = $wpdb->prefix . 'qp_user_attempts';
         $term_table = $wpdb->prefix . 'qp_terms';
         $rel_table = $wpdb->prefix . 'qp_term_relationships';
         $questions_table = $wpdb->prefix . 'qp_questions';

        ?>
        <div class="qp-card">
            <div class="qp-card-header"><h3>Lifetime Stats</h3></div>
            <div class="qp-card-content">
                <div class="qp-overall-stats">
                    <div class="stat-item">
                        <span class="stat-label">Accuracy</span>
                        <span class="stat-value"><?php echo round($overall_accuracy, 1); ?>%</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Attempted</span>
                        <span class="stat-value"><?php echo (int)($stats->total_attempted ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Correct</span>
                        <span class="stat-value"><?php echo (int)($stats->total_correct ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Incorrect</span>
                        <span class="stat-value"><?php echo (int)($stats->total_incorrect ?? 0); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="qp-card">
            <div class="qp-card-header"><h3>Quick Actions</h3></div>
            <div class="qp-card-content qp-quick-actions">
                 <a href="<?php echo esc_url($practice_page_url); ?>" class="qp-button qp-button-primary">
                     <span class="dashicons dashicons-edit"></span> Start New Practice
                 </a>
                 <button id="qp-start-incorrect-practice-btn" class="qp-button qp-button-secondary" <?php disabled($never_correct_count, 0); ?>>
                     <span class="dashicons dashicons-warning"></span> Practice Mistakes (<?php echo (int)$never_correct_count; ?>)
                 </button>
                  <button id="qp-start-reviewing-btn" class="qp-button qp-button-secondary" <?php disabled($review_count, 0); ?>>
                     <span class="dashicons dashicons-star-filled"></span> Review Marked (<?php echo (int)$review_count; ?>)
                 </button>
            </div>
            <?php // Hidden checkbox for practice mistakes mode ?>
             <div style="display: none;">
                  <label class="qp-custom-checkbox">
                     <input type="checkbox" id="qp-include-all-incorrect-cb" name="include_all_incorrect" value="1">
                     <span></span> Include all past mistakes
                 </label>
             </div>
        </div>

         <?php if (!empty($active_sessions)) : ?>
        <div class="qp-card">
            <div class="qp-card-header"><h3>Active / Paused Sessions</h3></div>
            <div class="qp-card-content">
                <div class="qp-active-sessions-list">
                <?php
                foreach ($active_sessions as $session) {
                    $settings = json_decode($session->settings_snapshot, true);
                    $mode = self::get_session_mode_name($session, $settings); // Use helper
                    $row_class = $session->status === 'paused' ? 'qp-session-paused-card' : ''; // Add class for paused
                ?>
                    <div class="qp-active-session-card <?php echo $row_class; ?>">
                        <div class="qp-card-details">
                            <span class="qp-card-subject"><?php echo esc_html($mode); ?></span>
                            <span class="qp-card-date">Started: <?php echo date_format(date_create($session->start_time), 'M j, Y, g:i a'); ?></span>
                        </div>
                        <div class="qp-card-actions">
                            <?php if ($mode !== 'Section Practice') : ?>
                                <button class="qp-button qp-button-danger qp-terminate-session-btn" data-session-id="<?php echo esc_attr($session->session_id); ?>">Terminate</button>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)); ?>" class="qp-button qp-button-primary"><?php echo ($session->status === 'paused' ? 'Resume' : 'Continue'); ?></a>
                        </div>
                    </div>
                <?php } ?>
                </div>
            </div>
        </div>
         <?php endif; ?>

        <div class="qp-card">
            <div class="qp-card-header"><h3>Recent History</h3></div>
            <div class="qp-card-content">
                <?php if (!empty($recent_history)) : ?>
                <table class="qp-dashboard-table qp-recent-history-table">
                     <thead>
                         <tr>
                             <th>Date</th>
                             <th>Mode</th>
                             <th>Subjects</th>
                             <th>Result</th>
                             <th>Actions</th>
                         </tr>
                     </thead>
                    <tbody>
                        <?php
                        // Fetch lineage data needed for recent history efficiently
                         list($lineage_cache, $group_to_topic_map, $question_to_group_map) = self::prefetch_lineage_data($recent_history);
                         foreach ($recent_history as $session) :
                            $settings = json_decode($session->settings_snapshot, true);
                            $mode = self::get_session_mode_name($session, $settings);
                            $subjects_display = self::get_session_subjects_display($session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map); // Use helper
                            $result_display = self::get_session_result_display($session, $settings); // Use helper
                        ?>
                        <tr>
                            <td data-label="Date"><?php echo date_format(date_create($session->start_time), 'M j, Y'); ?></td>
                            <td data-label="Mode"><?php echo esc_html($mode); ?></td>
                            <td data-label="Subjects"><?php echo esc_html($subjects_display); ?></td>
                            <td data-label="Result"><strong><?php echo esc_html($result_display); ?></strong></td>
                            <td data-label="Actions" class="qp-actions-cell">
                                <a href="<?php echo esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)); ?>" class="qp-button qp-button-secondary">Review</a>
                                 <?php /* Add delete button if needed, checking permissions */ ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                 <p style="text-align: right; margin-top: 1rem;"><a href="#history" class="qp-view-full-history-link">View Full History &rarr;</a></p>
                <?php else : ?>
                    <p style="text-align: center;">No completed sessions yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

   /**
     * NEW HELPER: Prefetches lineage data needed for session lists.
     */
    private static function prefetch_lineage_data($sessions) {
        global $wpdb;
        $all_session_qids = [];
        foreach ($sessions as $session) {
            $qids = json_decode($session->question_ids_snapshot, true);
            if (is_array($qids)) {
                $all_session_qids = array_merge($all_session_qids, $qids);
            }
        }

        $lineage_cache = [];
        $group_to_topic_map = [];
        $question_to_group_map = [];

        if (!empty($all_session_qids)) {
            $unique_qids = array_unique(array_map('absint', $all_session_qids));
             if(empty($unique_qids)) return [$lineage_cache, $group_to_topic_map, $question_to_group_map]; // Avoid empty IN clause

            $qids_placeholder = implode(',', $unique_qids);

            $tax_table = $wpdb->prefix . 'qp_taxonomies';
            $term_table = $wpdb->prefix . 'qp_terms';
            $rel_table = $wpdb->prefix . 'qp_term_relationships';
            $questions_table = $wpdb->prefix . 'qp_questions';
            $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

            $q_to_g_results = $wpdb->get_results("SELECT question_id, group_id FROM {$questions_table} WHERE question_id IN ($qids_placeholder)");
            foreach ($q_to_g_results as $res) {
                $question_to_group_map[$res->question_id] = $res->group_id;
            }

             $all_group_ids = array_unique(array_values($question_to_group_map));
             if(empty($all_group_ids)) return [$lineage_cache, $group_to_topic_map, $question_to_group_map]; // Avoid empty IN clause

             $group_ids_placeholder = implode(',', $all_group_ids);

            $g_to_t_results = $wpdb->get_results($wpdb->prepare(
                "SELECT r.object_id, r.term_id
                 FROM {$rel_table} r JOIN {$term_table} t ON r.term_id = t.term_id
                 WHERE r.object_type = 'group' AND r.object_id IN ($group_ids_placeholder) AND t.taxonomy_id = %d",
                $subject_tax_id
            ));
            foreach ($g_to_t_results as $res) {
                $group_to_topic_map[$res->object_id] = $res->term_id;
            }

             // Pre-populate lineage cache for all topics found
             $all_topic_ids = array_unique(array_values($group_to_topic_map));
              if (!empty($all_topic_ids)) {
                 foreach($all_topic_ids as $topic_id) {
                     if (!isset($lineage_cache[$topic_id])) {
                         $current_term_id = $topic_id;
                         $root_subject_name = 'N/A';
                         for ($i = 0; $i < 10; $i++) {
                             $term = $wpdb->get_row($wpdb->prepare("SELECT name, parent FROM $term_table WHERE term_id = %d", $current_term_id));
                             if (!$term || $term->parent == 0) {
                                 $root_subject_name = $term ? $term->name : 'N/A';
                                 break;
                             }
                             $current_term_id = $term->parent;
                         }
                         $lineage_cache[$topic_id] = $root_subject_name;
                     }
                 }
             }
        }
        return [$lineage_cache, $group_to_topic_map, $question_to_group_map];
    }

     /**
     * NEW HELPER: Determines the display name for a session's mode.
     */
     private static function get_session_mode_name($session, $settings) {
         $mode = 'Practice'; // Default
         if ($session->status === 'paused') {
             $mode = 'Paused Session';
         } elseif (isset($settings['practice_mode'])) {
             switch ($settings['practice_mode']) {
                 case 'revision': $mode = 'Revision'; break;
                 case 'mock_test': $mode = 'Mock Test'; break;
                 case 'Incorrect Que. Practice': $mode = 'Incorrect Practice'; break;
                 case 'Section Wise Practice': $mode = 'Section Practice'; break;
             }
         } elseif (isset($settings['subject_id']) && $settings['subject_id'] === 'review') {
             $mode = 'Review Session';
         }
         return $mode;
     }

    /**
     * NEW HELPER: Gets the subject display string for a session.
     */
    private static function get_session_subjects_display($session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map) {
         global $wpdb;
         $term_table = $wpdb->prefix . 'qp_terms';

        $session_qids = json_decode($session->question_ids_snapshot, true);
        $subjects_display = 'N/A';

        if (is_array($session_qids) && !empty($session_qids)) {
             $mode = self::get_session_mode_name($session, $settings); // Use the mode helper

            if ($mode === 'Section Practice') {
                 // Get source hierarchy for the first question
                 $first_question_id = $session_qids[0];
                 $source_hierarchy = qp_get_source_hierarchy_for_question($first_question_id); // Assumes this function exists globally
                 $subjects_display = !empty($source_hierarchy) ? implode(' / ', $source_hierarchy) : 'N/A';
            } else {
                $session_subjects = [];
                foreach ($session_qids as $qid) {
                    $gid = $question_to_group_map[$qid] ?? null;
                    $topic_id = $gid ? ($group_to_topic_map[$gid] ?? null) : null;
                    if ($topic_id && isset($lineage_cache[$topic_id])) {
                         $session_subjects[] = $lineage_cache[$topic_id];
                    }
                }
                $subjects_display = !empty($session_subjects) ? implode(', ', array_unique(array_filter($session_subjects, fn($s) => $s !== 'N/A'))) : 'N/A';
                if(empty($subjects_display)) $subjects_display = 'N/A';
            }
        }
        return $subjects_display;
    }

    /**
     * NEW HELPER: Gets the result display string for a session.
     */
    private static function get_session_result_display($session, $settings) {
        if ($session->status === 'paused') return '-'; // No result for paused

        $is_scored = isset($settings['marks_correct']);
        if ($is_scored) {
            return number_format((float)$session->marks_obtained, 1) . ' Score';
        } else {
             $total_attempted = (int) $session->correct_count + (int) $session->incorrect_count;
             $accuracy = ($total_attempted > 0) ? round(((int) $session->correct_count / $total_attempted) * 100) : 0;
             return $accuracy . '% Acc';
        }
    }

    // --- Keep the render_sessions_tab_content function, but make it private ---
    private static function render_sessions_tab_content() {
         global $wpdb;
         $user_id = get_current_user_id();
         $sessions_table = $wpdb->prefix . 'qp_user_sessions';

         // Fetching data remains the same
         $options = get_option('qp_settings');
         $session_page_url = isset($options['session_page']) ? get_permalink($options['session_page']) : home_url('/');
         $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/');
         $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');

         $user = wp_get_current_user();
         $user_roles = (array) $user->roles;
         $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
         $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

         $session_history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions_table
             WHERE user_id = %d AND status IN ('completed', 'abandoned', 'paused')
             ORDER BY CASE WHEN status = 'paused' THEN 0 ELSE 1 END, start_time DESC",
            $user_id
         ));

         // Pre-fetch lineage data
         list($lineage_cache, $group_to_topic_map, $question_to_group_map) = self::prefetch_lineage_data($session_history);

         // Display Header Actions
         echo '<div class="qp-history-header">
             <h3 style="margin:0;">Practice History</h3>
             <div class="qp-history-actions">
                 <a href="' . esc_url($practice_page_url) . '" class="qp-button qp-button-primary">Practice</a>';
         if ($can_delete) {
             echo '<button id="qp-delete-history-btn" class="qp-button qp-button-danger">Clear History</button>';
         }
         echo '</div></div>';

         // Display Table
         echo '<table class="qp-dashboard-table">
             <thead><tr><th>Date</th><th>Mode</th><th>Context</th><th>Result</th><th>Status</th><th>Actions</th></tr></thead>
             <tbody>';

         if (!empty($session_history)) {
             foreach ($session_history as $session) {
                 $settings = json_decode($session->settings_snapshot, true);
                 $mode = self::get_session_mode_name($session, $settings);
                 $subjects_display = self::get_session_subjects_display($session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map);
                 $result_display = self::get_session_result_display($session, $settings);
                 $status_display = ucfirst($session->status); // Simple status for now
                   if ($session->status === 'abandoned') $status_display = 'Abandoned';
                   if ($session->end_reason === 'autosubmitted_timer') $status_display = 'Auto-Submitted';


                 $row_class = $session->status === 'paused' ? 'class="qp-session-paused"' : '';
                 echo '<tr ' . $row_class . '>
                     <td data-label="Date">' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</td>
                     <td data-label="Mode">' . esc_html($mode) . '</td>
                     <td data-label="Context">' . esc_html($subjects_display) . '</td>
                     <td data-label="Result"><strong>' . esc_html($result_display) . '</strong></td>
                     <td data-label="Status">' . esc_html($status_display) . '</td>
                     <td data-label="Actions" class="qp-actions-cell">';

                 if ($session->status === 'paused') {
                     echo '<a href="' . esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)) . '" class="qp-button qp-button-primary">Resume</a>';
                 } else {
                     echo '<a href="' . esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)) . '" class="qp-button qp-button-secondary">Review</a>';
                 }
                 if ($can_delete) {
                     echo '<button class="qp-delete-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Delete</button>';
                 }
                 echo '</td></tr>';
             }
         } else {
             echo '<tr><td colspan="6" style="text-align: center;">You have no completed practice sessions yet.</td></tr>';
         }
         echo '</tbody></table>';
    }

} // End QP_Dashboard Class