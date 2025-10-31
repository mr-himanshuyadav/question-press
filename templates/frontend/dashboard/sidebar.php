<?php
/**
 * Template for the User Dashboard Sidebar.
 *
 * @package QuestionPress/Templates/Frontend
 *
 * @var WP_User $current_user         The current WordPress user object.
 * @var string  $access_status_message HTML message regarding user access/entitlements.
 * @var string  $active_tab           Slug of the currently active dashboard tab.
 * @var array   $tabs                 Associative array defining the sidebar tabs [slug => ['label', 'icon']].
 * @var string  $base_dashboard_url   Base URL for the dashboard page.
 * @var string  $logout_url           URL for logging out.
 * @var string  $avatar_html          HTML for the user's avatar.
 * @var bool    $is_front_page        True if the dashboard is the site's front page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div class="qp-sidebar-header">
	<button class="qp-sidebar-close-btn" aria-label="Close Navigation">
		<span class="dashicons dashicons-no-alt"></span>
	</button>
	<div class="qp-sidebar-avatar">
		<?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally ?>
	</div>
	<span class="qp-user-name"><?php echo esc_html__( 'Hello, ', 'question-press' ) . esc_html( $current_user->display_name ); ?>!</span><br>
	<span class="qp-access-status">
		<?php echo wp_kses_post( $access_status_message ); ?>
	</span>
</div>

<ul class="qp-sidebar-nav">
	<?php foreach ( $tabs as $slug => $details ) : ?>
		<?php
		$is_active = ( $slug === $active_tab );
        // Prepend 'tab/' if this is the front page, otherwise build the normal path
        $url_slug = ($is_front_page ? 'tab/' : '') . $slug . '/';
        
        if ($slug === 'overview') {
            $url = $base_dashboard_url; // Overview is always the base URL
        } else {
            $url = trailingslashit( $base_dashboard_url ) . $url_slug;
        }
		?>
		<li>
			<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $is_active ? 'active' : ''; ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $details['icon'] ); ?>"></span>
				<span><?php echo esc_html( $details['label'] ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>

<div class="qp-sidebar-footer">
	<a href="<?php echo esc_url( $logout_url ); ?>" class="qp-logout-link">
		<span class="dashicons dashicons-exit"></span> Logout
	</a>
</div>