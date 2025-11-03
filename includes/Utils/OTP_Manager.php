<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use QuestionPress\Database\DB;

/**
 * Handles generating, sending, and verifying OTP codes.
 */
class OTP_Manager extends DB {

    private static $table_name = 'qp_otp_verification';
    private static $expiry_minutes = 10; // OTPs are valid for 10 minutes

    /**
     * Get the full table name.
     */
    private static function get_table() {
        return self::$wpdb->prefix . self::$table_name;
    }

    /**
     * Generates a 6-digit numeric code.
     */
    private static function generate_code() {
        return (string) rand(100000, 999999);
    }

    /**
     * Generates, stores, and sends an OTP to an email address.
     *
     * @param string $email The user's email address.
     * @return bool|\WP_Error True on success, \WP_Error on failure.
     */
    public static function generate_and_send($email) {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', 'Invalid email address provided.');
        }

        $plain_code = self::generate_code();
        $code_hash = wp_hash_password($plain_code); // Use WordPress hasher
        $expires_at = date('Y-m-d H:i:s', time() + (self::$expiry_minutes * 60));

        // Invalidate old pending codes for this email
        self::$wpdb->update(
            self::get_table(),
            ['status' => 'expired'],
            ['email' => $email, 'status' => 'pending']
        );

        // Insert new code
        $inserted = self::$wpdb->insert(
            self::get_table(),
            [
                'email' => $email,
                'code_hash' => $code_hash,
                'expires_at' => $expires_at,
                'status' => 'pending'
            ]
        );

        if (!$inserted) {
            return new \WP_Error('db_error', 'Could not save OTP. Please try again.');
        }

        // Send the email
        $subject = 'Your Verification Code for ' . get_bloginfo('name');
        $message = sprintf(
            "Hello,\n\nYour verification code is: %s\n\nThis code is valid for %d minutes.\n\nIf you did not request this code, please ignore this email.",
            $plain_code,
            self::$expiry_minutes
        );
        
        $sent = wp_mail($email, $subject, $message);

        if (!$sent) {
            return new \WP_Error('mail_error', 'Could not send the verification email. Please check the site configuration.');
        }

        return true;
    }

    /**
     * Verifies a user-provided OTP against the stored hash.
     *
     * @param string $email The user's email.
     * @param string $otp   The 6-digit code from the user.
     * @return bool|\WP_Error True on success, \WP_Error on failure.
     */
    public static function verify($email, $otp) {
        $email = sanitize_email($email);
        $otp = preg_replace('/[^0-9]/', '', $otp); // Sanitize OTP

        if (strlen($otp) !== 6) {
            return new \WP_Error('invalid_code', 'Invalid OTP format. It must be 6 digits.');
        }

        // Get the most recent *pending* code for this email
        $record = self::$wpdb->get_row(self::$wpdb->prepare(
            "SELECT * FROM " . self::get_table() . "
             WHERE email = %s AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            $email
        ));

        if (!$record) {
            return new \WP_Error('not_found', 'No pending verification found. Please request a new code.');
        }

        // Check for expiry
        $current_time = time();
        $expiry_time = strtotime($record->expires_at);
        if ($current_time > $expiry_time) {
            // Mark as expired
            self::$wpdb->update(self::get_table(), ['status' => 'expired'], ['id' => $record->id]);
            return new \WP_Error('expired', 'This verification code has expired. Please request a new one.');
        }

        // Check the code
        if (!wp_check_password($otp, $record->code_hash)) {
            return new \WP_Error('invalid_code', 'The verification code is incorrect.');
        }

        // Success! Mark as verified
        self::$wpdb->update(
            self::get_table(),
            ['status' => 'verified'],
            ['id' => $record->id]
        );

        return true;
    }
}