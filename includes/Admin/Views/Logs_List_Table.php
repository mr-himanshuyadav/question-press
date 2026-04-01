<?php

namespace QuestionPress\Admin\Views;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

use \WP_List_Table;

class Logs_List_Table extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct(['singular' => 'Log', 'plural' => 'Logs', 'ajax' => false]);
    }

    public function get_columns()
    {
        // RESTORED: "Status" column is back
        return [
            'log_type'    => 'Log Type',
            'log_message' => 'Message',
            'log_data'    => 'Information',
            'status'      => 'Status',
            'log_date'    => 'Date'
        ];
    }

    // NEW: Display logic for the Log Data column
    public function column_log_data($item)
    {
        $data = json_decode($item['log_data'], true);
        if (empty($data)) return '-';

        // Create a compact preview and a button for the full JSON
        return sprintf(
            '<button class="button button-small qp-view-details" data-full="%s">View Details</button>',
            esc_attr($item['log_data'])
        );
    }

    public function get_sortable_columns()
    {
        return ['log_type' => ['log_type', false], 'log_date' => ['log_date', 'desc']];
    }

    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_logs';
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'log_date'];
        $per_page = 30;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'log_date';
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'desc';

        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        // Important: Direct string concatenation for identifiers (orderby/order) 
        // because $wpdb->prepare wraps %s in quotes which breaks ORDER BY.
        $this->items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY $orderby $order LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
    }

    // UPDATED: This column now only displays the message.
    public function column_log_message($item)
    {
        return esc_html($item['log_message']);
    }

    // NEW: Custom renderer for our new Status column
    public function column_status($item)
    {
        // Decode the log data once for efficiency
        $log_data = json_decode($item['log_data'], true);

        // 1. Handle REST API Errors: Show the status code
        if ($item['log_type'] === 'REST Error' && isset($log_data['status'])) {
            $status_code = absint($log_data['status']);

            // Dynamic coloring: Red for 4xx/5xx errors, Orange for others
            $color = ($status_code >= 400) ? '#d63638' : '#dba617';

            return sprintf(
                '<span style="color: %s; font-weight: bold; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; border: 1px solid %s;">%d</span>',
                $color,
                $color,
                $status_code
            );
        }

        // 2. Handle Question Reports: Show "Resolved" status
        if (!empty($item['resolved'])) {
            return '<span style="color: #00a32a; font-weight: 500;">Resolved</span>';
        }

        // 3. Handle Question Reports: Show "Review" link if a question is attached
        if (isset($log_data['question_id'])) {
            global $wpdb;
            $group_id = $wpdb->get_var($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d",
                $log_data['question_id']
            ));

            if ($group_id) {
                $review_link = admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id);
                return sprintf(
                    '<a href="%s" class="button button-secondary button-small">Review Question</a>',
                    esc_url($review_link)
                );
            }
        }

        // 4. Default Fallback
        return '<span style="color: #646970;">N/A</span>';
    }

    public function column_default($item, $column_name)
    {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}
