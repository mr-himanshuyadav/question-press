<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Import_Page {

    /**
     * Renders the Import admin page.
     */
    public static function render() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Import Questions</h1>
            <hr class="wp-header-end">

            <p>Upload a <code>.zip</code> file created by the "Question Press Kit" tool to import questions into the database.</p>

            <form method="post" action="" enctype="multipart/form-data">
                
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