<?php

namespace QuestionPress\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_Error;

/**
 * Handles authentication and user-related business logic.
 */
class Auth_Manager {

    /**
     * Checks if a username is available.
     *
     * @param array $params The parameters for the check.
     * @return array|WP_Error An array with availability status, or a WP_Error on failure.
     */
    public static function check_username_availability( $params ) {
        $username = isset($params['username']) ? sanitize_user($params['username']) : '';

        if (empty($username)) {
            return new WP_Error('empty_username', 'Username cannot be empty.', ['status' => 400]);
        }

        if (username_exists($username)) {
            return new WP_Error('username_taken', 'Username is already taken.', ['status' => 409]);
        } else {
            return ['message' => 'Username is available.'];
        }
    }

    /**
     * Checks if an email is available.
     *
     * @param array $params The parameters for the check.
     * @return array|WP_Error An array with availability status, or a WP_Error on failure.
     */
    public static function check_email_availability( $params ) {
        $email = isset($params['email']) ? sanitize_email($params['email']) : '';

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Please enter a valid email.', ['status' => 400]);
        }

        if (email_exists($email)) {
            return new WP_Error('email_taken', 'Email is already registered.', ['status' => 409]);
        } else {
            return ['message' => 'Email is available.'];
        }
    }

    /**
     * Resends an OTP code for registration.
     *
     * @param array $params The parameters for resending OTP, typically containing the email.
     * @return array|WP_Error An array with a success message, or a WP_Error on failure.
     */
    public static function resend_registration_otp( $params ) {
        if ( session_status() === PHP_SESSION_NONE ) {
            session_start();
        }

        $email = $params['email'] ?? ($_SESSION['qp_signup_data']['email'] ?? '');

        if (empty($email)) {
            return new WP_Error('session_expired', 'Your session has expired. Please go back.', ['status' => 400]);
        }
        
        $result = \QuestionPress\Utils\OTP_Manager::generate_and_send($email);

        if (is_wp_error($result)) {
            return $result;
        } else {
            return ['message' => 'A new code has been sent.'];
        }
    }
}