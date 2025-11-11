<?php

namespace QuestionPress\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_Error;

/**
 * Handles course-related business logic.
 */
class Course_Manager {

    /**
     * Enrolls a user in a course.
     *
     * @param array $params The parameters for enrollment.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function enroll_in_course( $params ) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $course_id = isset($params['course_id']) ? absint($params['course_id']) : 0;
        $user_id = get_current_user_id();

        if (!$course_id || get_post_type($course_id) !== 'qp_course') {
            return new WP_Error('invalid_course_id', 'Invalid course ID.', ['status' => 400]);
        }
        
        if ( get_post_status( $course_id ) !== 'publish' ) {
            return new WP_Error('course_not_available', 'This course is no longer available for enrollment.', ['status' => 403]);
        }

        $access_result = User_Access::can_access_course($user_id, $course_id, true);
        $entitlement_id_to_save = null;

        if ($access_result === false) {
            return new WP_Error('access_denied', 'You do not have access to enroll in this course. Please purchase it first.', ['status' => 403]);
        } elseif (is_numeric($access_result)) {
            $entitlement_id_to_save = absint($access_result);
        }

        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';

        $check_sql = "SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d";
        $check_params = [$user_id, $course_id];

        if (is_null($entitlement_id_to_save)) {
            $check_sql .= " AND entitlement_id IS NULL";
        } else {
            $check_sql .= " AND entitlement_id = %d";
            $check_params[] = $entitlement_id_to_save;
        }

        $is_enrolled = $wpdb->get_var($wpdb->prepare($check_sql, $check_params));

        if ($is_enrolled) {
            return ['message' => 'Already enrolled.', 'already_enrolled' => true];
        }

        $result = $wpdb->insert($user_courses_table, [
            'user_id' => $user_id,
            'course_id' => $course_id,
            'entitlement_id' => $entitlement_id_to_save,
            'enrollment_date' => current_time('mysql'),
            'status' => 'enrolled',
            'progress_percent' => 0
        ], [
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
            '%d'
        ]);

        if ($result) {
            return ['message' => 'Successfully enrolled!'];
        } else {
            return new WP_Error('enrollment_failed', 'Could not enroll in the course. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Searches for questions for the course editor modal.
     *
     * @param array $params The parameters for the search.
     * @return array|WP_Error An array of formatted questions, or a WP_Error on failure.
     */
    public static function search_questions_for_course( $params ) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'Permission denied.', ['status' => 403]);
        }

        $search_args = [
            'search'     => isset($params['search']) ? sanitize_text_field(wp_unslash($params['search'])) : '',
            'subject_id' => isset($params['subject_id']) ? absint($params['subject_id']) : 0,
            'topic_id'   => isset($params['topic_id']) ? absint($params['topic_id']) : 0,
            'source_id'  => isset($params['source_id']) ? absint($params['source_id']) : 0,
            'limit'      => 100
        ];

        $results = \QuestionPress\Database\Questions_DB::search_questions($search_args);

        $formatted_results = [];
        if ($results) {
            foreach ($results as $question) {
                $formatted_results[] = [
                    'id' => $question->question_id,
                    'text' => wp_strip_all_tags(wp_trim_words(stripslashes($question->question_text), 15, '...'))
                ];
            }
        }

        return ['questions' => $formatted_results];
    }

    /**
     * Retrieves data needed to render the practice form.
     *
     * @return array|WP_Error An array with form data, or a WP_Error on failure.
     */
    public static function get_practice_form_data() {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        // The original method directly called Shortcodes::render_practice_form()
        // which handles all data retrieval internally.
        // For refactoring, we need to extract that data.
        // This is a placeholder, as the Shortcodes class is not yet refactored.
        // In a real scenario, the data would be fetched here using other Managers/DB classes.
        // For now, we'll simulate returning data that the AJAX handler can use to call the shortcode.
        
        // This is a simplification. Ideally, Shortcodes::render_practice_form()
        // would be broken down into data retrieval and rendering parts.
        // For this refactoring step, we'll assume the Shortcodes class
        // will eventually be updated to accept data rather than fetch it all.
        
        // For now, we'll just indicate success. The AJAX handler will still call the shortcode.
        return ['message' => 'Practice form data ready for rendering.'];
    }

    /**
     * Fetches the structure (sections and items) for a specific course,
     * including the user's progress for items within that course.
     *
     * @param array $params The parameters for the request, typically from $_POST.
     * @return array|WP_Error An array with course structure and progress, or a WP_Error on failure.
     */
    public static function get_course_structure( $params ) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $course_id = isset($params['course_id']) ? absint($params['course_id']) : 0;
        $user_id = get_current_user_id();

        if (!$course_id) {
            return new WP_Error('invalid_course_id', 'Invalid course ID.', ['status' => 400]);
        }

        $access_mode = get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free';

        if ($access_mode !== 'free') {
            if (!\QuestionPress\Utils\User_Access::can_access_course($user_id, $course_id)) {
                return new WP_Error('access_denied', 'You do not have access to view this course structure.', ['status' => 403]);
            }
        }

        global $wpdb;
        $sections_table = $wpdb->prefix . 'qp_course_sections';
        $items_table = $wpdb->prefix . 'qp_course_items';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';
        $course_title = get_the_title($course_id);

        $structure = [
            'course_id' => $course_id,
            'course_title' => $course_title,
            'sections' => []
        ];

        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
            $course_id
        ));

        if (empty($sections)) {
            return $structure;
        }

        $section_ids = wp_list_pluck($sections, 'section_id');
        $ids_placeholder = implode(',', array_map('absint', $section_ids));

        $items_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT i.item_id, i.section_id, i.title, i.item_order, i.content_type, p.status, p.result_data
             FROM $items_table i
             LEFT JOIN {$wpdb->prefix}qp_user_items_progress p ON i.item_id = p.item_id AND p.user_id = %d AND p.course_id = %d
             WHERE i.section_id IN ($ids_placeholder)
             ORDER BY i.item_order ASC",
            $user_id,
            $course_id
        ));

        $items_by_section = [];
        foreach ($items_raw as $item) {
            $item->status = $item->status ?? 'not_started';

            $item->session_id = null;
            if (!empty($item->result_data)) {
                $result_data_decoded = json_decode($item->result_data, true);
                if (isset($result_data_decoded['session_id'])) {
                    $item->session_id = absint($result_data_decoded['session_id']);
                }
            }
            unset($item->result_data);

            if (!isset($items_by_section[$item->section_id])) {
                $items_by_section[$item->section_id] = [];
            }
            $items_by_section[$item->section_id][] = $item;
        }

        foreach ($sections as $section) {
            $structure['sections'][] = [
                'id' => $section->section_id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->section_order,
                'items' => $items_by_section[$section->section_id] ?? []
            ];
        }

        return $structure;
    }

    /**
     * Deregisters a user from a course, deleting associated progress and session data.
     *
     * @param array $params The parameters for deregistration.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function deregister_from_course( $params ) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in.', ['status' => 401]);
        }

        $user_id = get_current_user_id();
        $course_id = isset($params['course_id']) ? absint($params['course_id']) : 0;

        if (!$course_id || get_post_type($course_id) !== 'qp_course') {
            return new WP_Error('invalid_course', 'Invalid course.', ['status' => 400]);
        }

        $options = get_option('qp_settings');
        $allow_global_opt_out = (bool) ($options['allow_course_opt_out'] ?? 0);
        if (!$allow_global_opt_out) {
            return new WP_Error('opt_out_disabled_global', 'This action is not enabled globally.', ['status' => 403]);
        }

        $allow_course_opt_out = (bool) get_post_meta($course_id, '_qp_course_allow_opt_out', true);
        if (!$allow_course_opt_out) {
            return new WP_Error('opt_out_disabled_course', 'You are not allowed to deregister from this specific course.', ['status' => 403]);
        }

        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';

        try {
            $wpdb->query('START TRANSACTION');

            $wpdb->delete($user_courses_table, ['user_id' => $user_id, 'course_id' => $course_id], ['%d', '%d']);

            $wpdb->delete($progress_table, ['user_id' => $user_id, 'course_id' => $course_id], ['%d', '%d']);

            $session_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT session_id FROM {$sessions_table}
                 WHERE user_id = %d
                   AND settings_snapshot LIKE %s",
                $user_id,
                '%"course_id":' . $course_id . '%'
            ));

            if (!empty($session_ids)) {
                $ids_placeholder = implode(',', array_map('absint', $session_ids));
                $wpdb->query("DELETE FROM {$attempts_table} WHERE session_id IN ({$ids_placeholder})");
                $wpdb->query("DELETE FROM {$sessions_table} WHERE session_id IN ({$ids_placeholder})");
            }

            $wpdb->query('COMMIT');
            return ['message' => 'You have been successfully deregistered from the course.'];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'A database error occurred. Could not complete the action.', ['status' => 500]);
        }
    }
}