<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\ExamClock;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 6 — the CBT runtime.
 *
 * This is the part that has to work when three hundred students are sitting a paper
 * on a school connection that drops. Four rules govern everything below.
 *
 *  1. THE SERVER OWNS THE CLOCK. Nothing the browser says about time is believed.
 *     Remaining time is always duration - (now - started_at) + extension.
 *
 *  2. THE ANSWER KEY NEVER LEAVES THE SERVER. Questions are delivered without any
 *     `is_correct` flag. Marking happens server-side against question_options.
 *
 *  3. EVERY WRITE IS IDEMPOTENT. A student on a flaky connection will retry. Start
 *     is an upsert, answer-save is an upsert, submit is safe to call twice. None of
 *     them can duplicate or lose work.
 *
 *  4. SUBMISSION IS CHEAP. Grading does NOT happen inside the submit request,
 *     because every student in a hall submits within the same few seconds when the
 *     clock hits zero. Submit writes a status; a worker grades afterwards.
 */
class AttemptService {

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_GRADED      = 'graded';

    public const REASON_MANUAL = 'manual';
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_FORCED = 'forced';

    private ExamClock $clock;

    public function __construct( ?ExamClock $clock = null ) {
        $this->clock = $clock ?? new ExamClock();
    }

    // ---------------------------------------------------------------
    // Start / resume
    // ---------------------------------------------------------------

    /**
     * Begin, or resume, an attempt.
     *
     * Deliberately one method. A student whose browser crashed and a student opening
     * the paper for the first time press the same button, and the system must not
     * care which they are. The UNIQUE (paper_id, student_id) key is what makes this
     * safe under a double-click.
     *
     * @return array{success:bool,attempt?:array<string,mixed>,resumed?:bool,reason?:string}
     */
    public function start( int $school_id, int $student_id, int $paper_id, string $access_code = '' ): array {
        global $wpdb;

        $gate = ( new TimetableService() )->can_open( $school_id, $student_id, $paper_id, $access_code );

        if ( ! $gate['allowed'] ) {
            return [ 'success' => false, 'reason' => $gate['reason'] ];
        }

        $paper    = $gate['paper'];
        $attempts = Schema::table( 'attempts' );

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$attempts} WHERE paper_id = %d AND student_id = %d",
                $paper_id,
                $student_id
            ),
            ARRAY_A
        );

        if ( $existing ) {
            if ( (string) $existing['status'] !== self::STATUS_IN_PROGRESS ) {
                return [ 'success' => false, 'reason' => 'already_submitted' ];
            }

            // The window may have closed while the student was disconnected.
            if ( $this->clock->has_expired( $this->clock_shape( $existing, $paper ), $paper ) ) {
                $this->close( $school_id, absint( $existing['id'] ), self::REASON_TIMEOUT );
                return [ 'success' => false, 'reason' => 'window_closed' ];
            }

            $this->log_event( $school_id, absint( $existing['id'] ), 'resumed', [] );

            return [
                'success' => true,
                'resumed' => true,
                'attempt' => $this->hydrate( $school_id, absint( $existing['id'] ) ),
            ];
        }

        $order = $this->build_question_order( $school_id, $paper_id, $paper );

        if ( empty( $order ) ) {
            return [ 'success' => false, 'reason' => 'paper_has_no_questions' ];
        }

        $enrollment_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . Schema::table( 'enrollments' ) .
                    " WHERE student_id = %d AND class_id = %d AND status = 'active' LIMIT 1",
                    $student_id,
                    absint( $paper['class_id'] )
                )
            )
        );

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$attempts}
                    (school_id, paper_id, student_id, enrollment_id, session_token, question_order, started_at, status, max_score)
                 VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %f)",
                $school_id,
                $paper_id,
                $student_id,
                $enrollment_id,
                wp_generate_password( 48, false, false ),
                (string) wp_json_encode( $order ),
                current_time( 'mysql', true ),
                self::STATUS_IN_PROGRESS,
                (float) $paper['total_marks']
            )
        );

        // INSERT IGNORE swallowed a race: two tabs pressed start at the same instant.
        // The other one won; adopt its attempt rather than erroring.
        if ( ! $inserted ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare( "SELECT id FROM {$attempts} WHERE paper_id = %d AND student_id = %d", $paper_id, $student_id ),
                ARRAY_A
            );

            if ( ! $existing ) {
                return [ 'success' => false, 'reason' => 'could_not_start' ];
            }

            return [ 'success' => true, 'resumed' => true, 'attempt' => $this->hydrate( $school_id, absint( $existing['id'] ) ) ];
        }

        $attempt_id = absint( $wpdb->insert_id );

        EventDispatcher::action( 'educbt_attempt_started', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'paper_id'   => $paper_id,
            'student_id' => $student_id,
        ] );

        return [ 'success' => true, 'resumed' => false, 'attempt' => $this->hydrate( $school_id, $attempt_id ) ];
    }

    /**
     * Fix the question order at start and store it.
     *
     * Shuffling per request would renumber the paper every time a student refreshed,
     * so "question 12" would mean something different each time and the answer grid
     * would be meaningless. Order is decided once and never changes.
     *
     * Passage blocks stay intact — see PassageService.
     *
     * @return array<int,int>
     */
    private function build_question_order( int $school_id, int $paper_id, array $paper ): array {
        global $wpdb;

        $paper_questions = Schema::table( 'paper_questions' );
        $questions       = $wpdb->prefix . 'educbt_questions';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.passage_id, q.passage_position
                 FROM {$paper_questions} pq
                 INNER JOIN {$questions} q ON q.id = pq.question_id
                 WHERE pq.paper_id = %d AND pq.school_id = %d
                 ORDER BY pq.sort_order ASC",
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        return ( new PassageService() )->order_for_delivery( $rows, ! empty( $paper['shuffle_questions'] ) );
    }

    // ---------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------

    /**
     * The paper as the student receives it.
     *
     * NOTE the absence of `is_correct` anywhere in this payload. That is the single
     * most important line in this class: a student who opens devtools must find no
     * answer key, only options.
     *
     * The whole paper is sent at once rather than page-by-page. On a connection that
     * drops, one request that succeeds is far better than sixty that might not.
     *
     * @return array{questions:array<int,array<string,mixed>>,passages:array<int,array<string,mixed>>,answers:array<int,int>,timer:array<string,mixed>}
     */
    /**
     * The authoritative remaining time for an attempt.
     *
     * Exposed so any save path can return it — the client's countdown is cosmetic and
     * adopts whatever the server last said.
     *
     * @return array<string,mixed>
     */
    public function timer( int $school_id, int $attempt_id ): array {
        $attempt = $this->get( $school_id, $attempt_id );

        if ( ! $attempt ) {
            return [ 'remaining_seconds' => 0, 'expired' => true ];
        }

        $paper = $this->paper( $school_id, absint( $attempt['paper_id'] ) );

        return $this->clock->client_payload( $this->clock_shape( $attempt, $paper ), $paper );
    }

    public function deliver( int $school_id, int $attempt_id ): array {
        global $wpdb;

        $attempt = $this->get( $school_id, $attempt_id );

        if ( ! $attempt ) {
            return [ 'questions' => [], 'passages' => [], 'answers' => [], 'timer' => [] ];
        }

        $paper = $this->paper( $school_id, absint( $attempt['paper_id'] ) );
        $order = json_decode( (string) $attempt['question_order'], true );
        $order = is_array( $order ) ? array_map( 'absint', $order ) : [];

        if ( empty( $order ) ) {
            return [ 'questions' => [], 'passages' => [], 'answers' => [], 'timer' => [] ];
        }

        $placeholders = implode( ',', array_fill( 0, count( $order ), '%d' ) );

        $questions = $wpdb->prefix . 'educbt_questions';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, question_text, image_reference, question_type, marks, passage_id
                 FROM {$questions} WHERE id IN ({$placeholders})",
                $order
            ),
            ARRAY_A
        );

        $by_id = [];

        foreach ( $rows as $row ) {
            $by_id[ absint( $row['id'] ) ] = $row;
        }

        $options_table = Schema::table( 'question_options' );

        // Explicitly selecting columns, never `SELECT *`, so is_correct cannot leak
        // through a future schema change.
        $option_rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, question_id, option_key, option_text, option_image, sort_order
                 FROM {$options_table} WHERE question_id IN ({$placeholders}) ORDER BY sort_order ASC",
                $order
            ),
            ARRAY_A
        );

        $options = [];

        foreach ( $option_rows as $row ) {
            $options[ absint( $row['question_id'] ) ][] = [
                'id'    => absint( $row['id'] ),
                'key'   => (string) $row['option_key'],
                'text'  => (string) $row['option_text'],
                'image' => (string) $row['option_image'],
            ];
        }

        $shuffle_options = ! empty( $paper['shuffle_options'] );
        $delivered       = [];
        $position        = 1;

        foreach ( $order as $question_id ) {
            if ( ! isset( $by_id[ $question_id ] ) ) {
                continue;
            }

            $question = $by_id[ $question_id ];
            $choices  = $options[ $question_id ] ?? [];

            if ( $shuffle_options && count( $choices ) > 1 ) {
                // Seeded on the attempt so a refresh shows the same order. A student
                // who memorised "the second one" must not be punished for reloading.
                mt_srand( $attempt_id * 1000 + $question_id );
                shuffle( $choices );
                mt_srand();
            }

            $delivered[] = [
                'number'     => $position++,
                'id'         => $question_id,
                'text'       => (string) $question['question_text'],
                'image'      => (string) $question['image_reference'],
                'type'       => (string) $question['question_type'],
                'marks'      => (float) $question['marks'],
                'passage_id' => absint( $question['passage_id'] ),
                'options'    => $choices,
            ];
        }

        return [
            'questions' => $delivered,
            'passages'  => ( new PassageService() )->for_paper( $school_id, absint( $attempt['paper_id'] ) ),
            'answers'   => $this->saved_answers( $school_id, $attempt_id ),
            'timer'     => $this->clock->client_payload( $this->clock_shape( $attempt, $paper ), $paper ),
            'allow_review' => ! empty( $paper['allow_review'] ),
        ];
    }

    /**
     * Answers already saved, so a resumed attempt restores the grid.
     *
     * @return array<int,int> question_id => option_id
     */
    public function saved_answers( int $school_id, int $attempt_id ): array {
        global $wpdb;

        // answer_text is fetched too: a written answer resumes as the text the
        // candidate typed, not as an option id they never chose.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT question_id, option_id, answer_text FROM ' . Schema::table( 'attempt_answers' ) .
                ' WHERE attempt_id = %d AND school_id = %d',
                $attempt_id,
                $school_id
            ),
            ARRAY_A
        );

        $answers = [];

        foreach ( $rows as $row ) {
            $question_id = absint( $row['question_id'] );

            if ( $row['option_id'] !== null && absint( $row['option_id'] ) > 0 ) {
                $answers[ $question_id ] = absint( $row['option_id'] );
                continue;
            }

            $text = (string) ( $row['answer_text'] ?? '' );

            if ( $text !== '' ) {
                $answers[ $question_id ] = $text;
            }
        }

        return $answers;
    }

    // ---------------------------------------------------------------
    // Answering
    // ---------------------------------------------------------------

    /**
     * Record one answer.
     *
     * One row, one upsert, no read-modify-write. This is the shape that survives two
     * concurrent saves — the failure Phase 1 demonstrated, where a shared JSON blob
     * silently loses an answer.
     *
     * `is_correct` is deliberately NOT computed here. Marking during the exam would
     * put the answer key one timing attack away, and would mean a mid-exam edit to a
     * question could not be reflected in the final mark.
     *
     * @return array{success:bool,timer?:array<string,mixed>,reason?:string}
     */
    public function save_answer( int $school_id, int $attempt_id, int $question_id, ?int $option_id, string $session_token = '' ): array {
        global $wpdb;

        $attempt = $this->get( $school_id, $attempt_id );

        if ( ! $attempt ) {
            return [ 'success' => false, 'reason' => 'attempt_not_found' ];
        }

        if ( $session_token !== '' && ! hash_equals( (string) $attempt['session_token'], $session_token ) ) {
            $this->log_event( $school_id, $attempt_id, 'second_session', [] );
            return [ 'success' => false, 'reason' => 'session_superseded' ];
        }

        if ( (string) $attempt['status'] !== self::STATUS_IN_PROGRESS ) {
            return [ 'success' => false, 'reason' => 'attempt_closed' ];
        }

        $paper = $this->paper( $school_id, absint( $attempt['paper_id'] ) );

        if ( $this->clock->has_expired( $this->clock_shape( $attempt, $paper ), $paper ) ) {
            $this->close( $school_id, $attempt_id, self::REASON_TIMEOUT );
            return [ 'success' => false, 'reason' => 'time_expired' ];
        }

        // A question not on this paper must not create a row.
        if ( ! $this->question_on_attempt( $attempt, $question_id ) ) {
            return [ 'success' => false, 'reason' => 'question_not_on_this_paper' ];
        }

        $table = Schema::table( 'attempt_answers' );

        if ( $option_id === null || $option_id <= 0 ) {
            // Clearing an answer is a legitimate act; a student may want to leave a
            // question blank rather than guess.
            $wpdb->delete( $table, [ 'attempt_id' => $attempt_id, 'question_id' => $question_id ], [ '%d', '%d' ] );
        } else {
            if ( ! $this->option_belongs_to_question( $option_id, $question_id ) ) {
                return [ 'success' => false, 'reason' => 'option_does_not_belong_to_question' ];
            }

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (school_id, attempt_id, question_id, option_id, answered_at)
                     VALUES (%d, %d, %d, %d, %s)
                     ON DUPLICATE KEY UPDATE option_id = VALUES(option_id), answered_at = VALUES(answered_at)",
                    $school_id,
                    $attempt_id,
                    $question_id,
                    $option_id,
                    current_time( 'mysql', true )
                )
            );
        }

        return [
            'success' => true,
            'timer'   => $this->clock->client_payload( $this->clock_shape( $attempt, $paper ), $paper ),
        ];
    }

    /**
     * Save several answers at once. The client batches during a reconnect, so a
     * student who answered five questions offline does not fire five requests at a
     * connection that has only just come back.
     *
     * @param array<int,int|null> $answers question_id => option_id
     * @return array{saved:int,rejected:int,timer:array<string,mixed>}
     */
    public function save_answers( int $school_id, int $attempt_id, array $answers, string $session_token = '' ): array {
        $saved    = 0;
        $rejected = 0;
        $timer    = [];

        foreach ( $answers as $question_id => $option_id ) {
            $result = $this->save_answer(
                $school_id,
                $attempt_id,
                absint( $question_id ),
                $option_id === null ? null : absint( $option_id ),
                $session_token
            );

            if ( ! empty( $result['success'] ) ) {
                $saved++;
                $timer = $result['timer'] ?? $timer;
            } else {
                $rejected++;
            }
        }

        return [ 'saved' => $saved, 'rejected' => $rejected, 'timer' => $timer ];
    }

    // ---------------------------------------------------------------
    // Submission
    // ---------------------------------------------------------------

    /**
     * Submit. Cheap on purpose.
     *
     * Every student in a hall submits within the same few seconds when the clock
     * runs out. Grading three hundred papers inside three hundred simultaneous
     * requests is how a CBT system falls over at the exact moment it must not, so
     * this writes a status and returns. GradingWorker does the marking.
     *
     * @return array{success:bool,status?:string,reason?:string}
     */
    public function submit( int $school_id, int $attempt_id, string $reason = self::REASON_MANUAL, string $session_token = '' ): array {
        $attempt = $this->get( $school_id, $attempt_id );

        if ( ! $attempt ) {
            return [ 'success' => false, 'reason' => 'attempt_not_found' ];
        }

        if ( $session_token !== '' && ! hash_equals( (string) $attempt['session_token'], $session_token ) ) {
            return [ 'success' => false, 'reason' => 'session_superseded' ];
        }

        // Submitting twice is not an error. A student who taps Submit and then loses
        // the connection will tap it again, and must not see a failure.
        if ( (string) $attempt['status'] !== self::STATUS_IN_PROGRESS ) {
            return [ 'success' => true, 'status' => (string) $attempt['status'] ];
        }

        $paper = $this->paper( $school_id, absint( $attempt['paper_id'] ) );

        // A submission in flight when the clock hit zero must not cost the student
        // their answers; ExamClock allows a short grace window.
        if ( $reason === self::REASON_MANUAL && ! $this->clock->accepts_submission( $this->clock_shape( $attempt, $paper ), $paper ) ) {
            $reason = self::REASON_TIMEOUT;
        }

        $this->close( $school_id, $attempt_id, $reason );

        return [ 'success' => true, 'status' => self::STATUS_SUBMITTED ];
    }

    /**
     * Move an attempt to submitted. Idempotent — the WHERE clause means a second
     * call changes nothing.
     */
    public function close( int $school_id, int $attempt_id, string $reason ): bool {
        global $wpdb;

        $table = Schema::table( 'attempts' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, submitted_at = %s, submit_reason = %s
                 WHERE id = %d AND school_id = %d AND status = %s",
                self::STATUS_SUBMITTED,
                current_time( 'mysql', true ),
                $reason,
                $attempt_id,
                $school_id,
                self::STATUS_IN_PROGRESS
            )
        );

        if ( $updated ) {
            EventDispatcher::action( 'educbt_attempt_submitted', [
                'school_id'  => $school_id,
                'attempt_id' => $attempt_id,
                'reason'     => $reason,
            ] );
        }

        return (bool) $updated;
    }

    // ---------------------------------------------------------------
    // Integrity events — three signals, each with a defined response
    // ---------------------------------------------------------------

    public function log_event( int $school_id, int $attempt_id, string $type, array $payload = [] ): void {
        // Deliberately narrow. v1 logged undefined event types to nowhere and nobody
        // ever read them. These three each drive something visible.
        $allowed = [ 'window_blur', 'second_session', 'resumed' ];

        if ( ! in_array( $type, $allowed, true ) ) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            Schema::table( 'attempt_events' ),
            [
                'school_id'  => $school_id,
                'attempt_id' => $attempt_id,
                'event_type' => $type,
                'payload'    => (string) wp_json_encode( $payload ),
            ],
            [ '%d', '%d', '%s', '%s' ]
        );

        // A resume is normal on a Nigerian connection and carries no penalty, so it
        // is logged but not counted against the student.
        if ( $type === 'resumed' ) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::table( 'attempts' ) . ' SET flag_count = flag_count + 1 WHERE id = %d',
                $attempt_id
            )
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function get( int $school_id, int $attempt_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'attempts' ) . ' WHERE id = %d AND school_id = %d',
                $attempt_id,
                $school_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    private function paper( int $school_id, int $paper_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'exam_papers' ) . ' WHERE id = %d AND school_id = %d',
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : [];
    }

    /**
     * ExamClock was written against the v1 column names. Rather than duplicate the
     * timing logic, translate the v2 row into the shape it expects.
     *
     * @return array<string,mixed>
     */
    private function clock_shape( array $attempt, array $paper ): array {
        return [
            'id'                => absint( $attempt['id'] ?? 0 ),
            'status'            => (string) ( $attempt['status'] ?? '' ),
            'time_started'      => (string) ( $attempt['started_at'] ?? '' ),
            'extension_seconds' => absint( $attempt['extension_seconds'] ?? 0 ),
            'exam_id'           => absint( $paper['id'] ?? 0 ),
        ];
    }

    private function hydrate( int $school_id, int $attempt_id ): array {
        $attempt = $this->get( $school_id, $attempt_id );

        if ( ! $attempt ) {
            return [];
        }

        $paper = $this->paper( $school_id, absint( $attempt['paper_id'] ) );

        return [
            'id'            => $attempt_id,
            'paper_id'      => absint( $attempt['paper_id'] ),
            'session_token' => (string) $attempt['session_token'],
            'status'        => (string) $attempt['status'],
            'timer'         => $this->clock->client_payload( $this->clock_shape( $attempt, $paper ), $paper ),
        ];
    }

    private function question_on_attempt( array $attempt, int $question_id ): bool {
        $order = json_decode( (string) $attempt['question_order'], true );

        return is_array( $order ) && in_array( $question_id, array_map( 'absint', $order ), true );
    }

    private function option_belongs_to_question( int $option_id, int $question_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'question_options' ) . ' WHERE id = %d AND question_id = %d',
                $option_id,
                $question_id
            )
        );
    }

    /**
     * Progress for the student's own answer grid: which numbers are answered.
     *
     * @return array{answered:int,total:int}
     */
    public function progress( int $school_id, int $attempt_id ): array {
        $attempt = $this->get( $school_id, $attempt_id );

        if ( ! $attempt ) {
            return [ 'answered' => 0, 'total' => 0 ];
        }

        $order = json_decode( (string) $attempt['question_order'], true );

        return [
            'answered' => count( $this->saved_answers( $school_id, $attempt_id ) ),
            'total'    => is_array( $order ) ? count( $order ) : 0,
        ];
    }
}
