<?php
/**
 * Template for the Dashboard Progress Tab content.
 *
 * @var array $subjects       Array of available subject objects.
 * @var array $progress_data  Array containing aggregated user performance stats.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<style>
.qp-stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
.qp-stats-hero { display: flex; align-items: center; justify-content: center; }
.qp-accuracy-circle { width: 160px; height: 160px; }
.circular-chart { display: block; margin: 0 auto; max-width: 100%; }
.circle-bg { fill: none; stroke: #eee; stroke-width: 3.8; }
.circle { fill: none; stroke: var(--qp-primary); stroke-width: 2.8; stroke-linecap: round; }
.percentage { fill: var(--qp-dashboard-text); font-size: 0.5em; text-anchor: middle; font-weight: bold; }
.circle-label { fill: var(--qp-dashboard-text-light); font-size: 0.15em; text-anchor: middle; }
.qp-stats-cards { display: flex; flex-direction: column; gap: 1rem; }
.qp-stat-card { display: flex; align-items: center; padding: 1rem; gap: 1rem; }
.qp-stat-card .dashicons { font-size: 32px; width: 32px; height: 32px; color: var(--qp-primary); }
.qp-stat-info { display: flex; flex-direction: column; }
.qp-stat-value { font-size: 1.5rem; font-weight: bold; color: var(--qp-dashboard-text); line-height: 1; }
.qp-stat-label { font-size: 0.9rem; color: var(--qp-dashboard-text-light); }
@media (max-width: 768px) { .qp-stats-grid { grid-template-columns: 1fr; } }
</style>

<h2><?php esc_html_e( 'Performance Overview', 'question-press' ); ?></h2>

<div class="qp-stats-grid">
    <div class="qp-stats-hero qp-card">
        <div class="qp-card-content">
            <div class="qp-accuracy-circle">
                <svg viewBox="0 0 36 36" class="circular-chart">
                    <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    <path class="circle" stroke-dasharray="<?php echo esc_attr($progress_data['accuracy']); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    <text x="18" y="20.35" class="percentage"><?php echo number_format($progress_data['accuracy'], 0); ?>%</text>
                    <text x="18" y="26" class="circle-label">Accuracy</text>
                </svg>
            </div>
        </div>
    </div>
    <div class="qp-stats-cards">
        <div class="qp-stat-card qp-card">
            <span class="dashicons dashicons-editor-help"></span>
            <div class="qp-stat-info">
                <span class="qp-stat-value"><?php echo number_format_i18n($progress_data['total_attempts']); ?></span>
                <span class="qp-stat-label">Questions Attempted</span>
            </div>
        </div>
        <div class="qp-stat-card qp-card">
            <span class="dashicons dashicons-clock"></span>
            <div class="qp-stat-info">
                <span class="qp-stat-value"><?php echo number_format($progress_data['total_time'] / 3600, 1); ?></span>
                <span class="qp-stat-label">Total Hours Spent</span>
            </div>
        </div>
        <div class="qp-stat-card qp-card">
            <span class="dashicons dashicons-awards"></span>
            <div class="qp-stat-info">
                <span class="qp-stat-value"><?php echo number_format_i18n($progress_data['streak']); ?></span>
                <span class="qp-stat-label">Current Day Streak</span>
            </div>
        </div>
    </div>
</div>

<?php if ( current_user_can( 'manage_options' ) ) : ?>
    <div class="qp-card">
        <div class="qp-card-header"><h3>Advanced Filters</h3></div>
        <div class="qp-card-content">
            <div class="qp-progress-filters">
                <div class="qp-form-group">
                    <label for="qp-progress-subject"><?php esc_html_e( 'Select Subject', 'question-press' ); ?></label>
                    <select id="qp-progress-subject">
                        <option value="">— <?php esc_html_e( 'Select a Subject', 'question-press' ); ?> —</option>
                        <?php foreach ( $subjects as $subject ) : ?>
                            <option value="<?php echo esc_attr( $subject->term_id ); ?>"><?php echo esc_html( $subject->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                </div>
        </div>
    </div>
<?php endif; ?>

<div id="qp-progress-results-container" style="margin-top: 1.5rem;">
    <p style="text-align: center; color: var(--qp-dashboard-text-light);">
        <?php esc_html_e( 'Drill down by selecting filters above (Admin Only) or continue practicing to improve your stats.', 'question-press' ); ?>
    </p>
</div>