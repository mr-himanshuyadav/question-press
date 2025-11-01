<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// We also need the classes this page calls:
use QuestionPress\Admin\Views\Import_Page;
use QuestionPress\Admin\Views\Export_Page;
use QuestionPress\Admin\Views\Backup_Restore_Page;

/**
 * Handles rendering the "Tools" admin page with its tabs.
 */
class Tools_Page {

	/**
	 * Renders the "Tools" admin page and its tabs.
	 * Replaces the old qp_render_tools_page function.
	 */
	public static function render() {
		$tabs = [
			'import'         => ['label' => 'Import', 'callback' => [Import_Page::class, 'render']],
			'export'         => ['label' => 'Export', 'callback' => [Export_Page::class, 'render']],
			'backup_restore' => ['label' => 'Backup & Restore', 'callback' => [Backup_Restore_Page::class, 'render']],
		];
		$active_tab = isset( $_GET['tab'] ) && array_key_exists( $_GET['tab'], $tabs ) ? $_GET['tab'] : 'import';
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tools', 'question-press' ); ?></h1>
			<p><?php esc_html_e( 'Import, export, and manage your Question Press data.', 'question-press' ); ?></p>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
				<?php
				foreach ( $tabs as $tab_id => $tab_data ) {
					$class = ( $tab_id === $active_tab ) ? ' nav-tab-active' : '';
					echo '<a href="?page=qp-tools&tab=' . esc_attr( $tab_id ) . '" class="nav-tab' . esc_attr( $class ) . '">' . esc_html( $tab_data['label'] ) . '</a>';
				}
				?>
			</nav>

			<div class="tab-content" style="margin-top: 1.5rem;">
				<?php
				// Ensure the callback exists before calling it
				if ( isset($tabs[$active_tab]['callback']) && is_callable($tabs[$active_tab]['callback']) ) {
					call_user_func( $tabs[$active_tab]['callback'] );
				} else {
					echo '<p>Error: Could not load tab content.</p>'; // Basic error message
				}
				?>
			</div>
		</div>
		<?php
	}
}