<?php
if (!defined('ABSPATH')) exit;

class QP_Topics_Page {

    public static function render() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'qp_topics';
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $message = '';
        $message_type = ''; // 'updated' or 'error'
        $topic_to_edit = null;

        // --- Handle Form Submissions ---
        // (We will add the logic for adding, editing, and deleting topics here in a moment)


        // --- Check if we are in EDIT mode ---
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['topic_id'])) {
            $topic_id = absint($_GET['topic_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_topic_' . $topic_id)) {
                $topic_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $topics_table WHERE topic_id = %d", $topic_id));
            }
        }

        // Get all subjects for the dropdown
        $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");

        // Get all topics with their subject names for the list table
        $topics = $wpdb->get_results("
            SELECT t.topic_id, t.topic_name, s.subject_name
            FROM $topics_table t
            JOIN $subjects_table s ON t.subject_id = s.subject_id
            ORDER BY s.subject_name, t.topic_name ASC
        ");
        ?>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $topic_to_edit ? 'Edit Topic' : 'Add New Topic'; ?></h2>
                            
                            <form method="post" action="admin.php?page=qp-topics">
                                <?php if ($topic_to_edit) : ?>
                                    <?php wp_nonce_field('qp_update_topic_nonce'); ?>
                                    <input type="hidden" name="topic_id" value="<?php echo esc_attr($topic_to_edit->topic_id); ?>">
                                <?php else : ?>
                                    <?php wp_nonce_field('qp_add_topic_nonce'); ?>
                                <?php endif; ?>
                                
                                <div class="form-field form-required">
                                    <label for="topic-name">Topic Name</label>
                                    <input name="topic_name" id="topic-name" type="text" value="<?php echo $topic_to_edit ? esc_attr($topic_to_edit->topic_name) : ''; ?>" size="40" aria-required="true" required>
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
                                    <?php if ($topic_to_edit) : ?>
                                        <input type="submit" name="update_topic" id="submit" class="button button-primary" value="Update Topic">
                                        <a href="admin.php?page=qp-topics" class="button button-secondary">Cancel Edit</a>
                                    <?php else : ?>
                                        <input type="submit" name="add_topic" id="submit" class="button button-primary" value="Add New Topic">
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
                                    <th scope="col" class="manage-column">Topic Name</th>
                                    <th scope="col" class="manage-column">Parent Subject</th>
                                    <th scope="col" class="manage-column">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="the-list">
                                <?php if (!empty($topics)) : ?>
                                    <?php foreach ($topics as $topic) : ?>
                                        <tr>
                                            <td><?php echo esc_html($topic->topic_name); ?></td>
                                            <td><?php echo esc_html($topic->subject_name); ?></td>
                                            <td>
                                                <?php
                                                    $edit_nonce = wp_create_nonce('qp_edit_topic_' . $topic->topic_id);
                                                    $edit_link = sprintf('<a href="?page=%s&action=edit&topic_id=%s&_wpnonce=%s">Edit</a>', esc_attr($_REQUEST['page']), absint($topic->topic_id), $edit_nonce);

                                                    $delete_nonce = wp_create_nonce('qp_delete_topic_' . $topic->topic_id);
                                                    $delete_link = sprintf(
                                                        '<a href="?page=%s&action=delete&topic_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this topic?\');">Delete</a>',
                                                        esc_attr($_REQUEST['page']),
                                                        absint($topic->topic_id),
                                                        $delete_nonce
                                                    );
                                                    echo $edit_link . ' | ' . $delete_link;
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr class="no-items">
                                        <td class="colspanchange" colspan="3">No topics found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}