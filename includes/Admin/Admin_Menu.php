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
use QuestionPress\Database\Terms_DB;

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

	/**
     * Handles the one-time database migration to denormalize term IDs.
     * This is triggered by a GET request from the Tools page.
     */
    public static 	function handle_one_time_migration() {
        // 1. Check if our trigger is set
        if (!isset($_GET['qp_run_migration']) || $_GET['qp_run_migration'] !== 'true') {
            return;
        }

        // 2. Security Check: Verify the nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qp_term_migration_nonce')) {
            wp_die('Security check failed. Please go back and try again.');
        }

        // 3. Permissions Check
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to run this script.');
        }

        // 4. Set up Batch Processing
        $batch_size = 500; // Process 50 groups at a time. Adjust if needed.
        $batch = isset($_GET['batch']) ? absint($_GET['batch']) : 1;
        $offset = ($batch - 1) * $batch_size;

        global $wpdb;
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        // 5. Get the batch of group IDs to process
        $group_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT group_id FROM {$groups_table} LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ));

        if (empty($group_ids)) {
            // No more groups to process. We are done!
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p><strong>QuestionPress Migration Complete:</strong> All existing question and group data has been successfully updated with the new term structure.</p></div>';
            });
            // Remove the migration button
            remove_action('admin_notices', [Admin_Utils::class, 'show_migration_progress_notice']);
            echo '<script> document.getElementById("qp-migration-tool").style.display = "none"; </script>';
            return; // Stop the process
        }

        // Get Taxonomy IDs just once
        $tax_ids = [
            'subject' => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'"),
            'source'  => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'"),
            'exam'    => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'"),
        ];
        
        $processed_count = 0;

        // 6. Loop through each group in this batch
        foreach ($group_ids as $group_id) {
            
            // Find all linked terms for this group
            $linked_terms = $wpdb->get_results($wpdb->prepare(
                "SELECT t.term_id, t.taxonomy_id
                 FROM {$term_table} t
                 JOIN {$rel_table} r ON t.term_id = r.term_id
                 WHERE r.object_id = %d AND r.object_type = 'group'",
                $group_id
            ));

            $specific_subject_id = 0;
            $specific_source_id = 0;
            $exam_id = 0;

            // Find the specific term for each taxonomy
            foreach ($linked_terms as $term) {
                if ($term->taxonomy_id == $tax_ids['subject']) {
                    $specific_subject_id = $term->term_id;
                } elseif ($term->taxonomy_id == $tax_ids['source']) {
                    $specific_source_id = $term->term_id;
                } elseif ($term->taxonomy_id == $tax_ids['exam']) {
                    $exam_id = $term->term_id;
                }
            }

            // 7. Get the full term lineage using your helper function
            $subject_lineage = Terms_DB::get_term_lineage_ids($specific_subject_id);
            $source_lineage  = Terms_DB::get_term_lineage_ids($specific_source_id);

            // 8. Prepare the data to save
            $denormalized_data = [
                'primary_subject_term_id'  => $subject_lineage['primary'],
                'specific_subject_term_id' => $subject_lineage['specific'],
                'primary_source_term_id'   => $source_lineage['primary'],
                'specific_source_term_id'  => $source_lineage['specific'],
                'exam_term_id'             => $exam_id > 0 ? $exam_id : null, // Store null if 0
            ];

            // 9. Update the qp_question_groups table
            $wpdb->update(
                $groups_table,
                $denormalized_data,
                ['group_id' => $group_id]
            );

            // 10. Update ALL qp_questions for this group
            $wpdb->update(
                $questions_table,
                $denormalized_data,
                ['group_id' => $group_id]
            );
            
            $processed_count++;
        }

        // 11. Show a "next batch" notice and auto-refresh to the next batch
        $next_batch = $batch + 1;
        $redirect_url = admin_url('admin.php?page=qp-tools&tab=backup&qp_run_migration=true&batch=' . $next_batch . '&_wpnonce=' . $_GET['_wpnonce']);




		// Remove this after migration

        // Add a persistent notice
        add_action('admin_notices', [Admin_Utils::class, 'show_migration_progress_notice']);
        
        // Auto-redirect to the next batch using JavaScript
        echo '<script>window.location.href = "' . esc_url_raw($redirect_url) . '";</script>';
        exit; // Stop further page rendering
    }

    /**
     * Displays a persistent "In Progress" notice during migration.
     */
    public static function show_migration_progress_notice() {
        $batch = isset($_GET['batch']) ? absint($_GET['batch']) : 1;
        echo '<div class="notice notice-info"><p><strong>QuestionPress Migration In Progress:</strong> Processing batch ' . esc_html($batch) . '... Please do not close this window. This page will refresh automatically.</p></div>';
    }
}