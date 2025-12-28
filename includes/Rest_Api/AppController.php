<?php

namespace QuestionPress\Rest_Api;

if (!defined('ABSPATH')) exit;

use WP_REST_Response;

/**
 * Handles REST API requests for App-level configuration and maintenance status.
 */
class AppController
{
    /**
     * Retrieves the mobile app configuration from plugin settings.
     * * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_app_config($request)
    {
        $options = get_option('qp_settings');

        $data = [
            'min_version'         => $options['min_app_version'] ?? '1.0.0',
            'latest_version'      => $options['latest_app_version'] ?? '1.0.0',
            'maintenance_mode'    => isset($options['maintenance_mode']) && (int) $options['maintenance_mode'] === 1,
            'maintenance_message' => $options['maintenance_message'] ?? 'The app is currently undergoing maintenance. Please try again later.',
            'store_url_ios'       => $options['store_url_ios'] ?? '',
            'store_url_android'   => $options['store_url_android'] ?? '',
        ];

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data
        ], 200);
    }
}