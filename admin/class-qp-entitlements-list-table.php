<?php
// question-press/admin/class-qp-entitlements-list-table.php

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Entitlements_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Entitlement', 'question-press'), // singular name of the listed records
            'plural'   => __('Entitlements', 'question-press'), // plural name of the listed records
            'ajax'     => false // We won't support AJAX for this table initially
        ]);
    }

    /**
     * Get columns to show in the list table.
     */
    public function get_columns() {
        return [
            // 'cb'            => '<input type="checkbox" />', // Optional: Add checkboxes later for bulk actions
            'entitlement_id'  => __('ID', 'question-press'),
            'user_id'         => __('User', 'question-press'),
            'plan_id'         => __('Plan', 'question-press'),
            'order_id'        => __('Order ID', 'question-press'),
            'start_date'      => __('Start Date', 'question-press'),
            'expiry_date'     => __('Expiry Date', 'question-press'),
            'remaining_attempts' => __('Attempts Left', 'question-press'),
            'status'          => __('Status', 'question-press'),
        ];
    }

    /**
     * Get sortable columns.
     */
    public function get_sortable_columns() {
        return [
            'entitlement_id' => ['entitlement_id', false],
            'user_id'        => ['user_id', false],
            'plan_id'        => ['plan_id', false],
            'order_id'       => ['order_id', false],
            'start_date'     => ['start_date', false],
            'expiry_date'    => ['expiry_date', false],
            'status'         => ['status', false],
        ];
    }

    /**
     * Prepare the items for the table to process.
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_user_entitlements';
        $per_page = $this->get_items_per_page('entitlements_per_page', 20); // Allow screen options
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Define columns
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        // Ordering parameters
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'entitlement_id';
        $order = isset($_REQUEST['order']) ? sanitize_key($_REQUEST['order']) : 'DESC';

        // Base query
        $query = "SELECT e.*, u.display_name as user_name, p.post_title as plan_title
                  FROM {$table_name} e
                  LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                  LEFT JOIN {$wpdb->posts} p ON e.plan_id = p.ID";

        $where_clauses = [];
        $params = [];

        // Search functionality (by User ID, User Email/Name, or Order ID)
        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(trim($_REQUEST['s'])) . '%';
            if (is_numeric(trim($_REQUEST['s']))) {
                 $where_clauses[] = "(e.user_id = %d OR e.order_id = %d)";
                 $params[] = absint(trim($_REQUEST['s']));
                 $params[] = absint(trim($_REQUEST['s']));
            } else {
                 $where_clauses[] = "(u.display_name LIKE %s OR u.user_email LIKE %s)";
                 $params[] = $search_term;
                 $params[] = $search_term;
            }
        }

        if (!empty($where_clauses)) {
            $query .= " WHERE " . implode(' AND ', $where_clauses);
        }

        // Get total items count for pagination (needs the WHERE clause)
        $total_items_query = "SELECT COUNT(e.entitlement_id) FROM {$table_name} e LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID LEFT JOIN {$wpdb->posts} p ON e.plan_id = p.ID";
        if (!empty($where_clauses)) {
            $total_items_query .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $total_items = $wpdb->get_var($wpdb->prepare($total_items_query, $params));

        // Add ordering and pagination to the main query
        $query .= $wpdb->prepare(" ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $per_page, $offset);

        // Fetch the items
        $this->items = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

        // Set pagination arguments
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /**
     * Default column rendering.
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'entitlement_id':
            case 'order_id':
                return $item[$column_name];
            case 'start_date':
                return $item[$column_name] ? date_i18n(get_option('date_format') . ' H:i', strtotime($item[$column_name])) : '—';
            case 'expiry_date':
                return $item[$column_name] ? date_i18n(get_option('date_format') . ' H:i', strtotime($item[$column_name])) : '<em>Never</em>';
            case 'remaining_attempts':
                return is_null($item[$column_name]) ? '<em>Unlimited</em>' : number_format_i18n($item[$column_name]);
            case 'status':
                $status = $item[$column_name];
                $color = 'grey';
                if ($status === 'active') $color = 'green';
                if ($status === 'expired') $color = 'orange';
                if ($status === 'cancelled') $color = 'red';
                return '<span style="color:' . $color . '; font-weight:bold;">' . esc_html(ucfirst($status)) . '</span>';
            default:
                return print_r($item, true); //Show the whole array for troubleshooting
        }
    }

    /**
     * Render the User column.
     */
    public function column_user_id($item) {
        if (!empty($item['user_name'])) {
            // Link to user edit screen
            $edit_link = get_edit_user_link($item['user_id']);
            return sprintf('<a href="%s">%s (#%d)</a>', esc_url($edit_link), esc_html($item['user_name']), $item['user_id']);
        } elseif ($item['user_id']) {
            return sprintf('User #%d (Not Found)', $item['user_id']);
        }
        return 'N/A';
    }

    /**
     * Render the Plan column.
     */
    public function column_plan_id($item) {
        if (!empty($item['plan_title'])) {
            // Link to plan edit screen
            $edit_link = get_edit_post_link($item['plan_id']);
            return sprintf('<a href="%s">%s (#%d)</a>', esc_url($edit_link), esc_html($item['plan_title']), $item['plan_id']);
        } elseif ($item['plan_id']) {
            return sprintf('Plan #%d (Not Found)', $item['plan_id']);
        }
        return 'N/A';
    }

    /**
     * Add screen options (e.g., items per page).
     */
    public static function add_screen_options() {
        $option = 'per_page';
        $args = [
            'label'   => 'Entitlements per page',
            'default' => 20,
            'option'  => 'entitlements_per_page'
        ];
        add_screen_option($option, $args);
    }
}