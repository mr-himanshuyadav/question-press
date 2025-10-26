<?php
/**
 * Template for the Admin "All Questions" page (main list table).
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var string $add_new_url            URL to add a new question group.
 * @var string $session_message_html   HTML for displaying session messages (success/error notices).
 * @var string $bulk_edit_message_html HTML for displaying the bulk edit confirmation message.
 * @var string $views_html             HTML output from $list_table->views().
 * @var string $search_box_html        HTML output from $list_table->search_box().
 * @var string $list_table_display_html HTML output from $list_table->display().
 * @var string $view_modal_html        HTML output from $list_table->display_view_modal().
 * @var string $page_slug              The current admin page slug (e.g., 'question-press').
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'All Questions', 'question-press' ); ?></h1>
    <a href="<?php echo esc_url( $add_new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'question-press' ); ?></a>

    <?php
    // Echo pre-rendered messages and notices
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $session_message_html;
    echo $bulk_edit_message_html;
    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>

    <hr class="wp-header-end">

    <?php
    // Echo pre-rendered list table views
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $views_html;
    ?>

    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
        <?php
        // Echo pre-rendered search box and list table display
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $search_box_html;
        echo $list_table_display_html;
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    </form>

    <?php
    // Echo pre-rendered view modal HTML
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $view_modal_html;
    ?>

    <?php // Keep the CSS styles within the template for now ?>
    <style type="text/css">
        #post-query-submit {
            margin-left: 8px;
        }
        .wp-list-table .column-custom_question_id { width: 5%; }
        .wp-list-table .column-question_text { width: 50%; }
        .wp-list-table .column-subject_name { width: 15%; }
        .wp-list-table .column-source { width: 15%; }
        .wp-list-table .column-import_date { width: 10%; }
        .wp-list-table.questions #the-list tr td { border-bottom: 1px solid rgb(174, 174, 174); }
        #qp-view-modal-backdrop {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.6); z-index: 1001; display: none;
            justify-content: center; align-items: center;
        }
        #qp-view-modal-content {
            background: #fff; padding: 2rem; border-radius: 8px; max-width: 90%;
            width: 700px; max-height: 90vh; overflow-y: auto; position: relative;
            font: normal 1.5em KaTeX_Main, Times New Roman, serif;
        }
        .qp-modal-close-btn {
            position: absolute; top: 1rem; right: 1rem; font-size: 24px;
            background: none; border: none; cursor: pointer; color: #50575e;
        }
    </style>
</div>