<?php
if (!defined('ABSPATH')) exit;

class QP_Logs_Page {

    public static function render() {
        $list_table = new QP_Logs_List_Table();
        $list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">System Logs</h1>

            <form method="post" action="">
                <?php wp_nonce_field('qp_clear_logs_nonce'); ?>
                <input type="hidden" name="action" value="clear_logs">
                <?php submit_button('Clear All Logs', 'delete', 'clear_logs_submit', false); ?>
            </form>

            <hr class="wp-header-end">

            <form method="post">
                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
    }
}