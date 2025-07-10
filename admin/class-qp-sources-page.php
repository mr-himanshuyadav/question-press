<?php
if (!defined('ABSPATH')) exit;

class QP_Sources_Page {

    /**
     * Handles all form submissions for the Sources tab.
     */
    public static function handle_forms() {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'sources') {
            return;
        }
        global $wpdb;

        // --- Add/Update Source ---
        if (isset($_POST['action']) && ($_POST['action'] === 'add_source' || $_POST['action'] === 'update_source') && check_admin_referer('qp_add_edit_source_nonce')) {
            $source_name = sanitize_text_field($_POST['source_name']);
            $description = sanitize_textarea_field($_POST['source_description']);
            $subject_id = absint($_POST['subject_id']); // <-- CHANGED from linked_subjects

            if (empty($source_name) || empty($subject_id)) {
                self::set_message('Source Name and Parent Subject are required.', 'error');
            } else {
                $data = ['source_name' => $source_name, 'description' => $description, 'subject_id' => $subject_id];
                if ($_POST['action'] === 'update_source') {
                    $source_id = absint($_POST['source_id']);
                    $wpdb->update($wpdb->prefix . 'qp_sources', $data, ['source_id' => $source_id]);
                    self::set_message('Source updated successfully.', 'updated');
                } else {
                    $wpdb->insert($wpdb->prefix . 'qp_sources', $data);
                    self::set_message('Source added successfully.', 'updated');
                }
            }
            self::redirect_to_tab('sources');
        }

        // --- Add/Update Section (Logic remains the same) ---
        if (isset($_POST['action']) && ($_POST['action'] === 'add_section' || $_POST['action'] === 'update_section') && check_admin_referer('qp_add_edit_section_nonce')) {
            $section_name = sanitize_text_field($_POST['section_name']);
            $source_id = absint($_POST['source_id']);
            if (empty($section_name) || empty($source_id)) {
                self::set_message('Section name and parent source are required.', 'error');
            } else {
                 $data = ['section_name' => $section_name, 'source_id' => $source_id];
                if ($_POST['action'] === 'update_section') {
                    $section_id = absint($_POST['section_id']);
                    $wpdb->update($wpdb->prefix . 'qp_source_sections', $data, ['section_id' => $section_id]);
                    self::set_message('Section updated successfully.', 'updated');
                } else {
                    $wpdb->insert($wpdb->prefix . 'qp_source_sections', $data);
                    self::set_message('Section added successfully.', 'updated');
                }
            }
            self::redirect_to_tab('sources');
        }
        
        // --- Deletion logic remains the same ---
        if (isset($_GET['action']) && $_GET['action'] === 'delete_source' && isset($_GET['source_id']) && check_admin_referer('qp_delete_source_' . absint($_GET['source_id']))) {
            $source_id = absint($_GET['source_id']);
            $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_questions WHERE source_id = %d", $source_id));
            if ($usage_count > 0) {
                self::set_message("Source cannot be deleted because it is in use by {$usage_count} question(s).", 'error');
            } else {
                $wpdb->delete($wpdb->prefix . 'qp_sources', ['source_id' => $source_id]);
                $wpdb->delete($wpdb->prefix . 'qp_source_sections', ['source_id' => $source_id]);
                self::set_message('Source and its sections deleted successfully.', 'updated');
            }
            self::redirect_to_tab('sources');
        }
        if (isset($_GET['action']) && $_GET['action'] === 'delete_section' && isset($_GET['section_id']) && check_admin_referer('qp_delete_section_' . absint($_GET['section_id']))) {
            $section_id = absint($_GET['section_id']);
            $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_questions WHERE section_id = %d", $section_id));
            if ($usage_count > 0) {
                 self::set_message("Section cannot be deleted because it is in use by {$usage_count} question(s).", 'error');
            } else {
                $wpdb->delete($wpdb->prefix . 'qp_source_sections', ['section_id' => $section_id]);
                self::set_message('Section deleted successfully.', 'updated');
            }
            self::redirect_to_tab('sources');
        }
    }

    /**
     * Renders the HTML for the Sources tab with collapsible sections.
     */
    public static function render() {
        global $wpdb;
        $sources_table = $wpdb->prefix . 'qp_sources';
        $sections_table = $wpdb->prefix . 'qp_source_sections';
        $subjects_table = $wpdb->prefix . 'qp_subjects';

        // --- Get data needed for forms and tables ---
        $source_to_edit = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit_source' && isset($_GET['source_id'])) {
            $source_id = absint($_GET['source_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_source_' . $source_id)) {
                $source_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sources_table WHERE source_id = %d", $source_id));
            }
        }
        $section_to_edit = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit_section' && isset($_GET['section_id'])) {
            $section_id = absint($_GET['section_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_section_' . $section_id)) {
                $section_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sections_table WHERE section_id = %d", $section_id));
            }
        }
        
        $all_subjects = $wpdb->get_results("SELECT subject_id, subject_name FROM $subjects_table ORDER BY subject_name ASC");
        $sources = $wpdb->get_results("SELECT s.*, sub.subject_name FROM $sources_table s LEFT JOIN $subjects_table sub ON s.subject_id = sub.subject_id ORDER BY s.source_name ASC");
        $sections = $wpdb->get_results("SELECT * FROM $sections_table ORDER BY section_name ASC");
        
        // Group sections by their parent source ID for easy lookup
        $sections_by_source = [];
        foreach ($sections as $section) {
            $sections_by_source[$section->source_id][] = $section;
        }

        if (isset($_SESSION['qp_admin_message'])) {
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . esc_html($_SESSION['qp_admin_message']) . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="form-wrap">
                    <h2><?php echo $source_to_edit ? 'Edit Source' : 'Add New Source'; ?></h2>
                    <form method="post" action="admin.php?page=qp-organization&tab=sources">
                        <?php wp_nonce_field('qp_add_edit_source_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo $source_to_edit ? 'update_source' : 'add_source'; ?>">
                        <?php if ($source_to_edit): ?><input type="hidden" name="source_id" value="<?php echo esc_attr($source_to_edit->source_id); ?>"><?php endif; ?>

                        <div class="form-field form-required">
                            <label for="source-name">Source Name</label>
                            <input name="source_name" id="source-name" type="text" value="<?php echo $source_to_edit ? esc_attr($source_to_edit->source_name) : ''; ?>" size="40" required>
                        </div>
                        <div class="form-field form-required">
                            <label for="parent-subject-id">Parent Subject</label>
                            <select name="subject_id" id="parent-subject-id" required>
                                <option value="">— Select a Subject —</option>
                                <?php foreach($all_subjects as $subject): ?>
                                    <?php if(strtolower($subject->subject_name) === 'uncategorized') continue; ?>
                                    <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($source_to_edit ? $source_to_edit->subject_id : '', $subject->subject_id); ?>>
                                        <?php echo esc_html($subject->subject_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="source-description">Description</label>
                            <textarea name="source_description" id="source-description" rows="3"><?php echo $source_to_edit ? esc_textarea($source_to_edit->description) : ''; ?></textarea>
                        </div>
                        <p class="submit">
                            <input type="submit" class="button button-primary" value="<?php echo $source_to_edit ? 'Update Source' : 'Add New Source'; ?>">
                            <?php if ($source_to_edit): ?><a href="admin.php?page=qp-organization&tab=sources" class="button button-secondary">Cancel Edit</a><?php endif; ?>
                        </p>
                    </form>
                </div>
                <hr>
                <div class="form-wrap">
                    <h2><?php echo $section_to_edit ? 'Edit Section' : 'Add New Section'; ?></h2>
                    <form method="post" action="admin.php?page=qp-organization&tab=sources">
                         <?php wp_nonce_field('qp_add_edit_section_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo $section_to_edit ? 'update_section' : 'add_section'; ?>">
                        <?php if ($section_to_edit): ?><input type="hidden" name="section_id" value="<?php echo esc_attr($section_to_edit->section_id); ?>"><?php endif; ?>

                        <div class="form-field form-required">
                            <label for="parent-source-id">Parent Source</label>
                            <select name="source_id" id="parent-source-id" required>
                                <option value="">— Select a Source —</option>
                                <?php foreach($sources as $source): ?>
                                    <option value="<?php echo esc_attr($source->source_id); ?>" <?php selected($section_to_edit ? $section_to_edit->source_id : '', $source->source_id); ?>>
                                        <?php echo esc_html($source->source_name); ?> (<?php echo esc_html($source->subject_name); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field form-required">
                            <label for="section-name">Section Name</label>
                            <input name="section_name" id="section-name" type="text" value="<?php echo $section_to_edit ? esc_attr($section_to_edit->section_name) : ''; ?>" size="40" required>
                        </div>
                        <p class="submit">
                            <input type="submit" class="button button-primary" value="<?php echo $section_to_edit ? 'Update Section' : 'Add New Section'; ?>">
                             <?php if ($section_to_edit): ?><a href="admin.php?page=qp-organization&tab=sources" class="button button-secondary">Cancel Edit</a><?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <h3>Sources and Sections</h3>
                    <table class="wp-list-table widefat fixed striped" id="sources-sections-table">
                        <thead><tr><th>Name</th><th>Description / Parent</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php if (!empty($sources)) : foreach ($sources as $source) : 
                                $has_sections = !empty($sections_by_source[$source->source_id]);
                            ?>
                                <tr class="source-row">
                                    <td class="has-toggle">
                                        <?php if ($has_sections): ?>
                                            <button type="button" class="toggle-sections button-link" data-source-id="<?php echo esc_attr($source->source_id); ?>">
                                                <span class="dashicons dashicons-plus-alt2"></span>
                                            </button>
                                        <?php endif; ?>
                                        <strong style="font-size: 1.1em;"><?php echo esc_html($source->source_name); ?></strong>
<small>(<?php echo esc_html($source->subject_name ?? 'No Subject'); ?>)</small>
                                    </td>
                                    <td><?php echo esc_html($source->description); ?></td>
                                    <td>
                                        <?php
                                            printf('<a href="?page=qp-organization&tab=sources&action=edit_source&source_id=%s&_wpnonce=%s">Edit</a> | ', $source->source_id, wp_create_nonce('qp_edit_source_' . $source->source_id));
                                            printf('<a href="?page=qp-organization&tab=sources&action=delete_source&source_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $source->source_id, wp_create_nonce('qp_delete_source_' . $source->source_id));
                                        ?>
                                    </td>
                                </tr>
                                <?php if ($has_sections) : foreach ($sections_by_source[$source->source_id] as $section) : ?>
                                    <tr class="section-row hidden" data-parent-source="<?php echo esc_attr($source->source_id); ?>">
                                        <td style="padding-left: 30px;">&mdash; <?php echo esc_html($section->section_name); ?></td>
                                        <td><em>Source: <?php echo esc_html($source->source_name); ?></em></td>
                                        <td>
                                            <?php
                                                printf('<a href="?page=qp-organization&tab=sources&action=edit_section&section_id=%s&_wpnonce=%s">Edit</a> | ', $section->section_id, wp_create_nonce('qp_edit_section_' . $section->section_id));
                                                printf('<a href="?page=qp-organization&tab=sources&action=delete_section&section_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $section->section_id, wp_create_nonce('qp_delete_section_' . $section->section_id));
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            <?php endforeach; else : ?>
                                <tr class="no-items"><td colspan="3">No sources found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <style>
            .toggle-sections { text-decoration: none; margin-right: 5px; }
            .toggle-sections .dashicons { transition: transform 0.2s ease-in-out; }
            .toggle-sections.open .dashicons { transform: rotate(45deg); }
            .section-row.hidden { display: none; }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('#sources-sections-table').on('click', '.toggle-sections', function() {
                var button = $(this);
                var sourceId = button.data('source-id');
                button.toggleClass('open');
                $('tr.section-row[data-parent-source="' + sourceId + '"]').toggleClass('hidden');
            });
        });
        </script>
        <?php
    }

    public static function set_message($message, $type)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['qp_admin_message'] = $message;
            $_SESSION['qp_admin_message_type'] = $type;
        }
    }

    public static function redirect_to_tab($tab)
    {
        wp_safe_redirect(admin_url('admin.php?page=qp-organization&tab=' . $tab));
        exit;
    }
}