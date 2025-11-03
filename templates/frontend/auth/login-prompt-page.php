<?php
/**
 * Template for the full-page login/signup prompt.
 *
 * @package QuestionPress/Templates/Frontend/Auth
 *
 * @var string $signup_page_url URL to the registration page.
 * @var string $redirect_url    URL to redirect to after login.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="qp-practice-app-wrapper">
    <div class="qp-container qp-auth-prompt-container">
        <div class="qp-auth-form-wrapper">
            <h2><?php esc_html_e( 'Please Log In', 'question-press' ); ?></h2>
            <p><?php esc_html_e( 'You must be logged in to access this content.', 'question-press' ); ?></p>
            
            <?php
            // Display the WordPress login form
            wp_login_form( [
                'redirect'       => $redirect_url,
                'remember'       => true,
                'label_username' => esc_html__( 'Username or Email Address', 'question-press' ),
                'label_log_in'   => esc_html__( 'Log In', 'question-press' ),
            ] );
            ?>
        </div>

        <?php if ( ! empty( $signup_page_url ) ) : ?>
            <div class="qp-auth-signup-prompt">
                <p><?php esc_html_e( "Don't have an account?", 'question-press' ); ?></p>
                <a href="<?php echo esc_url( $signup_page_url ); ?>" class="qp-button qp-button-secondary"><?php esc_html_e( 'Sign Up Now', 'question-press' ); ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>