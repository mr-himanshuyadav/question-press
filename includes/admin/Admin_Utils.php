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
     * Redirects non-admin users trying to access the default WordPress profile page
     * (profile.php or user-edit.php) to the frontend dashboard's profile tab.
     * Replaces the old qp_redirect_wp_profile_page function.
     * Hooked to 'admin_init'.
     */
    public static function redirect_wp_profile_page() {
        // --- Logic copied from qp_redirect_wp_profile_page() ---
        global $pagenow;
        // Check if we're on the profile.php or user-edit.php page
        if ( $pagenow === 'profile.php' || $pagenow === 'user-edit.php' ) {
            // Check if the user is NOT an administrator (or can't manage_options)
            if ( ! current_user_can( 'manage_options' ) ) {
                // Get the dashboard page ID from settings
                $qp_settings = get_option( 'qp_settings', [] );
                $dashboard_page_id = isset( $qp_settings['dashboard_page'] ) ? absint( $qp_settings['dashboard_page'] ) : 0;

                if ( $dashboard_page_id > 0 ) {
                    $dashboard_url = get_permalink( $dashboard_page_id );
                    if ( $dashboard_url ) {
                        // Append the profile tab query var
                        $profile_url = add_query_arg( 'qp_tab', 'profile', $dashboard_url );
                        wp_safe_redirect( $profile_url );
                        exit;
                    }
                }
            }
        }
        // --- End logic from qp_redirect_wp_profile_page() ---
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

	/**
	 * Adds a "(Free Course)" indicator to courses in the admin list.
	 * Hooked to 'display_post_states'.
	 *
	 * @param array   $post_states An array of post states.
	 * @param \WP_Post $post        The current post object.
	 * @return array  The modified array of post states.
	 */
	public static function add_course_post_states( $post_states, $post ) {
		// Only check for 'qp_course' post type
		if ( $post->post_type === 'qp_course' ) {
			$access_mode = get_post_meta( $post->ID, '_qp_course_access_mode', true );
			
			// If mode is 'free' (or not set, defaulting to 'free'), add the state
			if ( empty( $access_mode ) || $access_mode === 'free' ) {
				$post_states['qp_free_course'] = __( 'Free Course', 'question-press' );
			}
		}
		return $post_states;
	}

} // End class Admin_Utils