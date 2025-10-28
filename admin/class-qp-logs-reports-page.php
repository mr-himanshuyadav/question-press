<?php
if (!defined('ABSPATH')) exit;

use QuestionPress\Database\Terms_DB;

// We will create these files in the next steps
require_once QP_PLUGIN_PATH . 'admin/class-qp-reports-list-table.php';
require_once QP_PLUGIN_PATH . 'admin/class-qp-log-settings-list-table.php';

class QP_Logs_Reports_Page {

    public static function render() {
        $tabs = [
            'reports' => ['label' => 'Reports', 'callback' => [self::class, 'render_reports_tab']],
            'log_settings' => ['label' => 'Log Settings', 'callback' => [self::class, 'render_log_settings_tab']],
        ];
        $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'reports';

        // --- Capture the output of the active tab's render function ---
        ob_start();
        call_user_func($tabs[$active_tab]['callback']);
        $tab_content_html = ob_get_clean();
        // --- End capturing ---

        // Prepare arguments for the wrapper template
        $args = [
            'tabs'             => $tabs,
            'active_tab'       => $active_tab,
            'tab_content_html' => $tab_content_html,
        ];
        
        // Load and echo the wrapper template
        echo qp_get_template_html( 'reports-page-wrapper', 'admin', $args );
    }

    public static function render_reports_tab() {
        global $wpdb;
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // Get counts for the view links
        $open_count = $wpdb->get_var("SELECT COUNT(DISTINCT question_id) FROM {$reports_table} WHERE status = 'open'");
        $resolved_count = $wpdb->get_var("SELECT COUNT(DISTINCT question_id) FROM {$reports_table} WHERE status = 'resolved'");
        
        $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'open';

        // Prepare the list table
        $list_table = new QP_Reports_List_Table();
        $list_table->prepare_items();
        
        // Capture the list table's HTML components
        ob_start();
        $list_table->search_box('Search Reports', 'report');
        $list_table_search_box_html = ob_get_clean();
        
        ob_start();
        $list_table->display();
        $list_table_display_html = ob_get_clean();

        // Prepare arguments for the template
        $args = [
            'open_count'               => $open_count,
            'resolved_count'           => $resolved_count,
            'current_status'           => $current_status,
            'list_table_search_box_html' => $list_table_search_box_html,
            'list_table_display_html'  => $list_table_display_html,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'reports-tab-main', 'admin', $args );
    }

    public static function render_log_settings_tab() {
        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';
        $reason_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'report_reason'");

        $term_to_edit = null;
        $is_active_for_edit = 1; // Default to active for new items
        $type_for_edit = 'report'; // Default to 'report' for new items

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['term_id'])) {
            $term_id = absint($_GET['term_id']);
            // Verify the nonce to ensure the request is legitimate
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_reason_' . $term_id)) {
                $term_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$term_table} WHERE term_id = %d", $term_id));
                if ($term_to_edit) {
                    $is_active_for_edit = Terms_DB::get_meta($term_id, 'is_active', true);
                    $type_for_edit = Terms_DB::get_meta($term_id, 'type', true) ?: 'report'; // Fetch the type, default to 'report' if not set
                }
            }
        }

        // Prepare the list table
        $list_table = new QP_Log_Settings_List_Table();
        $list_table->prepare_items();
        
        // Capture the list table's HTML
        ob_start();
        $list_table->display();
        $list_table_html = ob_get_clean();

        // Prepare arguments for the template
        $args = [
            'reason_tax_id'      => $reason_tax_id,
            'term_to_edit'       => $term_to_edit,
            'is_active_for_edit' => $is_active_for_edit,
            'type_for_edit'      => $type_for_edit,
            'list_table_html'    => $list_table_html,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'reports-tab-log-settings', 'admin', $args );
    }

    /**
     * Handles form submissions for the "Log Settings" (Report Reasons) tab.
     * Replaces the old qp_handle_log_settings_forms function.
     * Hooked to 'admin_init'.
     */
    public static function handle_log_settings_forms() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports' || !isset($_GET['tab']) || $_GET['tab'] !== 'log_settings') {
            return;
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        // Add/Update Reason
        if (isset($_POST['action']) && ($_POST['action'] === 'add_reason' || $_POST['action'] === 'update_reason') && check_admin_referer('qp_add_edit_reason_nonce')) {
            $reason_text = sanitize_text_field($_POST['reason_text']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $reason_type = isset($_POST['reason_type']) ? sanitize_key($_POST['reason_type']) : 'report';
            $taxonomy_id = absint($_POST['taxonomy_id']);

            $term_data = [
                'name' => $reason_text,
                'slug' => sanitize_title($reason_text),
                'taxonomy_id' => $taxonomy_id,
            ];

            if ($_POST['action'] === 'update_reason') {
                $term_id = absint($_POST['term_id']);
                $wpdb->update($term_table, $term_data, ['term_id' => $term_id]);
            } else {
                $wpdb->insert($term_table, $term_data);
                $term_id = $wpdb->insert_id;
            }

            if ($term_id) {
                // Use the namespaced Terms_DB class
                \QuestionPress\Database\Terms_DB::update_meta($term_id, 'is_active', $is_active);
                \QuestionPress\Database\Terms_DB::update_meta($term_id, 'type', $reason_type);
            }

            wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=1'));
            exit;
        }

        // Delete Reason
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['reason_id']) && check_admin_referer('qp_delete_reason_' . absint($_GET['reason_id']))) {
            $term_id_to_delete = absint($_GET['reason_id']);
            $reports_table = $wpdb->prefix . 'qp_question_reports';

            // Check if the reason is in use by any reports
            // **FIX**: The original function checked 'reason_term_id'. The new table uses 'reason_term_ids' (plural, comma-separated)
            $usage_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$reports_table} WHERE FIND_IN_SET(%d, reason_term_ids)",
                $term_id_to_delete
            ));

            if ($usage_count > 0) {
                // If it's in use, set an error message and redirect
                $message = sprintf('This reason cannot be deleted because it is currently used in %d report(s).', $usage_count);
                // **FIX**: Use Admin_Utils for message
                \QuestionPress\Admin\Admin_Utils::set_message($message, 'error');
            } else {
                // If not in use, proceed with deletion
                $wpdb->delete($wpdb->prefix . 'qp_term_meta', ['term_id' => $term_id_to_delete]);
                $wpdb->delete($term_table, ['term_id' => $term_id_to_delete]);
                // **FIX**: Use Admin_Utils for message
                \QuestionPress\Admin\Admin_Utils::set_message('Reason deleted successfully.', 'updated');
            }

            wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings'));
            exit;
        }
    }
}