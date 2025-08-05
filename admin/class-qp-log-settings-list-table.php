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
            'type'        => 'Type',
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
        
        // This query now fetches the 'type' meta value along with the 'is_active' status.
        $this->items = $wpdb->get_results($wpdb->prepare("
            SELECT 
                t.term_id as reason_id, 
                t.name as reason_text,
                MAX(CASE WHEN m.meta_key = 'is_active' THEN m.meta_value END) as is_active,
                MAX(CASE WHEN m.meta_key = 'type' THEN m.meta_value END) as type
            FROM {$term_table} t
            LEFT JOIN {$meta_table} m ON t.term_id = m.term_id
            WHERE t.taxonomy_id = %d
            GROUP BY t.term_id
            ORDER BY t.name ASC
        ", $reason_tax_id), 'ARRAY_A');
    }

    public function column_reason_text($item) {
        return esc_html($item['reason_text']);
    }

    public function column_type($item) {
        $type = !empty($item['type']) ? $item['type'] : 'report'; // Default to 'report' if not set
        $style = 'font-weight: 600; padding: 2px 6px; font-size: 10px; border-radius: 3px;';
        
        if ($type === 'report') {
            $style .= 'background-color: #f8d7da; color: #721c24;'; // Light Red
        } else {
            $style .= 'background-color: #d4edda; color: #155724;'; // Light Green
        }
        
        return '<span style="' . $style . '">' . esc_html(ucfirst($type)) . '</span>';
    }

    public function column_is_active($item) {
        // Default to active if the meta value isn't set
        $is_active = !isset($item['is_active']) || $item['is_active'] == '1';
        return $is_active ? '<span style="color:green;">Active</span>' : '<span style="color:red;">Inactive</span>';
    }

    public function column_actions($item) {
        $edit_nonce = wp_create_nonce('qp_edit_reason_' . $item['reason_id']);
        $edit_url = admin_url('admin.php?page=qp-logs-reports&tab=log_settings&action=edit&term_id=' . $item['reason_id'] . '&_wpnonce=' . $edit_nonce);

        $delete_nonce = wp_create_nonce('qp_delete_reason_' . $item['reason_id']);
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