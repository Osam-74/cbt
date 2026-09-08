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

        // Session events for the expandable detail rows in the test sessions table.
        register_rest_route(
            self::NAMESPACE,
            '/attempt/(?P<attempt>\d+)/events',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_events' ],
                'permission_callback' => [ $this, 'can_view_events' ],
            ]
        );

        // Report an integrity incident from the exam room.
        //
        // The browser was already warning students that right-clicking and leaving
        // the tab "has been logged" — but there was no route to log it through, so
        // nothing ever reached the server. The warning was telling the truth about
        // an intention and a lie about a fact.
        register_rest_route(
            self::NAMESPACE,
            '/attempt/(?P<attempt>\d+)/integrity',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'log_integrity_event' ],
                'permission_callback' => [ $this, 'can_sit' ],
            ]
        );

        // Flag a question during an exam.
        register_rest_route(
            self::NAMESPACE,
            '/attempt/(?P<attempt>\d+)/flag',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'toggle_flag' ],
                'permission_callback' => [ $this, 'can_sit' ],
            ]
        );
    }

    /**
     * Return session events for an attempt — used by the AJAX expand/collapse
     * in the test sessions table so teachers don't need a page reload.
     */

    /**
     * Record an integrity incident raised by the exam room.
     *
     * The student's own browser is the only thing that can see a right-click or a
     * tab switch, so it has to report them. That means the report is advisory, not
     * proof — a determined student can block the request. It is still worth having:
     * the common case is a student who alt-tabs without thinking, and a record of
     * that is exactly what an invigilator needs to have a quiet word.
     */
    public function log_integrity_event( $request ) {
        $attempt_id = (int) $request->get_param( 'attempt' );
        $type       = sanitize_key( (string) $request->get_param( 'event_type' ) );

        if ( $attempt_id <= 0 || $type === '' ) {
            return new \WP_Error( 'invalid_params', 'Invalid attempt or event.', [ 'status' => 400 ] );
        }

        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );

        // An attempt that has been submitted is closed. Accepting events against it
        // would let a finished paper accumulate incidents from a stray browser tab.
        global $wpdb;

        $status = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . \EduCBTPro\Core\Schema::table( 'attempts' ) . ' WHERE id = %d AND school_id = %d',
                $attempt_id,
                $school_id
            )
        );

        if ( $status !== 'in_progress' ) {
            return [ 'success' => false, 'reason' => 'attempt_closed' ];
        }

        ( new AttemptService() )->log_event(
            $school_id,
            $attempt_id,
            $type,
            [ 'at' => current_time( 'mysql', true ) ]
        );

        return [ 'success' => true ];
    }

    public function get_events( $request ) {
        global $wpdb;

        $attempt_id = (int) $request->get_param( 'attempt' );
        if ( $attempt_id <= 0 ) {
            return new \WP_Error( 'invalid_attempt', 'Invalid attempt.', [ 'status' => 400 ] );
        }

        $events_t = \EduCBTPro\Core\Schema::table( 'attempt_events' );
        $attempts_t = \EduCBTPro\Core\Schema::table( 'attempts' );
        $papers_t   = \EduCBTPro\Core\Schema::table( 'exam_papers' );

        // Verify the attempt exists and get summary info.
        $attempt = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.id, a.status, a.started_at, a.submitted_at, a.flag_count,
                        a.extension_seconds, p.duration_seconds, p.question_count
                 FROM {$attempts_t} a
                 INNER JOIN {$papers_t} p ON p.id = a.paper_id
                 WHERE a.id = %d",
                $attempt_id
            ),
            ARRAY_A
        );

        if ( ! $attempt ) {
            return [ 'events' => [], 'summary' => '' ];
        }

        $events = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, payload, created_at FROM {$events_t}
                 WHERE attempt_id = %d ORDER BY created_at ASC",
                $attempt_id
            ),
            ARRAY_A
        );

        // Two different things live in this table and they must not be presented
        // as one list.
        //
        // A QUESTION FLAG is the student's own bookmark — "come back to this one".
        // It is a studying habit, not a concern, and a careful candidate flags
        // several. Showing it beside integrity events, under a warning triangle,
        // told invigilators a diligent student was behaving suspiciously.
        //
        // An INTEGRITY EVENT is the paper leaving the student's screen, a second
        // session opening, or an attempt being resumed. Those are what an
        // invigilator is actually watching for.
        $labels = [
            'window_blur'      => 'Left the exam window',
            'second_session'   => 'Opened the paper in a second session',
            'resumed'          => 'Attempt resumed',
            'question_flagged' => 'Question bookmarked by student',
        ];

        $formatted = [];
        $flags     = [];

        foreach ( $events as $ev ) {
            $type    = (string) $ev['event_type'];
            $payload = (array) json_decode( (string) ( $ev['payload'] ?? '' ), true );
            $when    = wp_date( 'M j, g:i:s A', strtotime( (string) $ev['created_at'] . ' UTC' ) );

            if ( $type === 'question_flagged' ) {
                $flags[] = [
                    'question_id' => absint( $payload['question_id'] ?? 0 ),
                    'created_at'  => $when,
                ];
                continue;
            }

            $formatted[] = [
                'event_type' => $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ),
                'payload'    => $ev['payload'] ?? '',
                'created_at' => $when,
            ];
        }

        // Build summary.
        $summary_parts = [];
        $duration = (int) ( $attempt['duration_seconds'] ?? 0 );
        if ( $duration > 0 ) {
            $summary_parts[] = 'Duration allowed: ' . round( $duration / 60 ) . ' min';
        }
        if ( ! empty( $attempt['started_at'] ) && ! empty( $attempt['submitted_at'] ) ) {
            $elapsed = (int) strtotime( (string) $attempt['submitted_at'] . ' UTC' ) - (int) strtotime( $attempt['started_at'] . ' UTC' );
            if ( $elapsed > 0 ) {
                $summary_parts[] = 'Actual elapsed: ' . round( $elapsed / 60 ) . ' min';
            }
        }
        if ( (int) ( $attempt['extension_seconds'] ?? 0 ) > 0 ) {
            $summary_parts[] = 'Extension: ' . round( (int) $attempt['extension_seconds'] / 60 ) . ' min';
        }
        if ( ! empty( $flags ) ) {
            $summary_parts[] = count( $flags ) . ' question(s) bookmarked by the student';
        }

        return [
            'flags'    => $flags,
            // True when the paper ran without a single integrity event. Saying so
            // plainly is more useful than an empty list, which reads like a fault.
            'clean'    => empty( $formatted ),
            'events'  => $formatted,
            'summary' => implode(' · ', $summary_parts),
        ];
    }

    /**
     * Permission check for viewing attempt events — any logged-in portal user
     * who can view exams (teacher, exam officer, admin).
     */
    public function can_view_events( $request ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        return \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::VIEW_EXAMS );
    }

    /**
     * Toggle a question flag during an exam. Students can flag questions they
     * want to revisit — the flag is stored as an attempt event and the
     * attempt's flag_count is updated.
     */
    public function toggle_flag( $request ) {
        global $wpdb;

        $attempt_id = (int) $request->get_param( 'attempt' );
        $question_id = (int) $request->get_param( 'question_id' );
        $action_type = sanitize_text_field( $request->get_param( 'flag_action' ) ?: 'toggle' );

        if ( $attempt_id <= 0 || $question_id <= 0 ) {
            return new \WP_Error( 'invalid_params', 'Invalid attempt or question.', [ 'status' => 400 ] );
        }

        $attempts_t = \EduCBTPro\Core\Schema::table( 'attempts' );
        $events_t   = \EduCBTPro\Core\Schema::table( 'attempt_events' );

        // Verify attempt belongs to current student.
        $actor = ( new \EduCBTPro\Core\Scope() )->actor();
        $student_id = (int) ( $actor['id'] ?? 0 );

        $attempt = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, school_id FROM {$attempts_t} WHERE id = %d AND student_id = %d",
                $attempt_id, $student_id
            ),
            ARRAY_A
        );

        if ( ! $attempt ) {
            return new \WP_Error( 'not_found', 'Attempt not found.', [ 'status' => 404 ] );
        }

        $school_id = (int) $attempt['school_id'];

        // Check if already flagged.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$events_t}
                 WHERE attempt_id = %d AND event_type = 'question_flagged'
                 AND payload LIKE %s",
                $attempt_id,
                '%"question_id":' . $question_id . '%'
            )
        );

        if ( $existing ) {
            // Unflag.
            $wpdb->delete( $events_t, [ 'id' => $existing ], [ '%d' ] );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$attempts_t} SET flag_count = GREATEST(flag_count - 1, 0) WHERE id = %d",
                    $attempt_id
                )
            );
            return [ 'flagged' => false, 'flag_count' => max( (int) $wpdb->get_var( $wpdb->prepare( "SELECT flag_count FROM {$attempts_t} WHERE id = %d", $attempt_id ) ), 0 ) ];
        } else {
            // Flag.
            $wpdb->insert(
                $events_t,
                [
                    'school_id'   => $school_id,
                    'attempt_id'  => $attempt_id,
                    'event_type'  => 'question_flagged',
                    'payload'     => wp_json_encode( [ 'question_id' => $question_id ] ),
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$attempts_t} SET flag_count = flag_count + 1 WHERE id = %d",
                    $attempt_id
                )
            );
            return [ 'flagged' => true, 'flag_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT flag_count FROM {$attempts_t} WHERE id = %d", $attempt_id ) ) ];
        }
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
        // is marked by a teacher afterwards. But it must still pass the same safety
        // gates as the objective path — attempt is open, session is valid, time has
        // not expired, and the question belongs to this paper (either directly or
        // as a child of a theory question on the paper).
        if ( $text !== null ) {
            $attempt_service = new AttemptService();
            $attempt         = $attempt_service->get( $school_id, $attempt_id );

            if ( ! $attempt ) {
                return new \WP_Error( 'educbt_not_your_attempt', 'Not permitted.', [ 'status' => 403 ] );
            }

            if ( (string) $attempt['status'] !== AttemptService::STATUS_IN_PROGRESS ) {
                return [ 'saved' => false, 'reason' => 'attempt_closed' ];
            }

            $session_token = (string) $request->get_param( 'session_token' );
            if ( $session_token !== '' && ! hash_equals( (string) $attempt['session_token'], $session_token ) ) {
                return [ 'saved' => false, 'reason' => 'session_superseded' ];
            }

            $clock    = $attempt_service->timer( $school_id, $attempt_id );

            if ( ! empty( $clock['expired'] ) ) {
                return [ 'saved' => false, 'reason' => 'time_expired' ];
            }

            $question_id = absint( $request->get_param( 'question_id' ) );
            if ( ! $attempt_service->question_or_child_on_attempt( $attempt, $question_id ) ) {
                return [ 'saved' => false, 'reason' => 'question_not_on_this_paper' ];
            }

            $saved = ( new \EduCBTPro\Services\TheoryService() )->save_text_answer(
                $school_id,
                $attempt_id,
                $question_id,
                (string) $text
            );

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
