<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Import_Page {

    /**
     * Renders the Import admin page or handles the import process.
     */
    public static function render() {
        // Check if the form has been submitted
        if (isset($_POST['submit']) && isset($_FILES['question_zip_file'])) {
            // Instantiate the importer and handle the file
            $importer = new QP_Importer();
            $importer->handle_import();
        } else {
            // If not submitted, show the upload form
            self::render_upload_form();
        }
    }

    /**
     * Renders the initial upload form.
     */
    private static function render_upload_form() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Import Questions</h1>
            <hr class="wp-header-end">

            <p>Upload a <code>.zip</code> file created by the "Question Press Kit" tool to import questions into the database.</p>

            <form method="post" action="admin.php?page=qp-import" enctype="multipart/form-data">
                
                <?php wp_nonce_field('qp_import_nonce_action', 'qp_import_nonce_field'); ?>

                <table class="form-table">
                    <tbody>
                        <tr class="user-user-login-wrap">
                            <th><label for="question_zip_file">Question Package (.zip)</label></th>
                            <td><input type="file" name="question_zip_file" id="question_zip_file" accept=".zip" required></td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button('Upload and Import'); ?>

            </form>
        </div>
        <?php
    }
}