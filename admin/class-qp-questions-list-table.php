<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Questions_List_Table extends WP_List_Table
{

    public function __construct() {
        parent::__construct([
            'singular' => 'Question', 'plural' => 'Questions', 'ajax' => false,
            'screen' => get_current_screen() // Important for screen options to work
        ]);
    }

    /**
     * UPDATED: Define the columns for the table
     */
    public function get_columns() {
        return [
            'cb'                 => '<input type="checkbox" />',
            'custom_question_id' => 'ID',
            'question_text'      => 'Question',
            'subject_name'       => 'Subject',
            'source'             => 'Source',
            'is_pyq'             => 'PYQ',
            'last_modified'      => 'Last Modified'
        ];
    }

    public function get_sortable_columns() {
        return [
            'custom_question_id' => ['custom_question_id', true],
            'subject_name'       => ['subject_name', false],
            'last_modified'      => ['last_modified', true]
        ];
    }


    

    protected function get_hidden_columns() {
        // Hide some columns by default
        return ['source', 'is_pyq'];
    }

    
    /**
     * UPDATED: Define the bulk actions based on the current view
     */
    protected function get_bulk_actions() {
    $actions = [];
    $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
    
    if ($status === 'trash') {
        return ['untrash' => 'Restore', 'delete'  => 'Delete Permanently'];
    }
    
    $actions['trash'] = 'Move to Trash';

    if (!empty($_REQUEST['filter_by_label'])) {
        global $wpdb;
        $label_ids = array_map('absint', (array)$_REQUEST['filter_by_label']);
        
        if (!empty($label_ids)) {
            $labels_table = $wpdb->prefix . 'qp_labels';
            $ids_placeholder = implode(',', $label_ids);
            
            $selected_labels = $wpdb->get_results("SELECT label_id, label_name FROM {$labels_table} WHERE label_id IN ({$ids_placeholder})");
            
            foreach ($selected_labels as $label) {
                $actions['remove_label_' . $label->label_id] = 'Remove "' . esc_html($label->label_name) . '" label';
            }
        }
    }
    
    return $actions;
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
        $current_labels = isset($_REQUEST['filter_by_label']) ? array_map('absint', (array)$_REQUEST['filter_by_label']) : [];
?>


<div class="alignleft actions">
    <label for="labels_to_apply" class="screen-reader-text">Apply multiple labels</label>
    <select name="labels_to_apply[]" id="labels_to_apply" multiple="multiple" style="min-width: 180px;">
        <option value="">— Apply Labels —</option>
        <?php foreach ($labels as $label) : ?>
            <option value="<?php echo esc_attr($label->label_id); ?>"><?php echo esc_html($label->label_name); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="submit" name="apply_labels_submit" id="apply_labels_submit" class="button" value="Apply">
</div>
<div class="alignleft actions">
    <select name="filter_by_subject">
        <option value="">All Subjects</option>
        <?php foreach ($subjects as $subject) {
            echo sprintf('<option value="%s" %s>%s</option>', esc_attr($subject->subject_id), selected($current_subject, $subject->subject_id, false), esc_html($subject->subject_name));
        } ?>
    </select>
    
    <select name="filter_by_label[]" multiple="multiple" id="qp_label_filter_select" style="min-width: 200px;">
        <option value="" <?php if (empty($current_labels)) echo 'selected'; ?>>All Labels</option>
        <?php foreach ($labels as $label) {
            $is_selected = in_array($label->label_id, $current_labels);
            echo sprintf('<option value="%s" %s>%s</option>', esc_attr($label->label_id), selected($is_selected, true, false), esc_html($label->label_name));
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

    public function prepare_items()
{
    global $wpdb;
    $this->process_bulk_action();

    $columns = $this->get_columns();
    $hidden = get_hidden_columns($this->screen);
    $sortable = $this->get_sortable_columns();
    $this->_column_headers = [$columns, $hidden, $sortable, 'custom_question_id'];

    $per_page = $this->get_items_per_page('qp_questions_per_page', 20);
    $current_page = $this->get_pagenum();
    $offset = ($current_page - 1) * $per_page;

    $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'import_date';
    $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'desc';

    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $s_table = $wpdb->prefix . 'qp_subjects';
    $ql_table = $wpdb->prefix . 'qp_question_labels';

    $where_conditions = [];
    
    // --- Build base query for Question IDs ---
    $id_query_from = "FROM {$q_table} q";
    $id_query_joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

    // --- Handle Filters ---
    $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'all';
    if ($current_status === 'trash') { $where_conditions[] = "q.status = 'trash'"; }
    else if ($current_status === 'needs_review') {
        $review_label_ids = $wpdb->get_col("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name IN ('Wrong Answer', 'No Answer')");
        if (!empty($review_label_ids)) {
            $ids_placeholder = implode(',', $review_label_ids);
            $where_conditions[] = "q.question_id IN (SELECT question_id FROM {$ql_table} WHERE label_id IN ($ids_placeholder))";
        } else { $where_conditions[] = "1=0"; }
    } else { $where_conditions[] = "q.status = 'publish'"; }

    if (!empty($_REQUEST['filter_by_subject'])) { $where_conditions[] = $wpdb->prepare("g.subject_id = %d", absint($_REQUEST['filter_by_subject'])); }
    if (!empty($_REQUEST['s'])) {
        $search_term = '%' . $wpdb->esc_like(stripslashes($_REQUEST['s'])) . '%';
        $where_conditions[] = $wpdb->prepare("(q.question_text LIKE %s OR q.custom_question_id LIKE %s)", $search_term, $search_term);
    }

    // --- Handle Multi-Label Filter ---
    $selected_label_ids = isset($_REQUEST['filter_by_label']) ? array_filter(array_map('absint', (array)$_REQUEST['filter_by_label'])) : [];
    if (!empty($selected_label_ids)) {
        foreach($selected_label_ids as $index => $label_id) {
            $alias = "ql" . $index;
            $id_query_joins .= " JOIN {$ql_table} AS {$alias} ON q.question_id = {$alias}.question_id";
            $where_conditions[] = $wpdb->prepare("{$alias}.label_id = %d", $label_id);
        }
    }

    $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
    
    // --- Execute Query ---
    $id_query = "SELECT DISTINCT q.question_id {$id_query_from} {$id_query_joins} {$where_clause}";
    $matching_question_ids = $wpdb->get_col($id_query);
    
    $total_items = count($matching_question_ids);

    if (empty($matching_question_ids)) {
        $this->items = [];
    } else {
        $ids_placeholder = implode(',', $matching_question_ids);
        $data_query = "SELECT q.*, s.subject_name, g.group_id, g.direction_text, g.direction_image_id
            FROM {$q_table} q
            LEFT JOIN {$g_table} g ON q.group_id = g.group_id
            LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id
            WHERE q.question_id IN ({$ids_placeholder})
            ORDER BY {$orderby} {$order}
            LIMIT {$per_page} OFFSET {$offset}";
        
        $this->items = $wpdb->get_results($data_query, ARRAY_A);
    }
    
    // Fetch labels for the items on the current page
    $question_ids_on_page = wp_list_pluck($this->items, 'question_id');
    if (!empty($question_ids_on_page)) {
        $labels_placeholder = implode(',', $question_ids_on_page);
        $labels_results = $wpdb->get_results("SELECT ql.question_id, l.label_name, l.label_color FROM {$ql_table} ql JOIN {$wpdb->prefix}qp_labels l ON ql.label_id = l.label_id WHERE ql.question_id IN ({$labels_placeholder})");
        
        $labels_by_question_id = [];
        foreach ($labels_results as $label) {
            $labels_by_question_id[$label->question_id][] = $label;
        }

        foreach ($this->items as &$item) {
            $item['labels'] = $labels_by_question_id[$item['question_id']] ?? [];
        }
    }

    $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
}

/**
     * UPDATED: Process all bulk actions, including the new contextual one
     */
    public function process_bulk_action() {
        $action = $this->current_action();
        if (!$action || $action === -1) return;

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

            $g_table = $wpdb->prefix . 'qp_question_groups';

            // First, get the group IDs for the questions about to be deleted.
            $group_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT group_id FROM {$q_table} WHERE question_id IN ($ids_placeholder)", $question_ids));
            $group_ids = array_filter($group_ids); // Remove any null/empty group IDs

            // Now, delete the questions and their related data.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}qp_options WHERE question_id IN ($ids_placeholder)", $question_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}qp_question_labels WHERE question_id IN ($ids_placeholder)", $question_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$q_table} WHERE question_id IN ($ids_placeholder)", $question_ids));

            // Finally, check if the parent groups are now empty.
            if (!empty($group_ids)) {
                foreach ($group_ids as $group_id) {
                    $remaining_questions = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$q_table} WHERE group_id = %d", $group_id));
                    if ($remaining_questions == 0) {
                        $wpdb->delete($g_table, ['group_id' => $group_id]);
                    }
                }
            }
        }

        // NEW: Handle the dynamic remove label action
        if (strpos($action, 'remove_label_') === 0) {
            if (!wp_verify_nonce($nonce, 'bulk-questions')) wp_die('Security check failed.');
            
            $label_id_to_remove = absint(str_replace('remove_label_', '', $action));
            
            if ($label_id_to_remove > 0) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$ql_table} WHERE label_id = %d AND question_id IN ($ids_placeholder)",
                    $label_id_to_remove, ...$question_ids
                ));
            }
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
    $page = esc_attr($_REQUEST['page']);
    $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'all';
    $group_id = isset($item['group_id']) ? $item['group_id'] : 0; // Ensure group_id is available

    $output = '';

    // Display Direction if it exists
    if (!empty($item['direction_text'])) {
        $direction_display = '<strong>Direction:</strong> ' . wp_trim_words(esc_html($item['direction_text']), 40, '...');

        // Add an image indicator
        if (!empty($item['direction_image_id'])) {
            $direction_display .= ' <span style="background-color: #f0f0f1; color: #50575e; padding: 1px 5px; font-size: 10px; border-radius: 3px; font-weight: 600;">IMAGE</span>';
        }
        $output .= '<div style="padding-bottom: 8px;">' . $direction_display . '</div>';
    }

    // Remove any "Q#:" prefix before displaying the text
        $clean_question_text = preg_replace('/^q\d+:\s*/i', '', $item['question_text']);
        $output .= sprintf('<strong>%s</strong>', wp_trim_words(esc_html($clean_question_text), 50, '...'));

    // Display labels and the duplicate cross-reference
    if (!empty($item['labels'])) {
        $labels_html = '<div class="qp-labels-container" style="margin-top: 5px; display: flex; flex-wrap: wrap; gap: 5px;">';
        foreach ($item['labels'] as $label) {
            $label_text = esc_html($label->label_name);

            if ($label->label_name === 'Duplicate' && !empty($item['duplicate_of'])) {
                $original_custom_id = get_question_custom_id($item['duplicate_of']);
                if ($original_custom_id) {
                    $label_text .= sprintf(' (of #%s)', esc_html($original_custom_id));
                }
            }

            $labels_html .= sprintf(
                '<span class="qp-label" style="background-color: %s; color: #fff; padding: 2px 6px; font-size: 11px; border-radius: 3px;">%s</span>',
                esc_attr($label->label_color),
                $label_text
            );
        }
        $labels_html .= '</div>';
        $output .= $labels_html;
    }

    // Row Actions
    $actions = [];
    if ($status === 'trash') {
        $untrash_nonce = wp_create_nonce('qp_untrash_question_' . $item['question_id']);
        $delete_nonce = wp_create_nonce('qp_delete_question_' . $item['question_id']);
        $actions = [
            'untrash' => sprintf('<a href="?page=%s&action=untrash&question_id=%s&_wpnonce=%s">Restore</a>', $page, $item['question_id'], $untrash_nonce),
            'delete'  => sprintf('<a href="?page=%s&action=delete&question_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'You are about to permanently delete this item. This action cannot be undone. Are you sure?\');">Delete Permanently</a>', $page, $item['question_id'], $delete_nonce),
        ];
    } else {
        $trash_nonce = wp_create_nonce('qp_trash_question_' . $item['question_id']);
        $quick_edit_nonce = wp_create_nonce('qp_get_quick_edit_form_nonce');
        $actions = [
            'edit' => sprintf('<a href="admin.php?page=qp-edit-group&action=edit&group_id=%s">Edit</a>', $group_id),
            'inline hide-if-no-js' => sprintf(
                '<a href="#" class="editinline" data-question-id="%d" data-nonce="%s">Quick Edit</a>',
                $item['question_id'],
                $quick_edit_nonce
            ),
            'trash' => sprintf('<a href="?page=%s&action=trash&question_id=%s&_wpnonce=%s" style="color:#a00;">Trash</a>', $page, $item['question_id'], $trash_nonce),
        ];
    }
        
        $row_text = '';

        // NEW: Display Direction and Image Indicator
        if (!empty($item['direction_text'])) {
            $direction_display = '<strong>Direction:</strong> ' . wp_trim_words(esc_html($item['direction_text']), 25, '...');
            if (!empty($item['direction_image_id'])) {
                $direction_display .= ' <span class="dashicons dashicons-format-image" title="Includes Image" style="color:#888; font-size: 16px; vertical-align: middle;"></span>';
            }
            $row_text .= '<div style="padding: 0px; background-color: #f6f7f7; margin-bottom: 8px; border-radius: 3px;">' . $direction_display . '</div>';
        }
        
        $row_text .= sprintf('<strong>%s</strong>', esc_html($item['question_text']));

        // Display labels and the duplicate cross-reference
        if (!empty($item['labels'])) {
            $labels_html = '<div class="qp-labels-container" style="margin-top: 5px; display: flex; flex-wrap: wrap; gap: 5px;">';
            foreach ($item['labels'] as $label) {
                $label_text = esc_html($label->label_name);

                // If this is the "Duplicate" label and the data exists, create the link.
                if ($label->label_name === 'Duplicate' && !empty($item['duplicate_of'])) {
                    $original_custom_id = get_question_custom_id($item['duplicate_of']);
                    if ($original_custom_id) {
                        $label_text .= sprintf(' (of #%s)', esc_html($original_custom_id));
                    }
                }

                $labels_html .= sprintf(
                    '<span class="qp-label" style="background-color: %s; color: #fff; padding: 2px 6px; font-size: 11px; border-radius: 3px;">%s</span>',
                    esc_attr($label->label_color),
                    $label_text
                );
            }
            $labels_html .= '</div>';
            $row_text .= $labels_html;
        }
        return $row_text . $this->row_actions($actions);
}

    public function column_is_pyq($item)
    {
        return $item['is_pyq'] ? 'Yes' : 'No';
    }

    /**
     * NEW: Custom renderer for our new Source column
     */
    public function column_source($item) {
        $source_info = [];
        if (!empty($item['source_file'])) {
            $source_info[] = '<strong>File:</strong> ' . esc_html($item['source_file']);
        }
        if (!empty($item['source_page'])) {
            $source_info[] = '<strong>Page:</strong> ' . esc_html($item['source_page']);
        }
        if (!empty($item['source_number'])) {
            $source_info[] = '<strong>No:</strong> ' . esc_html($item['source_number']);
        }
        return implode('<br>', $source_info);
    }

    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}
