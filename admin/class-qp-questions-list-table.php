<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Questions_List_Table extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct([
            'singular' => 'Question',
            'plural'   => 'Questions',
            'ajax'     => false
        ]);
    }

    public function get_columns()
    {
        return [
            'cb'                 => '<input type="checkbox" />',
            'custom_question_id' => 'Question ID',
            'question_text'      => 'Question',
            'subject_name'       => 'Subject',
            'is_pyq'             => 'PYQ',
            'import_date'        => 'Date'
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'custom_question_id' => ['custom_question_id', false],
            'question_text'      => ['question_text', false],
            'subject_name'       => ['subject_name', false],
            'import_date'        => ['import_date', true]
        ];
    }

    // REPLACE this method
    protected function get_bulk_actions()
    {
        $status = isset($_REQUEST['status']) && in_array($_REQUEST['status'], ['trash', 'needs_review']) ? $_REQUEST['status'] : 'publish';

        if ($status === 'trash') {
            return ['untrash' => 'Restore', 'delete'  => 'Delete Permanently'];
        }

        if ($status === 'needs_review') {
            return ['remove_review_labels' => 'Remove Review Labels'];
        }

        return ['trash' => 'Move to Trash'];
    }

    // REPLACE this method
    protected function get_views()
    {
        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $l_table = $wpdb->prefix . 'qp_labels';
        $ql_table = $wpdb->prefix . 'qp_question_labels';

        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'all';
        $base_url = admin_url('admin.php?page=question-press');

        $publish_count = $wpdb->get_var("SELECT COUNT(*) FROM $q_table WHERE status = 'publish'");
        $trash_count = $wpdb->get_var("SELECT COUNT(*) FROM $q_table WHERE status = 'trash'");

        // NEW: Count for questions needing review
        $review_label_ids = $wpdb->get_col("SELECT label_id FROM $l_table WHERE label_name IN ('Wrong Answer', 'No Answer')");
        $review_count = 0;
        if (!empty($review_label_ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($review_label_ids), '%d'));
            $review_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT question_id) FROM $ql_table WHERE label_id IN ($ids_placeholder)", $review_label_ids));
        }

        $views = [
            'all' => sprintf('<a href="%s" class="%s">Published <span class="count">(%d)</span></a>', esc_url($base_url), $current_status === 'all' || $current_status === 'publish' ? 'current' : '', $publish_count),
            'needs_review' => sprintf('<a href="%s" class="%s">Needs Review <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', 'needs_review', $base_url)), $current_status === 'needs_review' ? 'current' : '', $review_count),
            'trash' => sprintf('<a href="%s" class="%s">Trash <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', 'trash', $base_url)), $current_status === 'trash' ? 'current' : '', $trash_count)
        ];

        return $views;
    }

    protected function extra_tablenav($which)
    {
        if ($which == "top") {
            global $wpdb;
            $subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
            $labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
            $current_subject = isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : '';
            $current_label = isset($_REQUEST['filter_by_label']) ? absint($_REQUEST['filter_by_label']) : '';
?>
            <div class="alignleft actions">
                <select name="filter_by_subject">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $subject) {
                        echo sprintf('<option value="%s" %s>%s</option>', esc_attr($subject->subject_id), selected($current_subject, $subject->subject_id, false), esc_html($subject->subject_name));
                    } ?>
                </select>
                <select name="filter_by_label">
                    <option value="">All Labels</option>
                    <?php foreach ($labels as $label) {
                        echo sprintf('<option value="%s" %s>%s</option>', esc_attr($label->label_id), selected($current_label, $label->label_id, false), esc_html($label->label_name));
                    } ?>
                </select>
                <?php submit_button('Filter', 'button', 'filter_action', false, ['id' => 'post-query-submit']); ?>
            </div>
        <?php
        }
    }

    public function search_box($text, $input_id)
    {
        $search_button_text = 'Search Questions';
        $input_id = $input_id . '-search-input';
        $search_query = isset($_REQUEST['s']) ? esc_attr(stripslashes($_REQUEST['s'])) : '';
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo $input_id ?>"><?php echo $search_button_text; ?>:</label>
            <input type="search" id="<?php echo $input_id ?>" name="s" value="<?php echo $search_query; ?>" placeholder="By ID or text" />
            <?php submit_button($search_button_text, 'button', 'search_submit', false, array('id' => 'search-submit')); ?>
        </p>
    <?php
    }

    // REPLACE this method
    public function prepare_items()
    {
        global $wpdb;
        $this->process_bulk_action();
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'custom_question_id'];
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'import_date';
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'desc';

        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $s_table = $wpdb->prefix . 'qp_subjects';
        $ql_table = $wpdb->prefix . 'qp_question_labels';

        $sql_query_from = " FROM {$q_table} q LEFT JOIN {$g_table} g ON q.group_id = g.group_id LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id";
        $where = [];
        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'all';

        if ($current_status === 'trash') {
            $where[] = "q.status = 'trash'";
        } else if ($current_status === 'needs_review') {
            $review_label_ids = $wpdb->get_col("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name IN ('Wrong Answer', 'No Answer')");
            if (!empty($review_label_ids)) {
                $ids_placeholder = implode(',', array_fill(0, count($review_label_ids), '%d'));
                $where[] = $wpdb->prepare("q.question_id IN (SELECT question_id FROM {$ql_table} WHERE label_id IN ($ids_placeholder))", $review_label_ids);
            } else {
                $where[] = "1=0"; // No review labels found, so show no results
            }
        } else {
            $where[] = "q.status = 'publish'";
        }

        if (!empty($_REQUEST['filter_by_subject'])) {
            $where[] = $wpdb->prepare("g.subject_id = %d", absint($_REQUEST['filter_by_subject']));
        }
        if (!empty($_REQUEST['filter_by_label'])) {
            $where[] = $wpdb->prepare("q.question_id IN (SELECT question_id FROM {$ql_table} WHERE label_id = %d)", absint($_REQUEST['filter_by_label']));
        }
        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(stripslashes($_REQUEST['s'])) . '%';
            $where[] = $wpdb->prepare("(q.question_text LIKE %s OR q.custom_question_id LIKE %s)", $search_term, $search_term);
        }

        $sql_query_where = " WHERE " . implode(' AND ', $where);

        $total_items = $wpdb->get_var("SELECT COUNT(q.question_id)" . $sql_query_from . $sql_query_where);
        $data_query = "SELECT q.question_id, q.custom_question_id, q.question_text, q.is_pyq, q.import_date, s.subject_name" . $sql_query_from . $sql_query_where;
        $data_query .= $wpdb->prepare(" ORDER BY %s %s LIMIT %d OFFSET %d", $orderby, $order, $per_page, $offset);

        $this->items = $wpdb->get_results($data_query, ARRAY_A);

        $question_ids = wp_list_pluck($this->items, 'question_id');
        if (!empty($question_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $question_ids));
            $labels_results = $wpdb->get_results("SELECT ql.question_id, l.label_name, l.label_color FROM {$ql_table} ql JOIN {$wpdb->prefix}qp_labels l ON ql.label_id = l.label_id WHERE ql.question_id IN ($ids_placeholder)");
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

    // REPLACE this method
    public function process_bulk_action()
    {
        $action = $this->current_action();
        if (!$action) return;

        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_key($_REQUEST['_wpnonce']) : '';
        $question_ids = isset($_REQUEST['question_ids']) ? array_map('absint', $_REQUEST['question_ids']) : (isset($_REQUEST['question_id']) ? [absint($_REQUEST['question_id'])] : []);

        if (empty($question_ids)) return;

        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $ql_table = $wpdb->prefix . 'qp_question_labels';
        $ids_placeholder = implode(',', array_fill(0, count($question_ids), '%d'));
        if ('trash' === $action) {
            if (!wp_verify_nonce($nonce, 'bulk-questions') && !wp_verify_nonce($nonce, 'qp_trash_question_' . $question_ids[0])) wp_die('Security check failed.');
            $wpdb->query($wpdb->prepare("UPDATE {$q_table} SET status = 'trash' WHERE question_id IN ($ids_placeholder)", $question_ids));
        }
        if ('untrash' === $action) {
            if (!wp_verify_nonce($nonce, 'bulk-questions') && !wp_verify_nonce($nonce, 'qp_untrash_question_' . $question_ids[0])) wp_die('Security check failed.');
            $wpdb->query($wpdb->prepare("UPDATE {$q_table} SET status = 'publish' WHERE question_id IN ($ids_placeholder)", $question_ids));
        }
        if ('delete' === $action) {
            if (!wp_verify_nonce($nonce, 'bulk-questions') && !wp_verify_nonce($nonce, 'qp_delete_question_' . $question_ids[0])) wp_die('Security check failed.');
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}qp_options WHERE question_id IN ($ids_placeholder)", $question_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}qp_question_labels WHERE question_id IN ($ids_placeholder)", $question_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$q_table} WHERE question_id IN ($ids_placeholder)", $question_ids));
        }
        // NEW: Handle the new bulk action
        if ('remove_review_labels' === $action) {
            if (!wp_verify_nonce($nonce, 'bulk-questions')) wp_die('Security check failed.');

            $labels_table = $wpdb->prefix . 'qp_labels';
            $review_label_ids = $wpdb->get_col("SELECT label_id FROM $labels_table WHERE label_name IN ('Wrong Answer', 'No Answer')");

            if (!empty($review_label_ids)) {
                $label_ids_placeholder = implode(',', array_fill(0, count($review_label_ids), '%d'));

                $args = array_merge($question_ids, $review_label_ids);

                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$ql_table} WHERE question_id IN ($ids_placeholder) AND label_id IN ($label_ids_placeholder)",
                    $args
                ));
            }
        }
    }


    /**
     * Override the parent display_rows method to add our inline editor row
     */
    public function display_rows()
    {
        foreach ($this->items as $item) {
            echo '<tr id="post-' . $item['question_id'] . '">';
            $this->single_row_columns($item);
            echo '</tr>';
            // Add the hidden row for the editor right after each question row
            $this->display_quick_edit_row($item);
        }
    }

    private function display_quick_edit_row($item)
    {
    ?>
        <tr id="edit-<?php echo $item['question_id']; ?>" class="inline-edit-row quick-edit-row" style="display: none;">
            <td colspan="<?php echo $this->get_column_count(); ?>" class="colspanchange">
                <div class="inline-edit-col">
                </div>
            </td>
        </tr>
<?php
    }

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_question_text($item) {
        $group_id = isset($item['group_id']) ? $item['group_id'] : 0;
        $page = esc_attr($_REQUEST['page']);
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'all';
        $actions = [];

        if ($status === 'trash') {
            // ... trash actions are unchanged ...
        } else {
            $trash_nonce = wp_create_nonce('qp_trash_question_' . $item['question_id']);
            $actions = [
                'edit' => sprintf('<a href="admin.php?page=qp-question-editor&action=edit&group_id=%s">Edit</a>', $group_id),
                // NEW: Quick Edit link
                'inline hide-if-no-js' => sprintf(
                    '<a href="#" class="editinline" data-question-id="%d">Quick Edit</a>',
                    $item['question_id']
                ),
                'trash' => sprintf('<a href="?page=%s&action=trash&question_id=%s&_wpnonce=%s" style="color:#a00;">Trash</a>', $page, $item['question_id'], $trash_nonce),
            ];
        }
        
        $row_text = sprintf('<strong>%s</strong>', wp_trim_words(esc_html($item['question_text']), 20, '...'));
        if (!empty($item['labels'])) {
            // ... label display logic is unchanged ...
        }
        return $row_text . $this->row_actions($actions);
    }

    public function column_is_pyq($item)
    {
        return $item['is_pyq'] ? 'Yes' : 'No';
    }
    public function column_default($item, $column_name)
    {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}
