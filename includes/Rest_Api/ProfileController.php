<?php

namespace QuestionPress\Rest_Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use QuestionPress\Utils\Profile_Manager;

class ProfileController {

    public static function update_profile( WP_REST_Request $request ) {
        $result = Profile_Manager::update_profile(
            get_current_user_id(),
            sanitize_text_field( $request->get_param( 'display_name' ) ),
            sanitize_email( $request->get_param( 'user_email' ) )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public static function change_password( WP_REST_Request $request ) {
        $result = Profile_Manager::change_password(
            get_current_user_id(),
            $request->get_param( 'current_password' ),
            $request->get_param( 'new_password' ),
            $request->get_param( 'confirm_password' )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public static function upload_avatar( WP_REST_Request $request ) {
        $result = Profile_Manager::handle_avatar_upload( get_current_user_id(), 'qp_avatar_upload' );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }
}