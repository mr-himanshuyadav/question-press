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
    $questions_table = $wpdb->prefix . 'qp_questions';
    $users_table = $wpdb->users;
    $terms_table = $wpdb->prefix . 'qp_terms'; // Use the terms table

    // --- UPDATED: Filtering and Query Logic ---
    $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'open';
    $where_clauses = ["r.status = %s"];
    $params = [$current_status];

    if (!empty($_REQUEST['s'])) {
        $search = '%' . $wpdb->esc_like($_REQUEST['s']) . '%';
        $where_clauses[] = "(q.question_text LIKE %s OR q.question_id LIKE %s)";
        $params[] = $search;
        $params[] = $search;
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

    // This query now aggregates reports by question and fetches all associated data.
    // It groups by the question ID to consolidate all reports for that question into a single row.
    $query = "
        SELECT
            r.question_id,
            q.question_text,
            GROUP_CONCAT(DISTINCT u.display_name SEPARATOR ', ') as reporters,
            MAX(r.report_date) as last_report_date,
            (SELECT g.group_id FROM {$wpdb->prefix}qp_question_groups g WHERE g.group_id = q.group_id) as group_id,
            GROUP_CONCAT(DISTINCT r.reason_term_ids SEPARATOR ',') as all_reason_ids
        FROM {$reports_table} r
        JOIN {$questions_table} q ON r.question_id = q.question_id
        JOIN {$users_table} u ON r.user_id = u.ID
        {$where_sql}
        GROUP BY r.question_id
        ORDER BY last_report_date DESC
    ";

    $items_raw = $wpdb->get_results($wpdb->prepare($query, $params), 'ARRAY_A');
    
    // Post-process to get reason names, as this is difficult to do in a single SQL query efficiently.
    $this->items = [];
    foreach ($items_raw as $item) {
        $all_ids = array_unique(array_filter(explode(',', $item['all_reason_ids'])));
        if (!empty($all_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $all_ids));
            $reason_names = $wpdb->get_col("SELECT name FROM {$terms_table} WHERE term_id IN ($ids_placeholder)");
            $item['reasons'] = implode(', ', $reason_names);
        } else {
            $item['reasons'] = 'N/A';
        }
        $this->items[] = $item;
    }
}

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_question_text($item) {
        return sprintf('<strong>#%s:</strong> %s', esc_html($item['question_id']), esc_html(wp_trim_words($item['question_text'], 40, '...')));
    }

    public function column_report_details($item) {
        return sprintf('<strong>Reported By:</strong> %s<br><strong>Reason(s):</strong> <span style="color: #c00;">%s</span><br><strong>Reported:</strong> %s', esc_html($item['reporters']), esc_html($item['reasons']), esc_html(date('M j, Y, g:i a', strtotime($item['last_report_date']))));
    }

    public function column_actions($item) {
        $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'open';
        $review_url = esc_url(admin_url('admin.php?page=qp-edit-group&group_id=' . $item['group_id']));
        
        $actions['review'] = sprintf('<a href="%s" class="button button-secondary button-small">Review</a>', $review_url);

        if ($current_status === 'open') {
            $resolve_nonce = wp_create_nonce('qp_resolve_report_' . $item['question_id']);
            $resolve_url = esc_url(admin_url('admin.php?page=qp-logs-reports&tab=reports&action=resolve_report&question_id=' . $item['question_id'] . '&_wpnonce=' . $resolve_nonce));
            $actions['resolve'] = sprintf('<a href="%s" class="button button-primary button-small">Mark as Resolved</a>', $resolve_url);
        } else {
            $reopen_nonce = wp_create_nonce('qp_reopen_report_' . $item['question_id']);
            $reopen_url = esc_url(admin_url('admin.php?page=qp-logs-reports&tab=reports&status=resolved&action=reopen_report&question_id=' . $item['question_id'] . '&_wpnonce=' . $reopen_nonce));
            $actions['reopen'] = sprintf('<a href="%s" class="button button-secondary button-small">Open Again</a>', $reopen_url);
        }

        return $this->row_actions($actions, true);
    }

    public function column_default($item, $column_name) {
        return 'N/A';
    }
}