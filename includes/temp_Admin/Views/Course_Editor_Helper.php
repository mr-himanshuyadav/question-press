<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper class for fetching data for admin editor pages (courses & questions).
 *
 * @package QuestionPress\Admin\Views
 */
class Course_Editor_Helper {

    /**
     * Helper function to retrieve the existing course structure for the editor.
     *
     * @param int $course_id The ID of the course post.
     * @return array The structured course data.
     */
    public static function get_course_structure_for_editor( $course_id ) {
        if ( ! $course_id ) {
            return [ 'sections' => [] ]; // Return empty structure for new courses
        }

        global $wpdb;
        $sections_table = $wpdb->prefix . 'qp_course_sections';
        $items_table    = $wpdb->prefix . 'qp_course_items';
        $structure      = [ 'sections' => [] ];

        $sections = $wpdb->get_results( $wpdb->prepare(
            "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
            $course_id
        ) );

        if ( empty( $sections ) ) {
            return $structure;
        }

        $section_ids     = wp_list_pluck( $sections, 'section_id' );
        $ids_placeholder = implode( ',', array_map( 'absint', $section_ids ) );

        $items_raw = $wpdb->get_results( "SELECT item_id, section_id, title, item_order, content_type, content_config FROM $items_table WHERE section_id IN ($ids_placeholder) ORDER BY item_order ASC" );

        $items_by_section = [];
        foreach ( $items_raw as $item ) {
            $item->content_config = json_decode( $item->content_config, true ); // Decode JSON
            if ( ! isset( $items_by_section[ $item->section_id ] ) ) {
                $items_by_section[ $item->section_id ] = [];
            }
            $items_by_section[ $item->section_id ][] = $item;
        }

        foreach ( $sections as $section ) {
            $structure['sections'][] = [
                'id'          => $section->section_id,
                'title'       => $section->title,
                'description' => $section->description,
                'order'       => $section->section_order,
                'items'       => $items_by_section[ $section->section_id ] ?? [],
            ];
        }

        return $structure;
    }

    /**
     * Fetches all taxonomy data needed for the Question Editor dropdowns.
     *
     * @return array
     */
    public static function get_question_editor_dropdown_data() {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table  = $wpdb->prefix . 'qp_taxonomies';
        $rel_table  = $wpdb->prefix . 'qp_term_relationships';

        $subject_tax_id = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'" );
        $label_tax_id   = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'" );
        $exam_tax_id    = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'" );
        $source_tax_id  = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'" );

        $all_subjects = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC", $subject_tax_id ) );
        $all_subject_terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $subject_tax_id ) );
        $all_labels = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS label_id, name AS label_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $label_tax_id ) );
        $all_exams = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS exam_id, name AS exam_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $exam_tax_id ) );
        $all_source_terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_id as id, name, parent as parent_id FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $source_tax_id ) );

        $source_subject_links = $wpdb->get_results( "SELECT object_id AS source_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'source_subject_link'" );
        $exam_subject_links = $wpdb->get_results( "SELECT object_id AS exam_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'exam_subject_link'" );
        $all_parent_sources = array_filter( $all_source_terms, fn( $term ) => $term->parent_id == 0 );

        return [
            'all_subjects'         => $all_subjects,
            'all_subject_terms'    => $all_subject_terms,
            'all_labels'           => $all_labels,
            'all_exams'            => $all_exams,
            'all_source_terms'     => $all_source_terms,
            'source_subject_links' => $source_subject_links,
            'exam_subject_links'   => $exam_subject_links,
            'all_parent_sources'   => $all_parent_sources,
        ];
    }

    /**
     * UPDATED: Fetches data needed for test series options (JS).
     * This was qp_get_test_series_options_for_js
     */
    public static function get_test_series_options_for_js() {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table  = $wpdb->prefix . 'qp_taxonomies';
        $rel_table  = $wpdb->prefix . 'qp_term_relationships';

        $subject_tax_id = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'" );
        $source_tax_id  = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'" );

        // Fetch ALL subjects and topics together
        $all_subject_terms = $wpdb->get_results( $wpdb->prepare(
            "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $subject_tax_id
        ), ARRAY_A ); // Fetch as associative arrays for JS

        // Fetch ALL source terms (including sections)
        $all_source_terms = $wpdb->get_results( $wpdb->prepare(
            "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $source_tax_id
        ), ARRAY_A );

        // Fetch source-subject links
        $source_subject_links = $wpdb->get_results(
            "SELECT object_id AS source_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'source_subject_link'",
            ARRAY_A
        );

        return [
            'allSubjectTerms'    => $all_subject_terms,
            'allSourceTerms'     => $all_source_terms, // Add source terms
            'sourceSubjectLinks' => $source_subject_links, // Add source-subject links
        ];
    }
}