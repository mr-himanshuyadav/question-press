<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// We need access to the List Table class
use \QP_Questions_List_Table;

/**
 * Handles rendering the "All Questions" admin page.
 */
class All_Questions_Page {

	/**
	 * Renders the main "All Questions" admin page using a template.
	 * This method replaces the old qp_all_questions_page_cb function.
	 */
	public static function render() {
		// Instantiate the list table
		$list_table = new QP_Questions_List_Table();
		// Prepare items (fetches data based on current request parameters)
		$list_table->prepare_items();

		// Capture session messages
		ob_start();
		if ( isset( $_SESSION['qp_admin_message'] ) ) {
			// Ensure session message type exists before using it.
			$message_type = isset($_SESSION['qp_admin_message_type']) ? $_SESSION['qp_admin_message_type'] : 'info'; // Default to 'info'
			$message = html_entity_decode( $_SESSION['qp_admin_message'] );
			echo '<div id="message" class="notice notice-' . esc_attr( $message_type ) . ' is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
			unset( $_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type'] );
		}
		// Handle standard WordPress update/save messages
		if ( isset( $_GET['message'] ) ) {
			$messages = ['1' => 'Question(s) updated successfully.', '2' => 'Question(s) saved successfully.'];
			$message_id = absint( $_GET['message'] );
			if ( isset( $messages[$message_id] ) ) {
				echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html( $messages[$message_id] ) . '</p></div>';
			}
		}
		$session_message_html = ob_get_clean();

		// Capture bulk edit message
		ob_start();
		if ( isset( $_GET['bulk_edit_message'] ) && $_GET['bulk_edit_message'] === '1' ) {
			echo '<div id="message" class="notice notice-success is-dismissible"><p>Questions have been bulk updated successfully.</p></div>';
		}
		$bulk_edit_message_html = ob_get_clean();


		// Capture list table views
		ob_start();
		$list_table->views();
		$views_html = ob_get_clean();

		// Capture search box
		ob_start();
		$list_table->search_box( 'Search Questions', 'question' );
		$search_box_html = ob_get_clean();

		// Capture list table display
		ob_start();
		$list_table->display();
		$list_table_display_html = ob_get_clean();

		// Capture view modal HTML
		ob_start();
		$list_table->display_view_modal();
		$view_modal_html = ob_get_clean();

		// Prepare arguments for the template
		$args = [
			'add_new_url'            => admin_url( 'admin.php?page=qp-question-editor' ),
			'session_message_html'   => $session_message_html,
			'bulk_edit_message_html' => $bulk_edit_message_html,
			'views_html'             => $views_html,
			'search_box_html'        => $search_box_html,
			'list_table_display_html'=> $list_table_display_html,
			'view_modal_html'        => $view_modal_html,
			'page_slug'              => isset( $_REQUEST['page'] ) ? esc_attr( $_REQUEST['page'] ) : 'question-press', // Pass current page slug
		];

		// Load and echo the template using the global function (we'll move this later if needed)
		echo \qp_get_template_html( 'all-questions-page', 'admin', $args );
	}
}