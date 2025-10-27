<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// We need the page classes this function uses
use \QP_Subjects_Page;
use \QP_Labels_Page;
use \QP_Exams_Page;
use \QP_Sources_Page;

/**
 * Handles rendering the "Organize" admin page with its tabs.
 */
class Organization_Page {

	/**
	 * Renders the "Organize" admin page and its tabs using a template.
	 * Replaces the old qp_render_organization_page function.
	 */
	public static function render() {
		$tabs = [
			'subjects' => ['label' => 'Subjects', 'callback' => ['\QP_Subjects_Page', 'render']], // Still uses global class for now
			'labels'   => ['label' => 'Labels', 'callback' => ['\QP_Labels_Page', 'render']],     // Still uses global class for now
			'exams'    => ['label' => 'Exams', 'callback' => ['\QP_Exams_Page', 'render']],       // Still uses global class for now
			'sources'  => ['label' => 'Sources', 'callback' => ['\QP_Sources_Page', 'render']],    // Still uses global class for now
		];
		$active_tab = isset( $_GET['tab'] ) && array_key_exists( $_GET['tab'], $tabs ) ? $_GET['tab'] : 'subjects';

		// --- Capture the output of the active tab's render function ---
		ob_start();
		// Ensure the callback exists before calling it
		if ( isset($tabs[$active_tab]['callback']) && is_callable($tabs[$active_tab]['callback']) ) {
			call_user_func( $tabs[$active_tab]['callback'] );
		} else {
			echo '<p>Error: Could not load tab content.</p>'; // Basic error message
		}
		$tab_content_html = ob_get_clean();
		// --- End capturing ---

		// Prepare arguments for the wrapper template
		$args = [
			'tabs'             => $tabs,
			'active_tab'       => $active_tab,
			'tab_content_html' => $tab_content_html,
		];

		// Load and echo the wrapper template using the global function
		echo \qp_get_template_html( 'organization-page-wrapper', 'admin', $args );
	}
}