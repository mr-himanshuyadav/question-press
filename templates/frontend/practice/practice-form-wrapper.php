<?php
/**
 * Template for the main practice form wrapper (multi-step container).
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var string $dashboard_page_url URL to the user's dashboard.
 * @var string $step_1_html        HTML content for step 1.
 * @var string $step_2_html        HTML content for step 2.
 * @var string $step_3_html        HTML content for step 3.
 * @var string $step_4_html        HTML content for step 4.
 * @var string $step_5_html        HTML content for step 5.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div id="qp-practice-app-wrapper">
    <div class="qp-multi-step-container">

        <div id="qp-step-1" class="qp-form-step active">
            <div class="qp-step-content">
                <?php echo $step_1_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is generated internally ?>
            </div>
        </div>

        <div id="qp-step-2" class="qp-form-step">
            <div class="qp-step-content">
                <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                <?php echo $step_2_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>

        <div id="qp-step-3" class="qp-form-step">
            <div class="qp-step-content">
                <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                <?php echo $step_3_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>

        <div id="qp-step-4" class="qp-form-step">
            <div class="qp-step-content">
                <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                <?php echo $step_4_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>

         <div id="qp-step-5" class="qp-form-step">
            <div class="qp-step-content">
                <button class="qp-back-btn" data-target-step="1">&larr; Back to Mode Selection</button>
                <?php echo $step_5_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>

    </div>
</div>