<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Log_Settings_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(['singular' => 'Reason', 'plural' => 'Reasons', 'ajax' => false]);
    }

    public function get_columns() {
        return [
            'reason_text' => 'Reason Text',
            'is_active'   => 'Status',
            'actions'     => 'Actions'
        ];
    }

    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = [$this->get_columns(), [], []];

        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';
        $meta_table = $wpdb->prefix . 'qp_term_meta';

        $reason_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'report_reason'");
        
        $this->items = $wpdb->get_results($wpdb->prepare("
            SELECT t.term_id as reason_id, t.name as reason_text, m.meta_value as is_active
            FROM {$term_table} t
            LEFT JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'is_active'
            WHERE t.taxonomy_id = %d
            ORDER BY t.name ASC
        ", $reason_tax_id), 'ARRAY_A');
    }

    public function column_reason_text($item) {
        return esc_html($item['reason_text']);
    }

    public function column_is_active($item) {
        return $item['is_active'] ? '<span style="color:green;">Active</span>' : '<span style="color:red;">Inactive</span>';
    }

    public function column_actions($item) {
        // Create a unique nonce for the edit action
        $edit_nonce = wp_create_nonce('qp_edit_reason_' . $item['reason_id']);
        // Use 'term_id' as the parameter name in the URL to match the form handler
        $edit_url = admin_url('admin.php?page=qp-logs-reports&tab=log_settings&action=edit&term_id=' . $item['reason_id'] . '&_wpnonce=' . $edit_nonce);

        $delete_nonce = wp_create_nonce('qp_delete_reason_' . $item['reason_id']);
        // Use 'reason_id' here as the delete handler expects it that way
        $delete_url = admin_url('admin.php?page=qp-logs-reports&tab=log_settings&action=delete&reason_id=' . $item['reason_id']);


        $actions = [
            'edit' => sprintf('<a href="%s">Edit</a>', esc_url($edit_url)),
            'delete' => sprintf('<a href="%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this reason?\');">Delete</a>', esc_url($delete_url), $delete_nonce)
        ];
        return $this->row_actions($actions);
    }

    public function column_default($item, $column_name) {
        return 'N/A';
    }
}