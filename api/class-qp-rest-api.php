<?php
if (!defined('ABSPATH')) exit;

// Manually include the JWT library files
require_once QP_PLUGIN_PATH . 'lib/JWT.php';
require_once QP_PLUGIN_PATH . 'lib/Key.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use QuestionPress\Rest_Api\AuthController;

class QP_Rest_Api {

    /**
     * The main function to hook into WordPress.
     */
    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register all REST API routes.
     */
    public static function register_routes() {
        // --- Authentication Endpoint (Public) ---
        register_rest_route('questionpress/v1', '/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [AuthController::class, 'get_auth_token'],
            'permission_callback' => '__return_true'
        ]);

        // --- Subjects Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/subjects', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_subjects'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Topics Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/topics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_topics'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Exams Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/exams', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_exams'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Sources Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/sources', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_sources'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Labels Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/labels', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [DataController::class, 'get_labels'], // CHANGED
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Add Question Group Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/questions/add', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'add_question_group'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        // --- Start Session / Get Questions Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/start-session', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'start_session_and_get_questions'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/question/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_single_question_by_id'],
            'permission_callback' => [AuthController::class, 'check_auth_token'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        // --- Session Management Endpoints (Protected) ---
        register_rest_route('questionpress/v1', '/session/create', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'create_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/session/attempt', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'record_attempt'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/session/end', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'end_session'],
            'permission_callback' => [AuthController::class, 'check_auth_token']
        ]);
    }
    
    public static function start_session_and_get_questions(WP_REST_Request $request) {
        // 1. Sanitize Parameters
        $subject_param = $request->get_param('subject_id');
        $subject_id = ($subject_param === 'all' || empty($subject_param)) ? 'all' : absint($subject_param);

        $pyq_only_param = $request->get_param('pyq_only');
        // Treat various truthy values (e.g., 'true', '1') as true
        $pyq_only = filter_var($pyq_only_param, FILTER_VALIDATE_BOOLEAN);

        // 2. Prepare Arguments for DB Method
        $args = [
            'subject_id' => $subject_id,
            'pyq_only'   => $pyq_only,
        ];

        // 3. Call DB Method
        $question_ids = QuestionPress\Database\Questions_DB::get_question_ids_for_api_session( $args );

        // Ensure IDs are integers
        $question_ids = array_map('intval', $question_ids);


        // 4. Handle Response
        if ( empty( $question_ids ) ) {
            // No error, just return an empty array if no questions match
            return new WP_REST_Response( ['question_ids' => []], 200 );
            // Or return 404 if preferred:
            // return new WP_Error('no_questions_found', 'No questions found matching your criteria.', ['status' => 404]);
        }

        return new WP_REST_Response( ['question_ids' => $question_ids], 200 );
    }

    
    public static function get_single_question_by_id(WP_REST_Request $request) {
        // Get the question's actual database ID from the URL parameter
        $question_id = (int) $request['id'];

        if ($question_id <= 0) {
             return new WP_Error('rest_invalid_id', 'Invalid Question ID provided.', ['status' => 400]);
        }

        // Call the new DB method
        $question_data = QuestionPress\Database\Questions_DB::get_question_details_for_api($question_id);

        // Check the result
        if ( $question_data ) {
            // Found and formatted data successfully
            return new WP_REST_Response( $question_data, 200 );
        } else {
            // Question not found or not published
            return new WP_Error( 'rest_question_not_found', 'Question not found or is not published.', ['status' => 404] );
        }
    }


    /**
     * NEW: Creates a new practice session record.
     */
    public static function create_session(WP_REST_Request $request) {
        $settings = $request->get_param('settings'); // App sends a JSON object of settings
        
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}qp_user_sessions", [
            'user_id' => get_current_user_id(),
            'settings_snapshot' => wp_json_encode($settings)
        ]);
        $session_id = $wpdb->insert_id;

        return new WP_REST_Response(['session_id' => $session_id], 200);
    }

    /**
     * NEW: Records a user's attempt for a single question.
     */
    public static function record_attempt(WP_REST_Request $request) {
        $session_id = $request->get_param('session_id');
        $question_id = $request->get_param('question_id'); // This is the internal DB ID
        $option_id = $request->get_param('option_id'); // Can be null if skipped

        if (!$session_id || !$question_id) {
            return new WP_Error('rest_invalid_request', 'Session ID and Question ID are required.', ['status' => 400]);
        }
        
        global $wpdb;
        $is_correct = null;
        $correct_option_id = null;

        if ($option_id) { // If an answer was submitted
            $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM {$wpdb->prefix}qp_options WHERE option_id = %d", $option_id));
            $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->prefix}qp_options WHERE question_id = %d AND is_correct = 1", $question_id));
        }

        $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'question_id' => $question_id,
            'is_correct' => $is_correct
        ]);

        return new WP_REST_Response([
            'success' => true,
            'is_correct' => $is_correct,
            'correct_option_id' => (int)$correct_option_id
        ], 200);
    }
    
    /**
     * NEW: Ends a session and calculates the final results.
     */
    public static function end_session(WP_REST_Request $request) {
        $session_id = $request->get_param('session_id');
        if (!$session_id) {
            return new WP_Error('rest_invalid_request', 'Session ID is required.', ['status' => 400]);
        }
        
        // This logic is identical to our AJAX handler
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
        $settings = json_decode($session->settings_snapshot, true);
        $marks_correct = $settings['marks_correct'] ?? 0;
        $marks_incorrect = $settings['marks_incorrect'] ?? 0;

        $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 1", $session_id));
        $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 0", $session_id));
        $skipped_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct IS NULL", $session_id));
        
        $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);
        $total_attempted = $correct_count + $incorrect_count;

        $wpdb->update($sessions_table, ['end_time' => current_time('mysql', 1), 'total_attempted' => $total_attempted, 'correct_count' => $correct_count, 'incorrect_count' => $incorrect_count, 'skipped_count' => $skipped_count, 'marks_obtained' => $final_score], ['session_id' => $session_id]);

        return new WP_REST_Response(['message' => 'Session ended successfully.', 'final_score' => $final_score], 200);
    }

    public static function add_question_group(WP_REST_Request $request) {
        global $wpdb;
        $data = $request->get_json_params();

        if (!isset($data['subject_id']) || !isset($data['questions']) || !is_array($data['questions'])) {
            return new WP_Error('rest_invalid_request', 'Missing required fields: subject_id and questions are required.', ['status' => 400]);
        }

        $g_table = $wpdb->prefix . 'qp_question_groups';
        $q_table = $wpdb->prefix . 'qp_questions';
        $o_table = $wpdb->prefix . 'qp_options';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // --- Create the Question Group ---
        $wpdb->insert($g_table, [
            'direction_text' => isset($data['direction_text']) ? sanitize_textarea_field($data['direction_text']) : null,
            'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
            'pyq_year' => isset($data['pyq_year']) ? sanitize_text_field($data['pyq_year']) : null,
        ]);
        $group_id = $wpdb->insert_id;

        if (!$group_id) {
            return new WP_Error('db_error', 'Could not create the question group.', ['status' => 500]);
        }

        // Link group to subject
        $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => absint($data['subject_id']), 'object_type' => 'group']);

        // Link group to exam if PYQ
        if (isset($data['is_pyq']) && !empty($data['exam_id'])) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => absint($data['exam_id']), 'object_type' => 'group']);
        }

        // --- Loop Through and Create Each Question ---
        foreach ($data['questions'] as $question_data) {
            $question_text = sanitize_textarea_field($question_data['question_text']);
            $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);

            $wpdb->insert($q_table, [
                'group_id' => $group_id,
                'custom_question_id' => $next_custom_id,
                'question_text' => $question_text,
                'question_text_hash' => $hash,
                'status' => 'publish'
            ]);
            $question_id = $wpdb->insert_id;
            update_option('qp_next_custom_question_id', $next_custom_id + 1);

            // Add Options
            if (isset($question_data['options']) && is_array($question_data['options'])) {
                foreach ($question_data['options'] as $option) {
                    $wpdb->insert($o_table, [
                        'question_id' => $question_id,
                        'option_text' => sanitize_text_field($option['option_text']),
                        'is_correct' => (int)$option['is_correct']
                    ]);
                }
            }

            // Add Labels by creating relationships
            if (isset($question_data['labels']) && is_array($question_data['labels'])) {
                foreach ($question_data['labels'] as $label_id) {
                    $wpdb->insert($rel_table, [
                        'object_id' => $question_id,
                        'term_id' => absint($label_id),
                        'object_type' => 'question'
                    ]);
                }
            }
        }

        return new WP_REST_Response(['success' => true, 'message' => 'Question group created successfully.', 'group_id' => $group_id], 201);
    }

}