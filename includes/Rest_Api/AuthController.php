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
use QuestionPress\Utils\Auth_Manager; // Business logic for availability
use QuestionPress\Utils\OTP_Manager;  // Multi-purpose OTP engine

/**
 * Handles REST API requests for authentication, registration, and password management.
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
            'success' => true,
            'data' => [
                'token'             => $token,
                'user_email'        => $user->user_email,
                'user_nicename'     => $user->user_nicename,
                'user_display_name' => $user->display_name
            ]
        ], 200);
    }

    /**
     * Checks if a username is available.
     */
    public static function check_username_availability( WP_REST_Request $request ) {
        $params = $request->get_params();
        $result = Auth_Manager::check_username_availability( $params );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new \WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    /**
     * Checks if an email is available.
     */
    public static function check_email_availability( WP_REST_Request $request ) {
        $params = $request->get_params();
        $result = Auth_Manager::check_email_availability( $params );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new \WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    /**
     * REGISTRATION STEP 1: Request OTP for user registration.
     */
    public static function request_registration_otp( WP_REST_Request $request ) {
        $email = sanitize_email($request->get_param('email'));
        if ( empty($email) ) {
            return new WP_Error('invalid_email', 'Email is required.', ['status' => 400]);
        }

        if ( email_exists($email) ) {
            return new WP_Error('email_taken', 'Email is already registered.', ['status' => 409]);
        }

        $result = OTP_Manager::generate_and_send($email, 'registration');
        if ( is_wp_error($result) ) return $result;

        return new \WP_REST_Response(['success' => true, 'data' => ['message' => 'Verification code sent to email.']], 200);
    }

    /**
     * REGISTRATION STEP 2: Register user with OTP verification.
     */
    public static function register_user( WP_REST_Request $request ) {
        $username = sanitize_user($request->get_param('username'));
        $email    = sanitize_email($request->get_param('email'));
        $password = $request->get_param('password');
        $otp      = $request->get_param('otp');

        if ( empty($username) || empty($email) || empty($password) || empty($otp) ) {
            return new WP_Error('missing_fields', 'All fields are required.', ['status' => 400]);
        }

        // 1. Verify OTP using the registration purpose
        $record = OTP_Manager::verify($email, $otp, 'registration');
        if ( is_wp_error($record) ) return $record;

        // 2. Final availability check before creation
        if ( username_exists($username) ) return new WP_Error('username_taken', 'Username already exists.', ['status' => 409]);
        if ( email_exists($email) ) return new WP_Error('email_taken', 'Email already exists.', ['status' => 409]);

        // 3. Create User account
        $user_id = wp_create_user($username, $password, $email);
        if ( is_wp_error($user_id) ) {
            return new WP_Error('registration_failed', $user_id->get_error_message(), ['status' => 500]);
        }

        // 4. Invalidate the OTP code
        OTP_Manager::mark_verified($record->id);

        return new \WP_REST_Response([
            'success' => true, 
            'data' => [
                'message' => 'Account created successfully!',
                'user_id' => $user_id
            ]
        ], 201);
    }

    /**
     * RESET STEP 1: Request OTP for password reset.
     */
    public static function request_password_reset(WP_REST_Request $request) {
        $email = sanitize_email($request->get_param('email'));
        if (!email_exists($email)) return new WP_Error('not_found', 'User not found.', ['status' => 404]);

        $result = OTP_Manager::generate_and_send($email, 'password_reset');
        if (is_wp_error($result)) return $result;

        return new \WP_REST_Response(['success' => true, 'data' => ['message' => 'OTP sent to email.']], 200);
    }

    /**
     * RESET STEP 2: Verify OTP and return reset_token.
     */
    public static function verify_password_reset(WP_REST_Request $request) {
        $email = sanitize_email($request->get_param('email'));
        $otp = $request->get_param('otp');

        $record = OTP_Manager::verify($email, $otp, 'password_reset');
        if (is_wp_error($record)) return $record;

        // Generate a temporary secure token for the final reset step
        $reset_token = wp_generate_password(32, false);
        OTP_Manager::mark_verified($record->id, ['reset_token' => $reset_token]);

        return new \WP_REST_Response(['success' => true, 'data' => ['reset_token' => $reset_token]], 200);
    }

    /**
     * RESET STEP 3: Use reset_token to set new password.
     */
    public static function finalize_password_reset(WP_REST_Request $request) {
        global $wpdb;
        $email = sanitize_email($request->get_param('email'));
        $token = $request->get_param('reset_token');
        $password = $request->get_param('new_password');

        $table = $wpdb->prefix . 'qp_otp_verification';
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE email = %s AND reset_token = %s AND status = 'verified' AND purpose = 'password_reset'",
            $email, $token
        ));

        if (!$record) return new WP_Error('forbidden', 'Invalid token.', ['status' => 403]);

        $user = get_user_by('email', $email);
        wp_set_password($password, $user->ID);
        $wpdb->update($table, ['status' => 'used'], ['id' => $record->id]);

        return new \WP_REST_Response(['success' => true, 'data' => ['message' => 'Password reset successful.']], 200);
    }
}