<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Utils\Template_Loader;
use QuestionPress\Admin\Backup\Backup_Manager;
use QuestionPress\Admin\Admin_Utils;
use QuestionPress\Database\Terms_DB; // Added for migration logic

/**
 * Handles rendering the "Tools > Backup & Restore" admin page.
 */
class Backup_Restore_Page {

	/**
	 * Renders the "Backup & Restore" admin page.
	 */
	public static function render() {
        // Check for migration trigger before rendering anything
        self::handle_one_time_migration();

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
					'interval' => $interval, 
					'frequency' => $frequency,
					'keep' => $keep,
					'prune_manual' => $prune_manual,
				];
				update_option('qp_auto_backup_schedule', $schedule_data);

				// Reschedule the cron job
				wp_clear_scheduled_hook('qp_scheduled_backup_hook');
				wp_schedule_event(time() + 10, $frequency, 'qp_scheduled_backup_hook');

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
                                Admin_Utils::set_message($msg, 'success');
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

    /**
     * Handles the one-time database migration to denormalize term IDs.
     * This is triggered by a GET request from the Tools page.
     */
    public static function handle_one_time_migration() {
        // 1. Check if our trigger is set
        if (!isset($_GET['qp_run_migration']) || $_GET['qp_run_migration'] !== 'true') {
            return;
        }

        // 2. Security Check: Verify the nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qp_term_migration_nonce')) {
            wp_die('Security check failed. Please go back and try again.');
        }

        // 3. Permissions Check
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to run this script.');
        }

        // 4. Set up Batch Processing
        $batch_size = 500; // Process 500 groups at a time.
        $batch = isset($_GET['batch']) ? absint($_GET['batch']) : 1;
        $offset = ($batch - 1) * $batch_size;

        global $wpdb;
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        // 5. Get the batch of group IDs to process
        $group_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT group_id FROM {$groups_table} LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ));

        if (empty($group_ids)) {
            // No more groups to process. We are done!
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p><strong>QuestionPress Migration Complete:</strong> All existing question and group data has been successfully updated with the new term structure.</p></div>';
            });
            // Remove the migration button (handled via JS in the view)
            echo '<script> if(document.getElementById("qp-migration-tool")) document.getElementById("qp-migration-tool").style.display = "none"; </script>';
            return; // Stop the process
        }

        // Get Taxonomy IDs just once
        $tax_ids = [
            'subject' => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'"),
            'source'  => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'"),
            'exam'    => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'"),
        ];
        
        $processed_count = 0;

        // 6. Loop through each group in this batch
        foreach ($group_ids as $group_id) {
            
            // Find all linked terms for this group
            $linked_terms = $wpdb->get_results($wpdb->prepare(
                "SELECT t.term_id, t.taxonomy_id
                 FROM {$term_table} t
                 JOIN {$rel_table} r ON t.term_id = r.term_id
                 WHERE r.object_id = %d AND r.object_type = 'group'",
                $group_id
            ));

            $specific_subject_id = 0;
            $specific_source_id = 0;
            $exam_id = 0;

            // Find the specific term for each taxonomy
            foreach ($linked_terms as $term) {
                if ($term->taxonomy_id == $tax_ids['subject']) {
                    $specific_subject_id = $term->term_id;
                } elseif ($term->taxonomy_id == $tax_ids['source']) {
                    $specific_source_id = $term->term_id;
                } elseif ($term->taxonomy_id == $tax_ids['exam']) {
                    $exam_id = $term->term_id;
                }
            }

            // 7. Get the full term lineage using Terms_DB
            $subject_lineage = Terms_DB::get_term_lineage_ids($specific_subject_id);
            $source_lineage  = Terms_DB::get_term_lineage_ids($specific_source_id);

            // 8. Prepare the data to save
            $denormalized_data = [
                'primary_subject_term_id'  => $subject_lineage['primary'],
                'specific_subject_term_id' => $subject_lineage['specific'],
                'primary_source_term_id'   => $source_lineage['primary'],
                'specific_source_term_id'  => $source_lineage['specific'],
                'exam_term_id'             => $exam_id > 0 ? $exam_id : null, 
            ];

            // 9. Update the qp_question_groups table
            $wpdb->update(
                $groups_table,
                $denormalized_data,
                ['group_id' => $group_id]
            );

            // 10. Update ALL qp_questions for this group
            $wpdb->update(
                $questions_table,
                $denormalized_data,
                ['group_id' => $group_id]
            );
            
            $processed_count++;
        }

        // 11. Show a "next batch" notice and auto-refresh to the next batch
        $next_batch = $batch + 1;
        $redirect_url = admin_url('admin.php?page=qp-tools&tab=backup&qp_run_migration=true&batch=' . $next_batch . '&_wpnonce=' . $_GET['_wpnonce']);

        // Add a persistent notice
        add_action('admin_notices', [self::class, 'show_migration_progress_notice']);
        
        // Auto-redirect to the next batch using JavaScript
        echo '<div class="wrap"><div class="notice notice-info"><p>Processing batch ' . esc_html($batch) . '... Redirecting...</p></div></div>';
        echo '<script>window.location.href = "' . esc_url_raw($redirect_url) . '";</script>';
        exit; // Stop further page rendering
    }

    /**
     * Displays a persistent "In Progress" notice during migration.
     */
    public static function show_migration_progress_notice() {
        $batch = isset($_GET['batch']) ? absint($_GET['batch']) : 1;
        echo '<div class="notice notice-info"><p><strong>QuestionPress Migration In Progress:</strong> Processing batch ' . esc_html($batch) . '... Please do not close this window. This page will refresh automatically.</p></div>';
    }
}