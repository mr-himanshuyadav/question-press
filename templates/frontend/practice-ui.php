<?php
/**
 * Template for the main Frontend Practice User Interface.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var string $mode_class         CSS class for the current practice mode (e.g., 'mode-mock-test').
 * @var string $mode_name          Display name for the current practice mode (e.g., 'Mock Test').
 * @var bool   $is_mock_test       True if the current session is a mock test.
 * @var bool   $is_section_wise    True if the current session is section-wise practice.
 * @var bool   $user_can_view_source True if the user role allows viewing source meta.
 * @var array  $session_settings   The settings array for the current session.
 * @var bool   $is_palette_mandatory True if the palette should be docked by default on desktop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Determine if the palette should start docked (for body class) - Although this might be better handled via JS after initial load
$body_class_palette = $is_palette_mandatory ? ' palette-mandatory' : '';

?>
<?php // Note: The main wrapper #qp-practice-app-wrapper is output by the shortcode handler ?>

<?php // Add preloader ?>
<div id="qp-preloader"><div class="qp-spinner"></div></div>

<?php // Palette Overlay & Sliding Palette ?>
<div id="qp-palette-overlay">
	<div id="qp-palette-sliding">
		<div class="qp-palette-header">
			<h4>Question Palette</h4>
			<button id="qp-palette-close-btn">&times;</button>
		</div>
		<?php // Palette Stats (only for non-mock tests) ?>
		<?php if ( ! $is_mock_test ) : ?>
			<div class="qp-header-bottom-row qp-palette-stats">
				<div class="qp-header-stat score"><span class="value" id="qp-score">0.00</span><span class="label">Score</span></div>
				<div class="qp-header-stat correct"><span class="value" id="qp-correct-count">0</span><span class="label">Correct</span></div>
				<div class="qp-header-stat incorrect"><span class="value" id="qp-incorrect-count">0</span><span class="label">Incorrect</span></div>
				<div class="qp-header-stat skipped"><span class="value" id="qp-skipped-count">0</span><span class="label"><?php echo $is_section_wise ? 'Not Attempted' : 'Skipped'; ?></span></div>
			</div>
		<?php endif; ?>
		<div class="qp-palette-grid">
			<?php // Palette buttons loaded via JS ?>
		</div>
		<div class="qp-palette-legend">
			<?php // Legend items based on mode ?>
			<?php if ( $is_mock_test ) : ?>
				<div class="legend-item" data-status="answered"><span class="swatch status-answered"></span><span class="legend-text">Answered</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="viewed"><span class="swatch status-viewed"></span><span class="legend-text">Not Answered</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="not_viewed"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Visited</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="marked_for_review"><span class="swatch status-marked_for_review"></span><span class="legend-text">Marked for Review</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="answered_and_marked_for_review"><span class="swatch status-answered_and_marked_for_review"></span><span class="legend-text">Answered & Marked</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
			<?php else : ?>
				<div class="legend-item" data-status="correct"><span class="swatch status-correct"></span><span class="legend-text">Correct</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="incorrect"><span class="swatch status-incorrect"></span><span class="legend-text">Incorrect</span><span class="legend-count">(0)</span></div>
				<?php if ( ! $is_section_wise ) : ?>
					<div class="legend-item" data-status="skipped"><span class="swatch status-skipped"></span><span class="legend-text">Skipped</span><span class="legend-count">(0)</span></div>
				<?php endif; ?>
				<div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
				<?php if ( $is_section_wise ) : ?>
					<div class="legend-item" data-status="not_attempted"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Attempted</span><span class="legend-count">(0)</span></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php // Main Practice Area Wrapper ?>
<div class="qp-container qp-practice-wrapper <?php echo esc_attr( $mode_class ); ?>"> <?php // Mode class added here ?>

	<?php // Docked Palette (for desktop mandatory modes) ?>
	<div id="qp-palette-docked">
		<div class="qp-palette-header">
			<h4>Question Palette</h4>
		</div>
		<?php if ( ! $is_mock_test ) : ?>
			<div class="qp-header-bottom-row qp-palette-stats">
				<div class="qp-header-stat score"><span class="value" id="qp-score">0.00</span><span class="label">Score</span></div>
				<div class="qp-header-stat correct"><span class="value" id="qp-correct-count">0</span><span class="label">Correct</span></div>
				<div class="qp-header-stat incorrect"><span class="value" id="qp-incorrect-count">0</span><span class="label">Incorrect</span></div>
				<div class="qp-header-stat skipped"><span class="value" id="qp-skipped-count">0</span><span class="label"><?php echo $is_section_wise ? 'Not Attempted' : 'Skipped'; ?></span></div>
			</div>
		<?php endif; ?>
		<div class="qp-palette-grid">
			<?php // Palette buttons loaded via JS ?>
		</div>
		<div class="qp-palette-legend">
			<?php // Legend items based on mode (Duplicated for docked view) ?>
			<?php if ( $is_mock_test ) : ?>
				<div class="legend-item" data-status="answered"><span class="swatch status-answered"></span><span class="legend-text">Answered</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="viewed"><span class="swatch status-viewed"></span><span class="legend-text">Not Answered</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="not_viewed"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Visited</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="marked_for_review"><span class="swatch status-marked_for_review"></span><span class="legend-text">Marked for Review</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="answered_and_marked_for_review"><span class="swatch status-answered_and_marked_for_review"></span><span class="legend-text">Answered & Marked</span><span class="legend-count">(0)</span></div>
                <div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
			<?php else : ?>
				<div class="legend-item" data-status="correct"><span class="swatch status-correct"></span><span class="legend-text">Correct</span><span class="legend-count">(0)</span></div>
				<div class="legend-item" data-status="incorrect"><span class="swatch status-incorrect"></span><span class="legend-text">Incorrect</span><span class="legend-count">(0)</span></div>
				<?php if ( ! $is_section_wise ) : ?>
					<div class="legend-item" data-status="skipped"><span class="swatch status-skipped"></span><span class="legend-text">Skipped</span><span class="legend-count">(0)</span></div>
				<?php endif; ?>
				<div class="legend-item" data-status="reported"><span class="swatch status-reported"></span><span class="legend-text">Reported</span><span class="legend-count">(0)</span></div>
				<?php if ( $is_section_wise ) : ?>
					<div class="legend-item" data-status="not_attempted"><span class="swatch status-not_viewed"></span><span class="legend-text">Not Attempted</span><span class="legend-count">(0)</span></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php // Main Content Area ?>
	<div id="qp-main-content">
		<div class="qp-header">
			<div class="qp-header-top-row">
                <div style="display: flex; align-items: center; gap: 10px; margin-right: 10px;">
                    <div class="qp-session-mode-indicator"><?php echo esc_html( $mode_name ); ?></div>
                    <p id="qp-session-id-display" style="margin: 0; color: #50575e; font-size: 14px; display: none;"></p> <?php // Populated by JS ?>
                </div>
				<div style="display: flex; flex-direction: row; gap: 5px;">
					<button id="qp-fullscreen-btn" class="qp-button qp-button-secondary" title="Enter Fullscreen" style="padding: 8px; line-height: 1;">
						<span class="dashicons dashicons-fullscreen-alt"></span>
					</button>
					<button id="qp-palette-toggle-btn" title="Toggle Question Palette">
						<span class="dashicons dashicons-layout"></span>
					</button>
					<button id="qp-rough-work-btn" class="qp-button qp-button-secondary" title="Rough Work" style="padding: 8px; line-height: 1;">
						<span class="dashicons dashicons-edit"></span>
					</button>
				</div>
			</div>

			<?php // Mock Test Header Row ?>
			<?php if ( $is_mock_test ) : ?>
				<div class="qp-header-bottom-row">
					<div class="qp-header-stat">
						<span class="value" id="qp-mock-test-timer">--:--</span>
						<span class="label">Time Remaining</span>
					</div>
					<div class="qp-header-stat">
						<span class="value" id="qp-question-counter">--/--</span>
						<span class="label">Questions</span>
					</div>
				</div>
                <p id="qp-timer-warning-message" style="color: #c62828; font-size: 0.8em;text-align: center; font-weight: 500; margin: 0; display: none;">
                    The test will be submitted automatically when the time expires.
                </p>
			<?php endif; ?>
		</div>

		<?php // Question Display Area ?>
		<div class="qp-animatable-area-container">
			<div class="qp-animatable-area">
				<div class="question-meta">
                    <div class="qp-question-meta-left">
                        <div id="qp-question-subject-line">
                            <span id="qp-question-subject"></span> <?php // Populated by JS ?>
                            <span id="qp-question-id"></span> <?php // Populated by JS ?>
                        </div>
                        <?php if ($user_can_view_source): ?>
                            <div id="qp-question-source"></div> <?php // Populated by JS ?>
                        <?php endif; ?>
                    </div>
					<div class="qp-question-meta-right">
                        <div class="qp-question-counter-box" style="display: none;"> <?php // Display controlled by JS based on mode ?>
                            <span class="qp-counter-label">Question</span>
                            <span class="qp-counter-value" id="qp-question-counter">1/1</span> <?php // Populated by JS ?>
                        </div>
						<button id="qp-report-btn" class="qp-report-button qp-button-secondary"><span>&#9888;</span> Report</button>
					</div>
				</div>

				<div class="qp-indicator-bar" style="display: none;">
					<?php if ( ! $is_mock_test ) : ?><div id="qp-timer-indicator" class="timer-stat" style="display: none;">--:--</div><?php endif; ?>
					<div id="qp-revision-indicator" style="display: none;">&#9851; Revision</div>
					<div id="qp-reported-indicator" style="display: none;">&#9888; Reported</div>
					<div id="qp-suggestion-indicator" style="display: none;">&#9998; Suggestion Sent</div>
				</div>

				<div class="qp-question-area">
					<div class="qp-direction" style="display: none;"></div> <?php // Populated by JS ?>
					<div class="question-text" id="qp-question-text-area">
						<p>Loading question...</p> <?php // Populated by JS ?>
					</div>
				</div>

				<div class="qp-options-area">
					<?php // Options populated by JS ?>
				</div>

				<?php // Action Bar Below Options ?>
				<?php if ( $is_mock_test ) : ?>
					<div class="qp-mock-test-actions">
						<button type="button" id="qp-clear-response-btn" class="qp-button qp-button-secondary">Clear Response</button>
						<label class="qp-button qp-button-secondary qp-review-later-checkbox">
                            <input type="checkbox" id="qp-mock-mark-review-cb"><span>Mark for Review</span>
                        </label>
					</div>
				<?php else : ?>
					<div class="qp-review-later" style="text-align:center;margin-bottom: 5px;">
                        <label class="qp-review-later-checkbox qp-button qp-button-secondary">
                            <input type="checkbox" id="qp-mark-for-review-cb"><span>Add to Review List</span>
                        </label>
                        <button id="qp-check-answer-btn" class="qp-button qp-button-primary" disabled>Check Answer</button>
                        <label class="qp-custom-checkbox" style="margin-left: 15px;">
                            <input type="checkbox" id="qp-auto-check-cb"><span></span>Auto Check
                        </label>
                    </div>
				<?php endif; ?>
			</div> <?php // End qp-animatable-area ?>
		</div> <?php // End qp-animatable-area-container ?>

		<?php // Footer Navigation ?>
		<div class="qp-footer-nav">
			<button id="qp-prev-btn" class="qp-button qp-button-primary" disabled><span>&#9664;</span></button>
			<?php if ( ! $is_mock_test && ! $is_section_wise ) : ?>
				<button id="qp-skip-btn" class="qp-button qp-button-secondary">Skip</button>
			<?php endif; ?>
			<button id="qp-next-btn" class="qp-button qp-button-primary"><span>&#9654;</span></button>
		</div>

		<hr class="qp-footer-divider">

		<?php // Footer Controls ?>
		<div class="qp-footer-controls">
			<?php if ( $is_mock_test ) : ?>
				<button id="qp-submit-test-btn" class="qp-button qp-button-danger">Submit Test</button>
			<?php else : ?>
				<button id="qp-pause-btn" class="qp-button qp-button-secondary">Pause & Save</button>
				<?php if ( ! $is_section_wise ) : ?>
					<button id="qp-end-practice-btn" class="qp-button qp-button-danger">End Session</button>
				<?php endif; ?>
			<?php endif; ?>
		</div>

	</div> <?php // End #qp-main-content ?>
</div> <?php // End .qp-practice-wrapper ?>

<?php // Report Modal ?>
<div id="qp-report-modal-backdrop" style="display: none;">
    <div id="qp-report-modal-content">
        <button class="qp-modal-close-btn" style="outline: none;">&times;</button>
        <h3>Report an Issue</h3>
        <p>Please select all issues that apply to the current question.</p>
        <form id="qp-report-form">
            <div id="qp-report-options-container"><?php // Options loaded via AJAX ?></div>
            <label for="qp-report-comment" style="font-size: .8em;">Comment<span style="color: red;">*</span></label>
            <textarea id="qp-report-comment" name="report_comment" rows="3" placeholder="Add a comment to explain the issue..." required></textarea>
            <div class="qp-modal-footer">
                <button type="submit" class="qp-button qp-button-primary">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<?php // Rough Work Canvas Modal ?>
<div id="qp-rough-work-overlay" style="display: none;">
    <div id="qp-rough-work-popup" class="qp-draggable-popup">
        <div class="qp-popup-header">
            <div class="qp-rough-work-controls">
                <button id="qp-tool-pencil" class="qp-tool-btn active" title="Pencil"><span class="dashicons dashicons-edit"></span></button>
                <button id="qp-tool-eraser" class="qp-tool-btn" title="Eraser"><span class="dashicons dashicons-editor-removeformatting"></span></button>
                <button id="qp-undo-btn" class="qp-tool-btn" title="Undo" disabled><span class="dashicons dashicons-undo"></span></button>
                <button id="qp-redo-btn" class="qp-tool-btn" title="Redo" disabled><span class="dashicons dashicons-redo"></span></button>
                <div class="qp-color-swatches">
                    <button class="qp-color-btn active" data-color="#171717ff" style="background-color: #171717ff;" title="Black"></button>
                    <button class="qp-color-btn" data-color="#ca0808ff" style="background-color: #ca0808ff;" title="Red"></button>
                    <button class="qp-color-btn" data-color="#002daaff" style="background-color: #002daaff;" title="Blue"></button>
                </div>
                <input type="range" min="10" max="100" value="90" class="qp-canvas-slider" id="qp-canvas-opacity-slider" title="Change Transparency"> <?php // Default to 90 ?>
                <button id="qp-clear-canvas-btn" class="qp-button qp-button-secondary">Clear</button>
            </div>
            <button id="qp-close-canvas-btn" class="qp-popup-close-btn" title="Close">&times;</button>
        </div>
        <div class="qp-popup-content">
            <canvas id="qp-rough-work-canvas"></canvas>
        </div>
        <div class="qp-popup-resize-handle"><span></span></div>
    </div>
</div>