<?php 

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// All variables like $session, $attempts, $settings, $mode_class, etc.,
// are passed in from the $args array in render_review_page()

?>
<div id="qp-practice-app-wrapper">


<div class="qp-container qp-review-wrapper <?php echo esc_attr($mode_class); ?>">
			<div style="display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 1.5rem; gap: 1rem;">
				<div style="display: flex; flex-direction: row; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
					<h2>Review</h2>
					<div class="qp-review-header-actions" style="display: flex; align-items: center; gap: 10px;">
						<?php
						if ($back_button_tag === 'a') :
						?>
							<a href="<?php echo esc_url($back_button_url); ?>" class="qp-button qp-button-secondary">&laquo; <?php echo $back_button_text; ?></a>
						<?php else : ?>
							<button type="button" onclick="<?php echo esc_attr($back_button_onclick); ?>" class="qp-button qp-button-secondary">&laquo; <?php echo $back_button_text; ?></button>
						<?php endif; ?>
						<a href="<?php echo esc_url($dashboard_page_url); ?>" class="qp-button qp-button-primary">Dashboard</a>
					</div>
				</div>
				<div style="display: flex; align-items: center; gap: 15px;">
					<span class="qp-session-mode-indicator" style="padding: 5px 12px; font-size: 12px;"><?php echo esc_html($mode); ?></span>
					<p style="margin: 0; color: #50575e; font-size: 14px;"><strong>Session ID:</strong> <?php echo esc_html($session_id); ?></p>
					<?php if ($is_course_item_deleted) : ?>
						<em style="color:#777; font-size:13px;">(Original course item removed)</em>
					<?php endif; ?>
				</div>
			</div>

			<div class="qp-summary-wrapper qp-review-summary">
				<div class="qp-summary-stats">
					<?php if (isset($settings['marks_correct'])) : ?>
						<div class="stat">
							<div class="value"><?php echo number_format($session->marks_obtained, 2); ?></div>
							<div class="label">Final Score</div>
						</div>
					<?php endif; ?>
					<div class="stat">
						<div class="value"><?php echo esc_html($avg_time_per_question); ?></div>
						<div class="label">Avg. Time / Q</div>
					</div>
					<div class="stat accuracy">
						<div class="value"><?php echo round($accuracy, 2); ?>%</div>
						<div class="label">Accuracy</div>
					</div>
					<div class="stat">
						<div class="value"><?php echo (int) $session->correct_count; ?></div>
						<div class="label">Correct<?php if (isset($settings['marks_correct'])) {
														echo ' (+' . esc_html($marks_correct) . '/Q)';
													} ?></div>
					</div>
					<div class="stat">
						<div class="value"><?php echo (int) $session->incorrect_count; ?></div>
						<div class="label">Incorrect<?php if (isset($settings['marks_correct'])) {
														echo ' (' . esc_html($marks_incorrect) . '/Q)';
													} ?></div>
					</div>

					<?php if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test') : ?>
						<div class="stat">
							<div class="value"><?php echo (int) $session->skipped_count; ?></div>
							<div class="label">Viewed & Unattempted</div>
						</div>
						<div class="stat">
							<div class="value"><?php echo (int) $session->not_viewed_count; ?></div>
							<div class="label">Not Viewed</div>
						</div>
					<?php elseif (! $is_section_wise_practice) : // *** THIS IS THE FIX ***
					?>
						<div class="stat">
							<div class="value"><?php echo (int) $session->skipped_count; ?></div>
							<div class="label">Skipped</div>
						</div>
					<?php endif; ?>
				</div>
				<?php if (! empty($topics_in_session)) : ?>
					<div class="qp-review-topics-list">
						<strong>Topics in this session:</strong> <?php echo implode(', ', array_map('esc_html', $topics_in_session)); ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="qp-review-questions-list">
				<?php
				foreach ($attempts as $index => $attempt) : // $attempts is now the $data['questions'] array
					$is_skipped          = empty($attempt->selected_option_id);
					$answer_display_text = 'Skipped';
					$answer_class        = $is_skipped ? 'skipped' : ($attempt->is_correct ? 'correct' : 'incorrect');

					if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test') {
						if ($attempt->mock_status === 'not_viewed') {
							$answer_display_text = 'Not Viewed';
							$answer_class        = 'not-viewed';
						} elseif ($attempt->mock_status === 'viewed' || $attempt->mock_status === 'marked_for_review') {
							$answer_display_text = 'Unattempted';
							$answer_class        = 'unattempted';
						}
					}
				?>
					<div class="qp-review-question-item">
						<div class="qp-review-question-meta" style="display: flex; justify-content: space-between; align-items: flex-start;">
							<div class="meta-left" style="display: flex; flex-direction: column; gap: 5px;">
								<span><strong>Question ID: </strong><?php echo esc_html($attempt->question_id); ?><?php
																													if (! empty($attempt->attempt_id)) {
																														echo ' | <strong>Attempt ID: </strong>' . esc_html($attempt->attempt_id);
																													}
																													?></span>
								<span>
									<strong>Topic: </strong>
									<?php echo esc_html(implode(' / ', $attempt->subject_lineage)); ?>
								</span>
							</div>
							<div class="meta-right">
								<?php $is_reported = in_array($attempt->question_id, $reported_qids_for_user); ?>
								<button class="qp-report-button qp-report-btn-review" data-question-id="<?php echo esc_attr($attempt->question_id); ?>" <?php echo $is_reported ? 'disabled' : ''; ?>>
									<span>&#9888;</span> <?php echo $is_reported ? 'Reported' : 'Report'; ?>
								</button>
							</div>
						</div>
						<?php
						$user_can_view_source = ! empty(array_intersect((array) wp_get_current_user()->roles, (array) ($options['show_source_meta_roles'] ?? [])));
						if ($mode === 'Section Wise Practice' && $user_can_view_source && ! empty($attempt->source_lineage)) :
							$source_parts = $attempt->source_lineage;
							if ($attempt->question_number_in_section) {
								$source_parts[] = 'Q ' . esc_html($attempt->question_number_in_section);
							}
						?>
							<div class="qp-review-source-meta">
								<?php echo implode(' / ', $source_parts); ?>
							</div>
						<?php endif; ?>
						<?php if (! empty($attempt->direction_text)) : ?>
							<div class="qp-review-direction-text">
								<?php echo wp_kses_post(nl2br($attempt->direction_text)); ?>
							</div>
						<?php endif; ?>

						<div class="qp-review-question-text">
							<strong>Q<?php echo $index + 1; ?>:</strong> <?php echo wp_kses_post(nl2br($attempt->question_text)); ?>
						</div>

						<div class="qp-review-answer-row">
							<span class="qp-review-label">Your Answer:</span>
							<span class="qp-review-answer <?php echo $answer_class; ?>">
								<?php
								if ($is_skipped) {
									echo esc_html($answer_display_text);
								} else {
									echo esc_html($attempt->selected_answer);
								}
								?>
							</span>
						</div>

						<?php if ($is_skipped || ! $attempt->is_correct) : ?>
							<div class="qp-review-answer-row">
								<span class="qp-review-label">Correct Answer:</span>
								<span class="qp-review-answer correct">
									<?php echo esc_html($attempt->correct_answer); ?>
								</span>
							</div>
						<?php endif; ?>

						<div class="qp-review-all-options-wrapper" style="margin-top: 0.5rem; padding-top: 0.5rem;">
							<details>
								<summary style="cursor: pointer; font-weight: bold; color: #2271b1; font-size: 13px; list-style-position: inside; outline: none;">
									Show All Options
								</summary>
								<ul style="margin: 10px 0 0 0; padding-left: 20px; list-style-type: upper-alpha;">
									<?php foreach ($attempt->options as $option) : ?>
										<li style="padding: 2px 0; <?php echo $option->is_correct ? 'font-weight: bold; color: #2e7d32;' : ''; ?>">
											<?php echo esc_html($option->option_text); ?>
											<span style="font-weight: normal; color: #888; font-size: 0.6em; margin-left: 5px;">(ID: <?php echo esc_html($option->option_id); ?>)</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</details>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<div id="qp-report-modal-backdrop" style="display: none;">
			<div id="qp-report-modal-content">
				<button class="qp-modal-close-btn">&times;</button>
				<h3>Report an Issue</h3>
				<p>Please select all issues that apply to the current question.</p>
				<form id="qp-report-form">
					<input type="hidden" id="qp-report-question-id-field" value="">
					<div id="qp-report-options-container"></div>
					<label for="qp-report-comment-review" style="font-size: .8em;">Comment<span style="color: red;">*</span></label>
					<textarea id="qp-report-comment-review" name="report_comment" rows="3" placeholder="Add a comment to explain the issue..." required></textarea>
					<div class="qp-modal-footer">
						<button type="submit" class="qp-button qp-button-primary">Submit Report</button>
					</div>
				</form>
			</div>
		</div>

        </div>