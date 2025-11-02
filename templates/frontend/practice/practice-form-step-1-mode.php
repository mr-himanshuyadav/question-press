<?php
/**
 * Template for Step 1: Mode Selection.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var string $dashboard_page_url URL to the user's dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<h2>Select Practice Mode</h2>
<div class="qp-mode-selection-group">
    <label class="qp-mode-radio-label">
        <input type="radio" name="practice_mode_selection" value="2">
        <span class="qp-mode-radio-button">Normal Practice</span>
    </label>
    <label class="qp-mode-radio-label">
        <input type="radio" name="practice_mode_selection" value="5">
        <span class="qp-mode-radio-button">Section Wise Practice</span>
    </label>
    <label class="qp-mode-radio-label">
        <input type="radio" name="practice_mode_selection" value="3">
        <span class="qp-mode-radio-button">Revision Mode</span>
    </label>
    <label class="qp-mode-radio-label">
        <input type="radio" name="practice_mode_selection" value="4">
        <span class="qp-mode-radio-button">Mock Test</span>
    </label>
</div>

<div class="qp-step-1-footer">
    <button id="qp-step1-next-btn" class="qp-button qp-button-primary" disabled>Next</button>
    <?php if ( $dashboard_page_url ) : ?>
        <a href="<?php echo esc_url( $dashboard_page_url ); ?>" class="qp-button qp-button-secondary">Go to Dashboard</a>
    <?php endif; ?>
</div>