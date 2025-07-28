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

        // --- **THE FIX**: Calculate Lifetime User Stats ---
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
            SUM(total_attempted) as total_attempted,
            SUM(correct_count) as total_correct,
            SUM(incorrect_count) as total_incorrect
         FROM {$sessions_table} 
         WHERE user_id = %d AND status = 'completed'",
            $user_id
        ));

        $total_attempted = $stats->total_attempted ?? 0;
        $total_correct = $stats->total_correct ?? 0;
        $total_incorrect = $stats->total_incorrect ?? 0;
        $overall_accuracy = ($total_attempted > 0) ? ($total_correct / $total_attempted) * 100 : 0;

        // Fetch Review Later questions (existing logic)
        $review_questions = $wpdb->get_results($wpdb->prepare(
            "SELECT q.question_id, q.custom_question_id, q.question_text, s.subject_name 
         FROM {$wpdb->prefix}qp_review_later rl
         JOIN {$wpdb->prefix}qp_questions q ON rl.question_id = q.question_id
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
         LEFT JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
         WHERE rl.user_id = %d ORDER BY rl.review_id DESC",
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
?>
        <div class="qp-container qp-dashboard-wrapper">
            <div class="qp-profile-header">
                <div class="qp-user-info">
                    <span class="qp-user-name">Welcome, <?php echo esc_html($current_user->display_name); ?>!</span>
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
                                    <small>ID: <?php echo esc_html($q->custom_question_id); ?> | Subject: <?php echo esc_html($q->subject_name); ?></small>
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
                            
                            $subjects = $wpdb->get_results($wpdb->prepare(
                                "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
                                $subject_tax_id
                            ));

                            foreach ($subjects as $subject) {
                                echo '<option value="' . esc_attr($subject->term_id) . '">' . esc_html($subject->name) . '</option>';
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
<?php
        return ob_get_clean();
    }


    public static function render_sessions_tab_content()
    {
        global $wpdb;
        $user_id = get_current_user_id();
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $subjects_table = $wpdb->prefix . 'qp_subjects';

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
        $session_history = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned', 'paused') ORDER BY start_time DESC", $user_id));

        // Pre-fetch all subjects for all questions in the user's history to optimize queries
        $all_session_qids = [];
        $all_sessions_for_subjects = array_merge($active_sessions, $session_history); // Combine for one query
        foreach ($all_sessions_for_subjects as $session) {
            $qids = json_decode($session->question_ids_snapshot, true);
            if (is_array($qids)) {
                $all_session_qids = array_merge($all_session_qids, $qids);
            }
        }

        $subjects_by_question = [];
        if (!empty($all_session_qids)) {
            $unique_qids = array_unique(array_map('absint', $all_session_qids));
            $ids_placeholder = implode(',', $unique_qids);
            $subject_results = $wpdb->get_results(
                "SELECT q.question_id, s.subject_name
             FROM {$wpdb->prefix}qp_questions q
             JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
             JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
             WHERE q.question_id IN ($ids_placeholder)"
            );
            foreach ($subject_results as $res) {
                $subjects_by_question[$res->question_id] = $res->subject_name;
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

                echo '<div class="qp-active-session-card">
                <div class="qp-card-details">
                    <span class="qp-card-subject">' . esc_html($mode) . '</span>
                    <span class="qp-card-date">Started: ' . date_format(date_create($session->start_time), 'M j, Y, g:i a') . '</span>
                </div>
                <div class="qp-card-actions">
                    <button class="qp-button qp-button-danger qp-terminate-session-btn" data-session-id="' . esc_attr($session->session_id) . '">Terminate</button>
                    <a href="' . esc_url(add_query_arg('session_id', $session->session_id, $session_page_url)) . '" class="qp-button qp-button-secondary">Continue</a>
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
                if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice' && !empty($settings['section_id'])) {
                    // For Section Wise Practice, build the specific "Source / Topic / Section" string
                    $section_id = absint($settings['section_id']);
                    $topic_id = !empty($settings['topics']) ? absint($settings['topics'][0]) : 0;

                    // Fetch the names from the database
                    $section_info = $wpdb->get_row($wpdb->prepare(
                        "SELECT sec.section_name, src.source_name
                     FROM {$wpdb->prefix}qp_source_sections sec
                     JOIN {$wpdb->prefix}qp_sources src ON sec.source_id = src.source_id
                     WHERE sec.section_id = %d",
                        $section_id
                    ));

                    $topic_name = $wpdb->get_var($wpdb->prepare("SELECT topic_name FROM {$wpdb->prefix}qp_topics WHERE topic_id = %d", $topic_id));

                    $display_parts = [];
                    if ($section_info && $section_info->source_name) $display_parts[] = esc_html($section_info->source_name);
                    if ($topic_name) $display_parts[] = esc_html($topic_name);
                    if ($section_info && $section_info->section_name) $display_parts[] = esc_html($section_info->section_name);

                    if (!empty($display_parts)) {
                        $subjects_display = implode(' / ', $display_parts);
                    }
                } else {
                    // Original logic for all other modes
                    $session_subjects = [];
                    if (is_array($session_qids)) {
                        foreach ($session_qids as $qid) {
                            if (isset($subjects_by_question[$qid])) {
                                $session_subjects[$subjects_by_question[$qid]] = true;
                            }
                        }
                    }
                    if (!empty($session_subjects)) {
                        $subjects_display = implode(', ', array_keys($session_subjects));
                    }
                }

                $accuracy = ($session->total_attempted > 0) ? round(($session->correct_count / $session->total_attempted) * 100, 2) . '%' : 'N/A';

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

                

                echo '<tr>
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
