<?php
/**
 * Template for the Admin "Organize" > "Labels" tab.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var stdClass|null $label_to_edit The term object being edited, or null if adding new.
 * @var array       $edit_meta     Array of meta values for the term being edited ('color', 'description', 'is_default').
 * @var array       $labels        List of all label objects to display in the table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="col-container" class="wp-clearfix">
    <div id="col-left">
        <div class="col-wrap">
            <div class="form-wrap">
                <h2><?php echo $label_to_edit ? esc_html__( 'Edit Label', 'question-press' ) : esc_html__( 'Add New Label', 'question-press' ); ?></h2>
                <form method="post" action="admin.php?page=qp-organization&tab=labels">
                    <?php wp_nonce_field( 'qp_add_edit_label_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo $label_to_edit ? 'update_label' : 'add_label'; ?>">
                    <?php if ( $label_to_edit ) : ?>
                        <input type="hidden" name="term_id" value="<?php echo esc_attr( $label_to_edit->term_id ); ?>">
                    <?php endif; ?>

                    <div class="form-field form-required">
                        <label for="label-name"><?php esc_html_e( 'Name', 'question-press' ); ?></label>
                        <input name="label_name" id="label-name" type="text" value="<?php echo $label_to_edit ? esc_attr( $label_to_edit->name ) : ''; ?>" size="40" required <?php echo ( $label_to_edit && $edit_meta['is_default'] ) ? 'readonly' : ''; ?>>
                        <?php if ( $label_to_edit && $edit_meta['is_default'] ): ?>
                            <p><?php esc_html_e( 'Default label names cannot be changed.', 'question-press' ); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="label-description"><?php esc_html_e( 'Description', 'question-press' ); ?></label>
                        <textarea name="label_description" id="label-description" rows="3" cols="40"><?php echo esc_textarea( $edit_meta['description'] ); ?></textarea>
                    </div>

                    <div class="form-field">
                        <label for="label-color"><?php esc_html_e( 'Color', 'question-press' ); ?></label>
                        <input name="label_color" id="label-color" type="text" value="<?php echo esc_attr( $edit_meta['color'] ); ?>" class="qp-color-picker">
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php echo $label_to_edit ? esc_attr__( 'Update Label', 'question-press' ) : esc_attr__( 'Add New Label', 'question-press' ); ?>">
                        <?php if ( $label_to_edit ) : ?>
                            <a href="admin.php?page=qp-organization&tab=labels" class="button button-secondary"><?php esc_html_e( 'Cancel Edit', 'question-press' ); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
    </div>
    <div id="col-right">
        <div class="col-wrap">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'question-press' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'question-press' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'question-press' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $labels ) ) : foreach ( $labels as $label ) : ?>
                            <tr>
                                <td><span style="padding: 2px 8px; border-radius: 3px; color: #fff; background-color: <?php echo esc_attr( $label->color ); ?>;"><?php echo esc_html( $label->name ); ?></span></td>
                                <td><?php echo esc_html( $label->description ); ?></td>
                                <td>
                                    <?php
                                    $edit_nonce = wp_create_nonce( 'qp_edit_label_' . $label->term_id );
                                    $edit_link = sprintf( '<a href="?page=qp-organization&tab=labels&action=edit&term_id=%s&_wpnonce=%s">Edit</a>', $label->term_id, $edit_nonce );

                                    if ( ! $label->is_default ) {
                                        $delete_nonce = wp_create_nonce( 'qp_delete_label_' . $label->term_id );
                                        $delete_link = sprintf(
                                            '<a href="?page=qp-organization&tab=labels&action=delete&term_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>',
                                            $label->term_id,
                                            $delete_nonce
                                        );
                                        echo $edit_link . ' | ' . $delete_link;
                                    } else {
                                        echo $edit_link;
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach;
                    else : ?>
                        <tr class="no-items">
                            <td colspan="3"><?php esc_html_e( 'No labels found.', 'question-press' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>