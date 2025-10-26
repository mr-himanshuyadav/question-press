<?php
if (!defined('ABSPATH')) exit;

class QP_Exams_Page {

    /**
     * Handles form submissions for the Exams tab using the new taxonomy system.
     */
    public static function handle_forms() {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'exams') {
            return;
        }
        
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");
        if (!$exam_tax_id) return; // Failsafe if migration hasn't run

        // Add/Update Handler
        if (isset($_POST['action']) && ($_POST['action'] === 'add_exam' || $_POST['action'] === 'update_exam') && check_admin_referer('qp_add_edit_exam_nonce')) {
            $exam_name = sanitize_text_field($_POST['exam_name']);
            $linked_subject_term_ids = isset($_POST['linked_subjects']) ? array_map('absint', $_POST['linked_subjects']) : [];

            if (empty($exam_name)) {
                QP_Sources_Page::set_message('Exam name cannot be empty.', 'error');
            } else {
                $term_data = [
                    'taxonomy_id' => $exam_tax_id,
                    'name' => $exam_name,
                    'slug' => sanitize_title($exam_name)
                ];
                $term_id = 0;

                if ($_POST['action'] === 'update_exam') {
                    $term_id = absint($_POST['term_id']);
                    $wpdb->update($term_table, $term_data, ['term_id' => $term_id]);
                    QP_Sources_Page::set_message('Exam updated successfully.', 'updated');
                } else {
                    $wpdb->insert($term_table, $term_data);
                    $term_id = $wpdb->insert_id;
                    QP_Sources_Page::set_message('Exam added successfully.', 'updated');
                }

                if ($term_id > 0) {
                    // Handle subject linking in the new relationship table
                    // Here, the 'object' is the exam term itself, linking to subject terms.
                    $wpdb->delete($rel_table, ['object_id' => $term_id, 'object_type' => 'exam_subject_link']);
                    if (!empty($linked_subject_term_ids)) {
                        foreach ($linked_subject_term_ids as $subject_term_id) {
                            $wpdb->insert($rel_table, [
                                'object_id'   => $term_id, 
                                'term_id'     => $subject_term_id, 
                                'object_type' => 'exam_subject_link'
                            ]);
                        }
                    }
                }
            }
            QP_Sources_Page::redirect_to_tab('exams');
        }

        // Delete Handler
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['term_id']) && check_admin_referer('qp_delete_exam_' . absint($_GET['term_id']))) {
            $term_id = absint($_GET['term_id']);

            // Corrected usage check: Count individual questions within the groups linked to this exam.
            $usage_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(q.question_id)
                 FROM {$wpdb->prefix}qp_questions q
                 JOIN {$rel_table} rel ON q.group_id = rel.object_id
                 WHERE rel.term_id = %d AND rel.object_type = 'group'",
                $term_id
            ));
            
            if ($usage_count > 0) {
                $formatted_count = "<strong><span style='color:red;'>{$usage_count} question(s).</span></strong>";
                QP_Sources_Page::set_message("This exam cannot be deleted because it is in use by {$formatted_count}", 'error');
            } else {
                // Delete the term and its relationships (like linked subjects)
                $wpdb->delete($term_table, ['term_id' => $term_id]);
                $wpdb->delete($rel_table, ['object_id' => $term_id, 'object_type' => 'exam_subject_link']);
                QP_Sources_Page::set_message('Exam deleted successfully.', 'updated');
            }
            QP_Sources_Page::redirect_to_tab('exams');
        }   
    }

    public static function render() {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        
        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $exam_to_edit = null;
        $linked_subjects_for_edit = [];

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['term_id'])) {
            $term_id = absint($_GET['term_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_exam_' . $term_id)) {
                $exam_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $term_table WHERE term_id = %d AND taxonomy_id = %d", $term_id, $exam_tax_id));
                if ($exam_to_edit) {
                    // Get linked subject term IDs
                    $linked_subjects_for_edit = $wpdb->get_col($wpdb->prepare(
                        "SELECT term_id FROM $rel_table WHERE object_id = %d AND object_type = 'exam_subject_link'",
                        $term_id
                    ));
                }
            }
        }
        
        // Get all available subjects (which are now parent-level terms)
        $all_subjects = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND name != 'Uncategorized' ORDER BY name ASC", 
            $subject_tax_id
        ));
        
        // Fetch all exam terms for the display table
        $exams = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d ORDER BY name ASC", $exam_tax_id));

        // Fetch all subject links for all exams in one query for efficiency
        $all_linked_subjects = $wpdb->get_results("
            SELECT rel.object_id as exam_term_id, terms.name as subject_name
            FROM $rel_table rel
            JOIN $term_table terms ON rel.term_id = terms.term_id
            WHERE rel.object_type = 'exam_subject_link'
        ");

        $subjects_by_exam = [];
        foreach ($all_linked_subjects as $link) {
            $subjects_by_exam[$link->exam_term_id][] = $link->subject_name;
        }

        // Display session messages before loading the template
        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }

        // Prepare arguments for the template
        $args = [
            'exam_to_edit'             => $exam_to_edit,
            'linked_subjects_for_edit' => $linked_subjects_for_edit,
            'all_subjects'             => $all_subjects,
            'exams'                    => $exams,
            'subjects_by_exam'         => $subjects_by_exam,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'organization-tab-exams', 'admin', $args );
    }
}