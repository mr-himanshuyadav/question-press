<?php
/**
 * Template for the OTP verification step.
 *
 * @package QuestionPress/Templates/Frontend/Auth
 *
 * @var string $email The user's email address.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<form id="qp-signup-form-otp" class="qp-signup-form" method="post">
    <input type="hidden" name="action" value="qp_verify_otp">
    <?php wp_nonce_field( 'qp_signup_nonce' ); ?>
    
    <h2><?php esc_html_e( 'Verify Your Email', 'question-press' ); ?></h2>
    <p class="description" style="text-align: center; font-size: 1em; margin-bottom: 1.5rem;">
        <?php printf( esc_html__( 'We sent a 6-digit code to %s. Please enter it below.', 'question-press' ), '<strong>' . esc_html( $email ) . '</strong>' ); ?>
    </p>

    <div class="qp-form-group">
        <label for="qp_reg_otp"><?php esc_html_e( 'Verification Code', 'question-press' ); ?> <span style="color:red">*</span></label>
        <input type="text" name="qp_reg_otp" id="qp_reg_otp" required pattern="\d{6}" maxlength="6" autocomplete="one-time-code" style="text-align: center; font-size: 1.2em; letter-spacing: 0.5em;">
    </div>

    <div class="qp-form-group qp-action-buttons" style="display: flex; flex-direction: row; gap: 10px;">
        <button type="submit" name="qp_signup_submit_back" class="qp-button qp-button-secondary" style="flex: 1;"><?php esc_html_e( 'Back', 'question-press' ); ?></button>
        <input type="submit" name="qp_signup_submit_otp" value="<?php esc_attr_e( 'Verify & Create Account', 'question-press' ); ?>" class="qp-button qp-button-primary" style="flex: 2;">
    </div>

    <div class="qp-resend-otp-wrapper" style="text-align: center; margin-top: 1.5rem;">
        <a href="#" id="qp-resend-otp-link"><?php esc_html_e( 'Resend Code', 'question-press' ); ?></a>
        <span id="qp-resend-otp-message" style="display: none; color: #50575e;"></span>
    </div>
</form>