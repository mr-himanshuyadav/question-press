<?php
namespace QuestionPress\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use QuestionPress\Admin\Views\All_Questions_Page;

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
		// Note: The callback 'qp_add_screen_options' is still global for now.
		// We hook it here, associated with the specific page load hook.
		add_action( "load-{$hook}", 'qp_add_screen_options' );

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
			['\QP_Question_Editor_Page', 'render'] // Callback using existing global class
		);
		add_submenu_page(
			'question-press',
			'Organize',
			'Organize',
			'manage_options',
			'qp-organization',
			'qp_render_organization_page' // Callback function (still global)
		);
		add_submenu_page(
			'question-press',
			'Tools',
			'Tools',
			'manage_options',
			'qp-tools',
			'qp_render_tools_page' // Callback function (still global)
		);
		add_submenu_page(
			'question-press',
			'Reports',
			'Reports',
			'manage_options',
			'qp-logs-reports',
			['\QP_Logs_Reports_Page', 'render'] // Callback using existing global class
		);
		add_submenu_page(
			'question-press',
			'User Entitlements',
			'User Entitlements',
			'manage_options',
			'qp-user-entitlements',
			'qp_render_user_entitlements_page' // Callback function (still global)
		);
		add_submenu_page(
			'question-press',
			'Settings',
			'Settings',
			'manage_options',
			'qp-settings',
			['\QP_Settings_Page', 'render'] // Callback using existing global class
		);

		// Hidden pages (Callbacks still global or existing global classes)
		add_submenu_page(
			null,                           // No parent menu item shown
			'Edit Question',                // Page title
			'Edit Question',                // Menu title (not shown)
			'manage_options',               // Capability
			'qp-edit-group',                // Menu slug
			['\QP_Question_Editor_Page', 'render'] // Callback
		);
		add_submenu_page(
			null,
			'Merge Terms',
			'Merge Terms',
			'manage_options',
			'qp-merge-terms',
			'qp_render_merge_terms_page' // Callback
		);
	}
}