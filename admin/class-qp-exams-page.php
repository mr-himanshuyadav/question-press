<?php
if (!defined('ABSPATH')) exit;

class QP_Exams_Page {

    public static function render() {
        ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <h2>Exams Management</h2>
                    <p>This section will allow you to create and manage exams and link them to subjects.</p>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <p>A list of exams will appear here.</p>
                </div>
            </div>
        </div>
        <?php
    }
}