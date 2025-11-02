<?php
/**
 * Template for Step 4: Mock Test Settings.
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
<form id="qp-start-mock-test-form" method="post" action="">
    <input type="hidden" name="practice_mode" value="mock_test">
    <h2>Mock Test</h2>

    <div class="qp-form-group">
        <label for="qp_subject_dropdown_mock">Select Subject(s):</label>
        <div class="qp-multi-select-dropdown" id="qp_subject_dropdown_mock">
            <?php if ( $multiSelectDisabled ) : ?>
                <p class="qp-no-subjects-message"><?php esc_html_e( 'No subjects are currently available based on your assigned scope. Please contact an administrator.', 'question-press' ); ?></p>
                <button type="button" class="qp-multi-select-button" disabled>-- No Subjects Available --</button>
                <div class="qp-multi-select-list" style="display: none;"></div>
            <?php else : ?>
                <button type="button" class="qp-multi-select-button">-- Please select --</button>
                <div class="qp-multi-select-list">
                    <?php if ( $allowed_subjects === 'all' || ! empty( $subjects ) ) : ?>
                        <label><input type="checkbox" name="mock_subjects[]" value="all"> All Subjects</label> <?php // Corrected name attribute ?>
                    <?php endif; ?>
                    <?php foreach ( $subjects as $subject ) : ?>
                        <label><input type="checkbox" name="mock_subjects[]" value="<?php echo esc_attr( $subject->subject_id ); ?>"> <?php echo esc_html( $subject->subject_name ); ?></label> <?php // Corrected name attribute ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="qp-form-group" id="qp-topic-group-mock" style="display: none;">
        <label for="qp_topic_dropdown_mock">Select Topic(s):</label>
        <div class="qp-multi-select-dropdown" id="qp_topic_dropdown_mock">
            <button type="button" class="qp-multi-select-button">-- Select subject(s) first --</button>
            <div class="qp-multi-select-list" id="qp_topic_list_container_mock">
                <?php // Options loaded via AJAX ?>
            </div>
        </div>
    </div>

    <div class="qp-form-group">
        <label for="qp_mock_num_questions">Number of Questions<span style="color:red">*</span></label>
        <input type="number" name="qp_mock_num_questions" id="qp_mock_num_questions" value="20" min="5" max="200" required>
    </div>

    <div class="qp-form-group">
        <label>Question Distribution</label>
        <div class="qp-mode-selection-group" style="flex-direction: row; gap: 1rem;">
            <label class="qp-mode-radio-label" style="flex: 1;">
                <input type="radio" name="question_distribution" value="random" checked>
                <span class="qp-mode-radio-button" style="font-size: 14px; padding: 10px;">Random</span>
            </label>
            <label class="qp-mode-radio-label" style="flex: 1;">
                <input type="radio" name="question_distribution" value="equal">
                <span class="qp-mode-radio-button" style="font-size: 14px; padding: 10px;">Equal per Topic</span>
            </label>
        </div>
    </div>

    <div class="qp-form-group">
        <label for="qp_mock_timer_minutes">Total Time (in minutes)<span style="color:red">*</span></label>
        <input type="number" name="qp_mock_timer_minutes" id="qp_mock_timer_minutes" value="30" min="1" max="180" required>
    </div>

    <div class="qp-form-group">
        <label class="qp-custom-checkbox">
            <input type="checkbox" name="scoring_enabled" id="qp_mock_scoring_enabled_cb">
            <span></span>
            Enable Scoring
        </label>
    </div>

    <div class="qp-form-group qp-marks-group" id="qp-mock-marks-group-wrapper" style="display: none;">
        <div>
            <label for="qp_mock_marks_correct">Marks for Correct Answer:</label>
            <input type="number" name="qp_marks_correct" id="qp_mock_marks_correct" value="4" step="0.01" min="0.01" max="10" disabled> <?php // Start disabled ?>
        </div>
        <div>
            <label for="qp_mock_marks_incorrect">Penalty for Incorrect Answer:</label>
            <input type="number" name="qp_marks_incorrect" id="qp_mock_marks_incorrect" value="1" step="0.01" min="0" max="10" disabled> <?php // Start disabled ?>
        </div>
    </div>

    <div class="qp-form-group qp-action-buttons">
        <input type="submit" name="qp_start_mock_test" value="Start Mock Test" class="qp-button qp-button-primary" <?php if ($multiSelectDisabled) echo 'disabled'; ?>>
    </div>
</form>