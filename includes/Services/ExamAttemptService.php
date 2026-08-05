<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\ExamClock;
use EduCBTPro\Core\Repository\ExamAttemptRepository;
use EduCBTPro\Core\Repository\ExamRepository;
use EduCBTPro\Core\Repository\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamAttemptService {
    private ExamAttemptRepository $repository;
    private ExamRepository $exam_repository;
    private QuestionRepository $question_repository;
    private ExamClock $clock;

    public function __construct( ?ExamAttemptRepository $repository = null, ?ExamRepository $exam_repository = null, ?QuestionRepository $question_repository = null, ?ExamClock $clock = null ) {
        $this->repository = $repository ?? new ExamAttemptRepository();
        $this->exam_repository = $exam_repository ?? new ExamRepository();
        $this->question_repository = $question_repository ?? new QuestionRepository();
        $this->clock = $clock ?? new ExamClock();
    }

    public function get_clock(): ExamClock {
        return $this->clock;
    }

    public function get_all_attempts( int $school_id ): array {
        return $this->repository->get_all_attempts( $school_id );
    }

    public function get_student_attempts( int $school_id, int $student_id, int $exam_id ): array {
        return $this->repository->get_student_attempts( $school_id, $student_id, $exam_id );
    }

    public function get_active_attempt( int $school_id, int $exam_id, int $student_id ): ?array {
        return $this->repository->get_active_attempt( $school_id, $exam_id, $student_id );
    }

    public function get_attempt_by_id( int $school_id, int $attempt_id ): ?array {
        return $this->repository->get_attempt_by_id( $school_id, $attempt_id );
    }

    public function create_attempt( int $school_id, int $exam_id, int $student_id, array $options = [] ): int {
        $active = $this->repository->get_active_attempt( $school_id, $exam_id, $student_id );
        if ( $active ) {
            EventDispatcher::action( 'session_abuse_detected', [
                'school_id'  => $school_id,
                'student_id' => $student_id,
                'exam_id'    => $exam_id,
                'attempt_id' => absint( $active['id'] ?? 0 ),
                'reason'     => 'duplicate_active_attempt',
            ] );
            return 0;
        }

        $exam = $this->exam_repository->get_exam( $exam_id );
        if ( ! $exam ) {
            return 0;
        }

        $question_ids = $this->exam_repository->get_exam_question_ids( $school_id, $exam_id );
        
        $question_order = $options['randomize_questions'] ?? false ? array_values( (array) $question_ids ) : $question_ids;
        if ( $options['randomize_questions'] ?? false ) {
            shuffle( $question_order );
        }

        $data = [
            'exam_id'                  => $exam_id,
            'student_id'               => $student_id,
            'session_key'              => $this->generate_session_key(),
            'question_order'           => $question_order,
            'randomize_options'        => $options['randomize_options'] ?? 0,
            'time_started'             => $this->get_current_time(),
            'timer_seconds_remaining'  => ( $exam['duration_minutes'] ?? 0 ) * 60,
            'status'                   => 'in_progress',
        ];

        $id = $this->repository->create_attempt( $school_id, $data );

        if ( $id > 0 ) {
            EventDispatcher::action( 'exam_started', [
                'school_id'  => $school_id,
                'attempt_id' => $id,
                'exam_id'    => $exam_id,
                'student_id' => $student_id,
            ] );
        }

        return $id;
    }

    public function get_attempt_by_session( int $school_id, string $session_key ): ?array {
        return $this->repository->get_attempt_by_session( $school_id, $session_key );
    }

    public function detect_multiple_logins( int $school_id, int $exam_id, int $student_id ): bool {
        $attempts = $this->repository->get_student_attempts( $school_id, $student_id, $exam_id );
        $active = array_values( array_filter( $attempts, static fn( array $a ): bool => ( $a['status'] ?? '' ) === 'in_progress' ) );
        return count( $active ) > 1;
    }

    public function validate_session_access( int $school_id, string $session_key ): array {
        if ( trim( $session_key ) === '' ) {
            return [ 'allowed' => false, 'reason' => 'session_key_required' ];
        }

        $attempt = $this->repository->get_attempt_by_session( $school_id, $session_key );
        if ( ! $attempt ) {
            return [ 'allowed' => false, 'reason' => 'session_not_found' ];
        }

        if ( ( $attempt['status'] ?? 'in_progress' ) !== 'in_progress' ) {
            EventDispatcher::action( 'session_abuse_detected', [
                'school_id'  => $school_id,
                'student_id' => absint( $attempt['student_id'] ?? 0 ),
                'exam_id'    => absint( $attempt['exam_id'] ?? 0 ),
                'attempt_id' => absint( $attempt['id'] ?? 0 ),
                'reason'     => 'session_not_active',
            ] );
            return [ 'allowed' => false, 'reason' => 'session_not_active', 'attempt' => $attempt ];
        }

        // PHASE 0: expiry is computed from the server clock, never read from the
        // stored or client-supplied timer value.
        if ( $this->is_expired( $attempt ) ) {
            $this->force_submit_expired( $school_id, $attempt );

            EventDispatcher::action( 'session_abuse_detected', [
                'school_id'  => $school_id,
                'student_id' => absint( $attempt['student_id'] ?? 0 ),
                'exam_id'    => absint( $attempt['exam_id'] ?? 0 ),
                'attempt_id' => absint( $attempt['id'] ?? 0 ),
                'reason'     => 'session_expired',
            ] );
            return [ 'allowed' => false, 'reason' => 'session_expired', 'attempt' => $attempt ];
        }

        $exam_id = absint( $attempt['exam_id'] ?? 0 );
        $student_id = absint( $attempt['student_id'] ?? 0 );
        if ( $exam_id > 0 && $student_id > 0 && $this->detect_multiple_logins( $school_id, $exam_id, $student_id ) ) {
            EventDispatcher::action( 'session_abuse_detected', [
                'school_id'  => $school_id,
                'student_id' => $student_id,
                'exam_id'    => $exam_id,
                'attempt_id' => absint( $attempt['id'] ?? 0 ),
                'reason'     => 'concurrent_session_detected',
            ] );
            return [ 'allowed' => false, 'reason' => 'concurrent_session_detected', 'attempt' => $attempt ];
        }

        return [ 'allowed' => true, 'attempt' => $attempt ];
    }

    /**
     * PHASE 0 SECURITY FIX.
     *
     * update_timer() previously accepted $seconds_remaining FROM THE BROWSER and stored
     * it, so a student could post a full duration indefinitely and sit an unlimited exam.
     * Any client-supplied value is now IGNORED; remaining time is recomputed from the
     * server clock, and an attempt past its window is force-submitted here.
     *
     * @return array{remaining_seconds:int,expired:bool,server_time:int,status:string}
     */
    public function heartbeat( int $school_id, int $attempt_id ): array {
        $attempt = $this->find_attempt_by_id( $school_id, $attempt_id );
        if ( ! $attempt ) {
            return [ 'remaining_seconds' => 0, 'expired' => true, 'server_time' => $this->clock->now(), 'status' => 'not_found' ];
        }

        $exam    = $this->get_exam_for_attempt( $attempt );
        $payload = $this->clock->client_payload( $attempt, $exam );
        $status  = (string) ( $attempt['status'] ?? '' );

        if ( $status !== 'in_progress' ) {
            return [ 'remaining_seconds' => 0, 'expired' => true, 'server_time' => $payload['server_time'], 'status' => $status ];
        }

        if ( $payload['expired'] ) {
            $this->force_submit_expired( $school_id, $attempt );
            return [ 'remaining_seconds' => 0, 'expired' => true, 'server_time' => $payload['server_time'], 'status' => 'submitted' ];
        }

        // Cached for reporting only. Never read back as an authority.
        $this->repository->update_attempt( $school_id, $attempt_id, [
            'timer_seconds_remaining' => $payload['remaining_seconds'],
        ] );

        return [
            'remaining_seconds' => $payload['remaining_seconds'],
            'expired'           => false,
            'server_time'       => $payload['server_time'],
            'status'            => 'in_progress',
        ];
    }

    /**
     * @deprecated Client-supplied timer values are ignored. Use heartbeat().
     */
    public function update_timer( int $school_id, int $attempt_id, int $seconds_remaining = 0 ): bool {
        unset( $seconds_remaining );
        $result = $this->heartbeat( $school_id, $attempt_id );
        return $result['status'] !== 'not_found';
    }

    /** Authoritative remaining seconds for an attempt. */
    public function get_remaining_seconds( int $school_id, int $attempt_id ): int {
        $attempt = $this->find_attempt_by_id( $school_id, $attempt_id );
        if ( ! $attempt ) {
            return 0;
        }

        return $this->clock->remaining_seconds( $attempt, $this->get_exam_for_attempt( $attempt ) );
    }

    public function get_timer_payload( int $school_id, int $attempt_id ): array {
        $attempt = $this->find_attempt_by_id( $school_id, $attempt_id );
        if ( ! $attempt ) {
            return [ 'remaining_seconds' => 0, 'allowed_seconds' => 0, 'server_time' => $this->clock->now(), 'expires_at' => null, 'expired' => true ];
        }

        return $this->clock->client_payload( $attempt, $this->get_exam_for_attempt( $attempt ) );
    }

    public function is_expired( array $attempt ): bool {
        return $this->clock->has_expired( $attempt, $this->get_exam_for_attempt( $attempt ) );
    }

    /**
     * Whether a submission arriving now is still inside the allowed window,
     * plus a short grace period for requests already in flight at expiry.
     */
    public function accepts_submission( array $attempt ): bool {
        return $this->clock->accepts_submission( $attempt, $this->get_exam_for_attempt( $attempt ) );
    }

    /** Close out an attempt whose window has passed. Idempotent. */
    public function force_submit_expired( int $school_id, array $attempt ): bool {
        $attempt_id = absint( $attempt['id'] ?? 0 );
        if ( $attempt_id <= 0 || ( $attempt['status'] ?? '' ) !== 'in_progress' ) {
            return false;
        }

        $updated = $this->repository->update_attempt(
            $school_id,
            $attempt_id,
            [
                'timer_seconds_remaining' => 0,
                'status'                  => 'submitted',
                'time_submitted'          => $this->get_current_time(),
                'submit_reason'           => 'timeout',
            ]
        );

        if ( $updated ) {
            EventDispatcher::action( 'exam_auto_submitted', [
                'school_id'  => $school_id,
                'attempt_id' => $attempt_id,
                'exam_id'    => absint( $attempt['exam_id'] ?? 0 ),
                'student_id' => absint( $attempt['student_id'] ?? 0 ),
                'reason'     => 'timeout',
            ] );
        }

        return $updated;
    }

    /**
     * Close every in-progress attempt whose window has passed, so a student who
     * closed the browser is still scored. Driven by cron.
     */
    public function sweep_expired_attempts( int $school_id ): int {
        $closed = 0;

        foreach ( $this->repository->get_all_attempts( $school_id ) as $attempt ) {
            if ( ( $attempt['status'] ?? '' ) !== 'in_progress' ) {
                continue;
            }

            if ( $this->is_expired( $attempt ) && $this->force_submit_expired( $school_id, $attempt ) ) {
                $closed++;
            }
        }

        return $closed;
    }

    private function get_exam_for_attempt( array $attempt ): array {
        $exam_id = absint( $attempt['exam_id'] ?? 0 );
        if ( $exam_id <= 0 ) {
            return [];
        }

        $exam = $this->exam_repository->get_exam( $exam_id );
        return is_array( $exam ) ? $exam : [];
    }

    public function submit_attempt( int $school_id, int $attempt_id ): bool {
        $attempt = $this->find_attempt_by_id( $school_id, $attempt_id );
        if ( ! $attempt || ( $attempt['status'] ?? '' ) !== 'in_progress' ) {
            return false;
        }

        // A submission arriving after the window closes (beyond the in-flight grace
        // period) is recorded as a timeout, not accepted as a normal submission.
        if ( ! $this->accepts_submission( $attempt ) ) {
            $this->force_submit_expired( $school_id, $attempt );
            return false;
        }

        return $this->repository->update_attempt(
            $school_id,
            $attempt_id,
            [
                'timer_seconds_remaining' => 0,
                'status'                  => 'submitted',
                'time_submitted'          => $this->get_current_time(),
                'submit_reason'           => 'manual',
            ]
        );
    }

    public function get_session_questions( int $school_id, string $session_key ): array {
        $access = $this->validate_session_access( $school_id, $session_key );
        if ( ! ( $access['allowed'] ?? false ) ) {
            return [];
        }

        $attempt = $access['attempt'];

        $question_order = $attempt['question_order'] ? json_decode( $attempt['question_order'], true ) : [];
        $questions = [];

        foreach ( $question_order as $question_id ) {
            $question = $this->question_repository->get_question( $question_id );
            if ( $question ) {
                // Remove answer key before sending to student
                $question['answers'] = null;
                $question['explanations'] = null;
                
                // Randomize options if enabled
                if ( $attempt['randomize_options'] ) {
                    $options = $question['options'] ? json_decode( $question['options'], true ) : [];
                    shuffle( $options );
                    $question['options'] = wp_json_encode( $options );
                }
                
                $questions[] = $question;
            }
        }

        return $questions;
    }

    public function get_attempt_questions( int $school_id, int $attempt_id, bool $include_sensitive_fields = false ): array {
        $attempt = $this->repository->get_attempt_by_id( $school_id, $attempt_id );
        if ( ! $attempt ) {
            return [];
        }

        $question_order = isset( $attempt['question_order'] ) ? json_decode( (string) $attempt['question_order'], true ) : [];
        if ( ! is_array( $question_order ) ) {
            $question_order = [];
        }

        $questions = [];
        foreach ( $question_order as $question_id ) {
            $question = $this->question_repository->get_question( absint( $question_id ) );
            if ( ! is_array( $question ) ) {
                continue;
            }

            $options = json_decode( (string) ( $question['options'] ?? '[]' ), true );
            $answers = json_decode( (string) ( $question['answers'] ?? '[]' ), true );

            $question['options'] = is_array( $options ) ? array_values( $options ) : [];
            $question['answers'] = is_array( $answers ) ? array_values( $answers ) : [];
            $question['explanations'] = sanitize_textarea_field( (string) ( $question['explanations'] ?? '' ) );

            if ( ! $include_sensitive_fields ) {
                $question['answers'] = [];
                $question['explanations'] = '';
            }

            $questions[] = $question;
        }

        return $questions;
    }

    public function get_student_attempt_count_for_day( int $school_id, int $student_id, string $day_ymd ): int {
        if ( $school_id <= 0 || $student_id <= 0 || trim( $day_ymd ) === '' ) {
            return 0;
        }

        return $this->repository->count_student_attempts_for_day( $school_id, $student_id, $day_ymd );
    }

    public function delete_attempt( int $school_id, int $attempt_id ): bool {
        return $this->repository->delete_attempt( $school_id, $attempt_id );
    }

    private function generate_session_key(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        // Fallback: create a simple hash-based session key
        return md5( uniqid( '', true ) . microtime( true ) );
    }

    private function get_current_time(): string {
        if ( function_exists( 'current_time' ) ) {
            return current_time( 'mysql' );
        }
        return date( 'Y-m-d H:i:s' );
    }

    /**
     * PHASE 0 PERFORMANCE FIX: this previously loaded every attempt in the school to
     * find one row, on the exam hot path. Indexed lookup first; the scan is retained
     * only as a fallback for repository doubles used in tests.
     */
    private function find_attempt_by_id( int $school_id, int $attempt_id ): ?array {
        if ( method_exists( $this->repository, 'get_attempt_by_id' ) ) {
            $direct = $this->repository->get_attempt_by_id( $school_id, $attempt_id );
            if ( is_array( $direct ) && ! empty( $direct ) ) {
                return $direct;
            }
        }

        foreach ( $this->repository->get_all_attempts( $school_id ) as $attempt ) {
            if ( absint( $attempt['id'] ?? 0 ) === $attempt_id ) {
                return $attempt;
            }
        }

        return null;
    }
}
