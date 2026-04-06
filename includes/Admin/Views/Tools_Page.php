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
			'data_integrity' => ['label' => 'Data Integrity', 'callback' => [self::class, 'render_integrity_tab']],
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

	/**
	 * Renders the Data Integrity tab content.
	 */
	public static function render_integrity_tab() {
		$nonce = wp_create_nonce('qp_admin_integrity_nonce');
		?>
		<div class="qp-integrity-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
			<div class="card">
				<h2>Initialize User Vaults</h2>
				<p>Creates missing vault entries for all users to ensure analytics and revision tracking work correctly.</p>
				<button id="qp-init-vaults" class="button button-primary" data-nonce="<?php echo esc_attr($nonce); ?>">Run Sync</button>
				<p id="qp-vault-status" class="status-msg" style="margin-top: 10px; font-weight: 600;"></p>
			</div>
			<div class="card">
				<h2>Sync Mastery Data</h2>
				<p>Re-evaluates Spaced Repetition boxes based on attempt history. (Latest Correct: Box 2, Latest Incorrect: Box 1)</p>
				<button id="qp-sync-mastery" class="button button-primary" data-nonce="<?php echo esc_attr($nonce); ?>">Recalculate</button>
				<p id="qp-mastery-status" class="status-msg" style="margin-top: 10px; font-weight: 600;"></p>
			</div>
			<div class="card">
				<h2>Calculate Subject Mastery</h2>
				<p>Analyzes historical attempts to generate Subject Mastery scores. Applies to subjects with &ge; 50 questions and users with &ge; 20 attempts.</p>
				<button id="qp-sync-subject-mastery" class="button button-primary" data-nonce="<?php echo esc_attr($nonce); ?>">Calculate Mastery</button>
				<p id="qp-subject-mastery-status" class="status-msg" style="margin-top: 10px; font-weight: 600;"></p>
			</div>

			<div class="card">
				<h2>Sync Question Hardness</h2>
				<p>Calculates the 1-10 Auto-Hardness scale for all questions based on global attempt accuracy. (Requires &ge; 10 attempts per question).</p>
				<button id="qp-sync-hardness" class="button button-primary" data-nonce="<?php echo esc_attr($nonce); ?>">Calculate Hardness</button>
				<p id="qp-hardness-status" class="status-msg" style="margin-top: 10px; font-weight: 600;"></p>
			</div>
		</div>
		<script>
			jQuery(document).ready(function($) {
				$('#qp-init-vaults').on('click', function() {
					const $btn = $(this);
					$btn.prop('disabled', true).text('Processing...');
					$.post(ajaxurl, { action: 'qp_initialize_user_vaults', nonce: $btn.data('nonce') }, function(res) {
						$('#qp-vault-status').text(res.data.message);
						$btn.prop('disabled', false).text('Run Sync');
					});
				});
				$('#qp-sync-mastery').on('click', function() {
					const $btn = $(this);
					$btn.prop('disabled', true).text('Processing...');
					$.post(ajaxurl, { action: 'qp_sync_mastery_data', nonce: $btn.data('nonce') }, function(res) {
						$('#qp-mastery-status').text(res.data.message);
						$btn.prop('disabled', false).text('Recalculate');
					});
				});

				$('#qp-sync-subject-mastery').on('click', function() {
					const $btn = $(this);
					$btn.prop('disabled', true).text('Calculating...');
					$.post(ajaxurl, { action: 'qp_sync_subject_mastery_data', nonce: $btn.data('nonce') }, function(res) {
						$('#qp-subject-mastery-status').text(res.data ? res.data.message : 'Error processing request.');
						$btn.prop('disabled', false).text('Calculate Mastery');
					}).fail(function() {
						$('#qp-subject-mastery-status').text('Server error occurred. Check console.');
						$btn.prop('disabled', false).text('Calculate Mastery');
					});
				});
				$('#qp-sync-hardness').on('click', function() {
					const $btn = $(this);
					$btn.prop('disabled', true).text('Calculating...');
					$.post(ajaxurl, { action: 'qp_sync_auto_hardness', nonce: $btn.data('nonce') }, function(res) {
						$('#qp-hardness-status').text(res.data ? res.data.message : 'Error processing request.');
						$btn.prop('disabled', false).text('Calculate Hardness');
					});
				});
			});
		</script>
		<?php
	}
}