<?php
/**
 * Template for the Admin "Reports" page wrapper (tabs).
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var array  $tabs             Array of tab definitions [slug => ['label', 'callback']].
 * @var string $active_tab       The slug of the currently active tab.
 * @var string $tab_content_html The pre-rendered HTML content of the active tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Reports', 'question-press' ); ?></h1>
    <p><?php esc_html_e( 'Review questions reported by users and manage the reasons available for reporting.', 'question-press' ); ?></p>
    <?php
    // Display settings errors and messages from the session
    if ( isset( $_SESSION['qp_admin_message'] ) ) {
        $message = html_entity_decode( $_SESSION['qp_admin_message'] );
        echo '<div id="message" class="notice notice-' . esc_attr( $_SESSION['qp_admin_message_type'] ) . ' is-dismissible"><p>' . $message . '</p></div>';
        unset( $_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type'] );
    }
    ?>
    <hr class="wp-header-end">

    <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
        <?php
        foreach ( $tabs as $tab_id => $tab_data ) {
            $class = ( $tab_id === $active_tab ) ? ' nav-tab-active' : '';
            echo '<a href="?page=qp-logs-reports&tab=' . esc_attr( $tab_id ) . '" class="nav-tab' . esc_attr( $class ) . '">' . esc_html( $tab_data['label'] ) . '</a>';
        }
        ?>
    </nav>

    <div class="tab-content" style="margin-top: 1.5rem;">
        <?php
        // Echo the pre-rendered HTML content for the active tab
        echo $tab_content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    </div>
</div>