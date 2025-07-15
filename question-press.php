<?php

/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           2.3.4
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
require_once QP_PLUGIN_DIR . 'admin/class-qp-logs-reports-page.php';
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
        start_time DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        last_activity DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
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
    status VARCHAR(20) NOT NULL DEFAULT 'answered',
    remaining_time INT,
    attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (attempt_id),
    KEY session_id (session_id),
    KEY user_id (user_id),
    KEY question_id (question_id),
    KEY status (status)
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

    // --- NEW: Table for "Review Later" questions ---
    $table_review_later = $wpdb->prefix . 'qp_review_later';
    $sql_review_later = "CREATE TABLE $table_review_later (
        review_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (review_id),
        UNIQUE KEY user_question (user_id, question_id),
        KEY user_id (user_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_review_later);

    // --- NEW: Table for Report Reasons ---
    $table_report_reasons = $wpdb->prefix . 'qp_report_reasons';
    $sql_report_reasons = "CREATE TABLE $table_report_reasons (
        reason_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        reason_text VARCHAR(255) NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        PRIMARY KEY (reason_id)
    ) $charset_collate;";
    dbDelta($sql_report_reasons);

    // --- NEW: Table for storing individual reports ---
    $table_question_reports = $wpdb->prefix . 'qp_question_reports';
    $sql_question_reports = "CREATE TABLE $table_question_reports (
        report_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        reason_id BIGINT(20) UNSIGNED NOT NULL,
        report_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        PRIMARY KEY (report_id),
        KEY question_id (question_id),
        KEY user_id (user_id),
        KEY reason_id (reason_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_question_reports);

    // --- NEW: Table for Revision Mode Attempts ---
    $table_revision_attempts = $wpdb->prefix . 'qp_revision_attempts';
    $sql_revision_attempts = "CREATE TABLE $table_revision_attempts (
    revision_attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    question_id BIGINT(20) UNSIGNED NOT NULL,
    topic_id BIGINT(20) UNSIGNED NOT NULL,
    attempt_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (revision_attempt_id),
    UNIQUE KEY user_question_topic (user_id, question_id, topic_id),
    KEY user_id (user_id),
    KEY topic_id (topic_id)
) $charset_collate;";
    dbDelta($sql_revision_attempts);

    // Add some default report reasons if the table is empty
    if ($wpdb->get_var("SELECT COUNT(*) FROM $table_report_reasons") == 0) {
        $default_reasons = ['Wrong Answer', 'Typo in question', 'Options are incorrect', 'Image is not loading', 'Question is confusing'];
        foreach ($default_reasons as $reason) {
            $wpdb->insert($table_report_reasons, ['reason_text' => $reason]);
        }
    }

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
    add_submenu_page('question-press', 'Logs / Reports', 'Logs / Reports', 'manage_options', 'qp-logs-reports', ['QP_Logs_Reports_Page', 'render']);
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

    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_PLUGIN_URL . 'admin/assets/js/quick-edit.js', ['jquery'], '1.0.2', true); // Version bump
        // NEW: Add a nonce specifically for our new admin filters
        wp_localize_script('qp-quick-edit-script', 'qp_admin_filter_data', [
            'nonce' => wp_create_nonce('qp_admin_filter_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);

        global $wpdb;
        // Get all sources with their parent subject_id
        $all_sources = $wpdb->get_results("SELECT source_id, source_name, subject_id FROM {$wpdb->prefix}qp_sources ORDER BY source_name ASC");

        // Get all sections with their parent source_id
        $all_sections = $wpdb->get_results("SELECT section_id, section_name, source_id FROM {$wpdb->prefix}qp_source_sections ORDER BY section_name ASC");

        // Get all exams and their links to subjects
        $all_exams = $wpdb->get_results("SELECT exam_id, exam_name FROM {$wpdb->prefix}qp_exams ORDER BY exam_name ASC");
        $exam_subject_links = $wpdb->get_results("SELECT exam_id, subject_id FROM {$wpdb->prefix}qp_exam_subjects");
        $all_topics = $wpdb->get_results("SELECT topic_id, topic_name, subject_id FROM {$wpdb->prefix}qp_topics ORDER BY topic_name ASC");

        // Localize ALL sets of data for our script
        wp_localize_script('qp-quick-edit-script', 'qp_bulk_edit_data', [
            'sources' => $all_sources,
            'sections' => $all_sections,
            'exams' => $all_exams,
            'exam_subject_links' => $exam_subject_links,
            'topics' => $all_topics
        ]);

        wp_localize_script('qp-quick-edit-script', 'qp_quick_edit_object', [
            'save_nonce' => wp_create_nonce('qp_save_quick_edit_nonce')
        ]);
        wp_enqueue_script('qp-multi-select-dropdown-script', QP_PLUGIN_URL . 'admin/assets/js/multi-select-dropdown.js', ['jquery'], '1.0.1', true);
    }
}
add_action('admin_enqueue_scripts', 'qp_admin_enqueue_scripts');

// FORM & ACTION HANDLERS
function qp_handle_form_submissions()
{
    // NEW: Handle list table actions here, before any other output.
    // We check if the user is on the main "All Questions" page.
    if (isset($_GET['page']) && $_GET['page'] === 'question-press') {
        $list_table = new QP_Questions_List_Table();
        $list_table->process_bulk_action();
    }

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
        }
        // NEW: Check for our bulk edit confirmation message
        if (isset($_GET['bulk_edit_message']) && $_GET['bulk_edit_message'] === '1') {
            echo '<div id="message" class="notice notice-success is-dismissible"><p>Questions have been bulk updated successfully.</p></div>';
        }

        ?>
        <hr class="wp-header-end">
        <?php $list_table->views(); ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php $list_table->search_box('Search Questions', 'question'); ?>
            <?php $list_table->display(); ?>
        </form>
        <style type="text/css">
            #post-query-submit{
                margin-left: 8px;
            }
            .wp-list-table .column-custom_question_id {
                width: 5%;
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


function qp_handle_save_question_group()
{
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

    if (empty($subject_id) || empty($questions_from_form)) {
        return;
    }

    // --- Save Group Data ---
    $group_data = [
        'direction_text' => wp_kses_post($direction_text),
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
            'question_text' => wp_kses_post($question_text),
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


/**
 * AJAX handler for the admin list table.
 * Gets only topics that have at least one question for a given subject.
 */
function qp_get_topics_for_list_table_filter_ajax()
{
    check_ajax_referer('qp_admin_filter_nonce', 'nonce'); // Using a new nonce for admin-side security
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

    if (!$subject_id) {
        wp_send_json_success(['topics' => []]);
    }

    global $wpdb;
    $topics_table = $wpdb->prefix . 'qp_topics';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $groups_table = $wpdb->prefix . 'qp_question_groups';

    // This query finds topics that are linked to questions which are linked to groups in the selected subject.
    $topics = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t.topic_id, t.topic_name
         FROM {$topics_table} t
         JOIN {$questions_table} q ON t.topic_id = q.topic_id
         JOIN {$groups_table} g ON q.group_id = g.group_id
         WHERE g.subject_id = %d
         ORDER BY t.topic_name ASC",
        $subject_id
    ));

    wp_send_json_success(['topics' => $topics]);
}
add_action('wp_ajax_get_topics_for_list_table_filter', 'qp_get_topics_for_list_table_filter_ajax');


/**
 * AJAX handler for the admin list table.
 * Gets sources/sections that have questions for a given subject and topic.
 */
function qp_get_sources_for_list_table_filter_ajax()
{
    check_ajax_referer('qp_admin_filter_nonce', 'nonce');
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

    if (!$subject_id) {
        wp_send_json_success(['sources' => []]);
    }

    global $wpdb;
    $sources_table = $wpdb->prefix . 'qp_sources';
    $sections_table = $wpdb->prefix . 'qp_source_sections';
    $questions_table = $wpdb->prefix . 'qp_questions';

    // We need to fetch both sources and their sections that match the criteria.
    $query_params = [$subject_id];
    $sql = "
        SELECT DISTINCT src.source_id, src.source_name, sec.section_id, sec.section_name
        FROM {$sources_table} src
        JOIN {$questions_table} q ON src.source_id = q.source_id
        LEFT JOIN {$sections_table} sec ON q.section_id = sec.section_id
        WHERE src.subject_id = %d
    ";

    if ($topic_id > 0) {
        $sql .= " AND q.topic_id = %d";
        $query_params[] = $topic_id;
    }

    $sql .= " ORDER BY src.source_name ASC, sec.section_name ASC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $query_params));

    // Group sections under their parent source
    $sources = [];
    foreach ($results as $row) {
        if (!isset($sources[$row->source_id])) {
            $sources[$row->source_id] = [
                'source_id' => $row->source_id,
                'source_name' => $row->source_name,
                'sections' => []
            ];
        }
        if ($row->section_id && !isset($sources[$row->source_id]['sections'][$row->section_id])) {
            $sources[$row->source_id]['sections'][$row->section_id] = [
                'section_id' => $row->section_id,
                'section_name' => $row->section_name
            ];
        }
    }

    wp_send_json_success(['sources' => array_values($sources)]); // Return as a simple array
}
add_action('wp_ajax_get_sources_for_list_table_filter', 'qp_get_sources_for_list_table_filter_ajax');

// Public-facing hooks and AJAX handlers
function qp_public_init()
{
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);

    add_shortcode('question_press_session', ['QP_Shortcodes', 'render_session_page']);
    add_shortcode('question_press_review', ['QP_Shortcodes', 'render_review_page']);
}
add_action('init', 'qp_public_init');

function qp_public_enqueue_scripts()
{
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard') || has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review'))) {

        // File versions for cache busting
        $css_version = filemtime(QP_PLUGIN_DIR . 'public/assets/css/practice.css');
        $practice_js_version = filemtime(QP_PLUGIN_DIR . 'public/assets/js/practice.js');
        $dashboard_js_version = filemtime(QP_PLUGIN_DIR . 'public/assets/js/dashboard.js');
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];

        // Check if the user's roles intersect with the allowed roles
        $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

        wp_enqueue_style('qp-practice-styles', QP_PLUGIN_URL . 'public/assets/css/practice.css', [], $css_version);

        $options = get_option('qp_settings');
        $ajax_data = [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('qp_practice_nonce'),
            'dashboard_page_url' => isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/'),
            'practice_page_url'  => isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/'),
            'review_page_url'    => isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/'),
            'question_order_setting'   => isset($options['question_order']) ? $options['question_order'] : 'random',
            'can_delete_history' => $can_delete
        ];

        // --- CORRECTED SCRIPT LOADING LOGIC ---

        // Load dashboard script if the dashboard shortcode is present
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('qp-dashboard-script', QP_PLUGIN_URL . 'public/assets/js/dashboard.js', ['jquery'], $dashboard_js_version, true);
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }

        // Load practice script if practice or session shortcodes are present
        if (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_session')) {
            // NEW: Enqueue the Hammer.js library from a CDN
            wp_enqueue_script('hammer-js', 'https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js', [], '2.0.8', true);

            // THE FIX: Add 'hammer-js' as a dependency for your practice script
            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery', 'hammer-js'], $practice_js_version, true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
        }

        // Load KaTeX if any page that can display questions is present
        if (has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review') || has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
            wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
            wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-js'], '0.16.9', true);

            // Add the inline script to actually render the math
            wp_add_inline_script('katex-auto-render', "
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof renderMathInElement === 'function') {
                        renderMathInElement(document.body, {
                            delimiters: [
                                {left: '$$', right: '$$', display: true},
                                {left: '$', right: '$', display: false},
                                {left: '\\\\[', right: '\\\\]', display: true},
                                {left: '\\\\(', right: '\\\\)', display: false}
                            ],
                            throwOnError: false
                        });
                    }
                });
            ");
        }

        // Localize session data specifically for the session page
        if (has_shortcode($post->post_content, 'question_press_session')) {
            $session_data = QP_Shortcodes::get_session_data_for_script();
            if ($session_data) {
                wp_localize_script('qp-practice-script', 'qp_session_data', $session_data);
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'qp_public_enqueue_scripts');


/**
 * AJAX handler to get topics for a subject THAT HAVE QUESTIONS.
 */
function qp_get_topics_for_subject_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $subject_ids_raw = isset($_POST['subject_id']) ? $_POST['subject_id'] : [];
    if (empty($subject_ids_raw)) {
        wp_send_json_error(['message' => 'No subjects provided.']);
    }

    // Handle the "all" case
    if (in_array('all', $subject_ids_raw)) {
        $subject_ids = []; // An empty array will fetch all
    } else {
        $subject_ids = array_map('absint', $subject_ids_raw);
    }

    global $wpdb;
    $topics_table = $wpdb->prefix . 'qp_topics';
    $subjects_table = $wpdb->prefix . 'qp_subjects';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $groups_table = $wpdb->prefix . 'qp_question_groups';

    // Base query to get topics linked to subjects that have questions
    $sql = "
        SELECT DISTINCT s.subject_name, t.topic_id, t.topic_name
        FROM {$topics_table} t
        JOIN {$subjects_table} s ON t.subject_id = s.subject_id
        JOIN {$questions_table} q ON t.topic_id = q.topic_id
        JOIN {$groups_table} g ON q.group_id = g.group_id AND g.subject_id = s.subject_id
    ";

    // Add WHERE clause only if specific subjects are selected
    if (!empty($subject_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($subject_ids), '%d'));
        $sql .= $wpdb->prepare(" WHERE s.subject_id IN ($ids_placeholder)", $subject_ids);
    }

    $sql .= " ORDER BY s.subject_name, t.topic_name ASC";

    $results = $wpdb->get_results($sql);

    // Group the results by subject name for the frontend
    $grouped_topics = [];
    foreach ($results as $row) {
        if (!isset($grouped_topics[$row->subject_name])) {
            $grouped_topics[$row->subject_name] = [];
        }
        $grouped_topics[$row->subject_name][] = [
            'topic_id' => $row->topic_id,
            'topic_name' => $row->topic_name
        ];
    }

    wp_send_json_success(['topics' => $grouped_topics]);
}
add_action('wp_ajax_get_topics_for_subject', 'qp_get_topics_for_subject_ajax');

/**
 * AJAX handler to get sections containing questions for a given subject and topic.
 */
function qp_get_sections_for_subject_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    // Get subject_id (required) and topic_id (optional) from the request
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

    if (!$subject_id) {
        wp_send_json_error(['message' => 'Invalid subject ID.']);
    }

    global $wpdb;
    $sources_table = $wpdb->prefix . 'qp_sources';
    $sections_table = $wpdb->prefix . 'qp_source_sections';
    $questions_table = $wpdb->prefix . 'qp_questions';

    // Base query joins sections to sources and questions
    $query = "
        SELECT DISTINCT sec.section_id, src.source_name, sec.section_name
        FROM {$sections_table} sec
        JOIN {$sources_table} src ON sec.source_id = src.source_id
        JOIN {$questions_table} q ON sec.section_id = q.section_id
        WHERE src.subject_id = %d
    ";
    $params = [$subject_id];

    // If a specific topic is selected, add it to the filter
    if ($topic_id > 0) {
        $query .= " AND q.topic_id = %d";
        $params[] = $topic_id;
    }

    $query .= " ORDER BY src.source_name ASC, sec.section_name ASC";

    $results = $wpdb->get_results($wpdb->prepare($query, $params));

    wp_send_json_success(['sections' => $results]);
}
add_action('wp_ajax_get_sections_for_subject', 'qp_get_sections_for_subject_ajax');

function qp_start_practice_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $practice_mode = isset($_POST['practice_mode']) ? sanitize_key($_POST['practice_mode']) : 'normal';
    global $wpdb;

    // --- THIS IS THE KEY ADDITION ---
    // Get a list of all question IDs that have an open report.
    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    $exclude_sql = !empty($reported_question_ids) ? 'AND q.question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';
    // --- END OF ADDITION ---

    if ($practice_mode === 'revision') {
        // ... (The revision mode logic remains the same, but we will add the exclude filter)
        $session_settings = [
            'practice_mode'   => 'revision',
            'selection_type'  => isset($_POST['revision_selection_type']) ? sanitize_key($_POST['revision_selection_type']) : 'auto',
            'subjects'        => isset($_POST['revision_subjects']) ? array_map('absint', $_POST['revision_subjects']) : [],
            'topics'          => isset($_POST['revision_topics']) ? array_map('absint', $_POST['revision_topics']) : [],
            'questions_per'   => isset($_POST['qp_revision_questions_per_topic']) ? absint($_POST['qp_revision_questions_per_topic']) : 10,
            'marks_correct'   => isset($_POST['qp_marks_correct']) ? floatval($_POST['qp_marks_correct']) : 1.0,
            'marks_incorrect' => isset($_POST['qp_marks_incorrect']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : 0.0,
            'timer_enabled'   => isset($_POST['qp_timer_enabled']),
            'timer_seconds'   => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
        ];

        $user_id = get_current_user_id();
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';

        // ... (rest of revision logic is the same)
        $topic_ids_to_query = [];
        if ($session_settings['selection_type'] === 'manual' && (!empty($session_settings['subjects']) || !empty($session_settings['topics']))) {
            $topic_ids_to_query = $session_settings['topics'];
            if (!empty($session_settings['subjects'])) {
                $subject_ids_placeholder = implode(',', $session_settings['subjects']);
                $topics_in_subjects = $wpdb->get_col("SELECT topic_id FROM {$wpdb->prefix}qp_topics WHERE subject_id IN ($subject_ids_placeholder)");
                $topic_ids_to_query = array_unique(array_merge($topic_ids_to_query, $topics_in_subjects));
            }
        } else {
            $topic_ids_to_query = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT q.topic_id FROM {$attempts_table} a JOIN {$questions_table} q ON a.question_id = q.question_id WHERE a.user_id = %d AND q.topic_id IS NOT NULL", $user_id));
        }

        if (empty($topic_ids_to_query)) {
            wp_send_json_error(['html' => '<div class="qp-container"><p>No previously attempted questions found for the selected criteria. Try different options or a Normal Practice session.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
        }

        $final_question_ids = [];
        $questions_per_topic = $session_settings['questions_per'];

        foreach ($topic_ids_to_query as $topic_id) {
            // Apply the exclude filter to the revision query
            $q_ids = $wpdb->get_col($wpdb->prepare("SELECT q.question_id FROM {$questions_table} q JOIN {$attempts_table} a ON q.question_id = a.question_id WHERE a.user_id = %d AND q.topic_id = %d {$exclude_sql} ORDER BY RAND() LIMIT %d", $user_id, $topic_id, $questions_per_topic));
            $final_question_ids = array_merge($final_question_ids, $q_ids);
        }
        $question_ids = array_unique($final_question_ids);
        shuffle($question_ids);
    } else {
        // **THE FIX**: This logic now handles arrays of subjects and topics
        $subjects_raw = isset($_POST['qp_subject']) && is_array($_POST['qp_subject']) ? $_POST['qp_subject'] : [];
        $topics_raw = isset($_POST['qp_topic']) && is_array($_POST['qp_topic']) ? $_POST['qp_topic'] : [];

        if ($practice_mode === 'normal' && empty($subjects_raw)) {
            wp_send_json_error(['message' => 'Please select at least one subject.']);
        }

        $section_id = isset($_POST['qp_section']) ? $_POST['qp_section'] : 'all';
        $practice_mode = 'normal';
        if ($section_id !== 'all' && is_numeric($section_id)) {
            $practice_mode = 'Section Wise Practice';
        }

        $session_settings = [
            'practice_mode'    => $practice_mode,
            'subjects'         => $subjects_raw,
            'topics'           => $topics_raw,
            'section_id'       => $section_id,
            'pyq_only'         => isset($_POST['qp_pyq_only']),
            'include_attempted' => isset($_POST['qp_include_attempted']),
            'marks_correct'    => isset($_POST['qp_marks_correct']) ? floatval($_POST['qp_marks_correct']) : 4.0,
            'marks_incorrect'  => isset($_POST['qp_marks_incorrect']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : -1.0,
            'timer_enabled'    => isset($_POST['qp_timer_enabled']),
            'timer_seconds'    => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
        ];


        $user_id = get_current_user_id();
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $a_table = $wpdb->prefix . 'qp_user_attempts';

        $where_clauses = ["q.status = 'publish'"];
        $query_args = [];
        $joins = "LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

        // Handle Subject selection (single, multiple, or all)
        if (!empty($subjects_raw) && !in_array('all', $subjects_raw)) {
            $subject_ids = array_map('absint', $subjects_raw);
            $ids_placeholder = implode(',', array_fill(0, count($subject_ids), '%d'));
            $where_clauses[] = $wpdb->prepare("g.subject_id IN ($ids_placeholder)", $subject_ids);
        }

        // Handle Topic selection
        if (!empty($topics_raw)) {
            $topic_ids = array_map('absint', $topics_raw);
            $ids_placeholder = implode(',', array_fill(0, count($topic_ids), '%d'));
            $where_clauses[] = $wpdb->prepare("q.topic_id IN ($ids_placeholder)", $topic_ids);
        }

        // Handle Section selection (only if one subject and one topic were chosen)
        if ($session_settings['section_id'] !== 'all' && is_numeric($session_settings['section_id'])) {
            $where_clauses[] = "q.section_id = %d";
            $query_args[] = absint($session_settings['section_id']);
        }
        if ($session_settings['pyq_only']) {
            $where_clauses[] = "g.is_pyq = 1";
        }

        $base_where_sql = implode(' AND ', $where_clauses);
        if (!$session_settings['include_attempted']) {
            // **THE FIX**: Only exclude questions that were explicitly 'answered'.
            $attempted_q_ids_sql = $wpdb->prepare("SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d AND status = 'answered'", $user_id);
            $base_where_sql .= " AND q.question_id NOT IN ($attempted_q_ids_sql)";
        }

        // **THE FIX**: This is the new, corrected ordering logic.
        $options = get_option('qp_settings');
        $admin_order_setting = isset($options['question_order']) ? $options['question_order'] : 'random';
        $order_by_sql = '';

        if ($session_settings['section_id'] !== 'all' && is_numeric($session_settings['section_id'])) {
            // Force numerical incrementing order if a specific section is chosen
            $order_by_sql = 'ORDER BY CAST(q.question_number_in_section AS UNSIGNED) ASC, q.custom_question_id ASC';
        } else {
            // Otherwise, use the admin setting
            $order_by_sql = ($admin_order_setting === 'in_order') ? 'ORDER BY q.custom_question_id ASC' : 'ORDER BY RAND()';
        }

        $query = "SELECT q.question_id FROM {$q_table} q {$joins} WHERE {$base_where_sql} {$exclude_sql} {$order_by_sql}";
        $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));
    }

    // --- COMMON SESSION CREATION LOGIC --- (No changes here)
    if (empty($question_ids)) {
        wp_send_json_error(['html' => '<div class="qp-container"><p>No questions were found for the selected criteria. Please try different options.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
    }

    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => get_current_user_id(),
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_start_practice_session', 'qp_start_practice_session_ajax');

/**
 * AJAX handler to start a special session with incorrectly answered questions.
 */
function qp_start_incorrect_practice_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $questions_table = $wpdb->prefix . 'qp_questions';

    // Decide which set of questions to fetch based on the checkbox
    $include_all_incorrect = isset($_POST['include_all_incorrect']) && $_POST['include_all_incorrect'] === 'true';

    $question_ids = [];

    if ($include_all_incorrect) {
        // Mode 1: Get all questions the user has EVER answered incorrectly.
        $question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 0",
            $user_id
        ));
    } else {
        // Mode 2: Get questions the user has NEVER answered correctly.
        // First, get all questions ever answered correctly by the user.
        $correctly_answered_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1",
            $user_id
        ));

        // Then, get all questions the user has explicitly ANSWERED (not skipped).
        $all_answered_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'",
            $user_id
        ));
        
        // The questions to practice are those answered but never answered correctly.
        $question_ids = array_diff($all_answered_qids, $correctly_answered_qids);
    }
    
    if (empty($question_ids)) {
        wp_send_json_error(['message' => 'No incorrect questions found to practice.']);
    }
    
    // Randomize the order of questions
    shuffle($question_ids);

    // Get the Session Page URL from settings
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    // Create a special settings snapshot for this session
    $session_settings = [
        'practice_mode'   => 'Incorrect Que. Practice', // Our new mode name
        'timer_enabled'   => false,
    ];

    // Create the new session record
    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode(array_values($question_ids)) // Re-index array
    ]);
    $session_id = $wpdb->insert_id;

    // Build the redirect URL and send it back
    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_qp_start_incorrect_practice_session', 'qp_start_incorrect_practice_session_ajax');

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // We can re-use the function from our shortcode class to get the form HTML
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}
add_action('wp_ajax_get_practice_form_html', 'qp_get_practice_form_html_ajax');

// In question-press.php, REPLACE this function

function qp_get_question_data_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID.']);
    }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $s_table = $wpdb->prefix . 'qp_subjects';
    $t_table = $wpdb->prefix . 'qp_topics';
    $src_table = $wpdb->prefix . 'qp_sources';
    $sec_table = $wpdb->prefix . 'qp_source_sections';
    $o_table = $wpdb->prefix . 'qp_options';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $user_id = get_current_user_id();

    // --- Fetch question data ---
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_id, q.custom_question_id, q.question_text, q.question_number_in_section,
                g.direction_text, g.direction_image_id,
                s.subject_name, t.topic_name,
                src.source_name, sec.section_name
         FROM {$q_table} q
         LEFT JOIN {$g_table} g ON q.group_id = g.group_id
         LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id
         LEFT JOIN {$t_table} t ON q.topic_id = t.topic_id
         LEFT JOIN {$src_table} src ON q.source_id = src.source_id
         LEFT JOIN {$sec_table} sec ON q.section_id = sec.section_id
         WHERE q.question_id = %d",
        $question_id
    ), ARRAY_A);

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    $options = get_option('qp_settings');
    $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
    $user = wp_get_current_user();
    $user_can_view = !empty(array_intersect((array)$user->roles, (array)$allowed_roles));

    if (!$user_can_view) {
        unset($question_data['source_name'], $question_data['section_name'], $question_data['question_number_in_section']);
    }

    $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;
    
    // **THE FIX**: Fetch the 'is_correct' status along with the options
    $options_from_db = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$o_table} WHERE question_id = %d", $question_id), ARRAY_A);

    $correct_option_id = null;
    $options_for_frontend = [];
    foreach ($options_from_db as $option) {
        if ($option['is_correct']) {
            $correct_option_id = (int) $option['option_id'];
        }
        // We only send the text and id to the frontend, not the answer status
        $options_for_frontend[] = [
            'option_id' => $option['option_id'],
            'option_text' => $option['option_text']
        ];
    }
    $question_data['options'] = $options_for_frontend;
    
    if (!empty($question_data['question_text'])) {
    $question_data['question_text'] = wp_kses_post(nl2br($question_data['question_text']));
}
    if (!empty($question_data['direction_text'])) {
        $question_data['direction_text'] = wp_kses_post(nl2br($question_data['direction_text']));
    }

    // --- State Checks ---
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $attempt_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $a_table WHERE user_id = %d AND question_id = %d AND status = 'answered' AND session_id != %d", $user_id, $question_id, $session_id));
    $review_table = $wpdb->prefix . 'qp_review_later';
    $is_marked = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$review_table} WHERE user_id = %d AND question_id = %d", $user_id, $question_id));

    // **THIS IS THE CRITICAL ADDITION**
    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $is_reported_by_user = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$reports_table} WHERE user_id = %d AND question_id = %d AND status = 'open'", $user_id, $question_id));

    // --- Send Final Response ---
    wp_send_json_success([
        'question'             => $question_data,
        'correct_option_id'    => $correct_option_id,
        'is_revision'          => ($attempt_count > 0),
        'is_admin'             => $user_can_view,
        'is_marked_for_review' => $is_marked,
        'is_reported_by_user'  => $is_reported_by_user // Send the authoritative state
    ]);
}
add_action('wp_ajax_get_question_data', 'qp_get_question_data_ajax');

/**
 * AJAX handler to get all active report reasons.
 */
function qp_get_report_reasons_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    global $wpdb;
    $reasons_table = $wpdb->prefix . 'qp_report_reasons';

    $reasons = $wpdb->get_results(
        "SELECT reason_id, reason_text FROM {$reasons_table} WHERE is_active = 1 ORDER BY reason_id ASC"
    );

    wp_send_json_success(['reasons' => $reasons]);
}
add_action('wp_ajax_get_report_reasons', 'qp_get_report_reasons_ajax');

/**
 * AJAX handler to submit a new question report from the modal.
 */
function qp_submit_question_report_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $reasons = isset($_POST['reasons']) && is_array($_POST['reasons']) ? array_map('absint', $_POST['reasons']) : [];
    $user_id = get_current_user_id();

    if (empty($question_id) || empty($reasons)) {
        wp_send_json_error(['message' => 'Invalid data provided.']);
    }

    global $wpdb;
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    foreach ($reasons as $reason_id) {
        $wpdb->insert(
            $reports_table,
            [
                'question_id' => $question_id,
                'user_id'     => $user_id,
                'reason_id'   => $reason_id,
                'report_date' => current_time('mysql'),
                'status'      => 'open'
            ]
        );
    }

    // Add a log entry for the admin panel
    $custom_id = get_question_custom_id($question_id);
    $wpdb->insert("{$wpdb->prefix}qp_logs", [
        'log_type'    => 'User Report',
        'log_message' => sprintf('User reported question #%s.', $custom_id),
        'log_data'    => wp_json_encode(['user_id' => $user_id, 'session_id' => $session_id, 'question_id' => $question_id, 'reasons' => $reasons])
    ]);

    wp_send_json_success(['message' => 'Report submitted.']);
}
add_action('wp_ajax_submit_question_report', 'qp_submit_question_report_ajax');

function qp_check_answer_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;
    if (!$session_id || !$question_id || !$option_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }

    global $wpdb;
    $o_table = $wpdb->prefix . 'qp_options';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    $wpdb->update($sessions_table, ['last_activity' => current_time('mysql')], ['session_id' => $session_id]);

    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));

    // --- **THE FIX**: This is the new logic ---
    $session = $wpdb->get_row($wpdb->prepare("SELECT settings_snapshot FROM $sessions_table WHERE session_id = %d", $session_id));
    $settings = json_decode($session->settings_snapshot, true);

    // Always record in the main attempts table
    $wpdb->insert($attempts_table, [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'selected_option_id' => $option_id,
        'is_correct' => $is_correct ? 1 : 0,
        'status' => 'answered',
        'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null
    ]);

    // If it's a revision session, also record in the revision table
    if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'revision') {
        $topic_id = $wpdb->get_var($wpdb->prepare("SELECT topic_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
        if ($topic_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}qp_revision_attempts (user_id, question_id, topic_id) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE attempt_date = NOW()",
                get_current_user_id(),
                $question_id,
                $topic_id
            ));
        }
    }
    // --- End of new logic ---

    wp_send_json_success(['is_correct' => $is_correct, 'correct_option_id' => $correct_option_id]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

/**
 * AJAX handler to start a REVISION practice session.
 */
function qp_start_revision_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    global $wpdb;

    // --- Gather settings from the new form ---
    $user_id = get_current_user_id();
    $subjects = isset($_POST['revision_subjects']) && is_array($_POST['revision_subjects']) ? $_POST['revision_subjects'] : [];
    $topics = isset($_POST['revision_topics']) && is_array($_POST['revision_topics']) ? $_POST['revision_topics'] : [];
    $questions_per_topic = isset($_POST['qp_revision_questions_per_topic']) ? absint($_POST['qp_revision_questions_per_topic']) : 10;
    $exclude_pyq = isset($_POST['exclude_pyq']);
    $choose_random = isset($_POST['choose_random']);

    $session_settings = [
        'practice_mode'       => 'revision',
        'subjects'            => $subjects,
        'topics'              => $topics,
        'questions_per'       => $questions_per_topic,
        'exclude_pyq'         => $exclude_pyq,
        'marks_correct'       => isset($_POST['qp_marks_correct']) ? floatval($_POST['qp_marks_correct']) : 1.0,
        'marks_incorrect'     => isset($_POST['qp_marks_incorrect']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : 0.0,
        'timer_enabled'       => isset($_POST['qp_timer_enabled']),
        'timer_seconds'       => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
    ];

    $subject_ids_numeric = array_map('absint', array_filter($subjects, 'is_numeric'));

    if (empty($subject_ids_numeric) && !in_array('all', $subjects)) {
        wp_send_json_error(['html' => '<div class="qp-container"><p>Please select at least one subject to revise.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
    }

    // --- Determine the final list of topics to query ---
    $topic_ids_to_query = [];

    // If "All Topics" is selected, or if no specific topics are chosen, get all topics from the selected subjects.
    if (in_array('all', $topics) || empty($topics)) {
        $subjects_to_query = [];
        if (in_array('all', $subjects)) {
            // Get all subject IDs if "All Subjects" is chosen
            $subjects_to_query = $wpdb->get_col("SELECT subject_id FROM {$wpdb->prefix}qp_subjects");
        } else {
            $subjects_to_query = $subject_ids_numeric;
        }

        if (!empty($subjects_to_query)) {
            $ids_placeholder = implode(',', array_fill(0, count($subjects_to_query), '%d'));
            $topic_ids_to_query = $wpdb->get_col($wpdb->prepare("SELECT topic_id FROM {$wpdb->prefix}qp_topics WHERE subject_id IN ($ids_placeholder)", $subjects_to_query));
        }
    } else {
        // Otherwise, use the specifically selected topics.
        $topic_ids_to_query = array_map('absint', array_filter($topics, 'is_numeric'));
    }

    $topic_ids_to_query = array_unique($topic_ids_to_query);

    if (empty($topic_ids_to_query)) {
        wp_send_json_error(['html' => '<div class="qp-container"><p>No topics found for the selected criteria.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
    }

    // --- Main Question Selection Logic ---
    $final_question_ids = [];
    $revision_table = $wpdb->prefix . 'qp_revision_attempts';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    $exclude_reported_sql = '';
    if (!empty($reported_question_ids)) {
        $exclude_reported_sql = ' AND q.question_id NOT IN (' . implode(',', array_map('absint', $reported_question_ids)) . ')';
    }

    foreach ($topic_ids_to_query as $topic_id) {
        $pyq_filter_sql = '';
        if ($exclude_pyq) {
            $pyq_filter_sql = " AND g.is_pyq = 0";
        }

        $master_pool_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT q.question_id 
            FROM {$wpdb->prefix}qp_questions q
            JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
            WHERE q.topic_id = %d" . $pyq_filter_sql . $exclude_reported_sql,
            $topic_id
        ));

        if (empty($master_pool_qids)) {
            continue; // Skip this topic if no questions match the PYQ filter
        }

        $revised_qids_for_topic = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $revision_table WHERE user_id = %d AND topic_id = %d", $user_id, $topic_id));
        $available_qids = array_diff($master_pool_qids, $revised_qids_for_topic);

        if (empty($available_qids) && !empty($master_pool_qids)) {
            $wpdb->delete($revision_table, ['user_id' => $user_id, 'topic_id' => $topic_id]);
            $available_qids = $master_pool_qids;
        }

        if (!empty($available_qids)) {
            $ids_placeholder = implode(',', array_map('absint', $available_qids));
            // Set the ordering based on the user's choice
            $order_by_sql = "ORDER BY src.source_name ASC, sec.section_name ASC, CAST(q.question_number_in_section AS UNSIGNED) ASC, q.question_id ASC";
            if ($choose_random) {
                $order_by_sql = "ORDER BY RAND()";
            }

            $q_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT q.question_id
                FROM $questions_table q
                LEFT JOIN {$wpdb->prefix}qp_sources src ON q.source_id = src.source_id
                LEFT JOIN {$wpdb->prefix}qp_source_sections sec ON q.section_id = sec.section_id
                WHERE q.question_id IN ($ids_placeholder)
                {$order_by_sql}
                LIMIT %d",
                $questions_per_topic
            ));
            $final_question_ids = array_merge($final_question_ids, $q_ids);
        }
    }

    // --- Create and Start the Session ---
    $question_ids = array_unique($final_question_ids);
    if (empty($question_ids)) {
        wp_send_json_error(['html' => '<div class="qp-container"><p>No questions were found for the selected criteria. Try different options or a Normal Practice session.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
    }

    shuffle($question_ids);

    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_start_revision_session', 'qp_start_revision_session_ajax');

/**
 * AJAX handler to mark a question as 'expired' for a session.
 */
function qp_expire_question_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;

    if (!$session_id || !$question_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }

    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'is_correct' => null,
        'status' => 'expired',
        'remaining_time' => 0 // Expired means 0 time left
    ]);

    wp_send_json_success();
}
add_action('wp_ajax_expire_question', 'qp_expire_question_ajax');

function qp_skip_question_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$session_id || !$question_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }

    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'is_correct' => null,
        'status' => 'skipped',
        'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null
    ]);

    wp_send_json_success();
}
add_action('wp_ajax_skip_question', 'qp_skip_question_ajax');

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
            'end_time' => current_time('mysql'),
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
        'settings' => $settings,
    ]);
}
add_action('wp_ajax_end_practice_session', 'qp_end_practice_session_ajax');

/**
 * AJAX handler to delete an empty/unterminated session record.
 */
function qp_delete_empty_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    // Delete the session record from the database
    $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

    wp_send_json_success(['message' => 'Empty session deleted.']);
}
add_action('wp_ajax_delete_empty_session', 'qp_delete_empty_session_ajax');


// ADD THIS NEW FUNCTION to the end of the file
function qp_delete_user_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // **THE FIX**: Add server-side permission check
    $options = get_option('qp_settings');
    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
    if (empty(array_intersect($user_roles, $allowed_roles))) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }
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
 * AJAX handler for deleting a user's entire revision and session history.
 */
function qp_delete_revision_history_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // **THE FIX**: Add server-side permission check
    $options = get_option('qp_settings');
    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
    if (empty(array_intersect($user_roles, $allowed_roles))) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }
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


function qp_get_quick_edit_form_ajax()
{
    // Nonce check remains the same
    check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error();
    }

    global $wpdb;

    // --- FIX: Updated query to fetch all necessary data ---
    // This now joins the groups table to get direction_text and the correct subject_id for the group.
    $question = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_text, q.topic_id, q.source_id, q.section_id, g.subject_id, g.direction_text, g.is_pyq, g.exam_id, g.pyq_year
         FROM {$wpdb->prefix}qp_questions q 
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id 
         WHERE q.question_id = %d",
        $question_id
    ));

    // Fetching other necessary data
    $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
    $all_sources = $wpdb->get_results("SELECT source_id, source_name, subject_id FROM {$wpdb->prefix}qp_sources ORDER BY source_name ASC");
    $all_sections = $wpdb->get_results("SELECT section_id, section_name, source_id FROM {$wpdb->prefix}qp_source_sections ORDER BY section_name ASC");
    $all_exams = $wpdb->get_results("SELECT exam_id, exam_name FROM {$wpdb->prefix}qp_exams ORDER BY exam_name ASC");
    $exam_subject_links = $wpdb->get_results("SELECT exam_id, subject_id FROM {$wpdb->prefix}qp_exam_subjects");
    $all_topics = $wpdb->get_results("SELECT topic_id, topic_name, subject_id FROM {$wpdb->prefix}qp_topics ORDER BY topic_name ASC");
    $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
    $current_labels = $wpdb->get_col($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_question_labels WHERE question_id = %d", $question_id));
    $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC", $question_id));

    $topics_by_subject = [];
    foreach ($all_topics as $topic) {
        $topics_by_subject[$topic->subject_id][] = ['id' => $topic->topic_id, 'name' => $topic->topic_name];
    }
    $sources_by_subject = [];
    foreach ($all_sources as $source) {
        $sources_by_subject[$source->subject_id][] = ['id' => $source->source_id, 'name' => $source->source_name];
    }
    $sections_by_source = [];
    foreach ($all_sections as $section) {
        $sections_by_source[$section->source_id][] = ['id' => $section->section_id, 'name' => $section->section_name];
    }

    // Start output buffering to capture the form HTML
    ob_start();
?>
    <script>
        var qp_quick_edit_data = {
            topics_by_subject: <?php echo json_encode($topics_by_subject); ?>,
            sources_by_subject: <?php echo json_encode($sources_by_subject); ?>,
            sections_by_source: <?php echo json_encode($sections_by_source); ?>,
            all_exams: <?php echo json_encode($all_exams); ?>,
            exam_subject_links: <?php echo json_encode($exam_subject_links); ?>,
            current_topic_id: <?php echo json_encode($question->topic_id); ?>,
            current_source_id: <?php echo json_encode($question->source_id); ?>,
            current_section_id: <?php echo json_encode($question->section_id); ?>,
            current_exam_id: <?php echo json_encode($question->exam_id); ?>
        };
    </script>
    <form class="quick-edit-form-wrapper">

        <div class="quick-edit-display-text">
            <?php if (!empty($question->direction_text)) : ?>
                <div class="display-group">
                    <strong>Direction:</strong>
                    <p><?php echo esc_html(wp_trim_words($question->direction_text, 50, '...')); ?></p>
                </div>
            <?php endif; ?>
            <div class="display-group">
                <strong>Question:</strong>
                <p><?php echo esc_html(wp_trim_words($question->question_text, 50, '...')); ?></p>
            </div>
        </div>

        <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">

        <div class="quick-edit-main-container">
            <div class="quick-edit-col-left">
                <label><strong>Correct Answer</strong></label>
                <div class="options-group">
                    <?php foreach ($options as $index => $option) : ?>
                        <label class="option-label">
                            <input type="radio" name="correct_option_id" value="<?php echo esc_attr($option->option_id); ?>" <?php checked($option->is_correct, 1); ?>>
                            <input type="text" readonly value="<?php echo esc_attr($option->option_text); ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="quick-edit-col-right">
                <div class="form-row-flex">
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-subject-<?php echo esc_attr($question_id); ?>"><strong>Subject</strong></label>
                        <select name="subject_id" id="qe-subject-<?php echo esc_attr($question_id); ?>" class="qe-subject-select">
                            <?php foreach ($all_subjects as $subject) : ?>
                                <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $question->subject_id); ?>><?php echo esc_html($subject->subject_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-topic-<?php echo esc_attr($question_id); ?>"><strong>Topic</strong></label>
                        <select name="topic_id" id="qe-topic-<?php echo esc_attr($question_id); ?>" class="qe-topic-select" disabled>
                            <option value="">— Select subject first —</option>
                        </select>
                    </div>
                </div>
                <div class="form-row-flex">
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-source-<?php echo esc_attr($question_id); ?>"><strong>Source</strong></label>
                        <select name="source_id" id="qe-source-<?php echo esc_attr($question_id); ?>" class="qe-source-select" disabled>
                            <option value="">— Select Subject First —</option>
                        </select>
                    </div>
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-section-<?php echo esc_attr($question_id); ?>"><strong>Section</strong></label>
                        <select name="section_id" id="qe-section-<?php echo esc_attr($question_id); ?>" class="qe-section-select" disabled>
                            <option value="">— Select Source First —</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-flex qe-pyq-fields-wrapper" style="align-items: center;">
                    <div class="form-group-shrink">
                        <label class="inline-checkbox">
                            <input type="checkbox" name="is_pyq" value="1" class="qe-is-pyq-checkbox" <?php checked($question->is_pyq, 1); ?>> Is PYQ?
                        </label>
                    </div>
                    <div class="form-group-expand qe-pyq-fields" style="<?php echo $question->is_pyq ? '' : 'display: none;'; ?>">
                        <div class="form-group-half">
                            <select name="exam_id" class="qe-exam-select">
                                <option value="">— Select Exam —</option>
                                <?php foreach ($all_exams as $exam) : ?>
                                    <option value="<?php echo esc_attr($exam->exam_id); ?>" <?php selected($exam->exam_id, $question->exam_id); ?>><?php echo esc_html($exam->exam_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group-half">
                            <input type="number" name="pyq_year" value="<?php echo esc_attr($question->pyq_year); ?>" placeholder="Year (e.g., 2023)">
                        </div>
                    </div>
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
        .quick-edit-display-text {
            background-color: #f6f7f7;
            border: 1px solid #e0e0e0;
            padding: 10px 20px;
            margin: 20px 20px 10px;
            border-radius: 4px
        }

        .quick-edit-display-text .display-group {
            margin-bottom: 10px
        }

        .options-group label:last-child,
        .quick-edit-display-text .display-group:last-child,
        .quick-edit-form-wrapper .form-row:last-child {
            margin-bottom: 0
        }

        .quick-edit-display-text p {
            margin: 5px 0 0;
            padding-left: 10px;
            border-left: 3px solid #ccc;
            color: #555;
            font-style: italic
        }

        .quick-edit-form-wrapper h4 {
            font-size: 16px;
            margin-top: 20px;
            margin-bottom: 10px;
            padding: 10px 20px
        }

        .inline-edit-row .submit {
            padding: 20px
        }

        .quick-edit-form-wrapper .title {
            font-size: 15px;
            font-weight: 500;
            color: #555
        }

        .quick-edit-form-wrapper .form-row,
        .quick-edit-form-wrapper .form-row-flex {
            margin-bottom: 1rem
        }

        .quick-edit-form-wrapper label,
        .quick-edit-form-wrapper strong {
            font-weight: 600;
            display: block;
            margin-bottom: 0rem
        }

        .quick-edit-form-wrapper select {
            width: 100%
        }

        .quick-edit-main-container {
            display: flex;
            gap: 20px;
            margin-bottom: 1rem;
            padding: 0 20px
        }

        .form-row-flex .qe-pyq-fields {
            display: flex;
            gap: 1rem;
        }

        .form-row-flex .qe-right-dropdowns {
            display: flex;
            flex-direction: row;
        }

        .form-row-flex .qe-right-dropdowns label {
            margin-right: 1rem;
            width: 15%;
        }

        .labels-group,
        .options-group {
            display: flex;
            padding: .5rem;
            border: 1px solid #ddd;
            background: #fff
        }

        .quick-edit-col-left {
            flex: 0 0 40%
        }

        .form-group-half,
        .quick-edit-col-right {
            flex: 1
        }

        .options-group {
            flex-direction: column;
            justify-content: space-between;
            height: auto;
            box-sizing: border-box;
            gap: 10px;
        }

        .option-label {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-bottom: .5rem
        }

        .option-label input[type=radio] {
            margin-top: 0;
            align-self: center
        }

        .option-label input[type=text] {
            width: 90%;
            background-color: #f0f0f1
        }

        .form-row-flex {
            display: flex;
            gap: 1rem
        }

        .quick-edit-form-wrapper p.submit button.button-secondary {
            margin-right: 10px
        }

        .labels-group {
            flex-wrap: wrap;
            gap: .5rem 1rem
        }

        .inline-checkbox {
            white-space: nowrap
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
        'topic_id' => isset($data['topic_id']) && $data['topic_id'] > 0 ? absint($data['topic_id']) : null,
        'last_modified' => current_time('mysql')
    ], ['question_id' => $question_id]);

    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
    if ($group_id) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", [
            'subject_id' => absint($data['subject_id']),
            'is_pyq' => isset($data['is_pyq']) ? 1 : 0
        ], ['group_id' => $group_id]);
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
 * Schedules the session cleanup event if it's not already scheduled.
 */
function qp_schedule_session_cleanup()
{
    if (!wp_next_scheduled('qp_cleanup_abandoned_sessions_event')) {
        wp_schedule_event(time(), 'hourly', 'qp_cleanup_abandoned_sessions_event');
    }
}
add_action('wp', 'qp_schedule_session_cleanup');

/**
 * The function that runs on the scheduled cron event to clean up old sessions.
 */
function qp_cleanup_abandoned_sessions()
{
    global $wpdb;
    $options = get_option('qp_settings');
    $timeout_minutes = isset($options['session_timeout']) ? absint($options['session_timeout']) : 20;

    if ($timeout_minutes < 5) {
        $timeout_minutes = 20;
    }

    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Get the IDs of all sessions that are active but have timed out
    $abandoned_session_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT session_id FROM {$sessions_table}
         WHERE status = 'active' AND last_activity < NOW() - INTERVAL %d MINUTE",
        $timeout_minutes
    ));

    if (empty($abandoned_session_ids)) {
        return; // No sessions to clean up.
    }

    // --- THE FIX: Delete the sessions and their attempts in bulk ---
    $ids_placeholder = implode(',', $abandoned_session_ids);
    $wpdb->query("DELETE FROM {$attempts_table} WHERE session_id IN ({$ids_placeholder})");
    $wpdb->query("DELETE FROM {$sessions_table} WHERE session_id IN ({$ids_placeholder})");
}
add_action('qp_cleanup_abandoned_sessions_event', 'qp_cleanup_abandoned_sessions');


/**
 * Handles the complete, on-demand data migration and cleanup process.
 * This is safe to run multiple times, as each step checks for completion.
 */
function qp_run_unified_data_migration()
{
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
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $exams_table = $wpdb->prefix . 'qp_exams';

    // === STEP 0: MIGRATE qp_user_sessions TABLE SCHEMA ===
    $sessions_columns = $wpdb->get_col("DESC $sessions_table", 0);
    $columns_to_add = [
        'status' => "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER `end_time`",
        'last_activity' => "ADD COLUMN `last_activity` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER `start_time`",
        'question_ids_snapshot' => "ADD COLUMN `question_ids_snapshot` LONGTEXT AFTER `settings_snapshot`"
    ];

    foreach ($columns_to_add as $column_name => $query_part) {
        if (!in_array($column_name, $sessions_columns)) {
            $wpdb->query("ALTER TABLE {$sessions_table} {$query_part};");
            $messages[] = "Step 0: Added `{$column_name}` column to the sessions table.";
        }
    }
    $wpdb->query("UPDATE {$sessions_table} SET `last_activity` = `start_time` WHERE `last_activity` = '0000-00-00 00:00:00'");

    // === STEP 1 (CORRECTED): PARSE LEGACY PYQ TAGS FROM QUESTION TEXT ===
    // This now checks the OLD `is_pyq` flag directly on the questions table.
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'is_pyq'")) {
        $pyq_questions_to_parse = $wpdb->get_results(
            "SELECT q.question_id, q.question_text, q.group_id
             FROM {$questions_table} q
             JOIN {$groups_table} g ON q.group_id = g.group_id
             WHERE q.question_text LIKE '%[%' AND g.exam_id IS NULL AND q.is_pyq = 1"
        );

        if (!empty($pyq_questions_to_parse)) {
            $parsed_count = 0;
            foreach ($pyq_questions_to_parse as $question) {
                preg_match('/\[([A-Z]+)\s*(\d{4})(?:\(\w+\))?\]$/', trim($question->question_text), $matches);

                if (count($matches) === 3) {
                    $exam_name = sanitize_text_field($matches[1]);
                    $pyq_year = sanitize_text_field($matches[2]);

                    $exam_id = $wpdb->get_var($wpdb->prepare("SELECT exam_id FROM {$exams_table} WHERE exam_name = %s", $exam_name));
                    if (!$exam_id) {
                        $wpdb->insert($exams_table, ['exam_name' => $exam_name]);
                        $exam_id = $wpdb->insert_id;
                    }

                    if ($exam_id && $question->group_id) {
                        $wpdb->update(
                            $groups_table,
                            ['exam_id' => $exam_id, 'pyq_year' => $pyq_year],
                            ['group_id' => $question->group_id]
                        );

                        $clean_text = preg_replace('/\[([A-Z]+)\s*(\d{4})(?:\(\w+\))?\]$/', '', trim($question->question_text));
                        $wpdb->update(
                            $questions_table,
                            ['question_text' => trim($clean_text)],
                            ['question_id' => $question->question_id]
                        );
                        $parsed_count++;
                    }
                }
            }
            if ($parsed_count > 0) {
                $messages[] = "Step 1: Parsed and migrated Exam/Year data for {$parsed_count} PYQ questions.";
            }
        }
    }


    // === STEP 2: MIGRATE LEGACY source_file to qp_sources TABLE ===
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
            if ($migrated_source_count > 0) $messages[] = "Step 2: Created {$migrated_source_count} new entries in the Sources table from legacy data.";
        }
    }

    // === STEP 3: ASSIGN ORPHANED SOURCES TO "UNCATEGORIZED" SUBJECT ===
    $uncategorized_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM {$subjects_table} WHERE subject_name = %s", 'Uncategorized'));
    if ($uncategorized_id) {
        $updated_rows = $wpdb->update($sources_table, ['subject_id' => $uncategorized_id], ['subject_id' => 0]);
        if ($updated_rows > 0) $messages[] = "Step 3: Assigned {$updated_rows} orphaned sources to the 'Uncategorized' subject.";
    }

    // === STEP 4: MIGRATE LEGACY source_number TO question_number_in_section ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'source_number'")) {
        $questions_to_migrate_num = $wpdb->get_results("SELECT question_id, source_number FROM {$questions_table} WHERE source_number IS NOT NULL AND (question_number_in_section IS NULL OR question_number_in_section = '')");
        if (!empty($questions_to_migrate_num)) {
            foreach ($questions_to_migrate_num as $q) {
                $wpdb->update($questions_table, ['question_number_in_section' => $q->source_number], ['question_id' => $q->question_id]);
            }
            $messages[] = "Step 4: Migrated question numbers for " . count($questions_to_migrate_num) . " questions.";
        }
    }

    // === STEP 5 (IMPROVED): MIGRATE PYQ STATUS TO GROUPS ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'is_pyq'")) {
        $groups_to_check = $wpdb->get_col("SELECT group_id FROM {$groups_table} WHERE is_pyq = 0");
        $migrated_groups_count = 0;
        if (!empty($groups_to_check)) {
            foreach ($groups_to_check as $group_id) {
                $is_legacy_pyq = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$questions_table} WHERE group_id = %d AND is_pyq = 1",
                    $group_id
                ));

                if ($is_legacy_pyq > 0) {
                    $wpdb->update($groups_table, ['is_pyq' => 1], ['group_id' => $group_id]);
                    $migrated_groups_count++;
                }
            }
            if ($migrated_groups_count > 0) $messages[] = "Step 5: Updated PYQ status for {$migrated_groups_count} question group(s).";
        }
    }

    // === STEP 6 (IMPROVED): ROBUST DATABASE CLEANUP ===
    $columns_to_drop = ['source_file', 'source_page', 'source_number', 'is_pyq', 'exam_id', 'pyq_year'];
    $dropped_columns = [];
    foreach ($columns_to_drop as $column) {
        if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE '{$column}'")) {
            $wpdb->query("ALTER TABLE {$questions_table} DROP COLUMN {$column};");
            $dropped_columns[] = "<code>{$column}</code>";
        }
    }
    if (!empty($dropped_columns)) {
        $messages[] = "Step 6: Finalized cleanup by removing old columns: " . implode(', ', $dropped_columns) . " from the questions table.";
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

/**
 * AJAX handler to update the last_activity timestamp for a session.
 */
function qp_update_session_activity_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    if ($session_id > 0) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'qp_user_sessions',
            ['last_activity' => current_time('mysql')],
            ['session_id' => $session_id]
        );
    }
    wp_send_json_success();
}
add_action('wp_ajax_update_session_activity', 'qp_update_session_activity_ajax');


/**
 * AJAX handler to add or remove a question from the user's review list.
 */
function qp_toggle_review_later_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    // --- THIS IS THE CORRECTED LOGIC ---
    // We explicitly check if the string from AJAX is 'true'.
    // Anything else, including 'false', will result in a boolean false.
    $is_marked = isset($_POST['is_marked']) && $_POST['is_marked'] === 'true';
    // --- END OF FIX ---
    $user_id = get_current_user_id();

    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid question ID.']);
    }

    global $wpdb;
    $review_table = $wpdb->prefix . 'qp_review_later';

    if ($is_marked) {
        // This block now only runs when the box is checked.
        $wpdb->insert(
            $review_table,
            ['user_id' => $user_id, 'question_id' => $question_id],
            ['%d', '%d']
        );
    } else {
        // This block now correctly runs when the box is unchecked.
        $wpdb->delete(
            $review_table,
            ['user_id' => $user_id, 'question_id' => $question_id],
            ['%d', '%d']
        );
    }

    wp_send_json_success();
}
add_action('wp_ajax_qp_toggle_review_later', 'qp_toggle_review_later_ajax');



/**
 * AJAX handler to start a special session with only the questions marked for review.
 */
function qp_start_review_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // Get all question IDs from the user's review list
    $review_question_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT question_id FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d ORDER BY review_id ASC",
        $user_id
    ));

    if (empty($review_question_ids)) {
        wp_send_json_error(['message' => 'Your review list is empty.']);
    }

    // Get the Session Page URL from settings
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    // Create a special settings snapshot for this review session
    $session_settings = [
        'subject_id'      => 'review', // Special identifier
        'topic_id'        => 'all',
        'sheet_label_id'  => 'all',
        'pyq_only'        => false,
        'revise_mode'     => true, // Treat it as revision
        'marks_correct'   => 1.0,  // Or any default you prefer
        'marks_incorrect' => 0,
        'timer_enabled'   => false,
    ];

    // Create the new session record
    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($review_question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    // Build the redirect URL and send it back
    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_qp_start_review_session', 'qp_start_review_session_ajax');

/**
 * AJAX handler to get the full data for a single question for the review popup.
 */
function qp_get_single_question_for_review_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid question ID.']);
    }

    global $wpdb;

    // Fetch question details
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_text, q.custom_question_id, g.direction_text, s.subject_name
         FROM {$wpdb->prefix}qp_questions q
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
         LEFT JOIN {$wpdb->prefix}qp_subjects s ON g.subject_id = s.subject_id
         WHERE q.question_id = %d",
        $question_id
    ), ARRAY_A);

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    // Fetch options
    $options = $wpdb->get_results($wpdb->prepare(
        "SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC",
        $question_id
    ), ARRAY_A);

    $question_data['options'] = $options;

    wp_send_json_success($question_data);
}
add_action('wp_ajax_get_single_question_for_review', 'qp_get_single_question_for_review_ajax');

/**
 * AJAX handler to terminate an active session.
 */
function qp_terminate_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $user_id = get_current_user_id();

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    // --- THE FIX: Delete the session and its attempts directly ---
    $wpdb->delete($attempts_table, ['session_id' => $session_id]);
    $wpdb->delete($sessions_table, ['session_id' => $session_id]);

    wp_send_json_success(['message' => 'Session terminated and removed successfully.']);
}
add_action('wp_ajax_qp_terminate_session', 'qp_terminate_session_ajax');


function qp_handle_log_settings_forms()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports' || !isset($_GET['tab']) || $_GET['tab'] !== 'log_settings') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'qp_report_reasons';

    // Add/Update Reason
    if (isset($_POST['action']) && ($_POST['action'] === 'add_reason' || $_POST['action'] === 'update_reason') && check_admin_referer('qp_add_edit_reason_nonce')) {
        $reason_text = sanitize_text_field($_POST['reason_text']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $data = ['reason_text' => $reason_text, 'is_active' => $is_active];

        if ($_POST['action'] === 'update_reason') {
            $wpdb->update($table_name, $data, ['reason_id' => absint($_POST['reason_id'])]);
        } else {
            $wpdb->insert($table_name, $data);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=1'));
        exit;
    }

    // Delete Reason
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['reason_id']) && check_admin_referer('qp_delete_reason_' . absint($_GET['reason_id']))) {
        $wpdb->delete($table_name, ['reason_id' => absint($_GET['reason_id'])]);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=2'));
        exit;
    }
}
add_action('admin_init', 'qp_handle_log_settings_forms');

function qp_handle_report_actions()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports') {
        return;
    }

    // Handle single resolve action
    if (isset($_GET['action']) && $_GET['action'] === 'resolve_report' && isset($_GET['question_id'])) {
        $question_id = absint($_GET['question_id']);
        check_admin_referer('qp_resolve_report_' . $question_id);

        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}qp_question_reports",
            ['status' => 'resolved'],
            ['question_id' => $question_id, 'status' => 'open']
        );

        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&message=3'));
        exit;
    }
}
add_action('admin_init', 'qp_handle_report_actions');

/**
 * Handles resolving all open reports for a group from the question editor page.
 */
function qp_handle_resolve_from_editor()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-edit-group' || !isset($_GET['action']) || $_GET['action'] !== 'resolve_group_reports') {
        return;
    }

    $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
    if (!$group_id) return;

    check_admin_referer('qp_resolve_group_reports_' . $group_id);

    global $wpdb;
    $questions_in_group_ids = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}qp_questions WHERE group_id = %d", $group_id));

    if (!empty($questions_in_group_ids)) {
        $ids_placeholder = implode(',', $questions_in_group_ids);
        $wpdb->query("UPDATE {$wpdb->prefix}qp_question_reports SET status = 'resolved' WHERE question_id IN ({$ids_placeholder}) AND status = 'open'");
    }

    // Redirect back to the editor page with a success message
    wp_safe_redirect(admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1'));
    exit;
}
add_action('admin_init', 'qp_handle_resolve_from_editor');
