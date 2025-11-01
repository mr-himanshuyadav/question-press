<?php
/**
 * Template for the Dashboard History Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var string $practice_page_url    URL for the main practice page.
 * @var bool   $can_delete           Whether the current user can delete history.
 * @var array  $all_sessions         Array of session objects (paused, completed, abandoned).
 * @var string $session_page_url     URL for the session page (resume links).
 * @var string $review_page_url      URL for the review page.
 * @var array  $lineage_cache        Prefetched lineage data.
 * @var array  $group_to_topic_map   Prefetched group->topic map.
 * @var array  $question_to_group_map Prefetched question->group map.
 * @var array  $accuracy_stats       Prefetched accuracy stats keyed by session_id.
 * @var array  $existing_course_item_ids Array flip of existing item IDs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use QuestionPress\Frontend\Dashboard;

?>
<div class="qp-history-header">
    <h2 style="margin:0;"><?php esc_html_e( 'Practice History', 'question-press' ); ?></h2>
    <div class="qp-history-actions">
        <a href="<?php echo esc_url( $practice_page_url ); ?>" class="qp-button qp-button-primary"><?php esc_html_e( 'Start New Practice', 'question-press' ); ?></a>
        <?php if ( $can_delete ) : ?>
            <button id="qp-delete-history-btn" class="qp-button qp-button-danger"><?php esc_html_e( 'Clear History', 'question-press' ); ?></button>
        <?php endif; ?>
    </div>
</div>

<table class="qp-dashboard-table qp-full-history-table">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Date', 'question-press' ); ?></th>
            <th><?php esc_html_e( 'Mode', 'question-press' ); ?></th>
            <th><?php esc_html_e( 'Context', 'question-press' ); ?></th>
            <th><?php esc_html_e( 'Result', 'question-press' ); ?></th>
            <th><?php esc_html_e( 'Status', 'question-press' ); ?></th>
            <th><?php esc_html_e( 'Actions', 'question-press' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if ( ! empty( $all_sessions ) ) : ?>
            <?php foreach ( $all_sessions as $session ) :
                $settings = json_decode( $session->settings_snapshot, true );
                $mode = Dashboard::get_session_mode_name( $session, $settings ); // Use helper
                $subjects_display = Dashboard::get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map ); // Use helper
                $is_scored = isset( $settings['marks_correct'] );

                // Use pre-calculated stats for accuracy, otherwise fallback for scored sessions
                if ( isset( $accuracy_stats[ $session->session_id ] ) && ! $is_scored ) {
                    $result_display = $accuracy_stats[ $session->session_id ];
                } else {
                    $result_display = Dashboard::get_session_result_display( $session, $settings ); // Use helper
                }
                $status_display = ucfirst( $session->status );
                if ( $session->status === 'abandoned' ) $status_display = 'Abandoned';
                if ( $session->end_reason === 'autosubmitted_timer' ) $status_display = 'Auto-Submitted';

                $row_class = $session->status === 'paused' ? 'class="qp-session-paused"' : '';

                // Check if associated course item was deleted
                $context_display = $subjects_display;
                if ( isset( $settings['course_id'] ) && isset( $settings['item_id'] ) ) {
                    if ( ! isset( $existing_course_item_ids[ absint( $settings['item_id'] ) ] ) ) {
                        $context_display .= ' <em style="color:#777; font-size:0.9em;">(Item removed)</em>';
                    }
                }
            ?>
                <tr <?php echo $row_class; ?>>
                    <td data-label="Date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $session->start_time ) ) ); ?></td>
                    <td data-label="Mode"><?php echo esc_html( $mode ); ?></td>
                    <td data-label="Context"><?php echo wp_kses_post( $context_display ); ?></td>
                    <td data-label="Result"><strong><?php echo esc_html( $result_display ); ?></strong></td>
                    <td data-label="Status"><?php echo esc_html( $status_display ); ?></td>
                    <td data-label="Actions" class="qp-actions-cell">
                        <?php if ( $session->status === 'paused' ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'session_id', $session->session_id, $session_page_url ) ); ?>" class="qp-button qp-button-primary"><?php esc_html_e( 'Resume', 'question-press' ); ?></a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'session_id', $session->session_id, $review_page_url ) ); ?>" class="qp-button qp-button-secondary"><?php esc_html_e( 'Review', 'question-press' ); ?></a>
                        <?php endif; ?>
                        <?php if ( $can_delete ) : ?>
                            <button class="qp-delete-session-btn" data-session-id="<?php echo esc_attr( $session->session_id ); ?>"><?php esc_html_e( 'Delete', 'question-press' ); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr>
                <td colspan="6" style="text-align: center; padding: 2rem;"><?php esc_html_e( 'You have no practice sessions yet.', 'question-press' ); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>