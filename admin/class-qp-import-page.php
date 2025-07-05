<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Import_Page {

    public static function render() {
        if (isset($_POST['submit']) && isset($_FILES['question_zip_file'])) {
            $importer = new QP_Importer();
            $importer->handle_import();
        } else {
            self::render_upload_form();
        }
    }

    private static function render_upload_form() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Import Questions</h1>
            <hr class="wp-header-end">

            <div style="display: flex; gap: 20px; margin-top: 1rem;">
                <div style="flex: 2;">
                    <h3>Upload Question Package</h3>
                    <p>Upload a <code>.zip</code> file created by the "Question Press Kit" tool to import questions into the database.</p>

                    <form method="post" action="admin.php?page=qp-import" enctype="multipart/form-data">
                        <?php wp_nonce_field('qp_import_nonce_action', 'qp_import_nonce_field'); ?>
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th><label for="question_zip_file">Question Package (.zip)</label></th>
                                    <td><input type="file" name="question_zip_file" id="question_zip_file" accept=".zip,application/zip,application/x-zip,application/x-zip-compressed" required></td>
                                </tr>
                            </tbody>
                        </table>
                        <?php submit_button('Upload and Import'); ?>
                    </form>
                </div>
                <div style="flex: 1; padding: 1rem; background-color: #f6f7f7; border: 1px solid #ddd;">
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
        </div>
        <?php
    }
}