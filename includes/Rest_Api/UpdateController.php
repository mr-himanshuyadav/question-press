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
     * Returns current version and Multi-ABI download links.
     * * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function check_update($request)
    {
        $update_info = Update_Manager::get_update_info();

        if (!$update_info) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No release information available.'
            ], 404);
        }

        return new WP_REST_Response([
            'success'  => true,
            'version'  => $update_info['version'],
            'build'    => $update_info['build'],
            'variants' => $update_info['variants']
        ], 200);
    }
}