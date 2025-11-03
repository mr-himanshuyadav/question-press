<?php
/**
 * Template for Step 3: Revision Mode Settings.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var array  $subjects              Array of available subject objects (subject_id, subject_name).
 * @var string $allowed_subjects      'all' or JSON array of allowed subject IDs.
 * @var bool   $multiSelectDisabled   Whether the subject dropdown should be disabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<form id="qp-start-revision-form" method="post" action="">
    <input type="hidden" name="practice_mode" value="revision">
    <h2>Revision Mode</h2>

    <div class="qp-form-group">
        <label for="qp_subject_dropdown_revision">Select Subject(s):</label>
        <div class="qp-multi-select-dropdown" id="qp_subject_dropdown_revision">
            <?php if ( $multiSelectDisabled ) : ?>
                <p class="qp-no-subjects-message"><?php esc_html_e( 'No subjects are currently available based on your assigned scope. Please contact an administrator.', 'question-press' ); ?></p>
                <button type="button" class="qp-multi-select-button" disabled>-- No Subjects Available --</button>
                <div class="qp-multi-select-list" style="display: none;"></div>
            <?php else : ?>
                <button type="button" class="qp-multi-select-button">-- Please select --</button>
                <div class="qp-multi-select-list">
                    <?php if ( $allowed_subjects === 'all' || ! empty( $subjects ) ) : ?>
                        <label><input type="checkbox" name="revision_subjects[]" value="all"> All Subjects</label>
                    <?php endif; ?>
                    <?php foreach ( $subjects as $subject ) : ?>
                        <label><input type="checkbox" name="revision_subjects[]" value="<?php echo esc_attr( $subject->subject_id ); ?>"> <?php echo esc_html( $subject->subject_name ); ?></label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="qp-form-group" id="qp-topic-group-revision" style="display: none;">
        <label for="qp_topic_dropdown_revision">Select Topic(s):</label>
        <div class="qp-multi-select-dropdown" id="qp_topic_dropdown_revision">
            <button type="button" class="qp-multi-select-button">-- Select subject(s) first --</button>
            <div class="qp-multi-select-list" id="qp_topic_list_container_revision">
                <?php // Options loaded via AJAX ?>
            </div>
        </div>
    </div>

    <div class="qp-form-group">
        <label for="qp_revision_questions_per_topic">Number of Questions from each Topic<span style="color:red">*</span></label>
        <input type="number" name="qp_revision_questions_per_topic" id="qp_revision_questions_per_topic" value="2" min="1" max="20" required>
    </div>

    <p class="description" style="font-size: 13px; color: #50575e; margin-bottom: 1.5rem;">
        Note: The total number of questions for the session will be capped at your limit of <strong><?php echo esc_attr($normal_practice_limit); ?></strong>.
    </p>

    <div class="qp-form-group qp-checkbox-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="exclude_pyq" value="1" checked>
            <span></span>
            Exclude PYQs
        </label>
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="choose_random" value="1"> <?php // Default to random now for consistency ?>
            <span></span>
            Choose Random Questions
        </label>
    </div>
     <p class="description" style="font-size: 13px; color: #50575e; margin-bottom: 1.5rem;">Revision mode selects questions you haven't seen recently within the chosen topics. Check "Choose Random Questions" to ignore the order and pick randomly from available revision questions.</p>


    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="scoring_enabled" id="qp_revision_scoring_enabled_cb">
            <span></span>
            Enable Scoring
        </label>
    </div>

    <div class="qp-form-group qp-marks-group" id="qp-revision-marks-group-wrapper" style="display: none;">
        <div>
            <label for="qp_revision_marks_correct">Marks for Correct Answer:</label>
            <input type="number" name="qp_marks_correct" id="qp_revision_marks_correct" value="4" step="0.01" min="0.01" max="10" disabled> <?php // Start disabled ?>
        </div>
        <div>
            <label for="qp_revision_marks_incorrect">Penalty for Incorrect Answer:</label>
            <input type="number" name="qp_marks_incorrect" id="qp_revision_marks_incorrect" value="1" step="0.01" min="0" max="10" disabled> <?php // Start disabled ?>
        </div>
    </div>

    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="qp_timer_enabled" class="qp-timer-enabled-cb"> <?php // Re-use class ?>
            <span></span>
            Enable Timer per Question
        </label>
        <div class="qp-timer-input-wrapper" style="display: none; margin-top: 15px;"> <?php // Re-use class ?>
            <label for="qp_revision_timer_seconds">Time in Seconds:</label>
            <input type="number" name="qp_timer_seconds" id="qp_revision_timer_seconds" value="60" min="10" max="300">
        </div>
    </div>

    <div class="qp-form-group qp-action-buttons">
        <input type="submit" name="qp_start_revision" value="Start Revision" class="qp-button qp-button-primary" <?php if ($multiSelectDisabled) echo 'disabled'; ?>>
    </div>
</form>