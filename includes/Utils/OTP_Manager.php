<?php
namespace QuestionPress\Utils;

if ( ! defined( 'ABSPATH' ) ) exit;

use QuestionPress\Database\DB;

/**
 * Handles generating, sending, and verifying OTP codes for various purposes.
 */
class OTP_Manager extends DB {

    private static $table_name = 'qp_otp_verification';
    private static $expiry_minutes = 10;

    private static function get_table() {
        return self::$wpdb->prefix . self::$table_name;
    }

    private static function generate_code() {
        return (string) rand(100000, 999999);
    }

    /**
     * Generates and sends an OTP, invalidating old pending ones.
     */
    public static function generate_and_send($email, $purpose = 'registration') {
        $email = sanitize_email($email);
        if (!is_email($email)) return new \WP_Error('invalid_email', 'Invalid email address.');

        // CONSTRAINT: Invalidate all existing pending codes for this user and purpose
        self::$wpdb->update(
            self::get_table(),
            ['status' => 'expired'],
            ['email' => $email, 'purpose' => $purpose, 'status' => 'pending']
        );

        $plain_code = self::generate_code();
        $code_hash = wp_hash_password($plain_code);
        $expires_at = date('Y-m-d H:i:s', time() + (self::$expiry_minutes * 60));

        $inserted = self::$wpdb->insert(
            self::get_table(),
            [
                'email' => $email,
                'code_hash' => $code_hash,
                'purpose' => $purpose,
                'expires_at' => $expires_at,
                'status' => 'pending'
            ]
        );

        if (!$inserted) return new \WP_Error('db_error', 'Could not generate code.');

        $subject = ($purpose === 'password_reset') ? 'Password Reset Code' : 'Verification Code';
        $message = sprintf("Your code is: %s\nValid for %d minutes.", $plain_code, self::$expiry_minutes);
        
        if (!wp_mail($email, $subject, $message)) {
            return new \WP_Error('mail_error', 'Could not send email.');
        }

        return true;
    }

    /**
     * Strictly verifies a code against email and purpose.
     */
    public static function verify($email, $otp, $purpose = 'registration') {
        $email = sanitize_email($email);
        $otp = preg_replace('/[^0-9]/', '', $otp);

        $record = self::$wpdb->get_row(self::$wpdb->prepare(
            "SELECT * FROM " . self::get_table() . " 
             WHERE email = %s AND purpose = %s AND status = 'pending' 
             ORDER BY id DESC LIMIT 1",
            $email, $purpose
        ));

        if (!$record) return new \WP_Error('not_found', 'No valid verification request found.');

        if (time() > strtotime($record->expires_at)) {
            self::$wpdb->update(self::get_table(), ['status' => 'expired'], ['id' => $record->id]);
            return new \WP_Error('expired', 'Code expired.');
        }

        if (!wp_check_password($otp, $record->code_hash)) {
            return new \WP_Error('invalid_code', 'Incorrect code.');
        }

        return $record;
    }

    public static function mark_verified($id, $data = []) {
        $update = array_merge(['status' => 'verified'], $data);
        self::$wpdb->update(self::get_table(), $update, ['id' => $id]);
    }
}