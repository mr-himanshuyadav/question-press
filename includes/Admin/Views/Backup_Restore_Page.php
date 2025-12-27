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
					
                    $file = $_FILES['backup_zip_file'];
                    $filename = sanitize_file_name($file['name']);
                    $file_type = wp_check_filetype($filename);
                    
                    // Capture the selected mode
                    $mode = isset($_POST['restore_mode']) ? sanitize_key($_POST['restore_mode']) : 'merge';
                    if (!in_array($mode, ['merge', 'overwrite'])) $mode = 'merge';

                    if ($file_type['ext'] === 'zip') {
                        // Move file to the correct backup directory so Backup_Manager can find it
                        $upload_dir = wp_upload_dir();
                        $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
                        if (!file_exists($backup_dir)) wp_mkdir_p($backup_dir);
                        
                        $target_path = trailingslashit($backup_dir) . $filename;
                        
                        if (move_uploaded_file($file['tmp_name'], $target_path)) {
                            // Perform the restore
                            $result = Backup_Manager::perform_restore($filename, $mode);
                            
                            if ($result['success']) {
                                $msg = 'Restore complete successfully.';
                                if (isset($result['stats'])) {
                                    $msg .= sprintf(' (Questions: %d, Users Processed: %d)', $result['stats']['questions'], ($result['stats']['users_mapped'] + $result['stats']['users_created']));
                                }
                                Admin_Utils::set_message($msg, 'success'); // Green message
                            } else {
                                Admin_Utils::set_message('Restore failed: ' . $result['message'], 'error');
                            }
                        } else {
                            Admin_Utils::set_message('Failed to move uploaded file to backup directory.', 'error');
                        }
                    } else {
                        Admin_Utils::set_message('Invalid file type. Please upload a .zip file.', 'error');
                    }

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