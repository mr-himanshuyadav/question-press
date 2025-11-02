<?php
/**
 * Template for the Admin Merge Terms page.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var string $taxonomy_label     Label for the taxonomy being merged (e.g., 'Subject/Topic').
 * @var string $taxonomy_name      Slug for the taxonomy being merged (e.g., 'subject').
 * @var array  $term_ids_to_merge  Array of term IDs being merged.
 * @var array  $terms_to_merge     Array of term objects being merged.
 * @var object $master_term        The term object selected as the default destination.
 * @var string $master_description The description of the master term.
 * @var array  $parent_terms       Array of potential parent term objects for the final item.
 * @var array  $children_by_parent Associative array mapping parent term ID to child term objects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div class="wrap">
	<h1><?php printf( esc_html__( 'Merge %s', 'question-press' ), esc_html( $taxonomy_label ) ); ?></h1>
	<p><?php esc_html_e( 'You are about to merge multiple items. All associated data (like questions linked via groups) will be reassigned to the final destination item.', 'question-press' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field( 'qp_perform_merge_nonce' ); ?>
        <input type="hidden" name="action" value="qp_perform_merge">
		<input type="hidden" name="taxonomy_name" value="<?php echo esc_attr( $taxonomy_name ); ?>">
		<?php foreach ( $term_ids_to_merge as $term_id ) : ?>
			<input type="hidden" name="source_term_ids[]" value="<?php echo esc_attr( $term_id ); ?>">
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Step 1: Choose the Destination Item', 'question-press' ); ?></h2>
		<p><?php esc_html_e( 'Select which item you want to merge the others into. Its details will be used as the default for the final merged item.', 'question-press' ); ?></p>
		<fieldset style="margin-bottom: 2rem; background: #fff; padding: 1rem; border: 1px solid #ccd0d4;">
			<?php foreach ( $terms_to_merge as $index => $term ) : ?>
				<label style="display: block; margin-bottom: 5px; padding: 5px; border-radius: 3px; <?php echo ( $index === 0 ) ? 'background-color: #f0f6fc;' : ''; ?>">
					<input type="radio" name="destination_term_id" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( $index, 0 ); ?>>
					<strong><?php echo esc_html( $term->name ); ?></strong> (ID: <?php echo esc_html( $term->term_id ); ?>)
				</label>
			<?php endforeach; ?>
		</fieldset>

		<h2><?php esc_html_e( 'Step 2: Final Merged Item Details', 'question-press' ); ?></h2>
		<p><?php esc_html_e( 'Review and edit the details for the final merged item below.', 'question-press' ); ?></p>
		<table class="form-table">
			<tbody>
				<tr class="form-field form-required">
					<th scope="row"><label for="term-name"><?php esc_html_e( 'Final Name', 'question-press' ); ?></label></th>
					<td><input name="term_name" id="term-name" type="text" value="<?php echo esc_attr( $master_term->name ); ?>" size="40" required></td>
				</tr>
				<tr class="form-field">
					<th scope="row"><label for="parent-term"><?php esc_html_e( 'Final Parent', 'question-press' ); ?></label></th>
					<td>
						<select name="parent" id="parent-term">
							<option value="0">— <?php esc_html_e( 'None', 'question-press' ); ?> —</option>
							<?php
							// Options will be populated/updated by JS based on destination selection
							?>
						</select>
					</td>
				</tr>
				<tr class="form-field">
					<th scope="row"><label for="term-description"><?php esc_html_e( 'Final Description', 'question-press' ); ?></label></th>
					<td><textarea name="term_description" id="term-description" rows="5" cols="50"><?php echo esc_textarea( $master_description ); ?></textarea></td>
				</tr>
			</tbody>
		</table>

		<h2 style="margin-top: 2rem;"><?php esc_html_e( 'Step 3: Merge Child Items (Optional)', 'question-press' ); ?></h2>
		<p><?php esc_html_e( 'For each child item (e.g., a topic or section), choose where its associated data should be moved. You can merge it into an existing child of the destination, or move it directly to the top-level destination item.', 'question-press' ); ?></p>

		<table class="wp-list-table widefat striped fixed" id="child-merge-table" style="margin-top: 1rem;">
			<thead>
				<tr>
					<th style="width: 40%;"><?php esc_html_e( 'Child Item to Merge', 'question-press' ); ?></th>
					<th style="width: 25%;"><?php esc_html_e( 'Original Parent', 'question-press' ); ?></th>
					<th><?php esc_html_e( 'Merge Into', 'question-press' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php // Rows populated by JS ?>
				<tr><td colspan="3"><?php esc_html_e( 'Select a destination item above to see merge options.', 'question-press' ); ?></td></tr>
			</tbody>
		</table>

		<p class="submit" style="margin-top: 2rem;">
			<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Confirm Merge', 'question-press' ); ?>">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=qp-organization&tab=' . $taxonomy_name . 's' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'question-press' ); ?></a>
		</p>
	</form>
</div>