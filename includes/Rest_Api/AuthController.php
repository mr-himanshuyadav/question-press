<?php
namespace QuestionPress\Rest_Api; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// We need to use these classes
use WP_REST_Request;
use WP_Error;
use WP_REST_Server;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Handles REST API requests for authentication.
 */
class AuthController {

    /**
     * Permission callback. Checks for a valid JWT in the Authorization header.
     */
    public static function check_auth_token( \WP_REST_Request $request ) {
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

    /**
     * Callback to generate and return a JWT auth token.
     */
    public static function get_auth_token( \WP_REST_Request $request ) {
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

        return new \WP_REST_Response([
            'token'             => $token,
            'user_email'        => $user->user_email,
            'user_nicename'     => $user->user_nicename,
            'user_display_name' => $user->display_name
        ], 200);
    }

} // End class AuthController