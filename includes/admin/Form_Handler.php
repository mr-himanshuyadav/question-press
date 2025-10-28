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

}