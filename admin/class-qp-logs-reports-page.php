<?php
if (!defined('ABSPATH')) exit;

// We will create these files in the next steps
require_once QP_PLUGIN_DIR . 'admin/class-qp-reports-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-log-settings-list-table.php';

class QP_Logs_Reports_Page {

    public static function render() {
        $tabs = [
            'reports' => ['label' => 'Reports', 'callback' => [self::class, 'render_reports_tab']],
            'log_settings' => ['label' => 'Log Settings', 'callback' => [self::class, 'render_log_settings_tab']],
        ];
        $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'reports';
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Logs & Reports</h1>
            <p>Review questions reported by users and manage the reasons available for reporting.</p>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
                <?php
                foreach ($tabs as $tab_id => $tab_data) {
                    $class = ($tab_id === $active_tab) ? ' nav-tab-active' : '';
                    echo '<a href="?page=qp-logs-reports&tab=' . esc_attr($tab_id) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($tab_data['label']) . '</a>';
                }
                ?>
            </nav>

            <div class="tab-content" style="margin-top: 1.5rem;">
                <?php
                call_user_func($tabs[$active_tab]['callback']);
                ?>
            </div>
        </div>
<?php
    }

    public static function render_reports_tab() {
        $list_table = new QP_Reports_List_Table();
        $list_table->prepare_items();
        ?>
        <form method="post">
            <?php $list_table->search_box('Search Reports', 'report'); ?>
            <?php $list_table->display(); ?>
        </form>
        <?php
    }

    public static function render_log_settings_tab() {
        // We will build this out in a later step
        echo '<h2>Manage Report Reasons</h2>';
        echo '<p>Here you will be able to add, edit, and delete the reasons users can select when reporting a question.</p>';
    }
}