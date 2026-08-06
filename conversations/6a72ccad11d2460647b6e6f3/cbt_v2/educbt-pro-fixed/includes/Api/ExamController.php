<?php

namespace EduCBTPro\Api;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Scope;
use EduCBTPro\Core\TenantContext;
use EduCBTPro\Services\AttemptService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoints for a student sitting a paper.
 *
 * Deliberately separate from the general REST controller: this is the hot path.
 * Three hundred students hit /answer within the same few minutes, so these routes
 * do the least possible work and nothing else shares their code.
 *
 * The student id is never taken from the request. It is resolved from the signed-in
 * user, so one student cannot answer as another by editing a payload.
 */
class ExamController {

    public const NAMESPACE = 'educbt/v1';

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/exam/(?P<paper>\d+)/start',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'start' ],
                'permission_callback' => [ $this, 'can_sit' ],
                'args'                => [ 'access_code' => [ 'type' => 'string', 'required' => false ] ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/attempt/(?P<attempt>\d+)/answer',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'answer' ],
                'permission_callback' => [ $this, 'can_sit' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/attempt/(?P<attempt>\d+)/submit',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'submit' ],
                'permission_callback' => [ $this, 'can_sit' ],
            ]
        );
    }

    public function can_sit(): bool {
        return is_user_logged_in() && Gate::allows( Capabilities::SIT_EXAM );
    }

    /**
     * @return array{0:int,1:int}  school_id, student_id
     */
    private function actor(): array {
        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        $actor     = ( new Scope() )->actor();

        return [ $school_id, $actor['type'] === Scope::ACTOR_STUDENT ? absint( $actor['id'] ) : 0 ];
    }

    public function start( $request ) {
        [ $school_id, $student_id ] = $this->actor();

        if ( $student_id === 0 ) {
            return new \WP_Error( 'educbt_not_a_student', 'Only a student can sit a paper.', [ 'status' => 403 ] );
        }

        $result = ( new AttemptService() )->start(
            $school_id,
            $student_id,
            absint( $request['paper'] ),
            (string) $request->get_param( 'access_code' )
        );

        if ( empty( $result['success'] ) ) {
            return new \WP_Error( 'educbt_cannot_start', $this->explain( (string) $result['reason'] ), [ 'status' => 403 ] );
        }

        $service = new AttemptService();
        $payload = $service->deliver( $school_id, absint( $result['attempt']['id'] ) );

        return [
            'attempt_id'    => absint( $result['attempt']['id'] ),
            'session_token' => (string) $result['attempt']['session_token'],
            'resumed'       => ! empty( $result['resumed'] ),
            'questions'     => $payload['questions'],
            'passages'      => $payload['passages'],
            'answers'       => $payload['answers'],
            'timer'         => $payload['timer'],
        ];
    }

    /**
     * Save one answer. Kept as small as possible — this fires on every click.
     */
    public function answer( $request ) {
        [ $school_id, $student_id ] = $this->actor();

        if ( $student_id === 0 ) {
            return new \WP_Error( 'educbt_not_a_student', 'Not permitted.', [ 'status' => 403 ] );
        }

        $attempt_id = absint( $request['attempt'] );

        if ( ! $this->owns_attempt( $school_id, $attempt_id, $student_id ) ) {
            return new \WP_Error( 'educbt_not_your_attempt', 'Not permitted.', [ 'status' => 403 ] );
        }

        $text = $request->get_param( 'answer_text' );

        // A written answer takes a different path: no option, no correctness, and it
        // is marked by a teacher afterwards.
        if ( $text !== null ) {
            $saved = ( new \EduCBTPro\Services\TheoryService() )->save_text_answer(
                $school_id,
                $attempt_id,
                absint( $request->get_param( 'question_id' ) ),
                (string) $text
            );

            $clock = ( new AttemptService() )->timer( $school_id, $attempt_id );

            if ( ! empty( $clock['expired'] ) ) {
                return [ 'saved' => false, 'reason' => 'time_expired' ];
            }

            return [ 'saved' => ! empty( $saved['success'] ), 'timer' => $clock ];
        }

        $option = $request->get_param( 'option_id' );

        $result = ( new AttemptService() )->save_answer(
            $school_id,
            $attempt_id,
            absint( $request->get_param( 'question_id' ) ),
            ( $option === null || $option === '' ) ? null : absint( $option ),
            (string) $request->get_param( 'session_token' )
        );

        if ( empty( $result['success'] ) ) {
            return [ 'saved' => false, 'reason' => (string) $result['reason'] ];
        }

        return [ 'saved' => true, 'timer' => $result['timer'] ];
    }

    public function submit( $request ) {
        [ $school_id, $student_id ] = $this->actor();
        $attempt_id                 = absint( $request['attempt'] );

        if ( $student_id === 0 || ! $this->owns_attempt( $school_id, $attempt_id, $student_id ) ) {
            return new \WP_Error( 'educbt_not_your_attempt', 'Not permitted.', [ 'status' => 403 ] );
        }

        $result = ( new AttemptService() )->submit(
            $school_id,
            $attempt_id,
            AttemptService::REASON_MANUAL,
            (string) $request->get_param( 'session_token' )
        );

        return [ 'submitted' => ! empty( $result['success'] ), 'status' => (string) ( $result['status'] ?? '' ) ];
    }

    /**
     * An attempt id in the URL proves nothing. Confirm it belongs to this student.
     */
    private function owns_attempt( int $school_id, int $attempt_id, int $student_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . \EduCBTPro\Core\Schema::table( 'attempts' ) .
                ' WHERE id = %d AND school_id = %d AND student_id = %d',
                $attempt_id,
                $school_id,
                $student_id
            )
        );
    }

    /**
     * Turn a refusal code into something a student under exam pressure can act on.
     */
    private function explain( string $reason ): string {
        $map = [
            'invalid_access_code'    => 'That code is not correct. Ask the invigilator to read it again.',
            'too_early'              => 'This paper has not opened yet.',
            'window_closed'          => 'The time for this paper has passed.',
            'already_submitted'      => 'You have already submitted this paper.',
            'not_in_this_class'      => 'This paper is not set for your class.',
            'subject_not_registered' => 'You are not registered for this subject.',
            'paper_not_published'    => 'This paper is not open yet.',
            'paper_has_no_questions' => 'This paper has no questions. Tell the invigilator.',
        ];

        return $map[ $reason ] ?? 'This paper cannot be opened.';
    }
}
