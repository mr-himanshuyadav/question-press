<?php
/**
 * Template for Step 5: Section Wise Practice Settings.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var array  $subjects              Array of available subject objects (subject_id, subject_name).
 * @var string $allowed_subjects      'all' or JSON array of allowed subject IDs.
 * @var bool   $sectionWiseDisabled   Whether the form should be disabled due to scope restrictions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<form id="qp-start-section-wise-form" method="post" action="">
    <input type="hidden" name="practice_mode" value="Section Wise Practice">
    <h2>Section Wise Practice</h2>

    <div class="qp-form-group">
        <label for="qp_section_subject">Select Subject:</label>
        <?php if ( $sectionWiseDisabled ) : ?>
            <p class="qp-no-subjects-message"><?php esc_html_e( 'No subjects are currently available based on your assigned scope. Please contact an administrator.', 'question-press' ); ?></p>
            <select name="qp_section_subject" id="qp_section_subject" disabled style="display: none;"></select> <?php // Hide dropdown ?>
        <?php else : ?>
            <select name="qp_section_subject" id="qp_section_subject">
                <option value="">— Select a Subject —</option>
                <?php foreach ( $subjects as $subject ) : ?>
                    <option value="<?php echo esc_attr( $subject->subject_id ); ?>"><?php echo esc_html( $subject->subject_name ); ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <div id="qp-section-cascading-dropdowns-container" <?php if ($sectionWiseDisabled) echo 'style="display: none;"'; ?>>
        <?php // Container for AJAX-loaded Source/Section dropdowns ?>
    </div>

    <div class="qp-form-group qp-checkbox-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="qp_include_attempted" value="1">
            <span></span>
            Include previously attempted questions
        </label>
    </div>

    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="scoring_enabled" class="qp-scoring-enabled-cb"> <?php // Re-use class ?>
            <span></span>
            Enable Scoring
        </label>
    </div>

    <div class="qp-form-group qp-marks-group" style="display: none;"> <?php // Re-use class ?>
        <div style="width: 48%">
            <label>Correct Marks:</label>
            <input type="number" name="qp_marks_correct" value="4" step="0.01" min="0.01" max="10" required disabled> <?php // Start disabled ?>
        </div>
        <div style="width: 48%">
            <label>Negative Marks:</label>
            <input type="number" name="qp_marks_incorrect" value="1" step="0.01" min="0" max="10" required disabled> <?php // Start disabled ?>
        </div>
    </div>

    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="qp_timer_enabled" class="qp-timer-enabled-cb"> <?php // Re-use class ?>
            <span></span>
            Question Timer
        </label>
        <div class="qp-timer-input-wrapper" style="display: none; margin-top: 15px;"> <?php // Re-use class ?>
            <label>Time in Seconds:</label>
            <input type="number" name="qp_timer_seconds" value="60" min="10" max="300">
        </div>
    </div>

    <div class="qp-form-group qp-action-buttons">
        <input type="submit" name="qp_start_section_practice" value="Start Practice" class="qp-button qp-button-primary" disabled <?php if ($sectionWiseDisabled) echo 'style="display: none;"'; ?>>
    </div>
</form>