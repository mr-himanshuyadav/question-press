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
							<?php if ( $mode !== 'Section Practice' ) : // Assuming this check is still needed ?>
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

<div class="qp-card">
	<div class="qp-card-header">
		<h3>Recent History</h3>
	</div>
	<div class="qp-card-content">
		<?php if ( ! empty( $recent_history ) ) : ?>
			<table class="qp-dashboard-table qp-recent-history-table">
				<thead>
					<tr>
						<th>Date</th>
						<th>Mode</th>
						<th>Context</th> <?php // Changed from Subjects ?>
						<th>Result</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $recent_history as $session ) :
						$settings         = json_decode( $session->settings_snapshot, true );
						$mode             = Dashboard::get_session_mode_name( $session, $settings ); // Use helper
						$context_display  = Dashboard::get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map ); // Use helper, renamed variable
						$result_display   = Dashboard::get_session_result_display( $session, $settings ); // Use helper
						$session_review_url = add_query_arg( 'session_id', $session->session_id, $review_page_url );
					?>
						<tr>
							<td data-label="Date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $session->start_time ) ) ); ?></td>
							<td data-label="Mode"><?php echo esc_html( $mode ); ?></td>
							<td data-label="Context"><?php echo esc_html( $context_display ); ?></td>
							<td data-label="Result"><strong><?php echo esc_html( $result_display ); ?></strong></td>
							<td data-label="Actions" class="qp-actions-cell">
								<a href="<?php echo esc_url( $session_review_url ); ?>" class="qp-button qp-button-secondary">Review</a>
								<?php /* Add delete button here if needed, checking permissions */ ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
            // Get base dashboard URL correctly
            $options = get_option('qp_settings');
            $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
            $base_dashboard_url = $dashboard_page_id ? trailingslashit(get_permalink($dashboard_page_id)) : trailingslashit(home_url());
            $history_tab_url = $base_dashboard_url . 'history/';
            ?>
			<p style="text-align: right; margin-top: 1rem;"><a href="<?php echo esc_url($history_tab_url); ?>" class="qp-view-full-history-link">View Full History &rarr;</a></p>
		<?php else : ?>
			<p style="text-align: center;">No completed sessions yet.</p>
		<?php endif; ?>
	</div>
</div>