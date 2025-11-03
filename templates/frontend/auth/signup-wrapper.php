<?php
/**
 * Template for the signup form wrapper.
 *
 * @package QuestionPress/Templates/Frontend/Auth
 *
 * @var string $step_html The HTML for the current step.
 * @var array  $errors    An array of error messages to display.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="qp-practice-app-wrapper">
    <div class="qp-container qp-signup-container">

        <?php echo $step_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        
    </div>
</div>