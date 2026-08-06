<?php

namespace EduCBTPro\Api;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Scope;
use EduCBTPro\Core\TenantContext;
use EduCBTPro\Services\QuestionApprovalService;
use EduCBTPro\Services\QuestionAuthoringService;
use EduCBTPro\Services\QuestionBankService;
use EduCBTPro\Services\QuestionSetService;
use EduCBTPro\Services\AcademicYearService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Saving questions without losing the page.
 *
 * Writing forty questions meant forty full page loads, each one throwing away the
 * subject, the class and the passage the teacher had already chosen. That is the
 * single thing that made the question bank tiring to use, so saving happens here
 * and the form stays where it is.
 */
class QuestionController {

    public const NAMESPACE = 'educbt/v1';

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/questions',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        // Question Set routes — the new spec-compliant question bank.
        register_rest_route(
            self::NAMESPACE,
            '/question-sets',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_set' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::VIEW_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_or_load_set' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/questions',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'add_question' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/questions/(?P<question_id>\d+)',
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_question' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/questions/(?P<question_id>\d+)',
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_question' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/questions/(?P<question_id>\d+)/duplicate',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'duplicate_question' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/reorder',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'reorder' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/submit',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'submit_set' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)/withdraw',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'withdraw_set' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/question-sets/(?P<set_id>\d+)',
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_set' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && Gate::allows( Capabilities::WRITE_QUESTIONS ),
            ]
        );
    }

    public function create( $request ) {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $scope     = new Scope();
        $actor     = $scope->actor();

        $subject_id = absint( $request->get_param( 'subject_id' ) );

        if ( ! Gate::allows( Capabilities::WRITE_QUESTIONS, [ 'subject_id' => $subject_id ] ) ) {
            return new \WP_Error( 'educbt_not_your_subject', 'You do not teach that subject.', [ 'status' => 403 ] );
        }

        $type = sanitize_key( (string) $request->get_param( 'question_type' ) );

        // Someone who can approve does not need their own work approving.
        $approval = Gate::allows( Capabilities::APPROVE_QUESTIONS )
            ? QuestionApprovalService::APPROVED
            : QuestionApprovalService::PENDING;

        $common = [
            'school_id'        => $school_id,
            'subject_id'       => $subject_id,
            'class_level'      => sanitize_text_field( (string) $request->get_param( 'class_level' ) ),
            'question_text'    => wp_kses_post( (string) $request->get_param( 'question_text' ) ),
            'image_reference'  => esc_url_raw( (string) $request->get_param( 'question_image' ) ),
            'marks'            => (float) $request->get_param( 'marks' ),
            'passage_id'       => absint( $request->get_param( 'passage_id' ) ) ?: null,
            'status'           => 'active',
            'approval_status'  => $approval,
            'created_by_staff' => absint( $actor['id'] ),
            'created_at'       => current_time( 'mysql', true ),
        ];

        if ( trim( wp_strip_all_tags( (string) $common['question_text'] ) ) === '' && $common['image_reference'] === '' ) {
            return new \WP_Error( 'educbt_empty_question', 'The question needs text or an image.', [ 'status' => 400 ] );
        }

        global $wpdb;

        if ( $type === QuestionBankService::TYPE_THEORY ) {
            $wpdb->insert(
                $wpdb->prefix . 'educbt_questions',
                array_merge(
                    $common,
                    [
                        'question_type' => QuestionBankService::TYPE_THEORY,
                        'explanations'  => wp_kses_post( (string) $request->get_param( 'marking_guide' ) ),
                    ]
                ),
                [ '%d', '%d', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
            );

            return $this->result( $wpdb->insert_id, $approval, $school_id, $subject_id, $common['class_level'] );
        }

        $options = [];

        foreach ( [ 'A', 'B', 'C', 'D', 'E', 'F' ] as $key ) {
            $options[] = [
                'text'  => (string) $request->get_param( 'option_' . $key ),
                'image' => (string) $request->get_param( 'option_image_' . $key ),
            ];
        }

        $authoring = new QuestionAuthoringService();

        $payload = $authoring->normalise_payload(
            [
                'subject_id'     => $subject_id,
                'class_level'    => $common['class_level'],
                'difficulty'     => 'medium',
                'marks'          => $common['marks'],
                'question_text'  => (string) $request->get_param( 'question_text' ),
                'question_image' => (string) $request->get_param( 'question_image' ),
                'passage_id'     => absint( $request->get_param( 'passage_id' ) ),
                'correct'        => (string) $request->get_param( 'correct' ),
                'options'        => $options,
            ]
        );

        $check = $authoring->validate_payload( $payload );

        if ( ! $check['valid'] ) {
            return new \WP_Error(
                'educbt_invalid_question',
                $this->explain( $check['errors'] ),
                [ 'status' => 400 ]
            );
        }

        $created = ( new QuestionBankService() )->create( $school_id, $authoring->payload_to_question( $payload ) );

        if ( empty( $created['success'] ) ) {
            return new \WP_Error( 'educbt_save_failed', $this->explain( $created['errors'] ?? [] ), [ 'status' => 400 ] );
        }

        $question_id = absint( $created['question_id'] );

        $wpdb->update(
            $wpdb->prefix . 'educbt_questions',
            [
                'approval_status'  => $approval,
                'created_by_staff' => absint( $actor['id'] ),
                'created_at'       => current_time( 'mysql', true ),
                'passage_id'       => $common['passage_id'],
            ],
            [ 'id' => $question_id, 'school_id' => $school_id ],
            [ '%s', '%d', '%s', '%d' ],
            [ '%d', '%d' ]
        );

        return $this->result( $question_id, $approval, $school_id, $subject_id, $common['class_level'] );
    }

    /**
     * @return array<string,mixed>
     */
    private function result( int $question_id, string $approval, int $school_id, int $subject_id, string $class_level ): array {
        global $wpdb;

        // The running count, so the form can say "Question 12" without a page load.
        $count = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $wpdb->prefix . "educbt_questions
                     WHERE school_id = %d AND subject_id = %d AND class_level = %s AND status = 'active'",
                    $school_id,
                    $subject_id,
                    $class_level
                )
            )
        );

        return [
            'saved'       => $question_id > 0,
            'question_id' => $question_id,
            'status'      => $approval,
            'total'       => $count,
            'next_number' => $count + 1,
            'message'     => $approval === QuestionApprovalService::APPROVED
                ? 'Saved and approved.'
                : 'Submitted — waiting for approval.',
        ];
    }

    /**
     * @param array<int|string,string> $errors
     */
    private function explain( array $errors ): string {
        $map = [
            'no_correct_answer'                     => 'Mark which option is correct.',
            'question_needs_text_or_image'          => 'The question needs text or an image.',
            'at_least_two_options_required'         => 'Give at least two options.',
            'duplicate_option_text'                 => 'Two options are identical.',
            'correct_answer_marked_on_empty_option' => 'The correct answer is marked on an empty option.',
        ];

        $out = [];

        foreach ( $errors as $code ) {
            $out[] = $map[ (string) $code ] ?? ucfirst( str_replace( '_', ' ', (string) $code ) );
        }

        return implode( ' ', array_unique( $out ) ) ?: 'That question could not be saved.';
    }

    /**
     * GET /question-sets?subject_id=&level_id=&department_id=&exam_type=
     * Load an existing set (or return null) for the given scope.
     */
    public function get_set( $request ) {
        $school_id  = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $scope      = new Scope();
        $actor      = $scope->actor();
        $subject_id = absint( $request->get_param( 'subject_id' ) ?? 0 );
        $exam_type  = sanitize_key( (string) ( $request->get_param( 'exam_type' ) ?? 'objective' ) );

        // A set is identified by level + department now. class_id is still accepted
        // so an older bookmark or a deep link built from a class keeps working — it
        // is resolved to the level it belongs to.
        $level_id      = absint( $request->get_param( 'level_id' ) ?? 0 );
        $department_id = absint( $request->get_param( 'department_id' ) ?? 0 );
        $class_id      = absint( $request->get_param( 'class_id' ) ?? 0 );

        if ( ! $level_id && $class_id ) {
            $resolved      = ( new QuestionSetService() )->scope_for_class( $school_id, $class_id );
            $level_id      = $resolved['level_id'];
            $department_id = $resolved['department_id'];
        }

        if ( ! $subject_id || ! $level_id ) {
            return [ 'success' => false, 'error' => 'missing_params' ];
        }

        $ay_service = new AcademicYearService();
        $session    = $ay_service->current_session( $school_id );
        $session_id = absint( $session['id'] ?? 0 );
        $term       = $ay_service->resolve_current_term( $school_id, $session_id );
        $term_id    = absint( $term['id'] ?? 0 );

        $service = new QuestionSetService();
        $set     = $service->find_set( $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type );

        $questions = [];
        $sibling   = null;

        if ( $set ) {
            $questions = $service->get_questions( absint( $set['id'] ) );
            $sibling   = $service->get_sibling_set( $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type );
            if ( $sibling ) {
                $sibling['question_count'] = $service->question_count( absint( $sibling['id'] ) );
                // Always use the live quota, not the stored snapshot which may be
                // stale from before the quota was corrected.
                $sibling['min_required'] = $service->get_min_required( $school_id, $subject_id, $level_id, $sibling['exam_type'] );
                $set['_sibling'] = $sibling;
            }
            // Also fix the current set's min_required to the live value.
            $set['min_required'] = $service->get_min_required( $school_id, $subject_id, $level_id, $exam_type );
        }

        $quotas = ( new QuestionApprovalService() )->quotas( $school_id );

        return [
            'success'   => true,
            'set'       => $set,
            'questions' => $questions,
            'quotas'    => $quotas,
        ];
    }

    /**
     * POST /question-sets
     * Create a new set (or load existing) for the given scope.
     */
    public function create_or_load_set( $request ) {
        $school_id  = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $scope      = new Scope();
        $actor      = $scope->actor();
        $subject_id = absint( $request->get_param( 'subject_id' ) ?? 0 );
        $exam_type  = sanitize_key( (string) ( $request->get_param( 'exam_type' ) ?? 'objective' ) );
        $marks      = (float) ( $request->get_param( 'default_marks' ) ?? 1 );

        // Same resolution as the GET: level + department identify the set, class_id
        // is accepted and resolved so older links keep working.
        $level_id      = absint( $request->get_param( 'level_id' ) ?? 0 );
        $department_id = absint( $request->get_param( 'department_id' ) ?? 0 );
        $class_id      = absint( $request->get_param( 'class_id' ) ?? 0 );

        if ( ! $level_id && $class_id ) {
            $resolved      = ( new QuestionSetService() )->scope_for_class( $school_id, $class_id );
            $level_id      = $resolved['level_id'];
            $department_id = $resolved['department_id'];
        }

        if ( ! $subject_id || ! $level_id ) {
            return new \WP_Error( 'educbt_missing_params', 'Subject and class level are required.', [ 'status' => 400 ] );
        }

        $ay_service = new AcademicYearService();
        $session    = $ay_service->current_session( $school_id );
        $session_id = absint( $session['id'] ?? 0 );
        $term       = $ay_service->resolve_current_term( $school_id, $session_id );
        $term_id    = absint( $term['id'] ?? 0 );

        // Two different failures, two different instructions. Saying only "save
        // failed" for either is what sent this bug round in circles.
        if ( ! $session_id ) {
            return new \WP_Error(
                'educbt_no_active_session',
                'No academic session is marked as current. Open School → Settings → Sessions and mark one current, then reload this page.',
                [ 'status' => 400 ]
            );
        }

        if ( ! $term_id ) {
            return new \WP_Error(
                'educbt_no_active_term',
                'The current session has no terms yet. Add its terms under School → Settings → Sessions, then reload this page.',
                [ 'status' => 400 ]
            );
        }

        $service = new QuestionSetService();

        // Try to find existing first.
        $set = $service->find_set( $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type );

        if ( ! $set ) {
            $result = $service->create_set( $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type, (int) $actor['id'], $marks );
            if ( empty( $result['id'] ) ) {
                return new \WP_Error( 'educbt_create_failed', 'Could not create question set.', [ 'status' => 400 ] );
            }
            $set = $service->find_set( $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type );
        }

        $questions = $set ? $service->get_questions( absint( $set['id'] ) ) : [];

        return [
            'success'   => true,
            'set'       => $set,
            'questions' => $questions,
        ];
    }

    /**
     * POST /question-sets/{id}/questions
     */
    public function add_question( $request ) {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $set_id    = absint( $request['set_id'] ?? 0 );

        $data = [
            'stem'          => (string) ( $request->get_param( 'stem' ) ?? $request->get_param( 'question_text' ) ?? '' ),
            'marks'         => (float) ( $request->get_param( 'marks' ) ?? 1 ),
            'options'       => (array) ( $request->get_param( 'options' ) ?? [] ),
            'sub_items'     => (array) ( $request->get_param( 'sub_items' ) ?? [] ),
            'explanation'   => (string) ( $request->get_param( 'explanation' ) ?? '' ),
            'marking_guide' => (string) ( $request->get_param( 'marking_guide' ) ?? '' ),
            'source_method' => sanitize_key( (string) ( $request->get_param( 'source_method' ) ?? 'manual' ) ),
            'difficulty'    => sanitize_key( (string) ( $request->get_param( 'difficulty' ) ?? 'medium' ) ),
            'passage_id'    => absint( $request->get_param( 'passage_id' ) ?? 0 ),
            'stem_image_id' => (string) ( $request->get_param( 'stem_image_id' ) ?? '' ),
        ];

        $service = new QuestionSetService();
        $result  = $service->add_question( $school_id, $set_id, $data );

        if ( empty( $result['success'] ) ) {
            $code = (string) ( $result['error'] ?? '' );

            $messages = [
                'set_not_found'     => 'Question set not found.',
                'set_locked'        => 'This set is locked — it has been submitted or approved.',
                'not_assigned'      => 'You are not assigned to this subject/class.',
                'stem_required'     => 'Question text is required.',
                'no_correct_answer' => 'Mark which option is correct.',
                'min_two_options'   => 'Give at least two options.',
                'marks_required'    => 'Marks must be greater than zero.',
                'insert_failed'     => 'The database rejected the question. This usually means a pending schema update — deactivate and reactivate EduCBT Pro, then try again.',
            ];

            $msg = $messages[ $code ] ?? 'Could not save the question.';

            return new \WP_Error( 'educbt_add_failed', $msg, [ 'status' => 400, 'reason' => $code ] );
        }

        return [ 'success' => true, 'id' => absint( $result['id'] ) ];
    }

    /**
     * PUT /question-sets/{id}/questions/{qid}
     */
    public function update_question( $request ) {
        $school_id   = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $set_id      = absint( $request['set_id'] ?? 0 );
        $question_id = absint( $request['question_id'] ?? 0 );

        // Only pass through what was actually sent. The service treats a present
        // `options` or `sub_items` key as "replace these", so defaulting them to an
        // empty array meant any partial update silently deleted the options or the
        // sub-questions it had not been given.
        $data = [
            'stem'  => (string) ( $request->get_param( 'stem' ) ?? $request->get_param( 'question_text' ) ?? '' ),
            'marks' => (float) ( $request->get_param( 'marks' ) ?? 1 ),
        ];

        foreach ( [ 'options', 'sub_items' ] as $key ) {
            $value = $request->get_param( $key );
            if ( $value !== null ) {
                $data[ $key ] = (array) $value;
            }
        }

        foreach ( [ 'explanation', 'marking_guide' ] as $key ) {
            $value = $request->get_param( $key );
            if ( $value !== null ) {
                $data[ $key ] = (string) $value;
            }
        }

        $service = new QuestionSetService();
        $result  = $service->update_question( $school_id, $set_id, $question_id, $data );

        if ( empty( $result['success'] ) ) {
            $code = (string) ( $result['error'] ?? '' );

            $messages = [
                'set_locked'         => 'This set is locked.',
                'not_assigned'       => 'You are not assigned to this subject/class.',
                'set_not_found'      => 'Question set not found.',
                'question_not_found' => 'That question is not in this set.',
                'no_correct_answer'  => 'Mark which option is correct.',
                'min_two_options'    => 'Give at least two options.',
            ];

            $msg = $messages[ $code ] ?? 'Could not update the question.';

            return new \WP_Error( 'educbt_update_failed', $msg, [ 'status' => 400, 'reason' => $code ] );
        }

        return [ 'success' => true ];
    }

    /**
     * DELETE /question-sets/{id}/questions/{qid}
     */
    public function delete_question( $request ) {
        $school_id   = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $set_id      = absint( $request['set_id'] ?? 0 );
        $question_id = absint( $request['question_id'] ?? 0 );

        $service = new QuestionSetService();
        $result  = $service->delete_question( $school_id, $set_id, $question_id );

        if ( empty( $result['success'] ) ) {
            $msg = $result['error'] === 'set_locked' ? 'This set is locked.'
                : 'Could not delete the question.';
            return new \WP_Error( 'educbt_delete_q_failed', $msg, [ 'status' => 400 ] );
        }

        return [ 'success' => true ];
    }

    /**
     * POST /question-sets/{id}/questions/{qid}/duplicate
     */
    public function duplicate_question( $request ) {
        $school_id   = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $set_id      = absint( $request['set_id'] ?? 0 );
        $question_id = absint( $request['question_id'] ?? 0 );

        $service = new QuestionSetService();
        $result  = $service->duplicate_question( $school_id, $set_id, $question_id );

        if ( empty( $result['success'] ) ) {
            return new \WP_Error( 'educbt_duplicate_failed', 'Could not duplicate the question.', [ 'status' => 400 ] );
        }

        return [ 'success' => true, 'id' => absint( $result['id'] ) ];
    }

    /**
     * POST /question-sets/{id}/reorder
     */
    public function reorder( $request ) {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $set_id    = absint( $request['set_id'] ?? 0 );
        $order     = (array) ( $request->get_json_params() ?? [] );

        $service = new QuestionSetService();
        $result  = $service->reorder( $school_id, $set_id, $order );

        if ( empty( $result['success'] ) ) {
            return new \WP_Error( 'educbt_reorder_failed', 'Could not reorder questions.', [ 'status' => 400 ] );
        }

        return [ 'success' => true ];
    }

    /**
     * POST /question-sets/{id}/submit
     */
    public function submit_set( $request ) {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $scope     = new Scope();
        $actor     = $scope->actor();
        $set_id    = absint( $request['set_id'] ?? 0 );

        // Only teachers submit questions for review — not principals, exam
        // officers, or other school-wide roles. They review, not submit.
        if ( $scope->is_school_wide() ) {
            return [
                'success' => false,
                'error'   => 'reviewers_cannot_submit',
                'message'  => 'Principals and exam officers review questions — they do not submit them.',
            ];
        }

        $service = new QuestionSetService();
        $result  = $service->submit_set( $school_id, $set_id, (int) $actor['id'] );

        if ( empty( $result['success'] ) ) {
            // Return structured data for below_minimum so the frontend can show
            // the combined shortfall (objective + theory) instead of a generic
            // message that only mentions the first shortfall type.
            if ( $result['error'] === 'below_minimum' ) {
                return [
                    'success'   => false,
                    'error'     => 'below_minimum',
                    'shortfall' => $result['shortfall'] ?? [],
                    'min'       => absint( $result['min'] ?? 0 ),
                    'count'     => absint( $result['count'] ?? 0 ),
                ];
            }

            $msg = $result['error'] === 'wrong_status' ? 'This set cannot be submitted in its current status.'
                : ( $result['error'] === 'not_assigned' ? 'You are not assigned to this subject/class.'
                : 'Set not found.' );
            return new \WP_Error( 'educbt_submit_failed', $msg, [ 'status' => 400 ] );
        }

        return [ 'success' => true, 'message' => 'Submitted for review.' ];
    }

    public function withdraw_set( $request ) {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $scope     = new Scope();
        $actor     = $scope->actor();
        $set_id    = absint( $request['set_id'] ?? 0 );

        $service = new QuestionSetService();
        $result = $service->withdraw_set( $school_id, $set_id, (int) $actor['id'] );

        if ( empty( $result['success'] ) ) {
            $msg = $result['error'] === 'cannot_withdraw' ? 'This set cannot be withdrawn in its current status.'
                : ( $result['error'] === 'not_assigned' ? 'You are not assigned to this subject/class.'
                : 'Set not found.' );
            return new \WP_Error( 'educbt_withdraw_failed', $msg, [ 'status' => 400 ] );
        }

        return [ 'success' => true, 'message' => 'Submission withdrawn. The set is back to draft.' ];
    }

    public function delete_set( $request ) {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $scope     = new Scope();
        $actor     = $scope->actor();
        $set_id    = absint( $request['set_id'] ?? 0 );

        $service = new QuestionSetService();
        $result = $service->delete_set( $school_id, $set_id, (int) $actor['id'] );

        if ( empty( $result['success'] ) ) {
            $msg = $result['error'] === 'cannot_delete' ? 'Only draft or returned sets can be deleted.'
                : ( $result['error'] === 'not_assigned' ? 'You are not assigned to this subject/class.'
                : 'Set not found.' );
            return new \WP_Error( 'educbt_delete_failed', $msg, [ 'status' => 400 ] );
        }

        return [ 'success' => true, 'message' => 'Draft set deleted.' ];
    }
}
