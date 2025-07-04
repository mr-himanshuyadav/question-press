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
            'cb'                 => '<input type="checkbox" />',
            'custom_question_id' => 'Question ID',
            'question_text'      => 'Question',
            'subject_name'       => 'Subject',
            'is_pyq'             => 'PYQ',
            'import_date'        => 'Date'
        ];
    }

    /**
     * Decide which columns to activate the sorting functionality on
     * @return array
     */
    public function get_sortable_columns() {
        return [
            'custom_question_id' => ['custom_question_id', false],
            'question_text'      => ['question_text', false],
            'subject_name'       => ['subject_name', false],
            'import_date'        => ['import_date', true]
        ];
    }
    
    /**
     * Define the bulk actions
     */
    protected function get_bulk_actions() {
        return ['trash' => 'Move to Trash'];
    }

    /**
     * Add filter controls to the table nav
     */
    protected function extra_tablenav($which) {
        if ($which == "top") {
            global $wpdb;
            $subjects_table = $wpdb->prefix . 'qp_subjects';
            $labels_table = $wpdb->prefix . 'qp_labels';

            $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
            $labels = $wpdb->get_results("SELECT * FROM $labels_table ORDER BY label_name ASC");
            
            $current_subject = isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : '';
            $current_label = isset($_REQUEST['filter_by_label']) ? absint($_REQUEST['filter_by_label']) : '';
            ?>
            <div class="alignleft actions">
                <select name="filter_by_subject">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $subject) : ?>
                        <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($current_subject, $subject->subject_id); ?>>
                            <?php echo esc_html($subject->subject_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="filter_by_label">
                    <option value="">All Labels</option>
                    <?php foreach ($labels as $label) : ?>
                        <option value="<?php echo esc_attr($label->label_id); ?>" <?php selected($current_label, $label->label_id); ?>>
                            <?php echo esc_html($label->label_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php submit_button('Filter', 'button', 'filter_action', false, ['id' => 'post-query-submit']); ?>
            </div>
            <?php
        }
    }
    
    /**
     * Display the search box
     */
    // In admin/class-qp-questions-list-table.php

public function search_box($text, $input_id) {
    // The main $text parameter is 'Search Questions'
    // We will use it for the button and ignore the query for the button text
    $search_button_text = $text;
    
    $input_id = $input_id . '-search-input';
    
    if (!empty($_REQUEST['s'])) {
        $text = esc_attr($_REQUEST['s']);
    } else {
        $text = ''; // Clear text if no search
    }
    ?>
    <p class="search-box">
        <label class="screen-reader-text" for="<?php echo $input_id ?>"><?php echo $search_button_text; ?>:</label>
        <input type="search" id="<?php echo $input_id ?>" name="s" value="<?php echo $text; ?>" placeholder="By ID or text" />
        <?php submit_button($search_button_text, 'button', 'search_submit', false, array('id' => 'search-submit')); ?>
    </p>
    <?php
}

    /**
     * Prepare the items for the table to process
     */
    public function prepare_items() {
        global $wpdb;
        $this->process_bulk_action();

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable, 'custom_question_id'];

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], array_keys($this->get_sortable_columns())) ? sanitize_key($_GET['orderby']) : 'import_date';
        $order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? sanitize_key($_GET['order']) : 'desc';

        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $s_table = $wpdb->prefix . 'qp_subjects';
        $ql_table = $wpdb->prefix . 'qp_question_labels';

        $sql_query_from = " FROM {$q_table} q
                            LEFT JOIN {$g_table} g ON q.group_id = g.group_id
                            LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id";
        
        $where = ["q.status = 'publish'"];
        
        if (!empty($_REQUEST['filter_by_subject'])) {
            $where[] = $wpdb->prepare("g.subject_id = %d", absint($_REQUEST['filter_by_subject']));
        }
        if (!empty($_REQUEST['filter_by_label'])) {
            $where[] = $wpdb->prepare("q.question_id IN (SELECT question_id FROM {$ql_table} WHERE label_id = %d)", absint($_REQUEST['filter_by_label']));
        }
        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(stripslashes($_REQUEST['s'])) . '%';
            $where[] = $wpdb->prepare(
                "(q.question_text LIKE %s OR q.custom_question_id LIKE %s)",
                $search_term, $search_term
            );
        }
        
        $sql_query_where = " WHERE " . implode(' AND ', $where);

        $total_items = $wpdb->get_var("SELECT COUNT(q.question_id)" . $sql_query_from . $sql_query_where);

        $data_query = "SELECT q.question_id, q.custom_question_id, q.question_text, q.is_pyq, q.import_date, s.subject_name" . $sql_query_from . $sql_query_where;
        $data_query .= $wpdb->prepare(" ORDER BY %s %s LIMIT %d OFFSET %d", $orderby, $order, $per_page, $offset);
        
        $this->items = $wpdb->get_results($data_query, ARRAY_A);
        
        $question_ids = wp_list_pluck($this->items, 'question_id');
        if (!empty($question_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $question_ids));
            $labels_results = $wpdb->get_results(
                "SELECT ql.question_id, l.label_name, l.label_color
                 FROM {$ql_table} ql
                 JOIN {$wpdb->prefix}qp_labels l ON ql.label_id = l.label_id
                 WHERE ql.question_id IN ($ids_placeholder)"
            );
            $labels_by_question_id = [];
            foreach ($labels_results as $label) {
                if (!isset($labels_by_question_id[$label->question_id])) {
                    $labels_by_question_id[$label->question_id] = [];
                }
                $labels_by_question_id[$label->question_id][] = $label;
            }
            foreach ($this->items as &$item) {
                if (isset($labels_by_question_id[$item['question_id']])) {
                    $item['labels'] = $labels_by_question_id[$item['question_id']];
                }
            }
        }

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
    }

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
            if (!$verified) { wp_die('Security check failed.'); }
            
            $question_ids = $is_bulk ? array_map('absint', $_POST['question_ids']) : [absint($_GET['question_id'])];
            if (empty($question_ids)) return;

            global $wpdb;
            $questions_table = $wpdb->prefix . 'qp_questions';
            $ids_placeholder = implode(',', array_fill(0, count($question_ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE {$questions_table} SET status = 'trash' WHERE question_id IN ($ids_placeholder)", $question_ids));
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_question_text($item) {
        $group_id = isset($item['group_id']) ? $item['group_id'] : 0;
        $trash_nonce = wp_create_nonce('qp_trash_question_' . $item['question_id']);
        $page = esc_attr($_REQUEST['page']);
        $actions = ['edit' => sprintf('<a href="admin.php?page=qp-question-editor&action=edit&group_id=%s">Edit</a>', $group_id), 'trash' => sprintf('<a href="?page=%s&action=trash&question_id=%s&_wpnonce=%s" style="color:#a00;">Trash</a>', $page, $item['question_id'], $trash_nonce)];
        
        $row_text = sprintf('<strong>%s</strong>', wp_trim_words(esc_html($item['question_text']), 20, '...'));

        if (!empty($item['labels'])) {
            $labels_html = '<div style="margin-top: 5px;">';
            foreach ($item['labels'] as $label) {
                $labels_html .= sprintf(
                    '<span style="display: inline-block; margin-right: 5px; padding: 2px 6px; font-size: 11px; border-radius: 3px; color: #fff; background-color: %s;">%s</span>',
                    esc_attr($label->label_color),
                    esc_html($label->label_name)
                );
            }
            $labels_html .= '</div>';
            $row_text .= $labels_html;
        }

        return $row_text . $this->row_actions($actions);
    }

    public function column_is_pyq($item) { return $item['is_pyq'] ? 'Yes' : 'No'; }
    
    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}