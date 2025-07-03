<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Subjects_Page {

    /**
     * Renders the Subjects admin page.
     */
    public static function render() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_subjects';

        // Get all subjects from the database to display in the table
        $subjects = $wpdb->get_results("SELECT * FROM $table_name ORDER BY subject_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Subjects</h1>
            <a href="#" class="page-title-action">Add New</a> <hr class="wp-header-end">

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2>Add New Subject</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('qp_add_subject_nonce'); ?>
                                
                                <div class="form-field form-required">
                                    <label for="subject-name">Name</label>
                                    <input name="subject_name" id="subject-name" type="text" value="" size="40" aria-required="true">
                                    <p>The name is how it appears on your site.</p>
                                </div>

                                <p class="submit">
                                    <input type="submit" name="add_subject" id="submit" class="button button-primary" value="Add New Subject">
                                </p>
                            </form>
                        </div>
                    </div>
                </div><div id="col-right">
                    <div class.col-wrap">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col" class="manage-column">Name</th>
                                    <th scope="col" class="manage-column">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="the-list">
                                <?php if (!empty($subjects)) : ?>
                                    <?php foreach ($subjects as $subject) : ?>
                                        <tr>
                                            <td><?php echo esc_html($subject->subject_name); ?></td>
                                            <td>
                                                <a href="#">Edit</a> | 
                                                <a href="#" style="color:#a00;">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr class="no-items">
                                        <td class="colspanchange" colspan="2">No subjects found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div></div></div>
        <?php
    }
}