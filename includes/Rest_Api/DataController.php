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
} // End class DataController