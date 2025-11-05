<?php
namespace QuestionPress\Ajax; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AJAX requests related to user profile management.
 */
class Profile_Ajax {

    /**
     * AJAX handler to save user profile (display name and email).
     */
    public static function save_profile() {
        // 1. Security Checks
        check_ajax_referer('qp_save_profile_nonce', '_qp_profile_nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.'], 401);
        }
        $user_id = get_current_user_id();

        // 2. Get and Sanitize Data
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $user_email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';

        // 3. Basic Validation
        if (empty($display_name) || empty($user_email)) {
            wp_send_json_error(['message' => 'Display Name and Email Address cannot be empty.'], 400);
        }
        if (!is_email($user_email)) {
            wp_send_json_error(['message' => 'Please enter a valid Email Address.'], 400);
        }

        // 4. Check if email is used by ANOTHER user
        $existing_user = email_exists($user_email);
        if ($existing_user && $existing_user != $user_id) {
            wp_send_json_error(['message' => 'This email address is already registered by another user.'], 409); // 409 Conflict
        }

        // 5. Prepare User Data for Update
        $user_data = [
            'ID'           => $user_id,
            'display_name' => $display_name,
            'user_email'   => $user_email,
        ];

        // 6. Update User
        $result = wp_update_user($user_data);

        // 7. Send Response
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'Error updating profile: ' . $result->get_error_message()], 500);
        } else {
            // Success: Send back the updated data for the frontend
            wp_send_json_success([
                'message' => 'Profile updated successfully!',
                'display_name' => $display_name,
                'user_email' => $user_email
            ]);
        }
    }

    /**
     * AJAX handler to change user password securely.
     */
    public static function change_password() {
        // 1. Security Checks
        check_ajax_referer('qp_change_password_nonce', '_qp_password_nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.'], 401);
        }
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        // 2. Get and Sanitize Passwords
        $current_password = isset($_POST['current_password']) ? wp_unslash($_POST['current_password']) : '';
        $new_password = isset($_POST['new_password']) ? wp_unslash($_POST['new_password']) : '';
        $confirm_password = isset($_POST['confirm_password']) ? wp_unslash($_POST['confirm_password']) : '';

        // 3. Validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            wp_send_json_error(['message' => 'All password fields are required.'], 400);
        }

        // 4. Verify Current Password *** SECURITY CRITICAL ***
        if (!wp_check_password($current_password, $user->user_pass, $user->ID)) {
            wp_send_json_error(['message' => 'Your current password does not match. Please try again.'], 403); // 403 Forbidden
        }

        // 5. Verify New Passwords Match
        if ($new_password !== $confirm_password) {
            wp_send_json_error(['message' => 'The new passwords do not match.'], 400);
        }

        // 6. (Optional but Recommended) Add Password Strength Check
        // You might want to add checks for minimum length, complexity, etc. here
        // if (strlen($new_password) < 8) {
        //     wp_send_json_error(['message' => 'New password must be at least 8 characters long.'], 400);
        // }

        // 7. Update Password
        wp_set_password($new_password, $user->ID);

        // Optional: Log the user out of other sessions for security after password change
        // wp_logout_all_sessions();

        // 8. Send Success Response
        wp_send_json_success(['message' => 'Password updated successfully!']);
    }

    /**
     * AJAX handler to upload a new user avatar, delete the old one, and update user meta.
     */
    public static function upload_avatar() {
        // 1. Security Checks
        // Use the nonce generated in the profile form
        check_ajax_referer('qp_save_profile_nonce', '_qp_profile_nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in.'], 401);
        }
        $user_id = get_current_user_id();

        // 2. Check if file was uploaded correctly
        if (!isset($_FILES['qp_avatar_upload']) || $_FILES['qp_avatar_upload']['error'] !== UPLOAD_ERR_OK) {
            $error_code = $_FILES['qp_avatar_upload']['error'] ?? UPLOAD_ERR_NO_FILE;
            $error_messages = [
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            ];
            wp_send_json_error(['message' => $error_messages[$error_code] ?? 'Unknown upload error.'], 400);
            return;
        }

        $file = $_FILES['qp_avatar_upload'];

        // 3. Include necessary WordPress file handling functions
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // 4. Handle the upload using media_handle_upload()
        // 'qp_avatar_upload' is the name attribute of our file input
        // 0 means the attachment is not associated with any specific post
        $attachment_id = media_handle_upload('qp_avatar_upload', 0);

        // 5. Check for upload errors
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => 'Error uploading file: ' . $attachment_id->get_error_message()], 500);
            return;
        }

        // 6. Get the ID of the PREVIOUS custom avatar (if any)
        $previous_avatar_id = get_user_meta($user_id, '_qp_avatar_attachment_id', true);

        // 7. Update user meta with the NEW attachment ID
        update_user_meta($user_id, '_qp_avatar_attachment_id', $attachment_id);

        // 8. Delete the PREVIOUS attachment (if it exists and is different from the new one)
        if (!empty($previous_avatar_id) && $previous_avatar_id != $attachment_id) {
            wp_delete_attachment($previous_avatar_id, true); // true forces delete, bypassing trash
        }

        // 9. Get the URL of the newly uploaded image (use a reasonable size)
        $new_avatar_url = wp_get_attachment_image_url($attachment_id, 'thumbnail'); // 'thumbnail' or 'medium' size

        // 10. Send Success Response
        wp_send_json_success([
            'message' => 'Avatar updated successfully!',
            'new_avatar_url' => $new_avatar_url ?: get_avatar_url($user_id) // Fallback to Gravatar if URL fetch fails
        ]);
    }

} // End class Profile_Ajax