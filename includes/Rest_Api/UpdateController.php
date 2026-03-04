<?php

namespace QuestionPress\Rest_Api;

if (!defined('ABSPATH')) exit;

use WP_REST_Response;
use QuestionPress\Utils\Update_Manager;

/**
 * Handles OTA update checks for the mobile application.
 */
class UpdateController
{
    /**
     * Returns current version, build, and Multi-ABI download links.
     * Includes min_version for forced update logic.
     */
    public static function check_update($request)
    {
        $update_info = Update_Manager::get_update_info();
        $options     = get_option('qp_settings', []);

        if (!$update_info) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No release information available.'
            ], 404);
        }

        // Include the minimum version from settings for 'Force Update' blockers
        return new WP_REST_Response([
            'success'     => true,
            'version'     => $update_info['version'],
            'build'       => (int) $update_info['build'],
            'min_version' => $options['min_app_version'] ?? '1.0.0',
            'variants'    => $update_info['variants']
        ], 200);
    }
}