<?php
if (!defined('ABSPATH')) exit;

class QP_Shortcodes
{

    public static function render_practice_form()
    {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to start a practice session. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }
        $output = '<div id="qp-practice-app-wrapper">';
        $output .= self::render_settings_form();
        $output .= '</div>';
        return $output;
    }

    // In public/class-qp-shortcodes.php

    private static function render_settings_form()
    {
        global $wpdb;
        $subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
        ob_start();
?>
        <div class="qp-practice-form-wrapper">
            <h2>Start a New Practice Session</h2>
            <form id="qp-start-practice-form" method="post" action="">

                <div class="qp-form-group">
                    <label for="qp_subject">Select Subject:</label>
                    <select name="qp_subject" id="qp_subject" required>
                        <option value="" disabled selected>-- Please select a subject --</option>
                        <option value="all">All Subjects</option>
                        <?php foreach ($subjects as $subject) : ?>
                            <option value="<?php echo esc_attr($subject->subject_id); ?>"><?php echo esc_html($subject->subject_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="qp-form-group qp-checkbox-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_pyq_only" value="1">
                        <span></span>
                        PYQ Only
                    </label>
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_revise_mode" value="1">
                        <span></span>
                        Revision Mode
                    </label>
                </div>
                <div class="qp-form-group-description">
                    <p><strong>PYQ Only:</strong> Only include questions that are marked as a "Previous Year Question".</p>
                    <p><strong>Revision Mode:</strong> Previously answered questions will reappear in your session to help you revise.</p>
                </div>

                <div class="qp-form-group qp-marks-group">
                    <div>
                        <label for="qp_marks_correct">Marks for Correct Answer:</label>
                        <input type="number" name="qp_marks_correct" id="qp_marks_correct" value="4" step="0.1" required>
                    </div>
                    <div>
                        <label for="qp_marks_incorrect">Penalty for Incorrect Answer:</label>
                        <input type="number" name="qp_marks_incorrect" id="qp_marks_incorrect" value="1" step="0.1" min="0" required>
                    </div>
                </div>

                <div class="qp-form-group">
                    <label class="qp-custom-checkbox">
                        <input type="checkbox" name="qp_timer_enabled" id="qp_timer_enabled_cb">
                        <span></span>
                        Enable Timer per Question
                    </label>
                    <div id="qp-timer-input-wrapper" style="display: none; margin-top: 15px;">
                        <label for="qp_timer_seconds">Time in Seconds:</label>
                        <input type="number" name="qp_timer_seconds" id="qp_timer_seconds" value="60" min="10">
                    </div>
                </div>

                <div class="qp-form-group">
                    <input type="submit" name="qp_start_practice" value="Start Practice">
                </div>
            </form>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Renders the static HTML layout for the main practice screen.
     * Changed to public so our AJAX handler can access it.
     */
    // In public/class-qp-shortcodes.php

    // In public/class-qp-shortcodes.php

    public static function render_practice_ui()
    {
        ob_start();
    ?>
        <div class="qp-practice-wrapper">
            <div class="qp-header">
                <div class="qp-header-stat timer-stat">
                    <div class="label">Timer</div>
                    <div class="value" id="qp-timer">--:--</div>
                </div>
                <div class="qp-header-stat">
                    <div class="label">Score</div>
                    <div class="value" id="qp-score">0</div>
                </div>
                <div class="qp-header-stat">
                    <div class="label">Correct</div>
                    <div class="value" id="qp-correct-count">0</div>
                </div>
                <div class="qp-header-stat">
                    <div class="label">Incorrect</div>
                    <div class="value" id="qp-incorrect-count">0</div>
                </div>
                <div class="qp-header-stat">
                    <div class="label">Skipped</div>
                    <div class="value" id="qp-skipped-count">0</div>
                </div>
            </div>

            <div class="qp-direction" style="display: none;"></div>

            <div class="qp-question-area">
                <div id="qp-revision-indicator" style="display: none; margin-bottom: 15px; background-color: #fffbe6; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; font-weight: bold; color: #856404;">
                &#9851; This is a Revision Question
            </div>
            <div id="qp-reported-indicator" style="display: none; margin-bottom: 15px; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 4px; font-weight: bold; color: #856404;">
                &#9888; You have reported an issue with this question.
            </div>
                <div class="question-meta" style="font-size: 12px; color: #777; margin-bottom: 10px;">
                    <span id="qp-question-subject"></span> | <span id="qp-question-id"></span>
                </div>
                <div class="question-text" id="qp-question-text-area">
                    <p>Loading question...</p>
                </div>
            </div>

            <div class="qp-options-area"></div>

            <div class="qp-footer-nav">
                <button id="qp-prev-btn" disabled>&laquo; Previous</button>
                <button id="qp-skip-btn">Skip</button>
                <button id="qp-next-btn">Next &raquo;</button>
            </div>

            <div class="qp-footer-controls" style="margin-top: 20px; text-align: center;">
                <button id="qp-end-practice-btn" class="qp-button">End Practice</button>
            </div>

            <div class="qp-user-report-area">
            <h4>Report</h4> <div class="button-group">
                 <button class="qp-report-button qp-user-report-btn" data-label="Wrong Answer">Report Incorrect Answer</button>
                 <button class="qp-report-button qp-user-report-btn" data-label="No Answer">Report No Answer</button>
            </div>
        </div>

        <?php if (current_user_can('manage_options')) : // Admin-only tools ?>
        <div class="qp-admin-report-area">
            <h4>For Admins Only (Label As)</h4>
            <div class="button-group">
                <button class="qp-report-button qp-admin-report-btn" data-label="Incorrect Formatting">Incorrect Formatting</button>
                <button class="qp-report-button qp-admin-report-btn" data-label="Wrong Subject">Wrong Subject</button>
            </div>
        </div>
        <?php endif; ?>
        </div>
<?php
        return ob_get_clean();
    }
}
