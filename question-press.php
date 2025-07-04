<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           1.0.0
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       question-press
 */

if (!defined('ABSPATH')) exit;

// Define constants
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

// Include class files
require_once QP_PLUGIN_DIR . 'admin/class-qp-subjects-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-labels-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-import-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-importer.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-export-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-questions-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-question-editor-page.php';
// Include Public class files
require_once QP_PLUGIN_DIR . 'public/class-qp-shortcodes.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-dashboard.php';


// Activation/Deactivation/Uninstall Hooks
// Activation/Deactivation/Uninstall Hooks
function qp_activate_plugin() { 
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // ... (All other table schemas are unchanged) ...
    // Table for Subjects
    $table_subjects = $wpdb->prefix . 'qp_subjects';
    $sql_subjects = "CREATE TABLE $table_subjects ( subject_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, subject_name VARCHAR(255) NOT NULL, description TEXT, PRIMARY KEY (subject_id) ) $charset_collate;";
    dbDelta($sql_subjects);
    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_subjects WHERE subject_name = %s", 'Uncategorized')) == 0) { $wpdb->insert($table_subjects, ['subject_name' => 'Uncategorized', 'description' => 'Default subject for questions without an assigned one.']); }
    // Table for Labels
    $table_labels = $wpdb->prefix . 'qp_labels';
    $sql_labels = "CREATE TABLE $table_labels ( label_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, label_name VARCHAR(255) NOT NULL, label_color VARCHAR(7) NOT NULL DEFAULT '#cccccc', is_default BOOLEAN NOT NULL DEFAULT 0, description TEXT, PRIMARY KEY (label_id) ) $charset_collate;";
    dbDelta($sql_labels);
    $default_labels = [['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'], ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'], ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'], ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'], ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']];
    foreach ($default_labels as $label) { if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name'])) == 0) { $wpdb->insert($table_labels, $label); } }
    // Table for Question Groups (Directions)
    $table_groups = $wpdb->prefix . 'qp_question_groups';
    $sql_groups = "CREATE TABLE $table_groups ( group_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, direction_text LONGTEXT, direction_image_id BIGINT(20) UNSIGNED, subject_id BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (group_id), KEY subject_id (subject_id) ) $charset_collate;";
    dbDelta($sql_groups);

    // UPDATED Table for Questions
    $table_questions = $wpdb->prefix . 'qp_questions';
    $sql_questions = "CREATE TABLE $table_questions (
        question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        custom_question_id BIGINT(20) UNSIGNED,
        group_id BIGINT(20) UNSIGNED,
        question_text LONGTEXT NOT NULL,
        question_text_hash VARCHAR(32) NOT NULL,
        is_pyq BOOLEAN NOT NULL DEFAULT 0,
        source_file VARCHAR(255),
        source_page INT,
        source_number INT,
        import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'publish',
        PRIMARY KEY (question_id),
        UNIQUE KEY custom_question_id (custom_question_id),
        KEY group_id (group_id),
        KEY status (status),
        KEY is_pyq (is_pyq),
        KEY question_text_hash (question_text_hash)
    ) $charset_collate;";
    dbDelta($sql_questions);
    
    // ... (All other table schemas are unchanged) ...
    // Table for Options
    $table_options = $wpdb->prefix . 'qp_options'; $sql_options = "CREATE TABLE $table_options ( option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, question_id BIGINT(20) UNSIGNED NOT NULL, option_text TEXT NOT NULL, is_correct BOOLEAN NOT NULL DEFAULT 0, PRIMARY KEY (option_id), KEY question_id (question_id) ) $charset_collate;"; dbDelta($sql_options);
    // Table for Question Labels
    $table_question_labels = $wpdb->prefix . 'qp_question_labels'; $sql_question_labels = "CREATE TABLE $table_question_labels ( question_id BIGINT(20) UNSIGNED NOT NULL, label_id BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (question_id, label_id), KEY label_id (label_id) ) $charset_collate;"; dbDelta($sql_question_labels);
    // Table for User Practice Sessions
    $table_sessions = $wpdb->prefix . 'qp_user_sessions'; $sql_sessions = "CREATE TABLE $table_sessions ( session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT(20) UNSIGNED NOT NULL, start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, end_time DATETIME, total_attempted INT, correct_count INT, incorrect_count INT, skipped_count INT, marks_obtained DECIMAL(10, 2), settings_snapshot TEXT, PRIMARY KEY (session_id), KEY user_id (user_id) ) $charset_collate;"; dbDelta($sql_sessions);
    // Table for User Question Attempts
    $table_attempts = $wpdb->prefix . 'qp_user_attempts'; $sql_attempts = "CREATE TABLE $table_attempts ( attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, session_id BIGINT(20) UNSIGNED NOT NULL, user_id BIGINT(20) UNSIGNED NOT NULL, question_id BIGINT(20) UNSIGNED NOT NULL, is_correct BOOLEAN, attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (attempt_id), KEY session_id (session_id), KEY user_id (user_id), KEY question_id (question_id) ) $charset_collate;"; dbDelta($sql_attempts);
    // Table for Logs
    $table_logs = $wpdb->prefix . 'qp_logs'; $sql_logs = "CREATE TABLE $table_logs ( log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, log_type VARCHAR(50) NOT NULL, log_message TEXT NOT NULL, log_data LONGTEXT, log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (log_id), KEY log_type (log_type) ) $charset_collate;"; dbDelta($sql_logs);

    // NEW: Initialize our custom ID counter
    add_option('qp_next_custom_question_id', 1000, '', 'no');
}
register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');

function qp_uninstall_plugin() {}
register_uninstall_hook(QP_PLUGIN_FILE, 'qp_uninstall_plugin');

// ADMIN MENU & SCRIPTS SETUP
function qp_admin_menu() {
    add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);
    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    // Unified editor page for both Add New and Edit
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page('question-press', 'Import', 'Import', 'manage_options', 'qp-import', ['QP_Import_Page', 'render']);
    add_submenu_page('question-press', 'Export', 'Export', 'manage_options', 'qp-export', ['QP_Export_Page', 'render']);
    add_submenu_page('question-press', 'Subjects', 'Subjects', 'manage_options', 'qp-subjects', ['QP_Subjects_Page', 'render']);
    add_submenu_page('question-press', 'Labels', 'Labels', 'manage_options', 'qp-labels', ['QP_Labels_Page', 'render']);
}
add_action('admin_menu', 'qp_admin_menu');

function qp_admin_enqueue_scripts($hook_suffix) {
    if (strpos($hook_suffix, 'qp-') !== false || strpos($hook_suffix, 'question-press') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
    
    if ($hook_suffix === 'question-press_page_qp-question-editor' || $hook_suffix === 'admin_page_qp-edit-group') {
        wp_enqueue_media();
        wp_enqueue_script('qp-media-uploader-script', QP_PLUGIN_URL . 'admin/assets/js/media-uploader.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('qp-editor-script', QP_PLUGIN_URL . 'admin/assets/js/question-editor.js', ['jquery'], '1.0.1', true);
    }
    
}
add_action('admin_enqueue_scripts', 'qp_admin_enqueue_scripts');

// FORM & ACTION HANDLERS
function qp_handle_form_submissions() {
    QP_Export_Page::handle_export_submission();
    qp_handle_save_question_group();
}
add_action('admin_init', 'qp_handle_form_submissions');

// In question-press.php

function qp_all_questions_page_cb() {
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
    global $wpdb;
    if (!empty($list_table->items)) {
        foreach ($list_table->items as &$item) {
            $item['group_id'] = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $item['question_id']));
        }
    }
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">All Questions</h1>
        <a href="<?php echo admin_url('admin.php?page=qp-question-editor'); ?>" class="page-title-action">Add New</a>

        <?php if (isset($_GET['message'])) {
            $messages = ['1' => 'Question(s) updated successfully.', '2' => 'Question(s) saved successfully.'];
            $message_id = absint($_GET['message']);
            if (isset($messages[$message_id])) {
                echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($messages[$message_id]) . '</p></div>';
            }
        } ?>

        <hr class="wp-header-end">

        <?php $list_table->views(); ?>
        
        <form method="post">
            <?php wp_nonce_field('bulk-questions'); ?>
            <?php
            // The search box is now called before the table
            $list_table->search_box('Search Questions', 'question');
            $list_table->display();
            ?>
        </form>
    </div>
    <?php
}

// REWRITTEN & ROBUST SAVE LOGIC
// In question-press.php

// In question-press.php

function qp_handle_save_question_group() {
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
        return;
    }

    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;

    $direction_text = sanitize_textarea_field($_POST['direction_text']);
    $direction_image_id = absint($_POST['direction_image_id']);
    $subject_id = absint($_POST['subject_id']);
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($subject_id) || empty($questions_from_form)) { return; }

    // 1. UPDATE OR INSERT THE GROUP
    $group_data = ['direction_text' => $direction_text, 'direction_image_id' => $direction_image_id, 'subject_id' => $subject_id];
    if ($is_editing) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", $group_data, ['group_id' => $group_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}qp_question_groups", $group_data);
        $group_id = $wpdb->insert_id;
    }

    // 2. INTELLIGENTLY UPDATE, INSERT, AND DELETE QUESTIONS
    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $ql_table = "{$wpdb->prefix}qp_question_labels";

    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
    $submitted_q_ids = [];

    foreach ($questions_from_form as $q_data) {
        $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
        $question_text = sanitize_textarea_field($q_data['question_text']);
        if (empty($question_text)) continue;

        $question_db_data = [
            'group_id' => $group_id,
            'question_text' => $question_text,
            'is_pyq' => $is_pyq,
            'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))))
        ];

        if ($question_id && in_array($question_id, $existing_q_ids)) {
            // This is an existing question, UPDATE it.
            $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
            $submitted_q_ids[] = $question_id;
        } else {
            // This is a new question, INSERT it.
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);
            $question_db_data['custom_question_id'] = $next_custom_id;
            
            $wpdb->insert($q_table, $question_db_data);
            $question_id = $wpdb->insert_id;
            update_option('qp_next_custom_question_id', $next_custom_id + 1);
            $submitted_q_ids[] = $question_id;
        }

        // Handle Options (delete old, insert new for this specific question)
        $wpdb->delete($o_table, ['question_id' => $question_id]);
        $options = isset($q_data['options']) ? (array) $q_data['options'] : [];
        $correct_option_index = isset($q_data['is_correct_option']) ? absint($q_data['is_correct_option']) : -1;
        foreach ($options as $index => $option_text) {
            if (!empty(trim($option_text))) {
                $wpdb->insert($o_table, ['question_id' => $question_id, 'option_text' => sanitize_text_field($option_text), 'is_correct' => ($index === $correct_option_index) ? 1 : 0 ]);
            }
        }
        
        // Handle Labels (delete old, insert new for this specific question)
        $wpdb->delete($ql_table, ['question_id' => $question_id]);
        $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
        foreach ($labels as $label_id) {
            $wpdb->insert($ql_table, ['question_id' => $question_id, 'label_id' => $label_id]);
        }
    }

    // 3. DELETE any questions that were removed from the form
    $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
    if (!empty($questions_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $questions_to_delete));
        $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $ql_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
    }
    
    // Redirect Logic
    wp_safe_redirect(admin_url('admin.php?page=question-press&message=1'));
    exit;
}

// ------------------------------------------------------------------
// PUBLIC FACING HOOKS & AJAX
// ------------------------------------------------------------------

// REPLACE this function
function qp_public_init() {
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);
}
add_action('init', 'qp_public_init');

// REPLACE this function
function qp_public_enqueue_scripts() {
    global $post;
    // Check if the post content has either of our shortcodes
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard'))) {
        
        // Always enqueue styles if a shortcode is present
        wp_enqueue_style('qp-practice-styles', QP_PLUGIN_URL . 'public/assets/css/practice.css', [], '1.0.2');
        
        // Localize data for both scripts
        $ajax_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('qp_practice_nonce') // A general nonce for frontend actions
        ];

        // Conditionally load the correct JS file
        if (has_shortcode($post->post_content, 'question_press_practice')) {
            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery'], '1.0.2', true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
        }

        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('qp-dashboard-script', QP_PLUGIN_URL . 'public/assets/js/dashboard.js', ['jquery'], '1.0.0', true);
            // Re-use the same nonce and ajax_url object
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }
    }
}
add_action('wp_enqueue_scripts', 'qp_public_enqueue_scripts');

function qp_start_practice_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $settings_str = isset($_POST['settings']) ? $_POST['settings'] : '';
    $settings = [];
    parse_str($settings_str, $settings);
    $subject_id = isset($settings['qp_subject']) ? $settings['qp_subject'] : '';
    $pyq_only = isset($settings['qp_pyq_only']) ? true : false;
    $settings['marks_correct'] = isset($settings['qp_marks_correct']) ? floatval($settings['qp_marks_correct']) : 4.0;
    $settings['marks_incorrect'] = isset($settings['qp_marks_incorrect']) ? -abs(floatval($settings['qp_marks_incorrect'])) : -1.0;
    $settings['timer_enabled'] = isset($settings['qp_timer_enabled']);
    $settings['timer_seconds'] = isset($settings['qp_timer_seconds']) ? absint($settings['qp_timer_seconds']) : 60;
    if (empty($subject_id)) { wp_send_json_error(['message' => 'Please select a subject.']); }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $where_clauses = ["q.status = 'publish'"];
    $query_args = [];
    if ($subject_id !== 'all') { $where_clauses[] = "g.subject_id = %d"; $query_args[] = absint($subject_id); }
    if ($pyq_only) { $where_clauses[] = "q.is_pyq = 1"; }
    $where_sql = implode(' AND ', $where_clauses);
    $query = "SELECT q.question_id FROM {$q_table} q LEFT JOIN {$g_table} g ON q.group_id = g.group_id WHERE {$where_sql} ORDER BY RAND()";
    $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));
    if (empty($question_ids)) { wp_send_json_error(['message' => 'No questions found matching your criteria.']); }
    
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $wpdb->insert($sessions_table, ['user_id' => get_current_user_id(), 'settings_snapshot' => wp_json_encode($settings)]);
    $session_id = $wpdb->insert_id;

    wp_send_json_success(['ui_html' => QP_Shortcodes::render_practice_ui(), 'question_ids' => $question_ids, 'session_id' => $session_id, 'settings' => $settings]);
}
add_action('wp_ajax_start_practice_session', 'qp_start_practice_session_ajax');

function qp_get_question_data_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) { wp_send_json_error(['message' => 'Invalid Question ID.']); }
    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions'; $g_table = $wpdb->prefix . 'qp_question_groups'; $s_table = $wpdb->prefix . 'qp_subjects'; $o_table = $wpdb->prefix . 'qp_options';
    $question_data = $wpdb->get_row($wpdb->prepare("SELECT q.custom_question_id, q.question_text, g.direction_text, s.subject_name FROM {$q_table} q LEFT JOIN {$g_table} g ON q.group_id = g.group_id LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id WHERE q.question_id = %d", $question_id), ARRAY_A);
    if (!$question_data) { wp_send_json_error(['message' => 'Question not found.']); }
    $question_data['options'] = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY RAND()", $question_id), ARRAY_A);
    wp_send_json_success(['question' => $question_data]);
}
add_action('wp_ajax_get_question_data', 'qp_get_question_data_ajax');

function qp_check_answer_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;
    if (!$session_id || !$question_id || !$option_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }
    global $wpdb;
    $o_table = $wpdb->prefix . 'qp_options'; $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));
    $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => $is_correct ? 1 : 0]);
    wp_send_json_success(['is_correct' => $is_correct, 'correct_option_id' => $correct_option_id]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

function qp_skip_question_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$session_id || !$question_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }
    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => null]);
    wp_send_json_success();
}
add_action('wp_ajax_skip_question', 'qp_skip_question_ajax');


/**
 * AJAX handler for ending a practice session.
 */
function qp_end_practice_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session.']);
    }

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Get session settings to calculate score
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
    $settings = json_decode($session->settings_snapshot, true);
    $marks_correct = isset($settings['marks_correct']) ? floatval($settings['marks_correct']) : 0;
    $marks_incorrect = isset($settings['marks_incorrect']) ? floatval($settings['marks_incorrect']) : 0;

    // Calculate stats from attempts
    $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 1", $session_id));
    $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 0", $session_id));
    $skipped_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct IS NULL", $session_id));
    $total_attempted = $correct_count + $incorrect_count;

    // Calculate final score
    $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

    // Update the session in the database with the final stats
    $wpdb->update($sessions_table, 
        [
            'end_time' => current_time('mysql', 1),
            'total_attempted' => $total_attempted,
            'correct_count' => $correct_count,
            'incorrect_count' => $incorrect_count,
            'skipped_count' => $skipped_count,
            'marks_obtained' => $final_score
        ],
        ['session_id' => $session_id]
    );

    // Send the final stats back to the frontend
    wp_send_json_success([
        'final_score' => $final_score,
        'total_attempted' => $total_attempted,
        'correct_count' => $correct_count,
        'incorrect_count' => $incorrect_count,
        'skipped_count' => $skipped_count,
    ]);
}
add_action('wp_ajax_end_practice_session', 'qp_end_practice_session_ajax');


// ADD THIS NEW FUNCTION to the end of the file
function qp_delete_user_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));

    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'You do not have permission to delete this session.']);
    }

    // Delete the session and its related attempts
    $wpdb->delete($attempts_table, ['session_id' => $session_id], ['%d']);
    $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

    wp_send_json_success(['message' => 'Session deleted.']);
}
add_action('wp_ajax_delete_user_session', 'qp_delete_user_session_ajax');

/**
 * AJAX handler for reporting an issue with a question.
 */
function qp_report_question_issue_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;

    // Get the specific label name from the AJAX call, default to a generic one if not provided
    $report_label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : 'Incorrect Formatting';

    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID.']);
    }

    global $wpdb;
    $labels_table = $wpdb->prefix . 'qp_labels';
    $question_labels_table = $wpdb->prefix . 'qp_question_labels';

    $label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM $labels_table WHERE label_name = %s", $report_label_name));

    if (!$label_id) {
        wp_send_json_error(['message' => 'Reporting system is not configured correctly. The "' . esc_html($report_label_name) . '" label does not exist.']);
    }

    // Check if this label is already assigned to prevent duplicates
    $already_assigned = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $question_labels_table WHERE question_id = %d AND label_id = %d",
        $question_id, $label_id
    ));

    if ($already_assigned == 0) {
        $wpdb->insert($question_labels_table, [
            'question_id' => $question_id,
            'label_id'    => $label_id
        ]);
    }

    wp_send_json_success(['message' => 'Issue reported.']);
}
add_action('wp_ajax_report_question_issue', 'qp_report_question_issue_ajax');

