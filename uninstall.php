<?php
/**
 * Fired when the plugin is uninstalled.
 */

// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if the user has opted to delete data.
$options = get_option('qp_settings');
if (isset($options['delete_on_uninstall']) && $options['delete_on_uninstall'] == 1) {

    global $wpdb;

    $tables = [
        // Old Tables (for cleanup during uninstall)
        $wpdb->prefix . 'qp_subjects',
        $wpdb->prefix . 'qp_labels',
        $wpdb->prefix . 'qp_topics',
        $wpdb->prefix . 'qp_exams',
        $wpdb->prefix . 'qp_exam_subjects',
        $wpdb->prefix . 'qp_sources',
        $wpdb->prefix . 'qp_source_sections',
        $wpdb->prefix . 'qp_question_labels',
        // Core Tables
        $wpdb->prefix . 'qp_question_groups',
        $wpdb->prefix . 'qp_questions',
        $wpdb->prefix . 'qp_options',
        // User Data Tables
        $wpdb->prefix . 'qp_user_sessions',
        $wpdb->prefix . 'qp_session_pauses',
        $wpdb->prefix . 'qp_user_attempts',
        $wpdb->prefix . 'qp_revision_attempts',
        $wpdb->prefix . 'qp_review_later',
        // Reporting & Logging Tables
        $wpdb->prefix . 'qp_logs',
        $wpdb->prefix . 'qp_report_reasons',
        $wpdb->prefix . 'qp_question_reports',
        // New Taxonomy Tables
        $wpdb->prefix . 'qp_taxonomies',
        $wpdb->prefix . 'qp_terms',
        $wpdb->prefix . 'qp_term_relationships',
        $wpdb->prefix . 'qp_term_meta',
    ];

    // Drop each table
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }

    // Delete plugin options from the options table
    delete_option('qp_settings');
    delete_option('qp_next_custom_question_id');
    delete_option('qp_jwt_secret_key');
    delete_option('widget_qp_search_widget'); // Example of widget data
}