<?php

namespace EduCBTPro\Api;

use EduCBTPro\Core\TenantContext;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Scope;
use EduCBTPro\Core\Capabilities;
use EduCBTPro\Services\ExamService;
use EduCBTPro\Services\ResultService;
use EduCBTPro\Services\AcademicAnalyticsService;
use EduCBTPro\Services\QuestionAnalyticsService;
use EduCBTPro\Services\OperationalReadinessService;
use EduCBTPro\Services\IntegrityAnalyticsService;
use EduCBTPro\Services\RiskAlertService;
use EduCBTPro\Services\PerformanceTrendService;
use EduCBTPro\Services\ExamComparativeAnalyticsService;
use EduCBTPro\Services\ResultApprovalService;
use EduCBTPro\Services\ContinuousAssessmentService;
use EduCBTPro\Services\BroadsheetService;
use EduCBTPro\Services\ExamAttemptService;
use EduCBTPro\Services\PromotionService;
use EduCBTPro\Services\TranscriptService;
use EduCBTPro\Services\AuditLogService;
use EduCBTPro\Services\StudentService;
use EduCBTPro\Services\TeacherService;
use EduCBTPro\Services\ClassService;
use EduCBTPro\Services\SchoolService;
use EduCBTPro\Services\SubjectService;
use EduCBTPro\Services\QuestionService;
use EduCBTPro\Services\PrivacyComplianceService;
use EduCBTPro\Services\NotificationService;
use EduCBTPro\Services\ExamTimetableService;
use EduCBTPro\Core\Repository\StudentRepository;
use EduCBTPro\Services\ExamIntegrityEventService;
use EduCBTPro\Services\StudentProfileUpdateService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RestController {
    private TenantContext $tenant_context;

    public function __construct( TenantContext $tenant_context ) {
        $this->tenant_context = $tenant_context;
    }

    public function register_routes(): void {
        register_rest_route(
            'educbt-pro/v1',
            '/schools',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_schools' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_school' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'school_name'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'school_code'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'address'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'phone'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'email'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'principal_name' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/teachers',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_teachers' ],
                    'permission_callback' => [ $this, 'permission_view_teachers' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_teacher' ],
                    'permission_callback' => [ $this, 'permission_manage_teachers' ],
                    'args'                => [
                        'teacher_id'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'full_name'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'contact_details'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'subjects'         => [ 'required' => false, 'type' => 'array' ],
                        'assigned_classes' => [ 'required' => false, 'type' => 'array' ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/teachers/bulk',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'bulk_create_teachers' ],
                    'permission_callback' => [ $this, 'permission_manage_teachers' ],
                    'args'                => [
                        'teachers' => [ 'required' => true, 'type' => 'array', 'validate_callback' => [ $this, 'validate_non_empty_array' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/subjects',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_subjects' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_subject' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'subject_name' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'subject_code' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'subject_type' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/classes',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_classes' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_class' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'class_name'  => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'arm'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'class_level' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'status'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/questions',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_questions' ],
                    'permission_callback' => [ $this, 'permission_manage_exams' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_question' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'question_text' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'subject'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'section'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'passage_text'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'topic'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'sub_topic'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'class'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'department'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'difficulty'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'learning_objective' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'bloom_level'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'examination_type' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'examination_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'estimated_duration' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'marks'         => [ 'required' => false, 'type' => 'number' ],
                        'image_reference' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'options'       => [ 'required' => false, 'type' => 'array' ],
                        'answers'       => [ 'required' => false, 'type' => 'array' ],
                        'question_type' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        // Exams
        register_rest_route(
            'educbt-pro/v1',
            '/exams',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_exams' ],
                    'permission_callback' => [ $this, 'permission_manage_exams' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_exam' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'title'          => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'exam_type'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'description'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'start_time'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'end_time'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'duration_minutes' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'is_published'   => [ 'required' => false, 'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_boolean' ] ],
                    ],
                ],
            ]
        );


        // Question bank — the in-place save the teacher authoring form uses.
        // This accepts the exact field names the JS sends (subject_id, class_level,
        // option_A..D, correct, marking_guide, passage_id) so the form works
        // without a page reload. The older /questions route stays for backward compat.
        register_rest_route(
            'educbt-pro/v1',
            '/question-bank',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'save_question_bank' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                ],
            ]
        );

        // Exam question linkage
        register_rest_route(
            'educbt-pro/v1',
            '/exam-questions',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_exam_questions' ],
                    'permission_callback' => [ $this, 'permission_manage_exams' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'assign_exam_questions' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'exam_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'question_ids' => [ 'required' => true, 'type' => 'array', 'validate_callback' => [ $this, 'validate_non_empty_array' ] ],
                    ],
                ],
            ]
        );

        // Exam attempts (sessions)
        register_rest_route(
            'educbt-pro/v1',
            '/exam-attempts',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_exam_attempts' ],
                    'permission_callback' => [ $this, 'permission_manage_exams' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_exam_attempt' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'exam_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'randomize_questions' => [ 'required' => false, 'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_boolean' ] ],
                        'randomize_options' => [ 'required' => false, 'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_boolean' ] ],
                    ],
                ],
            ]
        );

        // Exam submissions (auto-grading)
        register_rest_route(
            'educbt-pro/v1',
            '/exam-submissions',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'submit_exam' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'attempt_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    'responses'   => [ 'required' => true, 'type' => 'object' ],
                ],
            ]
        );

        // Exam results
        register_rest_route(
            'educbt-pro/v1',
            '/exam-results',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_exam_results' ],
                'permission_callback' => [ $this, 'permission_view_results' ],
            ]
        );

        // Results

        register_rest_route(
            'educbt-pro/v1',
            '/academic-intelligence',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_academic_intelligence' ],
                'permission_callback' => [ $this, 'permission_manage_exams' ],
                'args'                => [
                    'exam_id'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    'subject'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'class'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'term'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/question-analytics',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_question_analytics' ],
                'permission_callback' => [ $this, 'permission_manage_exams' ],
                'args'                => [
                    'exam_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/operational-readiness',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_operational_readiness' ],
                'permission_callback' => [ $this, 'permission_manage_exams' ],
                'args'                => [
                    'exam_id'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    'subject'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'term'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/integrity-analytics',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_integrity_analytics' ],
                'permission_callback' => [ $this, 'permission_manage_exams' ],
                'args'                => [
                    'exam_id'        => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    'subject'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'term'           => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'min_similarity' => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'min_questions'  => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/risk-alerts',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_risk_alerts' ],
                'permission_callback' => [ $this, 'permission_manage_exams' ],
                'args'                => [
                    'exam_id'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    'subject'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'class'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'term'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'min_similarity' => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'min_questions'  => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/performance-trends',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_performance_trends' ],
                'permission_callback' => [ $this, 'permission_view_results' ],
                'args'                => [
                    'student_id'   => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    'subject'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'class'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/exam-comparison',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_exam_comparison' ],
                'permission_callback' => [ $this, 'permission_manage_exams' ],
                'args'                => [
                    'exam_ids'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'subject'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'term'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/broadsheet',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_broadsheet' ],
                'permission_callback' => [ $this, 'permission_view_results' ],
                'args'                => [
                    'type'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'class'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'subject'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'term'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                ],
            ]
        );

        // Privacy compliance
        register_rest_route(
            'educbt-pro/v1',
            '/privacy/export',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_privacy_export' ],
                'permission_callback' => [ $this, 'permission_manage_options' ],
                'args'                => [
                    'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/privacy/consents',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_privacy_consents' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_privacy_consent' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'purpose'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'granted'    => [ 'required' => true, 'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_boolean' ], 'validate_callback' => [ $this, 'validate_boolean_value' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/privacy/erasure',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'request_privacy_erasure' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    'confirmed'  => [ 'required' => false, 'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_boolean' ], 'validate_callback' => [ $this, 'validate_boolean_value' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/privacy/retention',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_privacy_retention' ],
                'permission_callback' => [ $this, 'permission_manage_options' ],
                'args'                => [
                    'days' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/ca-compute',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'compute_ca' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'assignment'   => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'test1'        => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'test2'        => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'project'      => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'attendance'   => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                    'exam'         => [ 'required' => true,  'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                    'weights'      => [ 'required' => false, 'type' => 'object' ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/result-approval',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'transition_result_status' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'result_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    'action'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                    'comment'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/results',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_results' ],
                    'permission_callback' => [ $this, 'permission_view_results' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_result' ],
                    'permission_callback' => [ $this, 'permission_manage_results' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'subject' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'score' => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                        'grade' => [ 'required' => false, 'type' => 'number', 'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                        'remark' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'term' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/results/bulk',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'bulk_create_results' ],
                    'permission_callback' => [ $this, 'permission_manage_results' ],
                    'args'                => [
                        'results' => [ 'required' => true, 'type' => 'array', 'validate_callback' => [ $this, 'validate_non_empty_array' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/results/(?P<result_id>\d+)/grade',
            [
                [
                    'methods'             => 'PUT',
                    'callback'            => [ $this, 'update_result_grade' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'result_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'grade'     => [ 'required' => true, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ],  'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'remark'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/results/(?P<result_id>\d+)/theory-mark',
            [
                [
                    'methods'             => 'PUT',
                    'callback'            => [ $this, 'mark_theory_result' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'result_id'       => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'objective_score' => [ 'required' => true, 'type' => 'number',  'sanitize_callback' => [ $this, 'sanitize_number' ], 'validate_callback' => [ $this, 'validate_numeric' ] ],
                        'theory_score'    => [ 'required' => true, 'type' => 'number',  'sanitize_callback' => [ $this, 'sanitize_number' ], 'validate_callback' => [ $this, 'validate_numeric' ] ],
                        'max_score'       => [ 'required' => false, 'type' => 'number',  'sanitize_callback' => [ $this, 'sanitize_number' ] ],
                        'remark'          => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        // Promotions
        register_rest_route(
            'educbt-pro/v1',
            '/promotions',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_promotions' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_promotion' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'to_class' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'from_class' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'session_year' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        // Transcripts
        register_rest_route(
            'educbt-pro/v1',
            '/transcripts',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_transcripts' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_transcript' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'terms' => [ 'required' => false, 'type' => 'array' ],
                        'sessions' => [ 'required' => false, 'type' => 'array' ],
                        'summary' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        // Audit logs
        register_rest_route(
            'educbt-pro/v1',
            '/audit-logs',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_audit_logs' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                    'args'                => [
                        'action'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'object_type' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'user_id'     => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'limit'       => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_audit_log' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'user_id'        => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'action'         => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'object_type'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'object_id'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'previous_value' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'new_value'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'ip_address'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'device'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );


        // Students
        register_rest_route(
            'educbt-pro/v1',
            '/students',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_students' ],
                    'permission_callback' => [ $this, 'permission_view_students' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_student' ],
                    'permission_callback' => [ $this, 'permission_manage_students' ],
                    'args'                => [
                        'registration_number' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'admission_number' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'student_id'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'first_name'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'last_name'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'full_name'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'gender'           => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'date_of_birth'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'parent_information'=>[ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'parent_phone'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'parent_email'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'address'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'class'            => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'arm'              => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'department'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'session_year'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'login_username'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'temporary_password' => [ 'required' => false, 'type' => 'string' ],
                        'manual_subjects'  => [ 'required' => false, 'type' => 'array' ],
                        'status'           => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/students/bulk',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'bulk_create_students' ],
                    'permission_callback' => [ $this, 'permission_manage_students' ],
                    'args'                => [
                        'students' => [ 'required' => true, 'type' => 'array', 'validate_callback' => [ $this, 'validate_non_empty_array' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/students/(?P<student_id>\d+)',
            [
                [
                    'methods'             => 'PUT',
                    'callback'            => [ $this, 'update_student' ],
                    'permission_callback' => [ $this, 'permission_manage_students' ],
                    'args'                => [
                        'student_id'       => [ 'required' => true,  'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'full_name'        => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'class'            => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'arm'              => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'status'           => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student-profile-updates',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'list_student_profile_updates' ],
                    'permission_callback' => [ $this, 'permission_manage_students' ],
                    'args'                => [
                        'status' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'limit' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'submit_student_profile_update' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'changes' => [ 'required' => true, 'type' => 'object' ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student-profile-updates/(?P<request_id>\d+)/decision',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'decide_student_profile_update' ],
                    'permission_callback' => [ $this, 'permission_manage_students' ],
                    'args'                => [
                        'request_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'decision' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'review_note' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/parent/results',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_parent_results' ],
                    'permission_callback' => [ $this, 'permission_parent_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/parent/transcripts',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_parent_transcripts' ],
                    'permission_callback' => [ $this, 'permission_parent_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/parent/reports',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_parent_reports' ],
                    'permission_callback' => [ $this, 'permission_parent_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/parent/progress',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_parent_progress' ],
                    'permission_callback' => [ $this, 'permission_parent_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'subject'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/exams',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_student_exams' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/results',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_student_results' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/transcripts',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_student_transcripts' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/reports',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_student_reports' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/progress',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_student_progress' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'subject'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/exams/start',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'start_student_exam' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'exam_id'    => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'student_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/exams/session',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_student_exam_session' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'attempt_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/exams/autosave',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'autosave_student_exam_session' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'attempt_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'responses' => [ 'required' => false, 'type' => 'object' ],

                        'current_index' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/student/exams/submit',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'submit_student_exam' ],
                    'permission_callback' => [ $this, 'permission_student_portal' ],
                    'args'                => [
                        'attempt_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'responses' => [ 'required' => false, 'type' => 'object' ],
                    ],
                ],
            ]
        );

        // Notifications
        register_rest_route(
            'educbt-pro/v1',
            '/notifications',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_notifications' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                    'args'                => [
                        'recipient_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_notification' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'recipient_id' => [ 'required' => true,  'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'title'        => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ],  'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'message'      => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ],  'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'type'         => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/notifications/unread-count',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_notifications_unread_count' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                    'args'                => [
                        'recipient_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/notifications/mark-read',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'mark_notification_read' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'notification_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'recipient_id'    => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/notifications/mark-all-read',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'mark_all_notifications_read' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'recipient_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/timetables',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_timetables' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_timetable' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'exam_id'          => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'session_year'     => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'term'             => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'class_name'       => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'arm'              => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'department'       => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'subject'          => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'exam_type'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'exam_date'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'start_time'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'end_time'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'duration_minutes' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'venue'            => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'invigilator'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'is_trial_mode'    => [ 'required' => false, 'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_boolean' ] ],
                        'status'           => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/exam-integrity-events',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_integrity_events' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                    'args'                => [
                        'attempt_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'exam_id'    => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'student_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'event_type' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'date_from'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'date_to'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ] ],
                        'limit'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'create_integrity_event' ],
                    'permission_callback' => [ $this, 'permission_check' ],
                    'args'                => [
                        'attempt_id'     => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'exam_id'        => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'student_id'     => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ], 'validate_callback' => [ $this, 'validate_positive_integer' ] ],
                        'event_type'     => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_string' ], 'validate_callback' => [ $this, 'validate_non_empty_string' ] ],
                        'event_payload'  => [ 'required' => false, 'type' => 'object' ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'educbt-pro/v1',
            '/integrity-monitoring-settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_integrity_monitoring_settings' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'update_integrity_monitoring_settings' ],
                    'permission_callback' => [ $this, 'permission_manage_options' ],
                    'args'                => [
                        'blur_threshold' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'hidden_threshold' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                        'total_suspicious_threshold' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => [ $this, 'sanitize_integer' ] ],
                    ],
                ],
            ]
        );
    }

    public function get_exams( $request ): array {
        $svc = new ExamService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        return [ 'success' => true, 'data' => $svc->list_exams( $school_id ) ];
    }

    public function create_exam( $request ): array {
        $svc = new ExamService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        if ( empty( $params['title'] ) ) {
            return [ 'success' => false, 'message' => 'title_required' ];
        }

        $id = $svc->create_exam( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function get_timetables( $request ): array {
        $svc = new ExamTimetableService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        return [ 'success' => true, 'data' => $svc->list_timetables( $school_id ) ];
    }

    public function create_timetable( $request ): array {
        $svc = new ExamTimetableService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        if ( empty( $params['class_name'] ) || empty( $params['department'] ) || empty( $params['subject'] ) ) {
            return [ 'success' => false, 'message' => 'class_name_department_subject_required' ];
        }

        $constraint = $svc->validate_daily_subject_constraints( $school_id, $params );
        if ( ! $constraint['success'] ) {
            return [ 'success' => false, 'message' => $constraint['message'] ];
        }

        $id = $svc->create_timetable( $school_id, $params );

        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function get_integrity_events( $request ): array {
        $svc = new ExamIntegrityEventService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];

        $filters = [
            'attempt_id' => absint( $params['attempt_id'] ?? 0 ),
            'exam_id'    => absint( $params['exam_id'] ?? 0 ),
            'student_id' => absint( $params['student_id'] ?? 0 ),
            'event_type' => sanitize_text_field( $params['event_type'] ?? '' ),
            'date_from'  => sanitize_text_field( $params['date_from'] ?? '' ),
            'date_to'    => sanitize_text_field( $params['date_to'] ?? '' ),
            'limit'      => absint( $params['limit'] ?? 200 ),
        ];

        return [ 'success' => true, 'data' => $svc->list_events( $school_id, $filters ) ];
    }

    public function create_integrity_event( $request ): array {
        $svc = new ExamIntegrityEventService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        if ( empty( $params['exam_id'] ) || empty( $params['student_id'] ) || empty( $params['event_type'] ) ) {
            return [ 'success' => false, 'message' => 'exam_id_student_id_event_type_required' ];
        }

        $scope_error = $this->enforce_portal_student_scope( absint( $params['student_id'] ), 'create_integrity_event' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $id = $svc->log_event( $school_id, $params );

        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function get_integrity_monitoring_settings( $request ): array {
        $svc = new SchoolService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        return [ 'success' => true, 'data' => $svc->get_integrity_monitoring_settings( $school_id ) ];
    }

    public function update_integrity_monitoring_settings( $request ): array {
        $svc = new SchoolService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $payload = [
            'blur_threshold' => absint( $params['blur_threshold'] ?? 0 ),
            'hidden_threshold' => absint( $params['hidden_threshold'] ?? 0 ),
            'total_suspicious_threshold' => absint( $params['total_suspicious_threshold'] ?? 0 ),
        ];

        $updated = $svc->update_integrity_monitoring_settings( $school_id, $payload );
        if ( ! $updated ) {
            return [ 'success' => false, 'message' => 'integrity_settings_update_failed' ];
        }

        return [ 'success' => true, 'data' => $svc->get_integrity_monitoring_settings( $school_id ) ];
    }

    public function get_exam_questions( $request ): array {
        $svc = new ExamService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $exam_id = absint( $params['exam_id'] ?? 0 );

        if ( $exam_id <= 0 ) {
            return [ 'success' => false, 'message' => 'exam_id_required' ];
        }

        return [ 'success' => true, 'data' => $svc->list_exam_questions( $school_id, $exam_id ) ];
    }

    public function assign_exam_questions( $request ): array {
        $svc = new ExamService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $exam_id = absint( $params['exam_id'] ?? 0 );
        $question_ids = isset( $params['question_ids'] ) && is_array( $params['question_ids'] ) ? $params['question_ids'] : [];

        if ( $exam_id <= 0 ) {
            return [ 'success' => false, 'message' => 'exam_id_required' ];
        }

        if ( empty( $question_ids ) ) {
            return [ 'success' => false, 'message' => 'question_ids_required' ];
        }

        $assigned = $svc->assign_questions( $school_id, $exam_id, $question_ids );
        return [ 'success' => $assigned > 0, 'assigned' => $assigned ];
    }

    public function get_exam_attempts( $request ): array {
        $svc = new ExamAttemptService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        return [ 'success' => true, 'data' => $svc->get_all_attempts( $school_id ) ];
    }

    public function create_exam_attempt( $request ): array {
        $svc = new ExamAttemptService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $exam_id = absint( $params['exam_id'] ?? 0 );
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $exam_id <= 0 || $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'exam_id_and_student_id_required' ];
        }

        $options = [
            'randomize_questions' => $params['randomize_questions'] ?? false,
            'randomize_options'   => $params['randomize_options'] ?? false,
        ];

        $attempt_id = $svc->create_attempt( $school_id, $exam_id, $student_id, $options );

        if ( $attempt_id <= 0 && $svc->get_active_attempt( $school_id, $exam_id, $student_id ) ) {
            return [ 'success' => false, 'message' => 'duplicate_active_attempt' ];
        }

        return [ 'success' => $attempt_id > 0, 'attempt_id' => $attempt_id ];
    }

    public function submit_exam( $request ): array {
        $svc = new ResultService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $attempt_id = absint( $params['attempt_id'] ?? 0 );
        $responses = isset( $params['responses'] ) && is_array( $params['responses'] ) ? $params['responses'] : [];

        if ( $attempt_id <= 0 ) {
            return [ 'success' => false, 'message' => 'attempt_id_required' ];
        }

        if ( empty( $responses ) ) {
            return [ 'success' => false, 'message' => 'responses_required' ];
        }

        return $svc->submit_exam( $school_id, $attempt_id, $responses );
    }

    public function get_exam_results( $request ): array {
        $svc = new ResultService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $exam_id = absint( $params['exam_id'] ?? 0 );

        if ( $exam_id > 0 ) {
            return [ 'success' => true, 'data' => $svc->get_exam_results( $school_id, $exam_id ) ];
        }

        return [ 'success' => true, 'data' => $svc->list_results( $school_id ) ];
    }

    public function get_results( $request ): array {
        $svc = new ResultService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        return [ 'success' => true, 'data' => $svc->list_results( $school_id ) ];
    }

    public function get_privacy_export( $request ): array {
        $svc = new PrivacyComplianceService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $scope_error = $this->enforce_privacy_student_scope( $student_id );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        return $svc->export_student_data( $school_id, $student_id );
    }

    public function get_privacy_consents( $request ): array {
        $svc = new PrivacyComplianceService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $scope_error = $this->enforce_privacy_student_scope( $student_id );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        return [
            'success' => true,
            'data'    => $svc->get_consent_history( $school_id, $student_id ),
        ];
    }

    public function create_privacy_consent( $request ): array {
        $svc = new PrivacyComplianceService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $student_id = absint( $params['student_id'] ?? 0 );
        $purpose = sanitize_text_field( $params['purpose'] ?? '' );
        $granted = filter_var( $params['granted'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        if ( $purpose === '' ) {
            return [ 'success' => false, 'message' => 'purpose_required' ];
        }

        $scope_error = $this->enforce_privacy_student_scope( $student_id );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        return $svc->record_consent( $school_id, $student_id, $purpose, $granted );
    }

    public function request_privacy_erasure( $request ): array {
        $svc = new PrivacyComplianceService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $student_id = absint( $params['student_id'] ?? 0 );
        $confirmed = filter_var( $params['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $scope_error = $this->enforce_privacy_student_scope( $student_id );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        return $svc->request_erasure( $school_id, $student_id, $confirmed );
    }

    public function get_privacy_retention( $request ): array {
        $svc = new PrivacyComplianceService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $days = absint( $params['days'] ?? 2555 );
        if ( $days <= 0 ) {
            return [ 'success' => false, 'message' => 'days_required' ];
        }

        return [
            'success' => true,
            'data'    => $svc->get_expired_records( $school_id, $days ),
        ];
    }

    public function get_academic_intelligence( $request ): array {
        $svc = new AcademicAnalyticsService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $filters = [
            'exam_id'      => absint( $params['exam_id'] ?? 0 ),
            'subject'      => sanitize_text_field( $params['subject'] ?? '' ),
            'class'        => sanitize_text_field( $params['class'] ?? '' ),
            'term'         => sanitize_text_field( $params['term'] ?? '' ),
            'session_year' => sanitize_text_field( $params['session_year'] ?? '' ),
        ];

        return [
            'success' => true,
            'data'    => $svc->get_academic_intelligence( $school_id, $filters ),
        ];
    }

    public function get_question_analytics( $request ): array {
        $svc = new QuestionAnalyticsService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $exam_id = absint( $params['exam_id'] ?? 0 );

        return [
            'success' => true,
            'data'    => $svc->analyze_questions( $school_id, $exam_id ),
        ];
    }

    public function get_operational_readiness( $request ): array {
        $svc = new OperationalReadinessService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $filters = [
            'exam_id'      => absint( $params['exam_id'] ?? 0 ),
            'subject'      => sanitize_text_field( $params['subject'] ?? '' ),
            'term'         => sanitize_text_field( $params['term'] ?? '' ),
            'session_year' => sanitize_text_field( $params['session_year'] ?? '' ),
        ];

        return [
            'success' => true,
            'data'    => $svc->get_operational_readiness( $school_id, $filters ),
        ];
    }

    public function get_integrity_analytics( $request ): array {
        $svc = new IntegrityAnalyticsService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $filters = [
            'exam_id'        => absint( $params['exam_id'] ?? 0 ),
            'subject'        => sanitize_text_field( $params['subject'] ?? '' ),
            'term'           => sanitize_text_field( $params['term'] ?? '' ),
            'session_year'   => sanitize_text_field( $params['session_year'] ?? '' ),
            'min_similarity' => floatval( $params['min_similarity'] ?? 90 ),
            'min_questions'  => absint( $params['min_questions'] ?? 5 ),
        ];

        return [
            'success' => true,
            'data'    => $svc->get_integrity_analytics( $school_id, $filters ),
        ];
    }

    public function get_risk_alerts( $request ): array {
        $svc = new RiskAlertService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $filters = [
            'exam_id'        => absint( $params['exam_id'] ?? 0 ),
            'subject'        => sanitize_text_field( $params['subject'] ?? '' ),
            'class'          => sanitize_text_field( $params['class'] ?? '' ),
            'term'           => sanitize_text_field( $params['term'] ?? '' ),
            'session_year'   => sanitize_text_field( $params['session_year'] ?? '' ),
            'min_similarity' => floatval( $params['min_similarity'] ?? 90 ),
            'min_questions'  => absint( $params['min_questions'] ?? 5 ),
        ];

        return [
            'success' => true,
            'data'    => $svc->get_risk_alerts( $school_id, $filters ),
        ];
    }

    public function get_performance_trends( $request ): array {
        $svc = new PerformanceTrendService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $filters = [
            'student_id'   => absint( $params['student_id'] ?? 0 ),
            'subject'      => sanitize_text_field( $params['subject'] ?? '' ),
            'class'        => sanitize_text_field( $params['class'] ?? '' ),
            'session_year' => sanitize_text_field( $params['session_year'] ?? '' ),
        ];

        return [
            'success' => true,
            'data'    => $svc->get_performance_trends( $school_id, $filters ),
        ];
    }

    public function get_exam_comparison( $request ): array {
        $svc = new ExamComparativeAnalyticsService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $exam_ids = array_filter( array_map( 'absint', explode( ',', (string) ( $params['exam_ids'] ?? '' ) ) ) );

        $filters = [
            'exam_ids'     => $exam_ids,
            'subject'      => sanitize_text_field( $params['subject'] ?? '' ),
            'session_year' => sanitize_text_field( $params['session_year'] ?? '' ),
            'term'         => sanitize_text_field( $params['term'] ?? '' ),
        ];

        return [
            'success' => true,
            'data'    => $svc->compare_exams( $school_id, $filters ),
        ];
    }

    public function transition_result_status( $request ): array {
        $svc = new ResultApprovalService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $result_id   = absint( $params['result_id'] ?? 0 );
        $action      = sanitize_text_field( $params['action'] ?? '' );
        $comment     = sanitize_text_field( $params['comment'] ?? '' );
        $actor_roles = (array) ( $params['actor_roles'] ?? [] );

        if ( $result_id <= 0 || $action === '' ) {
            return [ 'success' => false, 'message' => 'result_id_and_action_required' ];
        }

        return $svc->transition( $school_id, $result_id, $action, $actor_roles, $comment );
    }

    public function compute_ca( $request ): array {
        $svc    = new ContinuousAssessmentService();
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $components = [
            'assignment' => floatval( $params['assignment'] ?? 0 ),
            'test1'      => floatval( $params['test1']      ?? 0 ),
            'test2'      => floatval( $params['test2']      ?? 0 ),
            'project'    => floatval( $params['project']    ?? 0 ),
            'attendance' => floatval( $params['attendance'] ?? 0 ),
            'exam'       => floatval( $params['exam']       ?? 0 ),
        ];

        $weights = isset( $params['weights'] ) && is_array( $params['weights'] ) ? $params['weights'] : [];

        return [
            'success' => true,
            'data'    => $svc->compute_ca( $components, $weights ),
        ];
    }

    public function get_broadsheet( $request ): array {
        $svc       = new BroadsheetService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params    = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        // BroadsheetService exposes build()/to_rows() and works in relational ids.
        // The three generate_*_broadsheet() methods called here never existed, and
        // the free-text class/term/session_year they were fed are exactly the v1
        // string keys Phase 1 replaced. Every request to this route was a fatal.
        $class_id   = absint( $params['class_id'] ?? 0 );
        $session_id = absint( $params['session_id'] ?? 0 );
        $term_id    = absint( $params['term_id'] ?? 0 );

        $ay = new \EduCBTPro\Services\AcademicYearService();

        if ( $session_id <= 0 ) {
            $session_id = absint( ( $ay->current_session( $school_id ) )['id'] ?? 0 );
        }

        if ( $term_id <= 0 ) {
            $term_id = absint( ( $ay->resolve_current_term( $school_id, $session_id ) )['id'] ?? 0 );
        }

        if ( $class_id <= 0 || $session_id <= 0 || $term_id <= 0 ) {
            return [ 'success' => false, 'message' => 'class_id_session_id_and_term_id_required' ];
        }

        $broadsheet = $svc->build( $school_id, $class_id, $session_id, $term_id );

        return [
            'success' => true,
            'data'    => $broadsheet,
            'rows'    => $svc->to_rows( $broadsheet ),
        ];
    }

    public function create_result( $request ): array {
        $svc = new ResultService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        if ( empty( $params['student_id'] ) || empty( $params['subject'] ) ) {
            return [ 'success' => false, 'message' => 'student_and_subject_required' ];
        }

        $id = $svc->create_result( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function bulk_create_results( $request ): array {
        $svc = new ResultService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $results = isset( $params['results'] ) && is_array( $params['results'] ) ? $params['results'] : [];

        if ( empty( $results ) ) {
            return [ 'success' => false, 'message' => 'results_required' ];
        }

        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ( $results as $index => $result_data ) {
            if ( ! is_array( $result_data ) || empty( $result_data['student_id'] ) || empty( $result_data['subject'] ) ) {
                $failed++;
                $errors[] = [ 'index' => $index, 'message' => 'student_and_subject_required' ];
                continue;
            }

            $id = $svc->create_result( $school_id, $result_data );
            if ( $id > 0 ) {
                $created++;
                continue;
            }

            $failed++;
            $errors[] = [ 'index' => $index, 'message' => 'create_failed' ];
        }

        return [
            'success'       => $created > 0,
            'created_count' => $created,
            'failed_count'  => $failed,
            'errors'        => $errors,
        ];
    }

    public function update_result_grade( $request ): array {
        $svc = new ResultService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $route_params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];

        $result_id = absint( $route_params['result_id'] ?? $params['result_id'] ?? 0 );
        $grade = sanitize_text_field( $params['grade'] ?? '' );
        $remark = sanitize_text_field( $params['remark'] ?? '' );

        if ( $result_id <= 0 ) {
            return [ 'success' => false, 'message' => 'result_id_required' ];
        }

        if ( $grade === '' ) {
            return [ 'success' => false, 'message' => 'grade_required' ];
        }

        $updated = $svc->update_grade( $school_id, $result_id, $grade, $remark );

        return [
            'success'   => (bool) $updated,
            'result_id' => $result_id,
            'grade'     => $grade,
        ];
    }

    public function mark_theory_result( $request ): array {
        $svc = new ResultService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $route_params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];

        $result_id = absint( $route_params['result_id'] ?? $params['result_id'] ?? 0 );
        if ( $result_id <= 0 ) {
            return [ 'success' => false, 'message' => 'result_id_required' ];
        }

        if ( ! array_key_exists( 'objective_score', $params ) ) {
            return [ 'success' => false, 'message' => 'objective_score_required' ];
        }

        if ( ! array_key_exists( 'theory_score', $params ) ) {
            return [ 'success' => false, 'message' => 'theory_score_required' ];
        }

        $objective_score = floatval( $params['objective_score'] );
        $theory_score = floatval( $params['theory_score'] );
        $max_score = array_key_exists( 'max_score', $params ) ? floatval( $params['max_score'] ) : 100.0;
        $remark = sanitize_text_field( $params['remark'] ?? '' );

        return $svc->mark_theory_result( $school_id, $result_id, $objective_score, $theory_score, $max_score, $remark );
    }

    public function get_promotions( $request ): array {
        $svc = new PromotionService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $batch_id = absint( $params['batch_id'] ?? 0 );

        // PromotionService works in batches: propose() creates one, review() reads
        // it back. list_promotions() and create_promotion() never existed, so both
        // of these routes were fatals.
        if ( $batch_id <= 0 ) {
            return [ 'success' => false, 'message' => 'batch_id_required' ];
        }

        return [ 'success' => true, 'data' => $svc->review( $school_id, $batch_id ) ];
    }

    public function create_promotion( $request ): array {
        $svc = new PromotionService();
        $params = (array) $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        $level_id        = absint( $params['level_id'] ?? 0 );
        $from_session_id = absint( $params['from_session_id'] ?? 0 );
        $to_session_id   = absint( $params['to_session_id'] ?? 0 );

        if ( $level_id <= 0 || $from_session_id <= 0 || $to_session_id <= 0 ) {
            return [ 'success' => false, 'message' => 'level_id_from_session_id_and_to_session_id_required' ];
        }

        $actor_id = absint( ( new Scope() )->actor()['id'] ?? 0 );

        $batch = $svc->propose( $school_id, $level_id, $from_session_id, $to_session_id, $actor_id );

        return [ 'success' => ! empty( $batch ), 'data' => $batch ];
    }

    public function get_transcripts( $request ): array {
        $svc = new TranscriptService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $student_id = absint( $params['student_id'] ?? 0 );

        // TranscriptService keeps issuance history per student — there is no
        // list_transcripts(). Calling it was a fatal on this route.
        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        return [ 'success' => true, 'data' => $svc->issuance_history( $school_id, $student_id ) ];
    }

    public function create_transcript( $request ): array {
        $svc = new TranscriptService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        if ( empty( $params['student_id'] ) ) {
            return [ 'success' => false, 'message' => 'student_required' ];
        }

        $id = $svc->issue(
            $school_id,
            absint( $params['student_id'] ),
            absint( get_current_user_id() ),
            sanitize_text_field( (string) ( $params['purpose'] ?? '' ) )
        );

        return [ 'success' => ! empty( $id['success'] ) || ! empty( $id['serial'] ), 'data' => $id ];
    }

    public function get_audit_logs( $request ): array {
        $svc = new AuditLogService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : ( method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [] );

        $filters = [
            'action'      => sanitize_text_field( $params['action'] ?? '' ),
            'object_type' => sanitize_text_field( $params['object_type'] ?? '' ),
            'user_id'     => absint( $params['user_id'] ?? 0 ),
            'limit'       => absint( $params['limit'] ?? 0 ),
        ];

        return [
            'success' => true,
            'data'    => $svc->get_audit_intelligence( $school_id, $filters ),
        ];
    }

    public function create_audit_log( $request ): array {
        $svc = new AuditLogService();
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        if ( empty( $params['user_id'] ) || empty( $params['action'] ) ) {
            return [ 'success' => false, 'message' => 'user_id_and_action_required' ];
        }

        $id = $svc->create_log( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }


    public function create_student( $request ): array {
        $svc = new StudentService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        $full_name = trim( (string) ( $params['full_name'] ?? '' ) );
        $first_name = trim( (string) ( $params['first_name'] ?? '' ) );
        $last_name = trim( (string) ( $params['last_name'] ?? '' ) );

        if ( $full_name === '' && $first_name === '' && $last_name === '' ) {
            return [ 'success' => false, 'message' => 'full_name_required' ];
        }

        $id = $svc->create_student( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function bulk_create_students( $request ): array {
        $svc = new StudentService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $students = isset( $params['students'] ) && is_array( $params['students'] ) ? $params['students'] : [];

        if ( empty( $students ) ) {
            return [ 'success' => false, 'message' => 'students_required' ];
        }

        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ( $students as $index => $student_data ) {
            if ( ! is_array( $student_data ) || empty( $student_data['full_name'] ) ) {
                $failed++;
                $errors[] = [ 'index' => $index, 'message' => 'full_name_required' ];
                continue;
            }

            $id = $svc->create_student( $school_id, $student_data );
            if ( $id > 0 ) {
                $created++;
                continue;
            }

            $failed++;
            $errors[] = [ 'index' => $index, 'message' => 'create_failed' ];
        }

        return [
            'success'       => $created > 0,
            'created_count' => $created,
            'failed_count'  => $failed,
            'errors'        => $errors,
        ];
    }

    public function update_student( $request ): array {
        $svc = new StudentService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $route_params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];

        $student_id = absint( $route_params['student_id'] ?? $params['student_id'] ?? 0 );
        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $update_data = [];
        foreach ( [ 'full_name', 'class', 'arm', 'status' ] as $field ) {
            if ( array_key_exists( $field, $params ) ) {
                $update_data[ $field ] = $params[ $field ];
            }
        }

        if ( empty( $update_data ) ) {
            return [ 'success' => false, 'message' => 'update_data_required' ];
        }

        $updated = $svc->update_student( $school_id, $student_id, $update_data );

        return [
            'success'    => (bool) $updated,
            'student_id' => $student_id,
        ];
    }

    public function submit_student_profile_update( $request ): array {
        $svc = new StudentProfileUpdateService();
        $student_repository = new StudentRepository();

        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $current_user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;

        $requested_student_id = absint( $params['student_id'] ?? 0 );
        $current_student = $student_repository->find_student_by_wp_user_id( $current_user_id );
        $current_student_id = absint( $current_student['id'] ?? 0 );

        if ( $requested_student_id > 0 && ! current_user_can( 'manage_options' ) && $requested_student_id !== $current_student_id ) {
            return [ 'success' => false, 'message' => 'unauthorized_student_scope' ];
        }

        $student_id = $requested_student_id > 0 ? $requested_student_id : $current_student_id;
        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $changes = isset( $params['changes'] ) && is_array( $params['changes'] ) ? $params['changes'] : [];
        if ( empty( $changes ) ) {
            return [ 'success' => false, 'message' => 'changes_required' ];
        }

        $request_id = $svc->submit_update_request( $school_id, $student_id, $current_user_id, $changes );

        return [
            'success' => $request_id > 0,
            'request_id' => $request_id,
        ];
    }

    public function list_student_profile_updates( $request ): array {
        $svc = new StudentProfileUpdateService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];

        return [
            'success' => true,
            'data' => $svc->list_requests(
                $school_id,
                [
                    'status' => sanitize_text_field( (string) ( $params['status'] ?? '' ) ),
                    'student_id' => absint( $params['student_id'] ?? 0 ),
                    'limit' => absint( $params['limit'] ?? 100 ),
                ]
            ),
        ];
    }

    public function decide_student_profile_update( $request ): array {
        $svc = new StudentProfileUpdateService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $route_params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $request_id = absint( $route_params['request_id'] ?? $params['request_id'] ?? 0 );
        $decision = sanitize_text_field( (string) ( $params['decision'] ?? '' ) );
        $review_note = sanitize_textarea_field( (string) ( $params['review_note'] ?? '' ) );

        if ( $request_id <= 0 ) {
            return [ 'success' => false, 'message' => 'request_id_required' ];
        }

        $reviewed_by_user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        $success = $svc->decide_request( $school_id, $request_id, $reviewed_by_user_id, $decision, $review_note );

        return [
            'success' => $success,
            'request_id' => $request_id,
            'decision' => $decision,
        ];
    }

    /**
     * PHASE 2: capability + scope. Extracts the object being acted on from the
     * request so Gate can check the assignment, not merely the capability.
     *
     * @param array<string,int> $extra
     */
    private function gate( string $capability, $request, array $extra = [] ): bool {
        $context = $extra;

        foreach ( [ 'class_id', 'subject_id', 'student_id', 'paper_id', 'department_id' ] as $key ) {
            if ( isset( $context[ $key ] ) ) {
                continue;
            }

            $value = method_exists( $request, 'get_param' ) ? $request->get_param( $key ) : null;
            if ( $value !== null ) {
                $context[ $key ] = absint( $value );
            }
        }

        return Gate::allows( $capability, $context );
    }

    public function permission_check( $request ): bool {
        $method = strtoupper( $request->get_method() );

        $route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        if ( $route !== '' ) {
            $capability = $this->resolve_route_capability( $method, $route );
            return $this->user_has_any_capability( [ $capability, 'manage_options' ] );
        }

        if ( $method === 'POST' || $method === 'PUT' || $method === 'DELETE' ) {
            return current_user_can( 'manage_options' );
        }

        return is_user_logged_in();
    }

    public function permission_view_students( $request ): bool {
        return $this->gate( Capabilities::VIEW_STUDENTS, $request );
    }

    public function permission_manage_students( $request ): bool {
        return $this->gate( Capabilities::MANAGE_STUDENTS, $request );
    }

    public function permission_view_teachers( $request ): bool {
        return $this->gate( Capabilities::VIEW_STAFF, $request );
    }

    public function permission_manage_teachers( $request ): bool {
        return $this->gate( Capabilities::MANAGE_STAFF, $request );
    }

    public function permission_view_results( $request ): bool {
        return $this->gate( Capabilities::VIEW_RESULTS, $request );
    }

    public function permission_manage_results( $request ): bool {
        return $this->gate( Capabilities::COMPILE_RESULTS, $request );
    }

    public function permission_manage_exams( $request ): bool {
        return $this->gate( Capabilities::MANAGE_PAPERS, $request );
    }

    public function permission_manage_options( $request ): bool {
        return current_user_can( 'manage_options' );
    }

    public function permission_parent_portal( $request ): bool {
        return $this->gate( Capabilities::GUARDIAN_PORTAL, $request );
    }

    public function permission_student_portal( $request ): bool {
        return $this->gate( Capabilities::STUDENT_PORTAL, $request );
    }

    public function get_schools( $request ): array {
        $svc = new SchoolService();
        $schools = $svc->list_schools();

        return [
            'success' => true,
            'data'    => $schools,
        ];
    }

    public function get_teachers( $request ): array {
        $svc = new TeacherService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $teachers = $svc->list_teachers( $school_id );

        return [
            'success' => true,
            'data'    => $teachers,
        ];
    }

    public function create_teacher( $request ): array {
        $svc = new TeacherService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        if ( empty( $params['full_name'] ) ) {
            return [ 'success' => false, 'message' => 'full_name_required' ];
        }

        $id = $svc->create_teacher( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function bulk_create_teachers( $request ): array {
        $svc = new TeacherService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $teachers = isset( $params['teachers'] ) && is_array( $params['teachers'] ) ? $params['teachers'] : [];

        if ( empty( $teachers ) ) {
            return [ 'success' => false, 'message' => 'teachers_required' ];
        }

        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ( $teachers as $index => $teacher_data ) {
            if ( ! is_array( $teacher_data ) || empty( $teacher_data['full_name'] ) ) {
                $failed++;
                $errors[] = [ 'index' => $index, 'message' => 'full_name_required' ];
                continue;
            }

            $id = $svc->create_teacher( $school_id, $teacher_data );
            if ( $id > 0 ) {
                $created++;
                continue;
            }

            $failed++;
            $errors[] = [ 'index' => $index, 'message' => 'create_failed' ];
        }

        return [
            'success'       => $created > 0,
            'created_count' => $created,
            'failed_count'  => $failed,
            'errors'        => $errors,
        ];
    }

    public function get_parent_results( $request ): array {
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $scope_error = $this->enforce_portal_student_scope( $student_id, 'parent_results' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $svc = new ResultService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $results = array_values(
            array_filter(
                $svc->list_results( $school_id ),
                fn( $row ) => absint( $row['student_id'] ?? 0 ) === $student_id
            )
        );

        return [ 'success' => true, 'data' => $results ];
    }

    public function get_parent_transcripts( $request ): array {
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $scope_error = $this->enforce_portal_student_scope( $student_id, 'parent_transcripts' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $svc = new TranscriptService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        // Was fetching every transcript in the school and filtering in PHP, via a
        // method that does not exist. issuance_history() already scopes by student.
        $transcripts = $svc->issuance_history( $school_id, $student_id );

        return [ 'success' => true, 'data' => $transcripts ];
    }

    public function get_parent_reports( $request ): array {
        $results_response = $this->get_parent_results( $request );
        if ( ! ( $results_response['success'] ?? false ) ) {
            return $results_response;
        }

        $results = $results_response['data'];
        $count = count( $results );
        $total_score = 0.0;

        foreach ( $results as $row ) {
            $total_score += floatval( $row['score'] ?? 0 );
        }

        return [
            'success' => true,
            'data'    => [
                'results_count' => $count,
                'average_score' => $count > 0 ? round( $total_score / $count, 2 ) : 0.0,
                'results'       => $results,
            ],
        ];
    }

    public function get_parent_progress( $request ): array {
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'student_id_required' ];
        }

        $scope_error = $this->enforce_portal_student_scope( $student_id, 'parent_progress' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $svc = new PerformanceTrendService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        return [
            'success' => true,
            'data'    => $svc->get_performance_trends(
                $school_id,
                [
                    'student_id'   => $student_id,
                    'subject'      => sanitize_text_field( $params['subject'] ?? '' ),
                    'class'        => sanitize_text_field( $params['class'] ?? '' ),
                    'session_year' => sanitize_text_field( $params['session_year'] ?? '' ),
                ]
            ),
        ];
    }

    public function get_notifications( $request ): array {
        $svc = new NotificationService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $recipient_id = absint( $params['recipient_id'] ?? 0 );

        if ( ! current_user_can( 'manage_options' ) ) {
            $current_user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
            if ( $recipient_id <= 0 ) {
                $recipient_id = $current_user_id;
            }

            $recipient_scope_error = $this->enforce_notification_recipient_scope( $recipient_id );
            if ( is_array( $recipient_scope_error ) ) {
                return $recipient_scope_error;
            }
        }

        // NotificationService exposes inbox()/unread_count(), never list_for_recipient()
        // or list_notifications(). Calling those was an outright fatal on this route.
        // The inbox is per-recipient by design, so a school-wide listing is not
        // something the service can answer — say so rather than crash.
        if ( $recipient_id <= 0 ) {
            return [ 'success' => false, 'message' => 'recipient_id_required' ];
        }

        $data = $svc->inbox( $school_id, $recipient_id );

        return [ 'success' => true, 'data' => $data ];
    }

    public function create_notification( $request ): array {
        $svc = new NotificationService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $recipient_id = absint( $params['recipient_id'] ?? $params['user_id'] ?? 0 );
        $title        = sanitize_text_field( (string) ( $params['title'] ?? '' ) );

        if ( $recipient_id <= 0 || $title === '' ) {
            return [ 'success' => false, 'message' => 'recipient_id_and_title_required' ];
        }

        $recipient_scope_error = $this->enforce_notification_recipient_scope( $recipient_id );
        if ( is_array( $recipient_scope_error ) ) {
            return $recipient_scope_error;
        }

        $id = $svc->notify(
            $school_id,
            $recipient_id,
            sanitize_key( (string) ( $params['type'] ?? 'general' ) ),
            $title,
            wp_kses_post( (string) ( $params['body'] ?? '' ) ),
            esc_url_raw( (string) ( $params['link'] ?? '' ) )
        );

        return [ 'success' => $id > 0, 'id' => $id ];
    }

    public function get_notifications_unread_count( $request ): array {
        $svc = new NotificationService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $recipient_id = absint( $params['recipient_id'] ?? 0 );

        if ( $recipient_id <= 0 ) {
            return [ 'success' => false, 'message' => 'recipient_id_required' ];
        }

        $recipient_scope_error = $this->enforce_notification_recipient_scope( $recipient_id );
        if ( is_array( $recipient_scope_error ) ) {
            return $recipient_scope_error;
        }

        return [ 'success' => true, 'count' => $svc->unread_count( $school_id, $recipient_id ) ];
    }

    public function mark_notification_read( $request ): array {
        $svc = new NotificationService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $notification_id = absint( $params['notification_id'] ?? 0 );
        $recipient_id    = absint( $params['recipient_id'] ?? 0 );

        if ( $notification_id <= 0 ) {
            return [ 'success' => false, 'message' => 'notification_id_required' ];
        }

        if ( $recipient_id <= 0 ) {
            return [ 'success' => false, 'message' => 'recipient_id_required' ];
        }

        $recipient_scope_error = $this->enforce_notification_recipient_scope( $recipient_id );
        if ( is_array( $recipient_scope_error ) ) {
            return $recipient_scope_error;
        }

        return $svc->mark_read( $school_id, $notification_id, $recipient_id );
    }

    public function mark_all_notifications_read( $request ): array {
        $svc = new NotificationService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $recipient_id = absint( $params['recipient_id'] ?? 0 );

        if ( $recipient_id <= 0 ) {
            return [ 'success' => false, 'message' => 'recipient_id_required' ];
        }

        $recipient_scope_error = $this->enforce_notification_recipient_scope( $recipient_id );
        if ( is_array( $recipient_scope_error ) ) {
            return $recipient_scope_error;
        }

        return $svc->mark_all_read( $school_id, $recipient_id );
    }

    public function get_student_exams( $request ): array {
        $svc = new ExamService();
        $attempt_service = new ExamAttemptService();
        $timetable_service = new ExamTimetableService();
        $student_repository = new StudentRepository();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $exams = $svc->list_exams( $school_id );

        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];
        $student_id = absint( $params['student_id'] ?? 0 );
        $student = null;

        if ( $student_id > 0 ) {
            $student = $student_repository->get_student_by_id( $school_id, $student_id );
        }

        if ( ! is_array( $student ) ) {
            $current_user_id = get_current_user_id();
            if ( $current_user_id > 0 ) {
                $student = $student_repository->find_student_by_wp_user_id( $current_user_id );
            }
        }

        if ( ! is_array( $student ) ) {
            return [ 'success' => true, 'data' => [] ];
        }

        // Keep student-facing list focused on published exams when the field is available.
        $published_exams = array_values(
            array_filter(
                $exams,
                static function ( $exam ) {
                    if ( ! is_array( $exam ) ) {
                        return false;
                    }

                    if ( ! array_key_exists( 'is_published', $exam ) ) {
                        return true;
                    }

                    return (bool) $exam['is_published'];
                }
            )
        );

        $filtered = $timetable_service->filter_exams_for_student( $published_exams, $school_id, $student );

        $student_record_id = absint( $student['id'] ?? 0 );
        foreach ( $filtered as &$exam ) {
            if ( ! is_array( $exam ) ) {
                continue;
            }

            $exam_id = absint( $exam['id'] ?? 0 );
            $active_attempt = $attempt_service->get_active_attempt( $school_id, $exam_id, $student_record_id );

            $exam['has_active_attempt'] = is_array( $active_attempt );
            $exam['active_attempt_id'] = absint( $active_attempt['id'] ?? 0 );
            $exam['active_attempt_status'] = sanitize_text_field( (string) ( $active_attempt['status'] ?? '' ) );
            $exam['active_attempt_timer_seconds_remaining'] = ( new ExamAttemptService() )->get_remaining_seconds(
                $school_id,
                absint( $active_attempt['id'] ?? 0 )
            );

            // Flatten timetable fields into the exam object for the frontend JS
            // (the theme reads exam.term, exam.session_year, exam.subject directly)
            if ( isset( $exam['timetable'] ) && is_array( $exam['timetable'] ) ) {
                $tt = $exam['timetable'];
                if ( ! isset( $exam['term'] ) ) {
                    $exam['term'] = sanitize_text_field( (string) ( $tt['term'] ?? '' ) );
                }
                if ( ! isset( $exam['session_year'] ) ) {
                    $exam['session_year'] = sanitize_text_field( (string) ( $tt['session_year'] ?? '' ) );
                }
                if ( ! isset( $exam['subject'] ) ) {
                    $exam['subject'] = sanitize_text_field( (string) ( $tt['subject'] ?? '' ) );
                }
                if ( ! isset( $exam['start_time'] ) || $exam['start_time'] === '' ) {
                    $exam['start_time'] = sanitize_text_field( (string) ( $tt['start_time'] ?? '' ) );
                }
            }
        }
        unset( $exam );

        return [ 'success' => true, 'data' => $filtered ];
    }

    public function get_student_results( $request ): array {
        return $this->get_parent_results( $request );
    }

    public function get_student_transcripts( $request ): array {
        return $this->get_parent_transcripts( $request );
    }

    public function get_student_reports( $request ): array {
        return $this->get_parent_reports( $request );
    }

    public function get_student_progress( $request ): array {
        return $this->get_parent_progress( $request );
    }

    public function start_student_exam( $request ): array {
        $svc = new ExamAttemptService();
        $timetable_service = new ExamTimetableService();
        $student_repository = new StudentRepository();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $exam_id = absint( $params['exam_id'] ?? 0 );
        $student_id = absint( $params['student_id'] ?? 0 );

        if ( $exam_id <= 0 || $student_id <= 0 ) {
            return [ 'success' => false, 'message' => 'exam_id_and_student_id_required' ];
        }

        $scope_error = $this->enforce_portal_student_scope( $student_id, 'start_student_exam' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $timetable = $timetable_service->get_exam_timetable( $school_id, $exam_id );
        if ( ! is_array( $timetable ) ) {
            return [ 'success' => false, 'message' => 'timetable_not_configured' ];
        }

        $student = $student_repository->get_student_by_id( $school_id, $student_id );
        if ( ! is_array( $student ) ) {
            return [ 'success' => false, 'message' => 'student_not_found' ];
        }

        $exam_date = sanitize_text_field( (string) ( $timetable['exam_date'] ?? '' ) );
        if ( $exam_date !== '' ) {
            $scheduled_subjects = $timetable_service->count_scheduled_subjects_for_student_on_date( $school_id, $student, $exam_date );
            $daily_limit = 3;
            if ( $scheduled_subjects > $daily_limit ) {
                return [
                    'success' => false,
                    'message' => 'daily_scheduled_subject_limit_exceeded',
                    'limit' => $daily_limit,
                    'scheduled_subjects' => $scheduled_subjects,
                    'exam_date' => $exam_date,
                ];
            }
        }

        if ( is_array( $timetable ) ) {
            $is_trial = ! empty( $timetable['is_trial_mode'] );
            if ( ! $is_trial && ! $this->is_timetable_active_now( $timetable ) ) {
                return [ 'success' => false, 'message' => 'exam_not_active' ];
            }
        }

        $attempt_id = $svc->create_attempt( $school_id, $exam_id, $student_id, [] );

        if ( $attempt_id <= 0 && $svc->get_active_attempt( $school_id, $exam_id, $student_id ) ) {
            $active = $svc->get_active_attempt( $school_id, $exam_id, $student_id );
            return [
                'success' => false,
                'message' => 'duplicate_active_attempt',
                'attempt_id' => absint( $active['id'] ?? 0 ),
                'resume' => true,
            ];
        }

        return [
            'success'    => $attempt_id > 0,
            'attempt_id' => $attempt_id,
        ];
    }

    public function get_student_exam_session( $request ): array {
        $svc = new ExamAttemptService();
        $exam_service = new ExamService();
        $timetable_service = new ExamTimetableService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : [];

        $attempt_id = absint( $params['attempt_id'] ?? 0 );
        if ( $attempt_id <= 0 ) {
            return [ 'success' => false, 'message' => 'attempt_id_required' ];
        }

        $attempt = $svc->get_attempt_by_id( $school_id, $attempt_id );
        if ( ! is_array( $attempt ) ) {
            return [ 'success' => false, 'message' => 'attempt_not_found' ];
        }

        $student_id = absint( $attempt['student_id'] ?? 0 );
        $scope_error = $this->enforce_portal_student_scope( $student_id, 'get_student_exam_session' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $exam_id = absint( $attempt['exam_id'] ?? 0 );
        $exam = $exam_service->get_exam( $school_id, $exam_id );
        $timetable = $timetable_service->get_exam_timetable( $school_id, $exam_id );
        $is_trial_mode = is_array( $timetable ) && ! empty( $timetable['is_trial_mode'] );

        $questions = $svc->get_attempt_questions( $school_id, $attempt_id, $is_trial_mode );
        $draft = $this->get_attempt_draft( $school_id, $attempt_id );

        // Fetch student profile for the candidate card in the exam sidebar.
        global $wpdb;
        $student_table = $wpdb->prefix . 'educbt_students';
        $student_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT full_name, gender, class, session_year FROM {$student_table} WHERE id = %d LIMIT 1",
                $student_id
            ),
            ARRAY_A
        );

        $student_profile = [
            'full_name' => sanitize_text_field( (string) ( $student_row['full_name'] ?? '' ) ),
            'gender'    => sanitize_text_field( (string) ( $student_row['gender'] ?? '' ) ),
            'class'     => sanitize_text_field( (string) ( $student_row['class'] ?? '' ) ),
            'session'   => sanitize_text_field( (string) ( $student_row['session_year'] ?? '' ) ),
        ];

        // Exam metadata from the timetable (subject, term, session).
        $exam_meta = [
            'subject' => '',
            'term'    => '',
            'session' => '',
        ];
        if ( is_array( $timetable ) ) {
            $exam_meta['subject'] = sanitize_text_field( (string) ( $timetable['subject'] ?? '' ) );
            $exam_meta['term']    = sanitize_text_field( (string) ( $timetable['term'] ?? '' ) );
            $exam_meta['session'] = sanitize_text_field( (string) ( $timetable['session_year'] ?? '' ) );
        }

        return [
            'success' => true,
            'data' => [
                'attempt' => [
                    'id' => $attempt_id,
                    'exam_id' => $exam_id,
                    'student_id' => $student_id,
                    'status' => sanitize_text_field( (string) ( $attempt['status'] ?? '' ) ),
                    'timer' => $svc->get_timer_payload( $school_id, $attempt_id ),
                    'timer_seconds_remaining' => $svc->get_remaining_seconds( $school_id, $attempt_id ),
                    'session_key' => sanitize_text_field( (string) ( $attempt['session_key'] ?? '' ) ),
                ],
                'exam' => [
                    'id' => $exam_id,
                    'title' => sanitize_text_field( (string) ( $exam['title'] ?? '' ) ),
                    'duration_minutes' => absint( $exam['duration_minutes'] ?? 0 ),
                ],
                'student_profile' => $student_profile,
                'exam_meta' => $exam_meta,
                'is_trial_mode' => $is_trial_mode,
                'questions' => $questions,
                'draft' => $draft,
            ],
        ];
    }

    public function autosave_student_exam_session( $request ): array {
        $svc = new ExamAttemptService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $attempt_id = absint( $params['attempt_id'] ?? 0 );
        if ( $attempt_id <= 0 ) {
            return [ 'success' => false, 'message' => 'attempt_id_required' ];
        }

        $attempt = $svc->get_attempt_by_id( $school_id, $attempt_id );
        if ( ! is_array( $attempt ) ) {
            return [ 'success' => false, 'message' => 'attempt_not_found' ];
        }

        $student_id = absint( $attempt['student_id'] ?? 0 );
        $scope_error = $this->enforce_portal_student_scope( $student_id, 'autosave_student_exam_session' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $responses = isset( $params['responses'] ) && is_array( $params['responses'] ) ? $params['responses'] : [];
        $answer_timestamps = isset( $params['answer_timestamps'] ) && is_array( $params['answer_timestamps'] )
            ? $params['answer_timestamps']
            : [];
        $current_index = absint( $params['current_index'] ?? 0 );

        // PHASE 0 SECURITY FIX: any `timer_seconds_remaining` sent by the browser is
        // discarded. Remaining time is derived server-side from time_started.
        unset( $params['timer_seconds_remaining'] );

        $saved_at = current_time( 'mysql' );

        $this->set_attempt_draft(
            $school_id,
            $attempt_id,
            [
                'responses' => $responses,
                'answer_timestamps' => $answer_timestamps,
                'current_index' => $current_index,
                'saved_at' => $saved_at,
            ]
        );

        $timer = $svc->heartbeat( $school_id, $attempt_id );

        return [
            'success'  => true,
            'saved_at' => $saved_at,
            'timer'    => $timer,
        ];
    }

    public function submit_student_exam( $request ): array {
        $attempt_service = new ExamAttemptService();
        $result_service = new ResultService();
        $timetable_service = new ExamTimetableService();

        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        $attempt_id = absint( $params['attempt_id'] ?? 0 );
        if ( $attempt_id <= 0 ) {
            return [ 'success' => false, 'message' => 'attempt_id_required' ];
        }

        $attempt = $attempt_service->get_attempt_by_id( $school_id, $attempt_id );
        if ( ! is_array( $attempt ) ) {
            return [ 'success' => false, 'message' => 'attempt_not_found' ];
        }

        $student_id = absint( $attempt['student_id'] ?? 0 );
        $scope_error = $this->enforce_portal_student_scope( $student_id, 'submit_student_exam' );
        if ( is_array( $scope_error ) ) {
            return $scope_error;
        }

        $responses = isset( $params['responses'] ) && is_array( $params['responses'] ) ? $params['responses'] : [];

        $result = $result_service->submit_exam( $school_id, $attempt_id, $responses );
        if ( empty( $result['success'] ) ) {
            return $result;
        }

        $this->clear_attempt_draft( $school_id, $attempt_id );

        $exam_id = absint( $attempt['exam_id'] ?? 0 );
        $timetable = $timetable_service->get_exam_timetable( $school_id, $exam_id );
        $is_trial_mode = is_array( $timetable ) && ! empty( $timetable['is_trial_mode'] );

        // RESULTS ARE NOT RELEASED AT SUBMISSION.
        //
        // A real examination follows the JAMB model: the candidate submits, and the
        // school decides when results are seen. Returning a score here handed every
        // student their mark the instant they finished — before the exam officer had
        // even looked at the paper, and before any moderation or cancellation.
        //
        // Trial mode is the deliberate exception: it exists to teach, so an immediate
        // score and a worked review are the entire point of it.
        $response_payload = [
            'success'       => true,
            'result_id'     => absint( $result['result_id'] ?? 0 ),
            'is_trial_mode' => $is_trial_mode,
        ];

        if ( $is_trial_mode ) {
            $response_payload['score'] = floatval( $result['score'] ?? 0 );
            $response_payload['grade'] = sanitize_text_field( (string) ( $result['grade'] ?? '' ) );
        } else {
            $response_payload['message'] = __( 'Your answers have been submitted. Your result will be released by the school.', 'educbt-pro' );
        }

        if ( $is_trial_mode ) {
            $questions = $attempt_service->get_attempt_questions( $school_id, $attempt_id, true );
            $review = [];

            foreach ( $questions as $question ) {
                if ( ! is_array( $question ) ) {
                    continue;
                }

                $question_id = absint( $question['id'] ?? 0 );
                $selected = sanitize_text_field( (string) ( $responses[ (string) $question_id ] ?? $responses[ $question_id ] ?? '' ) );
                $correct_answers = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $question['answers'] ?? [] ) ) ) );
                $is_correct = $selected !== '' && in_array( $selected, $correct_answers, true );

                $review[] = [
                    'question_id' => $question_id,
                    'question_text' => sanitize_text_field( (string) ( $question['question_text'] ?? '' ) ),
                    'selected_answer' => $selected,
                    'correct_answers' => $correct_answers,
                    'is_correct' => $is_correct,
                    'explanation' => sanitize_textarea_field( (string) ( $question['explanations'] ?? '' ) ),
                ];
            }

            $response_payload['review'] = $review;
        }

        return $response_payload;
    }

    private function get_attempt_draft( int $school_id, int $attempt_id ): array {
        $key = $this->attempt_draft_transient_key( $school_id, $attempt_id );
        $draft = get_transient( $key );

        return is_array( $draft ) ? $draft : [];
    }

    private function set_attempt_draft( int $school_id, int $attempt_id, array $draft ): void {
        $key = $this->attempt_draft_transient_key( $school_id, $attempt_id );
        set_transient( $key, $draft, 3 * DAY_IN_SECONDS );
    }

    private function clear_attempt_draft( int $school_id, int $attempt_id ): void {
        $key = $this->attempt_draft_transient_key( $school_id, $attempt_id );
        delete_transient( $key );
    }

    private function attempt_draft_transient_key( int $school_id, int $attempt_id ): string {
        return 'educbt_attempt_draft_' . $school_id . '_' . $attempt_id;
    }

    private function is_timetable_active_now( array $timetable ): bool {
        $exam_date = trim( (string) ( $timetable['exam_date'] ?? '' ) );
        $start_time = trim( (string) ( $timetable['start_time'] ?? '' ) );
        $end_time = trim( (string) ( $timetable['end_time'] ?? '' ) );

        if ( $exam_date === '' || $start_time === '' || $end_time === '' ) {
            return false;
        }

        $start_ts = strtotime( $exam_date . ' ' . $start_time );
        $end_ts = strtotime( $exam_date . ' ' . $end_time );
        if ( ! $start_ts || ! $end_ts ) {
            return false;
        }

        $now = current_time( 'timestamp' );
        return $now >= $start_ts && $now <= $end_ts;
    }

    private function user_has_any_capability( array $capabilities ): bool {
        foreach ( $capabilities as $capability ) {
            if ( current_user_can( $capability ) ) {
                return true;
            }
        }

        return false;
    }

    private function resolve_route_capability( string $method, string $route ): string {
        $route = $this->normalize_route( $route );

        if ( str_starts_with( $route, '/parent/' ) ) {
            return 'educbt_parent_portal';
        }

        if ( str_starts_with( $route, '/student/' ) ) {
            return 'educbt_student_portal';
        }

        if ( $method === 'GET' ) {
            if ( str_starts_with( $route, '/students' ) ) {
                return 'educbt_view_students';
            }
            if ( str_starts_with( $route, '/teachers' ) ) {
                return 'educbt_view_teachers';
            }
            if ( str_starts_with( $route, '/results' ) || str_starts_with( $route, '/transcripts' ) || str_starts_with( $route, '/exam-results' ) ) {
                return 'educbt_view_results';
            }
            if ( str_starts_with( $route, '/exam' ) || str_starts_with( $route, '/questions' ) ) {
                return 'educbt_manage_exams';
            }
            if ( str_starts_with( $route, '/audit-logs' ) || str_starts_with( $route, '/notifications' ) || str_starts_with( $route, '/privacy' ) ) {
                return 'manage_options';
            }

            return 'read';
        }

        if ( str_starts_with( $route, '/students' ) ) {
            return 'educbt_manage_students';
        }
        if ( str_starts_with( $route, '/teachers' ) ) {
            return 'educbt_manage_teachers';
        }
        if ( str_starts_with( $route, '/results' ) || str_starts_with( $route, '/result-approval' ) ) {
            return 'educbt_manage_results';
        }
        if ( str_starts_with( $route, '/exam-integrity-events' ) ) {
            return 'educbt_student_portal';
        }
        if ( str_starts_with( $route, '/exam' ) || str_starts_with( $route, '/questions' ) ) {
            return 'educbt_manage_exams';
        }

        return 'manage_options';
    }

    private function enforce_portal_student_scope( int $student_id, string $context ): ?array {
        if ( current_user_can( 'manage_options' ) ) {
            return null;
        }

        $has_portal_capability = current_user_can( 'educbt_student_portal' ) || current_user_can( 'educbt_parent_portal' );
        if ( ! $has_portal_capability ) {
            return null;
        }

        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $current_user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;

        $allowed_student_ids = apply_filters(
            'educbt_portal_allowed_student_ids',
            [],
            $current_user_id,
            $school_id,
            $context
        );

        if ( ! is_array( $allowed_student_ids ) ) {
            $allowed_student_ids = [];
        }

        $allowed_student_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', $allowed_student_ids ),
                    static fn( int $id ) => $id > 0
                )
            )
        );

        if ( empty( $allowed_student_ids ) && current_user_can( 'educbt_student_portal' ) && ! current_user_can( 'educbt_parent_portal' ) && $current_user_id > 0 ) {
            // Look up the student record ID from the WP user ID (they are different IDs)
            $student_repo = new StudentRepository();
            $student_record = $student_repo->find_student_by_wp_user_id( $current_user_id );
            if ( is_array( $student_record ) && ! empty( $student_record['id'] ) ) {
                $allowed_student_ids = [ absint( $student_record['id'] ) ];
            }
        }

        if ( empty( $allowed_student_ids ) ) {
            return [ 'success' => false, 'message' => 'no_portal_student_scope' ];
        }

        if ( ! in_array( $student_id, $allowed_student_ids, true ) ) {
            return [ 'success' => false, 'message' => 'unauthorized_student_scope' ];
        }

        return null;
    }

    private function enforce_notification_recipient_scope( int $recipient_id ): ?array {
        if ( current_user_can( 'manage_options' ) ) {
            return null;
        }

        $current_user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        if ( $current_user_id <= 0 ) {
            return null;
        }

        if ( $recipient_id <= 0 ) {
            return [ 'success' => false, 'message' => 'recipient_id_required' ];
        }

        if ( $recipient_id !== $current_user_id ) {
            return [ 'success' => false, 'message' => 'unauthorized_recipient_scope' ];
        }

        return null;
    }

    private function enforce_privacy_student_scope( int $student_id ): ?array {
        if ( current_user_can( 'manage_options' ) ) {
            return null;
        }

        $current_user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        if ( $current_user_id <= 0 ) {
            return [ 'success' => false, 'message' => 'unauthorized_student_scope' ];
        }

        if ( $student_id !== $current_user_id ) {
            return [ 'success' => false, 'message' => 'unauthorized_student_scope' ];
        }

        return null;
    }

    private function normalize_route( string $route ): string {
        $route = trim( $route );

        if ( str_starts_with( $route, '/educbt-pro/v1' ) ) {
            $route = substr( $route, strlen( '/educbt-pro/v1' ) );
        }

        if ( $route === '' ) {
            return '/';
        }

        return str_starts_with( $route, '/' ) ? $route : '/' . $route;
    }

    public function get_students( $request ): array {
        $svc = new StudentService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $students = $svc->list_students( $school_id );

        foreach ( $students as &$student ) {
            if ( ! is_array( $student ) ) {
                continue;
            }

            $student['registration_number'] = sanitize_text_field( (string) ( $student['admission_number'] ?? '' ) );
        }
        unset( $student );

        return [
            'success' => true,
            'data'    => $students,
        ];
    }

    public function get_subjects( $request ): array {
        $svc = new SubjectService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $subjects = $svc->list_subjects( $school_id );

        return [
            'success' => true,
            'data'    => $subjects,
        ];
    }

    public function get_classes( $request ): array {
        $svc = new ClassService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $classes = $svc->list_classes( $school_id );

        return [
            'success' => true,
            'data'    => $classes,
        ];
    }

    public function create_class( $request ): array {
        $svc = new ClassService();
        $params = $request->get_json_params();
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        if ( empty( $params['class_name'] ) ) {
            return [ 'success' => false, 'message' => 'class_name_required' ];
        }

        $id = $svc->create_class( $school_id, $params );
        if ( ! $id ) {
            return [ 'success' => false, 'message' => 'class_already_exists' ];
        }

        return [ 'success' => true, 'id' => $id ];
    }

    public function get_questions( $request ): array {
        $svc = new QuestionService();
        $school_id = $this->tenant_context->get_school_id() ?? 0;
        $questions = $svc->list_questions( $school_id );

        return [
            'success' => true,
            'data'    => $questions,
        ];
    }

    public function create_school( $request ): array {
        $svc    = new SchoolService();
        $params = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];

        if ( empty( $params['school_name'] ) ) {
            return [ 'success' => false, 'message' => 'school_name_required' ];
        }

        $id = $svc->create_school( $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function create_subject( $request ): array {
        $svc       = new SubjectService();
        $params    = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        if ( empty( $params['subject_name'] ) ) {
            return [ 'success' => false, 'message' => 'subject_name_required' ];
        }

        $id = $svc->create_subject( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    public function create_question( $request ): array {
        $svc       = new QuestionService();
        $params    = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        if ( empty( $params['question_text'] ) ) {
            return [ 'success' => false, 'message' => 'question_text_required' ];
        }

        $id = $svc->create_question( $school_id, $params );
        return [ 'success' => (bool) $id, 'id' => $id ];
    }

    /**
     * Save a question from the in-page authoring form.
     *
     * Accepts the exact field names the JS sends: subject_id, class_level,
     * question_type, marks, question_text, option_A..D, correct (letter),
     * marking_guide, passage_id, estimated_duration, question_image.
     *
     * Returns JSON: { success, message, question_id, total, next_number }.
     */
    public function save_question_bank( $request ): array {
        $params    = method_exists( $request, 'get_json_params' ) ? (array) $request->get_json_params() : [];
        $school_id = $this->tenant_context->get_school_id() ?? 0;

        if ( empty( $params['question_text'] ) ) {
            return [ 'success' => false, 'message' => 'Please type the question first.' ];
        }

        $subject_id  = absint( $params['subject_id'] ?? 0 );
        $class_level = sanitize_text_field( (string) ( $params['class_level'] ?? '' ) );
        $qtype       = sanitize_key( (string) ( $params['question_type'] ?? 'single_choice' ) );
        $marks       = (float) ( $params['marks'] ?? 1 );
        $passage_id  = absint( $params['passage_id'] ?? 0 );

        if ( $subject_id <= 0 || $class_level === '' ) {
            return [ 'success' => false, 'message' => 'Choose the subject and class first.' ];
        }

        // Gate: block saves when exam prep is closed (but allow school-wide reviewers).
        $school_svc = new SchoolService();
        if ( ! $school_svc->is_exam_prep_enabled( $school_id ) ) {
            $scope = new \EduCBTPro\Core\Scope();
            if ( ! $scope->is_school_wide() ) {
                return [ 'success' => false, 'message' => 'Exam preparation is currently closed. New questions cannot be submitted.' ];
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_questions';

        // Look up the subject name for storage.
        $subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
        $subject_name   = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM {$subjects_table} WHERE id = %d LIMIT 1", $subject_id )
        );

        // Determine the initial approval status.
        $scope = new \EduCBTPro\Core\Scope();
        $approval = $scope->is_school_wide() ? 'approved' : 'pending';

        if ( $qtype === 'theory' ) {
            // Written question — no options, has a marking guide.
            $wpdb->insert(
                $table,
                [
                    'school_id'          => $school_id,
                    'subject_id'         => $subject_id,
                    'subject'            => $subject_name,
                    'question_type'      => 'theory',
                    'class_level'        => $class_level,
                    'question_text'      => wp_kses_post( (string) ( $params['question_text'] ?? '' ) ),
                    'image_reference'    => esc_url_raw( (string) ( $params['question_image'] ?? '' ) ),
                    'marks'              => $marks,
                    'explanations'       => wp_kses_post( (string) ( $params['marking_guide'] ?? '' ) ),
                    'passage_id'         => $passage_id ?: null,
                    'estimated_duration' => absint( $params['estimated_duration'] ?? 0 ),
                    'status'             => 'active',
                    'approval_status'    => $approval,
                    'created_by_staff'   => (int) ( $scope->actor()['id'] ?? 0 ),
                    'created_at'         => current_time( 'mysql', true ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%d', '%s' ]
            );
        } else {
            // Objective question — build options from option_A..D + correct letter.
            $options = [];
            $answers = [];
            $correct = sanitize_key( (string) ( $params['correct'] ?? '' ) );

            foreach ( [ 'A', 'B', 'C', 'D' ] as $key ) {
                $text = trim( (string) ( $params[ 'option_' . $key ] ?? '' ) );
                if ( $text !== '' ) {
                    $options[ $key ] = $text;
                    if ( $key === $correct ) {
                        $answers[] = $text;
                    }
                }
            }

            if ( count( $options ) < 2 ) {
                return [ 'success' => false, 'message' => 'An objective question needs at least two options.' ];
            }

            if ( empty( $answers ) ) {
                return [ 'success' => false, 'message' => 'Mark the correct option before saving.' ];
            }

            $wpdb->insert(
                $table,
                [
                    'school_id'          => $school_id,
                    'subject_id'         => $subject_id,
                    'subject'            => $subject_name,
                    'question_type'      => 'single_choice',
                    'class_level'        => $class_level,
                    'question_text'      => wp_kses_post( (string) ( $params['question_text'] ?? '' ) ),
                    'image_reference'    => esc_url_raw( (string) ( $params['question_image'] ?? '' ) ),
                    'marks'              => $marks,
                    'options'            => wp_json_encode( array_values( $options ) ),
                    'answers'            => wp_json_encode( $answers ),
                    'passage_id'         => $passage_id ?: null,
                    'estimated_duration' => absint( $params['estimated_duration'] ?? 0 ),
                    'status'             => 'active',
                    'approval_status'    => $approval,
                    'created_by_staff'   => (int) ( $scope->actor()['id'] ?? 0 ),
                    'created_at'         => current_time( 'mysql', true ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s' ]
            );

            // Save individual options to the options table.
            $options_table = \EduCBTPro\Core\Schema::table( 'question_options' );
            $qid = (int) $wpdb->insert_id;
            if ( $qid > 0 ) {
                $wpdb->delete( $options_table, [ 'question_id' => $qid ], [ '%d' ] );
                $sort = 0;
                foreach ( $options as $key => $text ) {
                    $wpdb->insert(
                        $options_table,
                        [
                            'question_id' => $qid,
                            'option_key'  => $key,
                            'option_text' => $text,
                            'is_correct'  => ( $key === $correct ) ? 1 : 0,
                            'sort_order'  => $sort++,
                        ],
                        [ '%d', '%s', '%s', '%d', '%d' ]
                    );
                }
            }
        }

        $question_id = (int) $wpdb->insert_id;

        if ( $question_id <= 0 ) {
            return [ 'success' => false, 'message' => 'Could not save the question. The database rejected it.' ];
        }

        // Count the teacher's questions in this subject+class for the running tally.
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE school_id = %d AND subject_id = %d AND class_level = %s AND status = 'active'",
                $school_id, $subject_id, $class_level
            )
        );

        return [
            'success'     => true,
            'message'     => 'Saved.',
            'question_id' => $question_id,
            'total'       => $total,
            'next_number' => 'Question ' . ( $total + 1 ),
        ];
    }

    public function sanitize_string( $value ) {
        return sanitize_text_field( wp_unslash( $value ) );
    }

    public function sanitize_integer( $value ) {
        return absint( $value );
    }

    public function sanitize_boolean( $value ) {
        return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }

    public function sanitize_number( $value ) {
        return is_numeric( $value ) ? floatval( $value ) : 0;
    }

    public function validate_non_empty_string( $value, $request, $param ) {
        return is_string( $value ) && trim( $value ) !== '';
    }

    public function validate_positive_integer( $value, $request, $param ) {
        return is_numeric( $value ) && intval( $value ) > 0;
    }

    public function validate_boolean_value( $value, $request, $param ) {
        if ( is_bool( $value ) ) {
            return true;
        }

        return in_array( $value, [ 0, 1, '0', '1', 'true', 'false', 'TRUE', 'FALSE' ], true );
    }

    public function validate_numeric( $value, $request, $param ) {
        return is_numeric( $value );
    }

    public function validate_non_empty_array( $value, $request, $param ) {
        return is_array( $value ) && ! empty( $value );
    }
}
