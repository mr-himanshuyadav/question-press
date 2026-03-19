<?php
namespace QuestionPress\Utils;

if (!defined('ABSPATH')) exit;

class Logger
{
    public static function log($type, $message, $data = null)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'qp_logs';

        $wpdb->insert(
            $table,
            [
                'log_type'    => sanitize_text_field($type),
                'log_message' => sanitize_text_field($message),
                'log_data'    => $data ? wp_json_encode($data) : null,
                'log_date'    => current_time('mysql'),
                'resolved'    => 0,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%d'
            ]
        );
    }
}