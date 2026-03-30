<?php
/**
 * Template for the Single Course View.
 * (Moved from Dashboard.php)
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

?>
<div class="qp-course-structure-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h2><?php echo esc_html( $course_post->post_title ); ?></h2>
    <a href="<?php echo esc_url( $back_url ); ?>" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Courses</a>
</div>

<div class="qp-course-structure-content">
    <?php if ( ! empty( $sections ) ) : ?>
        <?php
        $is_previous_item_complete = true; // First item is always unlocked
        foreach ( $sections as $section ) :
            ?>
            <div class="qp-course-section-card qp-card">
                <div class="qp-card-header">
                    <h3><?php echo esc_html( $section->title ); ?></h3>
                    <?php if ( ! empty( $section->description ) ) : ?>
                        <p style="font-size: 0.9em; color: var(--qp-dashboard-text-light); margin-top: 5px;"><?php echo esc_html( $section->description ); ?></p>
                    <?php endif; ?>
                </div>
                <div class="qp-card-content qp-course-items-list">
                    <?php
                    $items = $items_by_section[ $section->section_id ] ?? [];
                    if ( ! empty( $items ) ) {
                        foreach ( $items as $item ) {
                            $item_progress   = $progress_data[ $item->item_id ] ?? [
                                'status'     => 'not_started',
                                'session_id' => null,
                                'attempt_count' => 0,
                            ];
                            $status          = $item_progress['status'];
                            $session_id_attr = $item_progress['session_id'] ? ' data-session-id="' . esc_attr( $item_progress['session_id'] ) . '"' : '';
                            $attempt_count   = (int) $item_progress['attempt_count'];

                            // --- Progression Logic ---
                            $is_locked = false;
                            if ( $is_progressive && ! $is_previous_item_complete ) {
                                $is_locked = true;
                            }
                            $row_class = $is_locked ? 'qp-item-locked' : '';
                            
                            // --- Button/Icon Logic ---
                            $status_icon  = '';
                            $button_html  = '';

                            if ($is_locked) {
                                $status_icon  = '<span class="dashicons dashicons-lock" style="color: var(--qp-dashboard-text-light);"></span>';
                                $button_html = sprintf(
                                    '<button class="qp-button qp-button-secondary" style="padding: 4px 10px; font-size: 12px;" disabled>%s</button>',
                                    esc_html__('Locked', 'question-press')
                                );
                            } elseif ( $item->content_type !== 'test_series' ) {
                                // Handle non-test items (e.g., lessons)
                                $status_icon  = '<span class="dashicons dashicons-text"></span>';
                                $button_html = sprintf(
                                    '<button class="qp-button qp-button-secondary view-course-content-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                    esc_attr($item->item_id),
                                    esc_html__('View', 'question-press')
                                );
                            } else {
                                // This is a test series item
                                switch ( $status ) {
                                    case 'completed':
                                        $status_icon  = '<span class="dashicons dashicons-yes-alt" style="color: var(--qp-dashboard-success);"></span>';
                                        
                                        $button_html = sprintf(
                                            '<button class="qp-button qp-button-secondary view-test-results-btn" data-item-id="%d" %s style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                            esc_attr($item->item_id),
                                            $session_id_attr,
                                            esc_html__('Review', 'question-press')
                                        );

                                        if ($allow_retakes) {
                                            $can_retake = false;
                                            $retake_text = esc_html__('Retake', 'question-press');

                                            if ($retake_limit === 0) {
                                                $can_retake = true;
                                            } else {
                                                $retakes_left = $retake_limit - $attempt_count;
                                                if ($retakes_left > 0) {
                                                    $can_retake = true;
                                                    $retake_text = sprintf(esc_html__('Retake (%d left)', 'question-press'), $retakes_left);
                                                } else {
                                                    $retake_text = esc_html__('No Retakes Left', 'question-press');
                                                }
                                            }
                                            
                                            $button_html .= sprintf(
                                                '<button class="qp-button qp-button-primary start-course-test-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;" %s>%s</button>',
                                                esc_attr($item->item_id),
                                                $can_retake ? '' : 'disabled',
                                                $retake_text
                                            );
                                        }
                                        break;
                                    case 'in_progress':
                                        $status_icon  = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-warning-dark);"></span>';
                                        
                                        if ( ! empty( $item_progress['session_id'] ) ) {
                                            $resume_url = add_query_arg('session_id', $item_progress['session_id'], $session_page_url);
                                            $button_html = sprintf(
                                                '<a href="%s" class="qp-button qp-button-primary" style="padding: 4px 10px; font-size: 12px; text-decoration: none;">%s</a>',
                                                esc_url($resume_url),
                                                esc_html__('Continue', 'question-press')
                                            );
                                        } else {
                                            $button_html = sprintf(
                                                '<button class="qp-button qp-button-primary start-course-test-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                                esc_attr($item->item_id),
                                                esc_html__('Continue', 'question-press')
                                            );
                                        }
                                        break;
                                    default: // not_started
                                        $status_icon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-border);"></span>';
                                        $button_html = sprintf(
                                            '<button class="qp-button qp-button-primary start-course-test-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                            esc_attr($item->item_id),
                                            esc_html__('Start', 'question-press')
                                        );
                                        break;
                                }
                            }
                            ?>
                            <div class="qp-course-item-row <?php echo esc_attr($row_class); ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--qp-dashboard-border-light);">
                                <span class="qp-course-item-link" style="display: flex; align-items: center; gap: 8px;">
                                    <?php echo $status_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span style="font-weight: 500;"><?php echo esc_html( $item->title ); ?></span>
                                </span>
                                <div class="qp-card-actions">
                                    <?php echo $button_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </div>
                            </div>
                            <?php

                            // Update lock for next iteration
                            if ($is_progressive) {
                                $is_previous_item_complete = ($status === 'completed');
                            }
                        } // end foreach $items
                    } else {
                        echo '<p style="text-align: center; color: var(--qp-dashboard-text-light); font-style: italic;">No items in this section.</p>';
                    }
                    ?>
                </div>
            </div>
            <?php
        endforeach; // end foreach $sections
    else : ?>
        <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">This course has no content yet.</p></div></div>
    <?php endif; ?>
</div>