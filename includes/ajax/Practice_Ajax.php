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

        $result = \QuestionPress\Utils\Practice_Manager::check_answer($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to save a user's selected answer during a mock test.
     * Includes access check and attempt decrement logic using entitlements table.
     */
    public static function save_mock_attempt()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::save_mock_attempt($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to update the status of a mock test question.
     * Handles statuses like viewed, marked_for_review, etc.
     */
    public static function update_mock_status()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Practice_Manager::update_mock_status($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to mark a question as 'expired' for a session.
     */
    public static function expire_question()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Practice_Manager::expire_question($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to skip a question.
     */
    public static function skip_question()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Practice_Manager::skip_question($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to add or remove a question from the user's review list.
     */
    public static function toggle_review_later()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::toggle_review_later([
            'question_id' => isset($_POST['question_id']) ? absint($_POST['question_id']) : 0,
            'is_marked' => isset($_POST['is_marked']) && $_POST['is_marked'] === 'true'
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
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

        $result = \QuestionPress\Utils\Practice_Manager::get_single_question_for_review($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to submit a new question report from the modal.
     */
    public static function submit_question_report()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::submit_question_report($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get all active report reasons.
     */
    public static function get_report_reasons()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_report_reasons([]); // No params needed

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            $reasons_by_type = $result;
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
    }

    /**
     * AJAX handler to get the number of unattempted questions for the current user.
     */
    public static function get_unattempted_counts()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Practice_Manager::get_unattempted_counts();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get the full data for a single question for the practice UI.
     */
    public static function get_question_data()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result_data = \QuestionPress\Utils\Practice_Manager::get_question_data([
            'question_id' => isset($_POST['question_id']) ? absint($_POST['question_id']) : 0,
            'session_id' => isset($_POST['session_id']) ? absint($_POST['session_id']) : 0
        ]);

        if (is_wp_error($result_data)) {
            wp_send_json_error(['message' => $result_data->get_error_message()]);
        } else {
            wp_send_json_success($result_data);
        }
    }

    /**
     * AJAX handler to get topics for a subject THAT HAVE QUESTIONS.
     */
    public static function get_topics_for_subject()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_topics_for_subject([
            'subject_id' => isset($_POST['subject_id']) ? $_POST['subject_id'] : []
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get sections containing questions for a given subject and topic.
     */
    public static function get_sections_for_subject()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_sections_for_subject([
            'topic_id' => isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get sources linked to a specific subject.
     */
    public static function get_sources_for_subject()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_sources_for_subject([
            'subject_id' => isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get child terms (sections) for a given parent term.
     */
    public static function get_child_terms()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_child_terms([
            'parent_id' => isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler for the dashboard progress tab.
     * Calculates and returns the hierarchical progress data.
     */
    public static function get_progress_data()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Practice_Manager::get_progress_data([
            'subject_id' => isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0,
            'source_id' => isset($_POST['source_id']) ? absint($_POST['source_id']) : 0,
            'exclude_incorrect' => isset($_POST['exclude_incorrect']) && $_POST['exclude_incorrect'] === 'true'
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            $subject_name = $result['subject_name'];
            $subject_percentage = $result['subject_percentage'];
            $source_term_object = $result['source_term_object'];
            $review_page_url = $result['review_page_url'];
            $session_page_url = $result['session_page_url'];

            ob_start();
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
                    if ($source_term_object && !empty($source_term_object->children)) {
                        self::render_progress_tree_recursive($source_term_object->children, $review_page_url, $session_page_url, $result['subject_term_id']);
                    }
                    ?>
                </div>
            </div>
<?php
            $html = ob_get_clean();
            wp_send_json_success(['html' => $html]);
        }
    }

    /**
     * Recursively renders the progress tree HTML.
     *
     * @param array $terms The terms to render.
     * @param string $review_page_url URL for the review page.
     * @param string $session_page_url URL for the session page.
     * @param int $subject_term_id The ID of the current subject term.
     */
    private static function render_progress_tree_recursive($terms, $review_page_url, $session_page_url, $subject_term_id)
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
                self::render_progress_tree_recursive($term->children, $review_page_url, $session_page_url, $subject_term_id);
                echo '</div>';
            }
        }
    }

    /**
     * AJAX handler to get sources linked to a specific subject (for cascading dropdowns).
     */
    public static function get_sources_for_subject_cascading()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_sources_for_subject_cascading([
            'subject_id' => isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get child terms (sections) for a given parent term (for cascading dropdowns).
     */
    public static function get_child_terms_cascading()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_child_terms_cascading([
            'parent_id' => isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler for the dashboard progress tab to get sources for a subject.
     * Renamed to avoid conflict.
     */
    public static function get_sources_for_subject_progress()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Practice_Manager::get_sources_for_subject_progress([
            'subject_id' => isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success($result);
        }
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

        $result = \QuestionPress\Utils\Practice_Manager::check_remaining_attempts();

        if (is_wp_error($result)) {
            wp_send_json_error(['has_access' => false, 'message' => $result->get_error_message(), 'reason_code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler for enrolling a user in a course.
     */
    public static function enroll_in_course() {
        check_ajax_referer('qp_enroll_course_nonce', 'nonce');

        $result = \QuestionPress\Utils\Course_Manager::enroll_in_course($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to search for questions for the course editor modal.
     */
    public static function search_questions_for_course()
    {
        check_ajax_referer('qp_course_editor_select_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $result = \QuestionPress\Utils\Course_Manager::search_questions_for_course($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    // --- NEW METHODS MOVED FROM question-press.php ---

    /**
     * AJAX handler to get the practice form HTML.
     * Moved from global scope.
     */
    public static function get_practice_form_html_ajax()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');
        
        $result = \QuestionPress\Utils\Course_Manager::get_practice_form_data();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            // Use global namespace \QP_Shortcodes as it's not refactored yet
            wp_send_json_success(['form_html' => Shortcodes::render_practice_form()]);
        }
    }

    /**
     * AJAX handler to fetch the structure (sections and items) for a specific course.
     * Also fetches the user's progress for items within that course.
     * Moved from global scope.
     */
    public static function get_course_structure_ajax()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce'); // Re-use the existing frontend nonce

        $result = \QuestionPress\Utils\Course_Manager::get_course_structure([
            'course_id' => isset($_POST['course_id']) ? absint($_POST['course_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler for a user to deregister (opt-out) from a course.
     */
    public static function deregister_from_course() {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Course_Manager::deregister_from_course([
            'course_id' => isset($_POST['course_id']) ? absint($_POST['course_id']) : 0
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to check if a username is available.
     */
    public static function check_username_availability() {
        // No nonce check needed for a public availability check
        $result = \QuestionPress\Utils\Auth_Manager::check_username_availability($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to check if an email is available.
     */
    public static function check_email_availability() {
        // No nonce check needed for a public availability check
        $result = \QuestionPress\Utils\Auth_Manager::check_email_availability($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler for resending an OTP code.
     */
    public static function resend_registration_otp() {
        if ( session_status() === PHP_SESSION_NONE ) {
            session_start();
        }

        // No nonce needed here, as we're acting on session data, not POST data.
        $email = $_SESSION['qp_signup_data']['email'] ?? '';

        $result = \QuestionPress\Utils\Auth_Manager::resend_registration_otp(['email' => $email]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX handler to get buffered question data.
     */
    public static function get_buffered_question_data()
    {
        check_ajax_referer('qp_practice_nonce', 'nonce');

        $result = \QuestionPress\Utils\Practice_Manager::get_buffered_question_data($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['questions' => $result]);
        }
    }
}
