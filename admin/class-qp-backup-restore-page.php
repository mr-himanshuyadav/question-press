<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

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
        $result = qp_perform_restore($new_filename);

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


    /**
     * Renders the Backup & Restore tab content.
     */
    public static function render()
    {
        // In future steps, we will add logic here to fetch local backups.
        $local_backups = []; // Placeholder for now.
?>
        <style>
            .qp-backups-table th.column-date {
                width: 18%;
            }

            .qp-backups-table th.column-name {
                width: 45%;
            }

            .qp-backups-table th.column-size {
                width: 7%;
            }

            .qp-backups-table th.column-actions {
                width: 30%;
            }

            .qp-backups-table .column-actions .button {
                white-space: nowrap;
                font-weight: 600;
            }
            #qp-local-backups-list .qp-delete-backup-btn{
                border-color: #d63638;
            }
            #qp-local-backups-list td{
                display: table-cell;
                vertical-align: middle;
            }
        </style>
        <?php settings_errors('qp_backup_notices'); ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap" style="margin-bottom: 30px;">
                        <h2>Create New Backup</h2>
                        <p>Click the button below to generate a full backup (Complete Database Backup)</p>
                        <p>
                            <a href="#" class="button button-primary submit" id="qp-create-backup-btn">Create New Backup</a>
                        </p>
                    </div>
                    <hr>
                    <div class="form-wrap">
                        <h2>Restore from Backup</h2>
                        <p>Upload a <code>.zip</code> backup file</p>
                        <p> <strong>Warning:</strong> This action will overwrite existing data.</p>
                        <p><strong>Filename Format: qp(-auto)-backup-YYYY-MM-DD_HH-MM-SS_IST.zip</strong></p>
                        <form id="qp-restore-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=qp-tools&tab=backup_restore')); ?>">
                            <input type="hidden" name="action" value="qp_restore_from_upload">
                            <?php wp_nonce_field('qp_restore_nonce_action', 'qp_restore_nonce_field'); ?>
                            <div class="form-field">
                                <label for="backup_zip_file"></label>
                                <input type="file" name="backup_zip_file" id="backup_zip_file" accept=".zip,application/zip" required>
                            </div>
                            <p class="submit">
                                <input type="submit" class="button button-danger" value="Upload and Restore">
                            </p>
                        </form>
                    </div>
                    <hr>
                    <div class="form-wrap">
                        <h2>Auto Backup Settings</h2>
                        <p>Automatically create a local backup at a scheduled interval. The WordPress cron system requires site visits to trigger events, so schedules may not be exact.</p>
                        <?php $schedule = get_option('qp_auto_backup_schedule', false); ?>
                        <form id="qp-auto-backup-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=qp-tools&tab=backup_restore')); ?>">
                            <input type="hidden" name="action" value="qp_save_auto_backup_settings">
                            <?php wp_nonce_field('qp_auto_backup_nonce_action', 'qp_auto_backup_nonce_field'); ?>

                            <div class="auto-backup-fields" style="display: flex; flex-direction: column; gap: 15px; align-items: flex-start;">
                                <div style="display: flex; gap: 15px; align-items: flex-start;flex-direction:column;">
                                    <div style="display: flex; align-items: center;">
                                        <span style="margin-right: 5px;">Every</span>
                                        <input type="number" name="auto_backup_interval" min="1" value="<?php echo esc_attr($schedule && isset($schedule['interval']) ? $schedule['interval'] : 1); ?>" style="width: 70px;">
                                        <select name="auto_backup_frequency">
                                            <option value="daily" <?php selected($schedule && isset($schedule['frequency']) ? $schedule['frequency'] : '', 'daily'); ?>>Day(s)</option>
                                            <option value="weekly" <?php selected($schedule && isset($schedule['frequency']) ? $schedule['frequency'] : '', 'weekly'); ?>>Week(s)</option>
                                            <option value="monthly" <?php selected($schedule && isset($schedule['frequency']) ? $schedule['frequency'] : '', 'monthly'); ?>>Month(s)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <span>Number of backups to keep:</span>
                                        <input type="number" name="auto_backup_keep" min="1" value="<?php echo esc_attr($schedule && isset($schedule['keep']) ? $schedule['keep'] : 5); ?>" style="width: 70px;">
                                    </div>
                                </div>
                                <div>
                                    <label>
                                        <input type="checkbox" name="auto_backup_prune_manual" value="1" <?php checked($schedule && !empty($schedule['prune_manual'])); ?>>
                                        Also delete manual backups during auto-pruning.
                                    </label>
                                </div>
                            </div>

                            <?php if ($schedule && wp_next_scheduled('qp_scheduled_backup_hook')) : ?>
                                <div class="notice notice-info inline" style="margin-top: 1rem;">
                                    <p><strong>Status:</strong> Active. Next backup is scheduled for <?php echo esc_html(get_date_from_gmt(date('Y-m-d H:i:s', wp_next_scheduled('qp_scheduled_backup_hook')), 'M j, Y, g:i a')); ?>.</p>
                                </div>
                            <?php else: ?>
                                <div class="notice notice-warning inline" style="margin-top: 1rem;">
                                    <p><strong>Status:</strong> Inactive.</p>
                                </div>
                            <?php endif; ?>

                            <p class="submit">
                                <input type="submit" class="button button-primary" id="qp-save-schedule-btn" value="<?php echo $schedule ? 'Update Schedule' : 'Save Schedule'; ?>" <?php if ($schedule) echo 'disabled'; ?>>

                                <button type="button" class="button button-secondary" id="qp-disable-auto-backup-btn" <?php if (!$schedule) echo 'disabled'; ?>>Disable</button>
                            </p>
                        </form>
                        <form id="qp-disable-backup-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=qp-tools&tab=backup_restore')); ?>" style="display: none;">
                            <input type="hidden" name="action" value="qp_disable_auto_backup">
                            <?php wp_nonce_field('qp_auto_backup_nonce_action', 'qp_auto_backup_nonce_field'); ?>
                        </form>
                    </div>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <h3>Backups</h3>
                    <table class="wp-list-table widefat fixed striped qp-backups-table">
                        <thead>
                            <tr>
                                <th class="column-date">Backup Date</th>
                                <th class="column-name">Backup Name</th>
                                <th class="column-size">Size</th>
                                <th class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="qp-local-backups-list">
                            <?php echo qp_get_local_backups_html(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php
    }
}
