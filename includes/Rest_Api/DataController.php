<?php

namespace QuestionPress\Rest_Api; // PSR-4 Namespace

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_Error;
use WP_REST_Response;
use QuestionPress\Database\Terms_DB; // Use our DB class
use QuestionPress\Utils\User_Access; // For course access checks

/**
 * Handles REST API requests for retrieving data (subjects, topics, etc.).
 */
class DataController
{

    /**
     * Callback to get all subjects.
     */
    public static function get_subjects()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $subject_tax_id = Terms_DB::get_taxonomy_id_by_name('subject');

        if (!$subject_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC",
            $subject_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all topics.
     */
    public static function get_topics()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $subject_tax_id = Terms_DB::get_taxonomy_id_by_name('subject');

        if (!$subject_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS topic_id, name AS topic_name, parent AS subject_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0 ORDER BY name ASC",
            $subject_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all exams.
     */
    public static function get_exams()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $exam_tax_id = Terms_DB::get_taxonomy_id_by_name('exam');

        if (!$exam_tax_id) {
            return new WP_REST_Response([], 200); // Return empty if taxonomy doesn't exist
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id as exam_id, name as exam_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $exam_tax_id
        ));

        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all sources and sections.
     */
    public static function get_sources()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $source_tax_id = Terms_DB::get_taxonomy_id_by_name('source');

        if (!$source_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS source_id, name AS source_name, parent AS parent_id FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $source_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all labels.
     */
    public static function get_labels()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();
        $meta_table = Terms_DB::get_term_meta_table_name();

        $label_tax_id = Terms_DB::get_taxonomy_id_by_name('label');

        if (!$label_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id as label_id, t.name as label_name, m.meta_value as label_color
             FROM {$term_table} t
             LEFT JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'color'
             WHERE t.taxonomy_id = %d 
             ORDER BY t.name ASC",
            $label_tax_id
        ));

        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all published qp_course posts.
     */
    public static function get_courses() {
        $courses = get_posts([
            'post_type' => 'qp_course',
            'post_status' => 'publish',
            'numberposts' => -1, // Get all courses
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if (empty($courses)) {
            return new \WP_REST_Response([], 200);
        }

        // Format the data for the app
        $formatted_courses = [];
        foreach ($courses as $course_post) {
            $formatted_courses[] = [
                'id' => $course_post->ID,
                'title' => $course_post->post_title,
                // You can add more data here if you need it in the app
                // 'thumbnail_url' => get_the_post_thumbnail_url($course_post->ID, 'medium'),
            ];
        }

        return new \WP_REST_Response($formatted_courses, 200);
    }    
    /**
     * Callback to get the details for a single qp_course.
     * v4: Corrected based on user feedback.
     * - 'test_id' is the 'item_id' itself.
     * - 'content_type' is 'test_series'.
     */
    public static function get_course_details( \WP_REST_Request $request ) {
        $course_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if ( ! $course_id ) {
            return new \WP_Error('rest_invalid_id', 'Invalid course ID.', ['status' => 400]);
        }

        // --- Enrollment Check (This part is correct) ---
        if ( ! User_Access::can_access_course( $user_id, $course_id ) ) {
            $plan_id = get_post_meta( $course_id, '_qp_linked_plan_id', true );
            if ( ! empty( $plan_id ) ) {
                 return new \WP_Error('rest_forbidden', 'You are not enrolled in this course.', ['status' => 403]);
            }
        }

        $course_post = get_post( $course_id );
        if ( ! $course_post || $course_post->post_type !== 'qp_course' || $course_post->post_status !== 'publish' ) {
            return new \WP_Error('rest_not_found', 'Course not found.', ['status' => 404]);
        }

        // --- Build Structure from Custom Tables (CORRECTED) ---
        global $wpdb;
        $structure = [];
        $sections_table = $wpdb->prefix . 'qp_course_sections';
        $items_table = $wpdb->prefix . 'qp_course_items';

        // 1. Get all sections
        $sections = $wpdb->get_results( $wpdb->prepare(
            "SELECT section_id, title FROM {$sections_table} WHERE course_id = %d ORDER BY section_order ASC",
            $course_id
        ) );

        // 2. Get all items
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, section_id, title, content_type
             FROM {$items_table} 
             WHERE course_id = %d 
             ORDER BY section_id ASC, item_order ASC",
            $course_id
        ) );

        // 3. Group items by their section_id for easier lookup
        $items_by_section = [];
        foreach ( $items as $item ) {
            $items_by_section[ $item->section_id ][] = $item;
        }

        // 4. Build the flat structure array
        if ( ! empty( $sections ) ) {
            foreach ( $sections as $section ) {
                // Add the Section to the structure
                $structure[] = [
                    'id'    => 'section_' . $section->section_id,
                    'type'  => 'section',
                    'title' => $section->title,
                ];

                // Check if this section has items and add them
                if ( isset( $items_by_section[ $section->section_id ] ) ) {
                    foreach ( $items_by_section[ $section->section_id ] as $item ) {
                        
                        // --- THIS IS THE NEW LOGIC ---
                        $test_id = null;
                        // Check for 'test_series' (with underscore)
                        if ( $item->content_type === 'test_series' ) {
                            // The test_id *is* the item_id.
                            $test_id = (string) $item->item_id;
                        }
                        // --- END NEW LOGIC ---

                        // Add the Item to the structure
                        $structure[] = [
                            'id'      => 'item_' . $item->item_id,
                            'type'    => $item->content_type,
                            'title'   => $item->title,
                            'test_id' => $test_id, // Pass the item_id as the test_id
                        ];
                    }
                }
            }
        }

        // --- Success ---
        $data = [
            'id' => $course_post->ID,
            'title' => $course_post->post_title,
            'content' => $course_post->post_content,
            'structure' => $structure,
        ];

        return new \WP_REST_Response( $data, 200 );
    }
    /**
     * Gets the results for a specific, completed session.
     */
    public static function get_session_results( \WP_REST_Request $request ) {
        $session_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if ( ! $session_id ) {
            return new WP_Error('rest_invalid_id', 'Invalid session ID.', ['status' => 400]);
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // --- Security Check ---
        // First, check if the session belongs to the current user
        $session_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$sessions_table} WHERE session_id = %d",
            $session_id
        ) );

        if ( ! $session_user_id ) {
            return new WP_Error('rest_not_found', 'Session not found.', ['status' => 404]);
        }
        
        if ( (int) $session_user_id !== $user_id ) {
            return new WP_Error('rest_forbidden', 'You do not have permission to view this session.', ['status' => 403]);
        }

        // --- Fetch Results ---
        // We know the user is allowed to see this, so get the data.
        // We get the column names from Activator.php
        $results = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                session_id, 
                status, 
                total_attempted, 
                correct_count, 
                incorrect_count, 
                skipped_count, 
                marks_obtained 
             FROM {$sessions_table} 
             WHERE session_id = %d",
            $session_id
        ), ARRAY_A ); // ARRAY_A gives us a clean associative array

        if ( ! $results ) {
            // This should be rare, but good to check
             return new WP_Error('rest_no_data', 'Could not retrieve session results.', ['status' => 500]);
        }

        return new WP_REST_Response( $results, 200 );
    }
} // End class DataController