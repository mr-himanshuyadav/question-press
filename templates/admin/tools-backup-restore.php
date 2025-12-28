<?php

/**
 * Template for the Admin Tools > Backup & Restore page.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var array|false $schedule     The auto-backup schedule settings, or false if not set.
 * @var string      $backups_html The pre-rendered HTML for the local backups list.
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<style>
    /* Styles specific to this page */
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

    #qp-local-backups-list .qp-delete-backup-btn {
        border-color: #d63638;
    }

    #qp-local-backups-list td {
        display: table-cell;
        vertical-align: middle;
    }

    /* Styles for the Restore Mode selection in the upload form */
    .qp-restore-mode-group {
        background: #fff;
        border: 1px solid #ccd0d4;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 15px;
        max-width: 600px;
    }
    .qp-restore-mode-option {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        margin-bottom: 15px;
        cursor: pointer;
    }
    .qp-restore-mode-option:last-child {
        margin-bottom: 0;
    }
    .qp-restore-mode-option input[type="radio"] {
        margin-top: 3px;
    }
</style>

<div id="col-container" class="wp-clearfix">
    <div id="col-left">
        <div class="col-wrap">

            <?php // --- Migration Tool (Re-added) --- ?>
            <div id="qp-migration-tool" class="notice notice-warning" style="padding: 1rem; border-left-width: 4px; margin-bottom: 30px;">
                <h3 style="margin-top: 0;">Update Database Structure</h3>
                <p>Run this one-time script to update all your existing questions and groups with the new, faster database structure (denormalized term IDs). This is required for your old data to work with the latest filtering and query improvements.</p>
                <p><strong>Warning:</strong> This process may take several minutes if you have many thousands of questions. Please do not close this tab until you see a "Complete" message.</p>
                <?php
                // Note: using tab=backup_restore to match this page's slug
                $migration_url = wp_nonce_url(
                    admin_url('admin.php?page=qp-tools&tab=backup_restore&qp_run_migration=true&batch=1'),
                    'qp_term_migration_nonce'
                );
                ?>
                <a href="<?php echo esc_url($migration_url); ?>" class="button button-primary">Run Data Migration Script</a>
            </div>

            <?php // --- Create New Backup --- 
            ?>
            <div class="form-wrap" style="margin-bottom: 30px;">
                <h2><?php esc_html_e('Create New Backup', 'question-press'); ?></h2>
                <p><?php esc_html_e('Click the button below to generate a full backup (Complete Database Backup)', 'question-press'); ?></p>
                <p>
                    <a href="#" class="button button-primary submit" id="qp-create-backup-btn"><?php esc_html_e('Create New Backup', 'question-press'); ?></a>
                </p>
            </div>

            <hr>

            <?php // --- Restore from Backup --- 
            ?>
            <div class="form-wrap">
                <h2><?php esc_html_e('Restore from Backup', 'question-press'); ?></h2>
                <p><?php esc_html_e('Upload a .zip backup file to restore data.', 'question-press'); ?></p>
                <p><strong><?php esc_html_e('Filename Format: qp(-auto)-backup-YYYY-MM-DD_HH-MM-SS_IST.zip', 'question-press'); ?></strong></p>
                
                <form id="qp-restore-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=qp-tools&tab=backup_restore')); ?>">
                    <input type="hidden" name="action" value="qp_restore_from_upload">
                    <?php wp_nonce_field('qp_restore_nonce_action', 'qp_restore_nonce_field'); ?>
                    
                    <div class="form-field">
                        <label for="backup_zip_file" style="margin-bottom: 10px; display:block;"><strong><?php esc_html_e('Select Backup File', 'question-press'); ?></strong></label>
                        <input type="file" name="backup_zip_file" id="backup_zip_file" accept=".zip,application/zip" required>
                    </div>

                    <div class="form-field">
                        <p style="margin-top: 20px; margin-bottom: 10px;"><strong><?php esc_html_e('Select Restore Mode:', 'question-press'); ?></strong></p>
                        
                        <div class="qp-restore-mode-group">
                            <label class="qp-restore-mode-option">
                                <input type="radio" name="restore_mode" value="merge" checked>
                                <div>
                                    <strong><?php esc_html_e('Merge (Recommended)', 'question-press'); ?></strong><br>
                                    <span class="description" style="margin:0;"><?php esc_html_e('Adds new data from the backup. Existing users, questions, and progress are preserved. Useful for combining data.', 'question-press'); ?></span>
                                </div>
                            </label>
                            
                            <label class="qp-restore-mode-option">
                                <input type="radio" name="restore_mode" value="overwrite">
                                <div>
                                    <strong style="color: #d63638;"><?php esc_html_e('Overwrite (Destructive)', 'question-press'); ?></strong><br>
                                    <span class="description" style="margin:0;"><?php esc_html_e('WARNING: Deletes ALL current Question Press data (questions, users, history) and replaces it exactly with this backup.', 'question-press'); ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-danger" value="<?php esc_attr_e('Upload and Restore', 'question-press'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to proceed with the selected restore mode?', 'question-press'); ?>');">
                    </p>
                </form>
            </div>

            <hr>

            <?php // --- Auto Backup Settings --- 
            ?>
            <div class="form-wrap">
                <h2><?php esc_html_e('Auto Backup Settings', 'question-press'); ?></h2>
                <p><?php esc_html_e('Automatically create a local backup at a scheduled interval. The WordPress cron system requires site visits to trigger events, so schedules may not be exact.', 'question-press'); ?></p>

                <form id="qp-auto-backup-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=qp-tools&tab=backup_restore')); ?>">
                    <input type="hidden" name="action" value="qp_save_auto_backup_settings">
                    <?php wp_nonce_field('qp_auto_backup_nonce_action', 'qp_auto_backup_nonce_field'); ?>

                    <div class="auto-backup-fields" style="display: flex; flex-direction: column; gap: 15px; align-items: flex-start;">
                        <div style="display: flex; gap: 15px; align-items: flex-start;flex-direction:column;">
                            <div style="display: flex; align-items: center;">
                                <span style="margin-right: 5px;"><?php esc_html_e('Every', 'question-press'); ?></span>
                                <input type="number" name="auto_backup_interval" min="1" value="<?php echo esc_attr($schedule && isset($schedule['interval']) ? $schedule['interval'] : 1); ?>" style="width: 70px;">
                                <select name="auto_backup_frequency">
                                    <option value="daily" <?php selected($schedule && isset($schedule['frequency']) ? $schedule['frequency'] : '', 'daily'); ?>><?php esc_html_e('Day(s)', 'question-press'); ?></option>
                                    <option value="weekly" <?php selected($schedule && isset($schedule['frequency']) ? $schedule['frequency'] : '', 'weekly'); ?>><?php esc_html_e('Week(s)', 'question-press'); ?></option>
                                    <option value="monthly" <?php selected($schedule && isset($schedule['frequency']) ? $schedule['frequency'] : '', 'monthly'); ?>><?php esc_html_e('Month(s)', 'question-press'); ?></option>
                                </select>
                            </div>
                            <div>
                                <span><?php esc_html_e('Number of backups to keep:', 'question-press'); ?></span>
                                <input type="number" name="auto_backup_keep" min="1" value="<?php echo esc_attr($schedule && isset($schedule['keep']) ? $schedule['keep'] : 5); ?>" style="width: 70px;">
                            </div>
                        </div>
                        <div>
                            <label>
                                <input type="checkbox" name="auto_backup_prune_manual" value="1" <?php checked($schedule && ! empty($schedule['prune_manual'])); ?>>
                                <?php esc_html_e('Also delete manual backups during auto-pruning.', 'question-press'); ?>
                            </label>
                        </div>
                    </div>

                    <?php if ($schedule && wp_next_scheduled('qp_scheduled_backup_hook')) : ?>
                        <div class="notice notice-info inline" style="margin-top: 1rem;">
                            <p><strong><?php esc_html_e('Status:', 'question-press'); ?></strong> <?php esc_html_e('Active. Next backup is scheduled for', 'question-press'); ?> <?php echo esc_html(get_date_from_gmt(date('Y-m-d H:i:s', wp_next_scheduled('qp_scheduled_backup_hook')), 'M j, Y, g:i a')); ?>.</p>
                        </div>
                    <?php else: ?>
                        <div class="notice notice-warning inline" style="margin-top: 1rem;">
                            <p><strong><?php esc_html_e('Status:', 'question-press'); ?></strong> <?php esc_html_e('Inactive.', 'question-press'); ?></p>
                        </div>
                    <?php endif; ?>

                    <p class="submit">
                        <input type="submit" class="button button-primary" id="qp-save-schedule-btn" value="<?php echo $schedule ? esc_attr__('Update Schedule', 'question-press') : esc_attr__('Save Schedule', 'question-press'); ?>" <?php if ($schedule) echo 'disabled'; ?>>
                        <button type="button" class="button button-secondary" id="qp-disable-auto-backup-btn" <?php if (! $schedule) echo 'disabled'; ?>><?php esc_html_e('Disable', 'question-press'); ?></button>
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
            <h3><?php esc_html_e('Backups', 'question-press'); ?></h3>
            <table class="wp-list-table widefat fixed striped qp-backups-table">
                <thead>
                    <tr>
                        <th class="column-date"><?php esc_html_e('Backup Date', 'question-press'); ?></th>
                        <th class="column-name"><?php esc_html_e('Backup Name', 'question-press'); ?></th>
                        <th class="column-size"><?php esc_html_e('Size', 'question-press'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Actions', 'question-press'); ?></th>
                    </tr>
                </thead>
                <tbody id="qp-local-backups-list">
                    <?php echo $backups_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is pre-rendered 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>