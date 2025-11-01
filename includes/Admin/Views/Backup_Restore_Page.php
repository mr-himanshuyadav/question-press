<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Utils\Template_Loader;
use QuestionPress\Admin\Backup\Backup_Manager; // Use our namespaced class

/**
 * Handles rendering the "Tools > Backup & Restore" admin page.
 */
class Backup_Restore_Page {

	/**
	 * Renders the "Backup & Restore" admin page.
	 */
	public static function render() {
		// Get auto-backup schedule
		$schedule = get_option('qp_auto_backup_schedule', false);

		// Get pre-rendered HTML for the backups list
		$backups_html = Backup_Manager::get_local_backups_html();

		// Prepare arguments for the template
		$args = [
			'schedule'     => $schedule,
			'backups_html' => $backups_html,
		];

		// Load and echo the template
		echo Template_Loader::get_html( 'tools-backup-restore', 'admin', $args );
	}
}