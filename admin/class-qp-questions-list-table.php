<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Questions_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'Question',
            'plural'   => 'Questions',
            'ajax'     => false
        ]);
    }

    /**
     * Define the columns that are going to be used in the table
     * @return array
     */
    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'question_text' => 'Question',
            'subject_name'  => 'Subject',
            'is_pyq'        => 'PYQ',
            'import_date'   => 'Date'
        ];
    }

    /**
     * Decide which columns to activate the sorting functionality on
     * @return array
     */
    public function get_sortable_columns() {
        return [
            'question_text' => ['question_text', false],
            'subject_name'  => ['subject_name', false],
            'import_date'   => ['import_date', true]
        ];
    }

    /**
     * Prepare the items for the table to process
     */
    public function prepare_items() {
        global $wpdb;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Ordering logic
        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], array_keys($this->get_sortable_columns())) ? sanitize_key($_GET['orderby']) : 'import_date';
        $order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? sanitize_key($_GET['order']) : 'desc';

        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $s_table = $wpdb->prefix . 'qp_subjects';

        $sql_query = "SELECT q.question_id, q.question_text, q.is_pyq, q.import_date, s.subject_name
                      FROM {$q_table} q
                      LEFT JOIN {$g_table} g ON q.group_id = g.group_id
                      LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id";
        
        // Count total items
        $total_items = $wpdb->get_var(str_replace("SELECT q.question_id, q.question_text, q.is_pyq, q.import_date, s.subject_name", "SELECT COUNT(q.question_id)", $sql_query));

        // Add ordering and pagination to the query
        $sql_query .= $wpdb->prepare(" ORDER BY %s %s LIMIT %d OFFSET %d", $orderby, $order, $per_page, $offset);
        
        $this->items = $wpdb->get_results($sql_query, ARRAY_A);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);
    }

    /**
     * Render the checkbox column
     */
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    /**
     * Render the Question column with actions
     */
    public function column_question_text($item) {
        $actions = [
            'edit'   => sprintf('<a href="#">Edit</a>'),
            'trash'  => sprintf('<a href="#" style="color:#a00;">Trash</a>'),
        ];
        return sprintf('<strong>%s</strong>%s', wp_trim_words(esc_html($item['question_text']), 20, '...'), $this->row_actions($actions));
    }

    /**
     * Render the PYQ column
     */
    public function column_is_pyq($item) {
        return $item['is_pyq'] ? 'Yes' : 'No';
    }

    /**
     * Default column rendering
     */
    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}