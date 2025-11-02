<?php
/**
 * Template for the Dashboard "My Courses" Tab content.
 *
 * @package QuestionPress/Templates/Frontend/Dashboard
 *
 * @var WP_Query $enrolled_courses_query       WP_Query object for enrolled courses.
 * @var array    $enrolled_courses_data        Array of progress data for enrolled courses.
 * @var array    $purchased_not_enrolled_posts Array of post objects for purchased, non-enrolled courses.
 * @var int      $user_id                      The current user ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>

<?php // --- SECTION 1: ENROLLED COURSES --- ?>
<h2><?php esc_html_e( 'My Enrolled Courses', 'question-press' ); ?></h2>

<?php if ( $enrolled_courses_query->have_posts() ) : ?>
    <div class="qp-course-list">
        <?php
        while ( $enrolled_courses_query->have_posts() ) : $enrolled_courses_query->the_post();
            $course_id = get_the_ID();
            
            // Data for this course
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
            ?>
            <div class="qp-card qp-course-item qp-enrolled">
                <div class="qp-card-content">
                    <h3 style="margin-top:0;"><?php the_title(); ?></h3>
                    <div class="qp-progress-bar-container" title="<?php echo esc_attr( $progress ); ?>% Complete">
                        <div class="qp-progress-bar-fill" style="width: <?php echo esc_attr( $progress ); ?>%;"></div>
                    </div>
                    <?php if ( has_excerpt() ) : ?>
                        <p><?php the_excerpt(); ?></p>
                    <?php else : ?>
                        <?php echo '<p>' . wp_trim_words( get_the_content(), 30, '...' ) . '</p>'; ?>
                    <?php endif; ?>
                </div>
                <div class="qp-card-action" style="padding: 1rem 1.5rem; border-top: 1px solid var(--qp-dashboard-border-light); text-align: right;">
                    <?php echo $button_html; ?>
                </div>
            </div>
        <?php endwhile; wp_reset_postdata(); ?>
    </div> <?php // End qp-course-list ?>
<?php else : ?>
    <div class="qp-card"><div class="qp-card-content"><p style="text-align: center;"><?php esc_html_e( 'You are not currently enrolled in any courses.', 'question-press' ); ?></p></div></div>
<?php endif; ?>


<?php // --- SECTION 2: PURCHASED (NOT ENROLLED) COURSES --- ?>
<?php if ( ! empty( $purchased_not_enrolled_posts ) ) : ?>
    
    <hr class="qp-divider" style="margin: 2rem 0;">
    <h2 style="margin-bottom: 1.5rem;"><?php esc_html_e( 'My Purchased Courses', 'question-press' ); ?></h2>
    
    <div class="qp-course-list">
        <?php
        foreach ( $purchased_not_enrolled_posts as $course_post ) :
            $course_id = $course_post->ID;
            
            // Button will always be "Enroll Now"
            $button_html = sprintf(
                '<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>',
                $course_id,
                __( 'Enroll Now (Purchased)', 'question-press' )
            );
        ?>
        <div class="qp-card qp-course-item qp-available">
            <div class="qp-card-content">
                <h3 style="margin-top:0;"><?php echo esc_html($course_post->post_title); ?></h3>
                <?php if ( has_excerpt($course_id) ) : ?>
                    <p><?php echo esc_html(get_the_excerpt($course_id)); ?></p>
                <?php else : ?>
                    <?php echo '<p>' . wp_trim_words( $course_post->post_content, 30, '...' ) . '</p>'; ?>
                <?php endif; ?>
            </div>
            <div class="qp-card-action" style="padding: 1rem 1.5rem; border-top: 1px solid var(--qp-dashboard-border-light); text-align: right;">
                <?php echo $button_html; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php // --- STYLES --- ?>
<style>
    .qp-course-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
    .qp-course-item .qp-card-content p { color: var(--qp-dashboard-text-light); font-size: 0.95em; line-height: 1.6; margin-bottom: 1rem; }
    .qp-progress-bar-container { height: 8px; background-color: var(--qp-dashboard-border-light); border-radius: 4px; overflow: hidden; margin-bottom: 1rem; }
    .qp-progress-bar-fill { height: 100%; background-color: var(--qp-dashboard-success); transition: width 0.5s ease-in-out; border-radius: 4px; }
</style>