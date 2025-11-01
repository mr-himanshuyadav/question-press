<?php
// Use the correct namespace
namespace QuestionPress\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WordPress rewrite rules and query variables.
 *
 * @package QuestionPress\Core
 */
class Rewrites {

    /**
     * Register custom query variables for dashboard routing.
     *
     * @param array $vars Existing query variables.
     * @return array Modified query variables.
     */
    public static function register_query_vars( $vars ) {
        $vars[] = 'qp_tab';          // To identify the main dashboard section (e.g., 'history', 'courses')
        $vars[] = 'qp_course_slug'; // To identify a specific course by its slug
        return $vars;
    }

    /**
     * Add rewrite rules for the dynamic dashboard URLs.
     */
    public static function add_dashboard_rewrite_rules() {
        $options = get_option( 'qp_settings' );
        $dashboard_page_id = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;

        if ( $dashboard_page_id <= 0 ) {
            return; // No page set, do nothing.
        }

        // Get the page's path relative to the home URL (e.g., "dashboard")
        // This will be an EMPTY STRING if the page is the site's front page.
        $dashboard_path = get_page_uri( $dashboard_page_id );
        $is_front_page = ( $dashboard_path === '' ); // Check if it's the front page

        // Define the known tabs
        $tabs      = [ 'overview', 'history', 'review', 'progress', 'courses', 'profile' ];
        $tab_regex = implode( '|', $tabs );

        if ( ! $is_front_page ) {
            // --- CASE 1: Dashboard is a SUB-PAGE (e.g., /dashboard/) ---

            // Rule for specific course: /dashboard-path/courses/course-slug/
            add_rewrite_rule(
                '^' . $dashboard_path . '/courses/([^/]+)/?$',
                // --- FIX: Use page_id instead of pagename ---
                'index.php?page_id=' . $dashboard_page_id . '&qp_tab=courses&qp_course_slug=$matches[1]',
                'top'
            );

            // Rule for a specific tab: /dashboard-path/tab-name/
            add_rewrite_rule(
                '^' . $dashboard_path . '/(' . $tab_regex . ')/?$',
                // --- FIX: Use page_id instead of pagename ---
                'index.php?page_id=' . $dashboard_page_id . '&qp_tab=$matches[1]',
                'top'
            );

            // Rule for the base dashboard URL: /dashboard-path/
            add_rewrite_rule(
                '^' . $dashboard_path . '/?$',
                // --- FIX: Use page_id instead of pagename ---
                'index.php?page_id=' . $dashboard_page_id,
                'top'
            );

        } else {
            // --- CASE 2: Dashboard IS THE FRONT PAGE (path is an empty string) ---

            // Rule for specific course on front page: /tab/courses/course-slug/
            add_rewrite_rule(
                '^tab/courses/([^/]+)/?$', // (This was correct from last time)
                'index.php?page_id=' . $dashboard_page_id . '&qp_tab=courses&qp_course_slug=$matches[1]',
                'top'
            );

            // Rule for a specific tab on front page: /tab/tab-name/
            add_rewrite_rule(
                '^tab/(' . $tab_regex . ')/?$', // (This was correct from last time)
                'index.php?page_id=' . $dashboard_page_id . '&qp_tab=$matches[1]',
                'top'
            );

            // The base front page (/) is already handled by WordPress.
        }
    }

}