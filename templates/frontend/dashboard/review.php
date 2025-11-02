<?php
/**
 * Template for the Dashboard Review Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var int   $never_correct_count Count of questions never answered correctly.
 * @var int   $total_incorrect_count Count of all questions ever answered incorrectly.
 * @var array $review_questions    Array of question objects marked for review.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<h2><?php esc_html_e( 'Review Center', 'question-press' ); ?></h2>

<?php // Practice Mistakes Card ?>
<div class="qp-practice-card qp-card">
    <div class="qp-card-content">
        <h4 id="qp-incorrect-practice-heading"
            data-never-correct-count="<?php echo esc_attr( $never_correct_count ); ?>"
            data-total-incorrect-count="<?php echo esc_attr( $total_incorrect_count ); ?>">
            <?php printf( esc_html__( 'Practice Your Mistakes (%d)', 'question-press' ), (int) $never_correct_count ); ?>
        </h4>
        <p><?php esc_html_e( 'Create a session from questions you have not yet answered correctly, or optionally include all past mistakes.', 'question-press' ); ?></p>
    </div>
    <div class="qp-card-action">
        <button id="qp-start-incorrect-practice-btn" class="qp-button qp-button-primary" <?php disabled( $never_correct_count + $total_incorrect_count, 0 ); ?>>
            <?php esc_html_e( 'Start Practice', 'question-press' ); ?>
        </button>
        <label class="qp-custom-checkbox">
            <input type="checkbox" id="qp-include-all-incorrect-cb" name="include_all_incorrect" value="1">
            <span></span>
            <?php esc_html_e( 'Include all past mistakes', 'question-press' ); ?>
        </label>
    </div>
</div>

<hr class="qp-divider">

<?php // Marked for Review List ?>
<?php if ( ! empty( $review_questions ) ) : ?>
    <div class="qp-review-list-header">
        <h3 style="margin: 0;"><?php printf( esc_html__( 'Marked for Review (%d)', 'question-press' ), count( $review_questions ) ); ?></h3>
        <button id="qp-start-reviewing-btn" class="qp-button qp-button-primary"><?php esc_html_e( 'Start Reviewing All', 'question-press' ); ?></button>
    </div>
    <ul class="qp-review-list">
        <?php foreach ( $review_questions as $index => $q ) : ?>
            <li data-question-id="<?php echo esc_attr( $q->question_id ); ?>">
                <div class="qp-review-list-q-text">
                    <strong><?php printf( esc_html__( 'Q%d:', 'question-press' ), $index + 1 ); ?></strong> <?php echo wp_trim_words( esc_html( strip_tags( $q->question_text ) ), 25, '...' ); ?>
                    <small><?php printf( esc_html__( 'ID: %d | Subject: %s', 'question-press' ), esc_html( $q->question_id ), esc_html( $q->subject_name ?: 'N/A' ) ); ?></small>
                </div>
                <div class="qp-review-list-actions">
                    <button class="qp-review-list-view-btn qp-button qp-button-secondary"><?php esc_html_e( 'View', 'question-press' ); ?></button>
                    <button class="qp-review-list-remove-btn qp-button qp-button-danger"><?php esc_html_e( 'Remove', 'question-press' ); ?></button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else : ?>
    <div class="qp-card"> <?php // Wrap the message in a card for consistency ?>
        <div class="qp-card-content">
            <p style="text-align: center;"><?php esc_html_e( 'You haven\'t marked any questions for review yet.', 'question-press' ); ?></p>
        </div>
    </div>
<?php endif; ?>