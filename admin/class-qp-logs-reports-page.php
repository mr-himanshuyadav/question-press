<?php
if (!defined('ABSPATH')) exit;

// We will create these files in the next steps
require_once QP_PLUGIN_DIR . 'admin/class-qp-reports-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-log-settings-list-table.php';

class QP_Logs_Reports_Page {

    public static function render() {
        $tabs = [
            'reports' => ['label' => 'Reports', 'callback' => [self::class, 'render_reports_tab']],
            'log_settings' => ['label' => 'Log Settings', 'callback' => [self::class, 'render_log_settings_tab']],
        ];
        $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'reports';
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Reports</h1>
            <p>Review questions reported by users and manage the reasons available for reporting.</p>
            <?php
            // Display settings errors and messages from the session
            if (isset($_SESSION['qp_admin_message'])) {
                $message = html_entity_decode($_SESSION['qp_admin_message']);
                echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
                unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
            }
            ?>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
                <?php
                foreach ($tabs as $tab_id => $tab_data) {
                    $class = ($tab_id === $active_tab) ? ' nav-tab-active' : '';
                    echo '<a href="?page=qp-logs-reports&tab=' . esc_attr($tab_id) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($tab_data['label']) . '</a>';
                }
                ?>
            </nav>

            <div class="tab-content" style="margin-top: 1.5rem;">
                <?php
                call_user_func($tabs[$active_tab]['callback']);
                ?>
            </div>
        </div>
<?php
    }

    public static function render_reports_tab() {
    global $wpdb;
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // Get counts for the view links
    $open_count = $wpdb->get_var("SELECT COUNT(DISTINCT question_id) FROM {$reports_table} WHERE status = 'open'");
    $resolved_count = $wpdb->get_var("SELECT COUNT(DISTINCT question_id) FROM {$reports_table} WHERE status = 'resolved'");
    
    $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'open';
    ?>
    <style>
        /* Target the specific columns in the reports table */
        .wp-list-table .column-question_text { width: 45%; }
        .wp-list-table .column-report_details { width: 35%; }
        .wp-list-table .column-actions { width: 20%; }
    </style>
    <ul class="subsubsub">
        <li><a href="?page=qp-logs-reports&tab=reports&status=open" class="<?php if ($current_status === 'open') echo 'current'; ?>">Open <span class="count">(<?php echo esc_html($open_count); ?>)</span></a> |</li>
        <li><a href="?page=qp-logs-reports&tab=reports&status=resolved" class="<?php if ($current_status === 'resolved') echo 'current'; ?>">Resolved <span class="count">(<?php echo esc_html($resolved_count); ?>)</span></a></li>
    </ul>

    <?php if ($current_status === 'resolved' && $resolved_count > 0) : 
        $clear_url = wp_nonce_url(admin_url('admin.php?page=qp-logs-reports&tab=reports&action=clear_resolved_reports'), 'qp_clear_all_reports_nonce');
    ?>
        <a href="<?php echo esc_url($clear_url); ?>" class="button button-danger" style="float: right; margin-top: -30px;" onclick="return confirm('Are you sure you want to permanently delete all resolved reports? This action cannot be undone.');">Clear All Resolved Reports</a>
    <?php endif; ?>

    <?php
    $list_table = new QP_Reports_List_Table();
    $list_table->prepare_items();
    ?>
    <form method="post">
        <input type="hidden" name="page" value="qp-logs-reports">
        <input type="hidden" name="tab" value="reports">
        <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
        <?php $list_table->search_box('Search Reports', 'report'); ?>
        <?php $list_table->display(); ?>
    </form>
    <?php
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
                $is_active_for_edit = qp_get_term_meta($term_id, 'is_active', true);
                $type_for_edit = qp_get_term_meta($term_id, 'type', true) ?: 'report'; // Fetch the type, default to 'report' if not set
            }
        }
    }

    $list_table = new QP_Log_Settings_List_Table();
    $list_table->prepare_items();
?>
    <div id="col-container" class="wp-clearfix">
        <div id="col-left">
            <div class="col-wrap">
                <div class="form-wrap">
                    <h2><?php echo $term_to_edit ? 'Edit Reason' : 'Add New Reason'; ?></h2>
                    <form method="post" action="admin.php?page=qp-logs-reports&tab=log_settings">
                        <?php wp_nonce_field('qp_add_edit_reason_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo $term_to_edit ? 'update_reason' : 'add_reason'; ?>">
                        <input type="hidden" name="taxonomy_id" value="<?php echo esc_attr($reason_tax_id); ?>">
                        <?php if ($term_to_edit): ?><input type="hidden" name="term_id" value="<?php echo esc_attr($term_to_edit->term_id); ?>"><?php endif; ?>

                        <div class="form-field form-required">
                            <label for="reason_text">Reason Text</label>
                            <input name="reason_text" id="reason_text" type="text" value="<?php echo $term_to_edit ? esc_attr($term_to_edit->name) : ''; ?>" size="40" required>
                        </div>

                        <div class="form-field">
                            <label><strong>Type</strong></label>
                            <label style="display: inline-block; margin-right: 15px;">
                                <input name="reason_type" type="radio" value="report" <?php checked($type_for_edit, 'report'); ?>>
                                Report (for errors)
                            </label>
                            <label style="display: inline-block;">
                                <input name="reason_type" type="radio" value="suggestion" <?php checked($type_for_edit, 'suggestion'); ?>>
                                Suggestion (for improvements)
                            </label>
                        </div>

                        <div class="form-field">
                            <label>
                                <input name="is_active" type="checkbox" value="1" <?php checked($is_active_for_edit, 1); ?>>
                                Active (Users can select this reason)
                            </label>
                        </div>

                        <p class="submit">
                            <input type="submit" class="button button-primary" value="<?php echo $term_to_edit ? 'Update Reason' : 'Add New Reason'; ?>">
                            <?php if ($term_to_edit): ?><a href="admin.php?page=qp-logs-reports&tab=log_settings" class="button button-secondary">Cancel</a><?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <div id="col-right">
            <div class="col-wrap">
                <?php $list_table->display(); ?>
            </div>
        </div>
    </div>
<?php
}
}