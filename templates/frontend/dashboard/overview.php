<?php
/**
 * Template for the Dashboard Overview Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var object $stats                Stats object (total_attempted, total_correct, total_incorrect).
 * @var float  $overall_accuracy     Calculated overall accuracy percentage.
 * @var array  $active_sessions      Array of active/paused session objects.
 * @var array  $recent_history       Array of recent completed/abandoned session objects.
 * @var int    $review_count         Count of questions marked for review.
 * @var int    $never_correct_count  Count of questions never answered correctly.
 * @var string $practice_page_url    URL for the main practice page.
 * @var string $session_page_url     URL for the session page.
 * @var string $review_page_url      URL for the review page.
 * @var array  $lineage_cache        Prefetched lineage data.
 * @var array  $group_to_topic_map   Prefetched group->topic map.
 * @var array  $question_to_group_map Prefetched question->group map.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use QuestionPress\Frontend\Dashboard;

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
                    'label' => 'Course Test',
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

// Ensure stats object exists and has defaults if necessary
$stats             = $stats ?? (object) [ 'total_attempted' => 0, 'total_correct' => 0, 'total_incorrect' => 0 ];
$overall_accuracy  = $overall_accuracy ?? 0;
$active_sessions   = $active_sessions ?? [];
$recent_history    = $recent_history ?? [];
$review_count      = $review_count ?? 0;
$never_correct_count = $never_correct_count ?? 0;

?>
<div class="qp-card">
	<div class="qp-card-header">
		<h3>Lifetime Stats</h3>
	</div>
	<div class="qp-card-content">
		<div class="qp-overall-stats">
			<div class="stat-item">
				<span class="stat-label">Accuracy</span>
				<span class="stat-value"><?php echo round( $overall_accuracy, 1 ); ?>%</span>
			</div>
			<div class="stat-item">
				<span class="stat-label">Attempted</span>
				<span class="stat-value"><?php echo (int) $stats->total_attempted; ?></span>
			</div>
			<div class="stat-item">
				<span class="stat-label">Correct</span>
				<span class="stat-value"><?php echo (int) $stats->total_correct; ?></span>
			</div>
			<div class="stat-item">
				<span class="stat-label">Incorrect</span>
				<span class="stat-value"><?php echo (int) $stats->total_incorrect; ?></span>
			</div>
		</div>
	</div>
</div>

<div class="qp-card">
	<div class="qp-card-header">
		<h3>Quick Actions</h3>
	</div>
	<div class="qp-card-content qp-quick-actions">
		<a href="<?php echo esc_url( $practice_page_url ); ?>" class="qp-button qp-button-primary">
			<span class="dashicons dashicons-edit"></span> Start New Practice
		</a>
		<button id="qp-start-incorrect-practice-btn" class="qp-button qp-button-secondary" <?php disabled( $never_correct_count, 0 ); ?>>
			<span class="dashicons dashicons-warning"></span> Practice Mistakes (<?php echo (int) $never_correct_count; ?>)
		</button>
		<button id="qp-start-reviewing-btn" class="qp-button qp-button-secondary" <?php disabled( $review_count, 0 ); ?>>
			<span class="dashicons dashicons-star-filled"></span> Review Marked (<?php echo (int) $review_count; ?>)
		</button>
	</div>
	<?php // Hidden checkbox for practice mistakes mode ?>
	<div style="display: none;">
		<label class="qp-custom-checkbox">
			<input type="checkbox" id="qp-include-all-incorrect-cb" name="include_all_incorrect" value="1">
			<span></span> Include all past mistakes
		</label>
	</div>
</div>

<?php if ( ! empty( $active_sessions ) ) : ?>
	<div class="qp-card">
		<div class="qp-card-header">
			<h3>Active / Paused Sessions</h3>
		</div>
		<div class="qp-card-content">
			<div class="qp-active-sessions-list">
				<?php
				foreach ( $active_sessions as $session ) {
					$settings    = json_decode( $session->settings_snapshot, true );
					$mode        = Dashboard::get_session_mode_name( $session, $settings ); // Use helper
					$row_class   = $session->status === 'paused' ? 'qp-session-paused-card' : ''; // Add class for paused
					?>
					<div class="qp-active-session-card <?php echo esc_attr( $row_class ); ?>">
						<div class="qp-card-details">
							<span class="qp-card-subject"><?php echo esc_html( $mode ); ?></span>
							<span class="qp-card-date">Started: <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $session->start_time ) ) ); ?></span>
						</div>
						<div class="qp-card-actions">
							<?php // Show button ONLY if setting is enabled AND it's NOT a Section Practice or Mock Test
							if ( $allow_termination && $mode !== 'Section Practice' && $mode !== 'Mock Test' ) : ?>
								<button class="qp-button qp-button-danger qp-terminate-session-btn" data-session-id="<?php echo esc_attr( $session->session_id ); ?>">Terminate</button>
							<?php endif; ?>
							<a href="<?php echo esc_url( add_query_arg( 'session_id', $session->session_id, $session_page_url ) ); ?>" class="qp-button qp-button-primary"><?php echo ( $session->status === 'paused' ? 'Resume' : 'Continue' ); ?></a>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>
	</div>
<?php endif; ?>

<div class="qp-card qp-card-recent-history">
    <div class="qp-card-header">
        <h3><?php esc_html_e( 'Recent History', 'question-press' ); ?></h3>
        <a href="<?php echo esc_url( $history_tab_url ); ?>" class="qp-button qp-button-secondary qp-button-small"><?php esc_html_e( 'View All', 'question-press' ); ?></a>
    </div>
    <div class="qp-card-content qp-history-list qp-overview-history-list" style="padding: 0;">
        <?php if ( ! empty( $recent_history ) ) : ?>
            <?php
            foreach ( $recent_history as $session ) :
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
                    // Fetch full course/section/item name
                    global $wpdb; 
                    $course_id = absint($settings['course_id']);
                    $item_id = absint($settings['item_id']);
                    $course_title = get_the_title($course_id);
                    $items_table = $wpdb->prefix . 'qp_course_items';
                    $sections_table = $wpdb->prefix . 'qp_course_sections';
                    $item_info = $wpdb->get_row($wpdb->prepare(
                        "SELECT i.title AS item_title, s.title AS section_title
                        FROM {$items_table} i
                        LEFT JOIN {$sections_table} s ON i.section_id = s.section_id
                        WHERE i.item_id = %d", $item_id
                    ));
                    $item_title = $item_info ? $item_info->item_title : null;
                    $section_title = $item_info ? $item_info->section_title : null;
                    $name_parts = [];
                    if ($course_title) $name_parts[] = esc_html($course_title);
                    if ($section_title) $name_parts[] = esc_html($section_title);
                    if ($item_title) $name_parts[] = esc_html($item_title);
                    if (!empty($name_parts)) {
                        $context_display = implode(' / ', $name_parts);
                    } else {
                        $context_display = '';
                    }
                } else {
                    // --- Use the *recent* lineage data ---
                    $context_display = Dashboard::get_session_subjects_display( $session, $settings, $lineage_cache_recent, $group_to_topic_map_recent, $question_to_group_map_recent );
                }
            ?>
                <div class="qp-history-item-card <?php echo esc_attr( $mode_details['class'] ); ?>">
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
                                <?php if ( $mode_details['class'] !== 'mode-course-test' ) : ?>
                                    <span class="context-label">Subjects:</span>
                                <?php endif; ?>
                                <span class="context-content"><?php echo wp_kses_post( $context_display ); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php // --- NOTE: We have REMOVED the .qp-card-sub-stats DIV here for conciseness --- ?>

                    </div>

                    <div class="qp-history-item-actions">
                        <span class="qp-history-item-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $session->start_time ) ) ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( 'session_id', $session->session_id, $review_page_url ) ); ?>" class="qp-button qp-button-secondary qp-button-review qp-button-small"><?php esc_html_e( 'Review', 'question-press' ); ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p style="text-align: center; padding: 2rem;"><?php esc_html_e( 'No recent history.', 'question-press' ); ?></p>
        <?php endif; ?>
    </div>
</div>