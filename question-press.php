<?php

/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           1.5.0
 * Author:            Himanshu
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ABSPATH')) exit;

// Define constants and include files
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

require_once QP_PLUGIN_DIR . 'admin/class-qp-subjects-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-labels-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-topics-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-exams-page.php'; // <-- ADD THIS
require_once QP_PLUGIN_DIR . 'admin/class-qp-sources-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-import-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-importer.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-export-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-questions-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-question-editor-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-settings-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-logs-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-logs-list-table.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-shortcodes.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-dashboard.php';
require_once QP_PLUGIN_DIR . 'api/class-qp-rest-api.php';


// Activation, Deactivation, Uninstall Hooks
function qp_activate_plugin()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table: Subjects
    $table_subjects = $wpdb->prefix . 'qp_subjects';
    $sql_subjects = "CREATE TABLE $table_subjects (
        subject_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subject_name VARCHAR(255) NOT NULL,
        description TEXT,
        PRIMARY KEY (subject_id)
    ) $charset_collate;";
    dbDelta($sql_subjects);
    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_subjects WHERE subject_name = %s", 'Uncategorized')) == 0) {
        $wpdb->insert($table_subjects, ['subject_name' => 'Uncategorized', 'description' => 'Default subject for questions without an assigned one.']);
    }

    // *** NEW: Table for Topics ***
    $table_topics = $wpdb->prefix . 'qp_topics';
    $sql_topics = "CREATE TABLE $table_topics (
        topic_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        topic_name VARCHAR(255) NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (topic_id),
        KEY subject_id (subject_id)
    ) $charset_collate;";
    dbDelta($sql_topics);
    // --- NEW: Table for Exams ---
    $table_exams = $wpdb->prefix . 'qp_exams';
    $sql_exams = "CREATE TABLE $table_exams (
    exam_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_name VARCHAR(255) NOT NULL,
    PRIMARY KEY (exam_id)
) $charset_collate;";
    dbDelta($sql_exams);

    // --- NEW: Pivot Table for Exam-Subject relationship ---
    $table_exam_subjects = $wpdb->prefix . 'qp_exam_subjects';
    $sql_exam_subjects = "CREATE TABLE $table_exam_subjects (
        exam_id BIGINT(20) UNSIGNED NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (exam_id, subject_id),
        KEY subject_id (subject_id)
    ) $charset_collate;";
    dbDelta($sql_exam_subjects);

    // --- NEW: Table for Sources ---
    $table_sources = $wpdb->prefix . 'qp_sources';
    $sql_sources = "CREATE TABLE $table_sources (
    source_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_id BIGINT(20) UNSIGNED NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    description TEXT,
    PRIMARY KEY (source_id),
    KEY subject_id (subject_id)
) $charset_collate;";
    dbDelta($sql_sources);

    // --- NEW: Table for Source Sections ---
    $table_source_sections = $wpdb->prefix . 'qp_source_sections';
    $sql_source_sections = "CREATE TABLE $table_source_sections (
        section_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        source_id BIGINT(20) UNSIGNED NOT NULL,
        section_name VARCHAR(255) NOT NULL,
        PRIMARY KEY (section_id),
        KEY source_id (source_id)
    ) $charset_collate;";
    dbDelta($sql_source_sections);


    // Table: Labels
    $table_labels = $wpdb->prefix . 'qp_labels';
    $sql_labels = "CREATE TABLE $table_labels (
        label_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        label_name VARCHAR(255) NOT NULL,
        label_color VARCHAR(7) NOT NULL DEFAULT '#cccccc',
        is_default BOOLEAN NOT NULL DEFAULT 0,
        description TEXT,
        PRIMARY KEY (label_id)
    ) $charset_collate;";
    dbDelta($sql_labels);
    $default_labels = [
        ['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'],
        ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'],
        ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'],
        ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'],
        ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']
    ];
    foreach ($default_labels as $label) {
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name'])) == 0) {
            $wpdb->insert($table_labels, $label);
        }
    }

    // --- UPDATED: Question Groups table with PYQ columns ---
    $table_groups = $wpdb->prefix . 'qp_question_groups';
    $sql_groups = "CREATE TABLE $table_groups (
        group_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        direction_text LONGTEXT,
        direction_image_id BIGINT(20) UNSIGNED,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        is_pyq BOOLEAN NOT NULL DEFAULT 0,
        exam_id BIGINT(20) UNSIGNED DEFAULT NULL,
        pyq_year VARCHAR(4) DEFAULT NULL,
        PRIMARY KEY (group_id),
        KEY subject_id (subject_id),
        KEY is_pyq (is_pyq)
    ) $charset_collate;";
    dbDelta($sql_groups);

    // --- UPDATED: Questions table with PYQ columns removed ---
    $table_questions = $wpdb->prefix . 'qp_questions';
    $sql_questions = "CREATE TABLE $table_questions (
        question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        custom_question_id BIGINT(20) UNSIGNED,
        group_id BIGINT(20) UNSIGNED,
        topic_id BIGINT(20) UNSIGNED DEFAULT NULL,
        source_id BIGINT(20) UNSIGNED DEFAULT NULL,
        section_id BIGINT(20) UNSIGNED DEFAULT NULL,
        question_number_in_section VARCHAR(20) DEFAULT NULL,
        question_text LONGTEXT NOT NULL,
        question_text_hash VARCHAR(32) NOT NULL,
        duplicate_of BIGINT(20) UNSIGNED DEFAULT NULL,
        import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'publish',
        last_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (question_id),
        UNIQUE KEY custom_question_id (custom_question_id),
        KEY group_id (group_id),
        KEY topic_id (topic_id),
        KEY source_id (source_id),
        KEY section_id (section_id),
        KEY status (status),
        KEY question_text_hash (question_text_hash)
    ) $charset_collate;";
    dbDelta($sql_questions);

    // Table: Options
    $table_options = $wpdb->prefix . 'qp_options';
    $sql_options = "CREATE TABLE $table_options (
        option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        option_text TEXT NOT NULL,
        is_correct BOOLEAN NOT NULL DEFAULT 0,
        PRIMARY KEY (option_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_options);

    // Table: Question Labels
    $table_question_labels = $wpdb->prefix . 'qp_question_labels';
    $sql_question_labels = "CREATE TABLE $table_question_labels (
        question_id BIGINT(20) UNSIGNED NOT NULL,
        label_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (question_id, label_id),
        KEY label_id (label_id)
    ) $charset_collate;";
    dbDelta($sql_question_labels);

    // Table: User Sessions
    $table_sessions = $wpdb->prefix . 'qp_user_sessions';
    $sql_sessions = "CREATE TABLE $table_sessions (
        session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        end_time DATETIME,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        total_attempted INT,
        correct_count INT,
        incorrect_count INT,
        skipped_count INT,
        marks_obtained DECIMAL(10, 2),
        settings_snapshot TEXT,
        question_ids_snapshot LONGTEXT,
        PRIMARY KEY (session_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_sessions);

    // --- UPDATED: User Attempts table with selected_option_id ---
    $table_attempts = $wpdb->prefix . 'qp_user_attempts';
    $sql_attempts = "CREATE TABLE $table_attempts (
        attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        selected_option_id BIGINT(20) UNSIGNED,
        is_correct BOOLEAN,
        attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (attempt_id),
        KEY session_id (session_id),
        KEY user_id (user_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_attempts);

    // Table: Logs
    $table_logs = $wpdb->prefix . 'qp_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        log_type VARCHAR(50) NOT NULL,
        log_message TEXT NOT NULL,
        log_data LONGTEXT,
        log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (log_id),
        KEY log_type (log_type),
        KEY resolved (resolved)
    ) $charset_collate;";
    dbDelta($sql_logs);

    // Set default options
    add_option('qp_next_custom_question_id', 1000, '', 'no');
    if (!get_option('qp_jwt_secret_key')) {
        add_option('qp_jwt_secret_key', wp_generate_password(64, true, true), '', 'no');
    }
}

register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');


// In question-press.php, replace the existing qp_admin_menu function
function qp_admin_menu()
{
    // Add top-level menu page for "All Questions" and store the hook
    $hook = add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);

    // Use this hook to add screen options for the questions list table
    add_action("load-{$hook}", 'qp_add_screen_options');

    // Primary Submenu Pages
    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);

    // --- NEW: Unified Organization Page ---
    add_submenu_page('question-press', 'Organize', 'Organize', 'manage_options', 'qp-organization', 'qp_render_organization_page');

    add_submenu_page('question-press', 'Import', 'Import', 'manage_options', 'qp-import', ['QP_Import_Page', 'render']);
    add_submenu_page('question-press', 'Export', 'Export', 'manage_options', 'qp-export', ['QP_Export_Page', 'render']);
    add_submenu_page('question-press', 'Logs', 'Logs', 'manage_options', 'qp-logs', ['QP_Logs_Page', 'render']);
    add_submenu_page('question-press', 'Settings', 'Settings', 'manage_options', 'qp-settings', ['QP_Settings_Page', 'render']);

    // Hidden pages (for editing links and backwards compatibility)
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-edit-group', ['QP_Question_Editor_Page', 'render']);
}
add_action('admin_menu', 'qp_admin_menu');


function qp_render_organization_page()
{
    $tabs = [
        'subjects' => ['label' => 'Subjects', 'callback' => ['QP_Subjects_Page', 'render']],
        'topics'   => ['label' => 'Topics', 'callback' => ['QP_Topics_Page', 'render']],
        'labels'   => ['label' => 'Labels', 'callback' => ['QP_Labels_Page', 'render']],
        'exams'    => ['label' => 'Exams', 'callback' => ['QP_Exams_Page', 'render']],
        'sources'  => ['label' => 'Sources', 'callback' => ['QP_Sources_Page', 'render']],
    ];
    $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'subjects';
?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Organize</h1>
        <p>Organize you questions using different taxanomies here.</p>
        <hr class="wp-header-end">

        <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
            <?php
            foreach ($tabs as $tab_id => $tab_data) {
                $class = ($tab_id === $active_tab) ? ' nav-tab-active' : '';
                echo '<a href="?page=qp-organization&tab=' . esc_attr($tab_id) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($tab_data['label']) . '</a>';
            }
            ?>
        </nav>

        <div class="tab-content" style="margin-top: 1.5rem;">
            <?php
            // Call the render method for the active tab
            call_user_func($tabs[$active_tab]['callback']);
            ?>
        </div>
    </div>
<?php
}

// CORRECTED: Function to add screen options
function qp_add_screen_options()
{
    $option = 'per_page';
    $args = [
        'label'   => 'Questions per page',
        'default' => 20,
        'option'  => 'qp_questions_per_page'
    ];
    add_screen_option($option, $args);
    new QP_Questions_List_Table(); // Instantiate table to register columns
}

// CORRECTED: Function to save the screen options
function qp_save_screen_options($status, $option, $value)
{
    if ('qp_questions_per_page' === $option) {
        return $value;
    }
    return $status;
}
add_filter('set-screen-option', 'qp_save_screen_options', 10, 3);




function qp_admin_enqueue_scripts($hook_suffix)
{
    if (strpos($hook_suffix, 'qp-') !== false || strpos($hook_suffix, 'question-press') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
    if ($hook_suffix === 'question-press_page_qp-question-editor' || $hook_suffix === 'admin_page_qp-edit-group') {
        wp_enqueue_media();
        wp_enqueue_script('qp-media-uploader-script', QP_PLUGIN_URL . 'admin/assets/js/media-uploader.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('qp-editor-script', QP_PLUGIN_URL . 'admin/assets/js/question-editor.js', ['jquery'], '1.0.1', true);
    }
    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_PLUGIN_URL . 'admin/assets/js/quick-edit.js', ['jquery'], '1.0.1', true);
        wp_localize_script('qp-quick-edit-script', 'qp_quick_edit_object', [
            'save_nonce' => wp_create_nonce('qp_save_quick_edit_nonce')
        ]);
        wp_enqueue_script('qp-multi-select-dropdown-script', QP_PLUGIN_URL . 'admin/assets/js/multi-select-dropdown.js', ['jquery'], '1.0.1', true);
    }
    if ($hook_suffix === 'question-press_page_qp-labels') {
        add_action('admin_footer', function () {
            echo '<script>jQuery(document).ready(function($){$(".qp-color-picker").wpColorPicker();});</script>';
        });
    }

    // Load script for the Settings page
    if ($hook_suffix === 'question-press_page_qp-settings') {
        wp_enqueue_script('qp-settings-script', QP_PLUGIN_URL . 'admin/assets/js/settings-page.js', ['jquery'], '1.0.0', true);
    }
}
add_action('admin_enqueue_scripts', 'qp_admin_enqueue_scripts');

// FORM & ACTION HANDLERS
function qp_handle_form_submissions() {
    if (isset($_GET['page']) && $_GET['page'] === 'qp-organization') {
        QP_Sources_Page::handle_forms();
        QP_Topics_Page::handle_forms();
        QP_Subjects_Page::handle_forms();
        QP_Labels_Page::handle_forms();
        QP_Exams_Page::handle_forms();
    }
    QP_Export_Page::handle_export_submission();
    qp_handle_save_question_group();
    qp_handle_topic_forms();
    QP_Settings_Page::register_settings();
    qp_handle_clear_logs();
    qp_handle_resolve_log();
    // --- ADD THIS LINE to hook our new unified migration handler ---
    qp_run_unified_data_migration();
}
add_action('admin_init', 'qp_handle_form_submissions');

// *** ADD THIS ENTIRE NEW FUNCTION ***
function qp_handle_topic_forms()
{
    global $wpdb;
    $topics_table = $wpdb->prefix . 'qp_topics';

    // Handle Add Topic
    if (isset($_POST['add_topic']) && check_admin_referer('qp_add_topic_nonce')) {
        $topic_name = sanitize_text_field($_POST['topic_name']);
        $subject_id = absint($_POST['subject_id']);
        if (!empty($topic_name) && $subject_id > 0) {
            $wpdb->insert($topics_table, ['topic_name' => $topic_name, 'subject_id' => $subject_id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-topics'));
        exit;
    }

    // Handle Update Topic
    if (isset($_POST['update_topic']) && isset($_POST['topic_id']) && check_admin_referer('qp_update_topic_nonce')) {
        $topic_id = absint($_POST['topic_id']);
        $topic_name = sanitize_text_field($_POST['topic_name']);
        $subject_id = absint($_POST['subject_id']);
        if (!empty($topic_name) && $subject_id > 0) {
            $wpdb->update($topics_table, ['topic_name' => $topic_name, 'subject_id' => $subject_id], ['topic_id' => $topic_id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-topics'));
        exit;
    }

    // Handle Delete Topic
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['topic_id'])) {
        $topic_id = absint($_GET['topic_id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_delete_topic_' . $topic_id)) {
            $wpdb->delete($topics_table, ['topic_id' => $topic_id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-topics'));
        exit;
    }
}

function qp_all_questions_page_cb()
{
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
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
            <?php $list_table->search_box('Search Questions', 'question');
            $list_table->display(); ?>
        </form>
        <style type="text/css">
            .wp-list-table .column-custom_question_id {
                width: 10%;
            }

            .wp-list-table .column-question_text {
                width: 50%;
            }

            .wp-list-table .column-subject_name {
                width: 15%;
            }

            .wp-list-table .column-source {
                width: 15%;
            }

            .wp-list-table .column-import_date {
                width: 10%;
            }

            .wp-list-table.questions #the-list tr td {
                border-bottom: 1px solid rgb(174, 174, 174);
            }
        </style>
    </div>
<?php
}

// ADD THIS NEW HELPER FUNCTION anywhere in question-press.php
function get_question_custom_id($question_id)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
}


function qp_handle_save_question_group() {
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
        return;
    }

    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;

    // --- Get group-level data from the form ---
    $direction_text = isset($_POST['direction_text']) ? stripslashes($_POST['direction_text']) : '';
    $direction_image_id = absint($_POST['direction_image_id']);
    $subject_id = absint($_POST['subject_id']);
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
    $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
    $section_id = isset($_POST['section_id']) ? absint($_POST['section_id']) : 0;
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $exam_id = isset($_POST['exam_id']) ? absint($_POST['exam_id']) : 0;
    $pyq_year = isset($_POST['pyq_year']) ? sanitize_text_field($_POST['pyq_year']) : '';

    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($subject_id) || empty($questions_from_form)) { return; }

    // --- Save Group Data ---
    $group_data = [
        'direction_text' => sanitize_textarea_field($direction_text),
        'direction_image_id' => $direction_image_id,
        'subject_id' => $subject_id,
        'is_pyq' => $is_pyq,
        'exam_id' => $is_pyq && $exam_id > 0 ? $exam_id : null,
        'pyq_year' => $is_pyq ? $pyq_year : null,
    ];

    if ($is_editing) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", $group_data, ['group_id' => $group_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}qp_question_groups", $group_data);
        $group_id = $wpdb->insert_id;
    }

    // --- Process Individual Questions ---
    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $ql_table = "{$wpdb->prefix}qp_question_labels";
    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
    $submitted_q_ids = [];

    foreach ($questions_from_form as $q_data) {
        $question_text = isset($q_data['question_text']) ? stripslashes($q_data['question_text']) : '';
        if (empty(trim($question_text))) continue;

        $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
        
        // --- CORRECTED: Get question number from the individual question data array ---
        $question_num = isset($q_data['question_number_in_section']) ? sanitize_text_field($q_data['question_number_in_section']) : '';

        $question_db_data = [
            'group_id' => $group_id,
            'topic_id' => $topic_id > 0 ? $topic_id : null,
            'source_id' => $source_id > 0 ? $source_id : null,
            'section_id' => $section_id > 0 ? $section_id : null,
            'question_number_in_section' => $question_num, // Save the individual number
            'question_text' => sanitize_textarea_field($question_text),
            'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
        ];

        if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
            $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
            $submitted_q_ids[] = $question_id;
        } else {
            // Insert new question
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);
            $question_db_data['custom_question_id'] = $next_custom_id;
            $wpdb->insert($q_table, $question_db_data);
            $question_id = $wpdb->insert_id;
            update_option('qp_next_custom_question_id', $next_custom_id + 1);
            $submitted_q_ids[] = $question_id;
        }

        // --- Process Options ---
        $wpdb->delete($o_table, ['question_id' => $question_id]);
        $options = isset($q_data['options']) ? (array)$q_data['options'] : [];
        $correct_option_index = isset($q_data['is_correct_option']) ? absint($q_data['is_correct_option']) : -1;
        foreach ($options as $index => $option_text) {
            if (!empty(trim($option_text))) {
                $wpdb->insert($o_table, [
                    'question_id' => $question_id,
                    'option_text' => sanitize_text_field(stripslashes($option_text)),
                    'is_correct' => ($index === $correct_option_index) ? 1 : 0
                ]);
            }
        }

        // --- Process Labels ---
        $wpdb->delete($ql_table, ['question_id' => $question_id]);
        $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
        foreach ($labels as $label_id) {
            $wpdb->insert($ql_table, ['question_id' => $question_id, 'label_id' => $label_id]);
        }
    }

    // --- Clean up removed questions ---
    $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
    if (!empty($questions_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $questions_to_delete));
        $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $ql_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
    }

    // --- Delete the group if it becomes empty ---
    if ($is_editing && empty($submitted_q_ids)) {
        $wpdb->delete("{$wpdb->prefix}qp_question_groups", ['group_id' => $group_id]);
        wp_safe_redirect(admin_url('admin.php?page=question-press&message=1'));
        exit;
    }

    // --- Redirect on success ---
    $redirect_url = $is_editing ? admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1') : admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');
    wp_safe_redirect($redirect_url);
    exit;
}

// Public-facing hooks and AJAX handlers
function qp_public_init()
{
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);

    add_shortcode('question_press_session', ['QP_Shortcodes', 'render_session_page']);
}
add_action('init', 'qp_public_init');

function qp_public_enqueue_scripts() {
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard') || has_shortcode($post->post_content, 'question_press_session'))) {
        
        $css_file_path = QP_PLUGIN_DIR . 'public/assets/css/practice.css';
        $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : '1.0.0';
        wp_enqueue_style('qp-practice-styles', QP_PLUGIN_URL . 'public/assets/css/practice.css', [], $css_version);

        $options = get_option('qp_settings');
        $ajax_data = [
            'ajax_url'           => admin_url('admin-ajax.php'), 
            'nonce'              => wp_create_nonce('qp_practice_nonce'),
            'dashboard_page_url' => isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/'),
            'practice_page_url'  => isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/')
        ];

        // --- CORRECTED SCRIPT ENQUEUEING AND LOCALIZATION ---
        if (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_session')) {
            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery'], filemtime(QP_PLUGIN_DIR . 'public/assets/js/practice.js'), true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
            
            // If we are on the session page, get the data from the shortcode class and localize it
            if (has_shortcode($post->post_content, 'question_press_session')) {
                $session_data = QP_Shortcodes::get_session_data_for_script();
                if ($session_data) {
                    wp_localize_script('qp-practice-script', 'qp_session_data', $session_data);
                }
            }
        }
        
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('qp-dashboard-script', QP_PLUGIN_URL . 'public/assets/js/dashboard.js', ['jquery'], filemtime(QP_PLUGIN_DIR . 'public/assets/js/dashboard.js'), true);
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }
    }
}
add_action('wp_enqueue_scripts', 'qp_public_enqueue_scripts');


// *** ADD THIS ENTIRE NEW FUNCTION ***
function qp_get_topics_for_subject_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

    if (!$subject_id) {
        wp_send_json_error(['message' => 'Invalid subject ID.']);
    }

    global $wpdb;
    $topics_table = $wpdb->prefix . 'qp_topics';
    $topics = $wpdb->get_results($wpdb->prepare("SELECT topic_id, topic_name FROM $topics_table WHERE subject_id = %d ORDER BY topic_name ASC", $subject_id));

    wp_send_json_success(['topics' => $topics]);
}
add_action('wp_ajax_get_topics_for_subject', 'qp_get_topics_for_subject_ajax');

function qp_start_practice_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $settings_str = isset($_POST['settings']) ? $_POST['settings'] : '';
    $form_settings = [];
    parse_str($settings_str, $form_settings);

    $session_settings = [
        'subject_id'      => isset($form_settings['qp_subject']) ? $form_settings['qp_subject'] : '',
        'topic_id'        => isset($form_settings['qp_topic']) ? $form_settings['qp_topic'] : 'all',
        'sheet_label_id'  => isset($form_settings['qp_sheet_label']) ? $form_settings['qp_sheet_label'] : 'all',
        'pyq_only'        => isset($form_settings['qp_pyq_only']),
        'revise_mode'     => isset($form_settings['qp_revise_mode']),
        'marks_correct'   => isset($form_settings['qp_marks_correct']) ? floatval($form_settings['qp_marks_correct']) : 4.0,
        'marks_incorrect' => isset($form_settings['qp_marks_incorrect']) ? -abs(floatval($form_settings['qp_marks_incorrect'])) : -1.0,
        'timer_enabled'   => isset($form_settings['qp_timer_enabled']),
        'timer_seconds'   => isset($form_settings['qp_timer_seconds']) ? absint($form_settings['qp_timer_seconds']) : 60
    ];

    if (empty($session_settings['subject_id'])) { wp_send_json_error(['message' => 'Please select a subject.']); }

    global $wpdb;
    $user_id = get_current_user_id();
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $l_table = $wpdb->prefix . 'qp_labels';
    $ql_table = $wpdb->prefix . 'qp_question_labels';

    $base_where_clauses = ["q.status = 'publish'"];
    $query_args = [];
    $joins = "LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

    $review_label_ids = $wpdb->get_col("SELECT label_id FROM $l_table WHERE label_name IN ('Wrong Answer', 'No Answer')");
    if (!empty($review_label_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($review_label_ids), '%d'));
        $base_where_clauses[] = "q.question_id NOT IN (SELECT question_id FROM $ql_table WHERE label_id IN ($ids_placeholder))";
        $query_args = array_merge($query_args, $review_label_ids);
    }

    if ($session_settings['subject_id'] !== 'all') {
        $base_where_clauses[] = "g.subject_id = %d";
        $query_args[] = absint($session_settings['subject_id']);
    }

    if ($session_settings['topic_id'] !== 'all' && is_numeric($session_settings['topic_id'])) {
        $base_where_clauses[] = "q.topic_id = %d";
        $query_args[] = absint($session_settings['topic_id']);
    }

    if ($session_settings['sheet_label_id'] !== 'all' && is_numeric($session_settings['sheet_label_id'])) {
        $joins .= " JOIN {$ql_table} ql ON q.question_id = ql.question_id";
        $base_where_clauses[] = "ql.label_id = %d";
        $query_args[] = absint($session_settings['sheet_label_id']);
    }

    // --- CORRECTED: PYQ check now queries the groups table ---
    if ($session_settings['pyq_only']) {
        $base_where_clauses[] = "g.is_pyq = 1";
    }

    $base_where_sql = implode(' AND ', $base_where_clauses);

    $final_where_clauses = $base_where_clauses;
    if ($session_settings['revise_mode']) {
        $final_where_clauses[] = $wpdb->prepare("q.question_id IN (SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d)", $user_id);
    } else {
        $final_where_clauses[] = $wpdb->prepare("q.question_id NOT IN (SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d)", $user_id);
    }

    $final_where_sql = implode(' AND ', $final_where_clauses);

    $options = get_option('qp_settings');
    $question_order = isset($options['question_order']) ? $options['question_order'] : 'random';
    $order_by_sql = ($question_order === 'in_order') ? 'ORDER BY q.custom_question_id ASC' : 'ORDER BY RAND()';

    $query = "SELECT DISTINCT q.question_id FROM {$q_table} q {$joins} WHERE {$final_where_sql} {$order_by_sql}";

    $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

    if (empty($question_ids)) {
        // ... (logic to determine $error_code remains the same)
        $error_html = '';
        if ($error_code === 'ALL_ATTEMPTED') {
            $error_html = '<div class="qp-practice-form-wrapper" style="text-align: center; padding: 40px 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><h2 style="margin-top:0; font-size: 22px;">You\'ve Mastered It!</h2><p style="font-size: 16px; color: #555; margin-bottom: 25px;">You have attempted all available questions for this criteria. Try Revision Mode or different settings.</p><button id="qp-go-back-btn" class="qp-button qp-button-secondary">Back to Form</button></div>';
        } else if ($error_code === 'NO_REVISION_QUESTIONS') {
             $error_html = '<div class="qp-practice-form-wrapper" style="text-align: center; padding: 40px 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><h2 style="margin-top:0; font-size: 22px;">Nothing to Revise Yet!</h2><p style="font-size: 16px; color: #555; margin-bottom: 25px;">You haven\'t attempted any questions matching this criteria yet. Try a regular practice session first.</p><button id="qp-go-back-btn" class="qp-button qp-button-primary">Back to Practice Form</button></div>';
        } else { // NO_QUESTIONS_EXIST
            $error_html = '<div class="qp-practice-form-wrapper" style="text-align: center; padding: 40px 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><h2 style="margin-top:0; font-size: 22px;">Fresh Questions Coming Soon!</h2><p style="font-size: 16px; color: #555; margin-bottom: 25px;">No questions were found matching your criteria. Please try different options.</p><button id="qp-go-back-btn" class="qp-button qp-button-secondary">Back to Practice Form</button></div>';
        }
        wp_send_json_error(['html' => $error_html]);
    }

    // Get the Session Page URL from settings
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    global $wpdb;
    // Create the session in the database
    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => get_current_user_id(),
        'status'                  => 'active',
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    // Prepare the data to be passed to the next page
    $session_data = [
        'session_id'    => $session_id,
        'question_ids'  => $question_ids,
        'settings'      => $session_settings,
    ];

    // Store this data in a transient (a temporary cached entry) that expires in 5 minutes
    // The key is unique to the session ID
    set_transient('qp_session_' . $session_id, $session_data, 5 * MINUTE_IN_SECONDS);

    // Build the redirect URL
    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));

    // Send the URL back to the JavaScript
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_start_practice_session', 'qp_start_practice_session_ajax');

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // We can re-use the function from our shortcode class to get the form HTML
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}
add_action('wp_ajax_get_practice_form_html', 'qp_get_practice_form_html_ajax');

// In question-press.php, REPLACE this function

function qp_get_question_data_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) { wp_send_json_error(['message' => 'Invalid Question ID.']); }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $s_table = $wpdb->prefix . 'qp_subjects';
    $t_table = $wpdb->prefix . 'qp_topics';
    $src_table = $wpdb->prefix . 'qp_sources';
    $sec_table = $wpdb->prefix . 'qp_source_sections';
    $o_table = $wpdb->prefix . 'qp_options';
    $a_table = $wpdb->prefix . 'qp_user_attempts';

    // --- CORRECTED QUERY: Fetching from new source/section tables directly ---
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT q.custom_question_id, q.question_text, q.question_number_in_section,
                g.direction_text, g.direction_image_id,
                s.subject_name, t.topic_name,
                src.source_name,
                sec.section_name
         FROM {$q_table} q
         LEFT JOIN {$g_table} g ON q.group_id = g.group_id
         LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id
         LEFT JOIN {$t_table} t ON q.topic_id = t.topic_id
         LEFT JOIN {$src_table} src ON q.source_id = src.source_id
         LEFT JOIN {$sec_table} sec ON q.section_id = sec.section_id
         WHERE q.question_id = %d",
        $question_id
    ), ARRAY_A);

    if (!$question_data) { wp_send_json_error(['message' => 'Question not found.']); }

    $options = get_option('qp_settings');
    $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
    $user = wp_get_current_user();
    $user_can_view = !empty(array_intersect((array)$user->roles, (array)$allowed_roles));

    if (!$user_can_view) {
        unset($question_data['source_name']);
        unset($question_data['section_name']);
        unset($question_data['question_number_in_section']);
    }

    $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;
    $question_data['options'] = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY RAND()", $question_id), ARRAY_A);
    $user_id = get_current_user_id();
    $attempt_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $a_table WHERE user_id = %d AND question_id = %d", $user_id, $question_id));

    wp_send_json_success(['question' => $question_data, 'is_revision' => ($attempt_count > 0), 'is_admin' => $user_can_view]);
}
add_action('wp_ajax_get_question_data', 'qp_get_question_data_ajax');

function qp_check_answer_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;
    if (!$session_id || !$question_id || !$option_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }

    global $wpdb;
    $o_table = $wpdb->prefix . 'qp_options';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    
    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));

    // --- UPDATED: Save the selected option ID along with the attempt ---
    $wpdb->insert($attempts_table, [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'selected_option_id' => $option_id,
        'is_correct' => $is_correct ? 1 : 0
    ]);
    
    wp_send_json_success(['is_correct' => $is_correct, 'correct_option_id' => $correct_option_id]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

// function qp_skip_question_ajax() {
//     check_ajax_referer('qp_practice_nonce', 'nonce');
//     $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
//     $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
//     if (!$session_id || !$question_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }
//     global $wpdb;
//     $attempts_table = $wpdb->prefix . 'qp_user_attempts';
//     $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => null]);
//     wp_send_json_success();
// }
// add_action('wp_ajax_skip_question', 'qp_skip_question_ajax');


/**
 * AJAX handler for ending a practice session.
 */
function qp_end_practice_session_ajax()
{
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
    $wpdb->update(
        $sessions_table,
        [
            'end_time' => current_time('mysql', 1),
            'status' => 'completed',
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
function qp_delete_user_session_ajax()
{
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
// UPDATED: Report issue function
function qp_report_question_issue_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $report_label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : 'Incorrect Formatting';
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID.']);
    }

    global $wpdb;
    $label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name = %s", $report_label_name));
    if (!$label_id) {
        wp_send_json_error(['message' => 'Reporting system not configured.']);
    }

    $already_assigned = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_question_labels WHERE question_id = %d AND label_id = %d", $question_id, $label_id));
    if ($already_assigned == 0) {
        $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => $label_id]);

        // CORRECTED: Use custom_question_id for the log message
        $custom_id = $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
        $wpdb->insert("{$wpdb->prefix}qp_logs", [
            'log_type' => 'Report',
            'log_message' => sprintf('User reported question #%s with label: %s', $custom_id, $report_label_name),
            'log_data' => wp_json_encode(['user_id' => get_current_user_id(), 'question_id' => $question_id])
        ]);
    }
    wp_send_json_success(['message' => 'Issue reported.']);
}
add_action('wp_ajax_report_question_issue', 'qp_report_question_issue_ajax');


// In question-press.php, ADD this new function

// UPDATED: Report and Skip function
function qp_report_and_skip_question_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : '';
    if (!$session_id || !$question_id || !$label_name) {
        wp_send_json_error(['message' => 'Invalid data.']);
    }

    global $wpdb;
    $label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name = %s", $label_name));
    if ($label_id) {
        $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => $label_id]);

        // CORRECTED: Use custom_question_id for the log message
        $custom_id = $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
        $wpdb->insert("{$wpdb->prefix}qp_logs", [
            'log_type' => 'Report',
            'log_message' => sprintf('User reported question #%s with label: %s', $custom_id, $label_name),
            'log_data' => wp_json_encode(['user_id' => get_current_user_id(), 'session_id' => $session_id, 'question_id' => $question_id])
        ]);
    }

    $wpdb->insert("{$wpdb->prefix}qp_user_attempts", ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => null]);
    wp_send_json_success(['message' => 'Question reported and skipped.']);
}
add_action('wp_ajax_report_and_skip_question', 'qp_report_and_skip_question_ajax');


/**
 * AJAX handler for deleting a user's entire revision and session history.
 */
function qp_delete_revision_history_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to do this.']);
    }

    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // Delete all rows from the attempts table for the current user.
    $wpdb->delete($attempts_table, ['user_id' => $user_id], ['%d']);

    // ALSO, delete all rows from the sessions table for the current user.
    $wpdb->delete($sessions_table, ['user_id' => $user_id], ['%d']);

    wp_send_json_success(['message' => 'Your practice and revision history has been successfully deleted.']);
}
add_action('wp_ajax_delete_revision_history', 'qp_delete_revision_history_ajax');

// NEW: Function to handle clearing the logs
function qp_handle_clear_logs()
{
    if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_clear_logs_nonce')) {
            wp_die('Security check failed.');
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_logs';
        $wpdb->query("TRUNCATE TABLE $table_name");
        wp_safe_redirect(admin_url('admin.php?page=qp-logs&message=1'));
        exit;
    }
}

// ADD this new function to the file
function qp_handle_resolve_log()
{
    if (isset($_GET['action']) && $_GET['action'] === 'resolve_log' && isset($_GET['log_id'])) {
        $log_id = absint($_GET['log_id']);
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qp_resolve_log_' . $log_id)) {
            wp_die('Security check failed.');
        }
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}qp_logs", ['resolved' => 1], ['log_id' => $log_id]);

        // Redirect back to the page the user was on
        wp_safe_redirect(remove_query_arg(['action', 'log_id', '_wpnonce']));
        exit;
    }
}


function qp_get_quick_edit_form_ajax()
{
    check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error();
    }

    global $wpdb;
    // ... (database queries and data preparation remain the same) ...
    $question = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_text, q.is_pyq, q.topic_id, g.subject_id 
         FROM {$wpdb->prefix}qp_questions q 
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id 
         WHERE q.question_id = %d",
        $question_id
    ));
    $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
    $all_topics = $wpdb->get_results("SELECT topic_id, topic_name, subject_id FROM {$wpdb->prefix}qp_topics ORDER BY topic_name ASC");
    $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
    $current_labels = $wpdb->get_col($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_question_labels WHERE question_id = %d", $question_id));
    $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC", $question_id));

    $topics_by_subject = [];
    foreach ($all_topics as $topic) {
        if (!isset($topics_by_subject[$topic->subject_id])) {
            $topics_by_subject[$topic->subject_id] = [];
        }
        $topics_by_subject[$topic->subject_id][] = ['id' => $topic->topic_id, 'name' => $topic->topic_name];
    }

    ob_start();
?>
    <script>
        var qp_quick_edit_topics_data = <?php echo json_encode($topics_by_subject); ?>;
        var qp_current_topic_id = <?php echo json_encode($question->topic_id); ?>;
    </script>
    <form class="quick-edit-form-wrapper">
        <h4><span style="font-weight: 700;">Question:</span> <span class="title"><?php echo esc_html(wp_trim_words($question->question_text, 10, '...')); ?></span></h4>
        <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">

        <div class="quick-edit-main-container">
            <div class="quick-edit-col-left">
                <label><strong>Correct Answer</strong></label>
                <div class="options-group">
                    <?php foreach ($options as $index => $option): ?>
                        <label class="option-label">
                            <input type="radio" name="correct_option_id" value="<?php echo esc_attr($option->option_id); ?>" <?php checked($option->is_correct, 1); ?>>
                            <input type="text" readonly value="<?php echo esc_attr($option->option_text); ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="quick-edit-col-right">
                <div class="form-row-flex">
                    <div class="form-group-half">
                        <label for="qe-subject-<?php echo esc_attr($question_id); ?>"><strong>Subject</strong></label>
                        <select name="subject_id" id="qe-subject-<?php echo esc_attr($question_id); ?>" class="qe-subject-select">
                            <?php foreach ($all_subjects as $subject) : ?>
                                <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $question->subject_id); ?>><?php echo esc_html($subject->subject_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-half">
                        <label for="qe-topic-<?php echo esc_attr($question_id); ?>"><strong>Topic</strong></label>
                        <select name="topic_id" id="qe-topic-<?php echo esc_attr($question_id); ?>" class="qe-topic-select" disabled>
                            <option value="">— Select subject first —</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <label class="inline-checkbox"><input type="checkbox" name="is_pyq" value="1" <?php checked($question->is_pyq, 1); ?>> Is PYQ?</label>
                </div>
                <div class="form-row">
                    <label><strong>Labels</strong></label>
                    <div class="labels-group">
                        <?php foreach ($all_labels as $label) : ?>
                            <label class="inline-checkbox"><input type="checkbox" name="labels[]" value="<?php echo esc_attr($label->label_id); ?>" <?php checked(in_array($label->label_id, $current_labels)); ?>> <?php echo esc_html($label->label_name); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <p class="submit inline-edit-save">
            <button type="button" class="button-secondary cancel">Cancel</button>
            <button type="button" class="button-primary save">Update</button>
        </p>
    </form>

    <style>
        .quick-edit-form-wrapper h4 {
            font-size: 16px;
            /* Increased font size for visibility */
            margin-top: 20px;
            margin-bottom: 10px;
            Padding: 10px 20px;
        }

        .inline-edit-row .submit {
            padding: 20px;
        }

        .quick-edit-form-wrapper .title {
            font-size: 15px;
            /* Also increased */
            font-weight: 500;
            color: #555;
        }

        .quick-edit-form-wrapper .form-row,
        .quick-edit-form-wrapper .form-row-flex {
            margin-bottom: 1rem;
        }

        .quick-edit-form-wrapper .form-row:last-child {
            margin-bottom: 0;
        }

        .quick-edit-form-wrapper strong,
        .quick-edit-form-wrapper label {
            font-weight: 600;
            display: block;
            margin-bottom: .5rem;
        }

        .quick-edit-form-wrapper select {
            width: 100%;
        }

        .quick-edit-main-container {
            display: flex;
            gap: 20px;
            margin-bottom: 1rem;
            padding: 0px 20px;
        }

        .quick-edit-col-left {
            flex: 0 0 40%;
        }

        .quick-edit-col-right {
            flex: 1;
        }

        .options-group {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: space-between;
            padding: .5rem;
            border: 1px solid #ddd;
            background: #fff;
            height: 100%;
            box-sizing: border-box;
        }

        .options-group label:last-child {
            margin-bottom: 0;
        }

        .option-label {
            display: flex;
            align-items: center;
            /* Vertically aligns the radio button and text */
            gap: .5rem;
            margin-bottom: .5rem;
        }

        /* Crucial fix for radio button alignment */
        .option-label input[type="radio"] {
            margin-top: 0;
            /* Resets default WordPress top margin on radio buttons */
            align-self: center;
        }

        .option-label input[type="text"] {
            width: 90%;
            background-color: #f0f0f1;
        }

        .form-row-flex {
            display: flex;
            gap: 1rem;
        }

        .form-group-half {
            flex: 1;
        }

        .quick-edit-form-wrapper p.submit button.button-secondary {
            margin-right: 10px;
        }

        .labels-group {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem 1rem;
            padding: .5rem;
            border: 1px solid #ddd;
            background: #fff;
        }

        .inline-checkbox {
            white-space: nowrap;
        }
    </style>
    <?php
    wp_send_json_success(['form' => ob_get_clean()]);
}
add_action('wp_ajax_get_quick_edit_form', 'qp_get_quick_edit_form_ajax');

function qp_save_quick_edit_data_ajax()
{
    check_ajax_referer('qp_save_quick_edit_nonce', 'nonce');

    parse_str($_POST['form_data'], $data);

    $question_id = isset($data['question_id']) ? absint($data['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID in form data.']);
    }

    global $wpdb;

    $wpdb->update("{$wpdb->prefix}qp_questions", [
        'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
        'topic_id' => isset($data['topic_id']) && $data['topic_id'] > 0 ? absint($data['topic_id']) : null,
        'last_modified' => current_time('mysql')
    ], ['question_id' => $question_id]);

    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
    if ($group_id) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", ['subject_id' => absint($data['subject_id'])], ['group_id' => $group_id]);
    }

    $correct_option_id = isset($data['correct_option_id']) ? absint($data['correct_option_id']) : 0;
    if ($correct_option_id) {
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 0], ['question_id' => $question_id]);
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 1], ['option_id' => $correct_option_id, 'question_id' => $question_id]);
    }

    $wpdb->delete("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id]);
    if (!empty($data['labels'])) {
        foreach ($data['labels'] as $label_id) {
            $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => absint($label_id)]);
        }
    }

    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
    $found_item = null;
    foreach ($list_table->items as $item) {
        if ($item['question_id'] == $question_id) {
            $found_item = $item;
            break;
        }
    }

    if ($found_item) {
        $found_item['group_id'] = $group_id;
        ob_start();
        $list_table->single_row($found_item);
        $row_html = ob_get_clean();
        wp_send_json_success(['row_html' => $row_html]);
    }

    wp_send_json_error(['message' => 'Could not retrieve updated row.']);
}
add_action('wp_ajax_save_quick_edit_data', 'qp_save_quick_edit_data_ajax');


/**
 * Initialize all plugin features that hook into WordPress.
 */
function qp_init_plugin()
{
    QP_Rest_Api::init();
}
add_action('init', 'qp_init_plugin');

// NEW: Add this function to the end of the file
function qp_regenerate_api_key_ajax()
{
    check_ajax_referer('qp_regenerate_api_key_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $new_key = wp_generate_password(64, true, true);
    update_option('qp_jwt_secret_key', $new_key);

    wp_send_json_success(['new_key' => $new_key]);
}
add_action('wp_ajax_regenerate_api_key', 'qp_regenerate_api_key_ajax');


function qp_admin_head_styles_for_list_table()
{
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_question-press') {
    ?>
        <style type="text/css">
            .qp-multi-select-dropdown {
                position: relative;
                display: inline-block;
                vertical-align: middle;
            }

            .qp-multi-select-list {
                display: none;
                position: absolute;
                background-color: white;
                border: 1px solid #ccc;
                z-index: 1000;
                padding: 10px;
                max-height: 250px;
                overflow-y: auto;
            }

            .qp-multi-select-list label {
                display: block;
                white-space: nowrap;
                padding: 5px;
            }

            .qp-multi-select-list label:hover {
                background-color: #f1f1f1;
            }
        </style>
    <?php
    }
}
add_action('admin_head', 'qp_admin_head_styles_for_list_table');

/**
 * AJAX handler to get "Sheet" labels relevant to a specific topic.
 */
function qp_get_sheets_for_topic_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

    if (!$topic_id) {
        wp_send_json_success(['labels' => []]); // Send empty array if no topic is selected
        return;
    }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $l_table = $wpdb->prefix . 'qp_labels';
    $ql_table = $wpdb->prefix . 'qp_question_labels';

    // This query finds labels that start with "Sheet" AND are linked to at least one question with the given topic_id.
    $sheet_labels = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT l.label_id, l.label_name
         FROM {$l_table} l
         JOIN {$ql_table} ql ON l.label_id = ql.label_id
         JOIN {$q_table} q ON ql.question_id = q.question_id
         WHERE l.label_name LIKE %s
         AND q.topic_id = %d
         ORDER BY l.label_name ASC",
        'Sheet%',
        $topic_id
    ));

    wp_send_json_success(['labels' => $sheet_labels]);
}
add_action('wp_ajax_get_sheets_for_topic', 'qp_get_sheets_for_topic_ajax');


/**
 * Handles the complete, on-demand data migration and cleanup process.
 * This is safe to run multiple times, as each step checks for completion.
 */
function qp_run_unified_data_migration() {
    // Check if the trigger is present in the URL and if the user has permission
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_unified_migration' || !current_user_can('manage_options')) {
        return;
    }

    // Security check
    check_admin_referer('qp_unified_migration_nonce');

    global $wpdb;
    $messages = [];
    $questions_table = $wpdb->prefix . 'qp_questions';
    $groups_table = $wpdb->prefix . 'qp_question_groups';
    $sources_table = $wpdb->prefix . 'qp_sources';
    $subjects_table = $wpdb->prefix . 'qp_subjects';

    // === STEP 1: MIGRATE LEGACY source_file to qp_sources TABLE ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'source_file'")) {
        $old_source_files = $wpdb->get_col("SELECT DISTINCT source_file FROM {$questions_table} WHERE source_file IS NOT NULL AND source_file != ''");
        if (!empty($old_source_files)) {
            $migrated_source_count = 0;
            foreach ($old_source_files as $source_file_name) {
                $existing_source_id = $wpdb->get_var($wpdb->prepare("SELECT source_id FROM {$sources_table} WHERE source_name = %s", $source_file_name));
                if (null === $existing_source_id) {
                    $wpdb->insert($sources_table, ['source_name' => $source_file_name, 'description' => 'Migrated from old source file.', 'subject_id' => 0]);
                    $new_source_id = $wpdb->insert_id;
                    $migrated_source_count++;
                } else {
                    $new_source_id = $existing_source_id;
                }
                $wpdb->update($questions_table, ['source_id' => $new_source_id], ['source_file' => $source_file_name]);
            }
            if ($migrated_source_count > 0) $messages[] = "Step 1: Created {$migrated_source_count} new entries in the Sources table from legacy data.";
        }
    }

    // === STEP 2: ASSIGN ORPHANED SOURCES TO "UNCATEGORIZED" SUBJECT ===
    $uncategorized_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM {$subjects_table} WHERE subject_name = %s", 'Uncategorized'));
    if ($uncategorized_id) {
        $updated_rows = $wpdb->update($sources_table, ['subject_id' => $uncategorized_id], ['subject_id' => 0]);
        if ($updated_rows > 0) $messages[] = "Step 2: Assigned {$updated_rows} orphaned sources to the 'Uncategorized' subject.";
    }

    // === STEP 3: MIGRATE LEGACY source_number TO question_number_in_section ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'source_number'")) {
        $questions_to_migrate_num = $wpdb->get_results("SELECT question_id, source_number FROM {$questions_table} WHERE source_number IS NOT NULL AND (question_number_in_section IS NULL OR question_number_in_section = '')");
        if (!empty($questions_to_migrate_num)) {
            foreach ($questions_to_migrate_num as $q) {
                $wpdb->update($questions_table, ['question_number_in_section' => $q->source_number], ['question_id' => $q->question_id]);
            }
            $messages[] = "Step 3: Migrated question numbers for " . count($questions_to_migrate_num) . " questions.";
        }
    }
    
    // === STEP 4 (IMPROVED): MIGRATE PYQ STATUS TO GROUPS ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'is_pyq'")) {
        $groups_to_check = $wpdb->get_col("SELECT group_id FROM {$groups_table} WHERE is_pyq = 0");
        $migrated_groups_count = 0;
        if (!empty($groups_to_check)) {
            foreach ($groups_to_check as $group_id) {
                $is_legacy_pyq = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$questions_table} WHERE group_id = %d AND is_pyq = 1", $group_id
                ));
                
                if ($is_legacy_pyq > 0) {
                    $wpdb->update($groups_table, ['is_pyq' => 1], ['group_id' => $group_id]);
                    $migrated_groups_count++;
                }
            }
            if ($migrated_groups_count > 0) $messages[] = "Step 4: Updated PYQ status for {$migrated_groups_count} question group(s).";
        }
    }

    // === STEP 5 (IMPROVED): ROBUST DATABASE CLEANUP ===
    $columns_to_drop = ['source_file', 'source_page', 'source_number', 'is_pyq', 'exam_id', 'pyq_year'];
    $dropped_columns = [];
    foreach ($columns_to_drop as $column) {
        // Check if the column exists before trying to drop it
        if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE '{$column}'")) {
            $wpdb->query("ALTER TABLE {$questions_table} DROP COLUMN {$column};");
            $dropped_columns[] = "<code>{$column}</code>";
        }
    }
    if (!empty($dropped_columns)) {
        $messages[] = "Step 5: Finalized cleanup by removing old columns: " . implode(', ', $dropped_columns) . " from the questions table.";
    }
    
    // --- Final Redirect ---
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (empty($messages)) {
            $_SESSION['qp_admin_message'] = 'Database is already fully up-to-date. No migration steps were needed.';
            $_SESSION['qp_admin_message_type'] = 'info';
        } else {
            $_SESSION['qp_admin_message'] = '<strong>Migration & Cleanup Report:</strong><br> - ' . implode('<br> - ', $messages);
            $_SESSION['qp_admin_message_type'] = 'success';
        }
    }
    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}