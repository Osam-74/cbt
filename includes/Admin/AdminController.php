<?php

namespace EduCBTPro\Admin;

use EduCBTPro\Core\TenantContext;
use EduCBTPro\Services\SchoolService;
use EduCBTPro\Services\TeacherService;
use EduCBTPro\Services\SubjectService;
use EduCBTPro\Services\QuestionService;
use EduCBTPro\Services\QuestionAnalyticsService;
use EduCBTPro\Services\ExamService;
use EduCBTPro\Services\ResultService;
use EduCBTPro\Services\PromotionService;
use EduCBTPro\Services\TranscriptService;
use EduCBTPro\Services\AuditLogService;
use EduCBTPro\Services\StudentService;
use EduCBTPro\Services\ClassService;
use EduCBTPro\Services\ExamTimetableService;
use EduCBTPro\Services\ExamIntegrityEventService;
use EduCBTPro\Services\NotificationService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminController {
    private TenantContext $tenant_context;
    private SchoolService $school_service;
    private TeacherService $teacher_service;
    private SubjectService $subject_service;
    private QuestionService $question_service;
    private QuestionAnalyticsService $question_analytics_service;
    private ExamService $exam_service;
    private ResultService $result_service;
    private PromotionService $promotion_service;
    private TranscriptService $transcript_service;
    private AuditLogService $audit_log_service;
    private StudentService $student_service;
    private ClassService $class_service;
    private ExamTimetableService $exam_timetable_service;
    private ExamIntegrityEventService $exam_integrity_event_service;
    private NotificationService $notification_service;

    public function __construct( TenantContext $tenant_context ) {
        $this->tenant_context   = $tenant_context;
        $this->school_service   = new SchoolService();
        $this->teacher_service  = new TeacherService();
        $this->subject_service  = new SubjectService();
        $this->question_service = new QuestionService();
        $this->question_analytics_service = new QuestionAnalyticsService();
        $this->exam_service      = new ExamService();
        $this->result_service    = new ResultService();
        $this->promotion_service = new PromotionService();
        $this->transcript_service= new TranscriptService();
        $this->audit_log_service = new AuditLogService();
        $this->student_service   = new StudentService();
        $this->class_service     = new ClassService();
        $this->exam_timetable_service = new ExamTimetableService();
        $this->exam_integrity_event_service = new ExamIntegrityEventService();
        $this->notification_service = new NotificationService();

        add_action( 'admin_post_educbt_download_question_template', [ $this, 'download_question_template' ] );
        add_action( 'admin_post_educbt_download_student_template', [ $this, 'download_student_template' ] );
    }

    public function register_admin_pages(): void {
        // The v1 menus write straight to the v1 tables and bypass every rule the
        // service layer enforces — no generated identifiers, no capability scope,
        // no validation. Creating a school here, for instance, produces a school
        // with no code, no subdomain, no principal account and no academic
        // structure, which then fails everywhere downstream.
        //
        // They are hidden by default. Re-enable as a migration escape hatch with:
        //     define( 'EDUCBT_LEGACY_ADMIN', true );   // wp-config.php
        if ( ! defined( 'EDUCBT_LEGACY_ADMIN' ) || ! EDUCBT_LEGACY_ADMIN ) {
            return;
        }

        add_menu_page(
            __( 'EduCBT Pro', 'educbt-pro' ),
            __( 'EduCBT Pro', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-dashboard',
            [ $this, 'render_dashboard_page' ],
            'dashicons-welcome-learn-more',
            6
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Schools', 'educbt-pro' ),
            __( 'Schools', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-schools',
            [ $this, 'render_schools_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Teachers', 'educbt-pro' ),
            __( 'Teachers', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-teachers',
            [ $this, 'render_teachers_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Students', 'educbt-pro' ),
            __( 'Students', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-students',
            [ $this, 'render_students_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Classes', 'educbt-pro' ),
            __( 'Classes', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-classes',
            [ $this, 'render_classes_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Subjects', 'educbt-pro' ),
            __( 'Subjects', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-subjects',
            [ $this, 'render_subjects_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Questions', 'educbt-pro' ),
            __( 'Questions', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-questions',
            [ $this, 'render_questions_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Question Verification', 'educbt-pro' ),
            __( 'Question Verification', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-question-verification',
            [ $this, 'render_question_submission_verification_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Exams', 'educbt-pro' ),
            __( 'Exams', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-exams',
            [ $this, 'render_exams_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Exam Timetable', 'educbt-pro' ),
            __( 'Exam Timetable', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-exam-timetable',
            [ $this, 'render_exam_timetable_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Integrity Events', 'educbt-pro' ),
            __( 'Integrity Events', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-integrity-events',
            [ $this, 'render_integrity_events_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Results', 'educbt-pro' ),
            __( 'Results', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-results',
            [ $this, 'render_results_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Promotions', 'educbt-pro' ),
            __( 'Promotions', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-promotions',
            [ $this, 'render_promotions_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Transcripts', 'educbt-pro' ),
            __( 'Transcripts', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-transcripts',
            [ $this, 'render_transcripts_page' ]
        );

        add_submenu_page(
            'educbt-pro-dashboard',
            __( 'Audit Logs', 'educbt-pro' ),
            __( 'Audit Logs', 'educbt-pro' ),
            'manage_options',
            'educbt-pro-audit-logs',
            [ $this, 'render_audit_logs_page' ]
        );

    }

    public function render_dashboard_page(): void {
        global $wpdb;

        $school_id = $this->get_current_school_id();
        $results_table = $wpdb->prefix . 'educbt_results';
        $students_table = $wpdb->prefix . 'educbt_students';

        $subject_rows = [];
        $class_rows = [];
        $today_integrity_counts = [];

        if ( $school_id > 0 ) {
            $subject_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT subject, AVG(score) AS avg_score FROM {$results_table} WHERE school_id = %d GROUP BY subject ORDER BY avg_score DESC LIMIT 8",
                    $school_id
                ),
                ARRAY_A
            ) ?: [];

            $class_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.class AS class_name, AVG(r.score) AS avg_score
                    FROM {$results_table} r
                    INNER JOIN {$students_table} s ON s.id = r.student_id
                    WHERE r.school_id = %d AND s.school_id = %d
                    GROUP BY s.class
                    ORDER BY avg_score DESC
                    LIMIT 8",
                    $school_id,
                    $school_id
                ),
                ARRAY_A
            ) ?: [];

            $today_integrity_events = $this->exam_integrity_event_service->list_events(
                $school_id,
                [
                    'date_from' => current_time( 'Y-m-d' ) . ' 00:00:00',
                    'date_to' => current_time( 'Y-m-d' ) . ' 23:59:59',
                    'limit' => 500,
                ]
            );

            foreach ( $today_integrity_events as $event ) {
                $event_type = sanitize_text_field( (string) ( $event['event_type'] ?? 'unknown' ) );
                if ( ! isset( $today_integrity_counts[ $event_type ] ) ) {
                    $today_integrity_counts[ $event_type ] = 0;
                }

                $today_integrity_counts[ $event_type ]++;
            }

            arsort( $today_integrity_counts );
        }

        echo '<div class="wrap"><h1>' . esc_html__( 'EduCBT Pro Dashboard', 'educbt-pro' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to EduCBT Pro. Use the menu to manage schools, users, and academic resources.', 'educbt-pro' ) . '</p>';
        $this->render_school_selector();

        echo '<h2>' . esc_html__( 'Subject Analytics (Average %)', 'educbt-pro' ) . '</h2>';
        $this->render_percentage_bars( $subject_rows, 'subject' );

        echo '<h2>' . esc_html__( 'Class Analytics (Average %)', 'educbt-pro' ) . '</h2>';
        $this->render_percentage_bars( $class_rows, 'class_name' );

        echo '<h2>' . esc_html__( 'Today\'s Integrity Signals', 'educbt-pro' ) . '</h2>';
        if ( empty( $today_integrity_counts ) ) {
            echo '<p>' . esc_html__( 'No integrity events recorded today for the selected school.', 'educbt-pro' ) . '</p>';
        } else {
            echo '<div style="max-width:780px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;">';
            echo '<p><strong>' . esc_html__( 'Total Events Today:', 'educbt-pro' ) . '</strong> ' . esc_html( (string) array_sum( $today_integrity_counts ) ) . '</p>';
            echo '<ul style="margin:0 0 0 18px;">';
            foreach ( $today_integrity_counts as $event_type => $count ) {
                echo '<li>' . esc_html( $event_type ) . ': ' . esc_html( (string) $count ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function render_percentage_bars( array $rows, string $label_key ): void {
        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No analytics data yet for the selected school.', 'educbt-pro' ) . '</p>';
            return;
        }

        echo '<div style="max-width:780px;">';
        foreach ( $rows as $row ) {
            $label = sanitize_text_field( (string) ( $row[ $label_key ] ?? '' ) );
            $avg = round( (float) ( $row['avg_score'] ?? 0 ), 2 );
            $width = max( 0, min( 100, $avg ) );

            echo '<div style="margin:8px 0;">';
            echo '<div style="display:flex;justify-content:space-between;"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( (string) $avg ) . '%</span></div>';
            echo '<div style="background:#eef2f8;border-radius:10px;height:12px;overflow:hidden;">';
            echo '<div style="width:' . esc_attr( (string) $width ) . '%;height:12px;background:#0a6fb7;"></div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function get_current_school_id(): int {
        $this->sync_school_context_from_request();

        $school_id = $this->tenant_context->get_school_id() ?? 0;
        if ( $school_id > 0 ) {
            return $school_id;
        }

        $schools = $this->school_service->list_schools();
        if ( count( $schools ) === 1 ) {
            $school_id = absint( $schools[0]['id'] ?? 0 );
            if ( $school_id > 0 ) {
                $this->tenant_context->set_school_id( $school_id );
                return $school_id;
            }
        }

        return 0;
    }

    /**
     * PHASE 0 SECURITY FIX.
     *
     * This previously accepted the active school from $_REQUEST['school_id'] and from
     * an unsigned `educbt_school_id` cookie, then called set_school_id() directly.
     * Combined with the old TenantContext resolver, that let any request choose which
     * school's data it operated on.
     *
     * The switcher is now a nonce-protected POST, gated on manage_options, routed
     * through TenantContext::switch_school() so the choice is stored server-side in
     * user meta and written to the audit log.
     */
    private function sync_school_context_from_request(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['educbt_pro_action'] ) ) {
            return;
        }

        if ( sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) !== 'select_school' ) {
            return;
        }

        if ( ! isset( $_POST['educbt_pro_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_select_school' ) ) {
            return;
        }

        $selected_school_id = absint( wp_unslash( $_POST['selected_school_id'] ?? 0 ) );
        if ( $selected_school_id <= 0 ) {
            return;
        }

        $this->tenant_context->switch_school( $selected_school_id );
    }

    private function render_school_selector(): void {
        $schools = $this->school_service->list_schools();
        if ( empty( $schools ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Create at least one school before adding teachers, students, classes, subjects, questions, exams, and results.', 'educbt-pro' ) . '</p></div>';
            return;
        }

        $current_school_id = $this->get_current_school_id();

        echo '<form method="post" action="" style="margin:12px 0 16px;">';
        wp_nonce_field( 'educbt_pro_select_school', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="select_school">';
        echo '<label for="selected_school_id" style="font-weight:600; margin-right:8px;">' . esc_html__( 'Current School Context', 'educbt-pro' ) . ':</label>';
        echo '<select id="selected_school_id" name="selected_school_id">';

        foreach ( $schools as $school ) {
            $id = absint( $school['id'] ?? 0 );
            $name = sanitize_text_field( $school['school_name'] ?? '' );
            echo '<option value="' . esc_attr( (string) $id ) . '" ' . selected( $current_school_id, $id, false ) . '>' . esc_html( $name . ' (#' . $id . ')' ) . '</option>';
        }

        echo '</select> ';
        submit_button( __( 'Switch', 'educbt-pro' ), 'secondary', 'submit', false );
        echo '</form>';
    }

    public function render_schools_page(): void {
        $message = '';
        $current_school_id = $this->get_current_school_id();

        if ( isset( $_POST['educbt_pro_action'] ) && 'save_school_academic_preferences' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_save_school_academic_preferences' ) ) {
                $target_school_id = absint( wp_unslash( $_POST['school_context_id'] ?? $current_school_id ) );
                $updated = $this->school_service->update_school_academic_preferences(
                    $target_school_id,
                    [
                        'registration_number_format' => sanitize_text_field( wp_unslash( $_POST['registration_number_format'] ?? '' ) ),
                        'minimum_questions_per_subject' => absint( wp_unslash( $_POST['minimum_questions_per_subject'] ?? 20 ) ),
                    ]
                );

                $message = $updated
                    ? esc_html__( 'School academic preferences saved successfully.', 'educbt-pro' )
                    : esc_html__( 'Unable to save school academic preferences.', 'educbt-pro' );
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_school' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_school' ) ) {
                $school_data = [
                    'school_name'    => sanitize_text_field( wp_unslash( $_POST['school_name'] ?? '' ) ),
                    'school_code'    => sanitize_text_field( wp_unslash( $_POST['school_code'] ?? '' ) ),
                    'address'        => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
                    'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
                    'email'          => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
                    'website'        => esc_url_raw( wp_unslash( $_POST['website'] ?? '' ) ),
                    'principal_name' => sanitize_text_field( wp_unslash( $_POST['principal_name'] ?? '' ) ),
                ];

                $school_data['academic_settings'] = [
                    'registration_number_format' => sanitize_text_field( wp_unslash( $_POST['registration_number_format'] ?? '{school_code}-{year}-{class}-{sequence}' ) ),
                    'minimum_questions_per_subject' => max( 1, absint( wp_unslash( $_POST['minimum_questions_per_subject'] ?? 20 ) ) ),
                ];

                $school_id = $this->school_service->create_school( $school_data );
                if ( $school_id ) {
                    $this->tenant_context->set_school_id( $school_id );
                    $message = esc_html__( 'School created successfully.', 'educbt-pro' );
                } else {
                    $message = esc_html__( 'Unable to create school. Please check the inputs and try again.', 'educbt-pro' );
                }
            }
        }

        $schools = $this->school_service->list_schools();
        $active_academic_settings = $current_school_id > 0
            ? $this->school_service->get_school_academic_settings( $current_school_id )
            : $this->school_service->get_school_academic_settings( 0 );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Schools', 'educbt-pro' ) . '</h1>';

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Create New School', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_school', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_school">';
        echo '<table class="form-table">';
        $this->render_input_row( 'school_name', __( 'School Name', 'educbt-pro' ) );
        $this->render_input_row( 'school_code', __( 'School Code', 'educbt-pro' ) );
        $this->render_input_row( 'registration_number_format', __( 'Registration Number Format', 'educbt-pro' ) );
        $this->render_input_row( 'minimum_questions_per_subject', __( 'Minimum Questions Per Subject', 'educbt-pro' ), 'number' );
        $this->render_textarea_row( 'address', __( 'Address', 'educbt-pro' ) );
        $this->render_input_row( 'phone', __( 'Phone', 'educbt-pro' ) );
        $this->render_input_row( 'email', __( 'Email', 'educbt-pro' ), 'email' );
        $this->render_input_row( 'website', __( 'Website', 'educbt-pro' ), 'url' );
        $this->render_input_row( 'principal_name', __( 'Principal Name', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Create School', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Active School Academic Preferences', 'educbt-pro' ) . '</h2>';
        if ( $current_school_id <= 0 ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Select a school context to edit academic preferences.', 'educbt-pro' ) . '</p></div>';
        } else {
            echo '<form method="post" action="">';
            wp_nonce_field( 'educbt_pro_save_school_academic_preferences', 'educbt_pro_nonce' );
            echo '<input type="hidden" name="educbt_pro_action" value="save_school_academic_preferences">';
            echo '<input type="hidden" name="school_context_id" value="' . esc_attr( (string) $current_school_id ) . '">';
            echo '<table class="form-table">';
            $this->render_input_row_with_value( 'registration_number_format', __( 'Registration Number Format', 'educbt-pro' ), (string) ( $active_academic_settings['registration_number_format'] ?? '{school_code}-{year}-{class}-{sequence}' ) );
            $this->render_input_row_with_value( 'minimum_questions_per_subject', __( 'Minimum Questions Per Subject', 'educbt-pro' ), (string) absint( $active_academic_settings['minimum_questions_per_subject'] ?? 20 ), 'number' );
            echo '</table>';
            submit_button( __( 'Save Academic Preferences', 'educbt-pro' ) );
            echo '</form>';
        }

        echo '<h2>' . esc_html__( 'Registered Schools', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'School Name', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Code', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Email', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Phone', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $schools ) ) {
            echo '<tr><td colspan="5">' . esc_html__( 'No schools registered yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $schools as $school ) {
                echo '<tr>';
                echo '<td>' . esc_html( $school['id'] ) . '</td>';
                echo '<td>' . esc_html( $school['school_name'] ) . '</td>';
                echo '<td>' . esc_html( $school['school_code'] ) . '</td>';
                echo '<td>' . esc_html( $school['email'] ) . '</td>';
                echo '<td>' . esc_html( $school['phone'] ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_students_page(): void {
        $message = '';
        $preview_report = [];
        $current_school_id = $this->get_current_school_id();

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_student' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_student' ) ) {
                $student_data = [
                    'registration_number' => sanitize_text_field( wp_unslash( $_POST['registration_number'] ?? '' ) ),
                    'admission_number'   => sanitize_text_field( wp_unslash( $_POST['admission_number'] ?? '' ) ),
                    'student_id'         => sanitize_text_field( wp_unslash( $_POST['student_id'] ?? '' ) ),
                    'first_name'         => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
                    'last_name'          => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
                    'passport_photo'     => sanitize_text_field( wp_unslash( $_POST['passport_photo'] ?? '' ) ),
                    'full_name'          => sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) ),
                    'gender'             => sanitize_text_field( wp_unslash( $_POST['gender'] ?? '' ) ),
                    'date_of_birth'      => sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) ),
                    'parent_information' => sanitize_textarea_field( wp_unslash( $_POST['parent_information'] ?? '' ) ),
                    'parent_phone'       => sanitize_text_field( wp_unslash( $_POST['parent_phone'] ?? '' ) ),
                    'parent_email'       => sanitize_email( wp_unslash( $_POST['parent_email'] ?? '' ) ),
                    'address'            => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
                    'class'              => sanitize_text_field( wp_unslash( $_POST['class'] ?? '' ) ),
                    'arm'                => sanitize_text_field( wp_unslash( $_POST['arm'] ?? '' ) ),
                    'department'         => sanitize_text_field( wp_unslash( $_POST['department'] ?? '' ) ),
                    'session_year'       => sanitize_text_field( wp_unslash( $_POST['session_year'] ?? '' ) ),
                    'status'             => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) ),
                    'login_username'     => sanitize_user( wp_unslash( $_POST['login_username'] ?? '' ) ),
                    'temporary_password' => wp_unslash( $_POST['temporary_password'] ?? '' ),
                    'manual_subjects'    => $this->convert_csv_to_array( wp_unslash( $_POST['manual_subjects'] ?? '' ) ),
                ];

                if ( $current_school_id <= 0 ) {
                    $message = esc_html__( 'Select a school context before adding students.', 'educbt-pro' );
                } else {
                    $student_id = $this->student_service->create_student( $current_school_id, $student_data );
                    $message = $student_id ? esc_html__( 'Student added successfully.', 'educbt-pro' ) : esc_html__( 'Unable to add student.', 'educbt-pro' );
                }
            }
        }

        if ( isset( $_POST['educbt_pro_student_csv_action'] ) ) {
            $student_csv_action = sanitize_text_field( wp_unslash( $_POST['educbt_pro_student_csv_action'] ) );
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_upload_students_csv' ) ) {
                if ( $student_csv_action === 'preview_students_csv' ) {
                    $preview_report = $this->preview_students_csv_upload( $current_school_id );
                    $message = sprintf(
                        esc_html__( 'CSV preview completed. Processed: %1$d. Suggested subject mappings: %2$d.', 'educbt-pro' ),
                        absint( $preview_report['processed'] ?? 0 ),
                        count( (array) ( $preview_report['subject_alias_suggestions'] ?? [] ) )
                    );
                }

                if ( $student_csv_action === 'upload_students_csv' ) {
                    $import_report = $this->handle_students_csv_upload( $current_school_id );
                    $message = sprintf(
                        esc_html__( 'Student CSV upload completed. Processed: %1$d. Imported: %2$d. Duplicates skipped: %3$d. Failed/empty rows: %4$d.', 'educbt-pro' ),
                        absint( $import_report['processed'] ?? 0 ),
                        absint( $import_report['imported'] ?? 0 ),
                        absint( $import_report['duplicates'] ?? 0 ),
                        absint( $import_report['failed'] ?? 0 )
                    );

                    $preview_report = $import_report;
                    if ( ! empty( $preview_report['subject_alias_suggestions'] ) ) {
                        $message .= ' ' . sprintf(
                            esc_html__( 'Detected %d unrecognized subject variant(s). Review the suggestions below before the next import.', 'educbt-pro' ),
                            count( (array) $preview_report['subject_alias_suggestions'] )
                        );
                    }
                }
            }
        }

        $students = $this->student_service->list_students( $current_school_id );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Students', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Add Student', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_student', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_student">';
        echo '<table class="form-table">';
        $this->render_input_row( 'registration_number', __( 'Registration Number', 'educbt-pro' ) );
        $this->render_input_row( 'student_id', __( 'Student ID', 'educbt-pro' ) );
        $this->render_input_row( 'first_name', __( 'First Name', 'educbt-pro' ) );
        $this->render_input_row( 'last_name', __( 'Last Name', 'educbt-pro' ) );
        $this->render_input_row( 'full_name', __( 'Full Name', 'educbt-pro' ) );
        $this->render_input_row( 'gender', __( 'Gender', 'educbt-pro' ) );
        $this->render_input_row( 'date_of_birth', __( 'Date of Birth', 'educbt-pro' ), 'date' );
        $this->render_textarea_row( 'parent_information', __( 'Parent Information', 'educbt-pro' ) );
        $this->render_input_row( 'parent_phone', __( 'Parent Phone', 'educbt-pro' ) );
        $this->render_input_row( 'parent_email', __( 'Parent Email', 'educbt-pro' ), 'email' );
        $this->render_textarea_row( 'address', __( 'Address', 'educbt-pro' ) );
        $this->render_input_row( 'class', __( 'Class', 'educbt-pro' ) );
        $this->render_input_row( 'arm', __( 'Arm', 'educbt-pro' ) );
        $this->render_input_row( 'department', __( 'Department (Science/Commercial/Arts)', 'educbt-pro' ) );
        $this->render_input_row( 'session_year', __( 'Session Year', 'educbt-pro' ) );
        $this->render_input_row( 'login_username', __( 'Login Username (optional)', 'educbt-pro' ) );
        $this->render_input_row( 'temporary_password', __( 'Temporary Password (optional)', 'educbt-pro' ) );
        $this->render_input_row( 'manual_subjects', __( 'Manual Subject Override (comma separated, optional)', 'educbt-pro' ) );
        $this->render_input_row( 'status', __( 'Status', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Add Student', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Bulk Upload Students (CSV)', 'educbt-pro' ) . '</h2>';
        echo '<p><a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=educbt_download_student_template' ), 'educbt_download_student_template' ) ) . '">' . esc_html__( 'Download Student CSV Template', 'educbt-pro' ) . '</a></p>';
        echo '<form method="post" action="" enctype="multipart/form-data" style="margin-bottom:20px;">';
        wp_nonce_field( 'educbt_pro_upload_students_csv', 'educbt_pro_nonce' );
        echo '<input type="file" name="students_csv" accept=".csv" required> ';
        echo '<button type="submit" class="button button-secondary" name="educbt_pro_student_csv_action" value="preview_students_csv">' . esc_html__( 'Preview Subject Normalization', 'educbt-pro' ) . '</button> ';
        echo '<button type="submit" class="button button-primary" name="educbt_pro_student_csv_action" value="upload_students_csv">' . esc_html__( 'Upload Student CSV', 'educbt-pro' ) . '</button>';
        echo '</form>';

        if ( ! empty( $preview_report ) ) {
            $subject_alias_suggestions = is_array( $preview_report['subject_alias_suggestions'] ?? null ) ? $preview_report['subject_alias_suggestions'] : [];
            echo '<div style="margin:0 0 20px;padding:14px 16px;border:1px solid #dcdcde;border-radius:8px;background:#fff;">';
            echo '<h3 style="margin-top:0;">' . esc_html__( 'CSV Normalization Preview', 'educbt-pro' ) . '</h3>';
            echo '<p>' . esc_html__( 'Review suggested subject aliases before running the import. These suggestions are derived from manual subject entries in the CSV.', 'educbt-pro' ) . '</p>';

            if ( empty( $subject_alias_suggestions ) ) {
                echo '<p><strong>' . esc_html__( 'No subject normalization issues detected.', 'educbt-pro' ) . '</strong></p>';
            } else {
                echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'Detected Alias', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Suggested Canonical Subject', 'educbt-pro' ) . '</th></tr></thead><tbody>';
                foreach ( $subject_alias_suggestions as $alias => $canonical ) {
                    echo '<tr><td>' . esc_html( (string) $alias ) . '</td><td>' . esc_html( (string) $canonical ) . '</td></tr>';
                }
                echo '</tbody></table>';

                echo '<form method="post" action="" style="margin-top:12px;">';
                wp_nonce_field( 'educbt_pro_save_subject_aliases_from_import', 'educbt_pro_nonce' );
                echo '<input type="hidden" name="educbt_pro_action" value="save_subject_aliases_from_import">';
                $alias_lines = [];
                foreach ( $subject_alias_suggestions as $alias => $canonical ) {
                    $alias_lines[] = sanitize_text_field( (string) $alias ) . ' => ' . sanitize_text_field( (string) $canonical );
                }
                $this->render_textarea_row_with_value( 'suggested_aliases', __( 'Suggested Mappings', 'educbt-pro' ), implode( "\n", $alias_lines ) );
                submit_button( __( 'Save Suggested Mappings', 'educbt-pro' ), 'secondary' );
                echo '</form>';
            }

            echo '</div>';
        }

        echo '<h2>' . esc_html__( 'Registered Students', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Registration #', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Student ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Name', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Class', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Department', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Login', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $students ) ) {
            echo '<tr><td colspan="7">' . esc_html__( 'No students added yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $students as $student ) {
                echo '<tr>';
                echo '<td>' . esc_html( $student['id'] ) . '</td>';
                echo '<td>' . esc_html( $student['admission_number'] ) . '</td>';
                echo '<td>' . esc_html( $student['student_id'] ) . '</td>';
                echo '<td>' . esc_html( $student['full_name'] ) . '</td>';
                echo '<td>' . esc_html( $student['class'] ) . '</td>';
                echo '<td>' . esc_html( $student['department'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $student['login_username'] ?? '' ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_exams_page(): void {
        $message = '';
        if ( isset( $_POST['educbt_pro_action'] ) && 'create_exam' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_exam' ) ) {
                $exam_data = [
                    'title'            => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
                    'exam_type'        => sanitize_text_field( wp_unslash( $_POST['exam_type'] ?? '' ) ),
                    'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                    'start_time'       => sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) ),
                    'end_time'         => sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) ),
                    'duration_minutes' => absint( wp_unslash( $_POST['duration_minutes'] ?? 0 ) ),
                    'is_published'     => isset( $_POST['is_published'] ) ? 1 : 0,
                ];
                $exam_id = $this->exam_service->create_exam( $this->get_current_school_id(), $exam_data );

                if ( $exam_id ) {
                    $question_ids_csv = sanitize_text_field( wp_unslash( $_POST['question_ids'] ?? '' ) );
                    $question_ids = array_map( 'absint', $this->convert_csv_to_array( $question_ids_csv ) );
                    $question_ids = array_values( array_filter( $question_ids ) );

                    if ( ! empty( $question_ids ) ) {
                        $this->exam_service->assign_questions( $this->get_current_school_id(), $exam_id, $question_ids );
                    }
                }

                $message = $exam_id ? esc_html__( 'Exam created successfully.', 'educbt-pro' ) : esc_html__( 'Unable to create exam.', 'educbt-pro' );
            }
        }

        $exams = $this->exam_service->list_exams( $this->get_current_school_id() );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Exams', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();
        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Schedule Exam', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_exam', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_exam">';
        echo '<table class="form-table">';
        $this->render_input_row( 'title', __( 'Title', 'educbt-pro' ) );
        $this->render_input_row( 'exam_type', __( 'Exam Type', 'educbt-pro' ) );
        $this->render_input_row( 'start_time', __( 'Start Time', 'educbt-pro' ), 'datetime-local' );
        $this->render_input_row( 'end_time', __( 'End Time', 'educbt-pro' ), 'datetime-local' );
        $this->render_input_row( 'duration_minutes', __( 'Duration (minutes)', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'question_ids', __( 'Question IDs (comma separated)', 'educbt-pro' ) );
        echo '<tr><th scope="row"><label for="is_published">' . esc_html__( 'Publish exam', 'educbt-pro' ) . '</label></th><td><input name="is_published" id="is_published" type="checkbox" value="1"></td></tr>';
        $this->render_textarea_row( 'description', __( 'Description', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Create Exam', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Exam List', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Title', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Type', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Start', 'educbt-pro' ) . '</th><th>' . esc_html__( 'End', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Published', 'educbt-pro' ) . '</th></tr></thead><tbody>';
        if ( empty( $exams ) ) {
            echo '<tr><td colspan="6">' . esc_html__( 'No exams scheduled yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $exams as $exam ) {
                echo '<tr>';
                echo '<td>' . esc_html( $exam['id'] ) . '</td>';
                echo '<td>' . esc_html( $exam['title'] ) . '</td>';
                echo '<td>' . esc_html( $exam['exam_type'] ) . '</td>';
                echo '<td>' . esc_html( $exam['start_time'] ) . '</td>';
                echo '<td>' . esc_html( $exam['end_time'] ) . '</td>';
                echo '<td>' . ( $exam['is_published'] ? esc_html__( 'Yes', 'educbt-pro' ) : esc_html__( 'No', 'educbt-pro' ) ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_exam_timetable_page(): void {
        $message = '';
        $current_school_id = $this->get_current_school_id();
        $classes = $this->class_service->list_classes( $current_school_id );
        $subjects = $this->subject_service->list_subjects( $current_school_id );
        $exams = $this->exam_service->list_exams( $current_school_id );
        $academic_settings = $this->school_service->get_school_academic_settings( $current_school_id );

        $exam_options = [];
        foreach ( $exams as $exam ) {
            $exam_id = absint( $exam['id'] ?? 0 );
            if ( $exam_id <= 0 ) {
                continue;
            }

            $exam_title = sanitize_text_field( (string) ( $exam['title'] ?? '' ) );
            if ( $exam_title === '' ) {
                $exam_title = 'Exam #' . $exam_id;
            }

            $exam_options[ (string) $exam_id ] = $exam_title . ' (#' . $exam_id . ')';
        }

        $class_options = [];
        $arm_options = [];
        foreach ( $classes as $class_row ) {
            $class_name = sanitize_text_field( (string) ( $class_row['class_name'] ?? '' ) );
            if ( $class_name !== '' ) {
                $class_options[ $class_name ] = $class_name;
            }

            $arm = sanitize_text_field( (string) ( $class_row['arm'] ?? '' ) );
            if ( $arm !== '' ) {
                $arm_options[ $arm ] = $arm;
            }
        }

        if ( empty( $arm_options ) ) {
            $arm_options['A'] = 'A';
        }

        $department_options = [];
        if ( isset( $academic_settings['departments'] ) && is_array( $academic_settings['departments'] ) ) {
            foreach ( array_keys( $academic_settings['departments'] ) as $department_name ) {
                $department_name = sanitize_text_field( (string) $department_name );
                if ( $department_name !== '' ) {
                    $department_options[ $department_name ] = $department_name;
                }
            }
        }

        if ( empty( $department_options ) ) {
            $department_options = [
                'Science' => 'Science',
                'Commercial' => 'Commercial',
                'Arts' => 'Arts',
            ];
        }

        $subject_options = [];
        foreach ( $subjects as $subject_row ) {
            $subject_name = sanitize_text_field( (string) ( $subject_row['subject_name'] ?? '' ) );
            if ( $subject_name !== '' ) {
                $subject_options[ $subject_name ] = $subject_name;
            }
        }

        $department_subject_map = [];
        if ( isset( $academic_settings['departments'] ) && is_array( $academic_settings['departments'] ) ) {
            foreach ( $academic_settings['departments'] as $department_name => $department_subjects ) {
                $department_name = sanitize_text_field( (string) $department_name );
                if ( $department_name === '' ) {
                    continue;
                }

                $department_subject_map[ $department_name ] = array_values(
                    array_filter(
                        array_map(
                            static fn( $subject ): string => sanitize_text_field( (string) $subject ),
                            (array) $department_subjects
                        )
                    )
                );
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_exam_timetable' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_exam_timetable' ) ) {
                $timetable_data = [
                    'exam_id'          => absint( wp_unslash( $_POST['exam_id'] ?? 0 ) ),
                    'session_year'     => sanitize_text_field( wp_unslash( $_POST['session_year'] ?? '' ) ),
                    'term'             => sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) ),
                    'class_name'       => sanitize_text_field( wp_unslash( $_POST['class_name'] ?? '' ) ),
                    'arm'              => sanitize_text_field( wp_unslash( $_POST['arm'] ?? '' ) ),
                    'department'       => sanitize_text_field( wp_unslash( $_POST['department'] ?? '' ) ),
                    'subject'          => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
                    'exam_type'        => sanitize_text_field( wp_unslash( $_POST['exam_type'] ?? '' ) ),
                    'exam_date'        => sanitize_text_field( wp_unslash( $_POST['exam_date'] ?? '' ) ),
                    'start_time'       => sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) ),
                    'end_time'         => sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) ),
                    'duration_minutes' => absint( wp_unslash( $_POST['duration_minutes'] ?? 0 ) ),
                    'venue'            => sanitize_text_field( wp_unslash( $_POST['venue'] ?? '' ) ),
                    'invigilator'      => sanitize_text_field( wp_unslash( $_POST['invigilator'] ?? '' ) ),
                    'is_trial_mode'    => isset( $_POST['is_trial_mode'] ) ? 1 : 0,
                    'status'           => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'scheduled' ) ),
                ];

                if ( empty( $timetable_data['class_name'] ) || empty( $timetable_data['department'] ) || empty( $timetable_data['subject'] ) ) {
                    $message = esc_html__( 'Class, Department, and Subject are required for timetable entries.', 'educbt-pro' );
                } else {
                    $constraint = $this->exam_timetable_service->validate_daily_subject_constraints( $current_school_id, $timetable_data );
                    if ( ! $constraint['success'] ) {
                        $message = $constraint['message'] === 'max_three_subjects_per_day_for_class_department'
                            ? esc_html__( 'Cannot schedule more than 3 exam subjects per day for the same class and department.', 'educbt-pro' )
                            : esc_html__( 'Class, Department, Subject, and Exam Date are required for timetable entries.', 'educbt-pro' );
                    } else {
                    $timetable_id = $this->exam_timetable_service->create_timetable( $current_school_id, $timetable_data );
                    $message = $timetable_id > 0
                        ? esc_html__( 'Exam timetable entry created successfully.', 'educbt-pro' )
                        : esc_html__( 'Unable to create exam timetable entry.', 'educbt-pro' );
                    }
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'auto_generate_exam_timetable' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_auto_generate_exam_timetable' ) ) {
                $manual_subjects = $this->convert_csv_to_array( sanitize_text_field( wp_unslash( $_POST['rule_subjects'] ?? '' ) ) );
                $subjects_per_day = max( 1, min( 3, absint( wp_unslash( $_POST['subjects_per_day'] ?? 3 ) ) ) );

                $rules = [
                    'session_year' => sanitize_text_field( wp_unslash( $_POST['rule_session_year'] ?? '' ) ),
                    'term' => sanitize_text_field( wp_unslash( $_POST['rule_term'] ?? '' ) ),
                    'class_name' => sanitize_text_field( wp_unslash( $_POST['rule_class_name'] ?? '' ) ),
                    'arm' => sanitize_text_field( wp_unslash( $_POST['rule_arm'] ?? '' ) ),
                    'department' => sanitize_text_field( wp_unslash( $_POST['rule_department'] ?? '' ) ),
                    'exam_date_start' => sanitize_text_field( wp_unslash( $_POST['rule_exam_date_start'] ?? '' ) ),
                    'start_time' => sanitize_text_field( wp_unslash( $_POST['rule_start_time'] ?? '' ) ),
                    'slot_duration_minutes' => max( 1, absint( wp_unslash( $_POST['rule_slot_duration_minutes'] ?? 90 ) ) ),
                    'slot_break_minutes' => max( 0, absint( wp_unslash( $_POST['rule_slot_break_minutes'] ?? 15 ) ) ),
                    'subjects_per_day' => $subjects_per_day,
                    'venue' => sanitize_text_field( wp_unslash( $_POST['rule_venue'] ?? '' ) ),
                    'invigilator' => sanitize_text_field( wp_unslash( $_POST['rule_invigilator'] ?? '' ) ),
                    'exam_type' => sanitize_text_field( wp_unslash( $_POST['rule_exam_type'] ?? '' ) ),
                    'status' => sanitize_text_field( wp_unslash( $_POST['rule_status'] ?? 'scheduled' ) ),
                    'subject_list' => $manual_subjects,
                    'subject_exam_map' => sanitize_text_field( wp_unslash( $_POST['rule_subject_exam_map'] ?? '' ) ),
                ];

                $result = $this->auto_generate_timetable_entries( $current_school_id, $rules, $academic_settings );
                $message = sprintf(
                    esc_html__( 'Auto-generation complete. Created: %1$d. Skipped: %2$d.', 'educbt-pro' ),
                    absint( $result['created'] ?? 0 ),
                    absint( $result['skipped'] ?? 0 )
                );

                if ( ! empty( $result['notes'] ) ) {
                    $message .= ' ' . implode( ' ', array_map( 'sanitize_text_field', (array) $result['notes'] ) );
                }
            }
        }

        $timetables = $this->exam_timetable_service->list_timetables( $current_school_id );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Exam Timetable', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Create Timetable Entry', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_exam_timetable', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_exam_timetable">';
        echo '<table class="form-table">';
        $this->render_select_row( 'exam_id', __( 'Exam', 'educbt-pro' ), $exam_options, true );
        $this->render_input_row( 'session_year', __( 'Session', 'educbt-pro' ) );
        $this->render_input_row( 'term', __( 'Term', 'educbt-pro' ) );
        $this->render_select_row( 'class_name', __( 'Class', 'educbt-pro' ), $class_options, true );
        $this->render_select_row( 'arm', __( 'Arm', 'educbt-pro' ), $arm_options );
        $this->render_select_row( 'department', __( 'Department', 'educbt-pro' ), $department_options, true );
        $this->render_select_row( 'subject', __( 'Subject', 'educbt-pro' ), $subject_options, true );
        $this->render_input_row( 'exam_type', __( 'Exam Type', 'educbt-pro' ) );
        $this->render_input_row( 'exam_date', __( 'Exam Date', 'educbt-pro' ), 'date' );
        $this->render_input_row( 'start_time', __( 'Start Time', 'educbt-pro' ), 'time' );
        $this->render_input_row( 'end_time', __( 'End Time', 'educbt-pro' ), 'time' );
        $this->render_input_row( 'duration_minutes', __( 'Duration (minutes)', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'venue', __( 'Venue', 'educbt-pro' ) );
        $this->render_input_row( 'invigilator', __( 'Invigilator', 'educbt-pro' ) );
        echo '<tr><th scope="row"><label for="is_trial_mode">' . esc_html__( 'Trial Mode', 'educbt-pro' ) . '</label></th><td><input name="is_trial_mode" id="is_trial_mode" type="checkbox" value="1"></td></tr>';
        $this->render_input_row( 'status', __( 'Status', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Save Timetable Entry', 'educbt-pro' ) );
        echo '<p id="educbt-timetable-slot-preview" style="margin:8px 0 0;color:#1d2327;"></p>';
        echo '</form>';

        echo '<h2>' . esc_html__( 'Auto-Generate Timetable (Rules)', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Generate timetable entries automatically from class/department rules. Maximum subjects per day is enforced at 3.', 'educbt-pro' ) . '</p>';
        echo '<form method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_auto_generate_exam_timetable', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="auto_generate_exam_timetable">';
        echo '<table class="form-table">';
        $this->render_input_row( 'rule_session_year', __( 'Session', 'educbt-pro' ) );
        $this->render_input_row( 'rule_term', __( 'Term', 'educbt-pro' ) );
        $this->render_select_row( 'rule_class_name', __( 'Class', 'educbt-pro' ), $class_options, true );
        $this->render_select_row( 'rule_arm', __( 'Arm', 'educbt-pro' ), $arm_options );
        $this->render_select_row( 'rule_department', __( 'Department', 'educbt-pro' ), $department_options, true );
        $this->render_input_row( 'rule_exam_date_start', __( 'Start Date', 'educbt-pro' ), 'date' );
        $this->render_input_row( 'rule_start_time', __( 'Start Time', 'educbt-pro' ), 'time' );
        $this->render_input_row( 'rule_slot_duration_minutes', __( 'Slot Duration (minutes)', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'rule_slot_break_minutes', __( 'Break Between Slots (minutes)', 'educbt-pro' ), 'number' );
        $this->render_select_row( 'subjects_per_day', __( 'Subjects Per Day', 'educbt-pro' ), [ '1' => '1', '2' => '2', '3' => '3' ], true );
        $this->render_input_row( 'rule_venue', __( 'Venue', 'educbt-pro' ) );
        $this->render_input_row( 'rule_invigilator', __( 'Invigilator', 'educbt-pro' ) );
        $this->render_input_row( 'rule_exam_type', __( 'Exam Type', 'educbt-pro' ) );
        $this->render_input_row( 'rule_status', __( 'Status', 'educbt-pro' ) );
        $this->render_input_row( 'rule_subject_exam_map', __( 'Subject:Exam ID Map (e.g. Mathematics:12,English Language:9)', 'educbt-pro' ) );
        $this->render_textarea_row( 'rule_subjects', __( 'Allowed Subjects (comma separated, optional override)', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Auto-Generate Timetable', 'educbt-pro' ), 'secondary' );
        echo '</form>';

        $department_subject_map_json = wp_json_encode( $department_subject_map );
        $existing_timetables_json = wp_json_encode( $timetables );
        echo '<script>';
        echo '(function(){';
        echo 'var form=document.querySelector("form input[name=\"educbt_pro_action\"][value=\"create_exam_timetable\"]")?document.querySelector("form input[name=\"educbt_pro_action\"][value=\"create_exam_timetable\"]").form:null;';
        echo 'var dept=document.getElementById("department");';
        echo 'var subject=document.getElementById("subject");';
        echo 'var classField=document.getElementById("class_name");';
        echo 'var dateField=document.getElementById("exam_date");';
        echo 'var preview=document.getElementById("educbt-timetable-slot-preview");';
        echo 'var submitBtn=form?form.querySelector("button[type=submit]"):null;';
        echo 'if(!dept||!subject||!classField||!dateField||!preview){return;}';
        echo 'var map=' . ( $department_subject_map_json ? $department_subject_map_json : '{}' ) . ';';
        echo 'var timetables=' . ( $existing_timetables_json ? $existing_timetables_json : '[]' ) . ';';
        echo 'var maxSubjectsPerDay=3;';
        echo 'var allOptions=Array.prototype.slice.call(subject.options).map(function(opt){return {value:opt.value,text:opt.text};});';
        echo 'var normalize=function(value){return String(value||"").trim().toLowerCase();};';
        echo 'var renderSubjects=function(){';
        echo 'var selectedDepartment=(dept.value||"").trim();';
        echo 'var allowed=Array.isArray(map[selectedDepartment])?map[selectedDepartment]:null;';
        echo 'var selectedValue=subject.value;';
        echo 'subject.innerHTML="";';
        echo 'allOptions.forEach(function(opt){';
        echo 'if(opt.value===""){subject.add(new Option(opt.text,opt.value));return;}';
        echo 'if(!selectedDepartment){return;}';
        echo 'if(allowed&&allowed.length>0&&allowed.indexOf(opt.value)===-1){return;}';
        echo 'subject.add(new Option(opt.text,opt.value));';
        echo '});';
        echo 'if(subject.querySelector("option[value=\""+selectedValue+"\"]")){subject.value=selectedValue;}';
        echo 'if(!selectedDepartment){subject.selectedIndex=0;return;}';
        echo '};';
        echo 'var renderSlotPreview=function(){';
        echo 'var className=normalize(classField.value);';
        echo 'var department=normalize(dept.value);';
        echo 'var examDate=String(dateField.value||"").trim();';
        echo 'var selectedSubject=normalize(subject.value);';
        echo 'if(!className||!department||!examDate){';
        echo 'preview.style.color="#1d2327";';
        echo 'preview.textContent="Select class, department, and exam date to preview available subject slots (max 3/day).";';
        echo 'if(submitBtn){submitBtn.disabled=false;}';
        echo 'return;';
        echo '}';
        echo 'var subjectsSet={};';
        echo 'timetables.forEach(function(entry){';
        echo 'if(!entry){return;}';
        echo 'if(normalize(entry.class_name)!==className){return;}';
        echo 'if(normalize(entry.department)!==department){return;}';
        echo 'if(String(entry.exam_date||"").trim()!==examDate){return;}';
        echo 'var s=normalize(entry.subject);';
        echo 'if(s){subjectsSet[s]=true;}';
        echo '});';
        echo 'var scheduledCount=Object.keys(subjectsSet).length;';
        echo 'var subjectNames=Object.keys(subjectsSet).sort();';
        echo 'var projectedCount=scheduledCount;';
        echo 'if(selectedSubject&&!subjectsSet[selectedSubject]){projectedCount++;}';
        echo 'var remaining=Math.max(0,maxSubjectsPerDay-scheduledCount);';
        echo 'var canSchedule=projectedCount<=maxSubjectsPerDay;';
        echo 'if(canSchedule){';
        echo 'preview.style.color="#23682f";';
        echo 'var listed=subjectNames.length?" Subjects: "+subjectNames.join(", ")+".":"";';
        echo 'preview.textContent="Scheduled subjects for this class/department/date: "+scheduledCount+"/"+maxSubjectsPerDay+". Remaining slots: "+remaining+"."+listed;';
        echo '}else{';
        echo 'preview.style.color="#b32d2e";';
        echo 'var listed=subjectNames.length?" Scheduled subjects: "+subjectNames.join(", ")+".":"";';
        echo 'preview.textContent="Limit reached: cannot schedule more than "+maxSubjectsPerDay+" subjects for this class/department on "+examDate+"."+listed;';
        echo '}';
        echo 'if(submitBtn){submitBtn.disabled=!canSchedule;}';
        echo '};';
        echo 'dept.addEventListener("change",renderSubjects);';
        echo 'dept.addEventListener("change",renderSlotPreview);';
        echo 'classField.addEventListener("change",renderSlotPreview);';
        echo 'dateField.addEventListener("change",renderSlotPreview);';
        echo 'subject.addEventListener("change",renderSlotPreview);';
        echo 'renderSubjects();';
        echo 'renderSlotPreview();';
        echo '})();';
        echo '</script>';

        echo '<h2>' . esc_html__( 'Current Timetable', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Exam ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Session', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Term', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Class', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Dept', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Date', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Start', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Trial', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $timetables ) ) {
            echo '<tr><td colspan="9">' . esc_html__( 'No timetable entries yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $timetables as $entry ) {
                echo '<tr>';
                echo '<td>' . esc_html( $entry['id'] ) . '</td>';
                echo '<td>' . esc_html( $entry['exam_id'] ) . '</td>';
                echo '<td>' . esc_html( $entry['session_year'] ) . '</td>';
                echo '<td>' . esc_html( $entry['term'] ) . '</td>';
                echo '<td>' . esc_html( $entry['class_name'] ) . '</td>';
                echo '<td>' . esc_html( $entry['department'] ) . '</td>';
                echo '<td>' . esc_html( $entry['exam_date'] ) . '</td>';
                echo '<td>' . esc_html( $entry['start_time'] ) . '</td>';
                echo '<td>' . ( ! empty( $entry['is_trial_mode'] ) ? esc_html__( 'Yes', 'educbt-pro' ) : esc_html__( 'No', 'educbt-pro' ) ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_integrity_events_page(): void {
        $current_school_id = $this->get_current_school_id();
        $message = '';

        if ( isset( $_POST['educbt_pro_action'] ) && 'save_integrity_thresholds' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_save_integrity_thresholds' ) ) {
                $saved = $this->school_service->update_integrity_monitoring_settings(
                    $current_school_id,
                    [
                        'blur_threshold' => absint( wp_unslash( $_POST['blur_threshold'] ?? 0 ) ),
                        'hidden_threshold' => absint( wp_unslash( $_POST['hidden_threshold'] ?? 0 ) ),
                        'total_suspicious_threshold' => absint( wp_unslash( $_POST['total_suspicious_threshold'] ?? 0 ) ),
                    ]
                );

                $message = $saved
                    ? esc_html__( 'Integrity risk thresholds updated.', 'educbt-pro' )
                    : esc_html__( 'Unable to update integrity risk thresholds.', 'educbt-pro' );
            }
        }

        $integrity_settings = $this->school_service->get_integrity_monitoring_settings( $current_school_id );
        $blur_threshold = max( 1, absint( $integrity_settings['blur_threshold'] ?? 3 ) );
        $hidden_threshold = max( 1, absint( $integrity_settings['hidden_threshold'] ?? 3 ) );
        $total_suspicious_threshold = max( 1, absint( $integrity_settings['total_suspicious_threshold'] ?? 4 ) );

        $exam_id = absint( wp_unslash( $_REQUEST['exam_id'] ?? 0 ) );
        $student_id = absint( wp_unslash( $_REQUEST['student_id'] ?? 0 ) );
        $attempt_id = absint( wp_unslash( $_REQUEST['attempt_id'] ?? 0 ) );
        $event_type = sanitize_text_field( wp_unslash( $_REQUEST['event_type'] ?? '' ) );
        $date_from = sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ?? '' ) );
        $date_to = sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ?? '' ) );

        $filters = [
            'exam_id' => $exam_id,
            'student_id' => $student_id,
            'attempt_id' => $attempt_id,
            'event_type' => $event_type,
            'date_from' => $date_from ? $date_from . ' 00:00:00' : '',
            'date_to' => $date_to ? $date_to . ' 23:59:59' : '',
            'limit' => 500,
        ];

        $events = $this->exam_integrity_event_service->list_events( $current_school_id, $filters );

        if ( isset( $_REQUEST['export_csv'] ) && absint( wp_unslash( $_REQUEST['export_csv'] ) ) === 1 ) {
            $this->download_integrity_events_csv( $events );
            return;
        }

        $type_counts = [];
        $attempt_risk_counts = [];
        $high_risk_attempts = [];
        foreach ( $events as $event ) {
            $type = sanitize_text_field( (string) ( $event['event_type'] ?? 'unknown' ) );
            if ( ! isset( $type_counts[ $type ] ) ) {
                $type_counts[ $type ] = 0;
            }
            $type_counts[ $type ]++;

            $attempt_key = absint( $event['attempt_id'] ?? 0 );
            if ( $attempt_key > 0 ) {
                if ( ! isset( $attempt_risk_counts[ $attempt_key ] ) ) {
                    $attempt_risk_counts[ $attempt_key ] = [
                        'blur' => 0,
                        'hidden' => 0,
                        'total' => 0,
                    ];
                }

                if ( $type === 'window_blur' ) {
                    $attempt_risk_counts[ $attempt_key ]['blur']++;
                    $attempt_risk_counts[ $attempt_key ]['total']++;
                }

                if ( $type === 'window_hidden' ) {
                    $attempt_risk_counts[ $attempt_key ]['hidden']++;
                    $attempt_risk_counts[ $attempt_key ]['total']++;
                }
            }
        }
        arsort( $type_counts );

        foreach ( $attempt_risk_counts as $attempt_key => $risk_count ) {
            if (
                $risk_count['blur'] >= $blur_threshold
                || $risk_count['hidden'] >= $hidden_threshold
                || $risk_count['total'] >= $total_suspicious_threshold
            ) {
                $high_risk_attempts[ $attempt_key ] = $risk_count;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Integrity Events', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Risk Threshold Settings', 'educbt-pro' ) . '</h2>';
        echo '<form id="educbt-integrity-threshold-form" method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_save_integrity_thresholds', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="save_integrity_thresholds">';
        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="blur_threshold">' . esc_html__( 'Blur Event Threshold', 'educbt-pro' ) . '</label></th><td><input name="blur_threshold" id="blur_threshold" type="number" min="1" class="small-text" value="' . esc_attr( (string) $blur_threshold ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="hidden_threshold">' . esc_html__( 'Hidden Event Threshold', 'educbt-pro' ) . '</label></th><td><input name="hidden_threshold" id="hidden_threshold" type="number" min="1" class="small-text" value="' . esc_attr( (string) $hidden_threshold ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="total_suspicious_threshold">' . esc_html__( 'Total Suspicious Threshold', 'educbt-pro' ) . '</label></th><td><input name="total_suspicious_threshold" id="total_suspicious_threshold" type="number" min="1" class="small-text" value="' . esc_attr( (string) $total_suspicious_threshold ) . '"></td></tr>';
        echo '</table>';
        submit_button( __( 'Save Thresholds', 'educbt-pro' ), 'secondary', 'submit', false );
        echo '<p id="educbt-integrity-threshold-status" style="margin:8px 0 0;display:none;"></p>';
        echo '</form>';

        echo '<form id="educbt-integrity-filter-form" method="get" action="" style="margin:12px 0 18px;">';
        echo '<input type="hidden" name="page" value="educbt-pro-integrity-events">';
        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="exam_id">' . esc_html__( 'Exam ID', 'educbt-pro' ) . '</label></th><td><input name="exam_id" id="exam_id" type="number" class="small-text" value="' . esc_attr( (string) $exam_id ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="student_id">' . esc_html__( 'Student ID', 'educbt-pro' ) . '</label></th><td><input name="student_id" id="student_id" type="number" class="small-text" value="' . esc_attr( (string) $student_id ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="attempt_id">' . esc_html__( 'Attempt ID', 'educbt-pro' ) . '</label></th><td><input name="attempt_id" id="attempt_id" type="number" class="small-text" value="' . esc_attr( (string) $attempt_id ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="event_type">' . esc_html__( 'Event Type', 'educbt-pro' ) . '</label></th><td><input name="event_type" id="event_type" type="text" class="regular-text" value="' . esc_attr( $event_type ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="date_from">' . esc_html__( 'Date From', 'educbt-pro' ) . '</label></th><td><input name="date_from" id="date_from" type="date" value="' . esc_attr( $date_from ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="date_to">' . esc_html__( 'Date To', 'educbt-pro' ) . '</label></th><td><input name="date_to" id="date_to" type="date" value="' . esc_attr( $date_to ) . '"></td></tr>';
        echo '</table>';
        submit_button( __( 'Filter Events', 'educbt-pro' ), 'secondary', 'submit', false );
        echo ' <button type="submit" class="button" name="export_csv" value="1">' . esc_html__( 'Export CSV', 'educbt-pro' ) . '</button>';
        echo '</form>';

        echo '<h2>' . esc_html__( 'Event Summary', 'educbt-pro' ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'Total Events:', 'educbt-pro' ) . '</strong> <span id="educbt-total-events-count">' . esc_html( (string) count( $events ) ) . '</span></p>';
        echo '<p><strong>' . esc_html__( 'High-Risk Attempts:', 'educbt-pro' ) . '</strong> <span id="educbt-high-risk-attempt-count">' . esc_html( (string) count( $high_risk_attempts ) ) . '</span></p>';
        if ( empty( $type_counts ) ) {
            echo '<p id="educbt-event-type-empty">' . esc_html__( 'No event types available for current filters.', 'educbt-pro' ) . '</p>';
            echo '<ul id="educbt-event-type-list" style="display:none;margin:0 0 18px 18px;"></ul>';
        } else {
            echo '<p id="educbt-event-type-empty" style="display:none;">' . esc_html__( 'No event types available for current filters.', 'educbt-pro' ) . '</p>';
            echo '<ul id="educbt-event-type-list" style="margin:0 0 18px 18px;">';
            foreach ( $type_counts as $type => $count ) {
                echo '<li>' . esc_html( $type ) . ': ' . esc_html( (string) $count ) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>' . esc_html__( 'Latest Events', 'educbt-pro' ) . '</h2>';
        echo '<table id="educbt-integrity-events-table" class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Exam', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Student', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Attempt', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Risk', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Type', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Payload', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Created', 'educbt-pro' ) . '</th></tr></thead><tbody id="educbt-integrity-events-body">';

        if ( empty( $events ) ) {
            echo '<tr><td colspan="8">' . esc_html__( 'No integrity events found.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $events as $event ) {
                $payload_raw = (string) ( $event['event_payload'] ?? '' );
                $payload_decoded = json_decode( $payload_raw, true );
                $payload_summary = is_array( $payload_decoded ) ? wp_json_encode( $payload_decoded ) : $payload_raw;
                $attempt_key = absint( $event['attempt_id'] ?? 0 );
                $is_high_risk = $attempt_key > 0 && ! empty( $high_risk_attempts[ $attempt_key ] );
                $risk_label = $is_high_risk ? __( 'High', 'educbt-pro' ) : __( 'Normal', 'educbt-pro' );
                $risk_tooltip = '';

                if ( $is_high_risk ) {
                    $counts = $high_risk_attempts[ $attempt_key ];
                    $risk_tooltip = sprintf(
                        'Blur:%d Hidden:%d Total:%d',
                        absint( $counts['blur'] ?? 0 ),
                        absint( $counts['hidden'] ?? 0 ),
                        absint( $counts['total'] ?? 0 )
                    );
                }

                echo '<tr>';
                echo '<td>' . esc_html( (string) ( $event['id'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $event['exam_id'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $event['student_id'] ?? '' ) ) . '</td>';
                echo '<td data-educbt-attempt-id="' . esc_attr( (string) $attempt_key ) . '">' . esc_html( (string) ( $event['attempt_id'] ?? '' ) ) . '</td>';
                echo '<td><span class="educbt-risk-badge" title="' . esc_attr( $risk_tooltip ) . '" style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . esc_attr( $is_high_risk ? '#fbeaea' : '#edf7ed' ) . ';color:' . esc_attr( $is_high_risk ? '#a4282f' : '#23682f' ) . ';font-weight:600;">' . esc_html( $risk_label ) . '</span></td>';
                echo '<td>' . esc_html( (string) ( $event['event_type'] ?? '' ) ) . '</td>';
                echo '<td><code style="white-space:normal;word-break:break-word;">' . esc_html( (string) $payload_summary ) . '</code></td>';
                echo '<td>' . esc_html( (string) ( $event['created_at'] ?? '' ) ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        $integrity_settings_endpoint = rest_url( 'educbt-pro/v1/integrity-monitoring-settings' );
        $integrity_events_endpoint = rest_url( 'educbt-pro/v1/exam-integrity-events' );
        $rest_nonce = wp_create_nonce( 'wp_rest' );
        echo '<script>';
        echo '(function(){';
        echo 'var form=document.getElementById("educbt-integrity-threshold-form");';
        echo 'if(!form){return;}';
        echo 'var status=document.getElementById("educbt-integrity-threshold-status");';
        echo 'var highRiskCountEl=document.getElementById("educbt-high-risk-attempt-count");';
        echo 'var submitButton=form.querySelector("button[type=submit]");';
        echo 'var endpoint=' . wp_json_encode( esc_url_raw( $integrity_settings_endpoint ) ) . ';';
        echo 'var eventsEndpoint=' . wp_json_encode( esc_url_raw( $integrity_events_endpoint ) ) . ';';
        echo 'var nonce=' . wp_json_encode( $rest_nonce ) . ';';
        echo 'var blurThreshold=' . (int) $blur_threshold . ';';
        echo 'var hiddenThreshold=' . (int) $hidden_threshold . ';';
        echo 'var totalThreshold=' . (int) $total_suspicious_threshold . ';';
        echo 'var suspiciousTypes={window_blur:"blur",window_hidden:"hidden"};';
        echo 'var filterForm=document.getElementById("educbt-integrity-filter-form");';
        echo 'var eventsBody=document.getElementById("educbt-integrity-events-body");';
        echo 'var totalEventsCountEl=document.getElementById("educbt-total-events-count");';
        echo 'var typeListEl=document.getElementById("educbt-event-type-list");';
        echo 'var typeEmptyEl=document.getElementById("educbt-event-type-empty");';

        echo 'var readFilterQuery=function(){';
        echo 'if(!filterForm){return "";}';
        echo 'var params=new URLSearchParams(new FormData(filterForm));';
        echo 'params.delete("page");';
        echo 'params.delete("export_csv");';
        echo 'return params.toString();';
        echo '};';

        echo 'var renderEventTypeSummary=function(events){';
        echo 'if(!typeListEl||!typeEmptyEl){return;}';
        echo 'var counts={};';
        echo 'events.forEach(function(item){var type=(item&&item.event_type?String(item.event_type):"").trim()||"unknown";counts[type]=(counts[type]||0)+1;});';
        echo 'var keys=Object.keys(counts);';
        echo 'if(keys.length===0){typeListEl.style.display="none";typeListEl.innerHTML="";typeEmptyEl.style.display="";return;}';
        echo 'typeEmptyEl.style.display="none";';
        echo 'typeListEl.style.display="";';
        echo 'typeListEl.innerHTML=keys.sort().map(function(type){return "<li>"+type+": "+counts[type]+"</li>";}).join("");';
        echo '};';

        echo 'var renderEventsTable=function(events){';
        echo 'if(!eventsBody){return;}';
        echo 'if(!Array.isArray(events)||events.length===0){eventsBody.innerHTML="<tr><td colspan=\"8\">No integrity events found.</td></tr>";if(totalEventsCountEl){totalEventsCountEl.textContent="0";}renderEventTypeSummary([]);return;}';
        echo 'eventsBody.innerHTML=events.map(function(item){';
        echo 'var id=Number(item&&item.id||0);';
        echo 'var examId=Number(item&&item.exam_id||0);';
        echo 'var studentId=Number(item&&item.student_id||0);';
        echo 'var attemptId=Number(item&&item.attempt_id||0);';
        echo 'var type=(item&&item.event_type?String(item.event_type):"");';
        echo 'var payload=(item&&item.event_payload?String(item.event_payload):"");';
        echo 'var created=(item&&item.created_at?String(item.created_at):"");';
        echo 'return "<tr>"+"<td>"+id+"</td>"+"<td>"+examId+"</td>"+"<td>"+studentId+"</td>"+"<td data-educbt-attempt-id=\""+attemptId+"\">"+attemptId+"</td>"+"<td><span class=\"educbt-risk-badge\" style=\"display:inline-block;padding:2px 8px;border-radius:10px;background:#edf7ed;color:#23682f;font-weight:600;\">Normal</span></td>"+"<td>"+type.replace(/</g,"&lt;").replace(/>/g,"&gt;")+"</td>"+"<td><code style=\"white-space:normal;word-break:break-word;\">"+payload.replace(/</g,"&lt;").replace(/>/g,"&gt;")+"</code></td>"+"<td>"+created.replace(/</g,"&lt;").replace(/>/g,"&gt;")+"</td>"+"</tr>";';
        echo '}).join("");';
        echo 'if(totalEventsCountEl){totalEventsCountEl.textContent=String(events.length);}';
        echo 'renderEventTypeSummary(events);';
        echo '};';

        echo 'var refreshEventsTable=function(){';
        echo 'var query=readFilterQuery();';
        echo 'var url=eventsEndpoint+(query?"?"+query:"");';
        echo 'return fetch(url,{method:"GET",credentials:"same-origin",headers:{"X-WP-Nonce":nonce}})';
        echo '.then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});})';
        echo '.then(function(result){if(!result.ok||!result.data||result.data.success!==true){throw new Error((result.data&&result.data.message)?result.data.message:"Unable to refresh events.");}return Array.isArray(result.data.data)?result.data.data:[];})';
        echo '.then(function(events){renderEventsTable(events);recalcRisk();});';
        echo '};';

        echo 'var recalcRisk=function(){';
        echo 'if(!eventsBody){return;}';
        echo 'var rows=eventsBody.querySelectorAll("tr");';
        echo 'var attemptCounts={};';
        echo 'rows.forEach(function(row){';
        echo 'var attemptCell=row.querySelector("td[data-educbt-attempt-id]");';
        echo 'if(!attemptCell){return;}';
        echo 'var attemptId=parseInt(attemptCell.getAttribute("data-educbt-attempt-id")||"0",10)||0;';
        echo 'if(!attemptId){return;}';
        echo 'if(!attemptCounts[attemptId]){attemptCounts[attemptId]={blur:0,hidden:0,total:0};}';
        echo 'var typeCell=row.children[5];';
        echo 'var type=(typeCell&&typeCell.textContent?typeCell.textContent:"").trim();';
        echo 'if(suspiciousTypes[type]){attemptCounts[attemptId][suspiciousTypes[type]]++;attemptCounts[attemptId].total++;}';
        echo '});';

        echo 'var highRisk={};';
        echo 'Object.keys(attemptCounts).forEach(function(id){';
        echo 'var counts=attemptCounts[id];';
        echo 'if(counts.blur>=blurThreshold||counts.hidden>=hiddenThreshold||counts.total>=totalThreshold){highRisk[id]=counts;}';
        echo '});';

        echo 'rows.forEach(function(row){';
        echo 'var attemptCell=row.querySelector("td[data-educbt-attempt-id]");';
        echo 'var badge=row.querySelector(".educbt-risk-badge");';
        echo 'if(!attemptCell||!badge){return;}';
        echo 'var attemptId=parseInt(attemptCell.getAttribute("data-educbt-attempt-id")||"0",10)||0;';
        echo 'var isHigh=attemptId&&highRisk[String(attemptId)];';
        echo 'if(isHigh){';
        echo 'badge.textContent="High";';
        echo 'badge.style.background="#fbeaea";';
        echo 'badge.style.color="#a4282f";';
        echo 'badge.title="Blur:"+highRisk[String(attemptId)].blur+" Hidden:"+highRisk[String(attemptId)].hidden+" Total:"+highRisk[String(attemptId)].total;';
        echo '}else{';
        echo 'badge.textContent="Normal";';
        echo 'badge.style.background="#edf7ed";';
        echo 'badge.style.color="#23682f";';
        echo 'badge.title="";';
        echo '}';
        echo '});';

        echo 'if(highRiskCountEl){highRiskCountEl.textContent=String(Object.keys(highRisk).length);}';
        echo '};';

        echo 'recalcRisk();';
        echo 'form.addEventListener("submit",function(event){';
        echo 'event.preventDefault();';
        echo 'if(submitButton){submitButton.disabled=true;}';
        echo 'if(status){status.style.display="block";status.style.color="#1d2327";status.textContent="Saving thresholds...";}';
        echo 'var payload={';
        echo 'blur_threshold:Math.max(1,parseInt((form.querySelector("#blur_threshold")||{}).value||"1",10)||1),';
        echo 'hidden_threshold:Math.max(1,parseInt((form.querySelector("#hidden_threshold")||{}).value||"1",10)||1),';
        echo 'total_suspicious_threshold:Math.max(1,parseInt((form.querySelector("#total_suspicious_threshold")||{}).value||"1",10)||1)';
        echo '};';
        echo 'fetch(endpoint,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify(payload)})';
        echo '.then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});})';
        echo '.then(function(result){';
        echo 'if(!result.ok||!result.data||result.data.success!==true){';
        echo 'var message=(result.data&&result.data.message)?result.data.message:"Unable to update integrity thresholds.";';
        echo 'throw new Error(message);';
        echo '}';
        echo 'var settings=result.data&&result.data.data?result.data.data:{};';
        echo 'blurThreshold=Math.max(1,parseInt(settings.blur_threshold||payload.blur_threshold||1,10)||1);';
        echo 'hiddenThreshold=Math.max(1,parseInt(settings.hidden_threshold||payload.hidden_threshold||1,10)||1);';
        echo 'totalThreshold=Math.max(1,parseInt(settings.total_suspicious_threshold||payload.total_suspicious_threshold||1,10)||1);';
        echo 'var blurInput=form.querySelector("#blur_threshold");';
        echo 'var hiddenInput=form.querySelector("#hidden_threshold");';
        echo 'var totalInput=form.querySelector("#total_suspicious_threshold");';
        echo 'if(blurInput){blurInput.value=String(blurThreshold);}';
        echo 'if(hiddenInput){hiddenInput.value=String(hiddenThreshold);}';
        echo 'if(totalInput){totalInput.value=String(totalThreshold);}';
        echo 'refreshEventsTable().catch(function(){recalcRisk();});';
        echo 'if(status){status.style.color="#23682f";status.textContent="Integrity risk thresholds updated.";}';
        echo '})';
        echo '.catch(function(error){';
        echo 'if(status){status.style.color="#b32d2e";status.textContent=error&&error.message?error.message:"Unable to update integrity thresholds.";}';
        echo '})';
        echo '.finally(function(){if(submitButton){submitButton.disabled=false;}});';
        echo '});';
        echo '})();';
        echo '</script>';
        echo '</div>';
    }

    private function download_integrity_events_csv( array $events ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=educbt-integrity-events.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, [ 'id', 'exam_id', 'student_id', 'attempt_id', 'event_type', 'event_payload', 'created_at' ] );

        foreach ( $events as $event ) {
            fputcsv(
                $output,
                [
                    absint( $event['id'] ?? 0 ),
                    absint( $event['exam_id'] ?? 0 ),
                    absint( $event['student_id'] ?? 0 ),
                    absint( $event['attempt_id'] ?? 0 ),
                    sanitize_text_field( (string) ( $event['event_type'] ?? '' ) ),
                    (string) ( $event['event_payload'] ?? '' ),
                    sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
                ]
            );
        }

        fclose( $output );
        exit;
    }

    public function render_results_page(): void {
        $message = '';
        if ( isset( $_POST['educbt_pro_action'] ) && 'create_result' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_result' ) ) {
                $result_data = [
                    'student_id'   => absint( wp_unslash( $_POST['student_id'] ?? 0 ) ),
                    'term'         => sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) ),
                    'session_year' => sanitize_text_field( wp_unslash( $_POST['session_year'] ?? '' ) ),
                    'subject'      => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
                    'score'        => floatval( wp_unslash( $_POST['score'] ?? 0 ) ),
                    'grade'        => sanitize_text_field( wp_unslash( $_POST['grade'] ?? '' ) ),
                    'remark'       => sanitize_text_field( wp_unslash( $_POST['remark'] ?? '' ) ),
                    'status'       => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'draft' ) ),
                ];
                $result_id = $this->result_service->create_result( $this->get_current_school_id(), $result_data );
                $message = $result_id ? esc_html__( 'Result recorded successfully.', 'educbt-pro' ) : esc_html__( 'Unable to record result.', 'educbt-pro' );
            }
        }

        $results = $this->result_service->list_results( $this->get_current_school_id() );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Results', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();
        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Record Result', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_result', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_result">';
        echo '<table class="form-table">';
        $this->render_input_row( 'student_id', __( 'Student ID', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'term', __( 'Term', 'educbt-pro' ) );
        $this->render_input_row( 'session_year', __( 'Session Year', 'educbt-pro' ) );
        $this->render_input_row( 'subject', __( 'Subject', 'educbt-pro' ) );
        $this->render_input_row( 'score', __( 'Score', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'grade', __( 'Grade', 'educbt-pro' ) );
        $this->render_input_row( 'remark', __( 'Remark', 'educbt-pro' ) );
        $this->render_input_row( 'status', __( 'Status', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Save Result', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Results History', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Student ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Subject', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Score', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Grade', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Status', 'educbt-pro' ) . '</th></tr></thead><tbody>';
        if ( empty( $results ) ) {
            echo '<tr><td colspan="6">' . esc_html__( 'No results recorded yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $results as $result ) {
                echo '<tr>';
                echo '<td>' . esc_html( $result['id'] ) . '</td>';
                echo '<td>' . esc_html( $result['student_id'] ) . '</td>';
                echo '<td>' . esc_html( $result['subject'] ) . '</td>';
                echo '<td>' . esc_html( $result['score'] ) . '</td>';
                echo '<td>' . esc_html( $result['grade'] ) . '</td>';
                echo '<td>' . esc_html( $result['status'] ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_promotions_page(): void {
        $message = '';
        if ( isset( $_POST['educbt_pro_action'] ) && 'create_promotion' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_promotion' ) ) {
                $promotion_data = [
                    'student_id'   => absint( wp_unslash( $_POST['student_id'] ?? 0 ) ),
                    'from_class'   => sanitize_text_field( wp_unslash( $_POST['from_class'] ?? '' ) ),
                    'to_class'     => sanitize_text_field( wp_unslash( $_POST['to_class'] ?? '' ) ),
                    'session_year' => sanitize_text_field( wp_unslash( $_POST['session_year'] ?? '' ) ),
                    'status'       => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'pending' ) ),
                ];
                $promotion_id = $this->promotion_service->create_promotion( $this->get_current_school_id(), $promotion_data );
                $message = $promotion_id ? esc_html__( 'Promotion request created.', 'educbt-pro' ) : esc_html__( 'Unable to create promotion request.', 'educbt-pro' );
            }
        }

        $promotions = $this->promotion_service->list_promotions( $this->get_current_school_id() );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Promotions', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();
        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Create Promotion', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_promotion', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_promotion">';
        echo '<table class="form-table">';
        $this->render_input_row( 'student_id', __( 'Student ID', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'from_class', __( 'From Class', 'educbt-pro' ) );
        $this->render_input_row( 'to_class', __( 'To Class', 'educbt-pro' ) );
        $this->render_input_row( 'session_year', __( 'Session Year', 'educbt-pro' ) );
        $this->render_input_row( 'status', __( 'Status', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Create Promotion', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Promotion Requests', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Student ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'From Class', 'educbt-pro' ) . '</th><th>' . esc_html__( 'To Class', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Session', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Status', 'educbt-pro' ) . '</th></tr></thead><tbody>';
        if ( empty( $promotions ) ) {
            echo '<tr><td colspan="6">' . esc_html__( 'No promotion requests yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $promotions as $promotion ) {
                echo '<tr>';
                echo '<td>' . esc_html( $promotion['id'] ) . '</td>';
                echo '<td>' . esc_html( $promotion['student_id'] ) . '</td>';
                echo '<td>' . esc_html( $promotion['from_class'] ) . '</td>';
                echo '<td>' . esc_html( $promotion['to_class'] ) . '</td>';
                echo '<td>' . esc_html( $promotion['session_year'] ) . '</td>';
                echo '<td>' . esc_html( $promotion['status'] ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_transcripts_page(): void {
        $message = '';
        if ( isset( $_POST['educbt_pro_action'] ) && 'create_transcript' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_transcript' ) ) {
                $transcript_data = [
                    'student_id' => absint( wp_unslash( $_POST['student_id'] ?? 0 ) ),
                    'terms'      => array_filter( array_map( 'trim', explode( ',', wp_unslash( $_POST['terms'] ?? '' ) ) ) ),
                    'sessions'   => array_filter( array_map( 'trim', explode( ',', wp_unslash( $_POST['sessions'] ?? '' ) ) ) ),
                    'summary'    => sanitize_textarea_field( wp_unslash( $_POST['summary'] ?? '' ) ),
                ];
                $transcript_id = $this->transcript_service->create_transcript( $this->get_current_school_id(), $transcript_data );
                $message = $transcript_id ? esc_html__( 'Transcript generated successfully.', 'educbt-pro' ) : esc_html__( 'Unable to generate transcript.', 'educbt-pro' );
            }
        }

        $transcripts = $this->transcript_service->list_transcripts( $this->get_current_school_id() );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Transcripts', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();
        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Create Transcript', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_transcript', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_transcript">';
        echo '<table class="form-table">';
        $this->render_input_row( 'student_id', __( 'Student ID', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'terms', __( 'Terms (comma separated)', 'educbt-pro' ) );
        $this->render_input_row( 'sessions', __( 'Sessions (comma separated)', 'educbt-pro' ) );
        $this->render_textarea_row( 'summary', __( 'Summary', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Generate Transcript', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Transcript Records', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Student ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Terms', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Sessions', 'educbt-pro' ) . '</th></tr></thead><tbody>';
        if ( empty( $transcripts ) ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No transcripts created yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $transcripts as $transcript ) {
                echo '<tr>';
                echo '<td>' . esc_html( $transcript['id'] ) . '</td>';
                echo '<td>' . esc_html( $transcript['student_id'] ) . '</td>';
                echo '<td>' . esc_html( implode( ', ', json_decode( $transcript['terms'], true ) ?: [] ) ) . '</td>';
                echo '<td>' . esc_html( implode( ', ', json_decode( $transcript['sessions'], true ) ?: [] ) ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_audit_logs_page(): void {
        $logs = $this->audit_log_service->list_logs( $this->get_current_school_id() );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Audit Logs', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'User', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Action', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Object', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Created At', 'educbt-pro' ) . '</th></tr></thead><tbody>';
        if ( empty( $logs ) ) {
            echo '<tr><td colspan="5">' . esc_html__( 'No audit logs available.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $logs as $log ) {
                echo '<tr>';
                echo '<td>' . esc_html( $log['id'] ) . '</td>';
                echo '<td>' . esc_html( $log['user_id'] ) . '</td>';
                echo '<td>' . esc_html( $log['action'] ) . '</td>';
                echo '<td>' . esc_html( $log['object_type'] . ' #' . $log['object_id'] ) . '</td>';
                echo '<td>' . esc_html( $log['created_at'] ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_teachers_page(): void {
        $message = '';
        $current_school_id = $this->get_current_school_id();

        if ( isset( $_POST['educbt_pro_action'] ) && 'update_teacher_assignments' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_update_teacher_assignments' ) ) {
                $teacher_row_id = absint( wp_unslash( $_POST['teacher_row_id'] ?? 0 ) );
                $teacher_data = [
                    'teacher_id'       => sanitize_text_field( wp_unslash( $_POST['teacher_id'] ?? '' ) ),
                    'full_name'        => sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) ),
                    'teacher_group'    => sanitize_text_field( wp_unslash( $_POST['teacher_group'] ?? '' ) ),
                    'contact_details'  => sanitize_textarea_field( wp_unslash( $_POST['contact_details'] ?? '' ) ),
                    'subjects'         => $this->convert_csv_to_array( wp_unslash( $_POST['subjects'] ?? '' ) ),
                    'assigned_classes' => $this->convert_csv_to_array( wp_unslash( $_POST['assigned_classes'] ?? '' ) ),
                ];

                if ( $current_school_id <= 0 ) {
                    $message = esc_html__( 'Select a school context before updating teachers.', 'educbt-pro' );
                } elseif ( $teacher_row_id <= 0 ) {
                    $message = esc_html__( 'Select a teacher record to update.', 'educbt-pro' );
                } else {
                    $existing_teacher = $this->teacher_service->get_teacher_by_id( $current_school_id, $teacher_row_id );
                    if ( is_array( $existing_teacher ) ) {
                        $teacher_data['teacher_id'] = $teacher_data['teacher_id'] !== '' ? $teacher_data['teacher_id'] : (string) ( $existing_teacher['teacher_id'] ?? '' );
                        $teacher_data['full_name'] = $teacher_data['full_name'] !== '' ? $teacher_data['full_name'] : (string) ( $existing_teacher['full_name'] ?? '' );
                        $teacher_data['teacher_group'] = $teacher_data['teacher_group'] !== '' ? $teacher_data['teacher_group'] : (string) ( $existing_teacher['teacher_group'] ?? '' );
                        $teacher_data['contact_details'] = $teacher_data['contact_details'] !== '' ? $teacher_data['contact_details'] : (string) ( $existing_teacher['contact_details'] ?? '' );

                        $existing_subjects = json_decode( (string) ( $existing_teacher['subjects'] ?? '[]' ), true );
                        $existing_classes = json_decode( (string) ( $existing_teacher['assigned_classes'] ?? '[]' ), true );
                        if ( empty( $teacher_data['subjects'] ) && is_array( $existing_subjects ) ) {
                            $teacher_data['subjects'] = $existing_subjects;
                        }
                        if ( empty( $teacher_data['assigned_classes'] ) && is_array( $existing_classes ) ) {
                            $teacher_data['assigned_classes'] = $existing_classes;
                        }
                    }

                    $updated = $this->teacher_service->update_teacher( $current_school_id, $teacher_row_id, $teacher_data );
                    $message = $updated
                        ? esc_html__( 'Teacher assignments updated successfully.', 'educbt-pro' )
                        : esc_html__( 'Unable to update teacher assignments.', 'educbt-pro' );
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_teacher' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_teacher' ) ) {
                $teacher_data = [
                    'teacher_id'       => sanitize_text_field( wp_unslash( $_POST['teacher_id'] ?? '' ) ),
                    'full_name'        => sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) ),
                    'teacher_group'    => sanitize_text_field( wp_unslash( $_POST['teacher_group'] ?? '' ) ),
                    'contact_details'  => sanitize_textarea_field( wp_unslash( $_POST['contact_details'] ?? '' ) ),
                    'subjects'         => $this->convert_csv_to_array( wp_unslash( $_POST['subjects'] ?? '' ) ),
                    'assigned_classes' => $this->convert_csv_to_array( wp_unslash( $_POST['assigned_classes'] ?? '' ) ),
                ];

                if ( $current_school_id <= 0 ) {
                    $message = esc_html__( 'Select a school context before adding teachers.', 'educbt-pro' );
                } else {
                    $teacher_id = $this->teacher_service->create_teacher( $current_school_id, $teacher_data );
                    if ( $teacher_id ) {
                        $message = esc_html__( 'Teacher created successfully.', 'educbt-pro' );
                    } else {
                        $message = esc_html__( 'Unable to create teacher.', 'educbt-pro' );
                    }
                }
            }
        }

        $teachers = $this->teacher_service->list_teachers( $current_school_id );
        $teacher_options = [];
        foreach ( $teachers as $teacher ) {
            $teacher_row_id = absint( $teacher['id'] ?? 0 );
            $teacher_name = sanitize_text_field( (string) ( $teacher['full_name'] ?? '' ) );
            $teacher_code = sanitize_text_field( (string) ( $teacher['teacher_id'] ?? '' ) );
            if ( $teacher_row_id > 0 ) {
                $teacher_options[ $teacher_row_id ] = trim( $teacher_name . ( $teacher_code !== '' ? ' (' . $teacher_code . ')' : '' ) );
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Teachers', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Add Teacher', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_teacher', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_teacher">';
        echo '<table class="form-table">';
        $this->render_input_row( 'teacher_id', __( 'Teacher ID', 'educbt-pro' ) );
        $this->render_input_row( 'full_name', __( 'Full Name', 'educbt-pro' ) );
        $this->render_input_row( 'teacher_group', __( 'Teacher Group (Science/Commercial/Art)', 'educbt-pro' ) );
        $this->render_textarea_row( 'contact_details', __( 'Contact Details', 'educbt-pro' ) );
        $this->render_input_row( 'subjects', __( 'Subjects (comma separated)', 'educbt-pro' ) );
        $this->render_input_row( 'assigned_classes', __( 'Assigned Classes (comma separated)', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Add Teacher', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Update Teacher Assignments', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Use this form to adjust subject and class assignments for an existing teacher record.', 'educbt-pro' ) . '</p>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_update_teacher_assignments', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="update_teacher_assignments">';
        echo '<table class="form-table">';
        $this->render_select_row_with_value( 'teacher_row_id', __( 'Teacher Record', 'educbt-pro' ), $teacher_options, '', true );
        $this->render_input_row( 'teacher_id', __( 'Teacher ID', 'educbt-pro' ) );
        $this->render_input_row( 'full_name', __( 'Full Name', 'educbt-pro' ) );
        $this->render_input_row( 'teacher_group', __( 'Teacher Group', 'educbt-pro' ) );
        $this->render_textarea_row( 'contact_details', __( 'Contact Details', 'educbt-pro' ) );
        $this->render_input_row( 'subjects', __( 'Subjects (comma separated)', 'educbt-pro' ) );
        $this->render_input_row( 'assigned_classes', __( 'Assigned Classes (comma separated)', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Save Teacher Assignments', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Registered Teachers', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Teacher ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Name', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Group', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Subjects', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Classes', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $teachers ) ) {
            echo '<tr><td colspan="6">' . esc_html__( 'No teachers registered yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $teachers as $teacher ) {
                echo '<tr>';
                echo '<td>' . esc_html( $teacher['id'] ) . '</td>';
                echo '<td>' . esc_html( $teacher['teacher_id'] ) . '</td>';
                echo '<td>' . esc_html( $teacher['full_name'] ) . '</td>';
                echo '<td>' . esc_html( $teacher['teacher_group'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( implode( ', ', json_decode( $teacher['subjects'], true ) ?: [] ) ) . '</td>';
                echo '<td>' . esc_html( implode( ', ', json_decode( $teacher['assigned_classes'], true ) ?: [] ) ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_classes_page(): void {
        $message = '';
        $current_school_id = $this->get_current_school_id();

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_class' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_class' ) ) {
                $class_data = [
                    'class_name'  => sanitize_text_field( wp_unslash( $_POST['class_name'] ?? '' ) ),
                    'arm'         => sanitize_text_field( wp_unslash( $_POST['arm'] ?? '' ) ),
                    'class_level' => sanitize_text_field( wp_unslash( $_POST['class_level'] ?? '' ) ),
                    'status'      => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) ),
                ];

                $class_id = $this->class_service->create_class( $current_school_id, $class_data );
                if ( $class_id ) {
                    $message = esc_html__( 'Class created successfully.', 'educbt-pro' );
                } else {
                    $message = esc_html__( 'Unable to create class.', 'educbt-pro' );
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'seed_default_classes' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_seed_default_classes' ) ) {
                $seeded = $this->class_service->seed_default_classes( $current_school_id );
                $message = sprintf( esc_html__( 'Standard class architecture synced. %d class records ensured/updated.', 'educbt-pro' ), absint( $seeded ) );
            }
        }

        $classes = $this->class_service->list_classes( $current_school_id );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Classes', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Add Class', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_class', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_class">';
        echo '<table class="form-table">';
        $this->render_input_row( 'class_name', __( 'Class Name', 'educbt-pro' ) );
        $this->render_input_row( 'arm', __( 'Arm', 'educbt-pro' ) );
        $this->render_input_row( 'class_level', __( 'Class Level', 'educbt-pro' ) );
        $this->render_input_row( 'status', __( 'Status', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Add Class', 'educbt-pro' ) );
        echo '</form>';

        echo '<form method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_seed_default_classes', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="seed_default_classes">';
        submit_button( __( 'Sync Standard JSS/SS Class Architecture', 'educbt-pro' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Registered Classes', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Class Name', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Arm', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Level', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Status', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $classes ) ) {
            echo '<tr><td colspan="5">' . esc_html__( 'No classes registered yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $classes as $class ) {
                echo '<tr>';
                echo '<td>' . esc_html( $class['id'] ) . '</td>';
                echo '<td>' . esc_html( $class['class_name'] ) . '</td>';
                echo '<td>' . esc_html( $class['arm'] ) . '</td>';
                echo '<td>' . esc_html( $class['class_level'] ) . '</td>';
                echo '<td>' . esc_html( $class['status'] ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_subjects_page(): void {
        $message = '';
        $current_school_id = $this->get_current_school_id();

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_subject' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_subject' ) ) {
                $subject_data = [
                    'subject_name' => $this->subject_service->canonicalize_subject_name( $current_school_id, sanitize_text_field( wp_unslash( $_POST['subject_name'] ?? '' ) ) ),
                    'subject_code' => sanitize_text_field( wp_unslash( $_POST['subject_code'] ?? '' ) ),
                    'subject_type' => sanitize_text_field( wp_unslash( $_POST['subject_type'] ?? 'core' ) ),
                ];

                if ( $current_school_id <= 0 ) {
                    $message = esc_html__( 'Select a school context before adding subjects.', 'educbt-pro' );
                } else {
                    $subject_id = $this->subject_service->create_subject( $current_school_id, $subject_data );
                    if ( $subject_id ) {
                        $message = esc_html__( 'Subject created successfully.', 'educbt-pro' );
                    } else {
                        $message = esc_html__( 'Unable to create subject.', 'educbt-pro' );
                    }
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'seed_default_subjects' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_seed_subjects' ) ) {
                $seeded = $this->subject_service->seed_default_subjects( $current_school_id );
                $message = sprintf( esc_html__( 'Default subject catalog synced. %d records ensured.', 'educbt-pro' ), absint( $seeded ) );
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'save_department_subject_map' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_save_department_subject_map' ) ) {
                $department_map = [
                    'Science' => $this->convert_csv_to_array( (string) wp_unslash( $_POST['dept_science_subjects'] ?? '' ) ),
                    'Commercial' => $this->convert_csv_to_array( (string) wp_unslash( $_POST['dept_commercial_subjects'] ?? '' ) ),
                    'Arts' => $this->convert_csv_to_array( (string) wp_unslash( $_POST['dept_arts_subjects'] ?? '' ) ),
                ];

                $saved = $this->school_service->update_department_subject_map( $current_school_id, $department_map );
                $message = $saved
                    ? esc_html__( 'Department subject combinations updated.', 'educbt-pro' )
                    : esc_html__( 'Unable to update department subject combinations.', 'educbt-pro' );
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'save_class_subject_allocation' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_save_class_subject_allocation' ) ) {
                $class_allocation = [
                    'class_structure' => $this->convert_csv_to_array( (string) wp_unslash( $_POST['class_structure'] ?? '' ) ),
                    'jss_compulsory_subjects' => $this->convert_csv_to_array( (string) wp_unslash( $_POST['jss_compulsory_subjects'] ?? '' ) ),
                ];

                $saved = $this->school_service->update_class_subject_allocation( $current_school_id, $class_allocation );
                $message = $saved
                    ? esc_html__( 'Class subject allocation updated.', 'educbt-pro' )
                    : esc_html__( 'Unable to update class subject allocation.', 'educbt-pro' );
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'save_subject_aliases' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_save_subject_aliases' ) ) {
                $raw_aliases = sanitize_textarea_field( wp_unslash( $_POST['subject_aliases'] ?? '' ) );
                $parsed_aliases = $this->parse_subject_alias_mapping_text( $raw_aliases );
                $saved = $this->school_service->update_subject_aliases( $current_school_id, $parsed_aliases );
                $message = $saved
                    ? esc_html__( 'Subject alias mappings saved.', 'educbt-pro' )
                    : esc_html__( 'Unable to save subject alias mappings.', 'educbt-pro' );
            }
        }

        $subjects = $this->subject_service->list_subjects( $current_school_id );
        $academic_settings = $this->school_service->get_school_academic_settings( $current_school_id );
        $class_structure = isset( $academic_settings['class_structure'] ) && is_array( $academic_settings['class_structure'] ) ? $academic_settings['class_structure'] : [];
        $jss_compulsory_subjects = isset( $academic_settings['jss_compulsory_subjects'] ) && is_array( $academic_settings['jss_compulsory_subjects'] ) ? $academic_settings['jss_compulsory_subjects'] : [];
        $department_map = isset( $academic_settings['departments'] ) && is_array( $academic_settings['departments'] ) ? $academic_settings['departments'] : [];
        $custom_aliases = $this->school_service->get_subject_aliases( $current_school_id );
        $alias_lines = [];
        foreach ( $custom_aliases as $alias => $canonical ) {
            $alias_lines[] = $alias . ' => ' . $canonical;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Subjects', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Add Subject', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_add_subject', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_subject">';
        echo '<table class="form-table">';
        $this->render_input_row( 'subject_name', __( 'Subject Name', 'educbt-pro' ) );
        $this->render_input_row( 'subject_code', __( 'Subject Code', 'educbt-pro' ) );
        $this->render_input_row( 'subject_type', __( 'Subject Type (core/elective)', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Add Subject', 'educbt-pro' ) );
        echo '</form>';

        echo '<form method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_seed_subjects', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="seed_default_subjects">';
        submit_button( __( 'Sync Default WAEC/NECO Subjects', 'educbt-pro' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Class Subject Allocation (JSS)', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Use this section to control the class structure and the compulsory JSS subject pool used by student allocation and question coverage checks.', 'educbt-pro' ) . '</p>';
        echo '<form method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_save_class_subject_allocation', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="save_class_subject_allocation">';
        echo '<table class="form-table">';
        $this->render_textarea_row_with_value( 'class_structure', __( 'Class Structure (comma separated)', 'educbt-pro' ), implode( ', ', (array) $class_structure ) );
        $this->render_textarea_row_with_value( 'jss_compulsory_subjects', __( 'JSS Compulsory Subjects (comma separated)', 'educbt-pro' ), implode( ', ', (array) $jss_compulsory_subjects ) );
        echo '</table>';
        submit_button( __( 'Save Class Allocation', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Department Subject Combinations (SS Classes)', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_save_department_subject_map', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="save_department_subject_map">';
        echo '<table class="form-table">';
        $this->render_textarea_row_with_value( 'dept_science_subjects', __( 'Science Subjects (comma separated)', 'educbt-pro' ), implode( ', ', (array) ( $department_map['Science'] ?? [] ) ) );
        $this->render_textarea_row_with_value( 'dept_commercial_subjects', __( 'Commercial Subjects (comma separated)', 'educbt-pro' ), implode( ', ', (array) ( $department_map['Commercial'] ?? [] ) ) );
        $this->render_textarea_row_with_value( 'dept_arts_subjects', __( 'Arts Subjects (comma separated)', 'educbt-pro' ), implode( ', ', (array) ( $department_map['Arts'] ?? [] ) ) );
        echo '</table>';
        submit_button( __( 'Save Department Subject Combinations', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Registered Subjects', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Name', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Code', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Type', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $subjects ) ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No subjects registered yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $subjects as $subject ) {
                echo '<tr>';
                echo '<td>' . esc_html( $subject['id'] ) . '</td>';
                echo '<td>' . esc_html( $subject['subject_name'] ) . '</td>';
                echo '<td>' . esc_html( $subject['subject_code'] ) . '</td>';
                echo '<td>' . esc_html( $subject['subject_type'] ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<h2>' . esc_html__( 'Subject Alias Normalization', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Use one mapping per line in the format: alias => Canonical Subject Name. These mappings are used during question imports.', 'educbt-pro' ) . '</p>';
        echo '<form method="post" action="" style="margin:12px 0 20px;">';
        wp_nonce_field( 'educbt_pro_save_subject_aliases', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="save_subject_aliases">';
        $this->render_textarea_row_with_value( 'subject_aliases', __( 'Alias Mappings', 'educbt-pro' ), implode( "\n", $alias_lines ) );
        submit_button( __( 'Save Subject Alias Mappings', 'educbt-pro' ) );
        echo '</form>';

        echo '</div>';
    }

    public function render_questions_page(): void {
        $message = '';
        $alias_suggestions = [];
        $current_school_id = $this->get_current_school_id();

        if ( isset( $_POST['educbt_pro_action'] ) && 'save_subject_aliases_from_import' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_save_subject_aliases_from_import' ) ) {
                $raw_aliases = sanitize_textarea_field( wp_unslash( $_POST['suggested_aliases'] ?? '' ) );
                $parsed_aliases = $this->parse_subject_alias_mapping_text( $raw_aliases );
                $saved = $this->school_service->update_subject_aliases( $current_school_id, $parsed_aliases );
                $message = $saved
                    ? esc_html__( 'Suggested subject aliases saved and will be applied to next imports.', 'educbt-pro' )
                    : esc_html__( 'Unable to save suggested subject aliases.', 'educbt-pro' );
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'create_question' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_add_question' ) ) {
                $question_data = [
                    'subject'       => $this->subject_service->canonicalize_subject_name( $current_school_id, sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ) ),
                    'section'       => sanitize_text_field( wp_unslash( $_POST['section'] ?? '' ) ),
                    'passage_text'  => sanitize_textarea_field( wp_unslash( $_POST['passage_text'] ?? '' ) ),
                    'topic'         => sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) ),
                    'sub_topic'     => sanitize_text_field( wp_unslash( $_POST['sub_topic'] ?? '' ) ),
                    'class'         => sanitize_text_field( wp_unslash( $_POST['class'] ?? '' ) ),
                    'department'    => sanitize_text_field( wp_unslash( $_POST['department'] ?? '' ) ),
                    'difficulty'    => sanitize_text_field( wp_unslash( $_POST['difficulty'] ?? '' ) ),
                    'learning_objective' => sanitize_text_field( wp_unslash( $_POST['learning_objective'] ?? '' ) ),
                    'bloom_level'   => sanitize_text_field( wp_unslash( $_POST['bloom_level'] ?? '' ) ),
                    'examination_type' => sanitize_text_field( wp_unslash( $_POST['examination_type'] ?? '' ) ),
                    'examination_year' => sanitize_text_field( wp_unslash( $_POST['examination_year'] ?? '' ) ),
                    'question_type' => sanitize_text_field( wp_unslash( $_POST['question_type'] ?? '' ) ),
                    'estimated_duration' => absint( wp_unslash( $_POST['estimated_duration'] ?? 0 ) ),
                    'marks'         => floatval( wp_unslash( $_POST['marks'] ?? 0 ) ),
                    'image_reference' => sanitize_text_field( wp_unslash( $_POST['image_reference'] ?? '' ) ),
                    'question_text' => sanitize_textarea_field( wp_unslash( $_POST['question_text'] ?? '' ) ),
                    'options'       => $this->convert_csv_to_array( wp_unslash( $_POST['options'] ?? '' ) ),
                    'answers'       => $this->convert_csv_to_array( wp_unslash( $_POST['answers'] ?? '' ) ),
                    'explanations'  => sanitize_textarea_field( wp_unslash( $_POST['explanations'] ?? '' ) ),
                ];

                $question_id = $current_school_id > 0 ? $this->question_service->create_question( $current_school_id, $question_data ) : 0;
                if ( $question_id ) {
                    $message = esc_html__( 'Question created successfully.', 'educbt-pro' );
                } else {
                    $message = $current_school_id > 0
                        ? esc_html__( 'Unable to create question. Ensure at least one meaningful question field is filled.', 'educbt-pro' )
                        : esc_html__( 'Select a school context before adding questions.', 'educbt-pro' );
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ), [ 'upload_objective_csv', 'upload_theory_csv' ], true ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_upload_questions_csv' ) ) {
                $is_theory = sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) === 'upload_theory_csv';
                $upload_report = $this->handle_questions_csv_upload( $current_school_id, $is_theory );
                $uploaded = absint( $upload_report['imported'] ?? 0 );
                $duplicates = absint( $upload_report['duplicates'] ?? 0 );
                $failed = absint( $upload_report['failed'] ?? 0 );
                $processed = absint( $upload_report['processed'] ?? 0 );
                $alias_suggestions = is_array( $upload_report['subject_alias_suggestions'] ?? null ) ? $upload_report['subject_alias_suggestions'] : [];
                $message = sprintf(
                    esc_html__( 'Upload completed. Processed: %1$d. Imported: %2$d. Duplicates skipped: %3$d. Failed/empty rows: %4$d.', 'educbt-pro' ),
                    $processed,
                    $uploaded,
                    $duplicates,
                    $failed
                );

                if ( ! empty( $alias_suggestions ) ) {
                    $message .= ' ' . sprintf(
                        esc_html__( 'Detected %d unrecognized subject variant(s). Review and save suggested mappings below.', 'educbt-pro' ),
                        count( $alias_suggestions )
                    );
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'import_default_questions' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_import_default_questions' ) ) {
                $seed_report = $this->import_default_question_sets( $current_school_id );
                $message = sprintf(
                    esc_html__( 'Default import completed. Files: %1$d. Processed: %2$d. Imported: %3$d. Duplicates skipped: %4$d. Failed/empty rows: %5$d.', 'educbt-pro' ),
                    absint( $seed_report['files'] ?? 0 ),
                    absint( $seed_report['processed'] ?? 0 ),
                    absint( $seed_report['imported'] ?? 0 ),
                    absint( $seed_report['duplicates'] ?? 0 ),
                    absint( $seed_report['failed'] ?? 0 )
                );
            }
        }

        $questions = $this->question_service->list_questions( $current_school_id );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Questions', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Add Question', 'educbt-pro' ) . '</h2>';
        echo '<form method="post" action="" enctype="multipart/form-data">';
        wp_nonce_field( 'educbt_pro_add_question', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="create_question">';
        echo '<table class="form-table">';
        $this->render_input_row( 'subject', __( 'Subject', 'educbt-pro' ) );
        $this->render_input_row( 'section', __( 'Section (e.g. Grammar, Comprehension)', 'educbt-pro' ) );
        $this->render_textarea_row( 'passage_text', __( 'Passage Text (for comprehension questions)', 'educbt-pro' ) );
        $this->render_input_row( 'topic', __( 'Topic', 'educbt-pro' ) );
        $this->render_input_row( 'sub_topic', __( 'Sub-topic', 'educbt-pro' ) );
        $this->render_input_row( 'class', __( 'Class', 'educbt-pro' ) );
        $this->render_input_row( 'department', __( 'Department', 'educbt-pro' ) );
        $this->render_input_row( 'difficulty', __( 'Difficulty', 'educbt-pro' ) );
        $this->render_input_row( 'learning_objective', __( 'Learning Objective', 'educbt-pro' ) );
        $this->render_input_row( 'bloom_level', __( 'Bloom Taxonomy Level', 'educbt-pro' ) );
        $this->render_input_row( 'examination_type', __( 'Examination Type', 'educbt-pro' ) );
        $this->render_input_row( 'examination_year', __( 'Examination Year', 'educbt-pro' ) );
        $this->render_input_row( 'question_type', __( 'Question Type', 'educbt-pro' ) );
        $this->render_input_row( 'estimated_duration', __( 'Estimated Duration (seconds)', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'marks', __( 'Marks', 'educbt-pro' ), 'number' );
        $this->render_input_row( 'image_reference', __( 'Image Reference (URL or asset key)', 'educbt-pro' ) );
        $this->render_textarea_row( 'question_text', __( 'Question Text', 'educbt-pro' ) );
        $this->render_textarea_row( 'options', __( 'Options (comma separated)', 'educbt-pro' ) );
        $this->render_textarea_row( 'answers', __( 'Answers (comma separated)', 'educbt-pro' ) );
        $this->render_textarea_row( 'explanations', __( 'Explanations', 'educbt-pro' ) );
        echo '</table>';
        submit_button( __( 'Add Question', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Bulk Upload Questions (CSV)', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Upload supports CSV and XLSX. Partial rows are accepted when at least one meaningful field is provided. Missing fields are omitted.', 'educbt-pro' ) . '</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=educbt_download_question_template&type=objective' ), 'educbt_download_question_template' ) ) . '">' . esc_html__( 'Download Objective Template', 'educbt-pro' ) . '</a> ';
        echo '<a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=educbt_download_question_template&type=theory' ), 'educbt_download_question_template' ) ) . '">' . esc_html__( 'Download Theory Template', 'educbt-pro' ) . '</a></p>';

        echo '<form method="post" action="" style="margin:10px 0 12px;">';
        wp_nonce_field( 'educbt_pro_import_default_questions', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="import_default_questions">';
        submit_button( __( 'Import Bundled Default Questions', 'educbt-pro' ), 'primary', 'submit', false );
        echo '</form>';

        echo '<form method="post" action="" enctype="multipart/form-data" style="margin-bottom:10px;">';
        wp_nonce_field( 'educbt_pro_upload_questions_csv', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="upload_objective_csv">';
        echo '<input type="file" name="questions_csv" accept=".csv,.xlsx" required> ';
        submit_button( __( 'Upload Objective CSV', 'educbt-pro' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '<form method="post" action="" enctype="multipart/form-data" style="margin-bottom:20px;">';
        wp_nonce_field( 'educbt_pro_upload_questions_csv', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="upload_theory_csv">';
        echo '<input type="file" name="questions_csv" accept=".csv,.xlsx" required> ';
        submit_button( __( 'Upload Theory CSV', 'educbt-pro' ), 'secondary', 'submit', false );
        echo '</form>';

        if ( ! empty( $alias_suggestions ) ) {
            $lines = [];
            foreach ( $alias_suggestions as $alias => $canonical ) {
                $lines[] = sanitize_text_field( (string) $alias ) . ' => ' . sanitize_text_field( (string) $canonical );
            }

            echo '<h3>' . esc_html__( 'Suggested Subject Alias Mappings', 'educbt-pro' ) . '</h3>';
            echo '<p>' . esc_html__( 'Confirm or edit mappings, then save. Format: alias => Canonical Subject.', 'educbt-pro' ) . '</p>';
            echo '<form method="post" action="" style="margin-bottom:20px;">';
            wp_nonce_field( 'educbt_pro_save_subject_aliases_from_import', 'educbt_pro_nonce' );
            echo '<input type="hidden" name="educbt_pro_action" value="save_subject_aliases_from_import">';
            $this->render_textarea_row_with_value( 'suggested_aliases', __( 'Suggested Mappings', 'educbt-pro' ), implode( "\n", $lines ) );
            submit_button( __( 'Save Suggested Mappings', 'educbt-pro' ), 'secondary' );
            echo '</form>';
        }

        echo '<h2>' . esc_html__( 'Question Bank', 'educbt-pro' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Subject', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Topic', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Type', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $questions ) ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No questions added yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $questions as $question ) {
                echo '<tr>';
                echo '<td>' . esc_html( $question['id'] ) . '</td>';
                echo '<td>' . esc_html( $question['subject'] ) . '</td>';
                echo '<td>' . esc_html( $question['topic'] ) . '</td>';
                echo '<td>' . esc_html( $question['question_type'] ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_question_submission_verification_page(): void {
        $current_school_id = $this->get_current_school_id();
        $verification_dashboard = $current_school_id > 0
            ? ( $this->question_analytics_service->analyze_questions( $current_school_id )['verification_dashboard'] ?? [] )
            : [];
        $message = '';
        $report_rows = [];
        $notified_count = 0;
        $duplicate_preview = [];
        $duplicate_removable_count = 0;
        $duplicate_unlink_count = 0;

        $verification_session_year = '';
        $verification_term = '';
        $verification_class_name = '';
        $verification_department = '';
        $verification_min_questions = 40;
        $verification_auto_notify = false;

        $cleanup_session_year = '';
        $cleanup_class_name = '';
        $cleanup_department = '';
        $cleanup_subject = '';

        $classes = $this->class_service->list_classes( $current_school_id );
        $class_options = [];
        foreach ( $classes as $class_row ) {
            $class_name = sanitize_text_field( (string) ( $class_row['class_name'] ?? '' ) );
            if ( $class_name !== '' ) {
                $class_options[ $class_name ] = $class_name;
            }
        }

        $academic_settings = $this->school_service->get_school_academic_settings( $current_school_id );
        $department_options = [];
        if ( isset( $academic_settings['departments'] ) && is_array( $academic_settings['departments'] ) ) {
            foreach ( array_keys( $academic_settings['departments'] ) as $department_name ) {
                $department_name = sanitize_text_field( (string) $department_name );
                if ( $department_name !== '' ) {
                    $department_options[ $department_name ] = $department_name;
                }
            }
        }

        if ( empty( $department_options ) ) {
            $department_options = [ 'Science' => 'Science', 'Commercial' => 'Commercial', 'Arts' => 'Arts' ];
        }

        if ( isset( $_POST['educbt_pro_action'] ) && 'verify_question_submissions' === sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_verify_question_submissions' ) ) {
                $session_year = sanitize_text_field( wp_unslash( $_POST['verification_session_year'] ?? '' ) );
                $term = sanitize_text_field( wp_unslash( $_POST['verification_term'] ?? '' ) );
                $class_name = sanitize_text_field( wp_unslash( $_POST['verification_class_name'] ?? '' ) );
                $department = sanitize_text_field( wp_unslash( $_POST['verification_department'] ?? '' ) );
                $min_questions = max( 40, absint( wp_unslash( $_POST['verification_min_questions'] ?? 40 ) ) );
                $auto_notify = isset( $_POST['verification_auto_notify'] );

                $verification_session_year = $session_year;
                $verification_term = $term;
                $verification_class_name = $class_name;
                $verification_department = $department;
                $verification_min_questions = $min_questions;
                $verification_auto_notify = $auto_notify;

                $questions = $this->question_service->list_questions( $current_school_id );
                $teacher_assignments = $this->build_teacher_assignment_index( $current_school_id );
                $target_subjects = $this->resolve_verification_subject_targets( $academic_settings, $class_name, $department, $questions, $teacher_assignments, $session_year );

                foreach ( $target_subjects as $subject_name ) {
                    $objective_stats = $this->collect_objective_question_integrity( $questions, $subject_name, $class_name, $department, $session_year );
                    $count = absint( $objective_stats['unique_count'] ?? 0 );
                    $duplicates = absint( $objective_stats['duplicate_count'] ?? 0 );
                    $similar = absint( $objective_stats['similar_count'] ?? 0 );
                    $integrity_score = max( 0, min( 100, absint( $objective_stats['integrity_score'] ?? 100 ) ) );
                    $is_pending = $count < $min_questions;
                    $remaining = max( 0, $min_questions - $count );
                    $teacher = $this->resolve_responsible_teacher_for_subject_class( $teacher_assignments, $subject_name, $class_name );
                    $teacher_id = absint( $teacher['id'] ?? 0 );
                    $pending_alerts = $teacher_id > 0 ? $this->notification_service->get_unread_count( $current_school_id, $teacher_id ) : 0;

                    $report_rows[] = [
                        'class_name' => $class_name,
                        'subject' => $subject_name,
                        'submitted_count' => $count,
                        'required_count' => $min_questions,
                        'remaining_count' => $remaining,
                        'duplicate_count' => $duplicates,
                        'similar_count' => $similar,
                        'integrity_score' => $integrity_score,
                        'is_pending' => $is_pending,
                        'teacher_id' => $teacher_id,
                        'teacher_name' => sanitize_text_field( (string) ( $teacher['full_name'] ?? 'Unassigned' ) ),
                        'teacher_pending_alerts' => $pending_alerts,
                    ];
                }

                if ( $auto_notify ) {
                    $notified_count = $this->notify_teachers_for_pending_subjects( $current_school_id, $report_rows, $term, $session_year, $class_name, $department );
                }

                $pending_count = count( array_filter( $report_rows, static fn( array $row ): bool => ! empty( $row['is_pending'] ) ) );
                $message = sprintf(
                    esc_html__( 'Verification completed. Pending subjects: %1$d.', 'educbt-pro' ),
                    absint( $pending_count )
                );

                if ( $auto_notify ) {
                    $message .= ' ' . sprintf( esc_html__( 'Teacher notifications sent: %d.', 'educbt-pro' ), absint( $notified_count ) );
                }
            }
        }

        if ( isset( $_POST['educbt_pro_action'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ) ), [ 'preview_duplicate_objective_questions', 'purge_duplicate_objective_questions' ], true ) ) {
            if ( isset( $_POST['educbt_pro_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['educbt_pro_nonce'] ), 'educbt_pro_duplicate_question_cleanup' ) ) {
                $cleanup_action = sanitize_text_field( wp_unslash( $_POST['educbt_pro_action'] ?? '' ) );
                $cleanup_session_year = sanitize_text_field( wp_unslash( $_POST['cleanup_session_year'] ?? '' ) );
                $cleanup_class_name = sanitize_text_field( wp_unslash( $_POST['cleanup_class_name'] ?? '' ) );
                $cleanup_department = sanitize_text_field( wp_unslash( $_POST['cleanup_department'] ?? '' ) );
                $cleanup_subject = sanitize_text_field( wp_unslash( $_POST['cleanup_subject'] ?? '' ) );

                $questions = $this->question_service->list_questions( $current_school_id );
                $duplicate_preview = $this->find_duplicate_objective_questions_for_scope( $questions, $cleanup_session_year, $cleanup_class_name, $cleanup_department, $cleanup_subject );
                $ids_to_delete = [];
                foreach ( $duplicate_preview as $group ) {
                    $ids_to_delete = array_merge( $ids_to_delete, array_map( 'absint', (array) ( $group['remove_ids'] ?? [] ) ) );
                }
                $ids_to_delete = array_values( array_unique( array_filter( $ids_to_delete ) ) );
                $duplicate_removable_count = count( $ids_to_delete );
                $duplicate_unlink_count = $this->count_exam_links_for_questions( $current_school_id, $ids_to_delete );

                if ( $cleanup_action === 'preview_duplicate_objective_questions' ) {
                    $dup_count = count( $duplicate_preview );

                    $message = sprintf(
                        esc_html__( 'Duplicate preview ready. Groups: %1$d. Removable objective duplicates: %2$d. Exam links to unlink: %3$d.', 'educbt-pro' ),
                        absint( $dup_count ),
                        absint( $duplicate_removable_count ),
                        absint( $duplicate_unlink_count )
                    );
                }

                if ( $cleanup_action === 'purge_duplicate_objective_questions' ) {
                    $confirm_purge = isset( $_POST['cleanup_confirm_purge'] );
                    if ( ! $confirm_purge ) {
                        $message = esc_html__( 'Tick confirmation before purging duplicates.', 'educbt-pro' );
                    } else {
                        $deleted = $this->delete_questions_by_ids( $current_school_id, $ids_to_delete );
                        $message = sprintf(
                            esc_html__( 'Duplicate purge completed. Deleted objective duplicate questions: %1$d. Exam links unlinked: %2$d.', 'educbt-pro' ),
                            absint( $deleted['deleted_questions'] ?? 0 ),
                            absint( $deleted['deleted_links'] ?? 0 )
                        );

                        $questions_after = $this->question_service->list_questions( $current_school_id );
                        $duplicate_preview = $this->find_duplicate_objective_questions_for_scope( $questions_after, $cleanup_session_year, $cleanup_class_name, $cleanup_department, $cleanup_subject );
                    }
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Exam Question Submission Verification', 'educbt-pro' ) . '</h1>';
        $this->render_school_selector();

        if ( ! empty( $verification_dashboard ) ) {
            $overall = (array) ( $verification_dashboard['overall'] ?? [] );
            $draft_vs_published = (array) ( $verification_dashboard['draft_vs_published_ratio'] ?? [] );
            echo '<div style="margin:16px 0;padding:16px;border:1px solid #dcdcde;background:#fff;border-radius:8px;">';
            echo '<h2 style="margin-top:0;">' . esc_html__( 'Question Verification Dashboard', 'educbt-pro' ) . '</h2>';
            echo '<p>' . esc_html__( 'A quick school-wide view of question bank readiness, publication status, and data quality.', 'educbt-pro' ) . '</p>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">';
            foreach ( [
                [ 'label' => __( 'Total Questions', 'educbt-pro' ), 'value' => absint( $overall['total_questions'] ?? 0 ) ],
                [ 'label' => __( 'Published', 'educbt-pro' ), 'value' => absint( $overall['published_questions'] ?? 0 ) ],
                [ 'label' => __( 'Draft', 'educbt-pro' ), 'value' => absint( $overall['draft_questions'] ?? 0 ) ],
                [ 'label' => __( 'Draft / Published', 'educbt-pro' ), 'value' => absint( $draft_vs_published['draft'] ?? 0 ) . ' / ' . absint( $draft_vs_published['published'] ?? 0 ) ],
                [ 'label' => __( 'Duplicate Groups', 'educbt-pro' ), 'value' => absint( $verification_dashboard['duplicate_question_detection']['duplicate_groups'] ?? 0 ) ],
                [ 'label' => __( 'Missing Topic', 'educbt-pro' ), 'value' => absint( $verification_dashboard['missing_topic_analysis']['missing_topic_count'] ?? 0 ) ],
            ] as $tile ) {
                echo '<div style="padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;">';
                echo '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;">' . esc_html( (string) $tile['label'] ) . '</div>';
                echo '<div style="font-size:24px;font-weight:700;margin-top:6px;">' . esc_html( (string) $tile['value'] ) . '</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';

            $subject_coverage = (array) ( $verification_dashboard['subject_coverage_matrix'] ?? [] );
            if ( ! empty( $subject_coverage ) ) {
                echo '<h3>' . esc_html__( 'Subject Coverage', 'educbt-pro' ) . '</h3>';
                echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'Subject', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Published', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Draft', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Required', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Completion', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Missing', 'educbt-pro' ) . '</th></tr></thead><tbody>';
                foreach ( $subject_coverage as $subject_name => $row ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( (string) $subject_name ) . '</td>';
                    echo '<td>' . esc_html( (string) absint( $row['published'] ?? 0 ) ) . '</td>';
                    echo '<td>' . esc_html( (string) absint( $row['draft'] ?? 0 ) ) . '</td>';
                    echo '<td>' . esc_html( (string) absint( $row['required_questions'] ?? 0 ) ) . '</td>';
                    echo '<td>' . esc_html( number_format_i18n( (float) ( $row['completion_percentage'] ?? 0 ), 2 ) ) . '%</td>';
                    echo '<td>' . esc_html( (string) absint( $row['missing_questions'] ?? 0 ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }

        if ( $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        echo '<form method="post" action="">';
        wp_nonce_field( 'educbt_pro_verify_question_submissions', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="verify_question_submissions">';
        echo '<table class="form-table">';
        $this->render_input_row_with_value( 'verification_session_year', __( 'Session Year', 'educbt-pro' ), $verification_session_year );
        $this->render_input_row_with_value( 'verification_term', __( 'Term', 'educbt-pro' ), $verification_term );
        $this->render_select_row_with_value( 'verification_class_name', __( 'Class', 'educbt-pro' ), $class_options, $verification_class_name, true );
        $this->render_select_row_with_value( 'verification_department', __( 'Department', 'educbt-pro' ), $department_options, $verification_department, true );
        $this->render_input_row_with_value( 'verification_min_questions', __( 'Minimum Objective Questions Per Subject (minimum 40)', 'educbt-pro' ), (string) $verification_min_questions, 'number' );
        echo '<tr><th scope="row"><label for="verification_auto_notify">' . esc_html__( 'Notify Teachers For Pending Submissions', 'educbt-pro' ) . '</label></th><td><input name="verification_auto_notify" id="verification_auto_notify" type="checkbox" value="1"' . checked( $verification_auto_notify, true, false ) . '></td></tr>';
        echo '</table>';
        submit_button( __( 'Run Verification', 'educbt-pro' ) );
        echo '</form>';

        echo '<h2>' . esc_html__( 'Objective Duplicate Cleanup', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Preview duplicate objective questions first. Purge will remove only duplicates and keep the oldest item in each duplicate group. Theory questions are untouched.', 'educbt-pro' ) . '</p>';
        echo '<form method="post" action="" style="margin-top:8px;">';
        wp_nonce_field( 'educbt_pro_duplicate_question_cleanup', 'educbt_pro_nonce' );
        echo '<input type="hidden" name="educbt_pro_action" value="preview_duplicate_objective_questions">';
        echo '<table class="form-table">';
        $this->render_input_row_with_value( 'cleanup_session_year', __( 'Session Year (optional)', 'educbt-pro' ), $cleanup_session_year );
        $this->render_select_row_with_value( 'cleanup_class_name', __( 'Class (optional)', 'educbt-pro' ), $class_options, $cleanup_class_name );
        $this->render_select_row_with_value( 'cleanup_department', __( 'Department (optional)', 'educbt-pro' ), $department_options, $cleanup_department );
        $this->render_input_row_with_value( 'cleanup_subject', __( 'Subject (optional)', 'educbt-pro' ), $cleanup_subject );
        echo '<tr><th scope="row"><label for="cleanup_confirm_purge">' . esc_html__( 'Confirm Purge', 'educbt-pro' ) . '</label></th><td><input name="cleanup_confirm_purge" id="cleanup_confirm_purge" type="checkbox" value="1"> ' . esc_html__( 'I understand duplicate objective questions will be permanently removed.', 'educbt-pro' ) . '</td></tr>';
        echo '</table>';
        echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Preview Objective Duplicates', 'educbt-pro' ) . '</button> ';
        echo '<button type="submit" class="button button-link-delete" name="educbt_pro_action" value="purge_duplicate_objective_questions" onclick="return confirm(\'Proceed to permanently delete duplicate objective questions?\');">' . esc_html__( 'Purge Objective Duplicates', 'educbt-pro' ) . '</button>';
        echo '</form>';

        if ( ! empty( $duplicate_preview ) ) {
            echo '<div style="margin-top:10px;padding:10px 12px;border:1px solid #ccd0d4;background:#f6f7f7;border-radius:4px;">';
            echo '<strong>' . esc_html__( 'Dry-run Summary', 'educbt-pro' ) . '</strong>';
            echo '<p style="margin:6px 0 0;">' . esc_html( sprintf( __( 'Objective duplicates removable: %1$d. Exam-question links that would be unlinked: %2$d.', 'educbt-pro' ), absint( $duplicate_removable_count ), absint( $duplicate_unlink_count ) ) ) . '</p>';
            echo '</div>';
        }

        if ( ! empty( $duplicate_preview ) ) {
            echo '<h3>' . esc_html__( 'Duplicate Preview Results', 'educbt-pro' ) . '</h3>';
            echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'Subject', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Class', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Department', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Session', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Keep ID', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Remove IDs', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Question Text', 'educbt-pro' ) . '</th></tr></thead><tbody>';
            foreach ( $duplicate_preview as $group ) {
                echo '<tr>';
                echo '<td>' . esc_html( (string) ( $group['subject'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $group['class_name'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $group['department'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $group['session_year'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) absint( $group['keep_id'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( implode( ', ', array_map( static fn( $id ): string => (string) absint( $id ), (array) ( $group['remove_ids'] ?? [] ) ) ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $group['question_text'] ?? '' ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__( 'Verification Report', 'educbt-pro' ) . '</h2>';
        echo '<p>' . esc_html__( 'Objective questions only: duplicates are excluded from submitted count. Theory questions are not affected.', 'educbt-pro' ) . '</p>';
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__( 'Class', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Subject', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Responsible Teacher', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Alerts', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Submitted (Unique Objective)', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Required', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Remaining', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Duplicate Questions', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Similar Questions', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Integrity', 'educbt-pro' ) . '</th><th>' . esc_html__( 'Status', 'educbt-pro' ) . '</th></tr></thead><tbody>';

        if ( empty( $report_rows ) ) {
            echo '<tr><td colspan="11">' . esc_html__( 'No verification run yet.', 'educbt-pro' ) . '</td></tr>';
        } else {
            foreach ( $report_rows as $row ) {
                $status_label = ! empty( $row['is_pending'] ) ? esc_html__( 'Pending Submission', 'educbt-pro' ) : esc_html__( 'Complete', 'educbt-pro' );
                $status_badge = ! empty( $row['is_pending'] )
                    ? '<span style="display:inline-block;background:#fff4e5;color:#8a4b08;border:1px solid #f0cc94;border-radius:12px;padding:2px 8px;">' . esc_html( $status_label ) . '</span>'
                    : '<span style="display:inline-block;background:#edf7ed;color:#23682f;border:1px solid #b6dfb6;border-radius:12px;padding:2px 8px;">&#10004; ' . esc_html( $status_label ) . '</span>';
                $integrity_score = absint( $row['integrity_score'] ?? 100 );
                $integrity_style = $integrity_score >= 85 ? '#23682f' : ( $integrity_score >= 70 ? '#8a6d1f' : '#b32d2e' );
                $teacher_name = sanitize_text_field( (string) ( $row['teacher_name'] ?? 'Unassigned' ) );
                $teacher_id = absint( $row['teacher_id'] ?? 0 );
                $teacher_label = $teacher_id > 0 ? $teacher_name . ' (#' . $teacher_id . ')' : $teacher_name;
                $pending_alerts = absint( $row['teacher_pending_alerts'] ?? 0 );
                $alerts_badge = '<span style="display:inline-block;min-width:24px;text-align:center;background:#f0f0f1;color:#1d2327;border-radius:999px;padding:2px 8px;font-weight:600;">' . esc_html( (string) $pending_alerts ) . '</span>';
                echo '<tr>';
                echo '<td>' . esc_html( (string) ( $row['class_name'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['subject'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( $teacher_label ) . '</td>';
                echo '<td>' . $alerts_badge . '</td>';
                echo '<td>' . esc_html( (string) ( $row['submitted_count'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['required_count'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['remaining_count'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['duplicate_count'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['similar_count'] ?? 0 ) ) . '</td>';
                echo '<td><span style="font-weight:600;color:' . esc_attr( $integrity_style ) . ';">' . esc_html( (string) $integrity_score ) . '%</span></td>';
                echo '<td>' . $status_badge . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function resolve_verification_subject_targets( array $academic_settings, string $class_name, string $department, array $questions = [], array $teacher_assignments = [], string $session_year = '' ): array {
        $class_name = strtoupper( trim( $class_name ) );
        $department = sanitize_text_field( $department );
        $subject_pool = [];

        if ( str_starts_with( $class_name, 'JSS' ) ) {
            $subjects = isset( $academic_settings['jss_compulsory_subjects'] ) && is_array( $academic_settings['jss_compulsory_subjects'] )
                ? $academic_settings['jss_compulsory_subjects']
                : $this->subject_service->list_jss_compulsory_subjects();

            $subject_pool = array_values( array_filter( array_map( 'sanitize_text_field', $subjects ) ) );
        } else {
            $department_map = isset( $academic_settings['departments'] ) && is_array( $academic_settings['departments'] )
                ? $academic_settings['departments']
                : $this->subject_service->list_department_subject_map();

            $subjects = isset( $department_map[ $department ] ) && is_array( $department_map[ $department ] )
                ? $department_map[ $department ]
                : [];

            $subject_pool = array_values( array_filter( array_map( 'sanitize_text_field', $subjects ) ) );
        }

        $class_key = strtolower( trim( $class_name ) );
        $department_key = strtolower( trim( $department ) );
        $session_key = strtolower( trim( $session_year ) );

        foreach ( $questions as $question ) {
            if ( ! is_array( $question ) ) {
                continue;
            }

            $subject_name = sanitize_text_field( (string) ( $question['subject'] ?? '' ) );
            if ( $subject_name === '' ) {
                continue;
            }

            $question_class = strtolower( trim( (string) ( $question['class'] ?? '' ) ) );
            if ( $class_key !== '' && $question_class !== '' && $question_class !== $class_key ) {
                continue;
            }

            $question_department = strtolower( trim( (string) ( $question['department'] ?? '' ) ) );
            if ( $department_key !== '' && $question_department !== '' && $question_department !== $department_key ) {
                continue;
            }

            $question_session = strtolower( trim( (string) ( $question['examination_year'] ?? '' ) ) );
            if ( $session_key !== '' && $question_session !== '' && $question_session !== $session_key ) {
                continue;
            }

            $subject_pool[] = $subject_name;
        }

        foreach ( $teacher_assignments as $teacher ) {
            if ( ! is_array( $teacher ) ) {
                continue;
            }

            $teacher_classes = array_map( 'strtolower', (array) ( $teacher['classes'] ?? [] ) );
            if ( ! empty( $teacher_classes ) && $class_key !== '' && ! in_array( $class_key, $teacher_classes, true ) ) {
                continue;
            }

            foreach ( (array) ( $teacher['subjects'] ?? [] ) as $subject_name ) {
                $subject_name = sanitize_text_field( (string) $subject_name );
                if ( $subject_name !== '' ) {
                    $subject_pool[] = $subject_name;
                }
            }
        }

        $subject_pool = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $subject_pool ) ) ) );
        natcasesort( $subject_pool );

        return array_values( $subject_pool );
    }

    private function count_questions_for_subject_scope( array $questions, string $subject, string $class_name, string $department, string $session_year ): int {
        $stats = $this->collect_objective_question_integrity( $questions, $subject, $class_name, $department, $session_year );
        return absint( $stats['unique_count'] ?? 0 );
    }

    private function collect_objective_question_integrity( array $questions, string $subject, string $class_name, string $department, string $session_year ): array {
        $subject_key = strtolower( trim( $subject ) );
        $class_key = strtolower( trim( $class_name ) );
        $department_key = strtolower( trim( $department ) );
        $session_key = strtolower( trim( $session_year ) );

        $seen_fingerprints = [];
        $canonical_texts = [];
        $duplicate_count = 0;
        $similar_count = 0;
        $unique_count = 0;

        foreach ( $questions as $question ) {
            if ( ! is_array( $question ) ) {
                continue;
            }

            $q_type = strtolower( trim( (string) ( $question['question_type'] ?? 'objective' ) ) );
            if ( $q_type !== 'objective' ) {
                continue;
            }

            $q_subject = strtolower( trim( (string) ( $question['subject'] ?? '' ) ) );
            if ( $q_subject !== $subject_key ) {
                continue;
            }

            $q_class = strtolower( trim( (string) ( $question['class'] ?? '' ) ) );
            if ( $class_key !== '' && $q_class !== '' && $q_class !== $class_key ) {
                continue;
            }

            $q_department = strtolower( trim( (string) ( $question['department'] ?? '' ) ) );
            if ( $department_key !== '' && $q_department !== '' && $q_department !== $department_key ) {
                continue;
            }

            $q_year = strtolower( trim( (string) ( $question['examination_year'] ?? '' ) ) );
            if ( $session_key !== '' && $q_year !== '' && $q_year !== $session_key ) {
                continue;
            }

            $fingerprint = $this->build_objective_question_fingerprint( $question );
            if ( $fingerprint === '' ) {
                continue;
            }

            if ( isset( $seen_fingerprints[ $fingerprint ] ) ) {
                $duplicate_count++;
                continue;
            }

            $canonical = $this->normalize_question_text( (string) ( $question['question_text'] ?? '' ) );
            if ( $canonical !== '' ) {
                foreach ( $canonical_texts as $known_text ) {
                    similar_text( $canonical, $known_text, $percent );
                    if ( $percent >= 85 ) {
                        $similar_count++;
                        break;
                    }
                }
                $canonical_texts[] = $canonical;
            }

            $seen_fingerprints[ $fingerprint ] = true;
            $unique_count++;
        }

        $integrity_score = 100;
        if ( $unique_count > 0 ) {
            $integrity_penalty = min( 100, (int) round( ( ( $duplicate_count * 2 ) + $similar_count ) * 100 / $unique_count ) );
            $integrity_score = max( 0, 100 - $integrity_penalty );
        }

        return [
            'unique_count' => $unique_count,
            'duplicate_count' => $duplicate_count,
            'similar_count' => $similar_count,
            'integrity_score' => $integrity_score,
        ];
    }

    private function notify_teachers_for_pending_subjects( int $school_id, array $report_rows, string $term, string $session_year, string $class_name, string $department ): int {
        $pending_rows = array_values(
            array_filter(
                $report_rows,
                static fn( array $row ): bool => ! empty( $row['is_pending'] )
            )
        );

        if ( empty( $pending_rows ) ) {
            return 0;
        }

        $notified_count = 0;
        foreach ( $pending_rows as $row ) {
            $teacher_id = absint( $row['teacher_id'] ?? 0 );
            if ( $teacher_id <= 0 ) {
                continue;
            }

            $subject_name = sanitize_text_field( (string) ( $row['subject'] ?? '' ) );
            $remaining = absint( $row['remaining_count'] ?? 0 );
            if ( $subject_name === '' || $remaining <= 0 ) {
                continue;
            }

            $signature = $this->build_pending_notification_signature( $session_year, $term, $class_name, $department, $subject_name );
            if ( ! $this->should_send_pending_notification( $school_id, $teacher_id, $signature, $remaining ) ) {
                continue;
            }

            $teacher_name = sanitize_text_field( (string) ( $row['teacher_name'] ?? 'Teacher' ) );

            $notification = $this->notification_service->create_notification(
                $school_id,
                [
                    'recipient_id' => $teacher_id,
                    'title' => sprintf( 'Question Submission Pending - %s %s', $term, $session_year ),
                    'message' => sprintf(
                        '%s, objective question submission pending for %s (%s), subject %s. Remaining objective questions: %d. %s',
                        $teacher_name,
                        $class_name,
                        $department,
                        $subject_name,
                        $remaining,
                        $signature
                    ),
                    'type' => 'alert',
                ]
            );

            if ( ! empty( $notification['success'] ) ) {
                $notified_count++;
            }
        }

        return $notified_count;
    }

    public function download_question_template(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_download_question_template' );

        $type = sanitize_text_field( wp_unslash( $_GET['type'] ?? 'objective' ) );
        $type = $type === 'theory' ? 'theory' : 'objective';

        $headers = [ 'subject', 'section', 'passage_text', 'topic', 'sub_topic', 'class', 'department', 'difficulty', 'learning_objective', 'bloom_level', 'examination_type', 'examination_year', 'question_type', 'estimated_duration', 'marks', 'image_reference', 'question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'answer', 'explanations' ];
        $row = $type === 'theory'
            ? [ 'English Language', 'Comprehension', 'Read the passage and answer the question.', 'Reading', 'Inference', 'SS2', 'Arts', 'medium', 'Infer author intent from text evidence', 'Analyze', 'WAEC', '2026', 'theory', '300', '10', '', 'What is the main idea of the passage?', '', '', '', '', 'Main-idea response', 'Use direct evidence from passage.' ]
            : [ 'Mathematics', 'Grammar', '', 'Algebra', 'Linear Equations', 'SS1', 'Science', 'easy', 'Solve simple linear equations', 'Apply', 'Internal Examination', '2026', 'objective', '120', '2', '', 'Solve: 2x + 4 = 10', 'x=2', 'x=3', 'x=4', 'x=5', 'x=3', '2x = 6 therefore x = 3' ];

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=educbt-' . $type . '-questions-template.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, $headers );
        fputcsv( $output, $row );
        fclose( $output );
        exit;
    }

    private function auto_generate_timetable_entries( int $school_id, array $rules, array $academic_settings ): array {
        $created = 0;
        $skipped = 0;
        $notes = [];

        $class_name = sanitize_text_field( (string) ( $rules['class_name'] ?? '' ) );
        $department = sanitize_text_field( (string) ( $rules['department'] ?? '' ) );
        $exam_date_start = sanitize_text_field( (string) ( $rules['exam_date_start'] ?? '' ) );
        $subjects_per_day = max( 1, min( 3, absint( $rules['subjects_per_day'] ?? 3 ) ) );
        $start_time = sanitize_text_field( (string) ( $rules['start_time'] ?? '08:00' ) );
        $slot_duration_minutes = max( 1, absint( $rules['slot_duration_minutes'] ?? 90 ) );
        $slot_break_minutes = max( 0, absint( $rules['slot_break_minutes'] ?? 15 ) );

        if ( $class_name === '' || $department === '' || $exam_date_start === '' ) {
            return [
                'created' => 0,
                'skipped' => 0,
                'notes' => [ 'Auto-generation requires class, department, and start date.' ],
            ];
        }

        $subjects = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $rules['subject_list'] ?? [] ) ) ) );
        if ( empty( $subjects ) ) {
            $dept_map = isset( $academic_settings['departments'] ) && is_array( $academic_settings['departments'] )
                ? $academic_settings['departments']
                : [];
            $subjects = isset( $dept_map[ $department ] ) && is_array( $dept_map[ $department ] )
                ? array_values( array_filter( array_map( 'sanitize_text_field', $dept_map[ $department ] ) ) )
                : [];
        }

        if ( empty( $subjects ) ) {
            return [
                'created' => 0,
                'skipped' => 0,
                'notes' => [ 'No subjects available for selected department/rules.' ],
            ];
        }

        $exam_pool = $this->exam_service->list_exams( $school_id );
        $subject_exam_map = $this->parse_subject_exam_map( sanitize_text_field( (string) ( $rules['subject_exam_map'] ?? '' ) ) );

        $base_ts = strtotime( $exam_date_start . ' ' . $start_time );
        if ( ! $base_ts ) {
            $base_ts = strtotime( $exam_date_start . ' 08:00' );
        }
        if ( ! $base_ts ) {
            return [
                'created' => 0,
                'skipped' => 0,
                'notes' => [ 'Unable to parse start date/time for auto-generation.' ],
            ];
        }

        foreach ( $subjects as $index => $subject_name ) {
            $exam_id = $this->resolve_exam_id_for_subject( $school_id, $exam_pool, $subject_name, $subject_exam_map );
            if ( $exam_id <= 0 ) {
                $skipped++;
                $notes[] = sprintf( 'Skipped %s (no deterministic exam mapping found).', $subject_name );
                continue;
            }

            $day_offset = intdiv( $index, $subjects_per_day );
            $slot_index = $index % $subjects_per_day;
            $slot_start_ts = strtotime( '+' . $day_offset . ' day', $base_ts );
            if ( ! $slot_start_ts ) {
                $slot_start_ts = $base_ts;
            }

            $slot_start_ts += ( $slot_index * ( $slot_duration_minutes + $slot_break_minutes ) * 60 );
            $slot_end_ts = $slot_start_ts + ( $slot_duration_minutes * 60 );

            $entry = [
                'exam_id' => $exam_id,
                'session_year' => sanitize_text_field( (string) ( $rules['session_year'] ?? '' ) ),
                'term' => sanitize_text_field( (string) ( $rules['term'] ?? '' ) ),
                'class_name' => $class_name,
                'arm' => sanitize_text_field( (string) ( $rules['arm'] ?? '' ) ),
                'department' => $department,
                'subject' => $subject_name,
                'exam_type' => sanitize_text_field( (string) ( $rules['exam_type'] ?? '' ) ),
                'exam_date' => wp_date( 'Y-m-d', $slot_start_ts, wp_timezone() ),
                'start_time' => wp_date( 'H:i', $slot_start_ts, wp_timezone() ),
                'end_time' => wp_date( 'H:i', $slot_end_ts, wp_timezone() ),
                'duration_minutes' => $slot_duration_minutes,
                'venue' => sanitize_text_field( (string) ( $rules['venue'] ?? '' ) ),
                'invigilator' => sanitize_text_field( (string) ( $rules['invigilator'] ?? '' ) ),
                'is_trial_mode' => 0,
                'status' => sanitize_text_field( (string) ( $rules['status'] ?? 'scheduled' ) ),
            ];

            $constraint = $this->exam_timetable_service->validate_daily_subject_constraints( $school_id, $entry );
            if ( ! $constraint['success'] ) {
                $skipped++;
                $notes[] = sprintf( 'Skipped %s (%s).', $subject_name, $constraint['message'] );
                continue;
            }

            $id = $this->exam_timetable_service->create_timetable( $school_id, $entry );
            if ( $id > 0 ) {
                $created++;
            } else {
                $skipped++;
                $notes[] = sprintf( 'Skipped %s (create failed).', $subject_name );
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'notes' => $notes,
        ];
    }

    private function resolve_exam_id_for_subject( int $school_id, array $exams, string $subject_name, array $subject_exam_map = [] ): int {
        $needle = strtolower( trim( $subject_name ) );
        if ( $needle === '' ) {
            return 0;
        }

        if ( isset( $subject_exam_map[ $needle ] ) ) {
            return absint( $subject_exam_map[ $needle ] );
        }

        $questions = $this->question_service->list_questions( $school_id );
        $question_subject_map = [];
        foreach ( $questions as $question ) {
            if ( ! is_array( $question ) ) {
                continue;
            }

            $qid = absint( $question['id'] ?? 0 );
            if ( $qid <= 0 ) {
                continue;
            }

            $question_subject_map[ $qid ] = strtolower( trim( (string) ( $question['subject'] ?? '' ) ) );
        }

        foreach ( $exams as $exam ) {
            if ( ! is_array( $exam ) ) {
                continue;
            }

            $exam_id = absint( $exam['id'] ?? 0 );
            if ( $exam_id <= 0 ) {
                continue;
            }

            $exam_questions = $this->exam_service->list_exam_questions( $school_id, $exam_id );
            if ( empty( $exam_questions ) ) {
                continue;
            }

            $matching = 0;
            $total = 0;
            foreach ( $exam_questions as $exam_question ) {
                $qid = absint( $exam_question['question_id'] ?? 0 );
                if ( $qid <= 0 || ! isset( $question_subject_map[ $qid ] ) ) {
                    continue;
                }

                $total++;
                if ( $question_subject_map[ $qid ] === $needle ) {
                    $matching++;
                }
            }

            if ( $total > 0 && $matching === $total ) {
                return $exam_id;
            }
        }

        foreach ( $exams as $exam ) {
            if ( ! is_array( $exam ) ) {
                continue;
            }

            $title = strtolower( trim( (string) ( $exam['title'] ?? '' ) ) );
            if ( $title === $needle ) {
                return absint( $exam['id'] ?? 0 );
            }
        }

        foreach ( $exams as $exam ) {
            if ( ! is_array( $exam ) ) {
                continue;
            }

            $title = strtolower( trim( (string) ( $exam['title'] ?? '' ) ) );
            if ( $title !== '' && preg_match( '/\\b' . preg_quote( $needle, '/' ) . '\\b/u', $title ) ) {
                return absint( $exam['id'] ?? 0 );
            }
        }

        return 0;
    }

    private function handle_questions_csv_upload( int $school_id, bool $is_theory ): array {
        if ( $school_id <= 0 ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0 ];
        }

        if ( empty( $_FILES['questions_csv']['tmp_name'] ) ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0 ];
        }

        $tmp_name = sanitize_text_field( wp_unslash( $_FILES['questions_csv']['tmp_name'] ) );
        $original_name = sanitize_text_field( wp_unslash( $_FILES['questions_csv']['name'] ?? '' ) );

        return $this->import_question_rows_from_file( $school_id, $is_theory, $tmp_name, $original_name );
    }

    private function import_default_question_sets( int $school_id ): array {
        if ( $school_id <= 0 ) {
            return [ 'files' => 0, 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0 ];
        }

        $base = EDUCBT_PRO_DIR . 'docs/default-questions/';
        $files = [
            $base . 'EduCBT_Economics_20_Hard_Questions.csv',
            $base . 'EduCBT_English_20_Questions.csv',
            $base . 'QuestionTest.csv',
        ];

        $report = [ 'files' => 0, 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0 ];

        foreach ( $files as $file ) {
            if ( ! file_exists( $file ) ) {
                continue;
            }

            $file_report = $this->import_question_rows_from_file( $school_id, false, $file, basename( $file ) );
            $report['files']++;
            $report['processed'] += absint( $file_report['processed'] ?? 0 );
            $report['imported'] += absint( $file_report['imported'] ?? 0 );
            $report['duplicates'] += absint( $file_report['duplicates'] ?? 0 );
            $report['failed'] += absint( $file_report['failed'] ?? 0 );
        }

        return $report;
    }

    private function import_question_rows_from_file( int $school_id, bool $is_theory, string $file_path, string $original_name = '' ): array {
        if ( ! file_exists( $file_path ) ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0 ];
        }

        $existing = $this->question_service->list_questions( $school_id );
        $fingerprints = [];

        foreach ( $existing as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $fp = $this->build_question_fingerprint( $row );
            if ( $fp !== '' ) {
                $fingerprints[ $fp ] = true;
            }
        }

        $rows = $this->read_question_rows_from_file( $file_path, $original_name );
        if ( empty( $rows ) || ! is_array( $rows[0] ) ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0 ];
        }

        $header = array_map( static fn( $item ): string => strtolower( trim( (string) $item ) ), $rows[0] );
        $subject_col_index = array_search( 'subject', $header, true );
        $processed = 0;
        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        $import_subjects = [];

        for ( $i = 1; $i < count( $rows ); $i++ ) {
            $row = (array) $rows[ $i ];
            if ( empty( array_filter( $row, static fn( $v ): bool => trim( (string) $v ) !== '' ) ) ) {
                continue;
            }

            $processed++;
            $data = $this->build_question_data_from_row( $header, $row, $is_theory );
            $data['subject'] = $this->subject_service->canonicalize_subject_name( $school_id, (string) ( $data['subject'] ?? '' ) );

            $raw_subject = '';
            if ( $subject_col_index !== false ) {
                $raw_subject = sanitize_text_field( trim( (string) ( $row[ $subject_col_index ] ?? '' ) ) );
            }
            if ( $raw_subject !== '' ) {
                $import_subjects[] = $raw_subject;
            }

            if ( ! $this->has_meaningful_question_payload( $data ) ) {
                $failed++;
                continue;
            }

            $fp = $this->build_question_fingerprint( $data );
            if ( $fp !== '' && isset( $fingerprints[ $fp ] ) ) {
                $duplicates++;
                continue;
            }

            if ( $fp !== '' ) {
                $fingerprints[ $fp ] = true;
            }

            $created = $this->question_service->create_question( $school_id, $data );
            if ( $created > 0 ) {
                $imported++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'subject_alias_suggestions' => $this->subject_service->suggest_subject_aliases( $school_id, $import_subjects ),
        ];
    }

    private function read_question_rows_from_file( string $file_path, string $original_name = '' ): array {
        $ext = strtolower( pathinfo( $original_name !== '' ? $original_name : $file_path, PATHINFO_EXTENSION ) );

        if ( $ext === 'xlsx' ) {
            return $this->read_xlsx_rows( $file_path );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return [];
        }

        $rows = [];
        while ( ( $csv_row = fgetcsv( $handle ) ) !== false ) {
            $rows[] = $csv_row;
        }

        fclose( $handle );
        return $rows;
    }

    private function read_xlsx_rows( string $file_path ): array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [];
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return [];
        }

        $shared_strings = [];
        $shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( is_string( $shared_xml ) && $shared_xml !== '' ) {
            $shared = simplexml_load_string( $shared_xml );
            if ( $shared instanceof \SimpleXMLElement && isset( $shared->si ) ) {
                foreach ( $shared->si as $si ) {
                    if ( isset( $si->t ) ) {
                        $shared_strings[] = (string) $si->t;
                        continue;
                    }

                    $text = '';
                    if ( isset( $si->r ) ) {
                        foreach ( $si->r as $run ) {
                            $text .= (string) ( $run->t ?? '' );
                        }
                    }
                    $shared_strings[] = $text;
                }
            }
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        $zip->close();

        if ( ! is_string( $sheet_xml ) || $sheet_xml === '' ) {
            return [];
        }

        $sheet = simplexml_load_string( $sheet_xml );
        if ( ! ( $sheet instanceof \SimpleXMLElement ) ) {
            return [];
        }

        $sheet->registerXPathNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        $row_nodes = $sheet->xpath( '//x:sheetData/x:row' );
        if ( ! is_array( $row_nodes ) ) {
            return [];
        }

        $rows = [];
        foreach ( $row_nodes as $row_node ) {
            $values = [];
            $cells = $row_node->xpath( 'x:c' );
            if ( ! is_array( $cells ) ) {
                continue;
            }

            foreach ( $cells as $cell ) {
                $ref = (string) ( $cell['r'] ?? '' );
                $type = (string) ( $cell['t'] ?? '' );
                $idx = $this->xlsx_column_index_from_ref( $ref );

                $value = '';
                if ( $type === 's' ) {
                    $shared_index = absint( (string) ( $cell->v ?? 0 ) );
                    $value = (string) ( $shared_strings[ $shared_index ] ?? '' );
                } elseif ( $type === 'inlineStr' && isset( $cell->is->t ) ) {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) ( $cell->v ?? '' );
                }

                $values[ $idx ] = $value;
            }

            if ( empty( $values ) ) {
                $rows[] = [];
                continue;
            }

            ksort( $values );
            $max = max( array_keys( $values ) );
            $flat = array_fill( 0, $max + 1, '' );
            foreach ( $values as $idx => $val ) {
                $flat[ $idx ] = $val;
            }

            $rows[] = $flat;
        }

        return $rows;
    }

    private function xlsx_column_index_from_ref( string $ref ): int {
        if ( $ref === '' ) {
            return 0;
        }

        if ( ! preg_match( '/^([A-Z]+)/i', $ref, $matches ) ) {
            return 0;
        }

        $letters = strtoupper( $matches[1] );
        $index = 0;
        for ( $i = 0; $i < strlen( $letters ); $i++ ) {
            $index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
        }

        return max( 0, $index - 1 );
    }

    private function build_question_data_from_row( array $header, array $row, bool $is_theory ): array {
        $get = static function ( array $keys ) use ( $header, $row ): string {
            foreach ( $keys as $key ) {
                $needle = strtolower( trim( $key ) );
                $index = array_search( $needle, $header, true );
                if ( $index !== false ) {
                    return trim( (string) ( $row[ $index ] ?? '' ) );
                }
            }

            return '';
        };

        $options = [
            $get( [ 'option_a', 'a', 'choice_a' ] ),
            $get( [ 'option_b', 'b', 'choice_b' ] ),
            $get( [ 'option_c', 'c', 'choice_c' ] ),
            $get( [ 'option_d', 'd', 'choice_d' ] ),
        ];
        $options = array_values( array_filter( array_map( 'sanitize_text_field', $options ) ) );

        $raw_answer = sanitize_text_field( $get( [ 'answer', 'answers', 'correct_answer' ] ) );
        $answers = $raw_answer === '' ? [] : [ $raw_answer ];
        $answers = $this->normalize_answers_against_options( $answers, $options );

        $section = sanitize_text_field( $get( [ 'section' ] ) );
        $question_type = sanitize_text_field( $get( [ 'question_type', 'type' ] ) );
        if ( $question_type === '' ) {
            $section_key = strtolower( trim( $section ) );
            if ( in_array( $section_key, [ 'objective', 'theory' ], true ) ) {
                $question_type = $section_key;
            }
        }

        if ( $question_type === '' ) {
            $question_type = $is_theory ? 'theory' : 'objective';
        }

        return [
            'subject'       => sanitize_text_field( $get( [ 'subject' ] ) ),
            'section'       => $section,
            'passage_text'  => sanitize_textarea_field( $get( [ 'passage_text', 'passage' ] ) ),
            'topic'         => sanitize_text_field( $get( [ 'topic' ] ) ),
            'sub_topic'     => sanitize_text_field( $get( [ 'sub_topic', 'subtopic' ] ) ),
            'class'         => sanitize_text_field( $get( [ 'class', 'class_name' ] ) ),
            'department'    => sanitize_text_field( $get( [ 'department' ] ) ),
            'difficulty'    => sanitize_text_field( $get( [ 'difficulty' ] ) ),
            'learning_objective' => sanitize_text_field( $get( [ 'learning_objective' ] ) ),
            'bloom_level'   => sanitize_text_field( $get( [ 'bloom_level' ] ) ),
            'examination_type' => sanitize_text_field( $get( [ 'examination_type', 'exam_type' ] ) ),
            'examination_year' => sanitize_text_field( $get( [ 'examination_year', 'session_year', 'session' ] ) ),
            'question_type' => $question_type,
            'estimated_duration' => absint( $get( [ 'estimated_duration', 'duration', 'duration_seconds' ] ) ),
            'marks'         => floatval( $get( [ 'marks', 'score' ] ) ),
            'image_reference' => sanitize_text_field( $get( [ 'image_reference', 'image' ] ) ),
            'question_text' => sanitize_textarea_field( $get( [ 'question_text', 'questions', 'question' ] ) ),
            'options'       => $options,
            'answers'       => $answers,
            'explanations'  => sanitize_textarea_field( $get( [ 'explanations', 'explanation' ] ) ),
        ];
    }

    private function normalize_answers_against_options( array $answers, array $options ): array {
        if ( empty( $answers ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $answers as $answer ) {
            $answer = sanitize_text_field( (string) $answer );
            $letter = strtolower( trim( $answer ) );
            if ( in_array( $letter, [ 'a', 'b', 'c', 'd' ], true ) ) {
                $index = ord( strtoupper( $letter ) ) - ord( 'A' );
                if ( isset( $options[ $index ] ) && trim( (string) $options[ $index ] ) !== '' ) {
                    $answer = (string) $options[ $index ];
                }
            }

            if ( trim( $answer ) !== '' ) {
                $normalized[] = $answer;
            }
        }

        return array_values( array_unique( $normalized ) );
    }

    private function has_meaningful_question_payload( array $data ): bool {
        return
            trim( (string) ( $data['question_text'] ?? '' ) ) !== '' ||
            trim( (string) ( $data['subject'] ?? '' ) ) !== '' ||
            trim( (string) ( $data['topic'] ?? '' ) ) !== '' ||
            ! empty( array_filter( (array) ( $data['options'] ?? [] ) ) ) ||
            ! empty( array_filter( (array) ( $data['answers'] ?? [] ) ) );
    }

    private function build_question_fingerprint( array $question ): string {
        $question_text = $this->normalize_question_text( (string) ( $question['question_text'] ?? '' ) );
        $subject = strtolower( trim( (string) ( $question['subject'] ?? '' ) ) );
        $class_name = strtolower( trim( (string) ( $question['class'] ?? '' ) ) );
        $department = strtolower( trim( (string) ( $question['department'] ?? '' ) ) );
        $session_year = strtolower( trim( (string) ( $question['examination_year'] ?? '' ) ) );
        $question_type = strtolower( trim( (string) ( $question['question_type'] ?? '' ) ) );

        $options_raw = $question['options'] ?? [];
        if ( is_string( $options_raw ) ) {
            $decoded = json_decode( $options_raw, true );
            $options_raw = is_array( $decoded ) ? $decoded : [];
        }

        $answers_raw = $question['answers'] ?? [];
        if ( is_string( $answers_raw ) ) {
            $decoded = json_decode( $answers_raw, true );
            $answers_raw = is_array( $decoded ) ? $decoded : [];
        }

        $options = array_values( array_filter( array_map( [ $this, 'normalize_question_text' ], (array) $options_raw ) ) );
        $answers = array_values( array_filter( array_map( [ $this, 'normalize_question_text' ], (array) $answers_raw ) ) );
        sort( $options );
        sort( $answers );

        if ( $question_text === '' ) {
            return '';
        }

        return md5( implode( '|', [ $subject, $class_name, $department, $session_year, $question_type, $question_text, implode( '~', $options ), implode( '~', $answers ) ] ) );
    }

    private function build_objective_question_fingerprint( array $question ): string {
        $fp = $this->build_question_fingerprint( $question );
        if ( $fp === '' ) {
            return '';
        }

        return $fp;
    }

    private function normalize_question_text( string $text ): string {
        $text = wp_strip_all_tags( $text );
        $text = strtolower( trim( $text ) );
        $text = preg_replace( '/\\s+/u', ' ', $text );
        $text = preg_replace( '/[^a-z0-9 ]+/u', '', (string) $text );

        return trim( (string) $text );
    }

    private function build_teacher_assignment_index( int $school_id ): array {
        $teachers = $this->teacher_service->list_teachers( $school_id );
        $index = [];

        foreach ( $teachers as $teacher ) {
            if ( ! is_array( $teacher ) ) {
                continue;
            }

            $teacher_id = absint( $teacher['id'] ?? 0 );
            if ( $teacher_id <= 0 ) {
                continue;
            }

            $subjects = json_decode( (string) ( $teacher['subjects'] ?? '[]' ), true );
            $subjects = is_array( $subjects ) ? $subjects : [];
            $classes = json_decode( (string) ( $teacher['assigned_classes'] ?? '[]' ), true );
            $classes = is_array( $classes ) ? $classes : [];

            $index[] = [
                'id' => $teacher_id,
                'full_name' => sanitize_text_field( (string) ( $teacher['full_name'] ?? '' ) ),
                'subjects' => array_values( array_filter( array_map( static fn( $s ): string => strtolower( trim( sanitize_text_field( (string) $s ) ) ), $subjects ) ) ),
                'classes' => array_values( array_filter( array_map( static fn( $c ): string => strtolower( trim( sanitize_text_field( (string) $c ) ) ), $classes ) ) ),
            ];
        }

        return $index;
    }

    private function resolve_responsible_teacher_for_subject_class( array $teacher_assignments, string $subject_name, string $class_name ): array {
        $subject_key = strtolower( trim( $subject_name ) );
        $class_key = strtolower( trim( $class_name ) );

        foreach ( $teacher_assignments as $teacher ) {
            if ( ! is_array( $teacher ) ) {
                continue;
            }

            $subjects = (array) ( $teacher['subjects'] ?? [] );
            $classes = (array) ( $teacher['classes'] ?? [] );
            $subject_match = in_array( $subject_key, $subjects, true );
            $class_match = empty( $classes ) || in_array( $class_key, $classes, true );

            if ( $subject_match && $class_match ) {
                return $teacher;
            }
        }

        foreach ( $teacher_assignments as $teacher ) {
            if ( ! is_array( $teacher ) ) {
                continue;
            }

            $subjects = (array) ( $teacher['subjects'] ?? [] );
            if ( in_array( $subject_key, $subjects, true ) ) {
                return $teacher;
            }
        }

        return [ 'id' => 0, 'full_name' => 'Unassigned' ];
    }

    private function build_pending_notification_signature( string $session_year, string $term, string $class_name, string $department, string $subject_name ): string {
        return sprintf(
            '[QSUB:%s|%s|%s|%s|%s]',
            strtolower( trim( $session_year ) ),
            strtolower( trim( $term ) ),
            strtolower( trim( $class_name ) ),
            strtolower( trim( $department ) ),
            strtolower( trim( $subject_name ) )
        );
    }

    private function should_send_pending_notification( int $school_id, int $teacher_id, string $signature, int $remaining ): bool {
        $notifications = $this->notification_service->list_for_recipient( $school_id, $teacher_id );
        foreach ( $notifications as $notification ) {
            if ( ! is_array( $notification ) ) {
                continue;
            }

            $message = (string) ( $notification['message'] ?? '' );
            if ( $message === '' || strpos( $message, $signature ) === false ) {
                continue;
            }

            $prior_remaining = -1;
            if ( preg_match( '/Remaining objective questions:\s*(\\d+)/i', $message, $matches ) ) {
                $prior_remaining = absint( $matches[1] ?? 0 );
            }

            $is_read = absint( $notification['is_read'] ?? 0 ) === 1;
            if ( ! $is_read && $prior_remaining === $remaining ) {
                $created_at = strtotime( (string) ( $notification['created_at'] ?? '' ) );
                if ( $created_at && ( time() - $created_at ) < DAY_IN_SECONDS ) {
                    return false;
                }

                return true;
            }

            if ( $prior_remaining > 0 && $remaining >= $prior_remaining ) {
                return false;
            }

            return true;
        }

        return true;
    }

    private function parse_subject_exam_map( string $raw ): array {
        $map = [];
        $pairs = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        foreach ( $pairs as $pair ) {
            $parts = array_map( 'trim', explode( ':', $pair, 2 ) );
            if ( count( $parts ) !== 2 ) {
                continue;
            }

            $subject = strtolower( sanitize_text_field( $parts[0] ) );
            $exam_id = absint( $parts[1] );
            if ( $subject !== '' && $exam_id > 0 ) {
                $map[ $subject ] = $exam_id;
            }
        }

        return $map;
    }

    private function find_duplicate_objective_questions_for_scope( array $questions, string $session_year, string $class_name, string $department, string $subject ): array {
        $session_key = strtolower( trim( $session_year ) );
        $class_key = strtolower( trim( $class_name ) );
        $department_key = strtolower( trim( $department ) );
        $subject_key = strtolower( trim( $subject ) );

        $groups = [];
        foreach ( $questions as $question ) {
            if ( ! is_array( $question ) ) {
                continue;
            }

            $qid = absint( $question['id'] ?? 0 );
            if ( $qid <= 0 ) {
                continue;
            }

            $q_type = strtolower( trim( (string) ( $question['question_type'] ?? 'objective' ) ) );
            if ( $q_type !== 'objective' ) {
                continue;
            }

            $q_session = strtolower( trim( (string) ( $question['examination_year'] ?? '' ) ) );
            $q_class = strtolower( trim( (string) ( $question['class'] ?? '' ) ) );
            $q_department = strtolower( trim( (string) ( $question['department'] ?? '' ) ) );
            $q_subject = strtolower( trim( (string) ( $question['subject'] ?? '' ) ) );

            if ( $session_key !== '' && $q_session !== $session_key ) {
                continue;
            }

            if ( $class_key !== '' && $q_class !== $class_key ) {
                continue;
            }

            if ( $department_key !== '' && $q_department !== $department_key ) {
                continue;
            }

            if ( $subject_key !== '' && $q_subject !== $subject_key ) {
                continue;
            }

            $fingerprint = $this->build_objective_question_fingerprint( $question );
            if ( $fingerprint === '' ) {
                continue;
            }

            if ( ! isset( $groups[ $fingerprint ] ) ) {
                $groups[ $fingerprint ] = [];
            }

            $groups[ $fingerprint ][] = $question;
        }

        $results = [];
        foreach ( $groups as $fingerprint => $items ) {
            if ( count( $items ) <= 1 ) {
                continue;
            }

            usort(
                $items,
                static fn( array $a, array $b ): int => absint( $a['id'] ?? 0 ) <=> absint( $b['id'] ?? 0 )
            );

            $keep = $items[0];
            $remove_ids = [];
            for ( $i = 1; $i < count( $items ); $i++ ) {
                $remove_ids[] = absint( $items[ $i ]['id'] ?? 0 );
            }

            $results[] = [
                'fingerprint' => $fingerprint,
                'subject' => sanitize_text_field( (string) ( $keep['subject'] ?? '' ) ),
                'class_name' => sanitize_text_field( (string) ( $keep['class'] ?? '' ) ),
                'department' => sanitize_text_field( (string) ( $keep['department'] ?? '' ) ),
                'session_year' => sanitize_text_field( (string) ( $keep['examination_year'] ?? '' ) ),
                'keep_id' => absint( $keep['id'] ?? 0 ),
                'remove_ids' => array_values( array_filter( $remove_ids ) ),
                'question_text' => sanitize_text_field( substr( (string) ( $keep['question_text'] ?? '' ), 0, 120 ) ),
            ];
        }

        return $results;
    }

    private function delete_questions_by_ids( int $school_id, array $question_ids ): array {
        $question_ids = array_values( array_unique( array_filter( array_map( 'absint', $question_ids ) ) ) );
        if ( $school_id <= 0 || empty( $question_ids ) ) {
            return [ 'deleted_questions' => 0, 'deleted_links' => 0 ];
        }

        global $wpdb;
        $question_table = $wpdb->prefix . 'educbt_questions';
        $exam_question_table = $wpdb->prefix . 'educbt_exam_questions';

        $placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
        $params_for_link_delete = array_merge( [ $school_id ], $question_ids );
        $params_for_question_delete = array_merge( [ $school_id ], $question_ids );

        $deleted_links = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$exam_question_table} WHERE school_id = %d AND question_id IN ({$placeholders})",
                ...$params_for_link_delete
            )
        );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$question_table} WHERE school_id = %d AND id IN ({$placeholders})",
                ...$params_for_question_delete
            )
        );

        return [
            'deleted_questions' => max( 0, absint( $deleted ) ),
            'deleted_links' => max( 0, absint( $deleted_links ) ),
        ];
    }

    private function count_exam_links_for_questions( int $school_id, array $question_ids ): int {
        $question_ids = array_values( array_unique( array_filter( array_map( 'absint', $question_ids ) ) ) );
        if ( $school_id <= 0 || empty( $question_ids ) ) {
            return 0;
        }

        global $wpdb;
        $exam_question_table = $wpdb->prefix . 'educbt_exam_questions';
        $placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
        $params = array_merge( [ $school_id ], $question_ids );

        return absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$exam_question_table} WHERE school_id = %d AND question_id IN ({$placeholders})",
                    ...$params
                )
            )
        );
    }

    public function download_student_template(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_download_student_template' );

        $headers = [
            'registration_number',
            'student_id',
            'first_name',
            'last_name',
            'full_name',
            'gender',
            'date_of_birth',
            'parent_information',
            'parent_phone',
            'parent_email',
            'address',
            'session_year',
            'class',
            'arm',
            'department',
            'status',
            'login_username',
            'temporary_password',
            'manual_subjects',
        ];

        $row = [
            'REG-1001',
            'STU-1001',
            'Ada',
            'Okoro',
            'Ada Okoro',
            'Female',
            '2010-03-12',
            'Parent: Mr Okoro',
            '08012345678',
            'parent@example.com',
            'No 10 School Road, Lagos',
            '2026/2027',
            'SS2',
            'A',
            'Science',
            'active',
            'ADM1001',
            'TempPass#2026',
            '',
        ];

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=educbt-students-template.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, $headers );
        fputcsv( $output, $row );
        fclose( $output );
        exit;
    }

    private function handle_students_csv_upload( int $school_id ): array {
        return $this->process_students_csv_upload( $school_id, true );
    }

    private function preview_students_csv_upload( int $school_id ): array {
        return $this->process_students_csv_upload( $school_id, false );
    }

    private function process_students_csv_upload( int $school_id, bool $import_students ): array {
        if ( $school_id <= 0 ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0, 'subject_alias_suggestions' => [] ];
        }

        if ( empty( $_FILES['students_csv']['tmp_name'] ) ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0, 'subject_alias_suggestions' => [] ];
        }

        $tmp_name = sanitize_text_field( wp_unslash( $_FILES['students_csv']['tmp_name'] ) );
        if ( ! file_exists( $tmp_name ) ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0, 'subject_alias_suggestions' => [] ];
        }

        $handle = fopen( $tmp_name, 'r' );
        if ( ! $handle ) {
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0, 'subject_alias_suggestions' => [] ];
        }

        $header = fgetcsv( $handle );
        if ( ! is_array( $header ) ) {
            fclose( $handle );
            return [ 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0, 'subject_alias_suggestions' => [] ];
        }

        $map = array_flip( array_map( 'trim', $header ) );
        $count = 0;
        $processed = 0;
        $duplicates = 0;
        $failed = 0;
        $import_subjects = [];

        if ( ! $import_students ) {
            $students = [];
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( empty( array_filter( $row, static fn( $value ): bool => trim( (string) $value ) !== '' ) ) ) {
                continue;
            }

            $processed++;
            $registration_number = sanitize_text_field( $row[ $map['registration_number'] ?? -1 ] ?? '' );
            if ( $registration_number === '' ) {
                $registration_number = sanitize_text_field( $row[ $map['admission_number'] ?? -1 ] ?? '' );
            }

            $manual_subjects = $this->convert_csv_to_array( (string) ( $row[ $map['manual_subjects'] ?? -1 ] ?? '' ) );
            if ( ! empty( $manual_subjects ) ) {
                foreach ( $manual_subjects as $subject_name ) {
                    $subject_name = sanitize_text_field( (string) $subject_name );
                    if ( $subject_name !== '' ) {
                        $import_subjects[] = $subject_name;
                    }
                }
            }

            $student_data = [
                'registration_number' => $registration_number,
                'admission_number'   => $registration_number,
                'student_id'         => sanitize_text_field( $row[ $map['student_id'] ?? -1 ] ?? '' ),
                'first_name'         => sanitize_text_field( $row[ $map['first_name'] ?? -1 ] ?? '' ),
                'last_name'          => sanitize_text_field( $row[ $map['last_name'] ?? -1 ] ?? '' ),
                'full_name'          => sanitize_text_field( $row[ $map['full_name'] ?? -1 ] ?? '' ),
                'gender'             => sanitize_text_field( $row[ $map['gender'] ?? -1 ] ?? '' ),
                'date_of_birth'      => sanitize_text_field( $row[ $map['date_of_birth'] ?? -1 ] ?? '' ),
                'parent_information' => sanitize_textarea_field( $row[ $map['parent_information'] ?? -1 ] ?? '' ),
                'parent_phone'       => sanitize_text_field( $row[ $map['parent_phone'] ?? -1 ] ?? '' ),
                'parent_email'       => sanitize_email( $row[ $map['parent_email'] ?? -1 ] ?? '' ),
                'address'            => sanitize_textarea_field( $row[ $map['address'] ?? -1 ] ?? '' ),
                'session_year'       => sanitize_text_field( $row[ $map['session_year'] ?? -1 ] ?? '' ),
                'class'              => sanitize_text_field( $row[ $map['class'] ?? -1 ] ?? '' ),
                'arm'                => sanitize_text_field( $row[ $map['arm'] ?? -1 ] ?? '' ),
                'department'         => sanitize_text_field( $row[ $map['department'] ?? -1 ] ?? '' ),
                'status'             => sanitize_text_field( $row[ $map['status'] ?? -1 ] ?? 'active' ),
                'login_username'     => sanitize_user( $row[ $map['login_username'] ?? -1 ] ?? '' ),
                'temporary_password' => (string) ( $row[ $map['temporary_password'] ?? -1 ] ?? '' ),
                'manual_subjects'    => $manual_subjects,
            ];

            if ( ! $import_students ) {
                continue;
            }

            $created = $this->student_service->create_student( $school_id, $student_data );
            if ( $created > 0 ) {
                $count++;
            } else {
                $failed++;
            }
        }

        fclose( $handle );
        $subject_alias_suggestions = $this->subject_service->suggest_subject_aliases( $school_id, $import_subjects );

        return [
            'processed' => $processed,
            'imported' => $count,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'subject_alias_suggestions' => $subject_alias_suggestions,
        ];
    }

    private function render_input_row( string $name, string $label, string $type = 'text' ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" class="regular-text"></td></tr>';
    }

    private function render_input_row_with_value( string $name, string $label, string $value, string $type = 'text' ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" class="regular-text" value="' . esc_attr( $value ) . '"></td></tr>';
    }

    private function render_textarea_row( string $name, string $label ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="large-text" rows="4"></textarea></td></tr>';
    }

    private function render_textarea_row_with_value( string $name, string $label, string $value ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="large-text" rows="4">' . esc_textarea( $value ) . '</textarea></td></tr>';
    }

    private function render_select_row( string $name, string $label, array $options, bool $required = false ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="regular-text"' . ( $required ? ' required' : '' ) . '>';
        echo '<option value="">' . esc_html__( 'Select...', 'educbt-pro' ) . '</option>';

        foreach ( $options as $value => $text ) {
            $value = sanitize_text_field( (string) $value );
            $text = sanitize_text_field( (string) $text );

            if ( $value === '' ) {
                continue;
            }

            echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $text ) . '</option>';
        }

        echo '</select>';
        echo '</td></tr>';
    }

    private function render_select_row_with_value( string $name, string $label, array $options, string $selected_value = '', bool $required = false ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="regular-text"' . ( $required ? ' required' : '' ) . '>';
        echo '<option value="">' . esc_html__( 'Select...', 'educbt-pro' ) . '</option>';

        foreach ( $options as $value => $text ) {
            $value = sanitize_text_field( (string) $value );
            $text = sanitize_text_field( (string) $text );

            if ( $value === '' ) {
                continue;
            }

            echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected_value, $value, false ) . '>' . esc_html( $text ) . '</option>';
        }

        echo '</select>';
        echo '</td></tr>';
    }

    private function convert_csv_to_array( string $csv ): array {
        $items = array_filter( array_map( 'trim', explode( ',', $csv ) ) );
        return array_values( $items );
    }

    private function parse_subject_alias_mapping_text( string $text ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $text ) ?: [];
        $aliases = [];

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) {
                continue;
            }

            $parts = preg_split( '/=>|:|=/', $line, 2 );
            if ( ! is_array( $parts ) || count( $parts ) !== 2 ) {
                continue;
            }

            $alias = strtolower( sanitize_text_field( trim( (string) $parts[0] ) ) );
            $canonical = sanitize_text_field( trim( (string) $parts[1] ) );
            if ( $alias === '' || $canonical === '' ) {
                continue;
            }

            $aliases[ $alias ] = $canonical;
        }

        return $aliases;
    }
}
