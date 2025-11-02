<?php
// Use the correct namespace
namespace QuestionPress\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WordPress cron events for Question Press.
 *
 * @package QuestionPress\Core
 */
class Cron {

    /**
     * Constructor.
     */
    public function __construct() {
        // Constructor can be used for setup if needed
    }

    /**
     * Ensures the entitlement expiration cron job is scheduled.
     * Runs on WordPress initialization.
     *
     * @return void
     */
    public function ensure_cron_scheduled() {
        if ( ! wp_next_scheduled( 'qp_check_entitlement_expiration_hook' ) ) {
            wp_schedule_event( time(), 'daily', 'qp_check_entitlement_expiration_hook' );
            error_log( "QP Cron: Re-scheduled entitlement expiration check on init." );
        }
    }

    /**
     * The callback function executed by the WP-Cron job to update expired entitlements.
     *
     * @return void
     */
    public function run_entitlement_expiration_check() {
        error_log( "QP Cron: Running entitlement expiration check..." );
        global $wpdb;
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $current_time       = current_time( 'mysql' );

        // Find entitlement records that are 'active' but whose expiry date is in the past
        $expired_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT entitlement_id
             FROM {$entitlements_table}
             WHERE status = 'active'
             AND expiry_date IS NOT NULL
             AND expiry_date <= %s",
            $current_time
        ) );

        if ( ! empty( $expired_ids ) ) {
            $ids_placeholder = implode( ',', array_map( 'absint', $expired_ids ) );

            // Update the status of these records to 'expired'
            $updated_count = $wpdb->query(
                "UPDATE {$entitlements_table}
                 SET status = 'expired'
                 WHERE entitlement_id IN ($ids_placeholder)"
            );

            if ( $updated_count !== false ) {
                error_log( "QP Cron: Marked {$updated_count} entitlements as expired." );
            } else {
                error_log( "QP Cron: Error updating expired entitlements. DB Error: " . $wpdb->last_error );
            }
        } else {
            error_log( "QP Cron: No expired entitlements found to update." );
        }
    }

    /**
     * Schedules the session cleanup event if it's not already scheduled.
     *
     * @return void
     */
    public function schedule_session_cleanup() {
        if ( ! wp_next_scheduled( 'qp_cleanup_abandoned_sessions_event' ) ) {
            wp_schedule_event( time(), 'hourly', 'qp_cleanup_abandoned_sessions_event' );
        }
    }

    /**
     * The function that runs on the scheduled cron event to clean up old sessions.
     *
     * @return void
     */
    public function cleanup_abandoned_sessions() {
        global $wpdb;
        $options         = get_option( 'qp_settings' );
        $timeout_minutes = isset( $options['session_timeout'] ) ? absint( $options['session_timeout'] ) : 20;

        if ( $timeout_minutes < 5 ) {
            $timeout_minutes = 20;
        }

        $sessions_table = $wpdb->prefix . 'qp_user_sessions';

        // --- 1. Handle Expired Mock Tests ---
        $active_mock_tests = $wpdb->get_results(
            "SELECT session_id, start_time, settings_snapshot FROM {$sessions_table} WHERE status = 'mock_test'"
        );

        foreach ( $active_mock_tests as $test ) {
            $settings         = json_decode( $test->settings_snapshot, true );
            $duration_seconds = $settings['timer_seconds'] ?? 0;

            if ( $duration_seconds <= 0 ) {
                continue;
            }

            $start_time_gmt  = get_gmt_from_date( $test->start_time );
            $start_timestamp = strtotime( $start_time_gmt );
            $end_timestamp   = $start_timestamp + $duration_seconds;

            // If the current time is past the test's official end time, finalize it as abandoned.
            if ( time() > $end_timestamp ) {
                // Our updated function will delete it if empty, or mark as abandoned if there are attempts.
                \QuestionPress\Utils\Session_Manager::finalize_and_end_session( $test->session_id, 'abandoned', 'abandoned_by_system' );
            }
        }

        // --- 2. Handle Abandoned 'active' sessions ---
        $abandoned_sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, settings_snapshot FROM {$sessions_table}
             WHERE status = 'active' AND last_activity < NOW() - INTERVAL %d MINUTE",
            $timeout_minutes
        ) );

        if ( ! empty( $abandoned_sessions ) ) {
            foreach ( $abandoned_sessions as $session ) {
                $settings            = json_decode( $session->settings_snapshot, true );
                $is_section_practice = isset( $settings['practice_mode'] ) && $settings['practice_mode'] === 'Section Wise Practice';

                if ( $is_section_practice ) {
                    // For section practice, just pause the session instead of abandoning it.
                    $wpdb->update(
                        $sessions_table,
                        [ 'status' => 'paused' ],
                        [ 'session_id' => $session->session_id ]
                    );
                } else {
                    // For all other modes, use the standard abandon/delete logic.
                    \QuestionPress\Utils\Session_Manager::finalize_and_end_session( $session->session_id, 'abandoned', 'abandoned_by_system' );
                }
            }
        }
    }

}