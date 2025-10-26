<?php

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

use QuestionPress\Database\Questions_DB;
use QuestionPress\Database\Terms_DB;

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
            'question_id' => 'ID',
            'question_text'      => 'Question',
            'subject_name'       => 'Subject',
            'source'             => 'Source',
            'is_pyq'             => 'PYQ',
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'question_id' => ['question_id', true],
            'subject_name'       => ['subject_name', false],
        ];
    }




    protected function get_hidden_columns()
    {
        // Hide some columns by default
        return [];
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
            // MODIFICATION START: The inline style "display: none;" is removed and the dropdown is now populated.
            echo '<select name="filter_by_source" id="qp_filter_by_source_section" style="margin-right: 5px;">';
            echo '<option value="">All Sources / Sections</option>';

            $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");
            if ($source_tax_id) {
                $all_source_terms = $wpdb->get_results($wpdb->prepare("SELECT term_id, name, parent FROM $term_table WHERE taxonomy_id = %d ORDER BY name ASC", $source_tax_id));

                // Helper function to build the hierarchical dropdown
                function qp_render_source_options($terms, $parent_id = 0, $level = 0, $current_selection = '')
                {
                    $prefix = str_repeat('&nbsp;&nbsp;', $level);
                    foreach ($terms as $term) {
                        if ($term->parent == $parent_id) {
                            $value = ($term->parent == 0 ? 'source_' : 'section_') . $term->term_id;
                            printf(
                                '<option value="%s" %s>%s%s</option>',
                                esc_attr($value),
                                selected($current_selection, $value, false),
                                $prefix,
                                esc_html($term->name)
                            );
                            qp_render_source_options($terms, $term->term_id, $level + 1, $current_selection);
                        }
                    }
                }
                qp_render_source_options($all_source_terms, 0, 0, $current_source_or_section);
            }

            echo '</select>';
            // MODIFICATION END

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
            <input type="search" id="<?php echo $input_id ?>" name="s" value="<?php echo $search_query; ?>" placeholder="By DB ID or text" />
            <?php submit_button($search_button_text, 'button', 'search_submit', false, array('id' => 'search-submit')); ?>
        </p>
    <?php
    }

    public function prepare_items()
    {

        // Process bulk actions first (this remains)
        $this->process_bulk_action();

        // Setup columns (this remains)
        $columns = $this->get_columns();
        $hidden = $this->get_hidden_columns(); // Use the getter method
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable, 'question_id']; // Primary column

        // Prepare arguments for the DB call based on request parameters
        $args = [
            'status'        => isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish',
            'subject_id'    => !empty($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : 0,
            'topic_id'      => !empty($_REQUEST['filter_by_topic']) ? absint($_REQUEST['filter_by_topic']) : 0,
            'source_filter' => isset($_REQUEST['filter_by_source']) ? sanitize_text_field($_REQUEST['filter_by_source']) : '',
            'label_ids'     => isset($_REQUEST['filter_by_label']) ? array_filter(array_map('absint', (array)$_REQUEST['filter_by_label'])) : [],
            'search'        => isset($_REQUEST['s']) ? sanitize_text_field(stripslashes($_REQUEST['s'])) : '',
            'orderby'       => isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'question_id',
            'order'         => isset($_REQUEST['order']) ? sanitize_key($_REQUEST['order']) : 'desc',
            'per_page'      => $this->get_items_per_page('qp_questions_per_page', 20),
            'current_page'  => $this->get_pagenum(),
        ];

        // Call the new static method from Questions_DB
        $result = Questions_DB::get_questions_for_list_table($args);

        // Assign items and total count
        $this->items = $result['items'];
        $total_items = $result['total_items'];

        // Set pagination arguments (this remains)
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $args['per_page'],
        ]);
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
                    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

                    // 1. Get the unique group IDs for the selected questions.
                    $group_ids = $wpdb->get_col("SELECT DISTINCT group_id FROM {$q_table} WHERE question_id IN ({$ids_placeholder})");

                    if (!empty($group_ids)) {
                        $group_ids_placeholder = implode(',', $group_ids);

                        // 2. Delete existing topic relationships for these groups.
                        // A topic is a term in the 'subject' taxonomy with a parent.
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$rel_table} 
                         WHERE object_id IN ({$group_ids_placeholder}) 
                         AND object_type = 'group' 
                         AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0)",
                            $subject_tax_id
                        ));

                        // 3. Insert the new relationship for each group.
                        foreach ($group_ids as $gid) {
                            $wpdb->insert($rel_table, ['object_id' => $gid, 'term_id' => $topic_term_id, 'object_type' => 'group']);
                        }
                    }
                }

                // Handle Source/Section Change
                $source_term_id = !empty($_REQUEST['bulk_edit_source']) ? absint($_REQUEST['bulk_edit_source']) : null;
                $section_term_id = !empty($_REQUEST['bulk_edit_section']) ? absint($_REQUEST['bulk_edit_section']) : null;
                $term_to_apply = $section_term_id ?: $source_term_id; // Prefer the more specific section

                if ($term_to_apply) {
                    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");

                    // 1. Get the unique group IDs for the selected questions.
                    $group_ids = $wpdb->get_col("SELECT DISTINCT group_id FROM {$q_table} WHERE question_id IN ({$ids_placeholder})");

                    if (!empty($group_ids)) {
                        $group_ids_placeholder = implode(',', $group_ids);

                        // 2. Delete existing source/section relationships for these groups.
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$rel_table} 
                         WHERE object_id IN ({$group_ids_placeholder}) 
                         AND object_type = 'group' 
                         AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)",
                            $source_tax_id
                        ));

                        // 3. Insert the new relationship for each group.
                        foreach ($group_ids as $gid) {
                            $wpdb->insert($rel_table, ['object_id' => $gid, 'term_id' => $term_to_apply, 'object_type' => 'group']);
                        }
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
            check_admin_referer('bulk-' . $this->_args['plural']); // Nonce check for bulk action

            global $wpdb;
            $q_table = Questions_DB::get_questions_table_name(); // Use static method
            $g_table = Questions_DB::get_groups_table_name(); // Use static method
            $rel_table = Terms_DB::get_relationships_table_name(); // Use static method

            // --- ADDED: Get associated group IDs BEFORE deleting questions ---
            $group_ids_to_check = $wpdb->get_col("SELECT DISTINCT group_id FROM {$q_table} WHERE question_id IN ({$ids_placeholder}) AND group_id IS NOT NULL AND group_id > 0");
            // --- END ADDED ---

            // Delete the questions and their related data
            Questions_DB::delete_questions($question_ids);

            // --- ADDED: Check and delete orphaned groups ---
            if (!empty($group_ids_to_check)) {
                $unique_group_ids = array_unique($group_ids_to_check);
                foreach ($unique_group_ids as $gid) {
                    // Check if any *other* questions still exist for this group
                    $remaining_questions = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$q_table} WHERE group_id = %d",
                        $gid
                    ));

                    // If no questions remain, delete the group and its relationships
                    if ($remaining_questions == 0) {
                        // Delete group relationships
                        $wpdb->delete($rel_table, ['object_id' => $gid, 'object_type' => 'group'], ['%d', '%s']);
                        // Delete the group itself
                        $wpdb->delete($g_table, ['group_id' => $gid], ['%d']);
                        // Optional: Log deletion of orphaned group $gid
                    }
                }
            }
            // --- END ADDED ---

            // Optional: Add admin notice
            // add_settings_error('qp_notices', 'bulk_delete', count($question_ids) . ' questions permanently deleted.', 'success');

            // Redirect after action to prevent resubmission and clear query args
            wp_safe_redirect(remove_query_arg(['action', 'action2', '_wpnonce', 'question_ids', 'labels_to_apply', 'bulk_edit_apply', 'bulk_edit_source', 'bulk_edit_section', 'bulk_edit_topic', 'bulk_edit_exam'], wp_get_referer() ?: admin_url('admin.php?page=question-press&status=trash'))); // Redirect back, usually to trash view
            exit;
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
        $page = isset($_REQUEST['page']) ? esc_attr($_REQUEST['page']) : 'question-press'; // Default to a safe value
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
                    $original_question_id = $item['duplicate_of'];
                    if ($original_question_id) {
                        $label_text .= sprintf(' (of #%s)', esc_html($original_question_id));
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
                    $original_question_id = $item['duplicate_of'];
                    if ($original_question_id) {
                        $label_text .= sprintf(' (of #%s)', esc_html($original_question_id));
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
