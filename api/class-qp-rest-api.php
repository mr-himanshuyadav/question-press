<?php
if (!defined('ABSPATH')) exit;

// Manually include the JWT library files
require_once QP_PLUGIN_PATH . 'lib/JWT.php';
require_once QP_PLUGIN_PATH . 'lib/Key.php';
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
            'methods' => WP_REST_Server::CREATABLE, // This is equivalent to POST
            'callback' => [self::class, 'get_auth_token'],
            'permission_callback' => '__return_true'
        ]);

        // --- Subjects Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/subjects', [
            'methods' => WP_REST_Server::READABLE, // This is equivalent to GET
            'callback' => [self::class, 'get_subjects'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        // --- Topics Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/topics', [
            'methods' => WP_REST_Server::READABLE, // GET
            'callback' => [self::class, 'get_topics'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        // --- Exams Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/exams', [
            'methods' => WP_REST_Server::READABLE, // GET
            'callback' => [self::class, 'get_exams'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        // --- Sources Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/sources', [
            'methods' => WP_REST_Server::READABLE, // GET
            'callback' => [self::class, 'get_sources'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        // --- Labels Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/labels', [
            'methods' => WP_REST_Server::READABLE, // GET
            'callback' => [self::class, 'get_labels'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        // --- Add Question Group Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/questions/add', [
            'methods' => WP_REST_Server::CREATABLE, // POST
            'callback' => [self::class, 'add_question_group'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);
        
        // --- Start Session / Get Questions Endpoint (Protected) ---
        register_rest_route('questionpress/v1', '/start-session', [
            'methods' => WP_REST_Server::CREATABLE, // POST
            'callback' => [self::class, 'start_session_and_get_questions'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);
        
        register_rest_route('questionpress/v1', '/question/(?P<id>\d+)', [
    'methods' => WP_REST_Server::READABLE, // GET
    'callback' => [self::class, 'get_single_question_by_id'],
    'permission_callback' => [self::class, 'check_auth_token'],
    'args' => [
        'id' => [
            'validate_callback' => function($param, $request, $key) {
                return is_numeric($param);
            }
        ],
    ],
]);

        // --- NEW: Session Management Endpoints (Protected) ---
        register_rest_route('questionpress/v1', '/session/create', [
            'methods' => WP_REST_Server::CREATABLE, // POST
            'callback' => [self::class, 'create_session'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/session/attempt', [
            'methods' => WP_REST_Server::CREATABLE, // POST
            'callback' => [self::class, 'record_attempt'],
            'permission_callback' => [self::class, 'check_auth_token']
        ]);

        register_rest_route('questionpress/v1', '/session/end', [
            'methods' => WP_REST_Server::CREATABLE, // POST
            'callback' => [self::class, 'end_session'],
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
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';

        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        if (!$subject_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC",
            $subject_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }   
    
    public static function start_session_and_get_questions(WP_REST_Request $request) {
        $subject_id = $request->get_param('subject_id') ?? 'all';
        $pyq_only = $request->get_param('pyq_only') ?? false;

        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $where_clauses = ["q.status = 'publish'"];
        $query_args = [];
        $joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

        if ($subject_id !== 'all') {
            $joins .= " JOIN {$rel_table} subject_rel ON g.group_id = subject_rel.object_id AND subject_rel.object_type = 'group'";
            $where_clauses[] = "subject_rel.term_id = %d";
            $query_args[] = absint($subject_id);
        }

        if ($pyq_only) {
            $where_clauses[] = "g.is_pyq = 1";
        }

        $where_sql = implode(' AND ', $where_clauses);
        $query = "SELECT q.question_id FROM {$q_table} q {$joins} WHERE {$where_sql} ORDER BY RAND()";
        $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

        if (empty($question_ids)) {
            return new WP_Error('no_questions_found', 'No questions found matching your criteria.', ['status' => 404]);
        }

        return new WP_REST_Response(['question_ids' => $question_ids], 200);
    }

    
    /**
     * UPDATED: Callback now fetches question by its custom ID.
     */
    public static function get_single_question_by_id(WP_REST_Request $request) {
        $question_db_id = (int) $request['id'];

        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $o_table = $wpdb->prefix . 'qp_options';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        $question_data = $wpdb->get_row($wpdb->prepare(
            "SELECT q.question_id, q.question_text, g.group_id, g.direction_text, g.direction_image_id
             FROM {$q_table} q 
             LEFT JOIN {$g_table} g ON q.group_id = g.group_id
             WHERE q.custom_question_id = %d AND q.status = 'publish'",
            $custom_question_id
        ), ARRAY_A);

        if (!$question_data) {
            return new WP_Error('rest_question_not_found', 'Question not found.', ['status' => 404]);
        }

        $question_id = $question_data['question_id'];
        $group_id = $question_data['group_id'];

        // Get Subject/Topic Hierarchy
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
        $subject_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $group_id, $subject_tax_id));
        $question_data['subject_lineage'] = $subject_term_id ? qp_get_term_lineage_names($subject_term_id, $wpdb, $term_table) : [];
        
        // Get Source/Section Hierarchy
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
        $source_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $group_id, $source_tax_id));
        $question_data['source_lineage'] = $source_term_id ? qp_get_term_lineage_names($source_term_id, $wpdb, $term_table) : [];


        $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;
        unset($question_data['direction_image_id']);
        unset($question_data['group_id']); // No need to expose group_id in API response

        $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY RAND()", $question_id), ARRAY_A);
        $question_data['options'] = $options;

        return new WP_REST_Response($question_data, 200);
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

    public static function get_topics() {
        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';

        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        if (!$subject_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS topic_id, name AS topic_name, parent AS subject_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0 ORDER BY name ASC",
            $subject_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    public static function get_exams() {
        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';

        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");

        if (!$exam_tax_id) {
            return new WP_REST_Response([], 200); // Return empty if taxonomy doesn't exist
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id as exam_id, name as exam_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $exam_tax_id
        ));

        return new WP_REST_Response($results, 200);
    }

    public static function get_sources() {
        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';

        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

        if (!$source_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS source_id, name AS source_name, parent AS parent_id FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $source_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    public static function get_labels() {
        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';
        $meta_table = $wpdb->prefix . 'qp_term_meta';

        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");

        if (!$label_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id as label_id, t.name as label_name, m.meta_value as label_color
             FROM {$term_table} t
             LEFT JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'color'
             WHERE t.taxonomy_id = %d 
             ORDER BY t.name ASC",
            $label_tax_id
        ));

        return new WP_REST_Response($results, 200);
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