<?php
if (!defined('ABSPATH')) exit;

class QP_Topics_Page {

    public static function handle_forms() {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'topics') {
            return;
        }
        global $wpdb;
        $topics_table = $wpdb->prefix . 'qp_topics';

        if(isset($_POST['action']) && ($_POST['action'] === 'add_topic' || $_POST['action'] === 'update_topic') && check_admin_referer('qp_add_edit_topic_nonce')) {
            $topic_name = sanitize_text_field($_POST['topic_name']);
            $subject_id = absint($_POST['subject_id']);

            if (!empty($topic_name) && $subject_id > 0) {
                 $data = ['topic_name' => $topic_name, 'subject_id' => $subject_id];
                if ($_POST['action'] === 'update_topic') {
                    $topic_id = absint($_POST['topic_id']);
                    $wpdb->update($topics_table, $data, ['topic_id' => $topic_id]);
                    QP_Sources_Page::set_message('Topic updated successfully.', 'updated');
                } else {
                    $wpdb->insert($topics_table, $data);
                    QP_Sources_Page::set_message('Topic added successfully.', 'updated');
                }
            } else {
                 QP_Sources_Page::set_message('Topic Name and Parent Subject are required.', 'error');
            }
            QP_Sources_Page::redirect_to_tab('topics');
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['topic_id']) && check_admin_referer('qp_delete_topic_' . absint($_GET['topic_id']))) {
            $topic_id = absint($_GET['topic_id']);
            $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_questions WHERE topic_id = %d", $topic_id));
            if ($usage_count > 0) {
                QP_Sources_Page::set_message("This topic cannot be deleted because it is in use by {$usage_count} question(s).", 'error');
            } else {
                $wpdb->delete($topics_table, ['topic_id' => $topic_id]);
                QP_Sources_Page::set_message('Topic deleted successfully.', 'updated');
            }
            QP_Sources_Page::redirect_to_tab('topics');
        }
    }

    public static function render() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'qp_topics';
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        
        $topic_to_edit = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['topic_id'])) {
            $topic_id = absint($_GET['topic_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_topic_' . $topic_id)) {
                $topic_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $topics_table WHERE topic_id = %d", $topic_id));
            }
        }

        $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
        $topics = $wpdb->get_results("
            SELECT t.topic_id, t.topic_name, s.subject_name
            FROM $topics_table t JOIN $subjects_table s ON t.subject_id = s.subject_id
            ORDER BY s.subject_name, t.topic_name ASC
        ");

        if (isset($_SESSION['qp_admin_message'])) {
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . esc_html($_SESSION['qp_admin_message']) . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap">
                        <h2><?php echo $topic_to_edit ? 'Edit Topic' : 'Add New Topic'; ?></h2>
                        <form method="post" action="admin.php?page=qp-organization&tab=topics">
                            <?php wp_nonce_field('qp_add_edit_topic_nonce'); ?>
                            <input type="hidden" name="action" value="<?php echo $topic_to_edit ? 'update_topic' : 'add_topic'; ?>">
                            <?php if ($topic_to_edit) : ?>
                                <input type="hidden" name="topic_id" value="<?php echo esc_attr($topic_to_edit->topic_id); ?>">
                            <?php endif; ?>
                            
                            <div class="form-field form-required">
                                <label for="topic-name">Topic Name</label>
                                <input name="topic_name" id="topic-name" type="text" value="<?php echo $topic_to_edit ? esc_attr($topic_to_edit->topic_name) : ''; ?>" size="40" required>
                            </div>
                            <div class="form-field form-required">
                                <label for="subject-id">Parent Subject</label>
                                <select name="subject_id" id="subject-id" required>
                                    <option value="">Select a Subject</option>
                                    <?php foreach($subjects as $subject): ?>
                                        <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($topic_to_edit ? $topic_to_edit->subject_id : '', $subject->subject_id); ?>>
                                            <?php echo esc_html($subject->subject_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <p class="submit">
                                <input type="submit" class="button button-primary" value="<?php echo $topic_to_edit ? 'Update Topic' : 'Add New Topic'; ?>">
                                <?php if ($topic_to_edit) : ?>
                                    <a href="admin.php?page=qp-organization&tab=topics" class="button button-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col">Topic Name</th>
                                <th scope="col">Parent Subject</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($topics)) : foreach ($topics as $topic) : ?>
                                <tr>
                                    <td><?php echo esc_html($topic->topic_name); ?></td>
                                    <td><?php echo esc_html($topic->subject_name); ?></td>
                                    <td>
                                        <?php
                                            $edit_nonce = wp_create_nonce('qp_edit_topic_' . $topic->topic_id);
                                            $delete_nonce = wp_create_nonce('qp_delete_topic_' . $topic->topic_id);
                                            $edit_link = sprintf('<a href="?page=qp-organization&tab=topics&action=edit&topic_id=%s&_wpnonce=%s">Edit</a>', $topic->topic_id, $edit_nonce);
                                            $delete_link = sprintf('<a href="?page=qp-organization&tab=topics&action=delete&topic_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $topic->topic_id, $delete_nonce);
                                            echo $edit_link . ' | ' . $delete_link;
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr class="no-items"><td colspan="3">No topics found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}