<?php
namespace QuestionPress\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility functions for the admin area.
 */
class Admin_Utils {

	/**
	 * Adds a "(Question Press)" indicator to the plugin's pages in the admin list.
	 * Hooked to 'display_post_states'.
	 * Replaces the old qp_add_page_indicator function.
	 *
	 * @param array   $post_states An array of post states.
	 * @param \WP_Post $post        The current post object.
	 * @return array  The modified array of post states.
	 */
	public static function add_page_indicator( $post_states, $post ) {
		// Get the saved IDs of our plugin's pages
		// Use get_option with default value
		$qp_settings = get_option( 'qp_settings', [] );
		$qp_page_ids = [
			// Ensure keys exist before accessing, default to 0
			isset( $qp_settings['practice_page'] ) ? absint( $qp_settings['practice_page'] ) : 0,
			isset( $qp_settings['dashboard_page'] ) ? absint( $qp_settings['dashboard_page'] ) : 0,
			isset( $qp_settings['session_page'] ) ? absint( $qp_settings['session_page'] ) : 0,
			isset( $qp_settings['review_page'] ) ? absint( $qp_settings['review_page'] ) : 0,
		];
		// Filter out 0 values if pages weren't set
		$qp_page_ids = array_filter( $qp_page_ids );

		// Check if the current page's ID is one of our plugin's pages
		if ( in_array( $post->ID, $qp_page_ids, true ) ) {
			$post_states['question_press_page'] = __( 'Question Press Page', 'question-press' ); // Use translation
		}

		return $post_states;
	}

	/**
	 * Sets a session-based admin notice.
	 *
	 * @param string $message The message to display.
	 * @param string $type    The notice type (e.g., 'updated', 'error', 'success', 'info').
	 */
	public static function set_message( $message, $type ) {
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			$_SESSION['qp_admin_message'] = $message;
			$_SESSION['qp_admin_message_type'] = $type;
		}
	}

	/**
	 * Redirects to a specific admin page tab.
	 *
	 * @param string $tab The slug of the tab to redirect to.
	 */
	public static function redirect_to_tab( $tab ) {
		wp_safe_redirect( admin_url( 'admin.php?page=qp-organization&tab=' . $tab ) );
		exit;
	}

} // End class Admin_Utils