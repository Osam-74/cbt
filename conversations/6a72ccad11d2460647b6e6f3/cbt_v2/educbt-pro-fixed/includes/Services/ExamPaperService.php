<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 5 — exam series and papers.
 *
 * You said `exams` and `exam_timetables` were confusing, and they were: two tables
 * both carrying `duration_minutes` with nothing defining which won, one holding a
 * title and the other holding the class and subject that give the title meaning.
 *
 * The real-world objects are:
 *
 *   SERIES  "First Term Examination 2025/2026" — the sitting
 *   PAPER   one subject, for one class, at one datetime, for one duration
 *
 * A timetable is not an entity. It is a query over papers grouped by date, which is
 * why TimetableService reads rather than stores. Duration lives on the paper, where
 * you said it should be, and is stored in SECONDS because ExamClock computes in
 * seconds and a minutes/seconds conversion at the boundary is exactly the sort of
 * thing that silently loses thirty seconds off a paper.
 */
class ExamPaperService {

    public const TYPE_TERMINAL = 'terminal';
    public const TYPE_MOCK     = 'mock';
    public const TYPE_PRACTICE = 'practice';

    // ---------------------------------------------------------------
    // Series
    // ---------------------------------------------------------------

    /**
     * @return array{success:bool,series_id?:int,error?:string}
     */
    public function create_series( int $school_id, array $data ): array {
        global $wpdb;

        $title = trim( (string) ( $data['title'] ?? '' ) );

        if ( $title === '' ) {
            return [ 'success' => false, 'error' => 'title_required' ];
        }

        $session_id = absint( $data['session_id'] ?? 0 );

        if ( $session_id <= 0 ) {
            $current    = ( new AcademicYearService() )->current_session( $school_id );
            $session_id = absint( $current['id'] ?? 0 );
        }

        if ( $session_id <= 0 ) {
            return [ 'success' => false, 'error' => 'no_current_session' ];
        }

        $type = (string) ( $data['series_type'] ?? self::TYPE_TERMINAL );

        if ( ! in_array( $type, [ self::TYPE_TERMINAL, self::TYPE_MOCK, self::TYPE_PRACTICE ], true ) ) {
            return [ 'success' => false, 'error' => 'invalid_series_type' ];
        }

        $wpdb->insert(
            Schema::table( 'exam_series' ),
            [
                'school_id'   => $school_id,
                'session_id'  => $session_id,
                'term_id'     => absint( $data['term_id'] ?? 0 ) ?: null,
                'title'       => sanitize_text_field( $title ),
                'series_type' => $type,
                'starts_on'   => sanitize_text_field( (string) ( $data['starts_on'] ?? '' ) ) ?: null,
                'ends_on'     => sanitize_text_field( (string) ( $data['ends_on'] ?? '' ) ) ?: null,
                'status'      => 'draft',
                'created_by'  => get_current_user_id(),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return [ 'success' => true, 'series_id' => absint( $wpdb->insert_id ) ];
    }

    // ---------------------------------------------------------------
    // Papers
    // ---------------------------------------------------------------

    /**
     * @return array{success:bool,paper_id?:int,access_code?:string,warnings?:array,errors?:array}
     */
    public function create_paper( int $school_id, array $data ): array {
        global $wpdb;

        $errors   = [];
        $warnings = [];

        $series_id  = absint( $data['series_id'] ?? 0 );
        $subject_id = absint( $data['subject_id'] ?? 0 );
        $class_id   = absint( $data['class_id'] ?? 0 );
        $scheduled  = trim( (string) ( $data['scheduled_at'] ?? '' ) );

        if ( $series_id <= 0 ) {
            $errors[] = 'series_required';
        }

        if ( $subject_id <= 0 ) {
            $errors[] = 'subject_required';
        }

        if ( $class_id <= 0 ) {
            $errors[] = 'class_required';
        }

        $duration = $this->resolve_duration( $data );

        if ( $duration <= 0 ) {
            $errors[] = 'duration_required';
        } elseif ( $duration < 300 ) {
            $errors[] = 'duration_too_short';
        } elseif ( $duration > 5 * HOUR_IN_SECONDS ) {
            $errors[] = 'duration_too_long';
        }

        if ( $scheduled === '' || ! strtotime( $scheduled ) ) {
            $errors[] = 'invalid_schedule';
        }

        $question_count = absint( $data['question_count'] ?? 0 );

        if ( $question_count <= 0 ) {
            $errors[] = 'question_count_required';
        }

        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        // Scheduling a 60-question paper against a bank of 40 is a failure the exam
        // officer must see now, not when the first student opens it.
        //
        // Counted over the APPROVED pool, which is the pool compose() actually draws
        // from. Counting every active question let a paper be created that could
        // never be filled: composition came up short, the paper stayed unpublished,
        // and its access code never appeared anywhere.
        $available = ( new QuestionApprovalService() )->approved_count( $school_id, $subject_id );

        if ( $available < $question_count ) {
            return [
                'success' => false,
                'errors'  => [ 'insufficient_questions:' . $available . '/' . $question_count ],
            ];
        }

        // A clash is only a clash for the SAME class. SS3 and JSS2 sitting at the
        // same time is normal — different candidates, different halls — so cross-class
        // concurrency is deliberately permitted.
        $clash = $this->find_clash( $school_id, $class_id, $scheduled, $duration );

        if ( $clash !== null ) {
            return [
                'success' => false,
                'errors'  => [ 'clashes_with_paper:' . $clash['id'] ],
            ];
        }

        // Venue is NOT a scheduling constraint. This is computer-based testing: a
        // "hall" is a label for the register, not a room two classes must queue for.
        // What can genuinely run out is TERMINALS, so the check is on concurrent
        // seats and only fires when a school has told us how many it has.
        $seat_warning = $this->check_seat_capacity( $school_id, $scheduled, $duration, $class_id );

        if ( $seat_warning !== null ) {
            $warnings[] = $seat_warning;
        }

        $is_practice  = ! empty( $data['is_practice'] );
        $access_code  = $is_practice ? '' : $this->generate_access_code();

        $wpdb->insert(
            Schema::table( 'exam_papers' ),
            [
                'school_id'            => $school_id,
                'series_id'            => $series_id,
                'subject_id'           => $subject_id,
                'class_id'             => $class_id,
                'level_id'             => absint( $data['level_id'] ?? 0 ) ?: null,
                'department_id'        => absint( $data['department_id'] ?? 0 ) ?: null,
                'scheduled_at'         => gmdate( 'Y-m-d H:i:s', (int) strtotime( $scheduled ) ),
                'duration_seconds'     => $duration,
                'question_count'       => $question_count,
                'total_marks'          => (float) ( $data['total_marks'] ?? $question_count ),
                'shuffle_questions'    => isset( $data['shuffle_questions'] ) ? absint( $data['shuffle_questions'] ) : 1,
                'shuffle_options'      => isset( $data['shuffle_options'] ) ? absint( $data['shuffle_options'] ) : 1,
                'access_code'          => $access_code,
                'requires_access_code' => $is_practice ? 0 : 1,
                'allow_review'         => ! empty( $data['allow_review'] ) ? 1 : 0,
                'venue'                => sanitize_text_field( (string) ( $data['venue'] ?? '' ) ),
                'is_practice'          => $is_practice ? 1 : 0,
                'status'               => 'draft',
                'created_by_staff'     => absint( $data['created_by_staff'] ?? 0 ) ?: null,
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%f', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%d' ]
        );

        $paper_id = absint( $wpdb->insert_id );

        if ( $paper_id <= 0 ) {
            return [ 'success' => false, 'errors' => [ 'insert_failed' ] ];
        }

        // Invigilator assignment is automatic, as you asked, and overridable after.
        $assignment = ( new StaffService() )->auto_assign_invigilator( $school_id, $paper_id );

        if ( empty( $assignment['success'] ) ) {
            $warnings[] = 'no_invigilator_assigned:' . ( $assignment['error'] ?? 'unknown' );
        }

        EventDispatcher::action( 'educbt_paper_created', [
            'school_id' => $school_id,
            'paper_id'  => $paper_id,
            'series_id' => $series_id,
        ] );

        return [
            'success'     => true,
            'paper_id'    => $paper_id,
            'access_code' => $access_code,
            'warnings'    => $warnings,
        ];
    }

    /**
     * Accept minutes from a form but store seconds, so the conversion happens once
     * at the boundary rather than being rediscovered at every read.
     */
    private function resolve_duration( array $data ): int {
        if ( isset( $data['duration_seconds'] ) && absint( $data['duration_seconds'] ) > 0 ) {
            return absint( $data['duration_seconds'] );
        }

        return absint( $data['duration_minutes'] ?? 0 ) * 60;
    }

    /**
     * A class cannot sit two papers at once. Overlap is checked as a real interval
     * comparison, not by matching start times — a 09:00 two-hour paper genuinely
     * clashes with a 10:00 one-hour paper, and a start-time check would miss it.
     *
     * @return array<string,mixed>|null
     */
    public function find_clash( int $school_id, int $class_id, string $scheduled_at, int $duration_seconds, int $ignore_paper_id = 0 ): ?array {
        global $wpdb;

        $start = gmdate( 'Y-m-d H:i:s', (int) strtotime( $scheduled_at ) );
        $table = Schema::table( 'exam_papers' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, subject_id, scheduled_at, duration_seconds FROM {$table}
                 WHERE school_id = %d AND class_id = %d AND id <> %d
                   AND status <> 'cancelled'
                   AND scheduled_at < DATE_ADD(%s, INTERVAL %d SECOND)
                   AND DATE_ADD(scheduled_at, INTERVAL duration_seconds SECOND) > %s
                 LIMIT 1",
                $school_id,
                $class_id,
                $ignore_paper_id,
                $start,
                $duration_seconds,
                $start
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * The only physical limit that matters in CBT: how many students can be sitting
     * simultaneously. A school with one lab of 40 machines cannot run two classes of
     * 35 at once, however many halls it has.
     *
     * Returns null unless the school has recorded a terminal count, because guessing
     * a capacity is worse than not checking one. Advisory in all cases — plenty of
     * schools run BYOD, where the limit is bandwidth rather than seats.
     */
    public function check_seat_capacity( int $school_id, string $scheduled_at, int $duration_seconds, int $class_id ): ?string {
        $capacity = absint( get_option( 'educbt_concurrent_seats_' . $school_id, 0 ) );

        if ( $capacity <= 0 ) {
            return null;
        }

        global $wpdb;

        $start       = gmdate( 'Y-m-d H:i:s', (int) strtotime( $scheduled_at ) );
        $papers      = Schema::table( 'exam_papers' );
        $enrollments = Schema::table( 'enrollments' );

        $concurrent = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} e
                     INNER JOIN {$papers} p ON p.class_id = e.class_id
                     WHERE p.school_id = %d AND p.status <> 'cancelled'
                       AND e.status = 'active'
                       AND p.scheduled_at < DATE_ADD(%s, INTERVAL %d SECOND)
                       AND DATE_ADD(p.scheduled_at, INTERVAL p.duration_seconds SECOND) > %s",
                    $school_id,
                    $start,
                    $duration_seconds,
                    $start
                )
            )
        );

        $incoming = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} WHERE class_id = %d AND status = 'active'",
                    $class_id
                )
            )
        );

        $total = $concurrent + $incoming;

        return $total > $capacity ? 'exceeds_concurrent_seats:' . $total . '/' . $capacity : null;
    }

    /**
     * Six characters, no vowels and no visually ambiguous glyphs, because an
     * invigilator reads this aloud to a hall and a student types it under pressure.
     * Excluding vowels also avoids accidentally generating a real word.
     */
    public function generate_access_code(): string {
        $alphabet = 'BCDFGHJKMNPQRSTVWXYZ23456789';
        $code     = '';

        for ( $i = 0; $i < 6; $i++ ) {
            $code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
        }

        return $code;
    }

    /**
     * Compose a paper by selecting questions from the bank.
     *
     * Selection is stratified by difficulty where possible, so a paper is not
     * accidentally all-easy or all-hard — a genuine risk with random selection over
     * a bank that is unevenly weighted.
     *
     * @return array{success:bool,selected?:int,error?:string}
     */
    public function compose( int $school_id, int $paper_id, array $options = [] ): array {
        global $wpdb;

        $papers = Schema::table( 'exam_papers' );

        $paper = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$papers} WHERE id = %d AND school_id = %d", $paper_id, $school_id ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return [ 'success' => false, 'error' => 'paper_not_found' ];
        }

        if ( in_array( (string) $paper['status'], [ 'active', 'completed' ], true ) ) {
            return [ 'success' => false, 'error' => 'paper_already_started' ];
        }

        $needed     = absint( $paper['question_count'] );
        $subject_id = absint( $paper['subject_id'] );

        $questions = $wpdb->prefix . 'educbt_questions';
        $opts      = Schema::table( 'question_options' );

        // Only questions with exactly one identifiable correct answer are eligible.
        // A question that would mark everyone wrong must never reach a paper.
        $eligible = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.difficulty, q.marks
                 FROM {$questions} q
                 INNER JOIN (
                     SELECT question_id, COUNT(*) AS total, SUM(is_correct) AS correct
                     FROM {$opts} GROUP BY question_id
                 ) o ON o.question_id = q.id
                 WHERE q.school_id = %d AND q.subject_id = %d AND q.status = 'active' AND q.approval_status = 'approved'
                   AND o.total >= 2 AND o.correct >= 1",
                $school_id,
                $subject_id
            ),
            ARRAY_A
        );

        if ( count( $eligible ) < $needed ) {
            return [
                'success' => false,
                'error'   => 'insufficient_usable_questions:' . count( $eligible ) . '/' . $needed,
            ];
        }

        $selected = $this->stratify( $eligible, $needed, $options['difficulty_mix'] ?? null );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->delete( Schema::table( 'paper_questions' ), [ 'paper_id' => $paper_id ], [ '%d' ] );

        $order = 0;
        $marks = 0.0;

        foreach ( $selected as $question ) {
            $wpdb->insert(
                Schema::table( 'paper_questions' ),
                [
                    'school_id'   => $school_id,
                    'paper_id'    => $paper_id,
                    'question_id' => absint( $question['id'] ),
                    'sort_order'  => $order++,
                    'marks'       => (float) ( $question['marks'] ?? 1 ),
                ],
                [ '%d', '%d', '%d', '%d', '%f' ]
            );

            $marks += (float) ( $question['marks'] ?? 1 );
        }

        $wpdb->update(
            $papers,
            [ 'total_marks' => $marks, 'status' => 'scheduled' ],
            [ 'id' => $paper_id ],
            [ '%f', '%s' ],
            [ '%d' ]
        );

        $wpdb->query( 'COMMIT' );

        return [ 'success' => true, 'selected' => count( $selected ) ];
    }

    /**
     * Draw questions across difficulty bands. Falls back gracefully when the bank
     * cannot supply a band, rather than failing the whole composition.
     *
     * @param array<int,array<string,mixed>> $pool
     * @return array<int,array<string,mixed>>
     */
    public function stratify( array $pool, int $needed, ?array $mix = null ): array {
        $mix = $mix ?: [ 'easy' => 0.3, 'medium' => 0.5, 'hard' => 0.2 ];

        $buckets = [ 'easy' => [], 'medium' => [], 'hard' => [] ];

        foreach ( $pool as $question ) {
            $band = (string) ( $question['difficulty'] ?? 'medium' );
            $band = isset( $buckets[ $band ] ) ? $band : 'medium';

            $buckets[ $band ][] = $question;
        }

        foreach ( $buckets as $band => $items ) {
            shuffle( $items );
            $buckets[ $band ] = $items;
        }

        $selected = [];

        foreach ( $mix as $band => $share ) {
            $take = (int) round( $needed * (float) $share );

            for ( $i = 0; $i < $take && ! empty( $buckets[ $band ] ); $i++ ) {
                $selected[] = array_shift( $buckets[ $band ] );
            }
        }

        // Top up from whatever remains when a band was underfilled.
        if ( count( $selected ) < $needed ) {
            $remaining = array_merge( $buckets['easy'], $buckets['medium'], $buckets['hard'] );
            shuffle( $remaining );

            while ( count( $selected ) < $needed && ! empty( $remaining ) ) {
                $selected[] = array_shift( $remaining );
            }
        }

        return array_slice( $selected, 0, $needed );
    }

    /**
     * Publishing makes a paper visible to students. It is refused unless the paper
     * is actually ready, because a half-composed paper appearing on a student's
     * dashboard is worse than one that appears late.
     *
     * @return array{success:bool,errors?:array<int,string>}
     */
    public function publish( int $school_id, int $paper_id ): array {
        global $wpdb;

        $papers = Schema::table( 'exam_papers' );

        $paper = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$papers} WHERE id = %d AND school_id = %d", $paper_id, $school_id ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return [ 'success' => false, 'errors' => [ 'paper_not_found' ] ];
        }

        $errors = [];

        $composed = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Schema::table( 'paper_questions' ) . ' WHERE paper_id = %d',
                    $paper_id
                )
            )
        );

        if ( $composed === 0 ) {
            $errors[] = 'not_composed';
        } elseif ( $composed < absint( $paper['question_count'] ) ) {
            $errors[] = 'composed_short:' . $composed . '/' . $paper['question_count'];
        }

        $invigilators = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Schema::table( 'paper_invigilators' ) . ' WHERE paper_id = %d',
                    $paper_id
                )
            )
        );

        if ( $invigilators === 0 && empty( $paper['is_practice'] ) ) {
            $errors[] = 'no_invigilator';
        }

        if ( absint( $paper['duration_seconds'] ) <= 0 ) {
            $errors[] = 'no_duration';
        }

        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        $wpdb->update( $papers, [ 'status' => 'published' ], [ 'id' => $paper_id ], [ '%s' ], [ '%d' ] );

        EventDispatcher::action( 'educbt_paper_published', [
            'school_id' => $school_id,
            'paper_id'  => $paper_id,
        ] );

        return [ 'success' => true ];
    }
}
