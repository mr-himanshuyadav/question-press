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

    public function get_columns() {
        return [
            'cb'                 => '<input type="checkbox" />',
            'custom_question_id' => 'Question ID', // New Column
            'question_text'      => 'Question',
            'subject_name'       => 'Subject',
            'is_pyq'             => 'PYQ',
            'import_date'        => 'Date'
        ];
    }

    public function get_sortable_columns() {
        return [
            'custom_question_id' => ['custom_question_id', false], // New Sortable Column
            'question_text'      => ['question_text', false],
            'subject_name'       => ['subject_name', false],
            'import_date'        => ['import_date', true]
        ];
    }
    
    protected function get_bulk_actions() {
        return ['trash' => 'Move to Trash'];
    }

    protected function extra_tablenav($which) {
        if ($which == "top") {
            global $wpdb;
            $subjects_table = $wpdb->prefix . 'qp_subjects';
            $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
            $current_subject = isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : '';
            ?>
            <div class="alignleft actions">
                <select name="filter_by_subject" id="filter_by_subject">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $subject) : ?>
                        <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($current_subject, $subject->subject_id); ?>>
                            <?php echo esc_html($subject->subject_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('Filter', 'button', 'filter_action', false); ?>
            </div>
            <?php
        }
    }

    public function prepare_items() {
        global $wpdb;
        $this->process_bulk_action();

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], array_keys($this->get_sortable_columns())) ? sanitize_key($_GET['orderby']) : 'import_date';
        $order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? sanitize_key($_GET['order']) : 'desc';

        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $s_table = $wpdb->prefix . 'qp_subjects';
        
        $sql_query_from = " FROM {$q_table} q
                            LEFT JOIN {$g_table} g ON q.group_id = g.group_id
                            LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id";
        
        $where = ["q.status = 'publish'"];
        if (!empty($_REQUEST['filter_by_subject'])) {
            $where[] = $wpdb->prepare("g.subject_id = %d", absint($_REQUEST['filter_by_subject']));
        }
        $sql_query_where = " WHERE " . implode(' AND ', $where);

        $total_items = $wpdb->get_var("SELECT COUNT(q.question_id)" . $sql_query_from . $sql_query_where);

        $data_query = "SELECT q.question_id, q.custom_question_id, q.question_text, q.is_pyq, q.import_date, s.subject_name" . $sql_query_from . $sql_query_where;
        $data_query .= $wpdb->prepare(" ORDER BY %s %s LIMIT %d OFFSET %d", $orderby, $order, $per_page, $offset);
        
        $this->items = $wpdb->get_results($data_query, ARRAY_A);

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
    }

    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        $action = $this->current_action();

        if ('trash' === $action) {
            $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_key($_REQUEST['_wpnonce']) : '';
            $is_bulk = isset($_POST['question_ids']);
            $is_single = isset($_GET['question_id']);

            $verified = false;
            if ($is_bulk && wp_verify_nonce($nonce, 'bulk-' . $this->_args['plural'])) {
                $verified = true;
            } elseif ($is_single && wp_verify_nonce($nonce, 'qp_trash_question_' . absint($_GET['question_id']))) {
                $verified = true;
            }

            if (!$verified) {
                wp_die('Security check failed.');
            }
            
            $question_ids = $is_bulk ? array_map('absint', $_POST['question_ids']) : [absint($_GET['question_id'])];

            if (empty($question_ids)) return;

            global $wpdb;
            $questions_table = $wpdb->prefix . 'qp_questions';
            $ids_placeholder = implode(',', array_fill(0, count($question_ids), '%d'));

            $wpdb->query($wpdb->prepare(
                "UPDATE {$questions_table} SET status = 'trash' WHERE question_id IN ($ids_placeholder)",
                $question_ids
            ));
        }
    }

    /**
     * Render the checkbox column
     */
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_question_text($item) {
        $group_id = isset($item['group_id']) ? $item['group_id'] : 0;
        $trash_nonce = wp_create_nonce('qp_trash_question_' . $item['question_id']);
        $page = esc_attr($_REQUEST['page']);
        $actions = ['edit' => sprintf('<a href="admin.php?page=qp-question-editor&action=edit&group_id=%s">Edit</a>', $group_id), 'trash' => sprintf('<a href="?page=%s&action=trash&question_id=%s&_wpnonce=%s" style="color:#a00;">Trash</a>', $page, $item['question_id'], $trash_nonce)];
        return sprintf('<strong>%s</strong>%s', wp_trim_words(esc_html($item['question_text']), 20, '...'), $this->row_actions($actions));
    }

    public function column_is_pyq($item) { return $item['is_pyq'] ? 'Yes' : 'No'; }
    public function column_default($item, $column_name) { return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A'; }
}