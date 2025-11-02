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
     * Displays session-based admin notices globally.
     * Hooked to 'admin_notices'.
     */
    public static function display_admin_notices() {
        // Check if a session is active and the message exists
        if ( session_status() === PHP_SESSION_ACTIVE && isset( $_SESSION['qp_admin_message'] ) ) {
            
            // We must use wp_kses_post here to allow the <strong> tags from our error message
            $message = wp_kses_post( $_SESSION['qp_admin_message'] ); 
            $type = esc_attr( $_SESSION['qp_admin_message_type'] ?? 'info' ); // Default to 'info'

            echo "<div class='notice notice-{$type} is-dismissible'><p>{$message}</p></div>";
            
            // Clear the session variable so it doesn't show again
            unset( $_SESSION['qp_admin_message'] );
            unset( $_SESSION['qp_admin_message_type'] );
        }
    }

	/**
     * Displays a persistent admin notice if WooCommerce is not active.
     * Hooked to 'admin_notices' from Plugin.php.
     */
    public static function show_woocommerce_required_notice() {
        // Don't show this notice on the plugins page
        global $pagenow;
        if ( $pagenow === 'plugins.php' ) {
            return;
        }
        
        // Check if WooCommerce is installed but not active
        $plugin_slug = 'woocommerce/woocommerce.php';
        $all_plugins = get_plugins();
        $is_installed = array_key_exists( $plugin_slug, $all_plugins );
        
        $message = '<strong>Question Press Monetization requires WooCommerce.</strong>';
        $button_html = '';

        if ( current_user_can( 'activate_plugins' ) ) {
            if ( $is_installed ) {
                // Is installed but not active
                $activate_url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . $plugin_slug ), 'activate-plugin_' . $plugin_slug );
                $message .= ' Please activate WooCommerce to enable course purchases.';
                $button_html = '<a href="' . esc_url( $activate_url ) . '" class="button button-primary" style="margin-left: 10px;">Activate WooCommerce</a>';
            } else {
                // Is not installed
                $install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ), 'install-plugin_woocommerce' );
                $message .= ' Please install WooCommerce to enable course purchases.';
                $button_html = '<a href="' . esc_url( $install_url ) . '" class="button button-primary" style="margin-left: 10px;">Install WooCommerce</a>';
            }
        } else {
            // User cannot install/activate plugins
            $message .= ' Please contact your site administrator to install or activate WooCommerce.';
        }

        echo '<div class="notice notice-error" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 15px;">
                <p style="margin: 0;">' . $message . '</p>
                <p style="margin: 0;">' . $button_html . '</p>
              </div>';
    }

    /**
     * Adds an indicator to auto-generated WooCommerce products.
     * Hooked to 'display_post_states'.
     *
     * @param array   $post_states An array of post states.
     * @param \WP_Post $post        The current post object.
     * @return array  The modified array of post states.
     */
    public static function add_product_post_states( $post_states, $post ) {
        // Only check for 'product' post type
        if ( $post->post_type !== 'product' ) {
            return $post_states;
        }

        // Check if it's our auto-generated product
        if ( get_post_meta( $post->ID, '_qp_is_auto_generated', true ) === 'true' ) {
            
            $course_id = get_post_meta( $post->ID, '_qp_linked_course_id', true );
            $plan_id = get_post_meta( $post->ID, '_qp_linked_plan_id', true );

            $links_html = [];

            // Add Course Link
            if ( $course_id && get_post( $course_id ) ) {
                $course_link = get_edit_post_link( $course_id );
                $links_html[] = sprintf(
                    'Course (ID: %d) <a href="%s" title="Edit Course">Edit</a>',
                    esc_html( $course_id ),
                    esc_url( $course_link )
                );
            } else if ($course_id) {
                $links_html[] = 'Course (ID: ' . esc_html( $course_id ) . ') - Missing';
            }

            // Add Plan Link
            if ( $plan_id && get_post( $plan_id ) ) {
                $plan_link = get_edit_post_link( $plan_id );
                $links_html[] = sprintf(
                    'Plan (ID: %d) <a href="%s" title="View Plan">View</a>',
                    esc_html( $plan_id ),
                    esc_url( $plan_link )
                );
            } else if ($plan_id) {
                $links_html[] = 'Plan (ID: ' . esc_html( $plan_id ) . ') - Missing';
            }

            // Create the final red indicator text
            $indicator_text = '<span style="color:#d63638;">Linked to: ' . implode(' | ', $links_html) . '</span>';
            
            // Add the new state
            $post_states['qp_auto_product'] = $indicator_text;
        }
        return $post_states;
    }

} // End class Admin_Utils