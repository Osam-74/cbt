<?php

namespace EduCBTPro\Frontend;

use EduCBTPro\Services\ExamAttemptService;
use EduCBTPro\Services\ExamService;
use EduCBTPro\Services\ExamTimetableService;
use EduCBTPro\Core\Repository\StudentRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend Exam Portal — conversational one-question-at-a-time interface.
 *
 * Shortcode: [educbt_exam_portal]
 * Renders the full CBT exam UI with timer, question navigator,
 * autosave, prev/next navigation, and submit.
 */
class ExamPortal {

    public static function register(): void {
        add_shortcode( 'educbt_exam_portal', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style(
            'educbt-exam-portal',
            plugins_url( 'assets/css/exam-portal.css', EDUCBT_PRO_FILE ),
            [],
            '4.0.0'
        );

        wp_enqueue_script(
            'educbt-exam-portal',
            plugins_url( 'assets/js/exam-portal.js', EDUCBT_PRO_FILE ),
            [ 'jquery' ],
            '4.0.0',
            true
        );

        wp_localize_script( 'educbt-exam-portal', 'educbtExamPortal', [
            'restUrl'    => esc_url_raw( rest_url( 'educbt-pro/v1' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'autoSaveMs' => 5000,
            'i18n'       => [
                'confirmSubmit'    => __( 'Are you sure you want to submit? You cannot change your answers after submission.', 'educbt-pro' ),
                'timeUp'           => __( 'Time is up! Your exam is being submitted automatically.', 'educbt-pro' ),
                'saving'            => __( 'Saving...', 'educbt-pro' ),
                'saved'             => __( 'Saved', 'educbt-pro' ),
                'saveFailed'        => __( 'Save failed — will retry', 'educbt-pro' ),
                'noExamSelected'    => __( 'No active exam attempt found. Please start an exam from your student dashboard.', 'educbt-pro' ),
                'loadingExam'       => __( 'Loading exam...', 'educbt-pro' ),
                'loadFailed'        => __( 'Failed to load exam. Please refresh or contact your administrator.', 'educbt-pro' ),
                'question'          => __( 'Question', 'educbt-pro' ),
                'of'                => __( 'of', 'educbt-pro' ),
                'prev'              => __( 'Previous', 'educbt-pro' ),
                'next'              => __( 'Next', 'educbt-pro' ),
                'submitExam'        => __( 'Submit Exam', 'educbt-pro' ),
                'answered'          => __( 'Answered', 'educbt-pro' ),
                'unanswered'        => __( 'Unanswered', 'educbt-pro' ),
                'reviewAnswers'     => __( 'Review Answers', 'educbt-pro' ),
                'examSubmitted'     => __( 'Exam submitted successfully!', 'educbt-pro' ),
                'scoreLabel'        => __( 'Score', 'educbt-pro' ),
                'gradeLabel'        => __( 'Grade', 'educbt-pro' ),
            ],
        ] );
    }

    /**
     * Shortcode renderer — outputs the exam portal container.
     * The JS takes over from here.
     */
    public static function render_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [
            'attempt_id' => 0,
        ], $atts, 'educbt_exam_portal' );

        $attempt_id = absint( $atts['attempt_id'] );
        if ( $attempt_id <= 0 && isset( $_GET['attempt_id'] ) ) {
            $attempt_id = absint( $_GET['attempt_id'] );
        }

        ob_start();
        ?>
        <div id="educbt-exam-portal" class="educbt-exam-portal"
             data-attempt-id="<?php echo esc_attr( (string) $attempt_id ); ?>">
            <!-- Loading state -->
            <div class="educbt-exam-loading">
                <div class="educbt-exam-spinner"></div>
                <p><?php esc_html_e( 'Loading exam...', 'educbt-pro' ); ?></p>
            </div>
            <!-- Exam UI is rendered by JS -->
        </div>
        <?php
        return ob_get_clean();
    }
}
