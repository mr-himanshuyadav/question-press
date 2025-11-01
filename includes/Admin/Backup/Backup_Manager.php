<?php
namespace QuestionPress\Admin\Backup;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Database\DB;
use \ZipArchive;
use \DateTime;
use \Exception;

/**
 * Handles creation, restoration, and management of plugin backups.
 * (Moved from global functions in question-press.php)
 */
class Backup_Manager extends DB {

	/**
	 * Performs the core backup creation process and saves the file locally.
	 *
	 * @param string $type The type of backup ('manual' or 'auto').
	 * @return array An array containing 'success' status and a 'message' or 'filename'.
	 */
	public static function perform_backup($type = 'manual')
	{
		$wpdb = self::$wpdb;
		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
		if (!file_exists($backup_dir)) {
			wp_mkdir_p($backup_dir);
		}

		$tables_to_backup = [
			'qp_question_groups', 'qp_questions', 'qp_options', 'qp_report_reasons',
			'qp_question_reports', 'qp_logs', 'qp_user_sessions', 'qp_session_pauses',
			'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts', 'qp_taxonomies',
			'qp_terms', 'qp_term_meta', 'qp_term_relationships',
		];
		$full_table_names = array_map(fn($table) => $wpdb->prefix . $table, $tables_to_backup);

		$backup_data = [];
		foreach ($full_table_names as $table) {
			$table_name_without_prefix = str_replace($wpdb->prefix, '', $table);
			$backup_data[$table_name_without_prefix] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
		}

		$backup_data['plugin_settings'] = ['qp_settings' => get_option('qp_settings')];

		$image_ids = $wpdb->get_col("SELECT DISTINCT direction_image_id FROM {$wpdb->prefix}qp_question_groups WHERE direction_image_id IS NOT NULL AND direction_image_id > 0");
		$image_map = [];
		$images_to_zip = [];
		if (!empty($image_ids)) {
			foreach ($image_ids as $image_id) {
				$image_path = get_attached_file($image_id);
				if ($image_path && file_exists($image_path)) {
					$image_filename = basename($image_path);
					$image_map[$image_id] = $image_filename; // Map ID to filename
					$images_to_zip[$image_filename] = $image_path; // Store unique paths to zip
				}
			}
		}
		$backup_data['image_map'] = $image_map; // Add the map to the backup data

		$json_data = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$json_filename = 'database.json';
		$temp_json_path = trailingslashit($backup_dir) . $json_filename;
		file_put_contents($temp_json_path, $json_data);

		$prefix = ($type === 'auto') ? 'qp-auto-backup-' : 'qp-backup-';
		$timestamp = current_time('mysql');
		$datetime = new DateTime($timestamp);
		$timezone_abbr = 'IST';
		$backup_filename = $prefix . $datetime->format('Y-m-d_H-i-s') . '_' . $timezone_abbr . '.zip';
		$zip_path = trailingslashit($backup_dir) . $backup_filename;

		$zip = new ZipArchive();
		if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
			return ['success' => false, 'message' => 'Cannot create ZIP archive.'];
		}

		$zip->addFile($temp_json_path, $json_filename);

		if (!empty($images_to_zip)) {
			$zip->addEmptyDir('images');
			foreach ($images_to_zip as $filename => $path) {
				$zip->addFile($path, 'images/' . $filename);
			}
		}

		$zip->close();
		unlink($temp_json_path);
		self::prune_old_backups();

		return ['success' => true, 'filename' => $backup_filename];
	}

	/**
	 * Intelligently prunes old backup files based on saved schedule settings.
	 * Correctly sorts by file modification time and respects pruning rules.
	 */
	public static function prune_old_backups()
	{
		$schedule = get_option('qp_auto_backup_schedule', false);
		if (!$schedule || !isset($schedule['keep'])) {
			return; // No schedule or keep limit set, so do nothing.
		}

		$backups_to_keep = absint($schedule['keep']);
		$prune_manual = !empty($schedule['prune_manual']);

		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
		if (!is_dir($backup_dir)) {
			return;
		}

		$all_files_in_dir = array_diff(scandir($backup_dir), ['..', '.']);

		// Create a detailed list of backup files with their timestamps
		$backup_files_with_time = [];
		foreach ($all_files_in_dir as $file) {
			$is_auto = strpos($file, 'qp-auto-backup-') === 0;
			$is_manual = strpos($file, 'qp-backup-') === 0;

			if ($is_auto || $is_manual) {
				$backup_files_with_time[] = [
					'name' => $file,
					'type' => $is_auto ? 'auto' : 'manual',
					'time' => filemtime(trailingslashit($backup_dir) . $file)
				];
			}
		}

		// Determine which files are candidates for deletion
		$candidate_files = [];
		if ($prune_manual) {
			// If pruning manual, all backups are candidates
			$candidate_files = $backup_files_with_time;
		} else {
			// Otherwise, only auto-backups are candidates
			foreach ($backup_files_with_time as $file_data) {
				if ($file_data['type'] === 'auto') {
					$candidate_files[] = $file_data;
				}
			}
		}

		if (count($candidate_files) <= $backups_to_keep) {
			return; // Nothing to do
		}

		// **CRITICAL FIX:** Sort candidates by their actual file time, oldest first
		usort($candidate_files, function ($a, $b) {
			return $a['time'] <=> $b['time'];
		});

		$backups_to_delete = array_slice($candidate_files, 0, count($candidate_files) - $backups_to_keep);

		foreach ($backups_to_delete as $file_data_to_delete) {
			unlink(trailingslashit($backup_dir) . $file_data_to_delete['name']);
		}
	}

	/**
	 * The function that runs on the scheduled cron event to create a backup.
	 */
	public static function run_scheduled_backup_event()
	{
		self::prune_old_backups();
		self::perform_backup('auto');
	}

	/**
	 * Scans the backup directory and returns the HTML for the local backups table body.
	 *
	 * @return string The HTML for the table rows.
	 */
	public static function get_local_backups_html()
	{
		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
		$backup_url_base = trailingslashit($upload_dir['baseurl']) . 'qp-backups';
		$backups = file_exists($backup_dir) ? array_diff(scandir($backup_dir), ['..', '.']) : [];

		// --- NEW SORTING LOGIC ---
		$sorted_backups = [];
		if (!empty($backups)) {
			$files_with_time = [];
			foreach ($backups as $backup_file) {
				$file_path = trailingslashit($backup_dir) . $backup_file;
				if (is_dir($file_path)) continue;
				$files_with_time[$backup_file] = filemtime($file_path);
			}

			// Sort by time descending, then by name ascending for tie-breaking
			uksort($files_with_time, function ($a, $b) use ($files_with_time) {
				if ($files_with_time[$a] == $files_with_time[$b]) {
					return strcmp($a, $b); // Sort by name if times are identical
				}
				// Primary sort by modification time, descending
				return $files_with_time[$b] <=> $files_with_time[$a];
			});
			$sorted_backups = array_keys($files_with_time);
		}
		// --- END NEW SORTING LOGIC ---

		ob_start();

		if (empty($sorted_backups)) { // Use the new sorted array
			echo '<tr class="no-items"><td class="colspanchange" colspan="4">No local backups found.</td></tr>';
		} else {
			foreach ($sorted_backups as $backup_file) { // Iterate over the new sorted array
				$file_path = trailingslashit($backup_dir) . $backup_file;
				$file_url = trailingslashit($backup_url_base) . $backup_file;

				$file_size = size_format(filesize($file_path));
				$file_timestamp_gmt = filemtime($file_path);
				$file_date = get_date_from_gmt(date('Y-m-d H:i:s', $file_timestamp_gmt), 'M j, Y, g:i a');
		?>
				<tr data-filename="<?php echo esc_attr($backup_file); ?>">
					<td><?php echo esc_html($file_date); ?></td>
					<td>
						<?php if (strpos($backup_file, 'qp-auto-backup-') === 0) : ?>
							<span style="background-color: #dadae0ff; color: #383d42ff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: 600; vertical-align: middle; margin-left: 5px;">AUTO</span>
						<?php else : ?>
							<span style="background-color: #d8e7f2ff; color: #0f82e7ff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: 600; vertical-align: middle; margin-left: 5px;">MANUAL</span>
						<?php endif; ?>
						<?php echo esc_html($backup_file); ?>
					</td>
					<td><?php echo esc_html($file_size); ?></td>
					<td>
						<a href="<?php echo esc_url($file_url); ?>" class="button button-secondary" download>Download</a>
						<button type="button" class="button button-primary qp-restore-btn">Restore</button>
						<button type="button" class="button button-link-delete qp-delete-backup-btn">Delete</button>
					</td>
				</tr>
		<?php
			}
		}
		return ob_get_clean();
	}

	/**
	 * Performs the core backup restore process from a given filename.
	 *
	 * @param string $filename The name of the backup .zip file in the qp-backups directory.
	 * @return array An array containing 'success' status and a 'message' or 'stats'.
	 */
	public static function perform_restore($filename)
	{
		@ini_set('max_execution_time', 300);
		@ini_set('memory_limit', '256M');

		$wpdb = self::$wpdb;
		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
		$file_path = trailingslashit($backup_dir) . $filename;
		$temp_extract_dir = trailingslashit($backup_dir) . 'temp_restore_' . time();

		if (!file_exists($file_path)) {
			return ['success' => false, 'message' => 'Backup file not found on server.'];
		}

		wp_mkdir_p($temp_extract_dir);
		$zip = new ZipArchive();
		if ($zip->open($file_path) !== TRUE) {
			self::delete_dir($temp_extract_dir);
			return ['success' => false, 'message' => 'Failed to open the backup file.'];
		}
		$zip->extractTo($temp_extract_dir);
		$zip->close();

		$json_file_path = trailingslashit($temp_extract_dir) . 'database.json';
		if (!file_exists($json_file_path)) {
			self::delete_dir($temp_extract_dir);
			return ['success' => false, 'message' => 'database.json not found in the backup file.'];
		}

		$backup_data = json_decode(file_get_contents($json_file_path), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			self::delete_dir($temp_extract_dir);
			return ['success' => false, 'message' => 'Invalid JSON in backup file.'];
		}

		// --- Image ID Mapping ---
		$old_to_new_id_map = [];
		$images_dir = trailingslashit($temp_extract_dir) . 'images';
		if (isset($backup_data['image_map']) && is_array($backup_data['image_map']) && file_exists($images_dir)) {
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/media.php');

			foreach ($backup_data['image_map'] as $old_id => $image_filename) {
				$image_path = trailingslashit($images_dir) . $image_filename;
				if (file_exists($image_path)) {
					$existing_attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $wpdb->esc_like($image_filename)));
					
					if ($existing_attachment_id) {
						$new_id = $existing_attachment_id;
					} else {
						$new_id = media_handle_sideload(['name' => $image_filename, 'tmp_name' => $image_path], 0);
					}

					if (!is_wp_error($new_id)) {
						$old_to_new_id_map[$old_id] = $new_id;
					}
				}
			}
		}

		if (isset($backup_data['qp_question_groups']) && !empty($old_to_new_id_map)) {
			foreach ($backup_data['qp_question_groups'] as &$group) {
				if (!empty($group['direction_image_id']) && isset($old_to_new_id_map[$group['direction_image_id']])) {
					$group['direction_image_id'] = $old_to_new_id_map[$group['direction_image_id']];
				}
			}
			unset($group);
		}
		
		// --- Clear Existing Data ---
		$tables_to_clear = [
			'qp_question_groups', 'qp_questions', 'qp_options', 'qp_report_reasons',
			'qp_question_reports', 'qp_logs', 'qp_user_sessions', 'qp_session_pauses',
			'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts', 'qp_taxonomies',
			'qp_terms', 'qp_term_meta', 'qp_term_relationships',
		];
		$wpdb->query('SET FOREIGN_KEY_CHECKS=0');
		foreach ($tables_to_clear as $table) {
			$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$table}");
		}
		$wpdb->query('SET FOREIGN_KEY_CHECKS=1');

		// --- Deduplicate Attempts and Calculate Stats ---
		$duplicates_handled = 0;
		if (!empty($backup_data['qp_user_attempts'])) {
			$original_attempt_count = count($backup_data['qp_user_attempts']);
			$unique_attempts = [];
			foreach ($backup_data['qp_user_attempts'] as $attempt) {
				$key = $attempt['session_id'] . '-' . $attempt['question_id'];
				if (!isset($unique_attempts[$key])) {
					$unique_attempts[$key] = $attempt;
				} else {
					$existing_attempt = $unique_attempts[$key];
					if (!empty($attempt['selected_option_id']) && empty($existing_attempt['selected_option_id'])) {
						$unique_attempts[$key] = $attempt;
					}
				}
			}
			$final_attempts = array_values($unique_attempts);
			$duplicates_handled = $original_attempt_count - count($final_attempts);
			$backup_data['qp_user_attempts'] = $final_attempts;
		}

		// *** THIS IS THE FIX: Calculate stats AFTER data processing ***
		$stats = [
			'questions' => isset($backup_data['qp_questions']) ? count($backup_data['qp_questions']) : 0,
			'options' => isset($backup_data['qp_options']) ? count($backup_data['qp_options']) : 0,
			'sessions' => isset($backup_data['qp_user_sessions']) ? count($backup_data['qp_user_sessions']) : 0,
			'attempts' => isset($backup_data['qp_user_attempts']) ? count($backup_data['qp_user_attempts']) : 0,
			'reports' => isset($backup_data['qp_question_reports']) ? count($backup_data['qp_question_reports']) : 0,
			'duplicates_handled' => $duplicates_handled
		];
		// *** END FIX ***

		// --- Insert Restored Data into Database ---
		$restore_order = [
			'qp_taxonomies', 'qp_terms', 'qp_term_meta', 'qp_term_relationships', 'qp_question_groups', 
			'qp_questions', 'qp_options', 'qp_report_reasons', 'qp_question_reports', 'qp_logs', 
			'qp_user_sessions', 'qp_session_pauses', 'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts'
		];
		foreach ($restore_order as $table_name) {
			if (!empty($backup_data[$table_name])) {
				$rows = $backup_data[$table_name];
				$chunks = array_chunk($rows, 100);
				foreach ($chunks as $chunk) {
					if (empty($chunk)) continue;
					$columns = array_keys($chunk[0]);
					$placeholders = [];
					$values = [];
					foreach ($chunk as $row) {
						$row_placeholders = [];
						foreach ($columns as $column) {
							$row_placeholders[] = '%s';
							$values[] = $row[$column];
						}
						$placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
					}
					$query = "INSERT INTO {$wpdb->prefix}{$table_name} (`" . implode('`, `', $columns) . "`) VALUES " . implode(', ', $placeholders);
					if ($wpdb->query($wpdb->prepare($query, $values)) === false) {
						self::delete_dir($temp_extract_dir);
						return ['success' => false, 'message' => "An error occurred while restoring '{$table_name}'. DB Error: " . $wpdb->last_error];
					}
				}
			}
		}

		if (isset($backup_data['plugin_settings'])) {
			update_option('qp_settings', $backup_data['plugin_settings']['qp_settings']);
		}

		self::delete_dir($temp_extract_dir);
		return ['success' => true, 'stats' => $stats];
	}

	/**
	 * Helper function to recursively delete a directory.
	 *
	 * @param string $dirPath The path to the directory to delete.
	 */
	public static function delete_dir($dirPath)
	{
		if (!is_dir($dirPath)) {
			return;
		}
		if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}
		$files = glob($dirPath . '*', GLOB_MARK);
		foreach ($files as $file) {
			is_dir($file) ? self::delete_dir($file) : unlink($file);
		}
		rmdir($dirPath);
	}
}