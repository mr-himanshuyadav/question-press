<?php

namespace QuestionPress\Ajax; // PSR-4 Namespace

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use QuestionPress\Database\Terms_DB;
use QuestionPress\Database\Questions_DB;
use QuestionPress\Utils\User_Access;
use QuestionPress\Frontend\Shortcodes;
use WP_Error; // Use statement for WP_Error
use Exception; // Use statement for Exception

/**
 * Handles AJAX requests related to the practice/session UI interactions.
 */
class Practice_Ajax
{

    /**
     * AJAX handler for checking an answer in non-mock test modes.
     * Includes access check and attempt decrement logic using entitlements table.
     */
    public static function check_answer()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        // --- Access Control Check ---
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to answer questions.', 'code' => 'not_logged_in']);
            return;
        }
        $user_id = get_current_user_id();
        global $wpdb;
        $current_time = current_time('mysql');

        // --- START REFINED Entitlement Check ---
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
            return;
        }

        // 1. Get the session settings
        $session_settings_json = $wpdb->get_var($wpdb->prepare("SELECT settings_snapshot FROM {$wpdb->prefix}qp_user_sessions WHERE session_id = %d", $session_id));
        $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $entitlement_to_decrement = null;
        $has_access = false;

        // 2. Check what kind of session this is
        if (isset($settings['course_id']) && $settings['course_id'] > 0) {
            // CASE A: This is a Course Test
            // Access is granted if the user can still access the course (e.g., enrolled or has a valid plan)
            if (User_Access::can_access_course($user_id, $settings['course_id'])) {
                $has_access = true;
                // We do NOT decrement general attempts for in-course tests.
            }
        } else {
            // CASE B: This is a General Practice Session
            // Access is granted if the user has general practice attempts (NULL or > 0)
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

        // 3. Final check and action
        if (!$has_access) {
            error_log("QP Check Answer: User #{$user_id} denied access for session #{$session_id}. No suitable entitlement found.");
            wp_send_json_error([
                'message' => 'You do not have access to perform this action. Your plan may have expired or you may be out of attempts.',
                'code' => 'access_denied'
            ]);
            return;
        }

        // 4. Decrement general practice attempts if one was identified
        if ($entitlement_to_decrement) {
            $new_attempts = max(0, (int)$entitlement_to_decrement->remaining_attempts - 1);
            $wpdb->update(
                $entitlements_table,
                ['remaining_attempts' => $new_attempts],
                ['entitlement_id' => $entitlement_to_decrement->entitlement_id]
            );
            error_log("QP Check Answer: User #{$user_id} used general attempt from Entitlement #{$entitlement_to_decrement->entitlement_id}. Remaining: {$new_attempts}");
        } else {
            error_log("QP Check Answer: User #{$user_id} attempt approved (Course Test or Unlimited Plan).");
        }
        // --- END REFINED Entitlement Check ---

        // --- Proceed with checking the answer (Original logic, slightly adjusted) ---
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;

        if (!$session_id || !$question_id || !$option_id) {
            // This case should ideally not happen if access was granted, but good to keep
            wp_send_json_error(['message' => 'Invalid data submitted after access check.']);
            return;
        }

        $o_table = $wpdb->prefix . 'qp_options';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $revision_table = $wpdb->prefix . 'qp_revision_attempts'; // For revision mode

        // Update session activity
        $wpdb->update($sessions_table, ['last_activity' => $current_time], ['session_id' => $session_id]);

        // Check correctness
        $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
        $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));

        // Get session settings for revision mode check
        $session_settings_json = $wpdb->get_var($wpdb->prepare("SELECT settings_snapshot FROM $sessions_table WHERE session_id = %d", $session_id));
        $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];

        // Record the attempt
        $wpdb->replace( // Use REPLACE to handle potential re-attempts within the same session if needed
            $attempts_table,
            [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'question_id' => $question_id,
                'selected_option_id' => $option_id,
                'is_correct' => $is_correct ? 1 : 0,
                'status' => 'answered',
                'mock_status' => null, // Not applicable for this mode
                'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null,
                'attempt_time' => $current_time // Use the time check was performed
            ]
        );
        $attempt_id = $wpdb->insert_id; // Get attempt ID after insert/replace


        // If it's a revision session, also record in the revision table
        if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'revision') {
            // **FIX START**: Get topic ID directly from group relationship
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
            // **FIX END**

            if ($topic_id) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$revision_table} (user_id, question_id, topic_id) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE attempt_date = NOW()",
                    $user_id,
                    $question_id,
                    $topic_id
                ));
            }
        }

        wp_send_json_success([
            'is_correct' => $is_correct,
            'correct_option_id' => $correct_option_id,
            'attempt_id' => $attempt_id // Return attempt ID
        ]);
    }

    /**
     * AJAX handler to save a user's selected answer during a mock test.
     * Includes access check and attempt decrement logic using entitlements table.
     */
    public static function save_mock_attempt()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        // --- Access Control Check ---
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to answer questions.', 'code' => 'not_logged_in']);
            return;
        }
        $user_id = get_current_user_id();
        global $wpdb;
        $current_time = current_time('mysql');

        // --- START REFINED Entitlement Check ---
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        if (!$session_id) {
            wp_send_json_error(['message' => 'Invalid session ID.']);
            return;
        }

        // 1. Get the session settings
        $session_settings_json = $wpdb->get_var($wpdb->prepare("SELECT settings_snapshot FROM {$wpdb->prefix}qp_user_sessions WHERE session_id = %d", $session_id));
        $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $entitlement_to_decrement = null;
        $has_access = false;

        // 2. Check what kind of session this is
        if (isset($settings['course_id']) && $settings['course_id'] > 0) {
            // CASE A: This is a Course Test
            if (User_Access::can_access_course($user_id, $settings['course_id'])) {
                $has_access = true;
            }
        } else {
            // CASE B: This is a General Practice Session
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

        // 3. Final check and action
        if (!$has_access) {
            error_log("QP Mock Save: User #{$user_id} denied access for session #{$session_id}. No suitable entitlement found.");
            wp_send_json_error([
                'message' => 'You do not have access to perform this action. Your plan may have expired or you may be out of attempts.',
                'code' => 'access_denied'
            ]);
            return;
        }

        // We just log that the access was approved
        error_log("QP Mock Save: User #{$user_id} attempt approved (Course Test, Unlimited, or General Mock). No deduction per click.");

        // --- Proceed with saving the mock attempt (Original logic, slightly adjusted) ---
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0; // Can be 0 if clearing response

        if (!$session_id || !$question_id) { // Option ID can be 0 when clearing
            wp_send_json_error(['message' => 'Invalid data submitted after access check.']);
            return;
        }

        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        // Check if an attempt record already exists for this question in this session
        $existing_attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT attempt_id, mock_status FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
            $session_id,
            $question_id
        ));

        // Determine the correct mock_status based on whether an option is selected and previous status
        $current_mock_status = $existing_attempt ? $existing_attempt->mock_status : 'viewed'; // Default to viewed if no record
        $new_mock_status = $current_mock_status; // Keep current status unless changed below

        if ($option_id > 0) { // An answer is being saved
            if ($current_mock_status == 'marked_for_review' || $current_mock_status == 'answered_and_marked_for_review') {
                $new_mock_status = 'answered_and_marked_for_review';
            } else {
                $new_mock_status = 'answered';
            }
        }
        // Note: Clearing the response (option_id=0) is handled by qp_update_mock_status_ajax, not this function directly.
        // This function assumes an answer is being *selected*.

        $attempt_data = [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'question_id' => $question_id,
            'selected_option_id' => $option_id > 0 ? $option_id : null, // Store NULL if clearing
            'is_correct' => null, // Graded only at the end
            'status' => $option_id > 0 ? 'answered' : 'viewed', // Main status: 'answered' if option selected, 'viewed' if cleared
            'mock_status' => $new_mock_status,
            'attempt_time' => $current_time
        ];

        if ($existing_attempt) {
            // Update existing attempt
            $wpdb->update($attempts_table, $attempt_data, ['attempt_id' => $existing_attempt->attempt_id]);
        } else {
            // Insert new attempt
            $wpdb->insert($attempts_table, $attempt_data);
        }

        // Update session activity time
        $wpdb->update($wpdb->prefix . 'qp_user_sessions', ['last_activity' => $current_time], ['session_id' => $session_id]);

        wp_send_json_success(['message' => 'Answer saved.']);
    }

    /**
     * AJAX handler to update the status of a mock test question.
     * Handles statuses like viewed, marked_for_review, etc.
     */
    public static function update_mock_status()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        $new_status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

        if (!$session_id || !$question_id || empty($new_status)) {
            wp_send_json_error(['message' => 'Invalid data provided for status update.']);
        }

        // A whitelist of allowed statuses to prevent arbitrary data injection.
        $allowed_statuses = ['viewed', 'answered', 'marked_for_review', 'answered_and_marked_for_review', 'not_viewed'];
        if (!in_array($new_status, $allowed_statuses)) {
            wp_send_json_error(['message' => 'Invalid status provided.']);
        }

        global $wpdb;
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $user_id = get_current_user_id();

        // Find the existing attempt for this question in this session.
        $existing_attempt_id = $wpdb->get_var($wpdb->prepare(
            "SELECT attempt_id FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
            $session_id,
            $question_id
        ));

        $data_to_update = ['mock_status' => $new_status];

        // If the user is clearing their response, we should also nullify their selected option.
        if ($new_status === 'viewed' || $new_status === 'marked_for_review') {
            $data_to_update['selected_option_id'] = null;
        }

        if ($existing_attempt_id) {
            // If an attempt record exists, update its mock_status.
            $wpdb->update($attempts_table, $data_to_update, ['attempt_id' => $existing_attempt_id]);
        } else {
            // If no record exists yet (e.g., the user just viewed it), create one.
            $data_to_update['session_id'] = $session_id;
            $data_to_update['user_id'] = $user_id;
            $data_to_update['question_id'] = $question_id;
            $data_to_update['status'] = 'viewed'; // The main status remains 'viewed' until answered.
            $wpdb->insert($attempts_table, $data_to_update);
        }

        wp_send_json_success(['message' => 'Status updated.']);
    }

    /**
     * AJAX handler to mark a question as 'expired' for a session.
     */
    public static function expire_question()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;

        if (!$session_id || !$question_id) {
            wp_send_json_error(['message' => 'Invalid data submitted.']);
        }

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'question_id' => $question_id,
            'is_correct' => null,
            'status' => 'expired',
            'remaining_time' => 0 // Expired means 0 time left
        ]);

        wp_send_json_success();
    }

    /**
     * AJAX handler to skip a question.
     */
    public static function skip_question()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        if (!$session_id || !$question_id) {
            wp_send_json_error(['message' => 'Invalid data submitted.']);
        }

        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}qp_user_attempts", [ // Changed insert to replace
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'question_id' => $question_id,
            'selected_option_id' => null, // Ensure selected option is null when skipping
            'is_correct' => null,
            'status' => 'skipped',
            'mock_status' => null, // Ensure mock status is null when skipping in non-mock modes
            'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null,
            'attempt_time' => current_time('mysql') // Add attempt time
        ]);

        wp_send_json_success();
    }

    /**
     * AJAX handler to add or remove a question from the user's review list.
     */
    public static function toggle_review_later()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        // --- THIS IS THE CORRECTED LOGIC ---
        // We explicitly check if the string from AJAX is 'true'.
        // Anything else, including 'false', will result in a boolean false.
        $is_marked = isset($_POST['is_marked']) && $_POST['is_marked'] === 'true';
        // --- END OF FIX ---
        $user_id = get_current_user_id();

        if (!$question_id) {
            wp_send_json_error(['message' => 'Invalid question ID.']);
        }

        global $wpdb;
        $review_table = $wpdb->prefix . 'qp_review_later';

        if ($is_marked) {
            // This block now only runs when the box is checked.
            $wpdb->insert(
                $review_table,
                ['user_id' => $user_id, 'question_id' => $question_id],
                ['%d', '%d']
            );
        } else {
            // This block now correctly runs when the box is unchecked.
            $wpdb->delete(
                $review_table,
                ['user_id' => $user_id, 'question_id' => $question_id],
                ['%d', '%d']
            );
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler to get the full data for a single question for the review popup.
     */
    public static function get_single_question_for_review()
    {
        // Security check - Allow nonce from practice or quick edit
        if (
            !(check_ajax_referer('qp_practice_nonce', 'nonce', false) ||
                check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce', false))
        ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }


        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in.']);
        }

        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        if (!$question_id) {
            wp_send_json_error(['message' => 'Invalid question ID.']);
        }

        global $wpdb;

        // **FIX START**: This new query correctly finds the group's topic and then traces back to the top-level subject.
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
        // **FIX END**

        if (!$question_data) {
            wp_send_json_error(['message' => 'Question not found.']);
        }

        // Get the image URL if an ID exists
        if (!empty($question_data['direction_image_id'])) {
            $question_data['direction_image_url'] = wp_get_attachment_url($question_data['direction_image_id']);
        } else {
            $question_data['direction_image_url'] = null;
        }

        // Apply nl2br to convert newlines to <br> tags for HTML display.
        if (!empty($question_data['direction_text'])) {
            $question_data['direction_text'] = wp_kses_post(nl2br($question_data['direction_text'])); // Added stripslashes
        }
        if (!empty($question_data['question_text'])) {
            $question_data['question_text'] = wp_kses_post(nl2br($question_data['question_text'])); // Added stripslashes
        }

        // Fetch options
        $options = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC",
            $question_id
        ), ARRAY_A);

        foreach ($options as &$option) {
            if (!empty($option['option_text'])) {
                $option['option_text'] = wp_kses_post(nl2br($option['option_text'])); // Added stripslashes
            }
        }
        unset($option);

        $question_data['options'] = $options;

        wp_send_json_success($question_data);
    }

    /**
     * AJAX handler to submit a new question report from the modal.
     */
    public static function submit_question_report()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.']);
        }

        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
        $reasons = isset($_POST['reasons']) && is_array($_POST['reasons']) ? array_map('absint', $_POST['reasons']) : [];
        $comment = isset($_POST['comment']) ? sanitize_textarea_field($_POST['comment']) : '';
        $user_id = get_current_user_id();

        if (empty($question_id) || empty($reasons)) {
            wp_send_json_error(['message' => 'Invalid data provided.']);
        }

        global $wpdb;
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // Serialize the array of reason IDs into a comma-separated string
        $reason_ids_string = implode(',', $reasons);

        // Insert a single row with all the data
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


        // Add a log entry for the admin panel
        $wpdb->insert("{$wpdb->prefix}qp_logs", [
            'log_type'    => 'User Report',
            'log_message' => sprintf('User reported question #%s.', $question_id),
            'log_data'    => wp_json_encode(['user_id' => $user_id, 'session_id' => $session_id, 'question_id' => $question_id, 'reasons' => $reasons, 'comment' => $comment])
        ]);

        // --- NEW: Fetch and return the updated report status ---
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

        wp_send_json_success(['message' => 'Report submitted.', 'reported_info' => $report_info]);
    }

    /**
     * AJAX handler to get all active report reasons.
     */
    public static function get_report_reasons()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

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

        // --- THIS IS THE FIX ---
        $other_reasons = [];
        foreach ($reasons_raw as $reason) {
            $type = !empty($reason->type) ? $reason->type : 'report';
            // Separate any reason containing "Other" into a temporary array
            if (strpos($reason->reason_text, 'Other') !== false) {
                $other_reasons[$type][] = $reason;
            } else {
                $reasons_by_type[$type][] = $reason;
            }
        }

        // Append the "Other" reasons to the end of their respective lists
        if (isset($other_reasons['report'])) {
            $reasons_by_type['report'] = array_merge($reasons_by_type['report'], $other_reasons['report']);
        }
        if (isset($other_reasons['suggestion'])) {
            $reasons_by_type['suggestion'] = array_merge($reasons_by_type['suggestion'], $other_reasons['suggestion']);
        }
        // --- END FIX ---

        ob_start();

        if (!empty($reasons_by_type['report'])) {
            echo '<div class="qp-report-type-header">Reports (for errors)</div>';
            foreach ($reasons_by_type['report'] as $reason) {
                echo '<label class="qp-custom-checkbox qp-report-reason-report">
                        <input type="checkbox" name="report_reasons[]" value="' . esc_attr($reason->reason_id) . '">
                        <span></span>
                        ' . esc_html($reason->reason_text) . '
                      </label>';
            }
        }

        if (!empty($reasons_by_type['suggestion'])) {
            if (!empty($reasons_by_type['report'])) {
                echo '<hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid #ddd;">';
            }
            echo '<div class="qp-report-type-header">Suggestions<br><span style="font-size:0.8em;font-weight:400;">You can still attempt question after.</span></div>';
            foreach ($reasons_by_type['suggestion'] as $reason) {
                echo '<label class="qp-custom-checkbox qp-report-reason-suggestion">
                        <input type="checkbox" name="report_reasons[]" value="' . esc_attr($reason->reason_id) . '">
                        <span></span>
                        ' . esc_html($reason->reason_text) . '
                      </label>';
            }
        }

        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX handler to get the number of unattempted questions for the current user.
     */
    public static function get_unattempted_counts()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'User not logged in.']);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $q_table = $wpdb->prefix . 'qp_questions';
        $a_table = $wpdb->prefix . 'qp_user_attempts';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        // 1. Get all question IDs the user has already answered.
        $attempted_q_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$a_table} WHERE user_id = %d AND status = 'answered'",
            $user_id
        ));
        $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

        // 2. Get all unattempted questions and trace them to their parent subject.
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

        // This query joins from the unattempted question, up to its group, to its linked term (topic),
        // and finally to that term's parent (subject).
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

        // 3. Process the results into a structured count array for the frontend.
        $counts = [
            'by_subject' => [],
            'by_topic'   => [],
        ];

        foreach ($results as $row) {
            // Increment count for the specific topic
            if (!isset($counts['by_topic'][$row->topic_id])) {
                $counts['by_topic'][$row->topic_id] = 0;
            }
            $counts['by_topic'][$row->topic_id]++;

            // Increment count for the parent subject
            if (!isset($counts['by_subject'][$row->subject_id])) {
                $counts['by_subject'][$row->subject_id] = 0;
            }
            $counts['by_subject'][$row->subject_id]++;
        }

        wp_send_json_success(['counts' => $counts]);
    }

    /**
     * AJAX handler to get the full data for a single question for the practice UI.
     */
    public static function get_question_data()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in.']); // Add login check
        }

        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0; // Get session_id from POST
        $user_id = get_current_user_id();

        if (!$question_id) {
            wp_send_json_error(['message' => 'Invalid Question ID.']);
        }

        // --- Call the new DB method ---
        $result_data = Questions_DB::get_question_details_for_practice($question_id, $user_id, $session_id);
        // --- End call ---

        if ($result_data) {
            // Data fetched successfully
            wp_send_json_success($result_data);
        } else {
            // Question not found or other error in DB method
            wp_send_json_error(['message' => 'Question not found or could not be loaded.']);
        }
    }

    /**
     * AJAX handler to get topics for a subject THAT HAVE QUESTIONS.
     */
    public static function get_topics_for_subject()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $subject_ids_raw = isset($_POST['subject_id']) ? $_POST['subject_id'] : [];
        if (empty($subject_ids_raw)) {
            wp_send_json_error(['message' => 'No subjects provided.']);
        }

        $subject_term_ids = array_filter(array_map('absint', $subject_ids_raw), function ($id) {
            return $id > 0;
        });

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        // Base query to get topics (child terms) of the selected subjects (parent terms)
        $sql = "
            SELECT parent_term.term_id as subject_id, parent_term.name as subject_name,
                   child_term.term_id as topic_id, child_term.name as topic_name
            FROM {$term_table} child_term
            JOIN {$term_table} parent_term ON child_term.parent = parent_term.term_id
        ";

        $where_clauses = [];
        $params = [];

        // If specific subjects are selected, filter by their term IDs
        if (!empty($subject_term_ids) && !in_array('all', $subject_ids_raw)) {
            $ids_placeholder = implode(',', array_fill(0, count($subject_term_ids), '%d'));
            $where_clauses[] = "child_term.parent IN ($ids_placeholder)";
            $params = array_merge($params, $subject_term_ids);
        }

        // Ensure we are only getting topics (terms with parents) from the subject taxonomy
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'");
        if ($subject_tax_id) {
            $where_clauses[] = "child_term.taxonomy_id = %d";
            $params[] = $subject_tax_id;
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        $sql .= " ORDER BY parent_term.name, child_term.name ASC";

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

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

        wp_send_json_success(['topics' => $grouped_topics]);
    }

    /**
     * AJAX handler to get sections containing questions for a given subject and topic.
     */
    public static function get_sections_for_subject()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
        $user_id = get_current_user_id();

        if (!$topic_id) {
            wp_send_json_error(['message' => 'Invalid topic ID.']);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

        // 1. Find all groups linked to the selected topic.
        $group_ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'group'", $topic_id));

        if (empty($group_ids)) {
            wp_send_json_success(['sections' => []]);
            return;
        }
        $group_ids_placeholder = implode(',', $group_ids);

        // 2. Find all source/section terms linked to those groups.
        $source_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.term_id, t.name, t.parent
             FROM {$term_table} t
             JOIN {$rel_table} r ON t.term_id = r.term_id
             WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d
             ORDER BY t.parent, t.name ASC",
            $source_tax_id
        ));

        // 3. Get all question IDs the user has already attempted.
        $attempted_q_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
        $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

        $results = [];
        foreach ($source_terms as $term) {
            if ($term->parent > 0) { // We are only interested in sections
                $parent_source_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $term_table WHERE term_id = %d", $term->parent));

                // Subquery to count unattempted questions in this specific section and topic
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

        wp_send_json_success(['sections' => $results]);
    }

    /**
     * AJAX handler to get sources linked to a specific subject.
     */
    public static function get_sources_for_subject()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

        if (!$subject_id) {
            wp_send_json_error(['message' => 'Invalid subject ID.']);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // Find source terms (object_id) linked to the given subject term (term_id)
        $source_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$rel_table} WHERE term_id = %d AND object_type = 'source_subject_link'",
            $subject_id
        ));

        if (empty($source_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }

        $ids_placeholder = implode(',', $source_ids);
        $sources = $wpdb->get_results("SELECT term_id, name FROM {$term_table} WHERE term_id IN ($ids_placeholder) ORDER BY name ASC");

        wp_send_json_success(['sources' => $sources]);
    }

    /**
     * AJAX handler to get child terms (sections) for a given parent term.
     */
    public static function get_child_terms()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $parent_term_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;

        if (!$parent_term_id) {
            wp_send_json_error(['message' => 'Invalid parent ID.']);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        $child_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM {$term_table} WHERE parent = %d ORDER BY name ASC",
            $parent_term_id
        ));

        wp_send_json_success(['children' => $child_terms]);
    }

    /**
     * AJAX handler for the dashboard progress tab.
     * Calculates and returns the hierarchical progress data.
     */
    public static function get_progress_data()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $subject_term_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
        $source_term_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $user_id = get_current_user_id();

        if (!$source_term_id || !$user_id || !$subject_term_id) {
            wp_send_json_error(['message' => 'Invalid request.']);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // Step 1: Get all term IDs in both hierarchies
        $all_subject_term_ids = Terms_DB::get_all_descendant_ids($subject_term_id);
        $all_source_term_ids = Terms_DB::get_all_descendant_ids($source_term_id);

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
            wp_send_json_success(['html' => '<p>No questions found for this subject and source combination.</p>']);
            return;
        }
        $group_ids_placeholder = implode(',', $relevant_group_ids);

        // Step 3: Get all questions in scope
        $all_qids_in_scope = $wpdb->get_col("SELECT question_id FROM {$questions_table} WHERE group_id IN ($group_ids_placeholder)");

        if (empty($all_qids_in_scope)) {
            wp_send_json_success(['html' => '<p>No questions found for this source.</p>']);
            return;
        }
        $qids_placeholder = implode(',', $all_qids_in_scope);

        // Step 4: Get user's completed questions
        $exclude_incorrect = isset($_POST['exclude_incorrect']) && $_POST['exclude_incorrect'] === 'true';
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
            $term->is_fully_attempted = false; // Add new property
            $term->session_info = $session_info_by_section[$term->term_id] ?? null;
            $terms_by_id[$term->term_id] = $term;
        }

        // Populate counts and check completion status
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

        // Final completion check for each term
        foreach ($terms_by_id as $term) {
            if ($term->total > 0 && $term->completed >= $term->total) {
                $term->is_fully_attempted = true;
            }
        }

        // Assemble the final tree structure
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


        ob_start();
        $subject_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$term_table} WHERE term_id = %d", $subject_term_id));
        $subject_percentage = $source_term_object->total > 0 ? round(($source_term_object->completed / $source_term_object->total) * 100) : 0;
?>
        <div class="qp-progress-tree">
            <div class="qp-progress-item subject-level">
                <div class="qp-progress-bar-bg" style="width: <?php echo esc_attr($subject_percentage); ?>%;"></div>
                <div class="qp-progress-label">
                    <strong><?php echo esc_html($subject_name); ?></strong>
                    <span class="qp-progress-percentage">
                        <?php echo esc_html($subject_percentage); ?>% (<?php echo esc_html($source_term_object->completed); ?>/<?php echo esc_html($source_term_object->total); ?>)
                    </span>
                </div>
            </div>
            <div class="qp-source-children-container" style="padding-left: 20px;">
                <?php
                // Define the recursive function locally or make it globally available if needed elsewhere
                if (!function_exists('qp_render_progress_tree_recursive')) {
                    function qp_render_progress_tree_recursive($terms, $review_page_url, $session_page_url, $subject_term_id)
                    {
                        usort($terms, fn($a, $b) => strcmp($a->name, $b->name));

                        foreach ($terms as $term) {
                            $percentage = $term->total > 0 ? round(($term->completed / $term->total) * 100) : 0;
                            $has_children = !empty($term->children);
                            $level_class = $has_children ? 'topic-level qp-topic-toggle' : 'section-level';

                            echo '<div class="qp-progress-item ' . $level_class . '" data-topic-id="' . esc_attr($term->term_id) . '">';
                            echo '<div class="qp-progress-bar-bg" style="width: ' . esc_attr($percentage) . '%;"></div>';
                            echo '<div class="qp-progress-label">';

                            echo '<span class="qp-progress-item-name">';
                            if ($has_children) {
                                echo '<span class="dashicons dashicons-arrow-right-alt2"></span>';
                            }
                            echo esc_html($term->name);
                            echo '</span>';

                            echo '<div class="qp-progress-item-details">';
                            echo '<span class="qp-progress-percentage">' . esc_html($percentage) . '% (' . $term->completed . '/' . $term->total . ')</span>';

                            // *** THIS IS THE FINAL FIX ***
                            if (!$has_children) {
                                $session = $term->session_info;
                                if ($session && $session['status'] === 'paused') {
                                    $url = esc_url(add_query_arg('session_id', $session['session_id'], $session_page_url));
                                    echo '<a href="' . $url . '" class="qp-button qp-button-primary qp-progress-action-btn">Resume</a>';
                                } elseif ($term->is_fully_attempted && $session) {
                                    $url = esc_url(add_query_arg('session_id', $session['session_id'], $review_page_url));
                                    echo '<a href="' . $url . '" class="qp-button qp-button-secondary qp-progress-action-btn">Review</a>';
                                } else {
                                    echo '<button class="qp-button qp-button-primary qp-progress-start-btn qp-progress-action-btn" data-subject-id="' . esc_attr($subject_term_id) . '" data-section-id="' . esc_attr($term->term_id) . '">Start</button>';
                                }
                            }

                            echo '</div>';

                            echo '</div>';
                            echo '</div>';

                            if ($has_children) {
                                echo '<div class="qp-topic-sections-container" data-parent-topic="' . esc_attr($term->term_id) . '" style="display: none; padding-left: 20px;">';
                                qp_render_progress_tree_recursive($term->children, $review_page_url, $session_page_url, $subject_term_id);
                                echo '</div>';
                            }
                        }
                    }
                }
                if ($source_term_object && !empty($source_term_object->children)) {
                    qp_render_progress_tree_recursive($source_term_object->children, $review_page_url, $session_page_url, $subject_term_id);
                }
                ?>
            </div>
        </div>
<?php
        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX handler to get sources linked to a specific subject (for cascading dropdowns).
     */
    public static function get_sources_for_subject_cascading()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

        if (!$subject_id) {
            wp_send_json_error(['message' => 'Invalid subject ID.']);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // Find source terms (object_id) linked to the given subject term (term_id)
        $source_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$rel_table} WHERE term_id = %d AND object_type = 'source_subject_link'",
            $subject_id
        ));

        if (empty($source_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }

        $ids_placeholder = implode(',', $source_ids);
        $sources = $wpdb->get_results("SELECT term_id, name FROM {$term_table} WHERE term_id IN ($ids_placeholder) ORDER BY name ASC");

        wp_send_json_success(['sources' => $sources]);
    }

    /**
     * AJAX handler to get child terms (sections) for a given parent term (for cascading dropdowns).
     */
    public static function get_child_terms_cascading()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $parent_term_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;

        if (!$parent_term_id) {
            wp_send_json_error(['message' => 'Invalid parent ID.']);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        $child_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM {$term_table} WHERE parent = %d ORDER BY name ASC",
            $parent_term_id
        ));

        wp_send_json_success(['children' => $child_terms]);
    }

    /**
     * AJAX handler for the dashboard progress tab to get sources for a subject.
     * Renamed to avoid conflict.
     */
    public static function get_sources_for_subject_progress()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        $subject_term_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

        if (!$subject_term_id) {
            wp_send_json_success(['sources' => []]);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

        // --- THIS IS THE FIX ---
        // Use the existing helper function to get ALL descendant topics and sub-topics,
        // not just the direct children. This includes the parent subject ID itself.
        $topic_ids = Terms_DB::get_all_descendant_ids($subject_term_id); // Removed extra args
        // --- END FIX ---

        if (empty($topic_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }
        $topic_ids_placeholder = implode(',', $topic_ids);

        // Step 2: Find all question groups linked to those topics.
        $group_ids = $wpdb->get_col("SELECT object_id FROM $rel_table WHERE term_id IN ($topic_ids_placeholder) AND object_type = 'group'");

        if (empty($group_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }
        $group_ids_placeholder = implode(',', $group_ids);

        // Step 3: Find all source AND section terms linked to the relevant groups.
        $all_linked_source_term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT r.term_id
             FROM {$rel_table} r
             JOIN {$term_table} t ON r.term_id = t.term_id
             WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d",
            $source_tax_id
        ));

        if (empty($all_linked_source_term_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }

        // Step 4: For each linked term, trace up to find its top-level parent (the source).
        $top_level_source_ids = [];
        foreach ($all_linked_source_term_ids as $term_id) {
            $current_id = $term_id;
            for ($i = 0; $i < 10; $i++) { // Safety break to prevent infinite loops
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
            wp_send_json_success(['sources' => []]);
            return;
        }

        $source_ids_placeholder = implode(',', $unique_source_ids);

        // Step 5: Fetch the names of the unique, top-level sources.
        $source_terms = $wpdb->get_results(
            "SELECT term_id as source_id, name as source_name
             FROM {$term_table}
             WHERE term_id IN ($source_ids_placeholder)
             ORDER BY name ASC"
        );

        // Step 6: Format for the dropdown.
        $sources = [];
        foreach ($source_terms as $term) {
            $sources[] = [
                'source_id' => $term->source_id,
                'source_name' => $term->source_name
            ];
        }

        wp_send_json_success(['sources' => $sources]);
    }

    /**
     * AJAX handler to check remaining attempts/access for the current user.
     */
    public static function check_remaining_attempts()
    {
        // No nonce check needed for reads, but login is essential.
        if (!is_user_logged_in()) {
            wp_send_json_error(['has_access' => false, 'message' => 'Not logged in.', 'reason_code' => 'not_logged_in']);
            return;
        }

        $user_id = get_current_user_id();
        $has_access = false;
        $total_remaining = 0;
        $has_unlimited_attempts = false;
        $denial_reason_code = 'no_entitlements'; // Default reason if nothing is found

        global $wpdb;
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $current_time = current_time('mysql');

        // Query for ALL entitlement records for this user to determine the reason later
        $all_user_entitlements = $wpdb->get_results($wpdb->prepare(
            "SELECT entitlement_id, remaining_attempts, expiry_date, status
             FROM {$entitlements_table}
             WHERE user_id = %d",
            $user_id
        ));

        if (!empty($all_user_entitlements)) {
            $denial_reason_code = 'expired_or_inactive'; // Assume expired/inactive if records exist but don't grant access
            $found_active_non_expired = false;

            foreach ($all_user_entitlements as $entitlement) {
                // Check if the entitlement is currently valid (active status and not expired)
                $is_active = $entitlement->status === 'active';
                $is_expired = !is_null($entitlement->expiry_date) && $entitlement->expiry_date <= $current_time;

                if ($is_active && !$is_expired) {
                    $found_active_non_expired = true; // Found at least one potentially valid plan
                    $denial_reason_code = 'out_of_attempts'; // Assume out of attempts if active plans exist

                    if (is_null($entitlement->remaining_attempts)) {
                        // Found an active plan with UNLIMITED attempts
                        $has_unlimited_attempts = true;
                        $has_access = true;
                        $total_remaining = -1;
                        break; // Access granted, stop checking
                    } else {
                        // Add this plan's remaining attempts to the total
                        $total_remaining += (int) $entitlement->remaining_attempts;
                    }
                }
            } // End foreach

            // If no unlimited plan was found among active/non-expired ones, check the total attempts
            if (!$has_unlimited_attempts && $found_active_non_expired && $total_remaining > 0) {
                $has_access = true;
            }
            // If $found_active_non_expired is true but $total_remaining is 0, $denial_reason_code remains 'out_of_attempts'
            // If $found_active_non_expired is false, $denial_reason_code remains 'expired_or_inactive'

        } else {
            // No entitlement records found at all for the user
            $denial_reason_code = 'no_entitlements';
        }


        if ($has_access) {
            wp_send_json_success(['has_access' => true, 'remaining' => $has_unlimited_attempts ? -1 : $total_remaining]);
        } else {
            // Send the specific reason code along with has_access = false
            wp_send_json_success(['has_access' => false, 'remaining' => 0, 'reason_code' => $denial_reason_code]);
        }
    }

    /**
     * AJAX handler for enrolling a user in a course.
     */
    public static function enroll_in_course() {
        check_ajax_referer('qp_enroll_course_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in.']);
        }

        $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;
        $user_id = get_current_user_id();

        if (!$course_id || get_post_type($course_id) !== 'qp_course') {
            wp_send_json_error(['message' => 'Invalid course ID.']);
        }
        
        // Check if the course is published
        if ( get_post_status( $course_id ) !== 'publish' ) {
            wp_send_json_error(['message' => 'This course is no longer available for enrollment.']);
        }

        // --- NEW: Check for access and get the entitlement ID ---
        $access_result = User_Access::can_access_course($user_id, $course_id, true); // true = ignore enrollment check
        $entitlement_id_to_save = null; // Default to NULL (for free/admin)

        if ($access_result === false) {
            // No access at all
            wp_send_json_error(['message' => 'You do not have access to enroll in this course. Please purchase it first.', 'code' => 'access_denied']);
            return;
        } elseif (is_numeric($access_result)) {
            // Access granted by a specific entitlement
            $entitlement_id_to_save = absint($access_result);
        }
        // If $access_result === true, it's a free course or admin, so $entitlement_id_to_save remains NULL.
        // --- END NEW CHECK ---

        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';

        // --- NEW: Modified duplicate enrollment check ---
        $check_sql = "SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d";
        $check_params = [$user_id, $course_id];

        if (is_null($entitlement_id_to_save)) {
            $check_sql .= " AND entitlement_id IS NULL";
        } else {
            $check_sql .= " AND entitlement_id = %d";
            $check_params[] = $entitlement_id_to_save;
        }

        $is_enrolled = $wpdb->get_var($wpdb->prepare($check_sql, $check_params));
        // --- END MODIFIED CHECK ---

        if ($is_enrolled) {
            wp_send_json_success(['message' => 'Already enrolled.', 'already_enrolled' => true]);
            return;
        }

        // --- NEW: Modified INSERT query ---
        $result = $wpdb->insert($user_courses_table, [
            'user_id' => $user_id,
            'course_id' => $course_id,
            'entitlement_id' => $entitlement_id_to_save, // <-- ADDED
            'enrollment_date' => current_time('mysql'),
            'status' => 'enrolled',
            'progress_percent' => 0
        ], [
            '%d', // user_id
            '%d', // course_id
            '%d', // entitlement_id (NULL will be handled correctly)
            '%s', // enrollment_date
            '%s', // status
            '%d'  // progress_percent
        ]);
        // --- END MODIFIED INSERT ---

        if ($result) {
            wp_send_json_success(['message' => 'Successfully enrolled!']);
        } else {
            wp_send_json_error(['message' => 'Could not enroll in the course. Please try again.']);
        }
    }

    /**
     * AJAX handler to search for questions for the course editor modal.
     */
    public static function search_questions_for_course()
    {
        // 1. Security Checks
        check_ajax_referer('qp_course_editor_select_nonce', 'nonce');
        if (!current_user_can('manage_options')) { // Use appropriate capability
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        // 2. Sanitize Input Parameters
        $search_args = [
            'search'     => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'subject_id' => isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0,
            'topic_id'   => isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0,
            'source_id'  => isset($_POST['source_id']) ? absint($_POST['source_id']) : 0,
            'limit'      => 100 // Keep the limit for performance
        ];

        // 3. Call the DB Method
        $results = Questions_DB::search_questions($search_args);

        // 4. Format and Send Response
        $formatted_results = [];
        if ($results) {
            foreach ($results as $question) {
                $formatted_results[] = [
                    'id' => $question->question_id,
                    // Apply formatting here before sending
                    'text' => wp_strip_all_tags(wp_trim_words(stripslashes($question->question_text), 15, '...'))
                ];
            }
        }

        wp_send_json_success(['questions' => $formatted_results]);
    }

    // --- NEW METHODS MOVED FROM question-press.php ---

    /**
     * AJAX handler to get the practice form HTML.
     * Moved from global scope.
     */
    public static function get_practice_form_html_ajax()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        // Use global namespace \QP_Shortcodes as it's not refactored yet
        wp_send_json_success(['form_html' => Shortcodes::render_practice_form()]);
    }

    /**
     * AJAX handler to fetch the structure (sections and items) for a specific course.
     * Also fetches the user's progress for items within that course.
     * Moved from global scope.
     */
    public static function get_course_structure_ajax()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce'); // Re-use the existing frontend nonce

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in.']);
        }

        $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;
        $user_id = get_current_user_id();

        if (!$course_id) {
            wp_send_json_error(['message' => 'Invalid course ID.']);
        }

        // --- FIX START: Check for access mode ---
        $access_mode = get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free';

        // If the course is NOT free, THEN we check for access permissions.
        if ($access_mode !== 'free') {
            // Uses the imported User_Access class
            if (!User_Access::can_access_course($user_id, $course_id)) {
                wp_send_json_error(['message' => 'You do not have access to view this course structure.', 'code' => 'access_denied']);
                return; // Stop execution
            }
        }
        // If the course is 'free', we grant access to view the structure.
        // --- FIX END ---


        global $wpdb;
        $sections_table = $wpdb->prefix . 'qp_course_sections';
        $items_table = $wpdb->prefix . 'qp_course_items';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';
        $course_title = get_the_title($course_id); // Get course title from wp_posts

        $structure = [
            'course_id' => $course_id,
            'course_title' => $course_title,
            'sections' => []
        ];

        // Get sections for the course
        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
            $course_id
        ));

        if (empty($sections)) {
            wp_send_json_success($structure); // Send structure with empty sections array
            return;
        }

        $section_ids = wp_list_pluck($sections, 'section_id');
        $ids_placeholder = implode(',', array_map('absint', $section_ids));

        // Get all items for these sections, including progress status and result data
        $items_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT i.item_id, i.section_id, i.title, i.item_order, i.content_type, p.status, p.result_data -- <<< ADD p.result_data
             FROM $items_table i
             LEFT JOIN {$wpdb->prefix}qp_user_items_progress p ON i.item_id = p.item_id AND p.user_id = %d AND p.course_id = %d
             WHERE i.section_id IN ($ids_placeholder)
             ORDER BY i.item_order ASC",
            $user_id,
            $course_id
        ));

        // Organize items by section
        $items_by_section = [];
        foreach ($items_raw as $item) {
            $item->status = $item->status ?? 'not_started'; // Use fetched status or default

            // --- ADD THIS BLOCK ---
            $item->session_id = null; // Default to null
            if (!empty($item->result_data)) {
                $result_data_decoded = json_decode($item->result_data, true);
                if (isset($result_data_decoded['session_id'])) {
                    $item->session_id = absint($result_data_decoded['session_id']);
                }
            }
            unset($item->result_data); // Don't need to send the full result data to JS for this
            // --- END ADDED BLOCK ---

            if (!isset($items_by_section[$item->section_id])) {
                $items_by_section[$item->section_id] = [];
            }
            $items_by_section[$item->section_id][] = $item;
        }

        // Get user's progress for these items in this course
        $progress_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id, status FROM $progress_table WHERE user_id = %d AND course_id = %d",
            $user_id,
            $course_id
        ), OBJECT_K); // Keyed by item_id for easy lookup

        // Organize items by section
        $items_by_section = [];
        foreach ($items_raw as $item) {
            $item->status = $progress_raw[$item->item_id]->status ?? 'not_started'; // Add status
            if (!isset($items_by_section[$item->section_id])) {
                $items_by_section[$item->section_id] = [];
            }
            $items_by_section[$item->section_id][] = $item;
        }

        // Build the final structure
        foreach ($sections as $section) {
            $structure['sections'][] = [
                'id' => $section->section_id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->section_order,
                'items' => $items_by_section[$section->section_id] ?? []
            ];
        }

        wp_send_json_success($structure);
    }
}
