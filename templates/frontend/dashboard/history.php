<?php
/**
 * Template for the Dashboard History Tab content.
 * (Redesigned with Material-style tabs and list layout)
 * (Refactored to use Dashboard_Manager helpers)
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Use the Dashboard_Manager class
use QuestionPress\Utils\Dashboard_Manager;

// --- REMOVED local function qp_get_history_mode_details ---
// It is now in Dashboard_Manager.php

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
            
            // --- UPDATED: Call Dashboard_Manager helper ---
            $mode_details = Dashboard_Manager::qp_get_history_mode_details( $session, $settings );

            // Get result display
            if ( isset( $accuracy_stats[ $session->session_id ] ) && ! $is_scored ) {
                $result_display = $accuracy_stats[ $session->session_id ];
            } else {
                $result_display = Dashboard_Manager::get_session_result_display( $session, $settings );
            }

            // --- UPDATED: Call Dashboard_Manager helper ---
            $context_display = Dashboard_Manager::get_session_context_display(
                $session, 
                $settings, 
                $lineage_cache_completed, 
                $group_to_topic_map_completed, 
                $question_to_group_map_completed,
                $existing_course_item_ids // Pass the pre-fetched IDs
            );
            
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
							
							<?php // This logic remains the same
							if ( $mode_details['class'] !== 'mode-course-test' ) : ?>
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
            
            // --- UPDATED: Call Dashboard_Manager helper ---
            $mode_details = Dashboard_Manager::qp_get_history_mode_details( $session, $settings );
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