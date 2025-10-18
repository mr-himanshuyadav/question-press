<?php
if (!defined('ABSPATH')) exit;

class QP_Dashboard
{

    public static function render()
    {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        global $wpdb;
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;

        $remaining_attempts = get_user_meta($user_id, 'qp_remaining_attempts', true);
$access_status_message = '';

if ($remaining_attempts !== '' && (int)$remaining_attempts > 0) { // Check if meta exists and is 0 or more
     // Use number_format to potentially add commas for larger numbers
     $access_status_message = 'Attempts remaining: <strong>' . number_format((int)$remaining_attempts) . '</strong>';
} else {
     // --- IMPROVED LINK GENERATION ---
     $shop_page_url = '';
     // Ensure WooCommerce functions exist before calling them
     if (function_exists('wc_get_page_id')) {
         $shop_page_id = wc_get_page_id('shop');
         if ($shop_page_id > 0) {
             $shop_page_url = get_permalink($shop_page_id);
         }
     }
     // Fallback if shop page isn't found
     if (empty($shop_page_url)) {
         $shop_page_url = home_url('/'); // Link to homepage as a last resort
         $link_text = 'Purchase Access'; // Generic text if shop page fails
     } else {
         $link_text = 'Purchase More';
     }
     $access_status_message = 'No attempts remaining. <a href="' . esc_url($shop_page_url) . '">' . esc_html($link_text) . '</a>';
     // --- END IMPROVEMENT ---
}

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

        // Fetch Review Later questions (existing logic)
        $review_questions = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                q.question_id, q.question_text, 
                subject_term.name as subject_name
             FROM {$wpdb->prefix}qp_review_later rl
             JOIN {$wpdb->prefix}qp_questions q ON rl.question_id = q.question_id
             LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
             LEFT JOIN {$wpdb->prefix}qp_term_relationships subject_rel ON g.group_id = subject_rel.object_id AND subject_rel.object_type = 'group' AND subject_rel.term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE parent = 0 AND taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'))
             LEFT JOIN {$wpdb->prefix}qp_terms subject_term ON subject_rel.term_id = subject_term.term_id
             WHERE rl.user_id = %d 
             ORDER BY rl.review_id DESC",
            $user_id
        ));

        // --- NEW: Calculate both counts for "Practice Your Mistakes" ---
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Count all questions EVER answered incorrectly
        $total_incorrect_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT question_id) FROM {$attempts_table} WHERE user_id = %d AND is_correct = 0",
            $user_id
        ));

        // Count questions NEVER answered correctly
        $correctly_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id));
        $all_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
        $never_correct_qids = array_diff($all_answered_qids, $correctly_answered_qids);
        $never_correct_count = count($never_correct_qids);

        ob_start();
?>      <div id="qp-practice-app-wrapper">
        <div class="qp-container qp-dashboard-wrapper">
            <div class="qp-profile-header">
                <div class="qp-user-info">
     <span class="qp-user-name">Welcome, <?php echo esc_html($current_user->display_name); ?>!</span>

     <span class="qp-access-status" style="font-size: 14px; color: #50575e; background-color: #e9ecef; padding: 4px 8px; border-radius: 4px;">
         <?php echo wp_kses_post($access_status_message); // Use wp_kses_post to allow the link and strong tag ?>
     </span>

     <a href="<?php echo wp_logout_url(wp_login_url()); ?>" class="qp-logout-link">(Logout)</a>
 </div>
            </div>

            <div class="qp-stats-section">
                <h3 class="qp-section-header">Lifetime Stats</h3>
                <div class="qp-overall-stats">
                    <div class="stat-item">
                        <span class="stat-label">Accuracy</span>
                        <span class="stat-value"><?php echo round($overall_accuracy, 2); ?>%</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Attempted</span>
                        <span class="stat-value"><?php echo (int)$total_attempted; ?></span>

                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Correct</span>
                        <span class="stat-value"><?php echo (int)$total_correct; ?></span>

                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Incorrect</span>
                        <span class="stat-value"><?php echo (int)$total_incorrect; ?></span>

                    </div>
                </div>
            </div>

            <div class="qp-dashboard-tabs">
                <button class="qp-tab-link active" data-tab="sessions" title="History">
                    <span class="dashicons dashicons-clock"></span>
                </button>
                <button class="qp-tab-link" data-tab="review" title="Review">
                    <span class="dashicons dashicons-star-filled"></span>
                </button>
                <button class="qp-tab-link" data-tab="progress" title="Progress">
                    <span class="dashicons dashicons-chart-bar"></span>
                </button>
            </div>

            <div id="sessions" class="qp-tab-content active">
                <?php self::render_sessions_tab_content(); // This function will be modified next 
                ?>
            </div>

            <div id="review" class="qp-tab-content">
                <div class="qp-practice-card">
                    <div class="qp-card-content">
                        <h4 id="qp-incorrect-practice-heading"
                            data-never-correct-count="<?php echo (int)$never_correct_count; ?>"
                            data-total-incorrect-count="<?php echo (int)$total_incorrect_count; ?>">
                            Practice Your Mistakes (<span><?php echo (int)$never_correct_count; ?></span>)
                        </h4>
                        <p>Create a session from questions you have not yet answered correctly.</p>
                    </div>
                    <div class="qp-card-action">
                        <button id="qp-start-incorrect-practice-btn" class="qp-button qp-button-primary">Start Practice</button>
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
                        <p><strong>Marked for Review</strong> (<?php echo count($review_questions); ?>)</p>
                        <button id="qp-start-reviewing-btn" class="qp-button qp-button-primary">Start Reviewing All</button>
                    </div>
                    <ul class="qp-review-list">
                        <?php foreach ($review_questions as $index => $q) : ?>
                            <li data-question-id="<?php echo esc_attr($q->question_id); ?>">
                                <div class="qp-review-list-q-text">
                                    <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo wp_trim_words(esc_html($q->question_text), 25, '...'); ?>
                                    <small>ID: <?php echo esc_html($q->question_id); ?> | Subject: <?php echo esc_html($q->subject_name); ?></small>
                                </div>
                                <div class="qp-review-list-actions">
                                    <button class="qp-review-list-view-btn">View</button>
                                    <button class="qp-review-list-remove-btn">Remove</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; padding: 2rem; background-color: #f9f9f9; border-radius: 8px;">You haven't marked any questions for review yet.</p>
                <?php endif; ?>
            </div>
            <div id="qp-review-modal-backdrop" style="display: none;">
                <div id="qp-review-modal-content"></div>
            </div>

            <div id="progress" class="qp-tab-content">
                <p style="text-align: center; font-style: italic; color: #50575e;">Progress of Attempts (Correct + Incorrect)</p>
                <div class="qp-progress-filters">
                    <div class="qp-form-group">
                        <label for="qp-progress-subject">Select Subject</label>
                        <select name="qp-progress-subject" id="qp-progress-subject">
                            <option value="">— Select a Subject —</option>
                            <?php
                            global $wpdb;
                            $term_table = $wpdb->prefix . 'qp_terms';
                            $tax_table = $wpdb->prefix . 'qp_taxonomies';
                            $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

                            if ($subject_tax_id) {
                                $subjects = $wpdb->get_results($wpdb->prepare(
                                    "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                                    $subject_tax_id
                                ));

                                foreach ($subjects as $subject) {
                                    echo '<option value="' . esc_attr($subject->term_id) . '">' . esc_html($subject->name) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="qp-form-group">
                        <label for="qp-progress-source">Select Source</label>
                        <select name="qp-progress-source" id="qp-progress-source" disabled>
                            <option value="">— Select a Subject First —</option>
                        </select>
                    </div>
                </div>
                <div class="qp-form-group" style="text-align: left; margin-bottom: 1.5rem;">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" id="qp-exclude-incorrect-cb" name="exclude_incorrect_attempts" value="1">
                        <span></span>
                        Exclude Incorrect Attempts
                    </label>
                </div>
                <div id="qp-progress-results-container">
                </div>
            </div>
        </div>
        </div>
<?php
        return ob_get_clean();
    }


    public static function render_sessions_tab_content()
    {
        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        $options = get_option('qp_settings');
        $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
        $review_page_id = isset($options['review_page']) ? absint($options['review_page']) : 0;
        $session_page_url = $session_page_id ? get_permalink($session_page_id) : home_url('/');
        $review_page_url = $review_page_id ? get_permalink($review_page_id) : home_url('/');

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
        $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

        // --- RESTORED: Fetch Active Sessions ---
        $active_sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('active', 'mock_test') ORDER BY start_time DESC", $user_id));
        $session_history = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned', 'paused') ORDER BY CASE WHEN status = 'paused' THEN 0 ELSE 1 END, start_time DESC", $user_id));

        $session_ids_history = wp_list_pluck($session_history, 'session_id');
        $accuracy_stats = [];
        if (!empty($session_ids_history)) {
            $ids_placeholder = implode(',', array_map('absint', $session_ids_history));
            $attempts_table = $wpdb->prefix . 'qp_user_attempts';
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
                $accuracy_stats[$result->session_id] = ($total_attempted > 0) ? round(($result->correct / $total_attempted) * 100, 2) . '%' : 'N/A';
            }
        }

        // --- NEW: Efficiently pre-fetch all necessary data to build subject lineage ---
        $all_session_qids = [];
        $all_sessions_for_lineage = array_merge($active_sessions, $session_history);
        foreach ($all_sessions_for_lineage as $session) {
            $qids = json_decode($session->question_ids_snapshot, true);
            if (is_array($qids)) {
                $all_session_qids = array_merge($all_session_qids, $qids);
            }
        }

        $lineage_cache = []; // Cache for storing the root subject of a given term
        $group_to_topic_map = []; // Map to store the linked topic for each group
        $question_to_group_map = []; // Map to store the parent group for each question

        if (!empty($all_session_qids)) {
            $unique_qids = array_unique(array_map('absint', $all_session_qids));
            $qids_placeholder = implode(',', $unique_qids);

            $tax_table = $wpdb->prefix . 'qp_taxonomies';
            $term_table = $wpdb->prefix . 'qp_terms';
            $rel_table = $wpdb->prefix . 'qp_term_relationships';
            $questions_table = $wpdb->prefix . 'qp_questions';
            $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

            // 1. Map questions to their parent group
            $q_to_g_results = $wpdb->get_results("SELECT question_id, group_id FROM {$questions_table} WHERE question_id IN ($qids_placeholder)");
            foreach($q_to_g_results as $res) {
                $question_to_group_map[$res->question_id] = $res->group_id;
            }

            // 2. Map groups to their specific topic
            $g_to_t_results = $wpdb->get_results($wpdb->prepare(
                "SELECT r.object_id, r.term_id 
                 FROM {$rel_table} r JOIN {$term_table} t ON r.term_id = t.term_id
                 WHERE r.object_type = 'group' AND t.taxonomy_id = %d", 
                $subject_tax_id
            ));
            foreach($g_to_t_results as $res) {
                 $group_to_topic_map[$res->object_id] = $res->term_id;
            }
        }

        // --- RESTORED: Display Active Sessions Section ---
        if (!empty($active_sessions)) {
            echo '<div class="qp-active-sessions-header"><h3>Active Sessions</h3></div>';
            echo '<div class="qp-active-sessions-list">';
            foreach ($active_sessions as $session) {
                $settings = json_decode($session->settings_snapshot, true);
                // Determine the mode, adding a case for our new 'paused' status.
                $mode = 'Practice'; // Default
                if ($session->status === 'paused') {
                    $mode = 'Paused';
                } elseif (isset($settings['practice_mode'])) {
                    if ($settings['practice_mode'] === 'revision') {
                        $mode = 'Revision';
                    } elseif ($settings['practice_mode'] === 'mock_test') {
                        $mode = 'Mock Test';
                    } elseif ($settings['practice_mode'] === 'Incorrect Que. Practice') {
                        $mode = 'Incorrect Practice';
                    } elseif ($settings['practice_mode'] === 'Section Wise Practice') {
                        $mode = 'Section Practice';
                    }
                } elseif (isset($settings['subject_id']) && $settings['subject_id'] === 'review') {
                    $mode = 'Review';
                }

                echo '<div class="qp-active-session-card">
<div class="qp-card-details">
    <span class="qp-card-subject">' . esc_html($mode) . '</span>
    <span class="qp-card-date">Started: ' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</span>
</div>
<div class="qp-card-actions">';
                // --- THIS IS THE FIX ---
                // Only show the Terminate button if it's NOT a Section Wise Practice session
                if ($mode !== 'Section Practice') {
                    echo '<button class="qp-button qp-button-danger qp-terminate-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Terminate</button>';
                }
                // --- END FIX ---
                echo '<a href="' . esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)) . '" class="qp-button qp-button-secondary">Continue</a>
</div>
              </div>';
            }
            echo '</div>';
        }

        // Session History
        $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');

        echo '<div class="qp-history-header">
        <h3 style="margin:0;">Practice History</h3>
        <div class="qp-history-actions">
            <a href="' . esc_url($practice_page_url) . '" class="qp-button qp-button-primary">Practice</a>';

        if ($can_delete) {
            echo '<button id="qp-delete-history-btn" class="qp-button qp-button-danger">Clear History</button>';
        }
        echo '</div></div>';

        echo '<table class="qp-dashboard-table">
    <thead><tr><th>Date</th><th>Mode</th><th>Subjects</th><th>Accuracy</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>';

        if (!empty($session_history)) {
            foreach ($session_history as $session) {
                $settings = json_decode($session->settings_snapshot, true);
                $session_qids = json_decode($session->question_ids_snapshot, true);

                // Determine the mode, adding a case for our new 'paused' status.
                $mode = 'Practice'; // Default
                if (isset($settings['practice_mode'])) {
                    if ($settings['practice_mode'] === 'revision') {
                        $mode = 'Revision';
                    } elseif ($settings['practice_mode'] === 'mock_test') { // This is the missing condition
                        $mode = 'Mock Test';
                    } elseif ($settings['practice_mode'] === 'Incorrect Que. Practice') {
                        $mode = 'Incorrect Practice';
                    } elseif ($settings['practice_mode'] === 'Section Wise Practice') {
                        $mode = 'Section Practice';
                    }
                } elseif (isset($settings['subject_id']) && $settings['subject_id'] === 'review') {
                    $mode = 'Review';
                }

                $subjects_display = 'N/A';
                if (is_array($session_qids) && !empty($session_qids)) {
                    if ($mode === 'Section Practice') {
                        // Section Wise Practice mode has its own specific display logic which is correct
                        $first_question_id = $session_qids[0];
                        $source_hierarchy = qp_get_source_hierarchy_for_question($first_question_id);
                        $subjects_display = implode(' / ', $source_hierarchy);
                    } else {
                        // --- NEW: Logic to find root subjects for all other modes ---
                        $session_subjects = [];
                        foreach ($session_qids as $qid) {
                            $gid = $question_to_group_map[$qid] ?? null;
                            $topic_id = $gid ? ($group_to_topic_map[$gid] ?? null) : null;

                            if ($topic_id) {
                                // Check cache first
                                if (isset($lineage_cache[$topic_id])) {
                                    $session_subjects[] = $lineage_cache[$topic_id];
                                } else {
                                    // Not in cache, so trace the lineage
                                    $current_term_id = $topic_id;
                                    $root_subject_name = 'N/A';
                                    for ($i = 0; $i < 10; $i++) { // Safety break
                                        $term = $wpdb->get_row($wpdb->prepare("SELECT name, parent FROM $term_table WHERE term_id = %d", $current_term_id));
                                        if (!$term || $term->parent == 0) {
                                            $root_subject_name = $term ? $term->name : 'N/A';
                                            break;
                                        }
                                        $current_term_id = $term->parent;
                                    }
                                    $lineage_cache[$topic_id] = $root_subject_name; // Cache the result
                                    $session_subjects[] = $root_subject_name;
                                }
                            }
                        }
                        $subjects_display = !empty($session_subjects) ? implode(', ', array_unique($session_subjects)) : 'N/A';
                    }
                }

                $accuracy = $accuracy_stats[$session->session_id] ?? 'N/A';

                // START: Replace this block
                $status_display = 'Completed'; // Default status
                // First, check if the end_reason property exists and has a value
                if ($session->status === 'paused') {
                    $status_display = 'Paused';
                } elseif (!empty($session->end_reason)) {
                    switch ($session->end_reason) {
                        case 'user_submitted':
                            $status_display = 'Completed';
                            break;
                        case 'autosubmitted_timer':
                            $status_display = 'Auto-Submitted';
                            break;
                        case 'abandoned_system':
                            $status_display = 'Abandoned';
                            break;
                    }
                } elseif ($session->status === 'abandoned') {
                    // Fallback for older sessions that were marked abandoned before the end_reason column existed
                    $status_display = 'Abandoned';
                }
                // END: Replacement block


                // *** THIS IS THE FIX ***
                $row_class = $session->status === 'paused' ? 'class="qp-session-paused"' : '';
                echo '<tr ' . $row_class . '>
                <td data-label="Date">' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</td>
                <td data-label="Mode">' . esc_html($mode) . '</td>
                <td data-label="Subjects">' . $subjects_display . '</td>
                <td data-label="Accuracy"><strong>' . $accuracy . '</strong></td>
                <td data-label="Status">' . esc_html($status_display) . '</td>
                <td data-label="Actions">';
                // Conditionally show "Resume" or "Review" button based on the status.
                if ($session->status === 'paused') {
                    // For paused sessions, the primary action is to Resume. It links to the session page.
                    echo '<a href="' . esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)) . '" class="qp-button qp-button-primary" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">Resume</a>';
                } else {
                    // For completed/abandoned sessions, the action is to Review.
                    echo '<a href="' . esc_url(add_query_arg('session_id', $session->session_id, $review_page_url)) . '" class="qp-button qp-button-secondary" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">Review</a>';
                }
                if ($can_delete) {
                    echo '<button class="qp-delete-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Delete</button>';
                }
                echo '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="5" style="text-align: center;">You have no completed practice sessions yet.</td></tr>';
        }
        echo '</tbody></table>';
    }
}
