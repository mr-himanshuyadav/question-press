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
use QuestionPress\Rest_Api\PracticeController;
use QuestionPress\Rest_Api\CourseController; // Added
use WP_REST_Server;

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
        register_rest_route('questionpress/v1', '/auth/check-username', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [AuthController::class, 'check_username_availability'],
            'permission_callback' => '__return_true' // Public endpoint
        ]);
        register_rest_route('questionpress/v1', '/auth/check-email', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [AuthController::class, 'check_email_availability'],
            'permission_callback' => '__return_true' // Public endpoint
        ]);
        register_rest_route('questionpress/v1', '/auth/resend-otp', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [AuthController::class, 'resend_registration_otp'],
            'permission_callback' => '__return_true' // Public endpoint
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
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        register_rest_route('questionpress/v1', '/dashboard/overview', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_dashboard_overview'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/dashboard/profile', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_dashboard_profile'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
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
        register_rest_route('questionpress/v1', '/session/start-course-test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'start_course_test_series'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/start-review', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'start_review_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/start-incorrect-practice', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'start_incorrect_practice_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/start-practice', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'start_practice_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/start-revision-test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'start_revision_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/start-mock-test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'start_mock_test_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/create', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'create_session'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [SessionController::class, 'get_session_results'],
            'permission_callback' => [AuthController::class, 'check_auth_token'],
            'args' => [
                'id' => [
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);
        register_rest_route('questionpress/v1', '/session/attempt', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'record_attempt'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/session/end', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [SessionController::class, 'end_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Practice Action Endpoints (Protected) ---
        register_rest_route('questionpress/v1', '/practice/check-answer', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'check_answer'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/save-mock-attempt', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'save_mock_attempt'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/update-mock-status', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'update_mock_status'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/expire-question', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'expire_question'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/skip-question', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'skip_question'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/toggle-review-later', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'toggle_review_later'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/submit-question-report', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [PracticeController::class, 'submit_question_report'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/question-for-review', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_single_question_for_review'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/report-reasons', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_report_reasons'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/unattempted-counts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_unattempted_counts'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/question-data', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_question_data'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/topics-for-subject', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_topics_for_subject'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/sections-for-subject', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_sections_for_subject'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/sources-for-subject', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_sources_for_subject'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/child-terms', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_child_terms'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/progress-data', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_progress_data'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/sources-for-subject-cascading', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_sources_for_subject_cascading'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/child-terms-cascading', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_child_terms_cascading'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/sources-for-subject-progress', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_sources_for_subject_progress'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/check-remaining-attempts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'check_remaining_attempts'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/practice/buffered-question-data', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [PracticeController::class, 'get_buffered_question_data'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // Course Routes
        register_rest_route('questionpress/v1', '/course/enroll', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [CourseController::class, 'enroll_in_course'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/course/search-questions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [CourseController::class, 'search_questions_for_course'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/course/practice-form-data', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [CourseController::class, 'get_practice_form_data'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/course/structure', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [CourseController::class, 'get_course_structure'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
        register_rest_route('questionpress/v1', '/course/deregister', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [CourseController::class, 'deregister_from_course'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
    }
}
