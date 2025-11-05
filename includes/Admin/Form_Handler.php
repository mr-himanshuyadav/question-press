<?php
namespace QuestionPress\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Database\Terms_DB;

/**
 * Handles various admin-side form submissions.
 * Initially, these are hooked to 'admin_init', but will be
 * refactored to use 'admin_post_' hooks.
 */
class Form_Handler {

	/**
     * Handles all report actions, including bulk, single, and clear.
     */
    public static function handle_report_actions() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'qp-logs-reports' || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $reports_table = "{$wpdb->prefix}qp_question_reports";

        // === 1. Handle Bulk Actions ===
        if ( isset( $_GET['bulk_action'] ) ) {
            // ... (existing bulk action logic) ...
            // Check for bulk actions
            $action = $_GET['bulk_action'] ?? '-1';
            if ( $action === '-1' && isset( $_GET['bulk_action2'] ) ) {
                $action = $_GET['bulk_action2'];
            }

            if ( ( $action === 'resolve' || $action === 'reopen' ) && isset( $_GET['report_ids'] ) ) {
                check_admin_referer( 'qp_bulk_report_action_nonce' );
                $report_ids = array_map( 'absint', $_GET['report_ids'] );
                $ids_placeholder = implode( ',', $report_ids );
                $new_status = ( $action === 'resolve' ) ? 'resolved' : 'open';

                $wpdb->query( "UPDATE {$reports_table} SET status = '{$new_status}' WHERE report_id IN ($ids_placeholder)" );

                $message_code = ( $action === 'resolve' ) ? '1' : '2';
                $redirect_url = admin_url( 'admin.php?page=qp-logs-reports&tab=reports&message=' . $message_code );
                wp_safe_redirect( $redirect_url );
                exit;
            }
            return; // Return after handling or ignoring bulk action
        }

        // === 2. Handle Single Link Actions ===
        if ( isset( $_GET['action'] ) ) {
            // Handle single resolve action
            if ( $_GET['action'] === 'resolve_report' && isset( $_GET['question_id'] ) ) {
                $question_id = absint( $_GET['question_id'] );
                check_admin_referer( 'qp_resolve_report_' . $question_id );
                $wpdb->update( $reports_table, [ 'status' => 'resolved' ], [ 'question_id' => $question_id, 'status' => 'open' ] );
                wp_safe_redirect( admin_url( 'admin.php?page=qp-logs-reports&tab=reports&message=3' ) );
                exit;
            }

            // Handle single re-open action
            if ( $_GET['action'] === 'reopen_report' && isset( $_GET['question_id'] ) ) {
                $question_id = absint( $_GET['question_id'] );
                check_admin_referer( 'qp_reopen_report_' . $question_id );
                $wpdb->update( $reports_table, [ 'status' => 'open' ], [ 'question_id' => $question_id, 'status' => 'resolved' ] );
                wp_safe_redirect( admin_url( 'admin.php?page=qp-logs-reports&tab=reports&status=resolved&message=4' ) );
                exit;
            }

            // Handle clearing all resolved reports
            if ( $_GET['action'] === 'clear_resolved_reports' ) {
                check_admin_referer( 'qp_clear_all_reports_nonce' );
                $wpdb->delete( $reports_table, [ 'status' => 'resolved' ] );
                wp_safe_redirect( admin_url( 'admin.php?page=qp-logs-reports&tab=reports&status=resolved&message=5' ) );
                exit;
            }
        }
    }

	/**
     * Handles resolving all open reports for a group from the question editor page.
     * Replaces the old qp_handle_resolve_from_editor function.
     * Hooked to 'admin_init'.
     */
    public static function handle_resolve_from_editor() {
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'resolve_group_reports' && isset( $_GET['group_id'] ) ) {
            $group_id = absint( $_GET['group_id'] );

            // Check nonce
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'qp_resolve_group_reports_' . $group_id ) ) {
                wp_die( 'Security check failed.' );
            }
            // Check capability
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'You do not have permission to perform this action.' );
            }

            global $wpdb;
            $reports_table = $wpdb->prefix . 'qp_question_reports';

            // Get all question IDs in the group
            $question_ids = $wpdb->get_col( $wpdb->prepare( "SELECT question_id FROM {$wpdb->prefix}qp_questions WHERE group_id = %d", $group_id ) );

            if ( ! empty( $question_ids ) ) {
                $ids_placeholder = implode( ',', $question_ids );
                // Only update 'open' reports
                $wpdb->query( "UPDATE $reports_table SET status = 'resolved' WHERE question_id IN ($ids_placeholder) AND status = 'open'" );
            }

            // Redirect back to the editor
            $redirect_url = admin_url( 'admin.php?page=qp-edit-group&group_id=' . $group_id );
            $redirect_url = add_query_arg( ['message' => 'reports_resolved'], $redirect_url ); // Use WP's message system
            wp_safe_redirect( $redirect_url );
            exit;
        }
        // --- End corrected logic ---
    }

	/**
     * NEW: Handles the 'admin_post_qp_perform_merge' action from the Merge Terms page.
     */
    public static function handle_perform_merge() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'qp_perform_merge' || !check_admin_referer('qp_perform_merge_nonce')) {
            wp_die('Security check failed.');
        }
        if (!current_user_can('manage_options')) wp_die('Permission denied.');

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $meta_table = $wpdb->prefix . 'qp_term_meta';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // Sanitize all POST data
        $destination_term_id = absint($_POST['destination_term_id']);
        $source_term_ids_raw = isset($_POST['source_term_ids']) ? (array) $_POST['source_term_ids'] : [];
        $source_term_ids = array_map('absint', $source_term_ids_raw);
        $final_name = sanitize_text_field($_POST['term_name']);
        $final_parent = absint($_POST['parent']);
        $final_description = sanitize_textarea_field($_POST['term_description']);
        $taxonomy_name = sanitize_key($_POST['taxonomy_name'] ?? '');

        // Remove the destination from the list of sources
        $source_term_ids_to_merge = array_diff($source_term_ids, [$destination_term_id]);
        
        if (empty($source_term_ids_to_merge) || empty($taxonomy_name) || empty($final_name)) {
            // Something went wrong, just redirect back
             \QuestionPress\Admin\Admin_Utils::set_message('Merge failed: Missing data.', 'error');
             \QuestionPress\Admin\Admin_Utils::redirect_to_tab($taxonomy_name . 's'); // e.g., 'subjects'
        }
        
        $ids_placeholder = implode(',', $source_term_ids_to_merge);

        // --- Re-assign relationships ---
        // This is a simplified merge. The logic in QP_Terms_List_Table::recursively_merge_terms was much more complex
        // and handled child terms and session data. We must replicate *that* logic here.
        // For now, let's use the simple logic from the old handler:
        
        // Re-assign GROUP relationships
        $wpdb->query($wpdb->prepare(
            "UPDATE $rel_table SET term_id = %d WHERE term_id IN ($ids_placeholder) AND object_type = 'group'",
            $destination_term_id
        ));

        // Re-assign QUESTION relationships (e.g., for Labels)
        $wpdb->query($wpdb->prepare(
            "UPDATE $rel_table SET term_id = %d WHERE term_id IN ($ids_placeholder) AND object_type = 'question'",
            $destination_term_id
        ));
        
        // --- Re-parent child terms ---
        $wpdb->query($wpdb->prepare(
            "UPDATE $term_table SET parent = %d WHERE parent IN ($ids_placeholder)",
            $destination_term_id
        ));

        // --- Update the final destination term ---
        $wpdb->update($term_table, 
            ['name' => $final_name, 'slug' => sanitize_title($final_name), 'parent' => $final_parent], 
            ['term_id' => $destination_term_id]
        );
        \QuestionPress\Database\Terms_DB::update_meta($destination_term_id, 'description', $final_description);

        // --- Delete the old terms ---
        $wpdb->query("DELETE FROM $term_table WHERE term_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $meta_table WHERE term_id IN ($ids_placeholder)");
        
        \QuestionPress\Admin\Admin_Utils::set_message(count($source_term_ids_to_merge) . ' item(s) were successfully merged into "' . esc_html($final_name) . '".', 'updated');
        \QuestionPress\Admin\Admin_Utils::redirect_to_tab($taxonomy_name . 's');
    }

}