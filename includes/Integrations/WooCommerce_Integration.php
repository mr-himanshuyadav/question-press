<?php
namespace QuestionPress\Integrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles all integration logic with WooCommerce.
 *
 * @package QuestionPress\Integrations
 */
class WooCommerce_Integration {

    /**
     * Constructor.
     * Adds all WooCommerce-related hooks.
     */
    public function __construct() {
        // Product Page Meta Fields
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_plan_link_to_simple_products' ] );
        add_action( 'woocommerce_process_product_meta_simple', [ $this, 'save_plan_link_simple_product' ] );
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'add_plan_link_to_variable_products' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation', [ $this, 'save_plan_link_variable_product' ], 10, 2 );

        // Order Completion Hook
        add_action( 'woocommerce_order_status_completed', [ $this, 'grant_access_on_order_complete' ], 10, 1 );
    }

    /**
     * Add custom field to WooCommerce Product Data > General tab for Simple products.
     */
    public function add_plan_link_to_simple_products() {
        global $post;

        // --- NEW: Check if this is an auto-generated product ---
        if ( get_post_meta( $post->ID, '_qp_is_auto_generated', true ) === 'true' ) {
            // Get Plan info
            $linked_plan_id = get_post_meta( $post->ID, '_qp_linked_plan_id', true );
            $plan_post = $linked_plan_id ? get_post( $linked_plan_id ) : null;
            
            // Get Course info
            $linked_course_id = get_post_meta( $post->ID, '_qp_linked_course_id', true );
            $course_post = $linked_course_id ? get_post( $linked_course_id ) : null;
            
            echo '<div class="options_group" style="padding: 12px; border-top: 1px solid #eee;">';
            echo '<p style="margin: 0; padding-bottom: 10px; border-bottom: 1px solid #eee;"><strong>' . esc_html__( 'Question Press Auto-Link', 'question-press' ) . '</strong></p>';
            echo '<div style="font-style: italic; color: #666; margin: 10px 0 0 0;">';

            // CASE 1: Linked to a Course (which implies a plan)
            if ( $course_post ) {
                echo '<strong>' . esc_html__( 'Linked Course:', 'question-press' ) . '</strong><br>';
                echo esc_html( $course_post->post_title ) . ' (ID: ' . esc_html( $linked_course_id ) . ')';
                echo ' (<a href="' . esc_url( get_edit_post_link( $course_post->ID ) ) . '" target="_blank">' . esc_html__( 'Edit Course', 'question-press' ) . '</a>)';
                
                echo '<br><br>'; // Add spacing

                echo '<strong>' . esc_html__( 'Linked Plan:', 'question-press' ) . '</strong><br>';
                if ( $plan_post ) {
                    echo esc_html( $plan_post->post_title ) . ' (ID: ' . esc_html( $linked_plan_id ) . ')';
                    echo ' (<a href="' . esc_url( get_edit_post_link( $plan_post->ID ) ) . '" target="_blank">' . esc_html__( 'View Plan', 'question-press' ) . '</a>)';
                } else {
                    echo esc_html__( 'Plan link is missing or broken.', 'question-press' );
                }
            
            // CASE 2: Linked ONLY to a Manual Plan
            } elseif ( $plan_post ) {
                echo '<strong>' . esc_html__( 'Linked Plan:', 'question-press' ) . '</strong><br>';
                echo esc_html( $plan_post->post_title ) . ' (ID: ' . esc_html( $linked_plan_id ) . ')';
                echo ' (<a href="' . esc_url( get_edit_post_link( $plan_post->ID ) ) . '" target="_blank">' . esc_html__( 'Edit Plan', 'question-press' ) . '</a>)';
            
            // CASE 3: Orphaned Product
            } else {
                echo '<span style="color: #c00;">' . esc_html__( 'This product is auto-generated but its source plan/course is missing.', 'question-press' ) . '</span>';
            }

            echo '</div></div>';
            return; // Stop here, don't show the dropdown
        }

        // Get all published 'qp_plan' posts, excluding auto-generated ones
        $plans = get_posts( [
            'post_type'   => 'qp_plan',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            // --- NEW: Exclude auto-generated plans from the dropdown ---
            'meta_query'  => [
                [
                    'key'     => '_qp_is_auto_generated',
                    'compare' => 'NOT EXISTS', // Show manual plans
                ]
            ]
            // --- END NEW ---
        ] );

        $options = [ '' => __( '— Select a Question Press Plan —', 'question-press' ) ];
        if ( $plans ) {
            foreach ( $plans as $plan ) {
                $options[ $plan->ID ] = esc_html( $plan->post_title );
            }
        }

        // Output the WooCommerce field
        woocommerce_wp_select( [
            'id'          => '_qp_linked_plan_id',
            'label'       => __( 'Question Press Plan', 'question-press' ),
            'description' => __( 'Link this product to a Question Press monetization plan. This grants access when the order is completed.', 'question-press' ),
            'desc_tip'    => true,
            'options'     => $options,
            'value'       => get_post_meta( $post->ID, '_qp_linked_plan_id', true ), // Get current value
        ] );
    }

    /**
     * Save the custom field for Simple products.
     */
    public function save_plan_link_simple_product( $post_id ) {
        // --- NEW: Don't save if it's an auto-generated product (it's set by the course) ---
        if ( get_post_meta( $post_id, '_qp_is_auto_generated', true ) === 'true' ) {
            return;
        }
        // --- END NEW ---

        $plan_id = isset( $_POST['_qp_linked_plan_id'] ) ? absint( $_POST['_qp_linked_plan_id'] ) : '';
        update_post_meta( $post_id, '_qp_linked_plan_id', $plan_id );
    }

    /**
     * Add custom field to WooCommerce Product Data > Variations tab for Variable products.
     */
    public function add_plan_link_to_variable_products( $loop, $variation_data, $variation ) {
        // Get all published 'qp_plan' posts (reuse logic or query again)
        $plans = get_posts( [
            'post_type'   => 'qp_plan',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            // --- NEW: Exclude auto-generated plans from the dropdown ---
            'meta_query'  => [
                [
                    'key'     => '_qp_is_auto_generated',
                    'compare' => 'NOT EXISTS', // Show manual plans
                ]
            ]
            // --- END NEW ---
        ] );

        $options = [ '' => __( '— Select a Question Press Plan —', 'question-press' ) ];
        if ( $plans ) {
            foreach ( $plans as $plan ) {
                $options[ $plan->ID ] = esc_html( $plan->post_title );
            }
        }

        // Output the WooCommerce field for variations
        woocommerce_wp_select( [
            'id'            => "_qp_linked_plan_id[{$loop}]", // Needs array index for variations
            'label'         => __( 'Question Press Plan', 'question-press' ),
            'description'   => __( 'Link this variation to a Question Press monetization plan.', 'question-press' ),
            'desc_tip'      => true,
            'options'       => $options,
            'value'         => get_post_meta( $variation->ID, '_qp_linked_plan_id', true ), // Get value for this variation ID
            'wrapper_class' => 'form-row form-row-full', // Ensure it takes full width in variation options
        ] );
    }

    /**
     * Save the custom field for Variable products (variations).
     */
    public function save_plan_link_variable_product( $variation_id, $i ) {
        $plan_id = isset( $_POST['_qp_linked_plan_id'][ $i ] ) ? absint( $_POST['_qp_linked_plan_id'][ $i ] ) : '';
        update_post_meta( $variation_id, '_qp_linked_plan_id', $plan_id );
    }

    /**
     * Grant Question Press entitlement when a specific WooCommerce order is completed.
     * Reads linked plan data and creates a record in wp_qp_user_entitlements.
     *
     * @param int $order_id The ID of the completed order.
     */
    public function grant_access_on_order_complete( $order_id ) {
        error_log( "QP Access Hook: Processing Order #{$order_id}" ); // Log start
        $order = wc_get_order( $order_id );

        // Check if the order is valid and paid (or processing if allowing access before full payment)
        if ( ! $order || ! $order->is_paid() ) { // Stricter check: use is_paid() for completed orders
            error_log( "QP Access Hook: Order #{$order_id} not valid or not paid." );
            return;
        }

        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            error_log( "QP Access Hook: No user ID associated with Order #{$order_id}. Cannot grant entitlement." );
            return; // Cannot grant entitlement to guest users
        }

        global $wpdb;
        $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
        $granted_entitlement = false;

        foreach ( $order->get_items() as $item_id => $item ) {
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $target_id    = $variation_id > 0 ? $variation_id : $product_id; // Use variation ID if available

            // Get the linked plan ID from product/variation meta
            $linked_plan_id = get_post_meta( $target_id, '_qp_linked_plan_id', true );

            if ( ! empty( $linked_plan_id ) ) {
                $plan_id   = absint( $linked_plan_id );
                $plan_post = get_post( $plan_id );

                // Ensure the linked plan exists and is published
                if ( $plan_post && $plan_post->post_type === 'qp_plan' && $plan_post->post_status === 'publish' ) {
                    error_log( "QP Access Hook: Found linked Plan ID #{$plan_id} for item in Order #{$order_id}" );

                    // Get plan details from post meta
                    $plan_type      = get_post_meta( $plan_id, '_qp_plan_type', true );
                    $duration_value = get_post_meta( $plan_id, '_qp_plan_duration_value', true );
                    $duration_unit  = get_post_meta( $plan_id, '_qp_plan_duration_unit', true );
                    $attempts       = get_post_meta( $plan_id, '_qp_plan_attempts', true );

                    $start_date         = current_time( 'mysql' );
                    $expiry_date        = null;
                    $remaining_attempts = null;

                    // Calculate expiry date if applicable
                    if ( ( $plan_type === 'time_limited' || $plan_type === 'combined' ) && ! empty( $duration_value ) && ! empty( $duration_unit ) ) {
                        try {
                            // Use WordPress timezone for calculation start point
                            $start_datetime = new \DateTime( $start_date, wp_timezone() );
                            $start_datetime->modify( '+' . absint( $duration_value ) . ' ' . sanitize_key( $duration_unit ) );
                            $expiry_date = $start_datetime->format( 'Y-m-d H:i:s' );
                            error_log( "QP Access Hook: Calculated expiry date for Plan ID #{$plan_id}: {$expiry_date}" );
                        } catch ( \Exception $e ) {
                            error_log( "QP Access Hook: Error calculating expiry date for Plan ID #{$plan_id} - " . $e->getMessage() );
                            $expiry_date = null; // Fallback if calculation fails
                        }
                    } elseif ( $plan_type === 'unlimited' ) {
                        $expiry_date        = null; // Explicitly null for unlimited time
                        $remaining_attempts = null; // Explicitly null for unlimited attempts
                        error_log( "QP Access Hook: Plan ID #{$plan_id} is Unlimited type." );
                    }

                    // Set remaining attempts
                    if ( $plan_type === 'unlimited' ) {
                        $remaining_attempts = null; // NULL = Unlimited attempts
                        error_log( "QP Access Hook: Setting UNLIMITED attempts for Plan ID #{$plan_id}." );
                    } else {
                        // This now correctly handles:
                        // 'attempt_limited' / 'combined' -> saves the number (e.g., 100)
                        // 'course_access' (manual or auto) -> saves 0 (because of our meta box fixes)
                        $remaining_attempts = absint( $attempts );
                        error_log( "QP Access Hook: Setting attempts for Plan ID #{$plan_id}: {$remaining_attempts}" );
                    }

                    // Insert the new entitlement record
                    $inserted = $wpdb->insert(
                        $entitlements_table,
                        [
                            'user_id'            => $user_id,
                            'plan_id'            => $plan_id,
                            'order_id'           => $order_id,
                            'start_date'         => $start_date,
                            'expiry_date'        => $expiry_date, // NULL if not time-based or unlimited
                            'remaining_attempts' => $remaining_attempts, // NULL if not attempt-based or unlimited
                            'status'             => 'active',
                        ],
                        [ // Data formats
                            '%d', // user_id
                            '%d', // plan_id
                            '%d', // order_id
                            '%s', // start_date
                            '%s', // expiry_date (can be NULL)
                            '%d', // remaining_attempts (can be NULL)
                            '%s', // status
                        ]
                    );

                    if ( $inserted ) {
                        error_log( "QP Access Hook: Successfully inserted entitlement record for User #{$user_id}, Plan #{$plan_id}, Order #{$order_id}" );
                        $granted_entitlement = true;
                        // Optional: Add an order note
                        $order->add_order_note( sprintf( 'Granted Question Press access via Plan ID %d.', $plan_id ) );
                        // Consider breaking if you only want to grant one plan per order,
                        // or allow multiple plans if purchased together. Let's allow multiple for now.
                        // break;
                    } else {
                        error_log( "QP Access Hook: FAILED to insert entitlement record for User #{$user_id}, Plan #{$plan_id}, Order #{$order_id}. DB Error: " . $wpdb->last_error );
                        $order->add_order_note( sprintf( 'ERROR: Failed to grant Question Press access for Plan ID %d. DB Error: %s', $plan_id, $wpdb->last_error ), true ); // Add as private note
                    }
                } else {
                    error_log( "QP Access Hook: Linked Plan ID #{$linked_plan_id} not found or not published for item in Order #{$order_id}" );
                }
            } else {
                // error_log("QP Access Hook: No QP Plan linked for product/variation ID #{$target_id} in Order #{$order_id}"); // This might be too verbose if many unrelated products are ordered.
            }
        } // end foreach item

        if ( ! $granted_entitlement ) {
            error_log( "QP Access Hook: No Question Press entitlements were granted for Order #{$order_id}." );
        }
    }

}