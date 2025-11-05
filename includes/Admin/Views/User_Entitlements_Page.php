<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Utils\Template_Loader;

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
            Entitlements_List_Table::add_screen_options();
        }
    }

	/**
     * Saves the screen options for the "User Entitlements" list table.
     * Hooked into the 'set-screen-option' filter.
     * Replaces the old qp_save_entitlements_screen_options function.
     *
     * @param mixed  $status Screen option value. Default false to skip.
     * @param string $option The option name.
     * @param mixed  $value  The new value.
     * @return mixed The validated value to save, or false to skip saving.
     */
    public static function save_screen_options( $status, $option, $value ) {
        // Check if the option being saved is the one for entitlements per page
        if ( 'entitlements_per_page' === $option ) {
            return $value; // Return the value to save it
        }
        // Important: Return the original status for other options
        return $status;
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
        echo Template_Loader::get_html( 'user-entitlements-page', 'admin', $args );

	} // End render()

	/**
     * Handles saving the user's subject scope from the User Entitlements page.
     * Hooked to 'admin_post_qp_save_user_scope'.
     * Replaces the old qp_handle_save_user_scope function.
     */
    public static function handle_save_scope() {
        // 1. Check Nonce (copied from original function)
        if ( ! isset( $_POST['_qp_scope_nonce'] ) || ! wp_verify_nonce( $_POST['_qp_scope_nonce'], 'qp_save_user_scope_nonce' ) ) {
            wp_die( 'Security check failed. Please try again.' );
        }

        // 2. Check Capability (copied from original function)
        if ( ! current_user_can( 'manage_options' ) ) { // Or a more specific capability if you have one
            wp_die( 'You do not have permission to perform this action.' );
        }

        // 3. Get and Sanitize Data (copied from original function)
        $user_id_to_update = isset( $_POST['user_id_to_update'] ) ? absint( $_POST['user_id_to_update'] ) : 0;
        if ( $user_id_to_update === 0 ) {
            wp_die( 'Invalid User ID. Please go back and try again.' );
        }

        // Sanitize allowed exams
        $allowed_exams = [];
        if ( isset( $_POST['allowed_exams'] ) && is_array( $_POST['allowed_exams'] ) ) {
            $allowed_exams = array_map( 'absint', $_POST['allowed_exams'] );
        }

        // Sanitize allowed subjects
        $allowed_subjects = [];
        if ( isset( $_POST['allowed_subjects'] ) && is_array( $_POST['allowed_subjects'] ) ) {
            $allowed_subjects = array_map( 'absint', $_POST['allowed_subjects'] );
        }

        // 4. Update Usermeta (copied from original function)
        // Note: Storing as JSON-encoded strings
        update_user_meta( $user_id_to_update, '_qp_allowed_exam_term_ids', wp_json_encode( $allowed_exams ) );
        update_user_meta( $user_id_to_update, '_qp_allowed_subject_term_ids', wp_json_encode( $allowed_subjects ) );

        // 5. Redirect back (copied from original function)
        $redirect_url = add_query_arg(
            [
                'page'      => 'qp-user-entitlements',
                'user_id'   => $user_id_to_update,
                'message'   => 'scope_updated', // Add a success message query arg
            ],
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

} // End class