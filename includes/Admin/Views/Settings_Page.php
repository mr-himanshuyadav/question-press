<?php

namespace QuestionPress\Admin\Views;

if (!defined('ABSPATH')) exit;

use QuestionPress\Utils\Template_Loader;
use QuestionPress\Utils\Update_Manager;

/**
 * Handles the QuestionPress Settings Page UI, field registration, and data integrity.
 */
class Settings_Page
{
    /**
     * Renders the settings page HTML.
     */
    public static function render()
    {
        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        settings_errors();

        ob_start();
        settings_fields('qp_settings_group');
        $settings_fields_html = ob_get_clean();

        ob_start();
        submit_button('Save Settings', 'primary', 'submit_top', false);
        $submit_button_top = ob_get_clean();

        ob_start();
        do_settings_sections('qp-settings-page');
        $sections_html = ob_get_clean();

        ob_start();
        submit_button('Save Settings');
        $submit_button_bottom = ob_get_clean();

        $args = [
            'settings_fields_html' => $settings_fields_html,
            'submit_button_top'    => $submit_button_top,
            'sections_html'        => $sections_html,
            'submit_button_bottom' => $submit_button_bottom,
        ];

        echo Template_Loader::get_html('settings-page-wrapper', 'admin', $args);
    }

    /**
     * Registers all settings sections and fields.
     */
    public static function register_settings()
    {
        register_setting('qp_settings_group', 'qp_settings', ['sanitize_callback' => [self::class, 'sanitize_settings']]);

        // Page Settings Section
        add_settings_section('qp_page_settings_section', 'Page Settings', [self::class, 'render_page_section_text'], 'qp-settings-page');
        add_settings_field('qp_practice_page', 'Practice Page', [self::class, 'render_practice_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_dashboard_page', 'Dashboard Page', [self::class, 'render_dashboard_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_review_page', 'Session Review Page', [self::class, 'render_review_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_session_page', 'Session Page', [self::class, 'render_session_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_signup_page', 'Signup Page', [self::class, 'render_signup_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');

        // Data Management Section
        add_settings_section('qp_data_settings_section', 'Data Management', [self::class, 'render_data_section_text'], 'qp-settings-page');
        add_settings_field('qp_question_order', 'Question Order', [self::class, 'render_question_order_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_show_source_meta_roles', 'Display Source Meta To', [self::class, 'render_source_meta_role_multiselect'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_delete_on_uninstall', 'Delete Data on Uninstall', [self::class, 'render_delete_data_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_session_timeout', 'Session Timeout', [self::class, 'render_session_timeout_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_show_question_counts', 'Show Unattempted Counts', [self::class, 'render_show_question_counts_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_show_topic_meta', 'Display Topic Meta', [self::class, 'render_show_topic_meta_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_normal_practice_limit', 'Practice Question Limit', [self::class, 'render_normal_practice_limit_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_question_buffer_size', 'Prefetch Buffer Size', [self::class, 'render_question_buffer_size_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_send_correct_answer', 'Instant Frontend Check', [self::class, 'render_send_correct_answer_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_ui_feedback_mode', 'UI Feedback Mode', [self::class, 'render_ui_feedback_mode_dropdown'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_can_delete_history_roles', 'Roles That Can Delete History', [self::class, 'render_can_delete_history_roles_multiselect'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_allow_session_termination', 'Allow Session Termination', [self::class, 'render_allow_session_termination_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_allow_course_opt_out', 'Allow Course Opt-Out', [self::class, 'render_allow_course_opt_out_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_enable_otp_verification', 'Enable Signup OTP', [self::class, 'render_enable_otp_verification_checkbox'], 'qp-settings-page', 'qp_data_settings_section');

        // App Command Center Section
        add_settings_section('qp_app_control_section', 'App Command Center', [self::class, 'render_app_section_text'], 'qp-settings-page');
        add_settings_field('qp_release_center', 'Release Center', [self::class, 'render_release_center'], 'qp-settings-page', 'qp_app_control_section');
        add_settings_field('qp_min_app_version', 'Min Required Version', [self::class, 'render_min_version_input'], 'qp-settings-page', 'qp_app_control_section');
        add_settings_field('qp_maintenance_mode', 'Maintenance Mode', [self::class, 'render_maintenance_mode_checkbox'], 'qp-settings-page', 'qp_app_control_section');
        add_settings_field('qp_maintenance_message', 'Maintenance Message', [self::class, 'render_maintenance_message_textarea'], 'qp-settings-page', 'qp_app_control_section');
        add_settings_field('qp_store_url_android', 'Play Store URL', [self::class, 'render_android_url_input'], 'qp-settings-page', 'qp_app_control_section');
        add_settings_field('qp_store_url_ios', 'App Store URL', [self::class, 'render_ios_url_input'], 'qp-settings-page', 'qp_app_control_section');
    }

    /**
     * Renders the high-end Release Center Card.
     */
    public static function render_release_center()
    {
        $info = Update_Manager::get_update_info();
        $expected_abis = ['arm64-v8a', 'armeabi-v7a', 'universal'];

        $detected_map = [];
        if ($info && !empty($info['variants'])) {
            foreach ($info['variants'] as $v) {
                $detected_map[$v['abi']] = $v;
            }
        }

        $version_display = $info ? "v{$info['version']} (Build {$info['build']})" : "No Active Release";
    ?>
        <div class="qp-release-card">
            <div class="qp-release-header">
                <h3 style="margin:0;">Active Production Build</h3>
                <span class="qp-version-pill"><?php echo esc_html($version_display); ?></span>
            </div>

            <div class="qp-file-list">
                <?php foreach ($expected_abis as $abi) : ?>
                    <?php if (isset($detected_map[$abi])) :
                        $v = $detected_map[$abi]; ?>
                        <div class="qp-file-item detected">
                            <div class="qp-status-icon">✅</div>
                            <div class="qp-file-info">
                                <strong><?php echo esc_html($v['abi']); ?>.apk</strong>
                                <span>Detected: <?php echo esc_html($v['size'] ?? 'Unknown'); ?> • MD5: <?php echo esc_html(substr($v['md5'], 0, 8)); ?>...</span>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="qp-file-item" style="opacity: 0.5;">
                            <div class="qp-status-icon">🔘</div>
                            <div class="qp-file-info">
                                <strong><?php echo esc_html($abi); ?>.apk</strong>
                                <span>Missing from server (Optional)</span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="qp-upload-container">
                <div class="qp-upload-btn-row">
                    <input type="file" id="qp_release_zip_input" style="display:none;" accept=".zip">
                    <button type="button" class="button button-primary" onclick="document.getElementById('qp_release_zip_input').click();">
                        Upload New Release (.zip)
                    </button>
                    <span id="qp_upload_status"></span>
                </div>
                <p style="font-size:11px; color:#a0aec0; margin-top:10px;">
                    Note: Upload a ZIP containing APKs and output-metadata.json. This will overwrite existing release data.
                </p>
            </div>
        </div>
    <?php
    }

    /**
     * Hardened Sanitization Callback.
     * Fixed: Prevents accidental maintenance mode activation and release data loss.
     */
    public static function sanitize_settings($input)
    {
        // 1. Load existing settings to preserve all non-form data
        $old_settings = get_option('qp_settings', []);
        $new_input    = $old_settings; 

        // 2. Identify if this is a standard UI form save via POST
        $is_form_submission = !empty($_POST['option_page']) && $_POST['option_page'] === 'qp_settings_group';

        // 3. Page Mapping
        $page_keys = ['practice_page', 'dashboard_page', 'session_page', 'signup_page', 'review_page'];
        foreach ($page_keys as $key) {
            if (isset($input[$key])) $new_input[$key] = absint($input[$key]);
        }

        // 4. Checkboxes: ONLY set to 0 if we are in a form submission and the key is missing
        $checkbox_keys = [
            'delete_on_uninstall', 'show_question_counts', 'show_topic_meta', 
            'allow_session_termination', 'allow_course_opt_out', 
            'enable_otp_verification', 'send_correct_answer', 'maintenance_mode'
        ];
        
        foreach ($checkbox_keys as $key) {
            if ($is_form_submission) {
                $new_input[$key] = !empty($input[$key]) ? 1 : 0;
            } elseif (isset($input[$key])) {
                // Programmatic update (like ZIP upload) only changes it if explicitly provided
                $new_input[$key] = !empty($input[$key]) ? 1 : 0;
            }
        }

        // 5. App Control & Data Persistence
        if (isset($input['min_app_version']))       $new_input['min_app_version']       = sanitize_text_field($input['min_app_version']);
        if (isset($input['maintenance_message']))   $new_input['maintenance_message']   = sanitize_textarea_field($input['maintenance_message']);
        if (isset($input['store_url_android']))     $new_input['store_url_android']     = esc_url_raw($input['store_url_android']);
        if (isset($input['store_url_ios']))         $new_input['store_url_ios']         = esc_url_raw($input['store_url_ios']);
        
        // Explicitly preserve release data unless explicitly passed (e.g. from Update_Manager)
        if (isset($input['latest_app_version']))    $new_input['latest_app_version']    = sanitize_text_field($input['latest_app_version']);
        if (isset($input['latest_app_build']))      $new_input['latest_app_build']      = absint($input['latest_app_build']);
        if (isset($input['latest_release_info']))   $new_input['latest_release_info']   = (array) $input['latest_release_info'];

        // 6. Behavioral Settings
        if (isset($input['question_order']))        $new_input['question_order']        = in_array($input['question_order'], ['random', 'in_order']) ? $input['question_order'] : 'random';
        if (isset($input['ui_feedback_mode']))      $new_input['ui_feedback_mode']      = in_array($input['ui_feedback_mode'], ['instant', 'robust']) ? $input['ui_feedback_mode'] : 'robust';
        if (isset($input['session_timeout']))       $new_input['session_timeout']       = max(5, absint($input['session_timeout']));
        if (isset($input['normal_practice_limit'])) $new_input['normal_practice_limit'] = max(10, absint($input['normal_practice_limit']));
        if (isset($input['question_buffer_size']))  $new_input['question_buffer_size']  = min(20, max(1, absint($input['question_buffer_size'])));

        // Roles
        if (isset($input['show_source_meta_roles']))   $new_input['show_source_meta_roles']   = array_map('sanitize_key', (array)$input['show_source_meta_roles']);
        if (isset($input['can_delete_history_roles'])) $new_input['can_delete_history_roles'] = array_map('sanitize_key', (array)$input['can_delete_history_roles']);

        return $new_input;
    }

    // --- Template & Section Helpers ---

    public static function render_page_section_text() { echo '<p>Map QuestionPress shortcodes to pages.</p>'; }
    public static function render_data_section_text() { echo '<p>General behavioral and data management settings.</p>'; }
    public static function render_app_section_text()  { echo '<p>Mobile app version control and maintenance alerts.</p>'; }

    public static function render_practice_page_dropdown() {
        $opts = get_option('qp_settings');
        wp_dropdown_pages(['name' => 'qp_settings[practice_page]', 'selected' => $opts['practice_page'] ?? 0, 'show_option_none' => '— Select Page —']);
    }
    public static function render_dashboard_page_dropdown() {
        $opts = get_option('qp_settings');
        wp_dropdown_pages(['name' => 'qp_settings[dashboard_page]', 'selected' => $opts['dashboard_page'] ?? 0, 'show_option_none' => '— Select Page —']);
    }
    public static function render_review_page_dropdown() {
        $opts = get_option('qp_settings');
        wp_dropdown_pages(['name' => 'qp_settings[review_page]', 'selected' => $opts['review_page'] ?? 0, 'show_option_none' => '— Select Page —']);
    }
    public static function render_session_page_dropdown() {
        $opts = get_option('qp_settings');
        wp_dropdown_pages(['name' => 'qp_settings[session_page]', 'selected' => $opts['session_page'] ?? 0, 'show_option_none' => '— Select Page —']);
    }
    public static function render_signup_page_dropdown() {
        $opts = get_option('qp_settings');
        wp_dropdown_pages(['name' => 'qp_settings[signup_page]', 'selected' => $opts['signup_page'] ?? 0, 'show_option_none' => '— Select Page —']);
    }

    public static function render_question_order_input() {
        $opts = get_option('qp_settings');
        $val = $opts['question_order'] ?? 'random';
        echo '<label><input type="radio" name="qp_settings[question_order]" value="random" ' . checked('random', $val, false) . '> Random</label><br>';
        echo '<label><input type="radio" name="qp_settings[question_order]" value="in_order" ' . checked('in_order', $val, false) . '> In Order</label>';
    }

    public static function render_source_meta_role_multiselect() {
        $opts = get_option('qp_settings');
        $sel = $opts['show_source_meta_roles'] ?? [];
        echo '<div style="display:flex; flex-wrap:wrap; gap:10px;">';
        foreach (get_editable_roles() as $slug => $info) {
            $chk = in_array($slug, $sel) ? 'checked' : '';
            echo "<label><input type='checkbox' name='qp_settings[show_source_meta_roles][]' value='".esc_attr($slug)."' $chk> ".esc_html($info['name'])."</label>";
        }
        echo '</div>';
    }

    public static function render_can_delete_history_roles_multiselect() {
        $opts = get_option('qp_settings');
        $sel = $opts['can_delete_history_roles'] ?? ['administrator'];
        echo '<div style="display:flex; flex-wrap:wrap; gap:10px;">';
        foreach (get_editable_roles() as $slug => $info) {
            $dis = ($slug === 'administrator') ? 'checked disabled' : (in_array($slug, $sel) ? 'checked' : '');
            echo "<label><input type='checkbox' name='qp_settings[can_delete_history_roles][]' value='".esc_attr($slug)."' $dis> ".esc_html($info['name'])."</label>";
        }
        echo '</div>';
    }

    public static function render_maintenance_mode_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[maintenance_mode]" value="1" ' . checked(1, $opts['maintenance_mode'] ?? 0, false) . '> Enable Global Maintenance Mode</label>';
    }

    public static function render_maintenance_message_textarea() {
        $opts = get_option('qp_settings');
        echo '<textarea name="qp_settings[maintenance_message]" rows="3" class="large-text">' . esc_textarea($opts['maintenance_message'] ?? '') . '</textarea>';
    }

    public static function render_min_version_input() {
        $opts = get_option('qp_settings');
        echo '<input type="text" name="qp_settings[min_app_version]" value="' . esc_attr($opts['min_app_version'] ?? '1.0.0') . '" class="small-text" />';
    }

    public static function render_android_url_input() {
        $opts = get_option('qp_settings');
        echo '<input type="url" name="qp_settings[store_url_android]" value="' . esc_url($opts['store_url_android'] ?? '') . '" class="regular-text" />';
    }

    public static function render_ios_url_input() {
        $opts = get_option('qp_settings');
        echo '<input type="url" name="qp_settings[store_url_ios]" value="' . esc_url($opts['store_url_ios'] ?? '') . '" class="regular-text" />';
    }

    public static function render_delete_data_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[delete_on_uninstall]" value="1" ' . checked(1, $opts['delete_on_uninstall'] ?? 0, false) . '> Wipe all data on uninstall</label>';
    }

    public static function render_session_timeout_input() {
        $opts = get_option('qp_settings');
        echo '<input type="number" name="qp_settings[session_timeout]" value="' . absint($opts['session_timeout'] ?? 20) . '" min="5"> min';
    }

    public static function render_show_question_counts_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[show_question_counts]" value="1" ' . checked(1, $opts['show_question_counts'] ?? 0, false) . '> Show unattempted counts</label>';
    }

    public static function render_show_topic_meta_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[show_topic_meta]" value="1" ' . checked(1, $opts['show_topic_meta'] ?? 0, false) . '> Show topic meta in session</label>';
    }

    public static function render_normal_practice_limit_input() {
        $opts = get_option('qp_settings');
        echo '<input type="number" name="qp_settings[normal_practice_limit]" value="' . absint($opts['normal_practice_limit'] ?? 100) . '" min="10">';
    }

    public static function render_question_buffer_size_input() {
        $opts = get_option('qp_settings');
        echo '<input type="number" name="qp_settings[question_buffer_size]" value="' . absint($opts['question_buffer_size'] ?? 5) . '" min="1" max="20">';
    }

    public static function render_send_correct_answer_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[send_correct_answer]" value="1" ' . checked(1, $opts['send_correct_answer'] ?? 0, false) . '> Send correct answer to client</label>';
    }

    public static function render_ui_feedback_mode_dropdown() {
        $opts = get_option('qp_settings');
        $val = $opts['ui_feedback_mode'] ?? 'robust';
        echo "<select name='qp_settings[ui_feedback_mode]'><option value='robust' ".selected('robust',$val,false).">Robust</option><option value='instant' ".selected('instant',$val,false).">Instant</option></select>";
    }

    public static function render_allow_session_termination_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[allow_session_termination]" value="1" ' . checked(1, $opts['allow_session_termination'] ?? 0, false) . '> Allow session termination</label>';
    }

    public static function render_allow_course_opt_out_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[allow_course_opt_out]" value="1" ' . checked(1, $opts['allow_course_opt_out'] ?? 0, false) . '> Allow course deregistration</label>';
    }

    public static function render_enable_otp_verification_checkbox() {
        $opts = get_option('qp_settings');
        echo '<label><input type="checkbox" name="qp_settings[enable_otp_verification]" value="1" ' . checked(1, $opts['enable_otp_verification'] ?? 0, false) . '> Enable Signup OTP</label>';
    }
}