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

    // Get all QP tables dynamically
    $tables = $wpdb->get_col( $wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $wpdb->prefix . 'qp_%'
    ) );

    // Drop each table
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    // Also drop the old tables just in case they are still around
    $old_tables = [
        $wpdb->prefix . 'qp_subjects',
        $wpdb->prefix . 'qp_labels',
        $wpdb->prefix . 'qp_topics',
        $wpdb->prefix . 'qp_exams',
        $wpdb->prefix . 'qp_exam_subjects',
        $wpdb->prefix . 'qp_sources',
        $wpdb->prefix . 'qp_source_sections',
        $wpdb->prefix . 'qp_question_labels',
        $wpdb->prefix . 'qp_report_reasons',
    ];
    foreach ($old_tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }

    // Delete plugin options from the options table
    delete_option('qp_settings');
    delete_option('qp_jwt_secret_key');
    delete_option('qp_auto_backup_schedule');
    
    // Delete custom CPTs and their meta
    $post_types_to_delete = ['qp_course', 'qp_plan'];
    foreach ($post_types_to_delete as $post_type) {
        $posts = get_posts([
            'post_type' => $post_type,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ]);
        if ($posts) {
            foreach ($posts as $post_id) {
                wp_delete_post($post_id, true); // true = force delete
            }
        }
    }
    
    // Clear cron jobs
    wp_clear_scheduled_hook('qp_check_entitlement_expiration_hook');
    wp_clear_scheduled_hook('qp_check_course_expiration_hook');
    wp_clear_scheduled_hook('qp_cleanup_abandoned_sessions_event');
    wp_clear_scheduled_hook('qp_scheduled_backup_hook');

}