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
        $this->items = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_report_reasons ORDER BY reason_text ASC", 'ARRAY_A');
    }

    public function column_reason_text($item) {
        return esc_html($item['reason_text']);
    }

    public function column_is_active($item) {
        return $item['is_active'] ? '<span style="color:green;">Active</span>' : '<span style="color:red;">Inactive</span>';
    }

    public function column_actions($item) {
        $edit_url = admin_url('admin.php?page=qp-logs-reports&tab=log_settings&action=edit&reason_id=' . $item['reason_id']);
        $delete_url = admin_url('admin.php?page=qp-logs-reports&tab=log_settings&action=delete&reason_id=' . $item['reason_id']);
        $nonce = wp_create_nonce('qp_delete_reason_' . $item['reason_id']);

        $actions = [
            'edit' => sprintf('<a href="%s">Edit</a>', esc_url($edit_url)),
            'delete' => sprintf('<a href="%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this reason?\');">Delete</a>', esc_url($delete_url), $nonce)
        ];
        return $this->row_actions($actions);
    }

    public function column_default($item, $column_name) {
        return 'N/A';
    }
}