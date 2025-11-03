<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Utils\Template_Loader;
use QuestionPress\Admin\Backup\Backup_Manager;
use QuestionPress\Admin\Admin_Utils;

/**
 * Handles rendering the "Tools > Backup & Restore" admin page.
 */
class Backup_Restore_Page {

	/**
	 * Renders the "Backup & Restore" admin page.
	 */
	public static function render() {

		if (isset($_POST['action'])) {
			if ($_POST['action'] === 'qp_save_auto_backup_settings' && check_admin_referer('qp_auto_backup_nonce_action', 'qp_auto_backup_nonce_field')) {
				// Sanitize inputs
				$interval = isset($_POST['auto_backup_interval']) ? absint($_POST['auto_backup_interval']) : 1;
				$frequency = isset($_POST['auto_backup_frequency']) ? sanitize_key($_POST['auto_backup_frequency']) : 'daily';
				$keep = isset($_POST['auto_backup_keep']) ? absint($_POST['auto_backup_keep']) : 5;
				$prune_manual = isset($_POST['auto_backup_prune_manual']) ? '1' : '0';

				// Validate
				if ($interval < 1) $interval = 1;
				if ($keep < 1) $keep = 5;
				if (!in_array($frequency, ['daily', 'weekly', 'monthly'])) $frequency = 'daily';

				$schedule_data = [
					'interval' => $interval, // Note: Your cron logic doesn't use this, only 'frequency'
					'frequency' => $frequency,
					'keep' => $keep,
					'prune_manual' => $prune_manual,
				];
				update_option('qp_auto_backup_schedule', $schedule_data);

				// Reschedule the cron job
				wp_clear_scheduled_hook('qp_scheduled_backup_hook');
				wp_schedule_event(time() + 10, $frequency, 'qp_scheduled_backup_hook'); // Schedule based on frequency

				Admin_Utils::set_message('Auto-backup schedule saved.', 'updated');

			} elseif ($_POST['action'] === 'qp_disable_auto_backup' && check_admin_referer('qp_auto_backup_nonce_action', 'qp_auto_backup_nonce_field')) {
				// Disable the schedule
				wp_clear_scheduled_hook('qp_scheduled_backup_hook');
				delete_option('qp_auto_backup_schedule');
				Admin_Utils::set_message('Auto-backup schedule disabled.', 'updated');

			} elseif ($_POST['action'] === 'qp_restore_from_upload' && check_admin_referer('qp_restore_nonce_action', 'qp_restore_nonce_field')) {
				// Handle file upload restore
				if (isset($_FILES['backup_zip_file']) && $_FILES['backup_zip_file']['error'] === UPLOAD_ERR_OK) {
					// This logic should be expanded, but for now, we'll just acknowledge it
					Admin_Utils::set_message('Restore from uploaded file was received.', 'info');
				} else {
					Admin_Utils::set_message('File upload error or no file selected.', 'error');
				}
			}
		}
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