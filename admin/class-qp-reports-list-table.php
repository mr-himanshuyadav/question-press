<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Reports_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'Report',
            'plural'   => 'Reports',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'question_text' => 'Question',
            'report_details' => 'Report Details',
            'actions'       => 'Actions',
        ];
    }

    public function prepare_items() {
        global $wpdb;

        $this->_column_headers = [$this->get_columns(), [], []];

        $reports_table = $wpdb->prefix . 'qp_question_reports';
        $reasons_table = $wpdb->prefix . 'qp_report_reasons';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $users_table = $wpdb->users;

        // This query groups all reasons for the same question report into a single row
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
            GROUP BY r.question_id
            ORDER BY last_report_date DESC
        ";

        $this->items = $wpdb->get_results($query, 'ARRAY_A');
    }
    
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }
    
    public function column_question_text($item) {
        return sprintf(
            '<strong>#%s:</strong> %s',
            esc_html($item['custom_question_id']),
            esc_html(wp_trim_words($item['question_text'], 40, '...'))
        );
    }

    public function column_report_details($item) {
        return sprintf(
            '<strong>Reported By:</strong> %s<br><strong>Reason(s):</strong> <span style="color: #c00;">%s</span><br><strong>Last Reported:</strong> %s',
            esc_html($item['reporters']),
            esc_html($item['reasons']),
            esc_html(date('M j, Y, g:i a', strtotime($item['last_report_date'])))
        );
    }
    
    public function column_actions($item) {
        $review_link = sprintf(
            '<a href="%s" class="button button-secondary button-small">Review</a>',
            esc_url(admin_url('admin.php?page=qp-edit-group&group_id=' . $item['group_id']))
        );
        return $review_link;
    }

    public function column_default($item, $column_name) {
        return 'N/A';
    }
}