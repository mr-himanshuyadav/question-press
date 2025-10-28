<?php
/**
 * Template for the Admin "Organize" > "Subjects" tab.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var stdClass|null $term_to_edit      The term object being edited, or null if adding new.
 * @var string      $edit_description    The description of the term being edited.
 * @var array       $parent_subjects     List of parent subject objects (term_id, name).
 * @var string      $list_table_html   The pre-rendered HTML for the list table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>

<div id="col-container" class="wp-clearfix">
    <div id="col-left">
        <div class="col-wrap">
            <div class="form-wrap">
                <h2><?php echo $term_to_edit ? esc_html__( 'Edit Subject/Topic', 'question-press' ) : esc_html__( 'Add New Subject/Topic', 'question-press' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field( 'qp_add_edit_subject_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo $term_to_edit ? 'qp_update_subject_term' : 'qp_add_subject_term'; ?>">
                    <?php if ( $term_to_edit ) : ?>
                        <input type="hidden" name="term_id" value="<?php echo esc_attr( $term_to_edit->term_id ); ?>">
                    <?php endif; ?>
                    
                    <div class="form-field form-required">
                        <label for="term-name"><?php esc_html_e( 'Name', 'question-press' ); ?></label>
                        <input name="term_name" id="term-name" type="text" value="<?php echo $term_to_edit ? esc_attr( $term_to_edit->name ) : ''; ?>" size="40" required <?php echo ( $term_to_edit && strtolower( $term_to_edit->name ) === 'uncategorized' ) ? 'readonly' : ''; ?>>
                    </div>

                    <div class="form-field">
                        <label for="parent-subject"><?php esc_html_e( 'Parent Subject', 'question-press' ); ?></label>
                        <select name="parent" id="parent-subject">
                            <option value="0">— <?php esc_html_e( 'None', 'question-press' ); ?> —</option>
                            <?php foreach ( $parent_subjects as $subject ) : ?>
                                <option value="<?php echo esc_attr( $subject->term_id ); ?>" <?php selected( $term_to_edit ? $term_to_edit->parent : 0, $subject->term_id ); ?>>
                                    <?php echo esc_html( $subject->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p><?php esc_html_e( 'Assign a parent term to create a hierarchy. For example, "Optics" would have "Physics" as its parent.', 'question-press' ); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="term-description"><?php esc_html_e( 'Description', 'question-press' ); ?></label>
                        <textarea name="term_description" id="term-description" rows="3" cols="40"><?php echo esc_textarea( $edit_description ); ?></textarea>
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php echo $term_to_edit ? esc_attr__( 'Update Item', 'question-press' ) : esc_attr__( 'Add New Item', 'question-press' ); ?>">
                        <?php if ( $term_to_edit ) : ?>
                            <a href="admin.php?page=qp-organization&tab=subjects" class="button button-secondary"><?php esc_html_e( 'Cancel Edit', 'question-press' ); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
    </div>
    <div id="col-right">
        <div class="col-wrap">
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
                <input type="hidden" name="tab" value="subjects" />
                <?php
                // Echo the pre-rendered list table HTML
                echo $list_table_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </form>
        </div>
    </div>
</div>