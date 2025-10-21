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

        <?php // --- NEW: Add Mobile Header --- ?>
            <div id="qp-mobile-header">
                <?php // --- MOVED: Toggle Button is now here --- ?>
                <button class="qp-sidebar-toggle" aria-label="Toggle Navigation" aria-expanded="false">
                    <span class="dashicons dashicons-menu-alt"></span>
                </button>
                <span class="qp-mobile-header-title"><?php bloginfo('name'); // Optional: Add site title ?></span>
                <?php // --- END MOVE --- ?>
            </div>
            <?php // --- END NEW --- ?>
            <div class="qp-dashboard-layout">
                <?php // --- ADD THIS LINE --- ?>
                <div class="qp-sidebar-overlay"></div>
                <?php // --- END ADD --- ?>
                <aside class="qp-sidebar">
                    <div class="qp-sidebar-header" style="text-align: center; padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--qp-dashboard-border);">
                        <?php // --- ADD THIS BUTTON --- ?>
                         <button class="qp-sidebar-close-btn" aria-label="Close Navigation">
                             <span class="dashicons dashicons-no-alt"></span>
                         </button>
                         <?php // --- END ADD --- ?>
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
                        <li><a href="#courses"><span class="dashicons dashicons-welcome-learn-more"></span><span>Courses</span></a></li>
                        <?php /* Placeholder for future items */ ?>
                    </ul>
                     <div class="qp-sidebar-footer" style="position: absolute; bottom: 10px; width: 100%; text-align: center;display: flex; justify-content:center;">
                         <a href="<?php echo wp_logout_url(get_permalink()); ?>" class="qp-logout-link" style="font-size: 0.9em; color: var(--qp-dashboard-text-light); text-decoration: none;width:65%;border:1px solid #dfdfdf;justify-content:center;">
                             <span class="dashicons dashicons-exit" style="vertical-align: middle;"></span> Logout
                         </a>
                     </div>
                </aside>

                <main class="qp-main-content">
                    <section id="qp-dashboard-overview" class="qp-dashboard-section active">
                        <?php self::render_overview_content($stats, $overall_accuracy, $active_sessions, $recent_history, $review_count, $never_correct_count, $practice_page_url, $session_page_url, $review_page_url); ?>
                    </section>

                    <section id="qp-dashboard-history" class="qp-dashboard-section" style="display: none;">
                        <?php // --- MODIFIED --- ?>
                        <?php echo self::render_history_content(); ?>
                        <?php // --- END MODIFIED --- ?>
                    </section>

                    <section id="qp-dashboard-review" class="qp-dashboard-section" style="display: none;">
                         <?php // --- MODIFIED --- ?>
                         <?php echo self::render_review_content(); ?>
                         <?php // --- END MODIFIED --- ?>
                    </section>

                    <section id="qp-dashboard-progress" class="qp-dashboard-section" style="display: none;">
                         <?php // --- MODIFIED --- ?>
                         <?php echo self::render_progress_content(); ?>
                         <?php // --- END MODIFIED --- ?>
                    </section>

                    <?php // --- ADD THIS NEW SECTION --- ?>
                        <section id="qp-dashboard-courses" class="qp-dashboard-section" style="display: none;">
                            <?php echo self::render_courses_content(); // We will create this method next ?>
                        </section>
                    <?php // --- END NEW SECTION --- ?>

                     <?php /* Hidden modal (Keep from old code) */ ?>
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
     * Renders the content specifically for the History section.
     * (Formerly part of render_sessions_tab_content)
     */
    private static function render_history_content() {
        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts'; // Needed for accuracy calc

        $options = get_option('qp_settings');
        $session_page_url = isset($options['session_page']) ? get_permalink($options['session_page']) : home_url('/');
        $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/');
        $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

        // Fetch Paused Sessions (Order them first)
        $paused_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE user_id = %d AND status = 'paused' ORDER BY start_time DESC",
            $user_id
        ));

         // Fetch Completed/Abandoned Sessions (Order them after paused)
        $session_history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC",
            $user_id
        ));

         // Combine paused and history
         $all_sessions_for_history = array_merge($paused_sessions, $session_history);


        // Pre-fetch lineage data
        list($lineage_cache, $group_to_topic_map, $question_to_group_map) = self::prefetch_lineage_data($all_sessions_for_history);

        // Fetch accuracy stats efficiently
        $session_ids_history = wp_list_pluck($all_sessions_for_history, 'session_id');
        $accuracy_stats = [];
         if (!empty($session_ids_history)) {
             $ids_placeholder = implode(',', array_map('absint', $session_ids_history));
             $results = $wpdb->get_results(
                 "SELECT session_id,
                     COUNT(CASE WHEN is_correct = 1 THEN 1 END) as correct,
                     COUNT(CASE WHEN is_correct = 0 THEN 1 END) as incorrect
              FROM {$attempts_table}
              WHERE session_id IN ({$ids_placeholder}) AND status = 'answered'
              GROUP BY session_id"
             );
             foreach ($results as $result) {
                 $total_attempted = $result->correct + $result->incorrect;
                 $accuracy = ($total_attempted > 0) ? (($result->correct / $total_attempted) * 100) : 0;
                 $accuracy_stats[$result->session_id] = number_format($accuracy, 2) . '%';
             }
         }


        ob_start();
        ?>
        <div class="qp-history-header">
             <h2 style="margin:0;">Practice History</h2>
             <div class="qp-history-actions">
                 <a href="<?php echo esc_url($practice_page_url); ?>" class="qp-button qp-button-primary">Start New Practice</a>
                 <?php if ($can_delete) : ?>
                     <button id="qp-delete-history-btn" class="qp-button qp-button-danger">Clear History</button>
                 <?php endif; ?>
             </div>
         </div>

         <table class="qp-dashboard-table qp-full-history-table">
             <thead>
                 <tr>
                     <th>Date</th>
                     <th>Mode</th>
                     <th>Context</th>
                     <th>Result</th>
                     <th>Status</th>
                     <th>Actions</th>
                 </tr>
             </thead>
             <tbody>
                 <?php if (!empty($all_sessions_for_history)) : ?>
                     <?php foreach ($all_sessions_for_history as $session) :
                        $settings = json_decode($session->settings_snapshot, true);
                        $mode = self::get_session_mode_name($session, $settings);
                        $subjects_display = self::get_session_subjects_display($session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map);
                        // Use pre-calculated stats for accuracy, otherwise fallback for scored sessions
                        if (isset($accuracy_stats[$session->session_id]) && !$is_scored) {
                            $result_display = $accuracy_stats[$session->session_id];
                        } else {
                            $result_display = self::get_session_result_display($session, $settings);
                        }
                         $status_display = ucfirst($session->status);
                         if ($session->status === 'abandoned') $status_display = 'Abandoned';
                         if ($session->end_reason === 'autosubmitted_timer') $status_display = 'Auto-Submitted';

                         $row_class = $session->status === 'paused' ? 'class="qp-session-paused"' : '';
                     ?>
                     <?php
                         // *** START NEW CHECK ***
                         $context_display = $subjects_display; // Default context
                         if (isset($settings['course_id']) && isset($settings['item_id'])) {
                             // Check if the item ID exists in the pre-fetched list (efficient)
                             // We need to pre-fetch existing item IDs before the loop
                             if (!isset($existing_course_item_ids)) {
                                 $items_table = $wpdb->prefix . 'qp_course_items';
                                 $existing_course_item_ids = $wpdb->get_col("SELECT item_id FROM $items_table");
                                 // Convert to a hash map for quick lookups
                                 $existing_course_item_ids = array_flip($existing_course_item_ids);
                             }

                             if (!isset($existing_course_item_ids[absint($settings['item_id'])])) {
                                 // If item ID is NOT in the list of existing items
                                 $context_display .= ' <em style="color:#777; font-size:0.9em;">(Item removed)</em>';
                             }
                         }
                         // *** END NEW CHECK ***
                     ?>
                     <tr <?php echo $row_class; ?>>
                         <td data-label="Date"><?php echo date_format(date_create($session->start_time), 'M j, Y, g:i a'); ?></td>
                         <td data-label="Mode"><?php echo esc_html($mode); ?></td>
                         <td data-label="Context"><?php echo wp_kses_post($context_display); // Use wp_kses_post to allow <em> tag ?></td>
                         <td data-label="Result"><strong><?php echo esc_html($result_display); ?></strong></td>
                         <td data-label="Status"><?php echo esc_html($status_display); ?></td>
                         <td data-label="Actions" class="qp-actions-cell">
                             <?php if ($session->status === 'paused') : ?>
                                 <a href="<?php echo esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)); ?>" class="qp-button qp-button-primary">Resume</a>
                             <?php else : ?>
                                 <a href="<?php echo esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)); ?>" class="qp-button qp-button-secondary">Review</a>
                             <?php endif; ?>
                             <?php if ($can_delete) : ?>
                                 <button class="qp-delete-session-btn" data-session-id="<?php echo esc_attr($session->session_id); ?>">Delete</button>
                             <?php endif; ?>
                         </td>
                     </tr>
                     <?php endforeach; ?>
                 <?php else : ?>
                     <tr><td colspan="6" style="text-align: center; padding: 2rem;">You have no practice sessions yet.</td></tr>
                 <?php endif; ?>
             </tbody>
         </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the content specifically for the Review section.
     */
    private static function render_review_content() {
        global $wpdb;
        $user_id = get_current_user_id();
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

         // Fetch Review Later questions
         $review_questions = $wpdb->get_results($wpdb->prepare(
             "SELECT
                 q.question_id, q.question_text,
                 subject_term.name as subject_name
              FROM {$wpdb->prefix}qp_review_later rl
              JOIN {$wpdb->prefix}qp_questions q ON rl.question_id = q.question_id
              LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
              LEFT JOIN {$wpdb->prefix}qp_term_relationships topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group' AND topic_rel.term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE parent != 0 AND taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'))
               LEFT JOIN {$wpdb->prefix}qp_terms topic_term ON topic_rel.term_id = topic_term.term_id
              LEFT JOIN {$wpdb->prefix}qp_terms subject_term ON topic_term.parent = subject_term.term_id
              WHERE rl.user_id = %d
              ORDER BY rl.review_id DESC",
             $user_id
         ));

        // Calculate counts for "Practice Your Mistakes"
         $total_incorrect_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT question_id) FROM {$attempts_table} WHERE user_id = %d AND is_correct = 0", $user_id
         ));
         $correctly_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id));
         $all_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
         $never_correct_qids = array_diff($all_answered_qids, $correctly_answered_qids);
         $never_correct_count = count($never_correct_qids);

        ob_start();
        ?>
        <h2>Review Center</h2>
        <div class="qp-practice-card qp-card"> <?php // Add qp-card class ?>
             <div class="qp-card-content">
                 <h4 id="qp-incorrect-practice-heading"
                     data-never-correct-count="<?php echo (int)$never_correct_count; ?>"
                     data-total-incorrect-count="<?php echo (int)$total_incorrect_count; ?>">
                     Practice Your Mistakes (<span><?php echo (int)$never_correct_count; ?></span>)
                 </h4>
                 <p>Create a session from questions you have not yet answered correctly, or optionally include all past mistakes.</p>
             </div>
             <div class="qp-card-action">
                 <button id="qp-start-incorrect-practice-btn" class="qp-button qp-button-primary" <?php disabled($never_correct_count + $total_incorrect_count, 0); ?>>Start Practice</button>
                 <label class="qp-custom-checkbox">
                     <input type="checkbox" id="qp-include-all-incorrect-cb" name="include_all_incorrect" value="1">
                     <span></span>
                     Include all past mistakes
                 </label>
             </div>
         </div>

         <hr class="qp-divider">

         <?php if (!empty($review_questions)) : ?>
             <div class="qp-review-list-header">
                 <h3 style="margin: 0;">Marked for Review (<?php echo count($review_questions); ?>)</h3>
                 <button id="qp-start-reviewing-btn" class="qp-button qp-button-primary">Start Reviewing All</button>
             </div>
             <ul class="qp-review-list">
                 <?php foreach ($review_questions as $index => $q) : ?>
                     <li data-question-id="<?php echo esc_attr($q->question_id); ?>">
                         <div class="qp-review-list-q-text">
                             <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo wp_trim_words(esc_html(strip_tags($q->question_text)), 25, '...'); // Strip tags for display ?>
                             <small>ID: <?php echo esc_html($q->question_id); ?> | Subject: <?php echo esc_html($q->subject_name ?: 'N/A'); ?></small>
                         </div>
                         <div class="qp-review-list-actions">
                             <button class="qp-review-list-view-btn qp-button qp-button-secondary">View</button>
                             <button class="qp-review-list-remove-btn qp-button qp-button-danger">Remove</button>
                         </div>
                     </li>
                 <?php endforeach; ?>
             </ul>
         <?php else : ?>
             <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">You haven't marked any questions for review yet.</p></div></div>
         <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the content specifically for the Progress section.
     */
    private static function render_progress_content() {
         global $wpdb;
         $term_table = $wpdb->prefix . 'qp_terms';
         $tax_table = $wpdb->prefix . 'qp_taxonomies';
         $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
         $subjects = [];
         if ($subject_tax_id) {
             $subjects = $wpdb->get_results($wpdb->prepare(
                 "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                 $subject_tax_id
             ));
         }

        ob_start();
        ?>
        <h2>Progress Tracker</h2>
        <p style="text-align: center; font-style: italic; color: var(--qp-dashboard-text-light);">Track your completion progress by subject and source.</p>

        <div class="qp-card"> <?php // Wrap filters in a card ?>
         <div class="qp-card-content">
             <div class="qp-progress-filters">
                 <div class="qp-form-group">
                     <label for="qp-progress-subject">Select Subject</label>
                     <select name="qp-progress-subject" id="qp-progress-subject">
                         <option value="">— Select a Subject —</option>
                         <?php foreach ($subjects as $subject) : ?>
                             <option value="<?php echo esc_attr($subject->term_id); ?>"><?php echo esc_html($subject->name); ?></option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                 <div class="qp-form-group">
                     <label for="qp-progress-source">Select Source</label>
                     <select name="qp-progress-source" id="qp-progress-source" disabled>
                         <option value="">— Select a Subject First —</option>
                     </select>
                 </div>
                  <div class="qp-form-group" style="align-self: flex-end;"> <?php // Align checkbox lower ?>
                     <label class="qp-custom-checkbox">
                         <input type="checkbox" id="qp-exclude-incorrect-cb" name="exclude_incorrect_attempts" value="1">
                         <span></span>
                         Count Correct Only
                     </label>
                 </div>
             </div>
         </div>
        </div>

         <div id="qp-progress-results-container" style="margin-top: 1.5rem;">
             <?php // Results will be loaded here via AJAX ?>
              <p style="text-align: center; color: var(--qp-dashboard-text-light);">Please select a subject and source to view your progress.</p>
         </div>
        <?php
        return ob_get_clean();
    }

/**
     * Renders the content specifically for the Courses section.
     * NOW INCLUDES Enrollment Status and Progress.
     */
    private static function render_courses_content() {
        if (!is_user_logged_in()) return ''; // Should not happen here, but good practice

        $user_id = get_current_user_id();
        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $items_table = $wpdb->prefix . 'qp_course_items';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';

        // Get IDs of courses the user is enrolled in
        $enrolled_course_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT course_id FROM $user_courses_table WHERE user_id = %d",
            $user_id
        ));

        // Get all published courses
        $args = [
            'post_type' => 'qp_course',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
        ];
        $courses_query = new WP_Query($args);

        // Prepare data for enrolled courses (progress calculation)
        $enrolled_courses_data = [];
        if (!empty($enrolled_course_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $enrolled_course_ids));

            // Get total item count for each enrolled course
            $total_items_results = $wpdb->get_results(
                "SELECT course_id, COUNT(item_id) as total_items FROM $items_table WHERE course_id IN ($ids_placeholder) GROUP BY course_id",
                OBJECT_K // Key by course_id
            );

            // Get completed item count for the user in each enrolled course
            $completed_items_results = $wpdb->get_results($wpdb->prepare(
                "SELECT course_id, COUNT(user_item_id) as completed_items FROM $progress_table WHERE user_id = %d AND course_id IN ($ids_placeholder) AND status = 'completed' GROUP BY course_id",
                $user_id
            ), OBJECT_K); // Key by course_id

            // Calculate progress
            foreach ($enrolled_course_ids as $course_id) {
                $total_items = $total_items_results[$course_id]->total_items ?? 0;
                $completed_items = $completed_items_results[$course_id]->completed_items ?? 0;
                $progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;
                $enrolled_courses_data[$course_id] = [
                    'progress' => $progress_percent,
                    'is_complete' => ($total_items > 0 && $completed_items >= $total_items)
                ];
            }
        }


        ob_start();
        ?>
        <h2>My Courses</h2>

        <?php if (!empty($enrolled_course_ids)) : ?>
            <div class="qp-course-list qp-enrolled-courses">
                <?php while ($courses_query->have_posts()) : $courses_query->the_post(); ?>
                    <?php if (in_array(get_the_ID(), $enrolled_course_ids)) :
                        $course_data = $enrolled_courses_data[get_the_ID()];
                        $progress = $course_data['progress'];
                        $is_complete = $course_data['is_complete'];
                    ?>
                        <div class="qp-card qp-course-item">
                            <div class="qp-card-content">
                                <h3 style="margin-top:0;"><?php the_title(); ?></h3>
                                <?php // --- Progress Bar --- ?>
                                <div class="qp-progress-bar-container" title="<?php echo esc_attr($progress); ?>% Complete">
                                    <div class="qp-progress-bar-fill" style="width: <?php echo esc_attr($progress); ?>%;"></div>
                                </div>
                                <?php if (has_excerpt()) : ?>
                                    <p><?php the_excerpt(); ?></p>
                                <?php else : ?>
                                    <?php echo '<p>' . wp_trim_words(get_the_content(), 30, '...') . '</p>'; ?>
                                <?php endif; ?>
                            </div>
                            <div class="qp-card-action" style="padding: 1rem 1.5rem; border-top: 1px solid var(--qp-dashboard-border-light); text-align: right;">
                                 <button class="qp-button qp-button-primary qp-view-course-btn" data-course-id="<?php echo get_the_ID(); ?>">
                                     <?php echo $is_complete ? 'View Course' : 'Continue Course'; ?>
                                 </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endwhile; ?>
                <?php rewind_posts(); // Rewind the query to loop again for available courses ?>
            </div>
            <hr class="qp-divider" style="margin: 2rem 0;">
            <h2>Available Courses</h2>
        <?php endif; ?>

        <?php // --- List Available Courses --- ?>
        <?php if ($courses_query->have_posts()) : ?>
            <div class="qp-course-list qp-available-courses">
                 <?php
                 $found_available = false;
                 while ($courses_query->have_posts()) : $courses_query->the_post(); ?>
                    <?php if (!in_array(get_the_ID(), $enrolled_course_ids)) :
                        $found_available = true;
                    ?>
                        <div class="qp-card qp-course-item">
                            <div class="qp-card-content">
                                <h3 style="margin-top:0;"><?php the_title(); ?></h3>
                                <?php if (has_excerpt()) : ?>
                                    <p><?php the_excerpt(); ?></p>
                                <?php else : ?>
                                    <?php echo '<p>' . wp_trim_words(get_the_content(), 30, '...') . '</p>'; ?>
                                <?php endif; ?>
                            </div>
                            <div class="qp-card-action" style="padding: 1rem 1.5rem; border-top: 1px solid var(--qp-dashboard-border-light); text-align: right;">
                                 <button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="<?php echo get_the_ID(); ?>">
                                     Enroll Now
                                 </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endwhile; ?>
                <?php wp_reset_postdata(); ?>

                <?php if (!$found_available && empty($enrolled_course_ids)) : // If no courses at all ?>
                     <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">No courses are available at the moment.</p></div></div>
                <?php elseif (!$found_available) : // If enrolled in all available courses ?>
                     <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">You are enrolled in all available courses.</p></div></div>
                <?php endif; ?>
            </div>
        <?php elseif (empty($enrolled_course_ids)) : // No courses exist at all ?>
            <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">No courses are available at the moment.</p></div></div>
        <?php endif; ?>


        <?php // --- Add CSS specific for this new section --- ?>
        <style>
            .qp-course-list {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 1.5rem;
            }
            .qp-course-item .qp-card-content p {
                 color: var(--qp-dashboard-text-light);
                 font-size: 0.95em;
                 line-height: 1.6;
                 margin-bottom: 1rem; /* Add margin below description */
            }
            .qp-progress-bar-container {
                height: 8px;
                background-color: var(--qp-dashboard-border-light);
                border-radius: 4px;
                overflow: hidden;
                margin-bottom: 1rem; /* Space between bar and description */
            }
            .qp-progress-bar-fill {
                height: 100%;
                background-color: var(--qp-dashboard-success); /* Use success color */
                transition: width 0.5s ease-in-out;
                border-radius: 4px;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to get the updated course list HTML.
     */
    public static function get_course_list_ajax() {
        check_ajax_referer('qp_practice_nonce', 'nonce'); // Re-use the frontend nonce for simplicity

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in.']);
        }

        // We can simply call the existing render function to get the HTML
        $course_list_html = self::render_courses_content();

        wp_send_json_success(['html' => $course_list_html]);
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
             // Calculate accuracy
             $accuracy = ($total_attempted > 0) ? (((int) $session->correct_count / $total_attempted) * 100) : 0;
             // Format to two decimal places and add '%'
             return number_format($accuracy, 2) . '%';
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