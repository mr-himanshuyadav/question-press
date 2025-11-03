<?php
/**
 * Template for the Admin User Entitlements & Scope page.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var int         $user_id_searched The User ID being managed/viewed.
 * @var WP_User|false $user_info        The WP_User object for the searched user, or false if not found/searched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use QuestionPress\Admin\Views\Entitlements_List_Table;

// Instantiate the List Table here, only if a user is found
$entitlements_list_table = null;
if ( $user_id_searched > 0 && $user_info ) {
	$entitlements_list_table = new Entitlements_List_Table(); // Use global class for now

	// Pass user_id to prepare_items for filtering
	$_REQUEST['user_id_filter'] = $user_id_searched; // Use a temporary request variable

	// Fetch, prepare, sort, and filter data
	$entitlements_list_table->prepare_items();
}

?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'User Entitlements & Scope', 'question-press' ); ?></h1>
	<?php
	// Display any notices (e.g., after saving scope)
	if ( isset( $_GET['message'] ) && $_GET['message'] === 'scope_updated' ) {
		 echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html__( 'User scope updated successfully.', 'question-press' ) . '</p></div>';
	}
	// Display any general settings errors for this page
	settings_errors( 'qp_entitlements_notices' );
	?>

	<?php // --- User Search Form --- ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom: 2rem;">
		<input type="hidden" name="page" value="qp-user-entitlements" />
		<label for="qp_user_id_search"><strong><?php esc_html_e( 'Enter User ID:', 'question-press' ); ?></strong></label><br>
		<input type="number" id="qp_user_id_search" name="user_id" value="<?php echo esc_attr( $user_id_searched ?: '' ); ?>" min="1" required style="width: 150px;">
		<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Find User & Manage Scope', 'question-press' ); ?>">
		 <?php if ( $user_id_searched > 0 && ! $user_info ) : ?>
			<p style="color: red; display: inline-block; margin-left: 10px;"><?php esc_html_e( 'Error: User ID not found.', 'question-press' ); ?></p>
		 <?php endif; ?>
	</form>

	<hr class="wp-header-end">

	<?php // --- Conditional Display based on user search ---
	if ( $user_id_searched > 0 && $user_info ) :
	?>
		<h2><?php printf( esc_html__( 'Managing Scope & Entitlements for: %s (#%d)', 'question-press' ), esc_html( $user_info->display_name ), $user_id_searched ); ?></h2>

		<?php // --- Scope Management Section --- ?>
		<div id="qp-user-scope-management" style="margin-bottom: 2rem; padding: 1.5rem; background-color: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px;">
			<h3><?php esc_html_e( 'User\'s Subject Scope', 'question-press' ); ?></h3>
			<p><?php esc_html_e( 'Define which subjects this user can access based on Exams or direct Subject assignments. Leave both sections empty to allow access to all subjects.', 'question-press' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php
				global $wpdb;
				$term_table = $wpdb->prefix . 'qp_terms';
				$tax_table = $wpdb->prefix . 'qp_taxonomies';

				// Get Tax IDs
				$exam_tax_id = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'" );
				$subject_tax_id = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'" );

				// Get all available Exams
				$all_exams = [];
				if ( $exam_tax_id ) {
					$all_exams = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d ORDER BY name ASC", $exam_tax_id ) );
				}

				// Get all available top-level Subjects
				$all_subjects = [];
				if ( $subject_tax_id ) {
					$all_subjects = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND name != 'Uncategorized' ORDER BY name ASC", $subject_tax_id ) );
				}

				// Get current user settings from usermeta
				$current_allowed_exams_json = get_user_meta( $user_id_searched, '_qp_allowed_exam_term_ids', true );
				$current_allowed_subjects_json = get_user_meta( $user_id_searched, '_qp_allowed_subject_term_ids', true );

				$current_allowed_exams = json_decode( $current_allowed_exams_json, true );
				$current_allowed_subjects = json_decode( $current_allowed_subjects_json, true );
				if ( ! is_array( $current_allowed_exams ) ) { $current_allowed_exams = []; }
				if ( ! is_array( $current_allowed_subjects ) ) { $current_allowed_subjects = []; }

				wp_nonce_field( 'qp_save_user_scope_nonce', '_qp_scope_nonce' );
				?>
				<input type="hidden" name="action" value="qp_save_user_scope">
				<input type="hidden" name="user_id_to_update" value="<?php echo esc_attr( $user_id_searched ); ?>">

				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								<label><?php esc_html_e( 'Allowed Exams', 'question-press' ); ?></label>
							</th>
							<td>
								<fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #fff;">
									<?php if ( ! empty( $all_exams ) ) : ?>
										<?php foreach ( $all_exams as $exam ) : ?>
											<label style="display: block; margin-bottom: 5px;">
												<input type="checkbox" name="allowed_exams[]" value="<?php echo esc_attr( $exam->term_id ); ?>" <?php checked( in_array( $exam->term_id, $current_allowed_exams ) ); ?>>
												<?php echo esc_html( $exam->name ); ?>
											</label>
										<?php endforeach; ?>
									<?php else : ?>
										<p><em><?php esc_html_e( 'No exams found.', 'question-press' ); ?></em></p>
									<?php endif; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Allows access to all subjects linked to the selected exam(s).', 'question-press' ); ?></p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label><?php esc_html_e( 'Directly Allowed Subjects', 'question-press' ); ?></label>
							</th>
							<td>
								<fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #fff;">
									<?php if ( ! empty( $all_subjects ) ) : ?>
										<?php foreach ( $all_subjects as $subject ) : ?>
											<label style="display: block; margin-bottom: 5px;">
												<input type="checkbox" name="allowed_subjects[]" value="<?php echo esc_attr( $subject->term_id ); ?>" <?php checked( in_array( $subject->term_id, $current_allowed_subjects ) ); ?>>
												<?php echo esc_html( $subject->name ); ?>
											</label>
										<?php endforeach; ?>
									<?php else : ?>
										<p><em><?php esc_html_e( 'No subjects found.', 'question-press' ); ?></em></p>
									<?php endif; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Allows access ONLY to these specific subjects (in addition to subjects from allowed exams).', 'question-press' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Scope Settings', 'question-press' ); ?>">
				</p>
			</form>
		</div>

		<hr> <?php // Separator ?>

		<h3><?php esc_html_e( 'User\'s Entitlement Records', 'question-press' ); ?></h3>
		<?php if ( $entitlements_list_table ) : ?>
			<form method="get">
				<?php // Keep existing page parameters ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ?? 'qp-user-entitlements' ); ?>" />
				 <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id_searched ); ?>" /> <?php // Keep user_id ?>
				<?php
					// Display the list table
					$entitlements_list_table->display();
				?>
			</form>
		<?php endif; ?>

	<?php // --- End conditional display ---
	else :
		 echo '<p>' . esc_html__( 'Please search for a User ID above to manage their scope and view their entitlements.', 'question-press' ) . '</p>';
	endif;
	?>

</div>