<?php
/**
 * Template for the Admin Settings page wrapper.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var string $settings_fields_html HTML output from settings_fields().
 * @var string $submit_button_top    HTML output from the top submit_button().
 * @var string $sections_html        HTML output from do_settings_sections().
 * @var string $submit_button_bottom HTML output from the bottom submit_button().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Note: settings_errors() and session messages are echoed directly in the render method *before* this template.
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Question Press Settings', 'question-press' ); ?></h1>
    
    <form action="options.php" method="post">
        <?php
        // Output the hidden settings fields
        echo $settings_fields_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        // Output the top save button
        echo $submit_button_top; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        
        // Output all the settings sections and fields
        echo $sections_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        
        // Output the bottom save button
        echo $submit_button_bottom; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    </form>
</div>