<?php
/**
 * Template for the Dashboard Courses Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var WP_Query $courses_query         WP_Query object for courses.
 * @var array    $enrolled_course_ids   Array of course IDs the user is enrolled in.
 * @var array    $enrolled_courses_data Array of progress data for enrolled courses, keyed by course ID.
 * @var bool     $found_enrolled        Flag indicating if any enrolled courses were found.
 * @var bool     $found_available       Flag indicating if any available (non-enrolled) courses were found.
 * @var int      $user_id               The current user ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<h2><?php esc_html_e( 'My Courses', 'question-press' ); ?></h2>

<?php if ( $courses_query->have_posts() ) : ?>
    <div class="qp-course-list">
        <?php
        while ( $courses_query->have_posts() ) : $courses_query->the_post();
            $course_id = get_the_ID();
            $is_enrolled = in_array( $course_id, $enrolled_course_ids );

            // --- Access Check ---
            $access_mode = get_post_meta( $course_id, '_qp_course_access_mode', true ) ?: 'free';
            $linked_product_id = get_post_meta( $course_id, '_qp_linked_product_id', true );
            $product_url = $linked_product_id ? get_permalink( $linked_product_id ) : '#';
            $user_has_access = \QuestionPress\Utils\User_Access::can_access_course( $user_id, $course_id, true ); // Pass user_id explicitly

            $button_html = '';
            if ( $is_enrolled ) {
                $course_data = $enrolled_courses_data[ $course_id ] ?? [ 'progress' => 0, 'is_complete' => false ];
                $progress = $course_data['progress'];
                $is_complete = $course_data['is_complete'];
                $button_text = $is_complete ? __( 'View Results', 'question-press' ) : __( 'Continue Course', 'question-press' );
                $button_html = sprintf(
                    '<button class="qp-button qp-button-primary qp-view-course-btn" data-course-id="%d" data-course-slug="%s">%s</button>',
                    $course_id,
                    esc_attr( get_post_field( 'post_name', $course_id ) ),
                    esc_html( $button_text )
                );
            } else {
                // Get the post status
                $course_status = get_post_status( $course_id );

                // If the course is expired, show a disabled button
                if ( $course_status === 'expired' ) {
                    $button_html = sprintf(
                        '<button class="qp-button" disabled>%s</button>',
                        __( 'Course Expired', 'question-press' )
                    );
                }

                elseif ( $access_mode === 'free' ) { // <-- Make sure to add 'else' here
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
            }
            ?>
            <div class="qp-card qp-course-item <?php echo $is_enrolled ? 'qp-enrolled' : 'qp-available'; ?>">
                <div class="qp-card-content">
                    <h3 style="margin-top:0;"><?php the_title(); ?></h3>
                    <?php if ( $is_enrolled ): ?>
                        <div class="qp-progress-bar-container" title="<?php echo esc_attr( $progress ); ?>% Complete">
                            <div class="qp-progress-bar-fill" style="width: <?php echo esc_attr( $progress ); ?>%;"></div>
                        </div>
                    <?php endif; ?>
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

    <?php // --- Display "No courses" messages --- ?>
    <?php if ( ! $found_enrolled && ! $found_available ) : ?>
        <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;"><?php esc_html_e( 'No courses are available at the moment.', 'question-press' ); ?></p></div></div>
    <?php elseif ( ! $found_available && $found_enrolled ) : ?>
        <div class="qp-card qp-available-courses"><div class="qp-card-content"><p style="text-align: center;"><?php esc_html_e( 'You are enrolled in all available courses.', 'question-press' ); ?></p></div></div>
    <?php elseif ( ! $found_enrolled && $found_available ) : ?>
        <script>
            jQuery(document).ready(function($) {
                if ($('.qp-enrolled').length === 0) {
                    $('.qp-available-courses').before('<h2><?php echo esc_js( __( 'Available Courses', 'question-press' ) ); ?></h2><hr class="qp-divider" style="margin: 0 0 1.5rem 0;">');
                }
            });
        </script>
    <?php endif; ?>

<?php else : // No courses query results at all ?>
    <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;"><?php esc_html_e( 'No courses are available at the moment.', 'question-press' ); ?></p></div></div>
<?php endif; ?>

<?php // --- Add CSS specific for this new section --- ?>
<style>
    .qp-course-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
    .qp-course-item .qp-card-content p { color: var(--qp-dashboard-text-light); font-size: 0.95em; line-height: 1.6; margin-bottom: 1rem; }
    .qp-progress-bar-container { height: 8px; background-color: var(--qp-dashboard-border-light); border-radius: 4px; overflow: hidden; margin-bottom: 1rem; }
    .qp-progress-bar-fill { height: 100%; background-color: var(--qp-dashboard-success); transition: width 0.5s ease-in-out; border-radius: 4px; }
</style>