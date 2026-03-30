<?php

namespace QuestionPress\Rest_Api;

use WP_REST_Request;
use QuestionPress\Utils\Logger;

if (!defined('ABSPATH')) exit;

class LogController
{
    public static function create_log(WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        $type    = $params['type'] ?? 'app';
        $message = $params['message'] ?? 'No message';
        $data    = $params['data'] ?? null;

        Logger::log($type, $message, $data);

        return [
            'success' => true,
            'message' => 'Log saved successfully'
        ];
    }
}