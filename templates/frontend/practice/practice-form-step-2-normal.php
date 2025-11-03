<?php
/**
 * Template for Step 2: Normal Practice Settings.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var array  $subjects              Array of available subject objects (subject_id, subject_name).
 * @var string $allowed_subjects      'all' or JSON array of allowed subject IDs.
 * @var bool   $multiSelectDisabled   Whether the subject dropdown should be disabled (due to scope restrictions).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

?>
<form id="qp-start-practice-form" method="post" action="">
    <input type="hidden" name="practice_mode" value="normal">
    <input type="hidden" name="question_order" value="incrementing"> <?php // Value might be changed by JS later based on settings ?>

    <h2>Normal Practice Session</h2>

    <div class="qp-form-group">
        <label for="qp_subject_dropdown">Select Subject(s):</label>
        <div class="qp-multi-select-dropdown" id="qp_subject_dropdown">
            <?php if ( empty( $subjects ) && $allowed_subjects !== 'all' ) : ?>
                <p class="qp-no-subjects-message"><?php esc_html_e( 'No subjects are currently available based on your assigned scope. Please contact an administrator.', 'question-press' ); ?></p>
                <button type="button" class="qp-multi-select-button" disabled>-- No Subjects Available --</button>
                <div class="qp-multi-select-list" style="display: none;"></div> <?php // Empty list container ?>
            <?php else : ?>
                <button type="button" class="qp-multi-select-button">-- Please select --</button>
                <div class="qp-multi-select-list">
                    <?php if ( $allowed_subjects === 'all' || ! empty( $subjects ) ) : // Show 'All' only if unrestricted or subjects exist ?>
                        <label><input type="checkbox" name="qp_subject[]" value="all"> All Subjects</label>
                    <?php endif; ?>
                    <?php foreach ( $subjects as $subject ) : ?>
                        <label><input type="checkbox" name="qp_subject[]" value="<?php echo esc_attr( $subject->subject_id ); ?>"> <?php echo esc_html( $subject->subject_name ); ?></label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="qp-form-group" id="qp-topic-group" style="display: none;">
        <label for="qp_topic_dropdown">Select Topic(s):</label>
        <div class="qp-multi-select-dropdown" id="qp_topic_dropdown">
            <button type="button" class="qp-multi-select-button">-- Select subject(s) first --</button>
            <div class="qp-multi-select-list" id="qp_topic_list_container">
                <?php // Options loaded via AJAX ?>
            </div>
        </div>
    </div>

    <div class="qp-form-group qp-checkbox-group">
        <div class="qp-form-group">
            <label for="qp_normal_practice_limit">Number of Questions (Max: <?php echo esc_attr($normal_practice_limit); ?>)</label>
            <input type="number" name="qp_normal_practice_limit" id="qp_normal_practice_limit" value="20" min="1" max="<?php echo esc_attr($normal_practice_limit); ?>" required>
        </div>
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="qp_pyq_only" value="1">
            <span></span>
            PYQ Only
        </label>
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="qp_include_attempted" value="1">
            <span></span>
            Include previously attempted questions
        </label>
    </div>
    <div class="qp-form-group-description">
        <p>Helps you practice questions from your selected exams and subjects.</p>
    </div>

    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="scoring_enabled" id="qp_scoring_enabled_cb">
            <span></span>
            Enable Scoring
        </label>
    </div>

    <div class="qp-form-group qp-marks-group" id="qp-marks-group-wrapper" style="display: none;">
        <div style="width: 48%">
            <label for="qp_marks_correct">Correct Marks:</label>
            <input type="number" name="qp_marks_correct" id="qp_marks_correct" value="4" step="0.01" min="0.01" max="10" required>
        </div>
        <div style="width: 48%">
            <label for="qp_marks_incorrect">Negative Marks:</label>
            <input type="number" name="qp_marks_incorrect" id="qp_marks_incorrect" value="1" step="0.01" min="0" max="10" required>
        </div>
    </div>

    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="qp_timer_enabled" id="qp_timer_enabled_cb">
            <span></span>
            Question Timer
        </label>
        <div id="qp-timer-input-wrapper" style="display: none; margin-top: 15px;">
            <label for="qp_timer_seconds">Time in Seconds:</label>
            <input type="number" name="qp_timer_seconds" id="qp_timer_seconds" value="60" min="10" max="300">
        </div>
    </div>

    <div class="qp-form-group qp-action-buttons">
        <input type="submit" name="qp_start_practice" value="Start Practice" class="qp-button qp-button-primary" <?php if ($multiSelectDisabled) echo 'disabled'; ?>>
    </div>
</form>