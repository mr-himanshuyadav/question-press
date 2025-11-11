<?php

namespace QuestionPress\Rest_Api; // Added namespace

if (!defined('ABSPATH')) exit;

// Manually include the JWT library files
require_once QP_PLUGIN_PATH . 'lib/JWT.php';
require_once QP_PLUGIN_PATH . 'lib/Key.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use QuestionPress\Rest_Api\AuthController;
use QuestionPress\Rest_Api\QuestionController;
use QuestionPress\Rest_Api\SessionController;
use QuestionPress\Rest_Api\DataController; // Added missing use statement
use \WP_REST_Server; // Added for WP_REST_Server::CREATABLE

final class Router
{ // Changed class name

    /**
     * The main function to hook into WordPress.
     */
    public static function init()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register all REST API routes.
     */
    public static function register_routes()
    {
        // --- Authentication Endpoint (Public) ---
        register_rest_route('questionpress/v1', '/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [AuthController::class, 'get_auth_token'],
            'permission_callback' => '__return_true'
        ]);

        // --- Data "Get" Endpoints (Protected) ---
        register_rest_route('questionpress/v1', '/subjects', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_subjects'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/topics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_topics'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/exams', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_exams'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/sources', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_sources'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/labels', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_labels'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/courses', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_courses'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/course/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_course_details'],
            'permission_callback' => [AuthController::class, 'check_auth_token'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) { return is_numeric($param); }
                ],
            ],
        ]);

        // --- Question Endpoints (Protected) ---
        register_rest_route('questionpress/v1', '/questions/add', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [QuestionController::class, 'add_question_group'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/start-session', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [QuestionController::class, 'start_session_and_get_questions'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/question/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [QuestionController::class, 'get_single_question_by_id'],
            'permission_callback' => [AuthController::class, 'check_auth_token'],
            'args' => [
                'id' => [
                    'validate_callback' => function ($param, $request, $key) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        // --- Session Management Endpoints (Protected) ---
        register_rest_route('questionpress/v1', '/session/create', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'create_session'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/attempt', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'record_attempt'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/end', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'end_session'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
    }
}
