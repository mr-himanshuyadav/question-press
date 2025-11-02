<?php
/**
 * Template for the User Dashboard Wrapper Layout.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var string $sidebar_html      HTML content for the sidebar.
 * @var string $main_content_html HTML content for the main content area.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div id="qp-practice-app-wrapper"> <?php // Outer wrapper remains ?>
	<div id="qp-mobile-header">
		<button class="qp-sidebar-toggle" aria-label="Toggle Navigation" aria-expanded="false">
			<span class="dashicons dashicons-menu-alt"></span>
		</button>
		<span class="qp-mobile-header-title"><?php bloginfo( 'name' ); ?></span>
	</div>
	<div class="qp-dashboard-layout">
		<div class="qp-sidebar-overlay"></div>
		<aside class="qp-sidebar">
			<?php echo $sidebar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sidebar HTML generated internally ?>
		</aside>

		<main class="qp-main-content">
			<?php echo $main_content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Main content HTML generated internally/conditionally ?>

			<?php // Keep the hidden modal for review list popups ?>
            <div id="qp-review-modal-backdrop" style="display: none;">
                <div id="qp-review-modal-content"></div>
            </div>
		</main>
	</div>
</div> <?php // End #qp-practice-app-wrapper ?>