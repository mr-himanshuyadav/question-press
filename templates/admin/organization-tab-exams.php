<?php
/**
 * Template for the Admin "Organize" > "Exams" tab.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var stdClass|null $exam_to_edit             The exam term object being edited, or null.
 * @var array       $linked_subjects_for_edit Array of subject term IDs linked to the exam being edited.
 * @var array       $all_subjects             List of all available subject objects (term_id, name).
 * @var array       $exams                    List of all exam term objects (term_id, name).
 * @var array       $subjects_by_exam         Associative array mapping exam_term_id => [subject_name, ...].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="col-container" class="wp-clearfix">
    <div id="col-left">
        <div class="col-wrap">
            <div class="form-wrap">
                <h2><?php echo $exam_to_edit ? esc_html__( 'Edit Exam', 'question-press' ) : esc_html__( 'Add New Exam', 'question-press' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field( 'qp_add_edit_exam_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo $exam_to_edit ? 'qp_update_exam_term' : 'qp_add_exam_term'; ?>">
                    <?php if ( $exam_to_edit ) : ?>
                        <input type="hidden" name="term_id" value="<?php echo esc_attr( $exam_to_edit->term_id ); ?>">
                    <?php endif; ?>
                    
                    <div class="form-field form-required">
                        <label for="exam-name"><?php esc_html_e( 'Exam Name', 'question-press' ); ?></label>
                        <input name="exam_name" id="exam-name" type="text" value="<?php echo $exam_to_edit ? esc_attr( $exam_to_edit->name ) : ''; ?>" size="40" required>
                        <p><?php esc_html_e( 'e.g., UPSC Prelims, NEET, GATE Civil', 'question-press' ); ?></p>
                    </div>
                    
                    <div class="form-field">
                        <label><?php esc_html_e( 'Linked Subjects', 'question-press' ); ?></label>
                        <div class="subjects-checkbox-group" style="padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 200px; overflow-y: auto;">
                        <?php foreach ( $all_subjects as $subject ): 
                            $checked = in_array( $subject->term_id, $linked_subjects_for_edit );
                        ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="linked_subjects[]" value="<?php echo esc_attr( $subject->term_id ); ?>" <?php checked( $checked ); ?>>
                                <?php echo esc_html( $subject->name ); ?>
                            </label>
                        <?php endforeach; ?>
                        </div>
                        <p><?php esc_html_e( 'Select all subjects that are part of this exam.', 'question-press' ); ?></p>
                    </div>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php echo $exam_to_edit ? esc_attr__( 'Update Exam', 'question-press' ) : esc_attr__( 'Add New Exam', 'question-press' ); ?>">
                        <?php if ( $exam_to_edit ) : ?>
                            <a href="admin.php?page=qp-organization&tab=exams" class="button button-secondary"><?php esc_html_e( 'Cancel Edit', 'question-press' ); ?></a>
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
                        <th><?php esc_html_e( 'Exam', 'question-press' ); ?></th>
                        <th><?php esc_html_e( 'Linked Subjects', 'question-press' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'question-press' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $exams ) ) : foreach ( $exams as $exam ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $exam->name ); ?></strong>
                            </td>
                            <td>
                                <?php 
                                    if ( ! empty( $subjects_by_exam[ $exam->term_id ] ) ) {
                                        echo implode( ', ', array_map( 'esc_html', $subjects_by_exam[ $exam->term_id ] ) );
                                    } else {
                                        echo '<em>' . esc_html__( 'None', 'question-press' ) . '</em>';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php
                                    $edit_nonce = wp_create_nonce( 'qp_edit_exam_' . $exam->term_id );
                                    $delete_nonce = wp_create_nonce( 'qp_delete_exam_' . $exam->term_id );
                                    $edit_link = sprintf( '<a href="?page=qp-organization&tab=exams&action=edit&term_id=%s&_wpnonce=%s">Edit</a>', $exam->term_id, $edit_nonce );
                                    $delete_link = sprintf( '<a href="?page=qp-organization&tab=exams&action=delete&term_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $exam->term_id, $delete_nonce );
                                    echo $edit_link . ' | ' . $delete_link;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr class="no-items"><td colspan="3"><?php esc_html_e( 'No exams found.', 'question-press' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>