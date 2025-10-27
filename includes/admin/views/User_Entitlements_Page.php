<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Global classes used by the original function
use \QP_Entitlements_List_Table;

/**
 * Handles rendering the "User Entitlements & Scope" admin page.
 */
class User_Entitlements_Page {

	/**
     * Adds screen options for the Entitlements list table.
     * Hooked into 'admin_head' action via the Plugin class.
     * Replaces the old qp_add_entitlements_screen_options function.
     */
    public static function add_screen_options() {
        $screen = get_current_screen();
        // Check if we are on the correct screen
        // Note: The screen ID should match the $hook parameter in add_submenu_page
        // For 'qp-user-entitlements' under 'question-press', it's 'question-press_page_qp-user-entitlements'
        if ($screen && $screen->id === 'question-press_page_qp-user-entitlements') {
            // Call the static method from the List Table class
            \QP_Entitlements_List_Table::add_screen_options();
        }
    }

	/**
	 * Renders the "User Entitlements & Scope" admin page.
	 * Replaces the old qp_render_user_entitlements_page function.
	 */
	public static function render() {
		// --- User Search Logic ---
		$user_id_searched = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user_info = null;
		if ( $user_id_searched > 0 ) {
			$user_info = get_userdata( $user_id_searched );
		}
		// --- End User Search Logic ---

		// Prepare arguments for the template
		$args = [
			'user_id_searched' => $user_id_searched,
			'user_info'        => $user_info,
		];

		// Load and echo the template
		echo \qp_get_template_html( 'user-entitlements-page', 'admin', $args );

	} // End render()

} // End class