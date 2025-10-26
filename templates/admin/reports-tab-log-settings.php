<?php
/**
 * Template for the Admin "Reports" > "Log Settings" tab content.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var int         $reason_tax_id      The taxonomy ID for 'report_reason'.
 * @var stdClass|null $term_to_edit       The term object being edited, or null if adding new.
 * @var int         $is_active_for_edit The 'is_active' meta value for the term being edited.
 * @var string      $type_for_edit      The 'type' meta value for the term being edited.
 * @var string      $list_table_html    The pre-rendered HTML for the list table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="col-container" class="wp-clearfix">
    <div id="col-left">
        <div class="col-wrap">
            <div class="form-wrap">
                <h2><?php echo $term_to_edit ? esc_html__( 'Edit Reason', 'question-press' ) : esc_html__( 'Add New Reason', 'question-press' ); ?></h2>
                <form method="post" action="admin.php?page=qp-logs-reports&tab=log_settings">
                    <?php wp_nonce_field( 'qp_add_edit_reason_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo $term_to_edit ? 'update_reason' : 'add_reason'; ?>">
                    <input type="hidden" name="taxonomy_id" value="<?php echo esc_attr( $reason_tax_id ); ?>">
                    <?php if ( $term_to_edit ): ?><input type="hidden" name="term_id" value="<?php echo esc_attr( $term_to_edit->term_id ); ?>"><?php endif; ?>

                    <div class="form-field form-required">
                        <label for="reason_text"><?php esc_html_e( 'Reason Text', 'question-press' ); ?></label>
                        <input name="reason_text" id="reason_text" type="text" value="<?php echo $term_to_edit ? esc_attr( $term_to_edit->name ) : ''; ?>" size="40" required>
                    </div>

                    <div class="form-field">
                        <label><strong><?php esc_html_e( 'Type', 'question-press' ); ?></strong></label>
                        <label style="display: inline-block; margin-right: 15px;">
                            <input name="reason_type" type="radio" value="report" <?php checked( $type_for_edit, 'report' ); ?>>
                            <?php esc_html_e( 'Report (for errors)', 'question-press' ); ?>
                        </label>
                        <label style="display: inline-block;">
                            <input name="reason_type" type="radio" value="suggestion" <?php checked( $type_for_edit, 'suggestion' ); ?>>
                            <?php esc_html_e( 'Suggestion (for improvements)', 'question-press' ); ?>
                        </label>
                    </div>

                    <div class="form-field">
                        <label>
                            <input name="is_active" type="checkbox" value="1" <?php checked( $is_active_for_edit, 1 ); ?>>
                            <?php esc_html_e( 'Active (Users can select this reason)', 'question-press' ); ?>
                        </label>
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php echo $term_to_edit ? esc_attr__( 'Update Reason', 'question-press' ) : esc_attr__( 'Add New Reason', 'question-press' ); ?>">
                        <?php if ( $term_to_edit ): ?><a href="admin.php?page=qp-logs-reports&tab=log_settings" class="button button-secondary"><?php esc_html_e( 'Cancel', 'question-press' ); ?></a><?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
    </div>
    <div id="col-right">
        <div class="col-wrap">
            <?php
            // Echo the pre-rendered list table HTML
            echo $list_table_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </div>
    </div>
</div>