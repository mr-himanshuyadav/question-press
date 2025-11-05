<?php
namespace QuestionPress\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Admin\Views\All_Questions_Page;
use QuestionPress\Admin\Views\Organization_Page;
use QuestionPress\Admin\Views\Tools_Page;
use QuestionPress\Admin\Views\Merge_Terms_Page;
use QuestionPress\Admin\Views\User_Entitlements_Page;
use QuestionPress\Admin\Views\Logs_Reports_Page;
use QuestionPress\Admin\Views\Question_Editor_Page;
use QuestionPress\Admin\Views\Settings_Page;
use QuestionPress\Admin\Views\Questions_List_Table;

/**
 * Handles the registration of WordPress admin menu items.
 */
class Admin_Menu {

	/**
	 * Registers the admin menu pages for the plugin.
	 * Hooked into 'admin_menu'.
	 */
	public function register_menus() {
		// Add top-level menu page for "All Questions" and store the hook
		$hook = add_menu_page(
			'All Questions',                // Page title
			'Question Press',               // Menu title
			'manage_options',               // Capability
			'question-press',               // Menu slug
			[All_Questions_Page::class, 'render'], // CHANGED CALLBACK
			'dashicons-forms',              // Icon
			25                              // Position
		);

		// Screen Options for All questions page
		add_action( "load-{$hook}", [Questions_List_Table::class, 'add_screen_options'] );

		// Add submenu pages under the main "Question Press" menu
		add_submenu_page(
			'question-press',               // Parent slug
			'All Questions',                // Page title
			'All Questions',                // Menu title
			'manage_options',               // Capability
			'question-press',               // Menu slug (same as parent for default page)
			[All_Questions_Page::class, 'render'] // CHANGED CALLBACK
		);
		add_submenu_page(
			'question-press',
			'Add New',
			'Add New',
			'manage_options',
			'qp-question-editor',
			[Question_Editor_Page::class, 'render'] // Callback using existing global class
		);
		add_submenu_page(
			'question-press',
			'Organize',
			'Organize',
			'manage_options',
			'qp-organization',
			[Organization_Page::class, 'render']
		);
		add_submenu_page(
			'question-press',
			'Tools',
			'Tools',
			'manage_options',
			'qp-tools',
			[Tools_Page::class, 'render']
		);
		add_submenu_page(
			'question-press',
			'Reports',
			'Reports',
			'manage_options',
			'qp-logs-reports',
			[Logs_Reports_Page::class, 'render'] // Callback using existing global class
		);
		add_submenu_page(
			'question-press',
			'User Entitlements',
			'User Entitlements',
			'manage_options',
			'qp-user-entitlements',
			[User_Entitlements_Page::class, 'render']
		);
		add_submenu_page(
			'question-press',
			'Settings',
			'Settings',
			'manage_options',
			'qp-settings',
			[Settings_Page::class, 'render'] // Callback using existing global class
		);

		// Hidden pages (Callbacks still global or existing global classes)
		add_submenu_page(
			(string)null,                           // No parent menu item shown
			'Edit Question',                // Page title
			'Edit Question',                // Menu title (not shown)
			'manage_options',               // Capability
			'qp-edit-group',                // Menu slug
			[Question_Editor_Page::class, 'render'] // Callback
		);
		add_submenu_page(
			(string)null,
			'Merge Terms',
			'Merge Terms',
			'manage_options',
			'qp-merge-terms',
			[Merge_Terms_Page::class, 'render']
		);
	}

	/**
     * Adds a notification bubble with the count of open reports to the admin menu.
     */
    public function add_report_count_to_menu() {
        global $wpdb, $menu, $submenu;

        // Only show the count to users who can manage the plugin
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $reports_table = $wpdb->prefix . 'qp_question_reports';
        // Get the count of open reports (not just distinct questions)
        $open_reports_count = (int) $wpdb->get_var( "SELECT COUNT(report_id) FROM {$reports_table} WHERE status = 'open'" );

        if ( $open_reports_count > 0 ) {
            // Create the bubble HTML using standard WordPress classes
            $bubble = " <span class='awaiting-mod'><span class='count-{$open_reports_count}'>{$open_reports_count}</span></span>";

            // Determine if we are on a Question Press admin page.
            $is_qp_page = ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'qp-' ) === 0 ) || ( isset( $_GET['page'] ) && $_GET['page'] === 'question-press' );

            // Only add the bubble to the top-level menu if we are NOT on a Question Press page.
            if ( ! $is_qp_page ) {
                foreach ( $menu as $key => $value ) {
                    if ( $value[2] == 'question-press' ) {
                        $menu[ $key ][0] .= $bubble;
                        break;
                    }
                }
            }

            // Always add the bubble to the "Reports" submenu item regardless of the current page.
            if ( isset( $submenu['question-press'] ) ) {
                foreach ( $submenu['question-press'] as $key => $value ) {
                    if ( $value[2] == 'qp-logs-reports' ) {
                        $submenu['question-press'][ $key ][0] .= $bubble;
                        break;
                    }
                }
            }
        }
    }
}