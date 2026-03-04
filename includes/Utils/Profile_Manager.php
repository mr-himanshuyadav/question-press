<?php

namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_Error;

/**
 * Single source of truth for User Profile management.
 */
class Profile_Manager {

    /**
     * Updates the user's basic profile information.
     */
    public static function update_profile( $user_id, $display_name, $user_email ) {
        if ( empty( $display_name ) || empty( $user_email ) ) {
            return new WP_Error( 'invalid_data', 'Display Name and Email Address cannot be empty.', [ 'status' => 400 ] );
        }

        if ( ! is_email( $user_email ) ) {
            return new WP_Error( 'invalid_email', 'Please enter a valid Email Address.', [ 'status' => 400 ] );
        }

        $existing_user = email_exists( $user_email );
        if ( $existing_user && $existing_user != $user_id ) {
            return new WP_Error( 'email_taken', 'This email address is already registered by another user.', [ 'status' => 409 ] );
        }

        $result = wp_update_user( [
            'ID'           => $user_id,
            'display_name' => $display_name,
            'user_email'   => $user_email,
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'message'      => 'Profile updated successfully!',
            'display_name' => $display_name,
            'user_email'   => $user_email,
        ];
    }

    /**
     * Validates and updates user password.
     */
    public static function change_password( $user_id, $current_password, $new_password, $confirm_password ) {
        $user = get_userdata( $user_id );

        if ( empty( $current_password ) || empty( $new_password ) || empty( $confirm_password ) ) {
            return new WP_Error( 'missing_fields', 'All password fields are required.', [ 'status' => 400 ] );
        }

        if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
            return new WP_Error( 'wrong_password', 'Your current password does not match.', [ 'status' => 403 ] );
        }

        if ( $new_password !== $confirm_password ) {
            return new WP_Error( 'mismatch', 'The new passwords do not match.', [ 'status' => 400 ] );
        }

        wp_set_password( $new_password, $user->ID );

        return [ 'message' => 'Password updated successfully!' ];
    }

    /**
     * Handles file upload and attachment management for avatars.
     */
    public static function handle_avatar_upload( $user_id, $file_input_key ) {
        if ( ! isset( $_FILES[ $file_input_key ] ) || $_FILES[ $file_input_key ]['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', 'No file was uploaded or an upload error occurred.', [ 'status' => 400 ] );
        }

        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        $attachment_id = media_handle_upload( $file_input_key, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        $previous_avatar_id = get_user_meta( $user_id, '_qp_avatar_attachment_id', true );
        update_user_meta( $user_id, '_qp_avatar_attachment_id', $attachment_id );

        if ( ! empty( $previous_avatar_id ) && $previous_avatar_id != $attachment_id ) {
            wp_delete_attachment( $previous_avatar_id, true );
        }

        return [
            'message'        => 'Avatar updated successfully!',
            'new_avatar_url' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: get_avatar_url( $user_id ),
        ];
    }
}