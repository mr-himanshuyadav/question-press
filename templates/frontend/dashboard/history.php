<?php
/**
 * Template for the Dashboard History Tab content.
 * (Redesigned with Material-style tabs and list layout)
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use QuestionPress\Frontend\Dashboard;

// Helper function to get mode-specific details
if ( ! function_exists( 'qp_get_history_mode_details' ) ) {
    function qp_get_history_mode_details( $session, $settings ) {
        $details = [
            'icon'  => 'dashicons-edit',
            'class' => 'mode-normal',
            'label' => 'Practice',
        ];

        if ( isset( $settings['practice_mode'] ) && $settings['practice_mode'] === 'mock_test' ) {
            if ( isset( $settings['course_id'] ) && $settings['course_id'] > 0 ) {
                $details = [
                    'icon'  => 'dashicons-welcome-learn-more',
                    'class' => 'mode-course-test',
                    'label' => 'Course Test', // Will show "Course / Section / Item"
                ];
            } else {
                $details = [
                    'icon'  => 'dashicons-analytics',
                    'class' => 'mode-mock-test',
                    'label' => Dashboard::get_session_mode_name( $session, $settings ),
                ];
            }
        } elseif ( isset( $settings['practice_mode'] ) ) {
            $mode_map = [
                'revision'                => [ 'icon' => 'dashicons-backup', 'class' => 'mode-revision', 'label' => 'Revision' ],
                'Incorrect Que. Practice' => [ 'icon' => 'dashicons-warning', 'class' => 'mode-incorrect', 'label' => 'Incorrect Practice' ],
                'Section Wise Practice'   => [ 'icon' => 'dashicons-layout', 'class' => 'mode-section-wise', 'label' => 'Section Practice' ],
            ];
            if ( isset( $mode_map[ $settings['practice_mode'] ] ) ) {
                $details = $mode_map[ $settings['practice_mode'] ];
            } else {
                 if ( $settings['practice_mode'] !== 'normal' ) {
                             $details['label'] = $settings['practice_mode'];
                        }
            }
        } elseif ( isset( $settings['subject_id'] ) && $settings['subject_id'] === 'review' ) {
            $details = [
                'icon'  => 'dashicons-book-alt',
                'class' => 'mode-review',
                'label' => 'Review Session',
            ];
        }

        return $details;
    }
}

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

<?php // --- NEW: Material Design Tabs --- ?>
<div class="qp-md-tabs">
    <div class="qp-md-tab-list" role="tablist">
        <button class="qp-md-tab-link active" data-tab="completed-sessions" role="tab" aria-selected="true">
            <?php esc_html_e( 'Completed', 'question-press' ); ?> (<?php echo count( $completed_sessions ); ?>)
        </button>
        <button class="qp-md-tab-link" data-tab="paused-sessions" role="tab" aria-selected="false">
            <?php esc_html_e( 'Active & Paused', 'question-press' ); ?> (<?php echo count( $paused_sessions ); ?>)
        </button>
    </div>
    <div class="qp-md-tab-ink-bar"></div>
</div>

<?php // --- TAB CONTENT: COMPLETED SESSIONS --- ?>
<div id="completed-sessions" class="qp-tab-content active qp-history-list">
    <?php if ( ! empty( $completed_sessions ) ) : ?>
        <?php
        foreach ( $completed_sessions as $session ) :
            $settings = json_decode( $session->settings_snapshot, true );
            $is_scored = isset( $settings['marks_correct'] );
            
            // Get all mode details (icon, class, label)
            $mode_details = qp_get_history_mode_details( $session, $settings );

            // Get result display
            if ( isset( $accuracy_stats[ $session->session_id ] ) && ! $is_scored ) {
                $result_display = $accuracy_stats[ $session->session_id ];
            } else {
                $result_display = Dashboard::get_session_result_display( $session, $settings );
            }

            // Get context (subjects or course)
			if ( $mode_details['class'] === 'mode-course-test' ) {
				// --- NEW: Fetch full course/section/item name ---
				global $wpdb; // Make sure $wpdb is available
				$course_id = absint($settings['course_id']);
				$item_id = absint($settings['item_id']);
				
				$course_title = get_the_title($course_id); // This is correct (it's a post)
				
				// Fetch Item and Section Title
				$items_table = $wpdb->prefix . 'qp_course_items';
				$sections_table = $wpdb->prefix . 'qp_course_sections';
				$item_info = $wpdb->get_row($wpdb->prepare(
					"SELECT i.title AS item_title, s.title AS section_title
					 FROM {$items_table} i
					 LEFT JOIN {$sections_table} s ON i.section_id = s.section_id
					 WHERE i.item_id = %d",
					$item_id
				));

				$item_title = $item_info ? $item_info->item_title : null;
				$section_title = $item_info ? $item_info->section_title : null;

				// Build the new name string
				$name_parts = [];
				if ($course_title) $name_parts[] = esc_html($course_title);
				if ($section_title) $name_parts[] = esc_html($section_title);
				if ($item_title) $name_parts[] = esc_html($item_title);

				if (!empty($name_parts)) {
					$context_display = implode(' / ', $name_parts);
				} else {
					$context_display = ''; // Blank it if we couldn't find names
				}
			} else {
				// --- Original logic for non-course tests ---
				$context_display = Dashboard::get_session_subjects_display( $session, $settings, $lineage_cache_completed, $group_to_topic_map_completed, $question_to_group_map_completed );
			}
            
            // Check for deleted course item
            if ( isset( $settings['course_id'] ) && isset( $settings['item_id'] ) ) {
                if ( ! isset( $existing_course_item_ids[ absint( $settings['item_id'] ) ] ) ) {
                    $context_display .= ' <em style="color:#777; font-size:0.9em;">(Item removed)</em>';
                }
            }
        ?>
            <div class="qp-card qp-history-item-card <?php echo esc_attr( $mode_details['class'] ); ?>">
                <div class="qp-history-item-icon">
                    <span class="dashicons <?php echo esc_attr( $mode_details['icon'] ); ?>"></span>
                </div>
                
                <div class="qp-history-item-main">
                    <div class="qp-history-item-header">
                        <span class="qp-history-item-mode"><?php echo esc_html( $mode_details['label'] ); ?></span>
                        <span class="qp-history-item-result"><?php echo esc_html( $result_display ); ?></span>
                    </div>
                    
                    <div class="qp-history-item-context <?php echo ( $mode_details['class'] === 'mode-course-test' ? 'course-context' : '' ); ?>">
						<?php if ( ! empty( $context_display ) ) : ?>
							
							<?php // --- THIS IS THE FIX: Only show label if NOT a course test --- ?>
							<?php if ( $mode_details['class'] !== 'mode-course-test' ) : ?>
								<span class="context-label">Subjects:</span>
							<?php endif; ?>

							<span class="context-content"><?php echo wp_kses_post( $context_display ); ?></span>
						<?php endif; ?>
					</div>
                    
                    <div class="qp-card-sub-stats">
                        <div class="stat-item correct">
                            <span class="stat-value"><?php echo (int) $session->correct_count; ?></span>
                            <span class="stat-label">Correct</span>
                        </div>
                        <div class="stat-item incorrect">
                            <span class="stat-value"><?php echo (int) $session->incorrect_count; ?></span>
                            <span class="stat-label">Incorrect</span>
                        </div>
                        <div class="stat-item skipped">
                            <span class="stat-value"><?php echo (int) $session->skipped_count + (int) $session->not_viewed_count; ?></span>
                            <span class="stat-label">Skipped</span>
                        </div>
                    </div>
                </div>

                <div class="qp-history-item-actions">
                    <span class="qp-history-item-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $session->start_time ) ) ); ?></span>
                    <?php if ( $can_delete ) : ?>
                        <button class="qp-delete-session-btn" data-session-id="<?php echo esc_attr( $session->session_id ); ?>" title="<?php esc_attr_e( 'Delete', 'question-press' ); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( add_query_arg( 'session_id', $session->session_id, $review_page_url ) ); ?>" class="qp-button qp-button-secondary qp-button-review"><?php esc_html_e( 'Review', 'question-press' ); ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="qp-card qp-no-results-card"><div class="qp-card-content">
            <span class="dashicons dashicons-info-outline"></span>
            <p><?php esc_html_e( 'You have no completed practice sessions yet.', 'question-press' ); ?></p>
        </div></div>
    <?php endif; ?>
</div>

<?php // --- TAB CONTENT: PAUSED SESSIONS --- ?>
<div id="paused-sessions" class="qp-tab-content qp-history-list" style="display: none;">
    <?php if ( ! empty( $paused_sessions ) ) : ?>
        <?php
        foreach ( $paused_sessions as $session ) :
            $settings = json_decode( $session->settings_snapshot, true );
            $mode_details = qp_get_history_mode_details( $session, $settings );
        ?>
            <div class="qp-card qp-history-item-card qp-history-item-paused <?php echo esc_attr( $mode_details['class'] ); ?>">
                <div class="qp-history-item-icon">
                    <span class="dashicons dashicons-controls-pause"></span>
                </div>

                <div class="qp-history-item-main">
                    <div class="qp-history-item-header">
                        <span class="qp-history-item-mode"><?php echo esc_html( $mode_details['label'] ); ?></span>
                    </div>
                    <div class="qp-history-item-context">
                        <span class="context-label">Paused:</span>
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $session->last_activity ) ) ); ?>
                    </div>
                </div>

                <div class="qp-history-item-actions">
                    <?php if ( $can_terminate ) : ?>
                        <button class="qp-button qp-button-danger qp-terminate-session-btn" data-session-id="<?php echo esc_attr( $session->session_id ); ?>">Terminate</button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( add_query_arg( 'session_id', $session->session_id, $session_page_url ) ); ?>" class="qp-button qp-button-primary"><?php echo ( $session->status === 'paused' ? 'Resume' : 'Continue' ); ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="qp-card qp-no-results-card"><div class="qp-card-content">
            <span class="dashicons dashicons-info-outline"></span>
            <p><?php esc_html_e( 'You have no paused sessions.', 'question-press' ); ?></p>
        </div></div>
    <?php endif; ?>
</div>