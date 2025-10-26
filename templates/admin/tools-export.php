<?php
/**
 * Template for the Admin Tools > Export page.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var array $subjects Array of subject objects (term_id, name).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Export Questions', 'question-press' ); ?></h1>
    <hr class="wp-header-end">

    <p><?php esc_html_e( 'Select the subjects you wish to export. All questions within the selected subjects will be exported into a single .zip file conforming to schema v2.2.', 'question-press' ); ?></p>

    <form method="post" action="admin.php?page=qp-tools&tab=export">
        <?php wp_nonce_field( 'qp_export_nonce_action', 'qp_export_nonce_field' ); ?>

        <h2><?php esc_html_e( 'Select Subjects', 'question-press' ); ?></h2>
        <fieldset>
            <?php if ( ! empty( $subjects ) ) : ?>
                <?php foreach ( $subjects as $subject ) : ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox" name="subject_ids[]" value="<?php echo esc_attr( $subject->term_id ); ?>">
                        <?php echo esc_html( $subject->name ); ?>
                    </label>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php esc_html_e( 'No subjects found.', 'question-press' ); ?></p>
            <?php endif; ?>
        </fieldset>

        <p class="submit">
            <input type="submit" name="export_questions" class="button button-primary" value="<?php esc_attr_e( 'Export Questions', 'question-press' ); ?>">
        </p>
    </form>
</div>