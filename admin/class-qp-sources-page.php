<?php
if (!defined('ABSPATH')) exit;

class QP_Sources_Page {

    public static function render() {
        ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <h2>Sources Management</h2>
                    <p>This section will allow you to create and manage definitive sources (e.g., textbooks, papers) and their internal sections (e.g., chapters).</p>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <p>A list of sources will appear here.</p>
                </div>
            </div>
        </div>
        <?php
    }
}