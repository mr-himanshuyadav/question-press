<?php
/**
 * Template for the Admin Tools > Import page.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var array $all_labels Array of label objects (term_id, name).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Import Questions', 'question-press' ); ?></h1>
    <hr class="wp-header-end">

    <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 1rem;">
        <div style="flex: 2; min-width: 300px;">
            <h3><?php esc_html_e( 'Upload Question Package', 'question-press' ); ?></h3>
            <p><?php esc_html_e( 'Upload a .zip file containing a questions.json file to import questions into the database.', 'question-press' ); ?></p>

            <form method="post" action="admin.php?page=qp-tools&tab=import" enctype="multipart/form-data">
                <?php wp_nonce_field( 'qp_import_nonce_action', 'qp_import_nonce_field' ); ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th><label for="question_zip_file"><?php esc_html_e( 'Question Package (.zip)', 'question-press' ); ?></label></th>
                            <td><input type="file" name="question_zip_file" id="question_zip_file" accept=".zip,application/zip,application/x-zip,application/x-zip-compressed" required></td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Apply Labels (Optional)', 'question-press' ); ?></label></th>
                            <td>
                                <div class="labels-group" style="padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 150px; overflow-y: auto;">
                                    <?php if ( ! empty( $all_labels ) ) : foreach ( $all_labels as $label ) : ?>
                                            <label class="inline-checkbox" style="display: block; margin-bottom: 5px;">
                                                <input type="checkbox" name="labels_to_apply[]" value="<?php echo esc_attr( $label->term_id ); ?>">
                                                <?php echo esc_html( $label->name ); ?>
                                            </label>
                                        <?php endforeach;
                                    else : ?>
                                        <p><?php esc_html_e( 'No labels found. You can create them on the Labels page.', 'question-press' ); ?></p>
                                    <?php endif; ?>
                                </div>
                                <p class="description"><?php esc_html_e( 'Select labels to apply to all questions imported in this batch.', 'question-press' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Upload and Import', 'question-press' ), 'primary', 'submit' ); // Added 'submit' as name ?>
            </form>
        </div>
        <div style="flex: 1; min-width: 250px; padding: 1rem; background-color: #f6f7f7; border: 1px solid #ddd;">
            <h3><?php esc_html_e( 'Download Creation Tool', 'question-press' ); ?></h3>
            <p><?php esc_html_e( 'To create the question package, you can use our Python script. You will need to have Python installed on your computer to run it.', 'question-press' ); ?></p>
            <p>
                <a href="<?php echo esc_url( QP_PLUGIN_URL . 'tools/question_press_kit.py' ); ?>" class="button button-secondary" download>
                    <span class="dashicons dashicons-download" style="line-height: 1.5;"></span>
                    <?php esc_html_e( 'Download Question Press Kit', 'question-press' ); ?>
                </a>
            </p>
        </div>
    </div>

    <div style="margin-top: 2rem;">
        <details>
            <summary style="font-size: 1.2em; cursor: pointer; font-weight: bold;"><?php esc_html_e( 'View Required JSON Schema (v3.2)', 'question-press' ); ?></summary>
            <div style="background: #fff; border: 1px solid #ddd; padding: 1rem; margin-top: 1rem;">
                <p><?php esc_html_e( 'Your questions.json file must follow this structure. Note the new hierarchical format for sources.', 'question-press' ); ?></p>
                <pre style="background: #fdf6e3; color: #657b83; padding: 1rem; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word;"><?php
                        echo esc_html( '{
    "schemaVersion": "3.2",
    "exportTimestamp": "2025-08-02T10:15:00Z",
    "questionGroups": [
        {
            "groupId": "unique-group-id-1",
            "subject": [
                "Science",
                "Physics",
                "Wave Optics"
            ],
            "source": [
                "University Physics Vol. 3",
                "Chapter 5: Interference"
            ],
            "isPYQ": true,
            "examName": "NEET",
            "pyqYear": "2022",
            "Direction": {
                "text": "The following questions relate to wave optics.",
                "image": "optional_image_filename.png"
            },
            "questions": [
                {
                    "questionId": "unique-question-id-1",
                    "questionText": "What is constructive interference?",
                    "questionNumber": "5",
                    "options": [
                        { "optionText": "...", "isCorrect": true },
                        { "optionText": "...", "isCorrect": false }
                    ]
                }
            ]
        }
    ]
}' );
                ?></pre>
            </div>
        </details>
    </div>
</div>