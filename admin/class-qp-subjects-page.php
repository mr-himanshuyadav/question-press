<?php
if (!defined('ABSPATH')) exit;

use QuestionPress\Database\Terms_DB;

// Ensure the new list table class is included
require_once QP_PLUGIN_PATH . 'admin/class-qp-terms-list-table.php';

class QP_Subjects_Page {

    public static function handle_forms() {
        // Handle bulk actions first
        if (isset($_GET['tab']) && $_GET['tab'] === 'subjects') {
            $list_table = new QP_Terms_List_Table('subject', 'Subject/Topic', 'subjects');
            $list_table->process_bulk_action();
        }
        
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'subjects') {
            return;
        }
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $meta_table = $wpdb->prefix . 'qp_term_meta';

        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        if (!$source_tax_id) return;

        

        // Delete Handler
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['term_id']) && check_admin_referer('qp_delete_subject_' . absint($_GET['term_id']))) {
            $term_id = absint($_GET['term_id']);
            $error_messages = [];

            // NEW Check 1: Prevent deletion if it's linked to any sources
                $linked_source_ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'source_subject_link'", $term_id));
                if (!empty($linked_source_ids)) {
                $source_count = count($linked_source_ids);
                $ids_placeholder = implode(',', $linked_source_ids);
                $source_names = $wpdb->get_col("SELECT name FROM $term_table WHERE term_id IN ($ids_placeholder)");
                $formatted_count = "<strong><span style='color:red;'>{$source_count} source(s)</span></strong>";
                // Wrap each name in a <strong> tag
                $formatted_names = implode(', ', array_map(function($name) { return '<strong>' . esc_html($name) . '</strong>'; }, $source_names));
                $error_messages[] = "{$formatted_count} are linked to it. Linked Source(s): " . $formatted_names . ".";
            }

                // NEW Check 2: Prevent deletion if it's linked to any exams
                $linked_exam_ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'exam_subject_link'", $term_id));
            if (!empty($linked_exam_ids)) {
                $exam_count = count($linked_exam_ids);
                $ids_placeholder = implode(',', $linked_exam_ids);
                $exam_names = $wpdb->get_col("SELECT name FROM $term_table WHERE term_id IN ($ids_placeholder)");
                $formatted_count = "<strong><span style='color:red;'>{$exam_count} exam(s)</span></strong>";
                // Wrap each name in a <strong> tag
                $formatted_names = implode(', ', array_map(function($name) { return '<strong>' . esc_html($name) . '</strong>'; }, $exam_names));
                $error_messages[] = "{$formatted_count} are linked to it. Linked exam(s): " . $formatted_names . ".";
            }

            // Check 1: Prevent deletion if it has child terms (sections/sub-sections)
            $child_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $term_table WHERE parent = %d", $term_id));
            if ($child_count > 0) {
                $formatted_count = "<strong><span style='color:red;'>{$child_count} topic(s)</span></strong>";
                $error_messages[] = "{$formatted_count} are associated with it.";
            }

                        // Check 2: Prevent deletion if it or any of its children are linked to questions
            $descendant_ids = [$term_id];
            $current_parent_ids = [$term_id];
            for ($i = 0; $i < 5; $i++) { // Safety break after 5 levels
                if (empty($current_parent_ids)) break;
                $ids_placeholder = implode(',', $current_parent_ids);
                $child_ids = $wpdb->get_col("SELECT term_id FROM $term_table WHERE parent IN ($ids_placeholder)");
                if (!empty($child_ids)) {
                    $descendant_ids = array_merge($descendant_ids, $child_ids);
                    $current_parent_ids = $child_ids;
                } else {
                    break;
                }
            }
            $descendant_ids_placeholder = implode(',', array_unique($descendant_ids));
            $usage_count = $wpdb->get_var("SELECT COUNT(DISTINCT object_id) FROM $rel_table WHERE object_type = 'question' AND term_id IN ($descendant_ids_placeholder)");

            if ($usage_count > 0) {
                $formatted_count = "<strong><span style='color:red;'>{$usage_count} question(s)</span></strong>";
                $error_messages[] = "{$formatted_count} are linked to it (or its topics).";
            }

            if (!empty($error_messages)) {
                $message = "This item cannot be deleted for the following reasons:<br>" . implode('<br>', $error_messages);
                \QuestionPress\Admin\Admin_Utils::set_message($message, 'error');
            } else {
                $wpdb->delete($term_table, ['term_id' => $term_id]);
                $wpdb->delete($meta_table, ['term_id' => $term_id]);
                \QuestionPress\Admin\Admin_Utils::set_message('Subject/Topic deleted successfully.', 'updated');
            }
            \QuestionPress\Admin\Admin_Utils::redirect_to_tab('subjects');
        }
    }

    /**
     * NEW: Handles the 'admin_post_qp_add_subject_term' action.
     */
    public static function handle_add_term() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'qp_add_subject_term' || !check_admin_referer('qp_add_edit_subject_nonce')) {
            wp_die('Security check failed.');
        }
        if (!current_user_can('manage_options')) wp_die('Permission denied.');

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        
        $term_name = sanitize_text_field($_POST['term_name']);
        $description = sanitize_textarea_field($_POST['term_description']);
        $parent = absint($_POST['parent']);

        if (empty($term_name)) {
            \QuestionPress\Admin\Admin_Utils::set_message('Name cannot be empty.', 'error');
        } else {
            $data = ['name' => $term_name, 'slug' => sanitize_title($term_name), 'parent' => $parent, 'taxonomy_id' => $source_tax_id];
            $wpdb->insert($term_table, $data);
            $term_id = $wpdb->insert_id;
            
            if ($term_id > 0) {
                Terms_DB::update_meta($term_id, 'description', $description);
                \QuestionPress\Admin\Admin_Utils::set_message('Subject/Topic added.', 'updated');
            }
        }
        \QuestionPress\Admin\Admin_Utils::redirect_to_tab('subjects');
    }

    /**
     * NEW: Handles the 'admin_post_qp_update_subject_term' action.
     */
    public static function handle_update_term() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'qp_update_subject_term' || !check_admin_referer('qp_add_edit_subject_nonce')) {
            wp_die('Security check failed.');
        }
        if (!current_user_can('manage_options')) wp_die('Permission denied.');

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $term_id = absint($_POST['term_id']);
        $term_name = sanitize_text_field($_POST['term_name']);
        $description = sanitize_textarea_field($_POST['term_description']);
        $parent = absint($_POST['parent']);

        if (empty($term_name)) {
            \QuestionPress\Admin\Admin_Utils::set_message('Name cannot be empty.', 'error');
        } else {
            $data = ['name' => $term_name, 'slug' => sanitize_title($term_name), 'parent' => $parent, 'taxonomy_id' => $source_tax_id];
            $wpdb->update($term_table, $data, ['term_id' => $term_id]);
            Terms_DB::update_meta($term_id, 'description', $description);
            \QuestionPress\Admin\Admin_Utils::set_message('Subject/Topic updated.', 'updated');
        }
        \QuestionPress\Admin\Admin_Utils::redirect_to_tab('subjects');
    }

    public static function render() {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        
        $term_to_edit = null;
        $edit_description = '';

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['term_id'])) {
            $term_id = absint($_GET['term_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_subject_' . $term_id)) {
                $term_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $term_table WHERE term_id = %d", $term_id));
                if ($term_to_edit) {
                    $edit_description = Terms_DB::get_meta($term_id, 'description', true);
                }
            }
        }
        
        $parent_subjects = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC", $subject_tax_id));

        // Prepare the list table
        $list_table = new QP_Terms_List_Table('subject', 'Subject/Topic', 'subjects');
        $list_table->prepare_items();
        
        // Capture the list table's HTML
        ob_start();
        $list_table->display();
        $list_table_html = ob_get_clean();

        // Display session messages before loading the template
        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        
        // Prepare arguments for the template
        $args = [
            'term_to_edit'      => $term_to_edit,
            'edit_description'  => $edit_description,
            'parent_subjects'   => $parent_subjects,
            'list_table_html'   => $list_table_html,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'organization-tab-subjects', 'admin', $args );
    }
}