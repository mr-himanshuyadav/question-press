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

    /**
     * Intercepts all REST responses and logs them if the status is not 200.
     *
     * @param WP_REST_Response $response The response object.
     * @param WP_REST_Server   $server   Server instance.
     * @param WP_REST_Request  $request  The request that was processed.
     * @return WP_REST_Response
     */
    public static function intercept_rest_errors($response, $server, $request)
    {
        $status = $response->get_status();

        // Only intercept errors (anything that isn't 200 OK)
        if ($status !== 200) {
            $user_id = get_current_user_id();
            $route   = $request->get_route();
            $method  = $request->get_method();
            $params  = $request->get_params();
            $data    = $response->get_data();

            // Scrub sensitive data from params before logging
            if (isset($params['password'])) $params['password'] = '********';

            $log_message = sprintf(
                'REST API Error: [%s] %s returned status %d',
                $method,
                $route,
                $status
            );

            $log_data = [
                'status'   => $status,
                'user_id'  => $user_id,
                'request'  => [
                    'method' => $method,
                    'route'  => $route,
                    'params' => $params,
                ],
                'response' => $data,
            ];

            // Use your existing Logger mechanism
            self::log('REST Error', $log_message, $log_data);
        }

        return $response;
    }
}
