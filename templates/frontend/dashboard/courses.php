<?php
/**
 * Template for the Dashboard "Available Courses" Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var WP_Query $available_courses_query WP_Query object for available (non-enrolled) courses.
 * @var int      $user_id                 The current user ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<h2><?php esc_html_e( 'Available Courses', 'question-press' ); ?></h2>

<?php if ( $available_courses_query->have_posts() ) : ?>
    <div class="qp-course-list">
        <?php
        while ( $available_courses_query->have_posts() ) : $available_courses_query->the_post();
            $course_id = get_the_ID();
            
            // --- Access Check for available courses ---
            $access_mode = get_post_meta( $course_id, '_qp_course_access_mode', true ) ?: 'free';
            $linked_product_id = get_post_meta( $course_id, '_qp_linked_product_id', true );
            $product_url = $linked_product_id ? get_permalink( $linked_product_id ) : '#';
            // Check if user has entitlement (ignoring enrollment, which they don't have)
            $user_has_access = \QuestionPress\Utils\User_Access::can_access_course( $user_id, $course_id, true );

            $button_html = '';
            $course_status = get_post_status( $course_id );

            // If the course is expired, show a disabled button
            if ( $course_status === 'expired' ) {
                $button_html = sprintf(
                    '<button class="qp-button" disabled>%s</button>',
                    __( 'Course Expired', 'question-press' )
                );
            }
            elseif ( $access_mode === 'free' ) {
                $button_html = sprintf(
                    '<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>',
                    $course_id,
                    __( 'Enroll Free', 'question-press' )
                );
            } elseif ( $access_mode === 'requires_purchase' ) {
                if ( $user_has_access ) {
                    $button_html = sprintf(
                        '<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>',
                        $course_id,
                        __( 'Enroll Now (Purchased)', 'question-press' )
                    );
                } else {
                    $button_html = sprintf(
                        '<a href="%s" class="qp-button qp-button-primary">%s</a>',
                        esc_url( $product_url ),
                        __( 'Purchase Access', 'question-press' )
                    );
                }
            }
            ?>
            <div class="qp-card qp-course-item qp-available">
                <div class="qp-card-content">
                    <h3 style="margin-top:0;"><?php the_title(); ?></h3>
                    <?php if ( has_excerpt() ) : ?>
                        <p><?php the_excerpt(); ?></p>
                    <?php else : ?>
                        <?php echo '<p>' . wp_trim_words( get_the_content(), 30, '...' ) . '</p>'; ?>
                    <?php endif; ?>
                </div>
                <?php if ( $button_html ): ?>
                    <div class="qp-card-action" style="padding: 1rem 1.5rem; border-top: 1px solid var(--qp-dashboard-border-light); text-align: right;">
                        <?php echo $button_html; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endwhile; wp_reset_postdata(); ?>
    </div> <?php // End qp-course-list ?>
<?php else : // This case should not be hit if the tab is conditionally hidden. ?>
    <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;"><?php esc_html_e( 'No other courses are available at this time.', 'question-press' ); ?></p></div></div>
<?php endif; ?>

<?php // Add CSS specific for this new section ?>
<style>
    .qp-course-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
    .qp-course-item .qp-card-content p { color: var(--qp-dashboard-text-light); font-size: 0.95em; line-height: 1.6; margin-bottom: 1rem; }
</style>