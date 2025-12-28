<?php

namespace QuestionPress\Ajax;

use QuestionPress\Utils\Profile_Manager;

class Profile_Ajax {

    public static function save_profile() {
        check_ajax_referer( 'qp_save_profile_nonce', '_qp_profile_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $result = Profile_Manager::update_profile(
            get_current_user_id(),
            isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
            isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : ''
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], $result->get_error_data()['status'] ?? 400 );
        }

        wp_send_json_success( $result );
    }

    public static function change_password() {
        check_ajax_referer( 'qp_change_password_nonce', '_qp_password_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $result = Profile_Manager::change_password(
            get_current_user_id(),
            $_POST['current_password'] ?? '',
            $_POST['new_password'] ?? '',
            $_POST['confirm_password'] ?? ''
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], $result->get_error_data()['status'] ?? 400 );
        }

        wp_send_json_success( $result );
    }

    public static function upload_avatar() {
        check_ajax_referer( 'qp_save_profile_nonce', '_qp_profile_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $result = Profile_Manager::handle_avatar_upload( get_current_user_id(), 'qp_avatar_upload' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], $result->get_error_data()['status'] ?? 400 );
        }

        wp_send_json_success( $result );
    }
}