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
	 * Handles bulk actions from the Reports admin page.
	 * Replaces the old qp_handle_report_actions function.
	 * Hooked to 'admin_init'.
	 */
	public static function handle_report_actions() {
		// --- Logic copied from qp_handle_report_actions() ---
		if ( ! isset( $_REQUEST['page'] ) || $_REQUEST['page'] !== 'qp-logs-reports' ) {
			return; // Not on the reports page
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return; // Permission check
		}

		$reports_list_table = new \QP_Reports_List_Table(); // Use global class
		$action = $reports_list_table->current_action();

		if ( $action && isset( $_REQUEST['report_ids'] ) ) {
			// Check nonce
			if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-reports' ) ) {
				wp_die( 'Security check failed for bulk reports action.' );
			}

			global $wpdb;
			$reports_table = $wpdb->prefix . 'qp_question_reports';
			$report_ids = array_map( 'absint', $_REQUEST['report_ids'] );
			$ids_placeholder = implode( ',', $report_ids );
			$redirect_url = admin_url( 'admin.php?page=qp-logs-reports&tab=main' ); // Redirect back to main reports tab

			if ( $action === 'bulk_resolve' ) {
				$wpdb->query( "UPDATE $reports_table SET status = 'resolved' WHERE report_id IN ($ids_placeholder)" );
				$redirect_url = add_query_arg( ['message' => 'resolved', 'count' => count( $report_ids )], $redirect_url );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			if ( $action === 'bulk_delete' ) {
				$wpdb->query( "DELETE FROM $reports_table WHERE report_id IN ($ids_placeholder)" );
				$redirect_url = add_query_arg( ['message' => 'deleted', 'count' => count( $report_ids )], $redirect_url );
				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
		// --- End logic from qp_handle_report_actions() ---
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