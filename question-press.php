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

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

/**
 * The main function to run when the plugin is activated.
 * This function creates the necessary database tables.
 */
function qp_activate_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table for Subjects
    $table_subjects = $wpdb->prefix . 'qp_subjects';
    $sql_subjects = "CREATE TABLE $table_subjects (
        subject_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subject_name VARCHAR(255) NOT NULL,
        PRIMARY KEY (subject_id)
    ) $charset_collate;";
    dbDelta($sql_subjects);

    // Insert default "Uncategorized" subject if it doesn't exist
    $subject_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_subjects WHERE subject_name = %s", 'Uncategorized'));
    if ($subject_exists == 0) {
        $wpdb->insert($table_subjects, ['subject_name' => 'Uncategorized'], ['%s']);
    }

    // Table for Labels
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

    // Insert default labels
    $default_labels = [
        ['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'],
        ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'],
        ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'],
        ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'],
        ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']
    ];
    foreach ($default_labels as $label) {
        $label_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name']));
        if ($label_exists == 0) {
            $wpdb->insert($table_labels, $label);
        }
    }

    // Table for Question Groups (Directions)
    $table_groups = $wpdb->prefix . 'qp_question_groups';
    $sql_groups = "CREATE TABLE $table_groups (
        group_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        direction_text LONGTEXT,
        direction_image_id BIGINT(20) UNSIGNED,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (group_id),
        KEY subject_id (subject_id)
    ) $charset_collate;";
    dbDelta($sql_groups);

    // Table for Questions
    $table_questions = $wpdb->prefix . 'qp_questions';
    $sql_questions = "CREATE TABLE $table_questions (
        question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
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
        KEY group_id (group_id),
        KEY status (status),
        KEY is_pyq (is_pyq),
        KEY question_text_hash (question_text_hash)
    ) $charset_collate;";
    dbDelta($sql_questions);

    // Table for Options
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

    // Table for Question Labels (Many-to-Many Relationship)
    $table_question_labels = $wpdb->prefix . 'qp_question_labels';
    $sql_question_labels = "CREATE TABLE $table_question_labels (
        question_id BIGINT(20) UNSIGNED NOT NULL,
        label_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (question_id, label_id),
        KEY label_id (label_id)
    ) $charset_collate;";
    dbDelta($sql_question_labels);

    // Table for User Practice Sessions
    $table_sessions = $wpdb->prefix . 'qp_user_sessions';
    $sql_sessions = "CREATE TABLE $table_sessions (
        session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        end_time DATETIME,
        total_attempted INT,
        correct_count INT,
        incorrect_count INT,
        skipped_count INT,
        marks_obtained DECIMAL(10, 2),
        settings_snapshot TEXT,
        PRIMARY KEY (session_id),
        KEY user_id (user_id)
    ) $charset_collate;";
    dbDelta($sql_sessions);

    // Table for User Question Attempts in a Session
    $table_attempts = $wpdb->prefix . 'qp_user_attempts';
    $sql_attempts = "CREATE TABLE $table_attempts (
        attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        is_correct BOOLEAN,
        attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (attempt_id),
        KEY session_id (session_id),
        KEY user_id (user_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_attempts);

    // Table for Logs
    $table_logs = $wpdb->prefix . 'qp_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        log_type VARCHAR(50) NOT NULL,
        log_message TEXT NOT NULL,
        log_data LONGTEXT,
        log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (log_id),
        KEY log_type (log_type)
    ) $charset_collate;";
    dbDelta($sql_logs);
}
register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

/**
 * Placeholder for deactivation logic
 */
function qp_deactivate_plugin() {
    // Future deactivation logic here.
}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');

/**
 * Handles plugin uninstallation.
 * Deletes all plugin data if the setting is checked.
 */
function qp_uninstall_plugin() {
    $options = get_option('qp_settings');
    if (isset($options['delete_on_uninstall']) && $options['delete_on_uninstall']) {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'qp_user_attempts',
            $wpdb->prefix . 'qp_user_sessions',
            $wpdb->prefix . 'qp_question_labels',
            $wpdb->prefix . 'qp_options',
            $wpdb->prefix . 'qp_questions',
            $wpdb->prefix . 'qp_question_groups',
            $wpdb->prefix . 'qp_labels',
            $wpdb->prefix . 'qp_subjects',
            $wpdb->prefix . 'qp_logs'
        ];
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        delete_option('qp_settings');
    }
}
register_uninstall_hook(QP_PLUGIN_FILE, 'qp_uninstall_plugin');

// We will add all other plugin functions and includes below this line in future steps.