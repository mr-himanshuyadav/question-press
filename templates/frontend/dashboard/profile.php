<?php

/**
 * Template for the Dashboard Profile Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var array $profile_data Array containing user profile details ('display_name', 'email', 'avatar_url', 'scope_description').
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<div class="qp-profile-page">
    <h2><?php esc_html_e('My Profile', 'question-press'); ?></h2>

    <div class="qp-profile-layout">
        <?php // Profile Card (Left Column) 
        ?>
        <div class="qp-card qp-profile-card">
            <div class="qp-card-content">
                <form id="qp-profile-update-form">
                    <?php wp_nonce_field('qp_save_profile_nonce', '_qp_profile_nonce'); ?>

                    <div class="qp-profile-avatar qp-profile-avatar-wrapper">
                        <img id="qp-profile-avatar-preview" src="<?php echo esc_url($profile_data['avatar_url']); ?>" alt="Profile Picture" width="128" height="128">
                        <input type="file" id="qp-avatar-upload-input" name="qp_avatar_upload" accept="image/jpeg, image/png, image/gif" style="display: none;">
                        <button type="button" class="qp-change-avatar-button qp-button qp-button-secondary" style="margin-top: 10px;"><?php esc_html_e('Change Avatar', 'question-press'); ?></button>
                        <div class="qp-avatar-upload-actions" style="display: none; margin-top: 10px; gap: 5px;">
                            <button type="button" class="qp-upload-avatar-button qp-button qp-button-primary button-small"><?php esc_html_e('Upload New', 'question-press'); ?></button>
                            <button type="button" class="qp-cancel-avatar-button qp-button qp-button-secondary button-small"><?php esc_html_e('Cancel', 'question-press'); ?></button>
                        </div>
                        <p id="qp-avatar-upload-error" class="qp-error-message" style="display: none; color: red; font-size: 0.9em; margin-top: 5px;"></p>
                    </div>

                    <?php // Display elements 
                    ?>
                    <div class="qp-profile-display">
                        <h3 class="qp-profile-name"><?php echo esc_html__('Hello, ', 'question-press') . esc_html($profile_data['display_name']); ?>!</h3>
                        <p class="qp-profile-email"><?php echo esc_html($profile_data['email']); ?></p>
                        <button type="button" class="qp-button qp-button-secondary qp-edit-profile-button"><?php esc_html_e('Edit Profile', 'question-press'); ?></button>
                    </div>

                    <?php // Edit elements 
                    ?>
                    <div class="qp-profile-edit" style="display: none; width: 100%;">
                        <div class="qp-form-group qp-profile-field">
                            <label for="qp_display_name"><?php esc_html_e('Display Name', 'question-press'); ?></label>
                            <input type="text" id="qp_display_name" name="display_name" value="<?php echo esc_attr($profile_data['display_name']); ?>" required>
                        </div>
                        <div class="qp-form-group qp-profile-field">
                            <label for="qp_user_email"><?php esc_html_e('Email Address', 'question-press'); ?></label>
                            <input type="email" id="qp_user_email" name="user_email" value="<?php echo esc_attr($profile_data['email']); ?>" required>
                        </div>
                        <div class="qp-profile-edit-actions">
                            <button type="button" class="qp-button qp-button-secondary qp-cancel-edit-profile-button"><?php esc_html_e('Cancel', 'question-press'); ?></button>
                            <button type="submit" class="qp-button qp-button-primary qp-save-profile-button"><?php esc_html_e('Save Changes', 'question-press'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php // Detail Cards (Right Column) 
        ?>
        <div class="qp-profile-details">
            <?php // Access Scope Card 
            ?>
            <div class="qp-card qp-access-card">
                <div class="qp-card-header">
                    <h3><?php esc_html_e('Your Practice Scope', 'question-press'); ?></h3>
                </div>
                <div class="qp-card-content">
                    <p><?php echo wp_kses_post($profile_data['scope_description']); // Use wp_kses_post if description might contain simple HTML like <strong> 
                        ?></p>
                </div>
            </div>

            <?php // Password Change Card 
            ?>
            <div class="qp-card qp-password-card">
                <div class="qp-card-header">
                    <h3><?php esc_html_e('Security', 'question-press'); ?></h3>
                </div>
                <div class="qp-card-content">
                    <div class="qp-password-display">
                        <p><?php esc_html_e('Manage your account password.', 'question-press'); ?></p>
                        <button type="button" class="qp-button qp-button-secondary qp-change-password-button"><?php esc_html_e('Change Password', 'question-press'); ?></button>
                        <p class="qp-forgot-password-link-wrapper">
                            <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="qp-forgot-password-link"><?php esc_html_e('Forgot Password?', 'question-press'); ?></a>
                        </p>
                    </div>
                    <div class="qp-password-edit" style="display: none;">
                        <form id="qp-password-change-form">
                            <?php wp_nonce_field('qp_change_password_nonce', '_qp_password_nonce'); ?>
                            <div class="qp-form-group qp-profile-field">
                                <label for="qp_current_password"><?php esc_html_e('Current Password', 'question-press'); ?></label>
                                <input type="password" id="qp_current_password" name="current_password" required autocomplete="current-password">
                            </div>
                            <div class="qp-form-group qp-profile-field">
                                <label for="qp_new_password"><?php esc_html_e('New Password', 'question-press'); ?></label>
                                <input type="password" id="qp_new_password" name="new_password" required autocomplete="new-password">
                            </div>
                            <div class="qp-form-group qp-profile-field">
                                <label for="qp_confirm_password"><?php esc_html_e('Confirm New Password', 'question-press'); ?></label>
                                <input type="password" id="qp_confirm_password" name="confirm_password" required autocomplete="new-password">
                                <p id="qp-password-match-error" class="qp-error-message" style="display: none; color: red; font-size: 0.9em; margin-top: 5px;"></p>
                            </div>
                            <div class="qp-password-edit-actions">
                                <button type="button" class="qp-button qp-button-secondary qp-cancel-change-password-button"><?php esc_html_e('Cancel', 'question-press'); ?></button>
                                <button type="submit" class="qp-button qp-button-primary qp-save-password-button"><?php esc_html_e('Update Password', 'question-press'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="qp-card">
                <div class="qp-card-header">
                    <h3>Your Order History</h3>
</div>
                
                <div class="qp-card-content">
                    <div class="qp-order-history">
                    <?php
                    if (! function_exists('wc_get_orders')) {
                        echo '<p>WooCommerce does not appear to be active.</p>';
                        return;
                    }

                    $user_id = get_current_user_id();
                    if ($user_id == 0) {
                        echo '<p>Please log in to view your order history.</p>';
                        return;
                    }

                    // Get all 'completed' orders for the current user
                    $orders = wc_get_orders([
                        'customer_id' => $user_id,
                        'status'      => 'completed', // You can change this or add more statuses
                        'limit'       => -1, // Get all orders
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ]);

                    if ($orders) {
                        echo '<table>';
                        echo '<thead>';
                        echo '<tr>';
                        echo '<th>Order</th>';
                        echo '<th>Date</th>';
                        echo '<th>Status</th>';
                        echo '<th>Total</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';

                        foreach ($orders as $order) {
                            echo '<tr>';
                            // You can make this a link to a detailed view if you build one
                            echo '<td>#' . esc_html($order->get_order_number()) . '</td>';
                            echo '<td>' . esc_html($order->get_date_created()->format('Y-m-d')) . '</td>';
                            echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
                            echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
                            echo '</tr>';
                        }

                        echo '</tbody>';
                        echo '</table>';
                    } else {
                        echo '<p>You have no completed orders.</p>';
                    }
                    ?>
                </div>
                </div>

                <style>
                    .qp-order-history table {
                        width: 100%;
                        border-collapse: collapse;
                    }

                    .qp-order-history th,
                    .qp-order-history td {
                        border: 1px solid #eee;
                        padding: 8px;
                        text-align: left;
                    }

                    .qp-order-history th {
                        background-color: #f9f9f9;
                    }
                </style>
            </div>
        </div>
    </div>
</div>