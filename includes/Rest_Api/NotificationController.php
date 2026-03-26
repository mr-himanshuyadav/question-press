<?php
namespace QuestionPress\Rest_Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for global notification management.
 */
class NotificationController {

    /**
     * Registers or updates a device token for the current user.
     * POST /questionpress/v1/notifications/register
     */
    public static function register_device_token( \WP_REST_Request $request ) {
        global $wpdb;
        $user_id = get_current_user_id();
        $token   = $request->get_param('device_token');
        $platform = $request->get_param('platform') ?: 'expo';

        error_log('TOKEN: ' . $token);

        // Validate Expo Push Token format: ExponentPushToken[...]
        $token = trim($token);

        error_log('Trimmed TOKEN: ' . $token);

        if (!preg_match('/^ExponentPushToken\[[A-Za-z0-9_-]+\]$/', $token)) {
            error_log('Invalid push token format.');
            return new \WP_REST_Response(
                ['success' => false, 'message' => 'Invalid push token format.'],
                400
            );
        }

        $table = $wpdb->prefix . 'qp_device_tokens';
        $wpdb->replace( $table, [
            'user_id'    => $user_id,
            'token'      => $token,
            'platform'   => sanitize_text_field( $platform ),
            'updated_at' => current_time( 'mysql' )
        ] );

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Token registered.' ], 200 );
    }

    /**
 * Removes user association from a device token (keeps token for anonymous use)
 * POST /questionpress/v1/notifications/deregister
 */
public static function deregister_device_token( \WP_REST_Request $request ) {
    global $wpdb;

    $user_id = get_current_user_id();
    $token   = $request->get_param('device_token');

    error_log('🔵 Deregister request for user ID: ' . $user_id);
    error_log('🔵 Token to deregister: ' . $token);

    if ( ! $token ) {
        return new \WP_REST_Response(
            [ 'success' => false, 'message' => 'Token is required.' ],
            400
        );
    }

    $table = $wpdb->prefix . 'qp_device_tokens';

    // 🔥 Instead of DELETE → UPDATE user_id = 0
    $updated = $wpdb->update(
        $table,
        [ 'user_id' => 0 ],
        [
            'user_id' => $user_id,
            'token'   => $token
        ],
        [ '%d' ],
        [ '%d', '%s' ]
    );

    if ( $updated === false ) {
        error_log('❌ Failed to update token for user ID: ' . $user_id);

        return new \WP_REST_Response(
            [ 'success' => false, 'message' => 'Database error.' ],
            500
        );
    }

    if ( $updated === 0 ) {
        error_log('⚠️ No rows updated. Token may not exist or already deregistered.');

        return new \WP_REST_Response(
            [ 'success' => false, 'message' => 'Token not found or already deregistered.' ],
            404
        );
    }

    error_log('✅ Token orphaned (user_id set to 0) for user ID: ' . $user_id);

    return new \WP_REST_Response(
        [ 'success' => true, 'message' => 'Token detached from user.' ],
        200
    );
}

    /**
     * Internal utility to fetch all active tokens for a specific user.
     * @param int $user_id
     * @return string[]
     */
    public static function get_tokens_for_user( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'qp_device_tokens';
        return $wpdb->get_col( $wpdb->prepare( "SELECT token FROM $table WHERE user_id = %d", $user_id ) ) ?: [];
    }
}