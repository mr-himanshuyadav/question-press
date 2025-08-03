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
            'plural' => 'Questions',
            'ajax' => false,
            'screen' => get_current_screen() // Important for screen options to work
        ]);
    }

    /**
     * UPDATED: Define the columns for the table
     */
    public function get_columns()
    {
        return [
            'cb'                 => '<input type="checkbox" />',
            'custom_question_id' => 'ID',
            'question_text'      => 'Question',
            'subject_name'       => 'Subject',
            'source'             => 'Source',
            'is_pyq'             => 'PYQ',
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'custom_question_id' => ['custom_question_id', true],
            'subject_name'       => ['subject_name', false],
        ];
    }




    protected function get_hidden_columns()
    {
        // Hide some columns by default
        return ['source', 'is_pyq'];
    }


    /**
     * UPDATED: Define the bulk actions. This now includes the contextual "Remove Label" action.
     */
    protected function get_bulk_actions()
    {
        global $wpdb;
        $actions = [];
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';

        if ($status === 'trash') {
            return ['untrash' => 'Restore', 'delete'  => 'Delete Permanently'];
        }

        $actions['trash'] = 'Move to Trash';

        // Add "Remove Label" action only when filtering by a label
        // Add "Remove Label" action only when filtering by a label
    if (!empty($_REQUEST['filter_by_label'])) {
    $label_ids = array_map('absint', (array)$_REQUEST['filter_by_label']);

    if (!empty($label_ids)) {
        // UPDATED: Query the new terms table
        $terms_table = $wpdb->prefix . 'qp_terms';
        $ids_placeholder = implode(',', array_fill(0, count($label_ids), '%d'));

        $selected_labels = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM {$terms_table} WHERE term_id IN ({$ids_placeholder})", $label_ids));

        if ($selected_labels) {
            $actions['remove_label_group_start'] = '--- Remove Labels ---';
            foreach ($selected_labels as $label) {
                $actions['remove_label_' . $label->term_id] = 'Remove "' . esc_html($label->name) . '" label';
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
protected function bulk_actions($which = '')
{
    if (is_null($this->_actions)) {
        $this->_actions = $this->get_bulk_actions();
        // Remove visual separators from the <select> dropdown
        $this->_actions = array_filter($this->_actions, function ($key) {
            return strpos($key, '_group_start') === false;
        }, ARRAY_FILTER_USE_KEY);
    }
    if (empty($this->_actions)) {
        return;
    }

    echo '<label for="bulk-action-selector-' . esc_attr($which) . '" class="screen-reader-text">' . __('Select bulk action') . '</label>';
    echo '<select name="action" id="bulk-action-selector-' . esc_attr($which) . '">';
    echo '<option value="-1">' . __('Bulk Actions') . '</option>';

    foreach ($this->get_bulk_actions() as $name => $title) {
        $class = 'edit' === $name ? 'hide-if-no-js' : '';
        // Use an <optgroup> for visual separation
        if (strpos($name, '_group_start') !== false) {
            echo '<optgroup label="' . esc_attr($title) . '">';
            continue;
        }
        echo "\n" . '<option value="' . esc_attr($name) . '" class="' . $class . '">' . $title . '</option>';
    }
    echo '</select>';

    // --- ADDING OUR CUSTOM MULTI-LABEL DROPDOWN (with corrected query) ---
    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");

    $labels = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
        $label_tax_id
    ));

    if ($labels) {
        echo '<span style="margin-left: 5px;"></span>'; // Add a small gap
        echo '<label for="labels_to_apply" class="screen-reader-text">Add labels to selected questions</label>';
        echo '<select name="labels_to_apply[]" id="labels_to_apply" multiple="multiple" style="min-width: 180px;">';
        echo '<option value="">— Add Labels —</option>';
        foreach ($labels as $label) {
            printf('<option value="%s">%s</option>', esc_attr($label->term_id), esc_html($label->name));
        }
        echo '</select>';
    }

    // --- END CUSTOM DROPDOWN ---

    submit_button(__('Apply'), 'action', '', false, array('id' => 'doaction' . ('top' === $which ? '' : '2'), 'style' => 'margin-left: 5px;'));
    echo "\n";
}

    protected function get_views()
    {
        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish'; // Default to 'publish'
        $base_url = admin_url('admin.php?page=question-press');

        $review_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT question_id) FROM {$reports_table} WHERE status = %s",
            'open'
        ));
        $review_url = admin_url('admin.php?page=qp-logs-reports&tab=reports');

        $publish_count = $wpdb->get_var("SELECT COUNT(*) FROM $q_table WHERE status = 'publish'");
        $draft_count = $wpdb->get_var("SELECT COUNT(*) FROM $q_table WHERE status = 'draft'");
        $trash_count = $wpdb->get_var("SELECT COUNT(*) FROM $q_table WHERE status = 'trash'");

        $views = [
            'publish' => sprintf('<a href="%s" class="%s">Published <span class="count">(%d)</span></a>', esc_url($base_url), in_array($current_status, ['all', 'publish']) ? 'current' : '', $publish_count),
            'draft' => sprintf('<a href="%s" class="%s">Drafts <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', 'draft', $base_url)), $current_status === 'draft' ? 'current' : '', $draft_count),
            'needs_review' => sprintf('<a href="%s">Needs Review <span class="count" style="color: #c00;">(%d)</span></a>', esc_url($review_url), $review_count),
            'trash' => sprintf('<a href="%s" class="%s">Trash <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', 'trash', $base_url)), $current_status === 'trash' ? 'current' : '', $trash_count)
        ];

        return array_filter($views); // Use array_filter to remove views with a count of 0 if needed in the future
    }

    protected function extra_tablenav($which)
{
    if ($which == "top") {
        global $wpdb;

        // --- ROW 1: Standard Filters (No changes needed here, it is correct) ---
        echo '<div class="alignleft actions">';

        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

        $subjects = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC",
            $subject_tax_id
        ));

        $current_subject = isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : '';
        echo '<select name="filter_by_subject" id="qp_filter_by_subject" style="margin-right: 5px;">';
        echo '<option value="">All Subjects</option>';
        foreach ($subjects as $subject) {
            printf('<option value="%s" %s>%s</option>', esc_attr($subject->term_id), selected($current_subject, $subject->term_id, false), esc_html($subject->name));
        }
        echo '</select>';

        $current_topic = isset($_REQUEST['filter_by_topic']) ? absint($_REQUEST['filter_by_topic']) : '';
        echo '<select name="filter_by_topic" id="qp_filter_by_topic" style="margin-right: 5px; display: none;">';
        echo '<option value="">All Topics</option>';
        echo '</select>';

        $current_source_or_section = isset($_REQUEST['filter_by_source']) ? esc_attr($_REQUEST['filter_by_source']) : '';
        echo '<select name="filter_by_source" id="qp_filter_by_source_section" style="margin-right: 5px; display: none;">';
        echo '<option value="">All Sources / Sections</option>';
        echo '</select>';

        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");
        $labels = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $label_tax_id
        ));

        $current_labels = isset($_REQUEST['filter_by_label']) ? array_map('absint', (array)$_REQUEST['filter_by_label']) : [];
        echo '<select name="filter_by_label[]" multiple="multiple" id="qp_label_filter_select" style="min-width: 200px; margin-right: 5px;">';
        echo '<option value="" ' . (empty($current_labels) ? 'selected' : '') . '>Filter by Label(s)</option>';
        foreach ($labels as $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($label->term_id), selected(in_array($label->term_id, $current_labels), true, false), esc_html($label->name));
        }
        echo '</select>';

        submit_button('Filter', 'button', 'filter_action', false, ['id' => 'post-query-submit']);
        echo '</div>';

        // --- ROW 2: Bulk Edit Controls (THIS IS THE CORRECTED PART) ---

        echo '<div id="qp-bulk-edit-panel" class="alignleft actions" style="display: none; clear: both; margin-top: 10px; padding: 10px; border: 1px solid #cce7f6; background-color: #f6f7f7;">';

        // NEW, CORRECTED QUERIES
        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");
        $all_exams = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $exam_tax_id));

        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
        $all_sources = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC", $source_tax_id));
        $all_sections = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0 ORDER BY name ASC", $source_tax_id));

        // Note: Topics are children of subjects, so they are fetched dynamically via JavaScript based on the filtered subject.
        // We will leave the initial dropdown empty and let the existing JS handle populating it based on the subject filter.

        echo '<span style="font-weight: bold; margin-right: 5px;">Bulk Edit:</span>';

        // Source Dropdown
        echo '<select name="bulk_edit_source" id="bulk_edit_source" style="margin-right: 5px;max-width: 18rem;">';
        echo '<option value="">— Change Source —</option>';
        foreach ($all_sources as $source) {
            printf('<option value="%s">%s</option>', esc_attr($source->term_id), esc_html($source->name));
        }
        echo '</select>';

        // Section Dropdown
        echo '<select name="bulk_edit_section" id="bulk_edit_section" style="margin-right: 5px;" disabled="disabled">';
        echo '<option value="">— Select Source First —</option>';
        foreach ($all_sections as $section) {
            printf('<option value="%s">%s</option>', esc_attr($section->term_id), esc_html($section->name));
        }
        echo '</select>';

        // Topic Dropdown (Initially empty, populated by JS)
        echo '<select name="bulk_edit_topic" id="bulk_edit_topic" style="margin-right: 5px;" disabled="disabled">';
        echo '<option value="">— Select Subject to see Topics —</option>';
        echo '</select>';

        // Exam Dropdown
        echo '<select name="bulk_edit_exam" id="bulk_edit_exam" style="margin-right: 5px; max-width: 20rem;" disabled="disabled">';
        echo '<option value="">— Select Subject to edit exam —</option>';
        foreach ($all_exams as $exam) {
            printf('<option value="%s">%s</option>', esc_attr($exam->term_id), esc_html($exam->name));
        }
        echo '</select>';

        submit_button('Apply Changes', 'primary', 'bulk_edit_apply', false);

        echo '</div>';
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

        $columns = $this->get_columns();
        $hidden = get_hidden_columns($this->screen);
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable, 'custom_question_id'];

        $per_page = $this->get_items_per_page('qp_questions_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'custom_question_id';
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'desc';

        // Define new table names
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        // Base query structure
        $query_from = "FROM {$q_table} q";
        $query_joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";
        $where_conditions = [];

        // Status filter (remains the same)
        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        $where_conditions[] = $wpdb->prepare("q.status = %s", $current_status);

        $joins_added = []; // Helper to prevent duplicate joins

        // Status filter (remains the same)
        $current_status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish';
        $where_conditions[] = $wpdb->prepare("q.status = %s", $current_status);

        // In admin/class-qp-questions-list-table.php, inside prepare_items()

// Handle Subject and Topic Filters
$subject_id = !empty($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : 0;
$topic_id = !empty($_REQUEST['filter_by_topic']) ? absint($_REQUEST['filter_by_topic']) : 0;

if ($topic_id) {
    // If a specific topic is selected, filter by it directly. This is the most specific filter.
    if (!in_array('topic_rel', $joins_added)) {
        $query_joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
        $joins_added[] = 'topic_rel';
    }
    $where_conditions[] = $wpdb->prepare("topic_rel.term_id = %d", $topic_id);
} elseif ($subject_id) {
    // If only a subject is selected, find all topics under that subject and filter by those.
    $child_topic_ids = $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$term_table} WHERE parent = %d", $subject_id));

    if (!empty($child_topic_ids)) {
        $ids_placeholder = implode(',', $child_topic_ids);
        if (!in_array('topic_rel', $joins_added)) {
            $query_joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
            $joins_added[] = 'topic_rel';
        }
        $where_conditions[] = "topic_rel.term_id IN ($ids_placeholder)";
    } else {
        // If the subject has no topics, no questions can be found.
        $where_conditions[] = "1=0"; // This will correctly return no results.
    }
}

// Handle Source/Section Filter
if (!empty($_REQUEST['filter_by_source'])) {
    $filter_value = sanitize_text_field($_REQUEST['filter_by_source']);
    $term_id_to_filter = 0;
    if (strpos($filter_value, 'source_') === 0) {
        $term_id_to_filter = absint(str_replace('source_', '', $filter_value));
    } elseif (strpos($filter_value, 'section_') === 0) {
        $term_id_to_filter = absint(str_replace('section_', '', $filter_value));
    }

    if ($term_id_to_filter > 0) {
        // This logic was already correct: it joins the group to the source/section term.
        if (!in_array('source_rel', $joins_added)) {
            $query_joins .= " JOIN {$rel_table} source_rel ON g.group_id = source_rel.object_id AND source_rel.object_type = 'group'";
            $joins_added[] = 'source_rel';
        }
        $where_conditions[] = $wpdb->prepare("source_rel.term_id = %d", $term_id_to_filter);
    }
}

        // Handle Search Filter (remains the same as old logic)
        if (!empty($_REQUEST['s'])) {
            $search_term = '%' . $wpdb->esc_like(stripslashes($_REQUEST['s'])) . '%';
            $where_conditions[] = $wpdb->prepare("(q.question_text LIKE %s OR q.custom_question_id LIKE %s)", $search_term, $search_term);
        }

        // Handle Label Filter
        $selected_label_ids = isset($_REQUEST['filter_by_label']) ? array_filter(array_map('absint', (array)$_REQUEST['filter_by_label'])) : [];
        if (!empty($selected_label_ids)) {
            $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");

            // This subquery ensures that we only get questions that have ALL of the selected labels.
            $question_ids_with_all_labels = $wpdb->get_col($wpdb->prepare(
                "SELECT r.object_id
         FROM {$rel_table} r
         JOIN {$term_table} t ON r.term_id = t.term_id
         WHERE r.object_type = 'question' AND t.taxonomy_id = %d AND r.term_id IN (" . implode(',', $selected_label_ids) . ")
         GROUP BY r.object_id
         HAVING COUNT(DISTINCT r.term_id) = %d",
                $label_tax_id,
                count($selected_label_ids)
            ));

            if (empty($question_ids_with_all_labels)) {
                // If no questions match, we can add a condition that will always be false to return no results.
                $where_conditions[] = "1=0";
            } else {
                $ids_placeholder = implode(',', $question_ids_with_all_labels);
                $where_conditions[] = "q.question_id IN ({$ids_placeholder})";
            }
        }

        $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);

        // Get the total number of items
        $total_items_query = "SELECT COUNT(DISTINCT q.question_id) {$query_from} {$query_joins} {$where_clause}";
        $total_items = $wpdb->get_var($total_items_query);

        // Fetch the actual data for the current page
        // Fetch the actual data for the current page
$data_query = "SELECT
    q.*,
    g.group_id, g.direction_text, g.direction_image_id, g.is_pyq, g.pyq_year,
    subject_term.name AS subject_name,
    topic_term.name AS topic_name,
    exam_term.name AS exam_name,
    source_rel.term_id AS linked_source_term_id
FROM {$q_table} q
LEFT JOIN {$g_table} g ON q.group_id = g.group_id
-- Get the Topic linked to the GROUP
LEFT JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group' AND topic_rel.term_id IN (SELECT term_id FROM {$term_table} WHERE parent != 0 AND taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'))
LEFT JOIN {$term_table} topic_term ON topic_rel.term_id = topic_term.term_id
-- Get the Subject by finding the Topic's parent
LEFT JOIN {$term_table} subject_term ON topic_term.parent = subject_term.term_id
-- Get the Exam linked to the GROUP
LEFT JOIN {$rel_table} exam_rel ON g.group_id = exam_rel.object_id AND exam_rel.object_type = 'group' AND exam_rel.term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'))
LEFT JOIN {$term_table} exam_term ON exam_rel.term_id = exam_term.term_id
-- Get the Source/Section linked to the GROUP
LEFT JOIN {$rel_table} source_rel ON g.group_id = source_rel.object_id AND source_rel.object_type = 'group' AND source_rel.term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'))
{$where_clause}
GROUP BY q.question_id
ORDER BY {$orderby} {$order}
LIMIT {$per_page} OFFSET {$offset}";

        $this->items = $wpdb->get_results($data_query, ARRAY_A);

        // Fetch labels for the questions on the current page
        $question_ids_on_page = wp_list_pluck($this->items, 'question_id');
        if (!empty($question_ids_on_page)) {
            $labels_placeholder = implode(',', $question_ids_on_page);
            $labels_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");

            $labels_results = $wpdb->get_results($wpdb->prepare(
                "SELECT r.object_id as question_id, t.name as label_name, m.meta_value as label_color
         FROM {$rel_table} r
         JOIN {$term_table} t ON r.term_id = t.term_id
         LEFT JOIN {$wpdb->prefix}qp_term_meta m ON t.term_id = m.term_id AND m.meta_key = 'color'
         WHERE r.object_id IN ({$labels_placeholder}) AND r.object_type = 'question' AND t.taxonomy_id = %d",
                $labels_tax_id
            ));

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

    public function column_subject_name($item)
{
    $output = '';

    // Display the Subject
    if (!empty($item['subject_name'])) {
        $output .= '<strong>Subject:</strong> ' . esc_html($item['subject_name']);
    } else {
        $output .= '<strong>Subject:</strong> <em style="color:#a00;">None</em>';
    }

    $output .= '<br>'; // Add a line break

    // Display the Topic
    if (!empty($item['topic_name'])) {
        $output .= '<strong>Topic:</strong> ' . esc_html($item['topic_name']);
    } else {
        $output .= '<strong>Topic:</strong> <em style="color:#888;">None</em>';
    }

    return $output;
}

    /**
     * UPDATED: Process all bulk actions, now correctly handling nonces for single-item actions.
     */
    public function process_bulk_action()
{
    // --- NEW: Handle Custom Bulk Edit First ---
    if (isset($_REQUEST['bulk_edit_apply'])) {
        check_admin_referer('bulk-questions'); // This nonce is automatically added by WP
        $question_ids = isset($_REQUEST['question_ids']) ? array_map('absint', $_REQUEST['question_ids']) : [];

        if (!empty($question_ids)) {
            global $wpdb;
            $rel_table = $wpdb->prefix . 'qp_term_relationships';
            $term_table = $wpdb->prefix . 'qp_terms';
            $tax_table = $wpdb->prefix . 'qp_taxonomies';
            $q_table = $wpdb->prefix . 'qp_questions';
            $ids_placeholder = implode(',', $question_ids);

            // Handle Topic Change
            if (!empty($_REQUEST['bulk_edit_topic'])) {
                $topic_term_id = absint($_REQUEST['bulk_edit_topic']);
                $topic_tax_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM $term_table WHERE term_id = %d", $topic_term_id));
                // First, delete existing topic relationships for these questions
                $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id IN ({$ids_placeholder}) AND object_type = 'question' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $topic_tax_id));
                // Then, insert the new ones
                foreach ($question_ids as $qid) {
                    $wpdb->insert($rel_table, ['object_id' => $qid, 'term_id' => $topic_term_id, 'object_type' => 'question']);
                }
            }

            // Handle Source/Section Change
            $source_term_id = !empty($_REQUEST['bulk_edit_source']) ? absint($_REQUEST['bulk_edit_source']) : null;
            $section_term_id = !empty($_REQUEST['bulk_edit_section']) ? absint($_REQUEST['bulk_edit_section']) : null;
            $term_to_apply = $section_term_id ?: $source_term_id; // Prefer the more specific section

            if ($term_to_apply) {
                $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
                 // First, delete existing source/section relationships for these questions
                $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id IN ({$ids_placeholder}) AND object_type = 'question' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $source_tax_id));
                // Then, insert the new one
                foreach ($question_ids as $qid) {
                    $wpdb->insert($rel_table, ['object_id' => $qid, 'term_id' => $term_to_apply, 'object_type' => 'question']);
                }
            }

            // Handle Exam Change
            if (!empty($_REQUEST['bulk_edit_exam'])) {
                $exam_term_id = absint($_REQUEST['bulk_edit_exam']);
                $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");
                $group_ids = $wpdb->get_col("SELECT DISTINCT group_id FROM {$q_table} WHERE question_id IN ({$ids_placeholder})");

                if (!empty($group_ids)) {
                    $group_ids_placeholder = implode(',', $group_ids);
                     // First, delete existing exam relationships for these groups
                    $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id IN ({$group_ids_placeholder}) AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $exam_tax_id));
                    // Then, insert the new ones
                    foreach ($group_ids as $gid) {
                        $wpdb->insert($rel_table, ['object_id' => $gid, 'term_id' => $exam_term_id, 'object_type' => 'group']);
                    }
                }
            }
        }
    }

    $action = $this->current_action();
    $labels_to_apply = isset($_REQUEST['labels_to_apply']) ? array_filter(array_map('absint', (array) $_REQUEST['labels_to_apply'])) : [];
    $question_ids = [];

    // --- NEW: Differentiate between single-item actions and bulk actions ---
    if ($action && isset($_GET['question_id'])) {
        // This is a single-item action (e.g., clicking a "Delete" link)
        $question_ids = [absint($_GET['question_id'])];
        // Check the unique nonce for this specific action
        if ($action === 'trash')   check_admin_referer('qp_trash_question_' . $question_ids[0]);
        if ($action === 'untrash') check_admin_referer('qp_untrash_question_' . $question_ids[0]);
        if ($action === 'delete')  check_admin_referer('qp_delete_question_' . $question_ids[0]);
    } else {
        // This is a bulk action (e.g., using the dropdown and Apply button)
        if ((!$action || $action === -1) && empty($labels_to_apply)) {
            return;
        }
        check_admin_referer('bulk-' . $this->_args['plural']);
        $question_ids = isset($_REQUEST['question_ids']) ? array_map('absint', $_REQUEST['question_ids']) : [];
    }

    if (empty($question_ids)) {
        return;
    }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $ids_placeholder = implode(',', $question_ids);

    // --- Handle applying labels ---
    if (!empty($labels_to_apply)) {
        foreach ($question_ids as $question_id) {
            foreach ($labels_to_apply as $label_term_id) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$rel_table} (object_id, term_id, object_type) VALUES (%d, %d, 'question')",
                    $question_id,
                    $label_term_id
                ));
            }
        }
    }

    // Handle standard bulk actions
    if ('trash' === $action) {
        $wpdb->query("UPDATE {$q_table} SET status = 'trash' WHERE question_id IN ({$ids_placeholder})");
    }
    if ('untrash' === $action) {
        $wpdb->query("UPDATE {$q_table} SET status = 'publish' WHERE question_id IN ({$ids_placeholder})");
    }
    if ('delete' === $action) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}qp_options WHERE question_id IN ({$ids_placeholder})");
        $wpdb->query("DELETE FROM {$rel_table} WHERE object_id IN ({$ids_placeholder}) AND object_type = 'question'");
        $wpdb->query("DELETE FROM {$q_table} WHERE question_id IN ({$ids_placeholder})");
    }

    if (strpos($action, 'remove_label_') === 0) {
    // UPDATED: Target the new relationships table
    $label_term_id_to_remove = absint(str_replace('remove_label_', '', $action));
    if ($label_term_id_to_remove > 0) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$rel_table} WHERE object_id IN ({$ids_placeholder}) AND object_type = 'question' AND term_id = %d",
                $label_term_id_to_remove
            )
        );
    }

    wp_safe_redirect(remove_query_arg(['action', 'action2', '_wpnonce', 'question_ids', 'labels_to_apply', 'bulk_edit_apply', 'bulk_edit_source', 'bulk_edit_section'], wp_get_referer()));
    exit;
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

    public function display_view_modal()
    {
    ?>
        <div id="qp-view-modal-backdrop" style="display: none;">
            <div id="qp-view-modal-content">
                <button class="qp-modal-close-btn">&times;</button>
                <div id="qp-view-modal-body"></div>
            </div>
        </div>
<?php
    }

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="question_ids[]" value="%s" />', $item['question_id']);
    }

    public function column_question_text($item)
    {
        $page = esc_attr($_REQUEST['page']);
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'all';
        $group_id = isset($item['group_id']) ? $item['group_id'] : 0; // Ensure group_id is available

        $output = '';

        // Display Direction if it exists
if (!empty($item['direction_text'])) {
    // Use wp_kses_post and nl2br to render HTML and respect line breaks
    $direction_display = '<strong>Direction:</strong> ' . wp_kses_post(nl2br($item['direction_text']));

    // Add an image indicator
    if (!empty($item['direction_image_id'])) {
        $direction_display .= ' <span style="background-color: #f0f0f1; color: #50575e; padding: 1px 5px; font-size: 10px; border-radius: 3px; font-weight: 600;">IMAGE</span>';
    }
    $output .= '<div style="padding-bottom: 8px;">' . $direction_display . '</div>';
}

// Remove any "Q#:" prefix before displaying the text
$clean_question_text = preg_replace('/^q\d+:\s*/i', '', $item['question_text']);
// Use wp_kses_post and nl2br here as well
$output .= '<strong>' . wp_kses_post(nl2br($clean_question_text)) . '</strong>';

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
    '<a href="#" class="editinline" data-question-id="%d" data-nonce="%s" data-status="%s">Quick Edit</a>',
    $item['question_id'],
    $quick_edit_nonce,
    esc_attr($item['status'])
),
                'view' => sprintf(
                    '<a href="#" class="view-question" data-question-id="%d">View</a>',
                    $item['question_id']
                ),
                'trash' => sprintf('<a href="?page=%s&action=trash&question_id=%s&_wpnonce=%s" style="color:#a00;">Trash</a>', $page, $item['question_id'], $trash_nonce),
            ];
        }

        $row_text = '';

        // NEW: Display Direction and Image Indicator
if (!empty($item['direction_text'])) {
    // Use wp_kses_post to allow HTML and nl2br for line breaks
    $direction_display = '<strong>Direction:</strong> ' . wp_kses_post(nl2br($item['direction_text']));
    if (!empty($item['direction_image_id'])) {
        $direction_display .= ' <span class="dashicons dashicons-format-image" title="Includes Image" style="color:#888; font-size: 16px; vertical-align: middle;"></span>';
    }
    $row_text .= '<div style="padding: 0px; background-color: #f6f7f7; margin-bottom: 8px; border-radius: 3px;">' . $direction_display . '</div>';
}

// Use wp_kses_post for the question text as well
$row_text .= '<strong>' . wp_kses_post(nl2br($item['question_text'])) . '</strong>';

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
        if (empty($item['is_pyq'])) {
            return 'No';
        }

        $output = '<strong>Exam:</strong> ';
        if (!empty($item['exam_name'])) {
            $output .= esc_html($item['exam_name']);
        } else {
            $output .= '<em>N/A</em>';
        }

        $output .= '<br>';

        $output .= '<strong>Year:</strong> ';
        if (!empty($item['pyq_year'])) {
            $output .= esc_html($item['pyq_year']);
        } else {
            $output .= '<em>N/A</em>';
        }

        return $output;
    }


    public function column_source($item)
{
    if (empty($item['linked_source_term_id'])) {
        return '<em>None</em>'; // Return if no source is linked
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';

    $term_id = absint($item['linked_source_term_id']);
    $lineage_names = [];

    // Trace up the tree to the root, with a safety limit of 10 levels
    for ($i = 0; $i < 10; $i++) {
        if (!$term_id || $term_id == 0) break; // Stop if we reach the top

        $term = $wpdb->get_row($wpdb->prepare("SELECT name, parent FROM {$term_table} WHERE term_id = %d", $term_id));

        if ($term) {
            array_unshift($lineage_names, $term->name); // Add to the beginning of the array to maintain order
            $term_id = $term->parent;
        } else {
            break; // Stop if a term is not found
        }
    }

    $output_parts = [];

    if (!empty($lineage_names)) {
        // The first item is always the main source
        $output_parts[] = '<strong>Source:</strong> ' . esc_html(array_shift($lineage_names));

        // If there are remaining items, they constitute the section hierarchy
        if (!empty($lineage_names)) {
            $output_parts[] = '<strong>Section:</strong> ' . esc_html(implode(' / ', $lineage_names));
        }
    }

    // Add the question number if it exists
    if (!empty($item['question_number_in_section'])) {
        $output_parts[] = '<strong>Q. No:</strong> ' . esc_html($item['question_number_in_section']);
    }

    return implode('<br>', $output_parts);
}

    public function column_default($item, $column_name)
    {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : 'N/A';
    }
}
