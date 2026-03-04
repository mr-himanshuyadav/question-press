<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// We need the page classes this function uses
use QuestionPress\Admin\Views\Subjects_Page;
use QuestionPress\Admin\Views\Labels_Page;
use QuestionPress\Admin\Views\Exams_Page;
use QuestionPress\Admin\Views\Sources_Page;
use QuestionPress\Utils\Template_Loader;

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
			'subjects' => ['label' => 'Subjects', 'callback' => [Subjects_Page::class, 'render']], // Still uses global class for now
			'labels'   => ['label' => 'Labels', 'callback' => [Labels_Page::class, 'render']],     // Still uses global class for now
			'exams'    => ['label' => 'Exams', 'callback' => [Exams_Page::class, 'render']],       // Still uses global class for now
			'sources'  => ['label' => 'Sources', 'callback' => [Sources_Page::class, 'render']],    // Still uses global class for now
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
		echo Template_Loader::get_html( 'organization-page-wrapper', 'admin', $args );
	}
}