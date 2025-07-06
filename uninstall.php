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

    // List of all custom tables to be dropped
    $tables = [
        $wpdb->prefix . 'qp_subjects',
        $wpdb->prefix . 'qp_labels',
        $wpdb->prefix . 'qp_question_groups',
        $wpdb->prefix . 'qp_questions',
        $wpdb->prefix . 'qp_options',
        $wpdb->prefix . 'qp_question_labels',
        $wpdb->prefix . 'qp_user_sessions',
        $wpdb->prefix . 'qp_user_attempts',
        $wpdb->prefix . 'qp_logs',
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