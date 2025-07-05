<?php
if (!defined('ABSPATH')) exit;

// Manually include the JWT library files
require_once QP_PLUGIN_DIR . 'lib/JWT.php';
require_once QP_PLUGIN_DIR . 'lib/Key.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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
            'methods' => 'POST',
            'callback' => [self::class, 'get_auth_token'],
            'permission_callback' => '__return_true'
        ]);

        // --- Subjects Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/subjects', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_subjects'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);
        
        // --- Start Session / Get Questions Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/start-session', [
            'methods' => 'POST',
            'callback' => [self::class, 'start_session_and_get_questions'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);
    }

    /**
     * Permission callback. Checks for a valid JWT in the Authorization header.
     */
    public static function check_auth_token(WP_REST_Request $request) {
        $secret_key = get_option('qp_jwt_secret_key');
        if (!$secret_key) {
            return new WP_Error('rest_jwt_not_configured', 'The JWT secret key has not been configured.', ['status' => 500]);
        }

        $auth_header = $request->get_header('Authorization');
        if (!$auth_header) {
            return new WP_Error('rest_forbidden', 'Authentication token not found.', ['status' => 401]);
        }

        list($token) = sscanf($auth_header, 'Bearer %s');
        if (!$token) {
            return new WP_Error('rest_forbidden', 'Malformed authentication token.', ['status' => 401]);
        }

        try {
            $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
            wp_set_current_user($decoded->data->user->id);
            return true;
        } catch (Exception $e) {
            return new WP_Error('rest_forbidden', $e->getMessage(), ['status' => 401]);
        }
    }

    public static function get_auth_token(WP_REST_Request $request) {
        $username = $request->get_param('username');
        $password = $request->get_param('password');

        if (empty($username) || empty($password)) {
            return new WP_Error('rest_invalid_request', 'Username and password are required.', ['status' => 400]);
        }

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return new WP_Error('rest_invalid_credentials', 'Invalid username or password.', ['status' => 403]);
        }

        $secret_key = get_option('qp_jwt_secret_key');
        if (!$secret_key) {
            return new WP_Error('rest_jwt_not_configured', 'The JWT secret key has not been configured.', ['status' => 500]);
        }
        
        $issued_at = time();
        $expire_at = $issued_at + (DAY_IN_SECONDS * 7);

        $payload = [
            'iss' => get_bloginfo('url'), 'iat' => $issued_at, 'nbf' => $issued_at, 'exp' => $expire_at,
            'data' => [ 'user' => [ 'id' => $user->ID ] ]
        ];

        $token = JWT::encode($payload, $secret_key, 'HS256');

        return new WP_REST_Response([
            'token'             => $token,
            'user_email'        => $user->user_email,
            'user_nicename'     => $user->user_nicename,
            'user_display_name' => $user->display_name
        ], 200);
    }

    public static function get_subjects() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT subject_id, subject_name FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
        return new WP_REST_Response($results, 200);
    }
    
    public static function start_session_and_get_questions(WP_REST_Request $request) {
        $subject_id = $request->get_param('subject_id') ?? 'all';
        $pyq_only = $request->get_param('pyq_only') ?? false;
        
        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions'; $g_table = $wpdb->prefix . 'qp_question_groups';
        $where_clauses = ["q.status = 'publish'"]; $query_args = [];
        
        if ($subject_id !== 'all') {
            $where_clauses[] = "g.subject_id = %d";
            $query_args[] = absint($subject_id);
        }
        if ($pyq_only) {
            $where_clauses[] = "q.is_pyq = 1";
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        $query = "SELECT q.question_id FROM {$q_table} q LEFT JOIN {$g_table} g ON q.group_id = g.group_id WHERE {$where_sql} ORDER BY RAND()";
        $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

        if (empty($question_ids)) {
            return new WP_Error('no_questions_found', 'No questions found matching your criteria.', ['status' => 404]);
        }
        
        return new WP_REST_Response(['question_ids' => $question_ids], 200);
    }
}