<?php
if (!defined('ABSPATH')) exit;

use QuestionPress\Database\Terms_DB;

class QP_Dashboard
{

    public static function render()
    {
        if (!is_user_logged_in()) {
            // Keep login message logic here for now
            return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        // --- Fetch common data ---
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        // --- Entitlement Summary Logic (Keep as is) ---
        $access_status_message = '';
        // ... (existing logic to fetch and format $access_status_message) ...
        global $wpdb; // Ensure $wpdb is available
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $current_time = current_time('mysql');
        $active_entitlements_for_display = $wpdb->get_results($wpdb->prepare( /* ... existing query ... */
            "SELECT e.entitlement_id, e.plan_id, e.remaining_attempts, e.expiry_date, p.post_title as plan_title
             FROM {$entitlements_table} e
             LEFT JOIN {$wpdb->posts} p ON e.plan_id = p.ID
             WHERE e.user_id = %d AND e.status = 'active' AND (e.expiry_date IS NULL OR e.expiry_date > %s)
             ORDER BY e.expiry_date ASC, e.entitlement_id ASC",
            $user_id, $current_time
        ));
        // ... (existing logic to build $access_status_message) ...
        $shop_page_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/');
        $link_text = empty($shop_page_url) ? 'Purchase Access' : 'Purchase More';
        $entitlement_summary = [];
         if (!empty($active_entitlements_for_display)) {
            foreach ($active_entitlements_for_display as $entitlement) {
                $clean_plan_title = preg_replace('/^Auto: Access Plan for Course "([^"]+)"$/', '$1', $entitlement->plan_title);
                $summary_line = '<strong>' . esc_html($clean_plan_title) . '</strong>';
                $details = [];
                if (!is_null($entitlement->remaining_attempts)) {
                    $details[] = number_format_i18n($entitlement->remaining_attempts) . ' attempts left';
                } else {
                    $details[] = 'Unlimited attempts';
                }
                if (!is_null($entitlement->expiry_date)) {
                    $expiry_timestamp = strtotime($entitlement->expiry_date);
                    $details[] = 'expires ' . date_i18n(get_option('date_format'), $expiry_timestamp);
                } else {
                    $details[] = 'never expires';
                }
                $summary_line .= ': ' . implode(', ', $details);
                $entitlement_summary[] = $summary_line;
            }
            $access_status_message = implode('<br>', $entitlement_summary);
        } else {
            $access_status_message = 'No active plan found. <a href="' . esc_url($shop_page_url) . '">' . esc_html($link_text) . '</a>';
        }


        // --- Determine active tab ---
        $current_tab = get_query_var('qp_tab', 'overview');
        $current_course_slug = get_query_var('qp_course_slug');

        // --- Get Sidebar HTML ---
        // Call the refactored method which now returns HTML
        $sidebar_html = self::render_sidebar($current_user, $access_status_message, $current_tab);

        // --- Get Main Content HTML ---
        // This part still uses output buffering within the specific content methods for now
        ob_start();
        // --- Main Conditional Rendering Logic (Keep as is) ---
        if ($current_tab === 'courses' && !empty($current_course_slug)) {
            self::render_single_course_view($current_course_slug, $user_id);
        } elseif ($current_tab === 'courses') {
            echo self::render_courses_content();
        } elseif ($current_tab === 'history') {
            echo self::render_history_content();
        } elseif ($current_tab === 'review') {
            echo self::render_review_content();
        } elseif ($current_tab === 'progress') {
            echo self::render_progress_content();
        } elseif ($current_tab === 'profile') {
            echo self::render_profile_content();
        } else {
            $attempts_table = $wpdb->prefix . 'qp_user_attempts';
            $stats = $wpdb->get_row($wpdb->prepare( "SELECT COUNT(CASE WHEN status = 'answered' THEN 1 END) as total_attempted, COUNT(CASE WHEN is_correct = 1 THEN 1 END) as total_correct, COUNT(CASE WHEN is_correct = 0 THEN 1 END) as total_incorrect FROM {$attempts_table} WHERE user_id = %d", $user_id)); // Added incorrect count
            $total_attempted = $stats->total_attempted ?? 0;
            $total_correct = $stats->total_correct ?? 0;
            $overall_accuracy = ($total_attempted > 0) ? ($total_correct / $total_attempted) * 100 : 0;
            $sessions_table = $wpdb->prefix . 'qp_user_sessions';
            $active_sessions = $wpdb->get_results($wpdb->prepare( "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('active', 'mock_test', 'paused') ORDER BY start_time DESC", $user_id));
            $recent_history = $wpdb->get_results($wpdb->prepare( "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC LIMIT 5", $user_id));
            $review_count = $wpdb->get_var($wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d", $user_id));
            $correctly_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id));
            $all_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
            $never_correct_qids = array_diff($all_answered_qids, $correctly_answered_qids);
            $never_correct_count = count($never_correct_qids);
            $options = get_option('qp_settings');
            $practice_page_url = isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/');
            $session_page_url = isset($options['session_page']) ? get_permalink($options['session_page']) : home_url('/');
            $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/');

            // Call overview rendering (Keep as is - echoes internally)
            self::render_overview_content($stats, $overall_accuracy, $active_sessions, $recent_history, $review_count, $never_correct_count, $practice_page_url, $session_page_url, $review_page_url);
        }
        $main_content_html = ob_get_clean();
        // --- End Main Content HTML ---

        // --- Load Wrapper Template ---
        // Pass sidebar and main content HTML to the wrapper template
        return qp_get_template_html('dashboard-wrapper', 'frontend', [
            'sidebar_html' => $sidebar_html,
            'main_content_html' => $main_content_html
        ]);
    }

    /**
     * Renders the sidebar HTML by loading the template.
     * NOW RETURNS the HTML string.
     *
     * @param \WP_User $current_user        The current user object.
     * @param string   $access_status_message HTML access status message.
     * @param string   $active_tab          Slug of the active tab.
     * @return string  The rendered sidebar HTML.
     */
    private static function render_sidebar($current_user, $access_status_message, $active_tab)
    {
        $options = get_option('qp_settings');
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
        $base_dashboard_url = $dashboard_page_id ? trailingslashit(get_permalink($dashboard_page_id)) : trailingslashit(home_url());

        $tabs = [
            'overview' => ['label' => 'Overview', 'icon' => 'chart-pie'],
            'history'  => ['label' => 'History', 'icon' => 'list-view'],
            'review'   => ['label' => 'Review', 'icon' => 'star-filled'],
            'progress' => ['label' => 'Progress', 'icon' => 'chart-bar'],
            'courses'  => ['label' => 'Courses', 'icon' => 'welcome-learn-more'],
            'profile'  => ['label' => 'Profile', 'icon' => 'admin-users'],
        ];

        // --- Get Avatar HTML ---
        $custom_avatar_id = get_user_meta($current_user->ID, '_qp_avatar_attachment_id', true);
        $sidebar_avatar_url = '';
        if (!empty($custom_avatar_id)) {
            $sidebar_avatar_url = wp_get_attachment_image_url(absint($custom_avatar_id), [64, 64]);
        }
        if (!empty($sidebar_avatar_url)) {
            $avatar_html = '<img src="' . esc_url($sidebar_avatar_url) . '" alt="Profile Picture" width="64" height="64" class="avatar avatar-64 photo">';
        } else {
            $avatar_html = get_avatar($current_user->ID, 64);
        }
        // --- End Avatar HTML ---

        $logout_url = wp_logout_url(get_permalink()); // Use current page as redirect_to

        // Prepare arguments for the template
        $args = [
            'current_user'         => $current_user,
            'access_status_message'=> $access_status_message,
            'active_tab'           => $active_tab,
            'tabs'                 => $tabs,
            'base_dashboard_url'   => $base_dashboard_url,
            'logout_url'           => $logout_url,
            'avatar_html'          => $avatar_html
        ];

        // Load and return the template HTML
        return qp_get_template_html('dashboard-sidebar', 'frontend', $args);
    }

    /**
     * Renders the view for a single specific course, fetching its structure and user progress.
     */
    private static function render_single_course_view($course_slug, $user_id)
    {
        $course_post = get_page_by_path($course_slug, OBJECT, 'qp_course'); // Get course WP_Post object by slug

        // --- Basic Course Validation ---
        if (!$course_post || $course_post->post_type !== 'qp_course') {
            echo '<div class="qp-card"><div class="qp-card-content"><p>Error: Course not found.</p></div></div>';
            $options = get_option('qp_settings');
            $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
            $base_dashboard_url = $dashboard_page_id ? trailingslashit(get_permalink($dashboard_page_id)) : trailingslashit(home_url());
            echo '<a href="' . esc_url($base_dashboard_url . 'courses/') . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Courses</a>';
            return;
        }
        $course_id = $course_post->ID;

        // --- Access Check ---
        if (!qp_user_can_access_course($user_id, $course_id)) {
            echo '<div class="qp-card"><div class="qp-card-content"><p>You do not have permission to view this course.</p></div></div>';
            $options = get_option('qp_settings');
            $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
            $base_dashboard_url = $dashboard_page_id ? trailingslashit(get_permalink($dashboard_page_id)) : trailingslashit(home_url());
            echo '<a href="' . esc_url($base_dashboard_url . 'courses/') . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Courses</a>';
            return;
        }

        // --- Fetch Structure Data (Similar to old AJAX handler) ---
        global $wpdb;
        $sections_table = $wpdb->prefix . 'qp_course_sections';
        $items_table = $wpdb->prefix . 'qp_course_items';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';

        // Get sections
        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
            $course_id
        ));

        $items_by_section = [];
        $all_items_in_course = []; // Store all items flat for progress fetching

        if (!empty($sections)) {
            $section_ids = wp_list_pluck($sections, 'section_id');
            $ids_placeholder = implode(',', array_map('absint', $section_ids));

            // Get all items for these sections
            $items_raw = $wpdb->get_results(
                "SELECT item_id, section_id, title, item_order, content_type, content_config
             FROM $items_table
             WHERE section_id IN ($ids_placeholder)
             ORDER BY item_order ASC"
            );
            $all_items_in_course = $items_raw; // Store for progress lookup

            // Organize items by section
            foreach ($items_raw as $item) {
                if (!isset($items_by_section[$item->section_id])) {
                    $items_by_section[$item->section_id] = [];
                }
                $items_by_section[$item->section_id][] = $item;
            }
        }

        // Fetch user's progress for all items in this course in one query
        $item_ids_in_course = wp_list_pluck($all_items_in_course, 'item_id');
        $progress_data = [];
        if (!empty($item_ids_in_course)) {
            $item_ids_placeholder = implode(',', array_map('absint', $item_ids_in_course));
            $progress_raw = $wpdb->get_results($wpdb->prepare(
                "SELECT item_id, status, result_data FROM $progress_table WHERE user_id = %d AND item_id IN ($item_ids_placeholder)",
                $user_id
            ), OBJECT_K); // Keyed by item_id

            // Process progress data to extract session_id
            foreach ($progress_raw as $item_id => $prog) {
                $session_id = null;
                if (!empty($prog->result_data)) {
                    $result_data_decoded = json_decode($prog->result_data, true);
                    if (isset($result_data_decoded['session_id'])) {
                        $session_id = absint($result_data_decoded['session_id']);
                    }
                }
                $progress_data[$item_id] = [
                    'status' => $prog->status,
                    'session_id' => $session_id
                ];
            }
        }

        // --- Render Structure HTML ---
        echo '<div class="qp-course-structure-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">';
        echo '<h2>' . esc_html(get_the_title($course_id)) . '</h2>';
        // Get base URL for back button
        $options = get_option('qp_settings');
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
        $base_dashboard_url = $dashboard_page_id ? trailingslashit(get_permalink($dashboard_page_id)) : trailingslashit(home_url());
        echo '<a href="' . esc_url($base_dashboard_url . 'courses/') . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Courses</a>';
        echo '</div>'; // Close qp-course-structure-header

        echo '<div class="qp-course-structure-content">';

        if (!empty($sections)) {
            foreach ($sections as $section) {
        ?>
                <div class="qp-course-section-card qp-card">
                    <div class="qp-card-header">
                        <h3><?php echo esc_html($section->title); ?></h3>
                        <?php if (!empty($section->description)) : ?>
                            <p style="font-size: 0.9em; color: var(--qp-dashboard-text-light); margin-top: 5px;"><?php echo esc_html($section->description); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="qp-card-content qp-course-items-list">
                        <?php
                        $items = $items_by_section[$section->section_id] ?? [];
                        if (!empty($items)) {
                            foreach ($items as $item) {
                                $item_progress = $progress_data[$item->item_id] ?? ['status' => 'not_started', 'session_id' => null];
                                $status = $item_progress['status'];
                                $session_id_attr = $item_progress['session_id'] ? ' data-session-id="' . esc_attr($item_progress['session_id']) . '"' : '';

                                $status_icon = '';
                                $button_text = 'Start';
                                $button_class = 'qp-button-primary start-course-test-btn'; // Default to test start

                                switch ($status) {
                                    case 'completed':
                                        $status_icon = '<span class="dashicons dashicons-yes-alt" style="color: var(--qp-dashboard-success);"></span>';
                                        $button_text = 'Review';
                                        $button_class = 'qp-button-secondary view-test-results-btn';
                                        break;
                                    case 'in_progress': // Add case for 'in_progress' if used later
                                        $status_icon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-warning-dark);"></span>';
                                        $button_text = 'Continue';
                                        $button_class = 'qp-button-primary start-course-test-btn';
                                        break;
                                    default: // not_started
                                        $status_icon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-border);"></span>';
                                        $button_text = 'Start';
                                        $button_class = 'qp-button-primary start-course-test-btn';
                                        break;
                                }

                                // Adjust button for non-test items
                                if ($item->content_type !== 'test_series') {
                                    $button_class = 'qp-button-secondary view-course-content-btn'; // Generic class for other types
                                    $button_text = 'View'; // Generic text
                                    $session_id_attr = ''; // No session ID for non-tests
                                }

                        ?>
                                <div class="qp-course-item-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--qp-dashboard-border-light);">
                                    <span class="qp-course-item-link" style="display: flex; align-items: center; gap: 8px;">
                                        <?php echo $status_icon; ?>
                                        <span style="font-weight: 500;"><?php echo esc_html($item->title); ?></span>
                                    </span>
                                    <button class="qp-button <?php echo esc_attr($button_class); ?>" data-item-id="<?php echo esc_attr($item->item_id); ?>" <?php echo $session_id_attr; ?> style="padding: 4px 10px; font-size: 12px;"><?php echo esc_html($button_text); ?></button>
                                </div>
                        <?php
                            }
                        } else {
                            echo '<p style="text-align: center; color: var(--qp-dashboard-text-light); font-style: italic;">No items in this section.</p>';
                        }
                        ?>
                    </div>
                </div><?php
                    } // end foreach section
                } else {
                    echo '<div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">This course has no content yet.</p></div></div>';
                }

                echo '</div>'; // Close qp-course-structure-content
            }

            /**
             * NEW: Renders the content specifically for the Overview section.
             */
            private static function render_overview_content($stats, $overall_accuracy, $active_sessions, $recent_history, $review_count, $never_correct_count, $practice_page_url, $session_page_url, $review_page_url)
            {
                global $wpdb; // Ensure $wpdb is available
                $user_id = get_current_user_id();
                $sessions_table = $wpdb->prefix . 'qp_user_sessions';
                $attempts_table = $wpdb->prefix . 'qp_user_attempts';
                $term_table = $wpdb->prefix . 'qp_terms';
                $rel_table = $wpdb->prefix . 'qp_term_relationships';
                $questions_table = $wpdb->prefix . 'qp_questions';

                        ?>
        <div class="qp-card">
            <div class="qp-card-header">
                <h3>Lifetime Stats</h3>
            </div>
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
            <div class="qp-card-header">
                <h3>Quick Actions</h3>
            </div>
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
            <?php // Hidden checkbox for practice mistakes mode 
            ?>
            <div style="display: none;">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" id="qp-include-all-incorrect-cb" name="include_all_incorrect" value="1">
                    <span></span> Include all past mistakes
                </label>
            </div>
        </div>

        <?php if (!empty($active_sessions)) : ?>
            <div class="qp-card">
                <div class="qp-card-header">
                    <h3>Active / Paused Sessions</h3>
                </div>
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
            <div class="qp-card-header">
                <h3>Recent History</h3>
            </div>
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
            private static function render_history_content()
            {
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
                            <td data-label="Context"><?php echo wp_kses_post($context_display); // Use wp_kses_post to allow <em> tag 
                                                        ?></td>
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
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem;">You have no practice sessions yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php
                return ob_get_clean();
            }

            /**
             * Renders the content specifically for the Review section.
             */
            private static function render_review_content()
            {
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
                    "SELECT COUNT(DISTINCT question_id) FROM {$attempts_table} WHERE user_id = %d AND is_correct = 0",
                    $user_id
                ));
                $correctly_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id));
                $all_answered_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
                $never_correct_qids = array_diff($all_answered_qids, $correctly_answered_qids);
                $never_correct_count = count($never_correct_qids);

                ob_start();
    ?>
        <h2>Review Center</h2>
        <div class="qp-practice-card qp-card"> <?php // Add qp-card class 
                                                ?>
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
                            <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo wp_trim_words(esc_html(strip_tags($q->question_text)), 25, '...'); // Strip tags for display 
                                                                            ?>
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
            <div class="qp-card">
                <div class="qp-card-content">
                    <p style="text-align: center;">You haven't marked any questions for review yet.</p>
                </div>
            </div>
        <?php endif; ?>
    <?php
                return ob_get_clean();
            }

            /**
             * Renders the content specifically for the Progress section.
             */
            private static function render_progress_content()
            {
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

        <div class="qp-card"> <?php // Wrap filters in a card 
                                ?>
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
                    <div class="qp-form-group" style="align-self: flex-end;"> <?php // Align checkbox lower 
                                                                                ?>
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
            <?php // Results will be loaded here via AJAX 
            ?>
            <p style="text-align: center; color: var(--qp-dashboard-text-light);">Please select a subject and source to view your progress.</p>
        </div>
    <?php
                return ob_get_clean();
            }

            /**
             * Renders the content specifically for the Courses section.
             * NOW INCLUDES Enrollment Status and Progress.
             */
            private static function render_courses_content()
            {
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

        <?php if ($courses_query->have_posts()) : ?>
            <div class="qp-course-list"> <?php // Combined list container 
                                            ?>
                <?php
                    $found_enrolled = false;
                    $found_available = false;
                    while ($courses_query->have_posts()) : $courses_query->the_post();
                        $course_id = get_the_ID();
                        $is_enrolled = in_array($course_id, $enrolled_course_ids);

                        // --- NEW: Check access mode and user entitlement ---
                        $access_mode = get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free';
                        $linked_product_id = get_post_meta($course_id, '_qp_linked_product_id', true);
                        $product_url = $linked_product_id ? get_permalink($linked_product_id) : '#'; // Link to product page
                        $user_has_access = qp_user_can_access_course($user_id, $course_id, true); // <<< CALL ACCESS CHECK FUNCTION

                        $button_html = '';
                        if ($is_enrolled) {
                            $found_enrolled = true; // Mark that we found at least one enrolled course
                            $course_data = $enrolled_courses_data[$course_id] ?? ['progress' => 0, 'is_complete' => false];
                            $progress = $course_data['progress'];
                            $is_complete = $course_data['is_complete'];
                            $button_text = $is_complete ? __('View Results', 'question-press') : __('Continue Course', 'question-press');
                            // Enrolled users always get the view/continue button, access check already passed implicitly
                            $button_html = sprintf(
                                '<button class="qp-button qp-button-primary qp-view-course-btn" data-course-id="%d" data-course-slug="%s">%s</button>',
                                $course_id,
                                esc_attr(get_post_field('post_name', $course_id)), // Added course slug
                                esc_html($button_text)
                            );
                        } else {
                            // Not enrolled - determine button based on access mode and entitlement
                            $found_available = true; // Mark that we found at least one available course
                            if ($access_mode === 'free') {
                                // Free course, not enrolled yet
                                $button_html = sprintf(
                                    '<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>',
                                    $course_id,
                                    __('Enroll Free', 'question-press')
                                );
                            } elseif ($access_mode === 'requires_purchase') {
                                if ($user_has_access) {
                                    // User purchased but somehow isn't enrolled? Show enroll (should ideally auto-enroll on purchase later)
                                    // Or maybe they unenrolled? Let's allow re-enrollment if they have access.
                                    $button_html = sprintf(
                                        '<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>',
                                        $course_id,
                                        __('Enroll Now (Purchased)', 'question-press') // Clarify they have access
                                    );
                                } else {
                                    // Requires purchase, user does not have access -> Show Purchase button
                                    $button_html = sprintf(
                                        '<a href="%s" class="qp-button qp-button-primary">%s</a>',
                                        esc_url($product_url),
                                        __('Purchase Access', 'question-press')
                                    );
                                }
                            }
                        }

                        // --- Render the Course Card ---
                ?>
                    <div class="qp-card qp-course-item <?php echo $is_enrolled ? 'qp-enrolled' : 'qp-available'; ?>">
                        <div class="qp-card-content">
                            <h3 style="margin-top:0;"><?php the_title(); ?></h3>
                            <?php if ($is_enrolled): // Show progress only if enrolled 
                            ?>
                                <div class="qp-progress-bar-container" title="<?php echo esc_attr($progress); ?>% Complete">
                                    <div class="qp-progress-bar-fill" style="width: <?php echo esc_attr($progress); ?>%;"></div>
                                </div>
                            <?php endif; ?>
                            <?php // Show excerpt 
                            ?>
                            <?php if (has_excerpt()) : ?>
                                <p><?php the_excerpt(); ?></p>
                            <?php else : ?>
                                <?php echo '<p>' . wp_trim_words(get_the_content(), 30, '...') . '</p>'; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($button_html): // Only show action area if there's a button 
                        ?>
                            <div class="qp-card-action" style="padding: 1rem 1.5rem; border-top: 1px solid var(--qp-dashboard-border-light); text-align: right;">
                                <?php echo $button_html; // Output the generated button/link 
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php
                    endwhile;
                    wp_reset_postdata();
                ?>
            </div> <?php // End combined list container 
                    ?>

            <?php // --- Display "No courses" messages --- 
            ?>
            <?php if (!$found_enrolled && !$found_available) : // No courses at all 
            ?>
                <div class="qp-card">
                    <div class="qp-card-content">
                        <p style="text-align: center;">No courses are available at the moment.</p>
                    </div>
                </div>
            <?php elseif (!$found_available && $found_enrolled) : // Enrolled in all, none available 
            ?>
                <div class="qp-card qp-available-courses">
                    <div class="qp-card-content">
                        <p style="text-align: center;">You are enrolled in all available courses.</p>
                    </div>
                </div>
            <?php elseif (!$found_enrolled && $found_available) : // None enrolled, but some available 
            ?>
                <?php // Need to add the "Available Courses" header back if no enrolled courses were found 
                ?>
                <script>
                    jQuery(document).ready(function($) {
                        if ($('.qp-enrolled').length === 0) {
                            $('.qp-available-courses').before('<h2>Available Courses</h2><hr class="qp-divider" style="margin: 0 0 1.5rem 0;">');
                        }
                    });
                </script>
            <?php endif; ?>

        <?php else : // No courses query results at all 
        ?>
            <div class="qp-card">
                <div class="qp-card-content">
                    <p style="text-align: center;">No courses are available at the moment.</p>
                </div>
            </div>
        <?php endif; ?>


        <?php // --- Add CSS specific for this new section --- 
        ?>
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
                margin-bottom: 1rem;
                /* Add margin below description */
            }

            .qp-progress-bar-container {
                height: 8px;
                background-color: var(--qp-dashboard-border-light);
                border-radius: 4px;
                overflow: hidden;
                margin-bottom: 1rem;
                /* Space between bar and description */
            }

            .qp-progress-bar-fill {
                height: 100%;
                background-color: var(--qp-dashboard-success);
                /* Use success color */
                transition: width 0.5s ease-in-out;
                border-radius: 4px;
            }
        </style>
    <?php
                return ob_get_clean();
            }

            /**
             * Renders the content specifically for the Profile section.
             * Fetches user data and displays it using cards.
             */
            private static function render_profile_content()
            {
                if (!is_user_logged_in()) {
                    // Should not happen if page is protected, but good practice
                    return '<p>You must be logged in to view your profile.</p>';
                }

                $user_id = get_current_user_id();
                $profile_data = self::get_profile_data($user_id); // Fetch data using our helper function

                // Get WordPress profile edit URL
                $profile_edit_url = get_edit_profile_url($user_id);

                ob_start();
    ?>
        <div class="qp-profile-page">
            <h2>My Profile</h2>

            <div class="qp-profile-layout">
                <div class="qp-card qp-profile-card">
                    <div class="qp-card-content">
                        <?php // Form now includes display and edit elements 
                        ?>
                        <form id="qp-profile-update-form">
                            <?php wp_nonce_field('qp_save_profile_nonce', '_qp_profile_nonce'); // Nonce for security 
                            ?>

                            <div class="qp-profile-avatar qp-profile-avatar-wrapper">
                                <?php // Avatar display (will be updated by JS) ?>
                                <img id="qp-profile-avatar-preview" src="<?php echo esc_url($profile_data['avatar_url']); ?>" alt="Profile Picture" width="128" height="128">

                                <?php // Hidden file input - triggered by button ?>
                                <input type="file" id="qp-avatar-upload-input" name="qp_avatar_upload" accept="image/jpeg, image/png, image/gif" style="display: none;">

                                <?php // Button shown only in edit mode ?>
                                <button type="button" class="qp-change-avatar-button qp-button qp-button-secondary" style="display: none; margin-top: 10px;">Change Avatar</button>

                                <?php // Upload/Remove buttons shown after selection (controlled by JS) ?>
                                <div class="qp-avatar-upload-actions" style="display: none; margin-top: 10px; gap: 5px;">
                                     <button type="button" class="qp-upload-avatar-button qp-button qp-button-primary button-small">Upload New</button>
                                     <button type="button" class="qp-cancel-avatar-button qp-button qp-button-secondary button-small">Cancel</button>
                                </div>
                                <p id="qp-avatar-upload-error" class="qp-error-message" style="display: none; color: red; font-size: 0.9em; margin-top: 5px;"></p>
                            </div>

                            <?php // --- Display elements (visible by default) --- 
                            ?>
                            <div class="qp-profile-display">
                                <h3 class="qp-profile-name"><?php echo esc_html__('Hello, ', 'question-press') . esc_html($profile_data['display_name']); ?>!</h3>
                                <p class="qp-profile-email"><?php echo esc_html($profile_data['email']); ?></p>
                                <button type="button" class="qp-button qp-button-secondary qp-edit-profile-button">Edit Profile</button>
                            </div>

                            <?php // --- Edit elements (hidden by default) --- 
                            ?>
                            <div class="qp-profile-edit" style="display: none; width: 100%;">
                                <div class="qp-form-group qp-profile-field">
                                    <label for="qp_display_name"><?php esc_html_e('Display Name', 'question-press'); ?></label>
                                    <input type="text" id="qp_display_name" name="display_name" value="<?php echo esc_attr($profile_data['display_name']); ?>" required>
                                </div>

                                <div class="qp-form-group qp-profile-field">
                                    <label for="qp_user_email"><?php esc_html_e('Email Address', 'question-press'); ?></label>
                                    <input type="email" id="qp_user_email" name="user_email" value="<?php echo esc_attr($profile_data['email']); ?>" required>
                                </div>

                                <div class="qp-profile-edit-actions">
                                    <button type="button" class="qp-button qp-button-secondary qp-cancel-edit-profile-button">Cancel</button>
                                    <button type="submit" class="qp-button qp-button-primary qp-save-profile-button">Save Changes</button>
                                </div>
                            </div>

                        </form> <?php // End Form 
                                ?>
                    </div> <?php // End Card Content 
                            ?>
                </div> <?php // End Profile Card 
                        ?>

                <div class="qp-profile-details">
                    <div class="qp-card qp-access-card">
                        <div class="qp-card-header">
                            <h3><?php esc_html_e('Your Practice Scope', 'question-press'); ?></h3>
                        </div>
                        <div class="qp-card-content">
                            <p><?php echo esc_html($profile_data['scope_description']); ?></p>
                            <?php /* Optional detailed lists (can uncomment later if needed)
                            if (!empty($profile_data['allowed_exams_list'])) {
                                echo '<h4>Allowed Exams:</h4><ul>';
                                foreach($profile_data['allowed_exams_list'] as $exam_name) {
                                    echo '<li>' . esc_html($exam_name) . '</li>';
                                }
                                echo '</ul>';
                            }
                            if (!empty($profile_data['allowed_subjects_list'])) {
                                echo '<h4>Specifically Allowed Subjects:</h4><ul>';
                                foreach($profile_data['allowed_subjects_list'] as $subject_name) {
                                    echo '<li>' . esc_html($subject_name) . '</li>';
                                }
                                echo '</ul>';
                            }
                            */ ?>
                        </div>
                    </div>

                    <?php // --- Password Change Card --- 
                    ?>
                    <div class="qp-card qp-password-card">
                        <div class="qp-card-header">
                            <h3><?php esc_html_e('Security', 'question-press'); ?></h3>
                        </div>
                        <div class="qp-card-content">
                            <?php // --- Display elements (visible by default) --- 
                            ?>
                            <div class="qp-password-display">
                                <p>Manage your account password.</p>
                                <button type="button" class="qp-button qp-button-secondary qp-change-password-button">Change Password</button>
                                <?php // --- ADD THIS LINK --- ?>
                                <p class="qp-forgot-password-link-wrapper">
                                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="qp-forgot-password-link">Forgot Password?</a>
                                </p>
                                <?php // --- END ADDED LINK --- ?>
                            </div>

                            <?php // --- Edit elements (hidden by default) --- 
                            ?>
                            <div class="qp-password-edit" style="display: none;">
                                <form id="qp-password-change-form">
                                    <?php wp_nonce_field('qp_change_password_nonce', '_qp_password_nonce'); // Nonce for security 
                                    ?>
                                    <div class="qp-form-group qp-profile-field">
                                        <label for="qp_current_password"><?php esc_html_e('Current Password', 'question-press'); ?></label>
                                        <input type="password" id="qp_current_password" name="current_password" required autocomplete="current-password">
                                    </div>
                                    <div class="qp-form-group qp-profile-field">
                                        <label for="qp_new_password"><?php esc_html_e('New Password', 'question-press'); ?></label>
                                        <input type="password" id="qp_new_password" name="new_password" required autocomplete="new-password">
                                    </div>
                                    <div class="qp-form-group qp-profile-field">
                                        <label for="qp_confirm_password"><?php esc_html_e('Confirm New Password', 'question-press'); ?></label>
                                        <input type="password" id="qp_confirm_password" name="confirm_password" required autocomplete="new-password">
                                        <p id="qp-password-match-error" class="qp-error-message" style="display: none; color: red; font-size: 0.9em; margin-top: 5px;"></p>
                                    </div>
                                    <div class="qp-password-edit-actions">
                                        <button type="button" class="qp-button qp-button-secondary qp-cancel-change-password-button">Cancel</button>
                                        <button type="submit" class="qp-button qp-button-primary qp-save-password-button">Update Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php // Placeholder for future Subscription/Entitlement Management 
                    ?>
                </div>
            </div>
        </div>
<?php
                return ob_get_clean();
            }

            /**
             * Gathers profile data for the dashboard profile tab.
             *
             * @param int $user_id The ID of the user.
             * @return array An array containing profile details.
             */
            private static function get_profile_data($user_id)
            {
                $user_info = get_userdata($user_id);
                if (!$user_info) {
                    return [ // Return default empty values if user not found
                        'display_name' => 'User Not Found',
                        'email' => '',
                        'avatar_url' => get_avatar_url(0), // Default avatar
                        'scope_description' => 'N/A',
                        'allowed_subjects_list' => [],
                        'allowed_exams_list' => [],
                    ];
                }

                // Check for custom avatar first
        $custom_avatar_id = get_user_meta($user_id, '_qp_avatar_attachment_id', true);
        $avatar_url = '';
        if (!empty($custom_avatar_id)) {
            // Use a reasonable size like 'thumbnail' or 'medium'
            $avatar_url = wp_get_attachment_image_url(absint($custom_avatar_id), 'thumbnail');
        }

        // Fallback to Gravatar if no custom avatar or URL fetch failed
        if (empty($avatar_url)) {
            $avatar_url = get_avatar_url($user_id, ['size' => 128, 'default' => 'mystery']); // Get a larger avatar
        }

                // --- Fetch and Process Scope ---
                $scope_description = 'All Subjects & Exams'; // Default
                $allowed_subjects_list = [];
                $allowed_exams_list = []; // <-- Initialize here
                $allowed_subject_ids_or_all = qp_get_allowed_subject_ids_for_user($user_id); // Use the existing function

                if ($allowed_subject_ids_or_all !== 'all') {
                    global $wpdb;
                    $term_table = $wpdb->prefix . 'qp_terms';
                    // $rel_table = $wpdb->prefix . 'qp_term_relationships'; // No longer needed for exams here
                    // $tax_table = $wpdb->prefix . 'qp_taxonomies'; // No longer needed for exams here

                    $allowed_subject_ids = $allowed_subject_ids_or_all; // It's an array if not 'all'

                    // Get names for the allowed subjects (This part is correct)
                    if (!empty($allowed_subject_ids)) {
                        $subj_ids_placeholder = implode(',', array_map('absint', $allowed_subject_ids));
                        // Fetch subject names directly allowed or allowed via exams included in qp_get_allowed_subject_ids_for_user
                        $allowed_subjects_list = $wpdb->get_col("SELECT name FROM {$term_table} WHERE term_id IN ($subj_ids_placeholder) AND parent = 0 ORDER BY name ASC");
                    }

                    // --- CORRECTED EXAM LOGIC ---
                    // Get directly allowed exams from user meta
                    $direct_exams_json = get_user_meta($user_id, '_qp_allowed_exam_term_ids', true);
                    $direct_exam_ids = json_decode($direct_exams_json, true);
                    if (!is_array($direct_exam_ids)) $direct_exam_ids = [];

                    $final_allowed_exam_ids = array_map('absint', $direct_exam_ids); // Start with directly allowed exams

                    // If specific subjects are allowed, find exams linked ONLY to those subjects
                    // Note: qp_get_allowed_subject_ids_for_user already calculated subjects allowed via exams,
                    // but here we need the EXAM names themselves for display. We only show DIRECTLY assigned exams.
                    // If you *also* wanted to show exams linked via allowed subjects, you'd add that logic back here.
                    // For now, we only display exams explicitly assigned to the user.

                    if (!empty($final_allowed_exam_ids)) {
                        $exam_ids_placeholder = implode(',', $final_allowed_exam_ids);
                        $allowed_exams_list = $wpdb->get_col("SELECT name FROM {$term_table} WHERE term_id IN ($exam_ids_placeholder) ORDER BY name ASC");
                    }
                    // --- END CORRECTED EXAM LOGIC ---

                    // Build the description string (This part is correct)
                    if (empty($allowed_subjects_list) && empty($allowed_exams_list)) {
                        $scope_description = 'No specific scope assigned.';
                    } else {
                        $scope_parts = [];
                        // Display only explicitly assigned exams
                        if (!empty($allowed_exams_list)) $scope_parts[] = "Allowed Exams: " . implode(', ', array_map('esc_html', $allowed_exams_list));
                        // Display all subjects derived from scope function
                        if (!empty($allowed_subjects_list)) $scope_parts[] = "Accessible Subjects: " . implode(', ', array_map('esc_html', $allowed_subjects_list));
                        $scope_description = implode('; ', $scope_parts);
                    }
                }
                // --- End Scope Processing ---

                return [
                    'display_name' => $user_info->display_name,
                    'email' => $user_info->user_email,
                    'avatar_url' => $avatar_url,
                    'scope_description' => $scope_description, // A user-friendly string
                    'allowed_subjects_list' => $allowed_subjects_list, // Raw list for potential detailed display
                    'allowed_exams_list' => $allowed_exams_list,       // Raw list for potential detailed display
                ];
            }

            /**
             * NEW HELPER: Prefetches lineage data needed for session lists.
             */
            private static function prefetch_lineage_data($sessions)
            {
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
                    if (empty($unique_qids)) return [$lineage_cache, $group_to_topic_map, $question_to_group_map]; // Avoid empty IN clause

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
                    if (empty($all_group_ids)) return [$lineage_cache, $group_to_topic_map, $question_to_group_map]; // Avoid empty IN clause

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
                        foreach ($all_topic_ids as $topic_id) {
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
            private static function get_session_mode_name($session, $settings)
            {
                $mode = 'Practice'; // Default
                if ($session->status === 'paused') {
                    $mode = 'Paused Session';
                } elseif (isset($settings['practice_mode'])) {
                    switch ($settings['practice_mode']) {
                        case 'revision':
                            $mode = 'Revision';
                            break;
                        case 'mock_test':
                            $mode = 'Mock Test';
                            break;
                        case 'Incorrect Que. Practice':
                            $mode = 'Incorrect Practice';
                            break;
                        case 'Section Wise Practice':
                            $mode = 'Section Practice';
                            break;
                    }
                } elseif (isset($settings['subject_id']) && $settings['subject_id'] === 'review') {
                    $mode = 'Review Session';
                }
                return $mode;
            }

            /**
             * NEW HELPER: Gets the subject display string for a session.
             */
            private static function get_session_subjects_display($session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map)
            {
                global $wpdb;
                $term_table = $wpdb->prefix . 'qp_terms';

                $session_qids = json_decode($session->question_ids_snapshot, true);
                $subjects_display = 'N/A';

                if (is_array($session_qids) && !empty($session_qids)) {
                    $mode = self::get_session_mode_name($session, $settings); // Use the mode helper

                    if ($mode === 'Section Practice') {
                        // Get source hierarchy for the first question
                        $first_question_id = $session_qids[0];
                        $source_hierarchy = Terms_DB::get_source_hierarchy_for_question($first_question_id); // Assumes this function exists globally
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
                        if (empty($subjects_display)) $subjects_display = 'N/A';
                    }
                }
                return $subjects_display;
            }

            /**
             * NEW HELPER: Gets the result display string for a session.
             */
            private static function get_session_result_display($session, $settings)
            {
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
            private static function render_sessions_tab_content()
            {
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