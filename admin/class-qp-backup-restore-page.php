<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use QuestionPress\Admin\Backup\Backup_Manager;

class QP_Backup_Restore_Page
{

    /**
     * Handles form submissions for the Backup & Restore page.
     */
    public static function handle_forms()
    {
        // Handle Auto Backup Settings
        if (isset($_POST['action']) && in_array($_POST['action'], ['qp_save_auto_backup_settings', 'qp_disable_auto_backup'])) {
            check_admin_referer('qp_auto_backup_nonce_action', 'qp_auto_backup_nonce_field');

            // Always clear any previously scheduled event first.
            wp_clear_scheduled_hook('qp_scheduled_backup_hook');

            if ($_POST['action'] === 'qp_save_auto_backup_settings') {
                $interval = isset($_POST['auto_backup_interval']) ? absint($_POST['auto_backup_interval']) : 1;
                $frequency = isset($_POST['auto_backup_frequency']) ? sanitize_key($_POST['auto_backup_frequency']) : 'daily';
                $keep = isset($_POST['auto_backup_keep']) ? absint($_POST['auto_backup_keep']) : 5;
                $prune_manual = isset($_POST['auto_backup_prune_manual']) ? 1 : 0;


                // This is a custom cron schedule, not a built-in one.
                $schedule_name = 'every_' . $interval . '_' . $frequency;

                // Note: For simplicity, we are assuming 'daily', 'weekly', 'monthly'.
                // A more complex system would add custom schedules to WordPress.
                // We will handle the interval manually for this implementation.

                $schedule_settings = ['interval' => $interval, 'frequency' => $frequency, 'keep' => $keep, 'prune_manual' => $prune_manual];
                update_option('qp_auto_backup_schedule', $schedule_settings);

                // Schedule the first event to run after the interval passes.
                wp_schedule_event(time(), $frequency, 'qp_scheduled_backup_hook');

                add_settings_error('qp_backup_notices', 'auto_backup_saved', 'Auto backup schedule has been saved and activated.', 'success');
            } elseif ($_POST['action'] === 'qp_disable_auto_backup') {
                delete_option('qp_auto_backup_schedule');
                add_settings_error('qp_backup_notices', 'auto_backup_disabled', 'Auto backup schedule has been disabled.', 'info');
            }
            return;
        }

        if (!isset($_POST['action']) || $_POST['action'] !== 'qp_restore_from_upload') {
            return;
        }


        if (!isset($_POST['qp_restore_nonce_field']) || !wp_verify_nonce($_POST['qp_restore_nonce_field'], 'qp_restore_nonce_action')) {
            wp_die('Security check failed.');
        }

        if (!isset($_FILES['backup_zip_file']) || $_FILES['backup_zip_file']['error'] !== UPLOAD_ERR_OK) {
            add_settings_error('qp_backup_notices', 'restore_error', 'File upload error. Please try again.', 'error');
            return;
        }

        $file = $_FILES['backup_zip_file'];

        if (!in_array($file['type'], ['application/zip', 'application/x-zip-compressed'])) {
            add_settings_error('qp_backup_notices', 'restore_error', 'Invalid file type. Please upload a .zip file.', 'error');
            return;
        }

        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        $new_filename = 'uploaded-' . date('Y-m-d-H-i-s') . '-' . sanitize_file_name($file['name']);
        $new_filepath = trailingslashit($backup_dir) . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $new_filepath)) {
            add_settings_error('qp_backup_notices', 'restore_error', 'Failed to move uploaded file.', 'error');
            return;
        }

        // Now, call the new restore function with the uploaded filename
        $result = Backup_Manager::perform_restore($new_filename);

        // Clean up the uploaded file regardless of success or failure
        if (file_exists($new_filepath)) {
            unlink($new_filepath);
        }

        if ($result['success']) {
            $stats = $result['stats'];
            $message = '<strong>Restore Complete!</strong><br> - Questions: ' . $stats['questions'] . '<br> - Options: ' . $stats['options'] . '<br> - Sessions: ' . $stats['sessions'] . '<br> - Attempts: ' . $stats['attempts'];
            add_settings_error('qp_backup_notices', 'restore_success', $message, 'success');
        } else {
            add_settings_error('qp_backup_notices', 'restore_error', 'Restore failed: ' . $result['message'], 'error');
        }
    }


    public static function render()
    {
        // Display any notices (like restore success/error) at the top
        settings_errors('qp_backup_notices'); 
        
        // Fetch data needed for the template
        $schedule = get_option('qp_auto_backup_schedule', false);
        $backups_html = Backup_Manager::get_local_backups_html(); // Call the helper to get the table rows

        // Prepare arguments for the template
        $args = [
            'schedule'     => $schedule,
            'backups_html' => $backups_html,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'tools-backup-restore', 'admin', $args );
    }
}
