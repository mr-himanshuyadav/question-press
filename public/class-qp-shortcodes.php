<?php
if (!defined('ABSPATH')) exit;

class QP_Shortcodes {

    /**
     * Renders the [question_press_practice] shortcode.
     * This function will now render a container that will be used by our JavaScript.
     * It will first show the form, and later, our script will replace the form with the practice UI.
     */
    public static function render_practice_form() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to start a practice session. <a href="' . wp_login_url(get_permalink()) . '">Click here to log in.</a></p>';
        }

        // The main wrapper for our entire application.
        $output = '<div id="qp-practice-app-wrapper">';
        
        // We always start by rendering the settings form.
        $output .= self::render_settings_form();

        // This is where our JavaScript will inject the practice UI later.
        $output .= '</div>'; 
        
        return $output;
    }

    /**
     * Renders the initial settings form HTML.
     */
    private static function render_settings_form() {
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

                <div class="qp-form-group">
                    <label><input type="checkbox" name="qp_pyq_only" value="1"> PYQ Only</label>
                    <label style="margin-left: 20px;"><input type="checkbox" name="qp_revise_mode" value="1"> Revision Mode</label>
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
                    <label>
                        <input type="checkbox" name="qp_timer_enabled" id="qp_timer_enabled_cb"> Enable Timer per Question
                    </label>
                    <div id="qp-timer-input-wrapper" style="display: none; margin-top: 10px;">
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
}