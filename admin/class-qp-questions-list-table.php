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
     * UPDATED: Define the bulk actions. This now includes the contextual "Remove Label" action.
     */
    protected function get_bulk_actions() {
        global $wpdb;
        $actions = [];
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        
        if ($status === 'trash') {
            return ['untrash' => 'Restore', 'delete'  => 'Delete Permanently'];
        }
        
        $actions['trash'] = 'Move to Trash';

        // Add "Remove Label" action only when filtering by a label
        if (!empty($_REQUEST['filter_by_label'])) {
            $label_ids = array_map('absint', (array)$_REQUEST['filter_by_label']);
            
            if (!empty($label_ids)) {
                $labels_table = $wpdb->prefix . 'qp_labels';
                $ids_placeholder = implode(',', array_fill(0, count($label_ids), '%d'));
                
                $selected_labels = $wpdb->get_results($wpdb->prepare("SELECT label_id, label_name FROM {$labels_table} WHERE label_id IN ({$ids_placeholder})", $label_ids));
                
                if ($selected_labels) {
                     $actions['remove_label_group_start'] = '--- Remove Labels ---';
                    foreach ($selected_labels as $label) {
                        $actions['remove_label_' . $label->label_id] = 'Remove "' . esc_html($label->label_name) . '" label';
                    }
                }
            }
        }
        
        return $actions;
    }

    /**
     * NEW: Overriding the parent bulk_actions method to insert our custom controls.
     * This creates the side-by-side dropdowns with a single Apply button.
     */
    protected function bulk_actions( $which = '' ) {
        if ( is_null( $this->_actions ) ) {
            $this->_actions = $this->get_bulk_actions();
            // Remove visual separators from the <select> dropdown
            $this->_actions = array_filter($this->_actions, function($key) {
                return strpos($key, '_group_start') === false;
            }, ARRAY_FILTER_USE_KEY);
        }
        if ( empty( $this->_actions ) ) {
            return;
        }

        echo '<label for="bulk-action-selector-' . esc_attr( $which ) . '" class="screen-reader-text">' . __( 'Select bulk action' ) . '</label>';
        echo '<select name="action" id="bulk-action-selector-' . esc_attr( $which ) . '">';
        echo '<option value="-1">' . __( 'Bulk Actions' ) . '</option>';

        foreach ( $this->get_bulk_actions() as $name => $title ) {
            $class = 'edit' === $name ? 'hide-if-no-js' : '';
            // Use an <optgroup> for visual separation
            if (strpos($name, '_group_start') !== false) {
                 echo '<optgroup label="' . esc_attr( $title ) . '">';
                 continue;
            }
            echo "\n" . '<option value="' . esc_attr($name) . '" class="' . $class . '">' . $title . '</option>';
        }
        echo '</select>';

        // --- ADDING OUR CUSTOM MULTI-LABEL DROPDOWN ---
        global $wpdb;
        $labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
        if ($labels) {
            echo '<span style="margin-left: 5px;"></span>'; // Add a small gap
            echo '<label for="labels_to_apply" class="screen-reader-text">Add labels to selected questions</label>';
            echo '<select name="labels_to_apply[]" id="labels_to_apply" multiple="multiple" style="min-width: 180px;">';
            echo '<option value="">— Add Labels —</option>';
            foreach ($labels as $label) {
                echo sprintf('<option value="%s">%s</option>', esc_attr($label->label_id), esc_html($label->label_name));
            }
            echo '</select>';
        }
        // --- END CUSTOM DROPDOWN ---

        submit_button( __( 'Apply' ), 'action', '', false, array( 'id' => 'doaction' . ( 'top' === $which ? '' : '2' ), 'style' => 'margin-left: 5px;'  ) );
        echo "\n";
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
            // UPDATED: Query now joins the questions table to check the status is 'publish'
            $review_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT ql.question_id) 
                 FROM {$ql_table} ql 
                 JOIN {$q_table} q ON ql.question_id = q.question_id 
                 WHERE ql.label_id IN ($ids_placeholder) AND q.status = 'publish'", 
                $review_label_ids
            ));
        }

        $views = [
            'all' => sprintf('<a href="%s" class="%s">Published <span class="count">(%d)</span></a>', esc_url($base_url), $current_status === 'all' || $current_status === 'publish' ? 'current' : '', $publish_count),
            'needs_review' => sprintf('<a href="%s" class="%s">Needs Review <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', 'needs_review', $base_url)), $current_status === 'needs_review' ? 'current' : '', $review_count),
            'trash' => sprintf('<a href="%s" class="%s">Trash <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', 'trash', $base_url)), $current_status === 'trash' ? 'current' : '', $trash_count)
        ];

        return $views;
    }

    /**
     * NEW: Adding back a simplified extra_tablenav just for the filters.
     */
    protected function extra_tablenav($which) {
        if ($which == "top") {
            global $wpdb;
            
            // This container will hold our filter controls
            echo '<div class="alignleft actions">';

                $subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
                $current_subject = isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : '';

                $labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
                $current_labels = isset($_REQUEST['filter_by_label']) ? array_map('absint', (array)$_REQUEST['filter_by_label']) : [];

                // Subject filter
                echo '<select name="filter_by_subject" style="margin-left: 5px;">';
                echo '<option value="">All Subjects</option>';
                foreach ($subjects as $subject) {
                    echo sprintf('<option value="%s" %s>%s</option>', esc_attr($subject->subject_id), selected($current_subject, $subject->subject_id, false), esc_html($subject->subject_name));
                }
                echo '</select>';
                
                // Label filter
                echo '<select name="filter_by_label[]" multiple="multiple" id="qp_label_filter_select" style="min-width: 200px; margin-left: 5px;">';
                echo '<option value="" ' . (empty($current_labels) ? 'selected' : '') . '>Filter by Label(s)</option>';
                foreach ($labels as $label) {
                    $is_selected = in_array($label->label_id, $current_labels);
                    echo sprintf('<option value="%s" %s>%s</option>', esc_attr($label->label_id), selected($is_selected, true, false), esc_html($label->label_name));
                }
                echo '</select>';
                
                // The dedicated "Filter" button with a gap
                submit_button('Filter', 'button', 'filter_action', false, ['id' => 'post-query-submit', 'style' => 'margin-left: 5px;']);

            echo '</div>'; // End filter actions div
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

    public function prepare_items() {
    global $wpdb;
    $this->process_bulk_action();

    $columns = $this->get_columns();
    $hidden = get_hidden_columns($this->screen);
    $sortable = $this->get_sortable_columns();
    $this->_column_headers = [$columns, $hidden, $sortable, 'custom_question_id'];

    $per_page = $this->get_items_per_page('qp_questions_per_page', 20);
    $current_page = $this->get_pagenum();
    $offset = ($current_page - 1) * $per_page;

    $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'custom_question_id';
    $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'desc';

    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $s_table = $wpdb->prefix . 'qp_subjects';
    $ql_table = $wpdb->prefix . 'qp_question_labels';
    $src_table = $wpdb->prefix . 'qp_sources';
    $sec_table = $wpdb->prefix . 'qp_source_sections';

    $where_conditions = [];
    $id_query_from = "FROM {$q_table} q";
    $id_query_joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

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

    $selected_label_ids = isset($_REQUEST['filter_by_label']) ? array_filter(array_map('absint', (array)$_REQUEST['filter_by_label'])) : [];
    if (!empty($selected_label_ids)) {
        foreach($selected_label_ids as $index => $label_id) {
            $alias = "ql" . $index;
            $id_query_joins .= " JOIN {$ql_table} AS {$alias} ON q.question_id = {$alias}.question_id";
            $where_conditions[] = $wpdb->prepare("{$alias}.label_id = %d", $label_id);
        }
    }

    $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
    $id_query = "SELECT DISTINCT q.question_id {$id_query_from} {$id_query_joins} {$where_clause}";
    $matching_question_ids = $wpdb->get_col($id_query);

    $total_items = count($matching_question_ids);

    if (empty($matching_question_ids)) {
        $this->items = [];
    } else {
        $ids_placeholder = implode(',', $matching_question_ids);
        $data_query = "SELECT q.*, s.subject_name, g.group_id, g.direction_text, g.direction_image_id, g.is_pyq,
                        src.source_name, sec.section_name
            FROM {$q_table} q
            LEFT JOIN {$g_table} g ON q.group_id = g.group_id
            LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id
            LEFT JOIN {$src_table} src ON q.source_id = src.source_id
            LEFT JOIN {$sec_table} sec ON q.section_id = sec.section_id
            WHERE q.question_id IN ({$ids_placeholder})
            ORDER BY {$orderby} {$order}
            LIMIT {$per_page} OFFSET {$offset}";

        $this->items = $wpdb->get_results($data_query, ARRAY_A);
    }

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
     * UPDATED: Process all bulk actions, now correctly handling nonces for single-item actions.
     */
    public function process_bulk_action() {
        $action = $this->current_action();
        $labels_to_apply = isset($_POST['labels_to_apply']) ? array_filter(array_map('absint', $_POST['labels_to_apply'])) : [];

        // Exit if there's no action to perform
        if ((!$action || $action === -1) && empty($labels_to_apply)) {
            return;
        }

        // --- UPDATED: Smarter Nonce Verification ---
        // Check for a single-item action nonce (from a GET request)
        if (isset($_GET['question_id']) && isset($_GET['_wpnonce'])) {
            $question_id = absint($_GET['question_id']);
            $nonce_action = 'qp_' . $action . '_question_' . $question_id;
            if (!wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
                wp_die('Security check failed for single item action.');
            }
        // Check for a bulk action nonce (from a POST request)
        } elseif (isset($_POST['question_ids'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'], 'bulk-questions')) {
                 wp_die('Security check failed for bulk action.');
            }
        } else {
            return; // No items or valid nonce found
        }
        // --- END NONCE VERIFICATION ---

        // Consolidate IDs from either a bulk or single action
        $question_ids = isset($_REQUEST['question_ids']) ? array_map('absint', (array) $_REQUEST['question_ids']) : [absint($_GET['question_id'])];
        if (empty($question_ids)) {
            return;
        }

        global $wpdb;

        if (!empty($labels_to_apply)) {
            $ql_table = $wpdb->prefix . 'qp_question_labels';
            foreach ($question_ids as $question_id) {
                foreach ($labels_to_apply as $label_id) {
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$ql_table} (question_id, label_id) VALUES (%d, %d)",
                        $question_id, $label_id
                    ));
                }
            }
        }

        if ($action && $action !== -1) {
            $q_table = $wpdb->prefix . 'qp_questions';
            $ids_placeholder = implode(',', $question_ids);

            if ('trash' === $action) {
                $wpdb->query("UPDATE {$q_table} SET status = 'trash' WHERE question_id IN ({$ids_placeholder})");
            }
            if ('untrash' === $action) {
                $wpdb->query("UPDATE {$q_table} SET status = 'publish' WHERE question_id IN ({$ids_placeholder})");
            }
            if ('delete' === $action) {
                $g_table = $wpdb->prefix . 'qp_question_groups';
                $group_ids = $wpdb->get_col("SELECT DISTINCT group_id FROM {$q_table} WHERE question_id IN ({$ids_placeholder})");
                $group_ids = array_filter($group_ids);

                $wpdb->query("DELETE FROM {$wpdb->prefix}qp_options WHERE question_id IN ({$ids_placeholder})");
                $wpdb->query("DELETE FROM {$wpdb->prefix}qp_question_labels WHERE question_id IN ({$ids_placeholder})");
                $wpdb->query("DELETE FROM {$q_table} WHERE question_id IN ({$ids_placeholder})");

                if (!empty($group_ids)) {
                    foreach ($group_ids as $group_id) {
                        $remaining = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$q_table} WHERE group_id = %d", $group_id));
                        if ($remaining == 0) {
                            $wpdb->delete($g_table, ['group_id' => $group_id]);
                        }
                    }
                }
            }
            if (strpos($action, 'remove_label_') === 0) {
                $ql_table = $wpdb->prefix . 'qp_question_labels';
                $label_id_to_remove = absint(str_replace('remove_label_', '', $action));
                if ($label_id_to_remove > 0) {
                    $wpdb->query("DELETE FROM {$ql_table} WHERE label_id = {$label_id_to_remove} AND question_id IN ({$ids_placeholder})");
                }
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

        // --- Row Actions with Correct Nonces ---
        $actions = [];
        if ($status === 'trash') {
            // UPDATED: Create unique nonces for single-item actions
            $untrash_nonce = wp_create_nonce('qp_untrash_question_' . $item['question_id']);
            $delete_nonce = wp_create_nonce('qp_delete_question_' . $item['question_id']);
            $actions = [
                'untrash' => sprintf('<a href="?page=%s&action=untrash&question_id=%s&_wpnonce=%s">Restore</a>', $page, $item['question_id'], $untrash_nonce),
                'delete'  => sprintf('<a href="?page=%s&action=delete&question_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'You are about to permanently delete this item. This action cannot be undone. Are you sure?\');">Delete Permanently</a>', $page, $item['question_id'], $delete_nonce),
            ];
        } else {
            // UPDATED: Create unique nonces for single-item actions
            $trash_nonce = wp_create_nonce('qp_trash_question_' . $item['question_id']);
            $quick_edit_nonce = wp_create_nonce('qp_get_quick_edit_form_nonce'); // This one is fine as it's for AJAX
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
    

public function column_source($item) {
    $source_info = [];
    // Reads from the joined source and section data
    if (!empty($item['source_name'])) {
        $source_info[] = '<strong>Source:</strong> ' . esc_html($item['source_name']);
    }
    if (!empty($item['section_name'])) {
        $source_info[] = '<strong>Section:</strong> ' . esc_html($item['section_name']);
    }
    if (!empty($item['question_number_in_section'])) {
        $source_info[] = '<strong>No:</strong> ' . esc_html($item['question_number_in_section']);
    }
    return implode('<br>', $source_info);
}

    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}
