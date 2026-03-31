<?php

/**
 * Template for the Admin User Entitlements & Scope page.
 */

if (! defined('ABSPATH')) {
	exit;
}

use QuestionPress\Admin\Views\Entitlements_List_Table;

$entitlements_list_table = null;
if ($user_id_searched && $user_info) {
	$entitlements_list_table = new Entitlements_List_Table();
	$_REQUEST['user_id_filter'] = $user_info->ID;
	$entitlements_list_table->prepare_items();
}
?>

<style>
	.qp-admin-wrap {
		margin: 20px 20px 0 0;
		max-width: 1100px;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}

	.qp-card {
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 10px;
		box-shadow: 0 2px 5px rgba(0, 0, 0, 0.04);
		margin-bottom: 24px;
	}

	/* Search Bar */
	.qp-search-container {
		display: flex;
		gap: 10px;
		background: #fff;
		padding: 12px;
		border-radius: 10px;
		border: 1px solid #c3c4c7;
		margin-bottom: 24px;
		align-items: center;
	}

	.qp-search-input-wrapper {
		flex-grow: 1;
		position: relative;
	}

	.qp-search-input-wrapper input {
		width: 100%;
		height: 40px;
		padding-left: 35px !important;
		border-radius: 6px !important;
		border: 1px solid #8c8f94 !important;
		margin: 0 !important;
	}

	.qp-search-input-wrapper .dashicons {
		position: absolute;
		left: 10px;
		top: 10px;
		color: #646970;
	}

	/* Identity Section */
	.qp-user-identity {
		display: flex;
		align-items: center;
		gap: 15px;
		margin-bottom: 20px;
	}

	.qp-user-identity img {
		border-radius: 50%;
		box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
	}

	.qp-user-details h2 {
		margin: 0;
		font-size: 20px;
		font-weight: 600;
	}

	.qp-user-meta {
		color: #646970;
		font-size: 13px;
		display: flex;
		gap: 15px;
		margin-top: 2px;
	}

	/* Scope Summary / Pills */
	.qp-scope-section {
		padding: 20px;
	}

	.qp-scope-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		margin-bottom: 15px;
	}

	.qp-scope-title h3 {
		margin: 0;
		font-size: 15px;
		color: #1d2327;
	}

	.qp-scope-title p {
		margin: 2px 0 0;
		font-size: 13px;
		color: #646970;
	}

	.qp-pill-container {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		margin-bottom: 15px;
	}

	.qp-pill {
		background: #f0f6fc;
		color: #2271b1;
		padding: 4px 12px;
		border-radius: 100px;
		font-size: 12px;
		font-weight: 500;
		border: 1px solid #c5d9ed;
	}

	.qp-pill.empty {
		background: #f6f7f7;
		color: #646970;
		border-color: #dcdcde;
		border-style: dashed;
	}

	.qp-pill.all-access {
		background: #edfaef;
		color: #135e23;
		border-color: #b8e6bf;
	}

	/* Collapsible Editor */
	#qp-scope-editor {
		display: none;
		padding-top: 20px;
		border-top: 1px solid #f0f0f1;
		margin-top: 10px;
	}

	.qp-scope-grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 20px;
		margin-bottom: 20px;
	}

	.qp-selection-list {
		border: 1px solid #dcdcde;
		border-radius: 6px;
		height: 220px;
		overflow-y: auto;
		background: #fdfdfd;
	}

	.qp-selection-list label {
		display: block;
		padding: 8px 12px;
		border-bottom: 1px solid #f0f0f1;
		margin: 0 !important;
		cursor: pointer;
		font-size: 13px;
	}

	.qp-selection-list label:hover {
		background: #f0f6fc;
	}

	.qp-edit-toggle {
		font-weight: 500;
		text-decoration: none;
		display: flex;
		align-items: center;
		gap: 4px;
	}

	/* History Table */
	.qp-table-card .qp-card-header {
		padding: 15px 20px;
		border-bottom: 1px solid #f0f0f1;
		font-weight: 600;
	}

	.qp-table-card .wp-list-table {
		border: none;
	}
</style>

<div class="wrap qp-admin-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e('Access Management', 'question-press'); ?></h1>
	<hr class="wp-header-end">

	<?php
	if (isset($_GET['message']) && $_GET['message'] === 'scope_updated') {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Permissions updated successfully.', 'question-press') . '</p></div>';
	}
	?>

	<!-- Unified Search -->
	<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
		<input type="hidden" name="page" value="qp-user-entitlements" />
		<div class="qp-search-container">
			<div class="qp-search-input-wrapper">
				<span class="dashicons dashicons-search"></span>
				<input type="text" name="user_id" placeholder="<?php esc_attr_e('Search ID, Username, or Email...', 'question-press'); ?>" value="<?php echo esc_attr($user_id_searched ?: ''); ?>" required>
			</div>
			<button type="submit" class="button button-primary"><?php esc_html_e('Find User', 'question-press'); ?></button>
		</div>
	</form>

	<?php if ($user_id_searched && $user_info) : ?>

		<!-- Identity -->
		<div class="qp-user-identity">
			<?php echo get_avatar($user_info->ID, 54); ?>
			<div class="qp-user-details">
				<h2><?php echo esc_html($user_info->display_name); ?></h2>
				<div class="qp-user-meta">
					<span><strong>ID:</strong> #<?php echo (int) $user_info->ID; ?></span>
					<span><strong>Username:</strong> @<?php echo esc_html($user_info->user_login); ?></span>
					<span><strong>Email:</strong> <?php echo esc_html($user_info->user_email); ?></span>
				</div>
			</div>
		</div>

		<!-- Optimized Scope Card -->
		<div class="qp-card">
			<div class="qp-scope-section">
				<?php
				global $wpdb;
				$term_table = $wpdb->prefix . 'qp_terms';
				$tax_table = $wpdb->prefix . 'qp_taxonomies';

				$exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");
				$subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

				$all_exams = $exam_tax_id ? $wpdb->get_results("SELECT term_id, name FROM $term_table WHERE taxonomy_id = $exam_tax_id ORDER BY name ASC") : [];
				$all_subjects = $subject_tax_id ? $wpdb->get_results("SELECT term_id, name FROM $term_table WHERE taxonomy_id = $subject_tax_id AND parent = 0 AND name != 'Uncategorized' ORDER BY name ASC") : [];

				$vault = QuestionPress\Utils\Vault_Manager::get_vault($user_info->ID);
				$scope = $vault ? $vault->access_scope : [];

				$current_exams_ids = $scope['exams'] ?? [];
				$current_subs_ids  = $scope['manual_subjects'] ?? [];

				// Logic for summary display
				$is_global_access = (empty($current_exams_ids) && empty($current_subs_ids));
				?>

				<div class="qp-scope-header">
					<div class="qp-scope-title">
						<h3><?php esc_html_e('Current Access Scope', 'question-press'); ?></h3>
						<p><?php esc_html_e('Content this user is permitted to view.', 'question-press'); ?></p>
					</div>
					<a href="#" class="qp-edit-toggle button" onclick="document.getElementById('qp-scope-editor').style.display='block'; this.style.display='none'; return false;">
						<span class="dashicons dashicons-edit"></span> <?php esc_html_e('Edit Access Scope', 'question-press'); ?>
					</a>
				</div>

				<!-- Summary View (Pills) -->
				<div id="qp-scope-summary">
					<?php if ($is_global_access) : ?>
						<div class="qp-pill-container">
							<span class="qp-pill all-access"><?php esc_html_e('Full Access (No restrictions)', 'question-press'); ?></span>
						</div>
					<?php else : ?>
						<div style="margin-bottom: 10px;">
							<small style="color: #8c8f94; text-transform: uppercase; font-weight: 600; font-size: 10px;"><?php esc_html_e('By Exam:', 'question-press'); ?></small>
							<div class="qp-pill-container">
								<?php
								$found_exam = false;
								foreach ($all_exams as $e) {
									if (in_array($e->term_id, $current_exams_ids)) {
										echo '<span class="qp-pill">' . esc_html($e->name) . '</span>';
										$found_exam = true;
									}
								}
								if (!$found_exam) echo '<span class="qp-pill empty">' . esc_html__('None', 'question-press') . '</span>';
								?>
							</div>
						</div>
						<div>
							<small style="color: #8c8f94; text-transform: uppercase; font-weight: 600; font-size: 10px;"><?php esc_html_e('Direct Subjects:', 'question-press'); ?></small>
							<div class="qp-pill-container">
								<?php
								$found_sub = false;
								foreach ($all_subjects as $s) {
									if (in_array($s->term_id, $current_subs_ids)) {
										echo '<span class="qp-pill">' . esc_html($s->name) . '</span>';
										$found_sub = true;
									}
								}
								if (!$found_sub) echo '<span class="qp-pill empty">' . esc_html__('None', 'question-press') . '</span>';
								?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<!-- Hidden Editor -->
				<div id="qp-scope-editor">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('qp_save_user_scope_nonce', '_qp_scope_nonce'); ?>
						<input type="hidden" name="action" value="qp_save_user_scope">
						<input type="hidden" name="user_id_to_update" value="<?php echo esc_attr($user_info->ID); ?>">

						<div class="qp-scope-grid">
							<div>
								<p><strong><?php esc_html_e('Allowed Exams', 'question-press'); ?></strong></p>
								<div class="qp-selection-list">
									<?php foreach ($all_exams as $exam) : ?>
										<label><input type="checkbox" name="allowed_exams[]" value="<?php echo $exam->term_id; ?>" <?php checked(in_array($exam->term_id, $current_exams_ids)); ?>> <?php echo esc_html($exam->name); ?></label>
									<?php endforeach; ?>
								</div>
							</div>
							<div>
								<p><strong>Direct Subjects</strong></p>
								<div class="qp-selection-list">
									<?php foreach ($all_subjects as $subject) : ?>
										<label><input type="checkbox" name="allowed_subjects[]" value="<?php echo $subject->term_id; ?>" <?php checked(in_array($subject->term_id, $current_subs_ids)); ?>> <?php echo esc_html($subject->name); ?></label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
						<div style="background: #fcfcfc; padding: 15px; border-radius: 6px; border: 1px solid #e2e4e7; display: flex; justify-content: space-between; align-items: center;">
							<span class="description"><?php esc_html_e('Uncheck everything to allow access to all content.', 'question-press'); ?></span>
							<div>
								<button type="button" class="button" onclick="location.reload();"><?php esc_html_e('Cancel', 'question-press'); ?></button>
								<button type="submit" class="button button-primary"><?php esc_html_e('Save Scope Changes', 'question-press'); ?></button>
							</div>
						</div>
					</form>
				</div>
			</div>
		</div>

		<!-- History Card -->
		<div class="qp-card qp-table-card">
			<div class="qp-card-header">
				<span class="dashicons dashicons-cart" style="vertical-align: text-bottom; margin-right: 5px;"></span>
				<?php esc_html_e('Entitlement & Purchase History', 'question-press'); ?>
			</div>
			<div class="qp-card-body" style="padding: 0;">
				<?php if ($entitlements_list_table) : ?>
					<form method="get">
						<input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? 'qp-user-entitlements'); ?>" />
						<input type="hidden" name="user_id" value="<?php echo esc_attr($user_info->ID); ?>" />
						<?php $entitlements_list_table->display(); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>

	<?php else : ?>
		<div style="text-align: center; padding: 100px 20px; color: #646970;">
			<span class="dashicons dashicons-id-alt" style="font-size: 50px; width: 50px; height: 50px; opacity: 0.3;"></span>
			<h2 style="margin-top: 20px;"><?php esc_html_e('Search for a User', 'question-press'); ?></h2>
			<p><?php esc_html_e('Enter a name, email, or ID above to view or adjust their content access levels.', 'question-press'); ?></p>
		</div>
	<?php endif; ?>
</div>