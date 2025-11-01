<?php
namespace QuestionPress\Admin\Views;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

use \WP_List_Table;

class Reports_List_Table extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct(['singular' => 'Report', 'plural' => 'Reports', 'ajax' => false]);
    }

    // Attention! Change the funciton name based on the context of the class.
    public function get_columns()
    {
        return [
            'cb'             => '<input type="checkbox" />',
            'report_id'      => 'Report ID',
            'question_text'  => 'Report Details',
            'report_details' => 'Report Meta',
            'actions'        => 'Actions',
        ];
    }

    // --- NEW: Add Bulk Actions ---
    protected function get_bulk_actions()
    {
        return [
            'resolve_reports' => 'Mark as Resolved',
            'delete_reports'  => 'Delete Reports'
        ];
    }

    // --- NEW: Process Bulk Actions ---
    public function process_bulk_action()
    {
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

    /**
     * Overrides the parent display_rows method to add a custom class to the <tr>.
     */
    public function display_rows()
    {
        foreach ($this->items as $item) {
            // Add a class to the row based on the report severity
            $row_class = 'report-type-' . (isset($item['report_severity']) ? esc_attr($item['report_severity']) : 'suggestion');
            echo '<tr class="' . $row_class . '">';
            $this->single_row_columns($item);
            echo '</tr>';
        }
    }

    /**
     * NEW: Add this empty method to prevent a PHP notice.
     * The parent class expects this method to exist, even if we don't use it in our custom display_rows.
     */
    public function single_row_columns($item)
    {
        parent::single_row_columns($item);
    }

    public function prepare_items() {
    global $wpdb;

    $this->process_bulk_action();
    $this->_column_headers = [$this->get_columns(), [], []];

    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $users_table = $wpdb->users;
    $terms_table = $wpdb->prefix . 'qp_terms';
    $meta_table = $wpdb->prefix . 'qp_term_meta';

    $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'open';
    
    // Base query parts
    $select_sql = "SELECT r.report_id, r.question_id, r.user_id, r.comment, r.reason_term_ids, r.report_date, q.question_text, u.display_name as reporter_name, q.group_id";
    $from_sql = " FROM {$reports_table} r JOIN {$questions_table} q ON r.question_id = q.question_id JOIN {$users_table} u ON r.user_id = u.ID";
    $where_sql = $wpdb->prepare(" WHERE r.status = %s", $current_status);
    $params = [];

    if (!empty($_REQUEST['s'])) {
        $search_term = '%' . $wpdb->esc_like($_REQUEST['s']) . '%';
        
        // Find term IDs that match the search term for reasons and types
        $matching_term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.term_id 
             FROM {$terms_table} t 
             LEFT JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'type'
             WHERE t.name LIKE %s OR m.meta_value LIKE %s",
            $search_term, $search_term
        ));

        $search_where_parts = [
            $wpdb->prepare("r.report_id LIKE %s", $search_term),
            $wpdb->prepare("r.question_id LIKE %s", $search_term),
            $wpdb->prepare("u.display_name LIKE %s", $search_term),
            $wpdb->prepare("r.user_id LIKE %s", $search_term),
            $wpdb->prepare("r.comment LIKE %s", $search_term),
        ];

        if (!empty($matching_term_ids)) {
            foreach ($matching_term_ids as $term_id) {
                // Use FIND_IN_SET for searching in the comma-separated string
                $search_where_parts[] = $wpdb->prepare("FIND_IN_SET(%d, r.reason_term_ids)", $term_id);
            }
        }
        
        $where_sql .= " AND (" . implode(' OR ', $search_where_parts) . ")";
    }

    $query = $select_sql . $from_sql . $where_sql . " ORDER BY r.report_date DESC";
    
    $items_raw = $wpdb->get_results($query, ARRAY_A);

    $this->items = [];
foreach ($items_raw as $item) {
    $reason_ids = array_filter(explode(',', $item['reason_term_ids']));
    
    // Set defaults
    $item['report_severity'] = 'suggestion';
    $item['report_types'] = ['Suggestion'];
    $item['reasons'] = 'N/A';

    if (!empty($reason_ids)) {
        $ids_placeholder = implode(',', array_map('absint', $reason_ids));
        $reasons_data = $wpdb->get_results("
            SELECT t.name, m.meta_value as type
            FROM {$terms_table} t
            LEFT JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'type'
            WHERE t.term_id IN ($ids_placeholder)
        ");
        
        $reason_html_parts = [];
        $reason_types = [];
        foreach ($reasons_data as $reason) {
            $type = !empty($reason->type) ? $reason->type : 'report';
            $reason_types[] = ucfirst($type);
            
            // Determine color for each individual reason
            $reason_color = ($type === 'report') ? '#c00' : '#f57f17';
            $reason_html_parts[] = '<span style="color: ' . esc_attr($reason_color) . ';">' . esc_html($reason->name) . '</span>';
        }

        // The 'reasons' key now contains a pre-built HTML string
        $item['reasons'] = implode(', ', $reason_html_parts);
        
        $unique_types = array_unique($reason_types);
        sort($unique_types);
        $item['report_types'] = $unique_types;

        // Prioritize 'report' for the row's severity class (for the border)
        if (in_array('Report', $unique_types)) {
            $item['report_severity'] = 'report';
        }
    }
    
    $this->items[] = $item;
}
}

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_report_id($item)
    {
        return '<strong>' . esc_html($item['report_id']) . '</strong>';
    }
    public function column_question_text($item) {
    // Use the new report_types array to build the display string
    $type_text = isset($item['report_types']) ? implode(', ', $item['report_types']) : 'Suggestion';

    $output = '<strong>ID:</strong> ' . esc_html($item['question_id']) . ' | <strong>Type:</strong> ' . esc_html($type_text);
    
    if (!empty($item['reasons']) && $item['reasons'] !== 'N/A') {
        // We no longer need to add a color here, just output the HTML string
        // Using wp_kses_post to allow our safe <span> tags with style attributes
        $output .= '<br><strong>Reasons:</strong> ' . wp_kses_post($item['reasons']);
    }

    if (!empty(trim($item['comment']))) {
        $output .= '<br><strong>Comment:</strong> <em>' . esc_html(trim($item['comment'])) . '</em>';
    }
    
    return $output;
}

    public function column_report_details($item)
    {
        $reporter_info = esc_html($item['reporter_name']) . ' (ID: ' . esc_html($item['user_id']) . ')';
        return sprintf('<strong>Reported By:</strong> %s<br><strong>Reported On:</strong> %s', $reporter_info, esc_html(date('M j, Y, g:i a', strtotime($item['report_date']))));
    }

    public function column_actions($item)
    {
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

    public function column_default($item, $column_name)
    {
        return 'N/A';
    }
}