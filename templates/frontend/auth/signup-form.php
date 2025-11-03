<?php
/**
 * Template for the new single-page signup form.
 *
 * @package QuestionPress/Templates/Frontend/Auth
 *
 * @var array  $errors    An array of error messages to display.
 * @var array  $subjects  Array of available subject terms (term_id, name).
 * @var array  $exams     Array of available exam terms (term_id, name).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="qp-practice-app-wrapper">
    <div class="qp-container qp-signup-container">

        <form id="qp-signup-form" class="qp-signup-form" method="post">
            <input type="hidden" name="action" value="qp_signup_submit">
            <?php wp_nonce_field( 'qp_signup_nonce' ); ?>
            
            <h2><?php esc_html_e( 'Create Your Account', 'question-press' ); ?></h2>

            <?php if ( ! empty( $errors ) ) : ?>
                <div class="qp-error-notice">
                    <?php foreach ( $errors as $error ) : ?>
                        <p><?php echo wp_kses_post( $error ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="qp-form-group">
                <label for="qp_reg_username"><?php esc_html_e( 'Username', 'question-press' ); ?> <span style="color:red">*</span></label>
                <div class="qp-input-with-status">
                    <input type="text" name="qp_reg_username" id="qp_reg_username" value="<?php echo esc_attr( $_POST['qp_reg_username'] ?? '' ); ?>" required>
                    <span class="qp-validation-icon" data-for="qp_reg_username"></span>
                </div>
                <p id="qp_username_error" class="qp-validation-message qp-error"></p>
                <p class="description"><?php esc_html_e( 'This cannot be changed later.', 'question-press' ); ?></p>
            </div>

            <div class="qp-form-group">
                <label for="qp_reg_email"><?php esc_html_e( 'Email Address', 'question-press' ); ?> <span style="color:red">*</span></label>
                <div class="qp-input-with-status">
                    <input type="email" name="qp_reg_email" id="qp_reg_email" value="<?php echo esc_attr( $_POST['qp_reg_email'] ?? '' ); ?>" required>
                    <span class="qp-validation-icon" data-for="qp_reg_email"></span>
                </div>
                <p id="qp_email_error" class="qp-validation-message qp-error"></p>
            </div>
            
            <div class="qp-form-group">
                <label for="qp_reg_display_name"><?php esc_html_e( 'Display Name', 'question-press' ); ?> <span style="color:red">*</span></label>
                <input type="text" name="qp_reg_display_name" id="qp_reg_display_name" value="<?php echo esc_attr( $_POST['qp_reg_display_name'] ?? '' ); ?>" required>
                <p class="description"><?php esc_html_e( 'This name will be shown on your profile.', 'question-press' ); ?></p>
            </div>

            <div class="qp-form-group">
                <label for="qp_reg_password"><?php esc_html_e( 'Password', 'question-press' ); ?> <span style="color:red">*</span></label>
                <input type="password" name="qp_reg_password" id="qp_reg_password" required>
                <p id="qp_password_length_error" class="qp-validation-message qp-error"><?php esc_html_e( 'Password must be at least 8 characters long.', 'question-press' ); ?></p>
            </div>

            <div class="qp-form-group">
                <label for="qp_reg_confirm_password"><?php esc_html_e( 'Confirm Password', 'question-press' ); ?> <span style="color:red">*</span></label>
                <input type="password" name="qp_reg_confirm_password" id="qp_reg_confirm_password" required>
                <p id="qp_password_match_error" class="qp-validation-message qp-error"><?php esc_html_e( 'Passwords do not match.', 'question-press' ); ?></p>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--qp-dashboard-border-light); margin: 2rem 0 1.5rem 0;">
            <h3 style="font-size: 1.2em; margin-bottom: 1rem;"><?php esc_html_e( 'Select Your Practice Scope', 'question-press' ); ?></h3>
            <p class="description"><?php esc_html_e( 'This will set the default subjects and exams you can access.', 'question-press' ); ?></p>
            
            <div class="qp-form-group">
                <label for="qp_reg_exam"><?php esc_html_e( 'Select Exam (Optional)', 'question-press' ); ?></label>
                <select name="qp_reg_exam" id="qp_reg_exam">
                    <option value=""><?php esc_html_e( '— Select an Exam —', 'question-press' ); ?></option>
                    <?php foreach ( $exams as $exam ) : ?>
                        <option value="<?php echo esc_attr( $exam->term_id ); ?>"><?php echo esc_html( $exam->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="qp-form-group">
                <label for="qp_reg_subject"><?php esc_html_e( 'Select Subjects (up to 5)', 'question-press' ); ?></label>
                <select name="qp_reg_subject[]" id="qp_reg_subject" multiple>
                    <?php foreach ( $subjects as $subject ) : ?>
                        <option value="<?php echo esc_attr( $subject->term_id ); ?>"><?php echo esc_html( $subject->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p id="qp_subject_error" class="qp-validation-message qp-error" style="display: none;"></p>
                <p class="description"><?php esc_html_e( 'Select up to 5 subjects. This will be ignored if you select an Exam.', 'question-press' ); ?></p>
            </div>

            <p id="qp_scope_error" class="qp-validation-message qp-error" style="display: block; text-align: center; margin-bottom: 1rem;"><?php esc_html_e( 'Please select an Exam OR at least one Subject.', 'question-press' ); ?></p>

            <div class="qp-form-group qp-action-buttons" style="margin-top: 2rem;">
                <input type="submit" name="qp_signup_submit" value="<?php esc_attr_e( 'Please complete all fields', 'question-press' ); ?>" class="qp-button qp-button-primary" disabled>
            </div>
        </form>
    </div>
</div>