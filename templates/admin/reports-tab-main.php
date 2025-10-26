<?php
/**
 * Template for the Admin "Reports" > "Reports" tab content.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var int    $open_count              Count of open reports.
 * @var int    $resolved_count          Count of resolved reports.
 * @var string $current_status          The current status view ('open' or 'resolved').
 * @var string $list_table_search_box_html The pre-rendered HTML for the list table search box.
 * @var string $list_table_display_html  The pre-rendered HTML for the list table display.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>

<style>
/* Target the specific columns in the reports table */
.wp-list-table .column-report_id { width: 8%; }
.wp-list-table .column-question_text { width: 45%; }
.wp-list-table .column-report_details { width: 22%; }
.wp-list-table .column-actions { width: 25%; }

/* --- NEW & IMPROVED Vertical Bar Styling --- */
.wp-list-table tr.report-type-report th,
.wp-list-table tr.report-type-suggestion th {
    /* This creates the space for our bar */
    border-left: 4px solid transparent;
}
.wp-list-table tr.report-type-report th{
    border-left: 4px solid #d63638; /* Red */
}
.wp-list-table tr.report-type-suggestion th{
    border-left: 4px solid #FFC107; /* Yellow */
}
</style>    

<ul class="subsubsub">
    <li><a href="?page=qp-logs-reports&tab=reports&status=open" class="<?php if ($current_status === 'open') echo 'current'; ?>"><?php esc_html_e( 'Open', 'question-press' ); ?> <span class="count">(<?php echo esc_html( $open_count ); ?>)</span></a> |</li>
    <li><a href="?page=qp-logs-reports&tab=reports&status=resolved" class="<?php if ($current_status === 'resolved') echo 'current'; ?>"><?php esc_html_e( 'Resolved', 'question-press' ); ?> <span class="count">(<?php echo esc_html( $resolved_count ); ?>)</span></a></li>
</ul>

<?php if ( $current_status === 'resolved' && $resolved_count > 0 ) : 
    $clear_url = wp_nonce_url( admin_url( 'admin.php?page=qp-logs-reports&tab=reports&action=clear_resolved_reports' ), 'qp_clear_all_reports_nonce' );
?>
    <a href="<?php echo esc_url( $clear_url ); ?>" class="button button-danger" style="float: right; margin-top: -30px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete all resolved reports? This action cannot be undone.', 'question-press' ); ?>');"><?php esc_html_e( 'Clear All Resolved Reports', 'question-press' ); ?></a>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="page" value="qp-logs-reports">
    <input type="hidden" name="tab" value="reports">
    <input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>">
    
    <?php
    // Echo the pre-rendered search box and list table HTML
    echo $list_table_search_box_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $list_table_display_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
</form>