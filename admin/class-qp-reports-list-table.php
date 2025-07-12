<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Reports_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(['singular' => 'Report', 'plural' => 'Reports', 'ajax' => false]);
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'question_text' => 'Question',
            'report_details' => 'Report Details',
            'actions'       => 'Actions',
        ];
    }

    // --- NEW: Add Bulk Actions ---
    protected function get_bulk_actions() {
        return [
            'resolve_reports' => 'Mark as Resolved',
            'delete_reports'  => 'Delete Reports'
        ];
    }

    // --- NEW: Process Bulk Actions ---
    public function process_bulk_action() {
        $action = $this->current_action();
        if (!$action) return;

        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'bulk-' . $this->_args['plural'])) {
            wp_die('Security check failed.');
        }

        $question_ids = isset($_REQUEST['question_ids']) ? array_map('absint', $_REQUEST['question_ids']) : [];
        if (empty($question_ids)) return;

        global $wpdb;
        $reports_table = $wpdb->prefix . 'qp_question_reports';
        $ids_placeholder = implode(',', $question_ids);

        if ('resolve_reports' === $action) {
            $wpdb->query("UPDATE {$reports_table} SET status = 'resolved' WHERE question_id IN ({$ids_placeholder})");
        }

        if ('delete_reports' === $action) {
            $wpdb->query("DELETE FROM {$reports_table} WHERE question_id IN ({$ids_placeholder})");
        }
    }

    public function prepare_items() {
        global $wpdb;

        // First, process any bulk actions
        $this->process_bulk_action();

        $this->_column_headers = [$this->get_columns(), [], []];

        $reports_table = $wpdb->prefix . 'qp_question_reports';
        $reasons_table = $wpdb->prefix . 'qp_report_reasons';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $users_table = $wpdb->users;

        // --- NEW: Add Filtering Logic ---
        $where_clauses = ["r.status = 'open'"]; // Default to show only open reports
        $params = [];

        if (!empty($_REQUEST['s'])) {
            $search = '%' . $wpdb->esc_like($_REQUEST['s']) . '%';
            $where_clauses[] = "(q.question_text LIKE %s OR q.custom_question_id LIKE %s)";
            $params[] = $search;
            $params[] = $search;
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

        $query = "
            SELECT
                r.question_id,
                q.question_text,
                q.custom_question_id,
                GROUP_CONCAT(DISTINCT rr.reason_text SEPARATOR ', ') as reasons,
                GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as reporters,
                MAX(r.report_date) as last_report_date,
                (SELECT g.group_id FROM {$wpdb->prefix}qp_question_groups g WHERE g.group_id = q.group_id) as group_id
            FROM {$reports_table} r
            JOIN {$questions_table} q ON r.question_id = q.question_id
            JOIN {$reasons_table} rr ON r.reason_id = rr.reason_id
            JOIN {$users_table} u ON r.user_id = u.ID
            {$where_sql}
            GROUP BY r.question_id
            ORDER BY last_report_date DESC
        ";

        $this->items = $wpdb->get_results($wpdb->prepare($query, $params), 'ARRAY_A');
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_question_text($item) {
        return sprintf('<strong>#%s:</strong> %s', esc_html($item['custom_question_id']), esc_html(wp_trim_words($item['question_text'], 40, '...')));
    }

    public function column_report_details($item) {
        return sprintf('<strong>Reported By:</strong> %s<br><strong>Reason(s):</strong> <span style="color: #c00;">%s</span><br><strong>Last Reported:</strong> %s', esc_html($item['reporters']), esc_html($item['reasons']), esc_html(date('M j, Y, g:i a', strtotime($item['last_report_date']))));
    }

    public function column_actions($item) {
        $review_url = esc_url(admin_url('admin.php?page=qp-edit-group&group_id=' . $item['group_id']));

        // --- NEW: Add Resolve Link ---
        $resolve_nonce = wp_create_nonce('qp_resolve_report_' . $item['question_id']);
        $resolve_url = esc_url(admin_url('admin.php?page=qp-logs-reports&tab=reports&action=resolve_report&question_id=' . $item['question_id'] . '&_wpnonce=' . $resolve_nonce));

        $actions = [
            'review' => sprintf('<a href="%s" class="button button-secondary button-small">Review</a>', $review_url),
            'resolve' => sprintf('<a href="%s" class="button button-primary button-small">Mark as Resolved</a>', $resolve_url)
        ];
        return $this->row_actions($actions, true);
    }

    public function column_default($item, $column_name) {
        return 'N/A';
    }
}