<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Import_Page
{
    public static function render()
    {
        if (isset($_POST['submit']) && isset($_FILES['question_zip_file'])) {
            $importer = new QP_Importer();
            $importer->handle_import();
        } else {
            self::render_upload_form();
        }
    }

    private static function render_upload_form() {
        global $wpdb;
        $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Import Questions</h1>
            <hr class="wp-header-end">

            <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 1rem;">
                <div style="flex: 2; min-width: 300px;">
                    <h3>Upload Question Package</h3>
                    <p>Upload a <code>.zip</code> file containing a <code>questions.json</code> file to import questions into the database.</p>

                    <form method="post" action="admin.php?page=qp-import" enctype="multipart/form-data">
                        <?php wp_nonce_field('qp_import_nonce_action', 'qp_import_nonce_field'); ?>
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th><label for="question_zip_file">Question Package (.zip)</label></th>
                                    <td><input type="file" name="question_zip_file" id="question_zip_file" accept=".zip,application/zip,application/x-zip,application/x-zip-compressed" required></td>
                                </tr>
                                <tr>
                                    <th><label>Apply Labels (Optional)</label></th>
                                    <td>
                                        <div class="labels-group" style="padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 150px; overflow-y: auto;">
                                            <?php if (!empty($all_labels)) : foreach ($all_labels as $label) : ?>
                                                <label class="inline-checkbox" style="display: block; margin-bottom: 5px;">
                                                    <input type="checkbox" name="labels_to_apply[]" value="<?php echo esc_attr($label->label_id); ?>">
                                                    <?php echo esc_html($label->label_name); ?>
                                                </label>
                                            <?php endforeach; else : ?>
                                                <p>No labels found. You can create them on the Labels page.</p>
                                            <?php endif; ?>
                                        </div>
                                        <p class="description">Select labels to apply to all questions imported in this batch.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <?php submit_button('Upload and Import'); ?>
                    </form>
                </div>
                <div style="flex: 1; min-width: 250px; padding: 1rem; background-color: #f6f7f7; border: 1px solid #ddd;">
                    <h3>Download Creation Tool</h3>
                    <p>To create the question package, you can use our Python script. You will need to have Python installed on your computer to run it.</p>
                    <p>
                        <a href="<?php echo esc_url(QP_PLUGIN_URL . 'tools/question_press_kit.py'); ?>" class="button button-secondary" download>
                            <span class="dashicons dashicons-download" style="line-height: 1.5;"></span>
                            Download Question Press Kit
                        </a>
                    </p>
                </div>
            </div>

            <div style="margin-top: 2rem;">
                <details>
                    <summary style="font-size: 1.2em; cursor: pointer; font-weight: bold;">View Required JSON Schema (v2.2)</summary>
                    <div style="background: #fff; border: 1px solid #ddd; padding: 1rem; margin-top: 1rem;">
                        <p>Your <code>questions.json</code> file must follow this structure. All PYQ-related fields are now at the group level.</p>
                        <pre style="background: #fdf6e3; color: #657b83; padding: 1rem; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word;"><?php
                            echo esc_html('{
    "schemaVersion": "2.2",
    "exportTimestamp": "2025-07-10T21:05:00Z",
    "questionGroups": [
        {
            "groupId": "unique-id-for-group",
            "subject": "Physics",
            "sourceName": "University Physics Vol. 3",
            "sectionName": "Chapter 5: Interference",
            "isPYQ": true,
            "examName": "NEET",
            "pyqYear": "2022",
            "Direction": { 
                "text": "The following questions relate to wave optics.",
                "image": "optional_image_filename.png"
            },
            "questions": [
                {
                    "questionId": "unique-id-for-question-1",
                    "topicName": "Wave Optics",
                    "questionText": "What is constructive interference?",
                    "questionNumber": "5.1",
                    "options": [
                        { "optionText": "When two waves cancel each other out.", "isCorrect": false },
                        { "optionText": "When two waves add up to make a larger wave.", "isCorrect": true }
                    ]
                }
            ]
        }
    ]
}');
                        ?></pre>
                    </div>
                </details>
            </div>
        </div>
        <?php
    }
}