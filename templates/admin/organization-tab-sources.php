<?php
/**
 * Template for the Admin "Organize" > "Sources" tab.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var stdClass|null $term_to_edit       The term object being edited, or null if adding new.
 * @var string      $edit_description     The description of the term being edited.
 * @var array       $all_source_terms     List of all source term objects (term_id, name, parent).
 * @var array       $all_subjects         List of all parent subject objects (term_id, name).
 * @var array       $linked_subject_ids   Array of subject term IDs linked to the source being edited.
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
                <h2><?php echo $term_to_edit ? esc_html__( 'Edit Source/Section', 'question-press' ) : esc_html__( 'Add New Source/Section', 'question-press' ); ?></h2>
                <form method="post" action="admin.php?page=qp-organization&tab=sources">
                    <?php wp_nonce_field( 'qp_add_edit_source_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo $term_to_edit ? 'update_term' : 'add_term'; ?>">
                    <?php if ( $term_to_edit ) : ?>
                        <input type="hidden" name="term_id" value="<?php echo esc_attr( $term_to_edit->term_id ); ?>">
                    <?php endif; ?>
                    
                    <div class="form-field form-required">
                        <label for="term-name"><?php esc_html_e( 'Name', 'question-press' ); ?></label>
                        <input name="term_name" id="term-name" type="text" value="<?php echo $term_to_edit ? esc_attr( $term_to_edit->name ) : ''; ?>" size="40" required>
                        <p><?php esc_html_e( 'The name of the source (e.g., a book title) or a section within it (e.g., Chapter 5).', 'question-press' ); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="parent-source"><?php esc_html_e( 'Parent Item', 'question-press' ); ?></label>
                        <select name="parent" id="parent-source">
                            <option value="0">— <?php esc_html_e( 'None (Top-Level Source)', 'question-press' ); ?> —</option>
                            <?php
                                // A simple function to recursively display options
                                if ( ! function_exists( 'qp_source_dropdown_options' ) ) {
                                    function qp_source_dropdown_options( $terms, $parent_id = 0, $level = 0, $selected = 0 ) {
                                        $prefix = str_repeat( '— ', $level );
                                        foreach ( $terms as $term ) {
                                            if ( $term->parent == $parent_id ) {
                                                printf(
                                                    '<option value="%s" %s>%s%s</option>',
                                                    esc_attr( $term->term_id ),
                                                    selected( $selected, $term->term_id, false ),
                                                    $prefix,
                                                    esc_html( $term->name )
                                                );
                                                qp_source_dropdown_options( $terms, $term->term_id, $level + 1, $selected );
                                            }
                                        }
                                    }
                                }
                                qp_source_dropdown_options( $all_source_terms, 0, 0, $term_to_edit ? $term_to_edit->parent : 0 );
                            ?>
                        </select>
                        <p><?php esc_html_e( 'Assign a parent to create a hierarchy. A "Chapter" should have a "Book" as its parent.', 'question-press' ); ?></p>
                    </div>

                    <div class="form-field" id="linked-subjects-field" style="<?php echo ( $term_to_edit && $term_to_edit->parent != 0 ) ? 'display:none;' : ''; ?>">
                        <label for="linked-subjects"><?php esc_html_e( 'Linked Subjects', 'question-press' ); ?></label>
                        <div class="subjects-checkbox-group" style="padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 150px; overflow-y: auto;">
                            <?php foreach ( $all_subjects as $subject ) : ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="linked_subjects[]" value="<?php echo esc_attr( $subject->term_id ); ?>" <?php checked( in_array( $subject->term_id, $linked_subject_ids ) ); ?>>
                                    <?php echo esc_html( $subject->name ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p><?php esc_html_e( 'Select the subjects this source should be available under. This only applies to top-level sources.', 'question-press' ); ?></p>
                    </div>

                    <script>
                        jQuery(document).ready(function($) {
                            $('#parent-source').on('change', function() {
                                if ($(this).val() == '0') {
                                    $('#linked-subjects-field').slideDown();
                                } else {
                                    $('#linked-subjects-field').slideUp();
                                }
                            });
                        });
                    </script>

                    <div class="form-field">
                        <label for="term-description"><?php esc_html_e( 'Description', 'question-press' ); ?></label>
                        <textarea name="term_description" id="term-description" rows="3" cols="40"><?php echo esc_textarea( $edit_description ); ?></textarea>
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php echo $term_to_edit ? esc_attr__( 'Update Item', 'question-press' ) : esc_attr__( 'Add New Item', 'question-press' ); ?>">
                        <?php if ( $term_to_edit ) : ?>
                            <a href="admin.php?page=qp-organization&tab=sources" class="button button-secondary"><?php esc_html_e( 'Cancel Edit', 'question-press' ); ?></a>
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
                <input type="hidden" name="tab" value="sources" />
                <?php
                // Echo the pre-rendered list table HTML
                echo $list_table_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </form>
        </div>
    </div>
</div>