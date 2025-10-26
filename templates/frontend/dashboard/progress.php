<?php
/**
 * Template for the Dashboard Progress Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var array $subjects Array of available subject term objects (term_id, name).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<h2><?php esc_html_e( 'Progress Tracker', 'question-press' ); ?></h2>
<p style="text-align: center; font-style: italic; color: var(--qp-dashboard-text-light);">
    <?php esc_html_e( 'Track your completion progress by subject and source.', 'question-press' ); ?>
</p>

<?php // Filter Card ?>
<div class="qp-card">
    <div class="qp-card-content">
        <div class="qp-progress-filters">
            <div class="qp-form-group">
                <label for="qp-progress-subject"><?php esc_html_e( 'Select Subject', 'question-press' ); ?></label>
                <select name="qp-progress-subject" id="qp-progress-subject">
                    <option value="">— <?php esc_html_e( 'Select a Subject', 'question-press' ); ?> —</option>
                    <?php foreach ( $subjects as $subject ) : ?>
                        <option value="<?php echo esc_attr( $subject->term_id ); ?>"><?php echo esc_html( $subject->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="qp-form-group">
                <label for="qp-progress-source"><?php esc_html_e( 'Select Source', 'question-press' ); ?></label>
                <select name="qp-progress-source" id="qp-progress-source" disabled>
                    <option value="">— <?php esc_html_e( 'Select a Subject First', 'question-press' ); ?> —</option>
                </select>
            </div>
            <div class="qp-form-group" style="align-self: flex-end;">
                <label class="qp-custom-checkbox">
                    <input type="checkbox" id="qp-exclude-incorrect-cb" name="exclude_incorrect_attempts" value="1">
                    <span></span>
                    <?php esc_html_e( 'Count Correct Only', 'question-press' ); ?>
                </label>
            </div>
        </div>
    </div>
</div>

<?php // Results Container ?>
<div id="qp-progress-results-container" style="margin-top: 1.5rem;">
    <p style="text-align: center; color: var(--qp-dashboard-text-light);">
        <?php esc_html_e( 'Please select a subject and source to view your progress.', 'question-press' ); ?>
    </p>
    <?php // Results will be loaded here via AJAX ?>
</div>