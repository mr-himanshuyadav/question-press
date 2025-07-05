<?php
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Logs_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(['singular' => 'Log', 'plural' => 'Logs', 'ajax' => false]);
    }

    public function get_columns() {
        // REMOVED: Status column
        return [
            'log_type'    => 'Log Type',
            'log_message' => 'Message / Action',
            'log_date'    => 'Date'
        ];
    }

    public function get_sortable_columns() {
        return ['log_type' => ['log_type', false], 'log_date' => ['log_date', true]];
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_logs';
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'log_date'];
        $per_page = 30;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'log_date';
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'desc';

        $total_items = $wpdb->get_var("SELECT COUNT(log_id) FROM $table_name");
        $this->items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY %s %s LIMIT %d OFFSET %d",
            $orderby, $order, $per_page, $offset
        ), ARRAY_A);
        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
    }

    // UPDATED: Custom renderer for the message column
    public function column_log_message($item) {
        $message = esc_html($item['log_message']);
        $log_data = json_decode($item['log_data'], true);

        // If the log is unresolved and has a question ID, show a "Review" button
        if ($item['resolved'] == 0 && isset($log_data['question_id'])) {
            global $wpdb;
            $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $log_data['question_id']));
            if ($group_id) {
                $review_link = admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id);
                // Styled like a button for emphasis
                $message .= sprintf(' <a href="%s" class="button button-secondary button-small">Review</a>', esc_url($review_link));
            }
        }
        return $message;
    }

    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}