<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\ExamClock;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 6 — the invigilator's screen.
 *
 * This is where the integrity work pays off. v1 wrote undefined event types to a
 * table nobody read; the value was never in the log, it was in a person watching a
 * hall in real time and being able to act.
 *
 * So this service answers the four questions an invigilator actually has:
 *
 *   Who has started, and who has not?
 *   Is anyone stuck or disconnected?
 *   Who is flagged, and for what?
 *   How much time is left, per student?
 *
 * And gives them the three interventions they actually need: release the access
 * code, grant extra time, force a submission.
 *
 * Every intervention is audit-logged with the acting staff member, because "the
 * system gave him extra time" is not an answer a principal can give a parent.
 */
class InvigilatorService {

    /** A student who has not saved an answer in this long is likely disconnected. */
    public const STALE_SECONDS = 120;

    private ExamClock $clock;

    public function __construct( ?ExamClock $clock = null ) {
        $this->clock = $clock ?? new ExamClock();
    }

    /**
     * The live board for one paper.
     *
     * Deliberately one query plus one pass. This is polled every few seconds by
     * every invigilator in the building while the hall is also autosaving, so it
     * must not be expensive.
     *
     * @return array{paper:array<string,mixed>,summary:array<string,int>,students:array<int,array<string,mixed>>}
     */
    public function board( int $school_id, int $paper_id ): array {
        global $wpdb;

        $papers = Schema::table( 'exam_papers' );

        $paper = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$papers} WHERE id = %d AND school_id = %d", $paper_id, $school_id ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return [ 'paper' => [], 'summary' => [], 'students' => [] ];
        }

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );
        $attempts    = Schema::table( 'attempts' );
        $answers     = Schema::table( 'attempt_answers' );

        // Every student expected to sit this paper, whether or not they have started.
        // "Who has NOT started" is the question an invigilator asks first, and a
        // query over attempts alone could never answer it.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id AS student_id, st.admission_number, st.first_name, st.last_name,
                        a.id AS attempt_id, a.status, a.started_at, a.submitted_at,
                        a.submit_reason, a.extension_seconds, a.flag_count,
                        a.raw_score, a.percentage,
                        (SELECT COUNT(*) FROM {$answers} ans WHERE ans.attempt_id = a.id) AS answered,
                        (SELECT MAX(ans2.updated_at) FROM {$answers} ans2 WHERE ans2.attempt_id = a.id) AS last_activity
                 FROM {$enrollments} e
                 INNER JOIN {$students} st ON st.id = e.student_id
                 INNER JOIN {$registered} rs ON rs.student_id = st.id
                        AND rs.subject_id = %d AND rs.session_id = e.session_id
                 LEFT JOIN {$attempts} a ON a.paper_id = %d AND a.student_id = st.id
                 WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active'
                 ORDER BY st.last_name ASC, st.first_name ASC",
                absint( $paper['subject_id'] ),
                $paper_id,
                $school_id,
                absint( $paper['class_id'] )
            ),
            ARRAY_A
        );

        $total_questions = absint( $paper['question_count'] );
        $now             = $this->clock->now();

        $summary = [
            'expected'     => 0,
            'not_started'  => 0,
            'in_progress'  => 0,
            'submitted'    => 0,
            'disconnected' => 0,
            'flagged'      => 0,
        ];

        $board = [];

        foreach ( $rows as $row ) {
            $summary['expected']++;

            $attempt_id = absint( $row['attempt_id'] );
            $status     = (string) ( $row['status'] ?? '' );

            $entry = [
                'student_id'       => absint( $row['student_id'] ),
                'admission_number' => (string) $row['admission_number'],
                'name'             => trim( $row['first_name'] . ' ' . $row['last_name'] ),
                'attempt_id'       => $attempt_id,
                'answered'         => absint( $row['answered'] ),
                'total'            => $total_questions,
                'flags'            => absint( $row['flag_count'] ),
                'extension_minutes' => (int) round( absint( $row['extension_seconds'] ) / 60 ),
                'state'            => 'not_started',
                'remaining_seconds' => null,
                'disconnected'     => false,
            ];

            if ( $attempt_id === 0 ) {
                $summary['not_started']++;
                $board[] = $entry;
                continue;
            }

            if ( $status === AttemptService::STATUS_IN_PROGRESS ) {
                $summary['in_progress']++;
                $entry['state'] = 'in_progress';

                $entry['remaining_seconds'] = $this->clock->remaining_seconds(
                    [
                        'time_started'      => (string) $row['started_at'],
                        'extension_seconds' => absint( $row['extension_seconds'] ),
                    ],
                    $paper
                );

                // Silence is the only disconnection signal available — a dropped
                // browser cannot send anything to say it dropped.
                $last = $row['last_activity'] ?: $row['started_at'];
                $seen = $this->clock->to_timestamp( (string) $last );

                if ( $seen !== null && ( $now - $seen ) > self::STALE_SECONDS ) {
                    $entry['disconnected'] = true;
                    $summary['disconnected']++;
                }
            } else {
                $summary['submitted']++;
                $entry['state']         = $status;
                $entry['submit_reason'] = (string) $row['submit_reason'];
                $entry['percentage']    = $row['percentage'] !== null ? (float) $row['percentage'] : null;
            }

            if ( absint( $row['flag_count'] ) > 0 ) {
                $summary['flagged']++;
            }

            $board[] = $entry;
        }

        return [
            'paper'    => [
                'id'               => $paper_id,
                'scheduled_at'     => (string) $paper['scheduled_at'],
                'duration_seconds' => absint( $paper['duration_seconds'] ),
                'question_count'   => $total_questions,
                'access_code'      => (string) $paper['access_code'],
                'code_released'    => $this->code_released( $paper_id ),
            ],
            'summary'  => $summary,
            'students' => $board,
        ];
    }

    // ---------------------------------------------------------------
    // Access code release
    // ---------------------------------------------------------------

    /**
     * The code exists so a paper cannot be opened early or from home. It is
     * therefore hidden from the invigilator's own screen until they release it,
     * which timestamps who revealed it and when.
     */
    public function release_code( int $school_id, int $paper_id, int $staff_id ): array {
        update_option(
            'educbt_code_released_' . $paper_id,
            [ 'at' => $this->clock->now(), 'by' => $staff_id ],
            false
        );

        EventDispatcher::action( 'educbt_access_code_released', [
            'school_id' => $school_id,
            'paper_id'  => $paper_id,
            'staff_id'  => $staff_id,
        ] );

        return [ 'success' => true ];
    }

    public function code_released( int $paper_id ): bool {
        return (bool) get_option( 'educbt_code_released_' . $paper_id, false );
    }

    // ---------------------------------------------------------------
    // Interventions
    // ---------------------------------------------------------------

    /**
     * Grant extra time.
     *
     * Two real cases: a documented accessibility need, and a technical fault that
     * cost a student minutes through no fault of their own. Both are legitimate and
     * both must be recorded with a reason, because unexplained extra time is
     * indistinguishable from favouritism.
     *
     * The extension is ADDITIVE and applies to the whole attempt, so it works
     * whether granted before the student starts or in the last minute.
     *
     * @return array{success:bool,extension_seconds?:int,error?:string}
     */
    public function grant_extension( int $school_id, int $attempt_id, int $minutes, int $staff_id, string $reason ): array {
        global $wpdb;

        if ( $minutes <= 0 || $minutes > 120 ) {
            return [ 'success' => false, 'error' => 'implausible_extension' ];
        }

        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $table = Schema::table( 'attempts' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET extension_seconds = extension_seconds + %d
                 WHERE id = %d AND school_id = %d",
                $minutes * 60,
                $attempt_id,
                $school_id
            )
        );

        if ( ! $updated ) {
            return [ 'success' => false, 'error' => 'attempt_not_found' ];
        }

        $total = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT extension_seconds FROM {$table} WHERE id = %d", $attempt_id )
            )
        );

        EventDispatcher::action( 'educbt_extension_granted', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'minutes'    => $minutes,
            'staff_id'   => $staff_id,
            'reason'     => sanitize_text_field( $reason ),
        ] );

        return [ 'success' => true, 'extension_seconds' => $total ];
    }

    /**
     * Extend every in-progress attempt on a paper at once.
     *
     * This is the one that matters in practice: the power went out for six minutes
     * and the whole hall needs those six minutes back. Doing it per student, under
     * pressure, with students watching a running clock, is not workable.
     */
    public function extend_paper( int $school_id, int $paper_id, int $minutes, int $staff_id, string $reason ): array {
        global $wpdb;

        if ( $minutes <= 0 || $minutes > 120 ) {
            return [ 'success' => false, 'error' => 'implausible_extension' ];
        }

        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $table = Schema::table( 'attempts' );

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET extension_seconds = extension_seconds + %d
                 WHERE school_id = %d AND paper_id = %d AND status = %s",
                $minutes * 60,
                $school_id,
                $paper_id,
                AttemptService::STATUS_IN_PROGRESS
            )
        );

        EventDispatcher::action( 'educbt_paper_extended', [
            'school_id' => $school_id,
            'paper_id'  => $paper_id,
            'minutes'   => $minutes,
            'attempts'  => absint( $affected ),
            'staff_id'  => $staff_id,
            'reason'    => sanitize_text_field( $reason ),
        ] );

        return [ 'success' => true, 'attempts_extended' => absint( $affected ) ];
    }

    /**
     * Force a submission — a student who walked out, or a machine that has to be
     * freed for the next session. Their saved answers are marked as they stand.
     */
    public function force_submit( int $school_id, int $attempt_id, int $staff_id, string $reason ): array {
        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $closed = ( new AttemptService() )->close( $school_id, $attempt_id, AttemptService::REASON_FORCED );

        EventDispatcher::action( 'educbt_attempt_force_submitted', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'staff_id'   => $staff_id,
            'reason'     => sanitize_text_field( $reason ),
        ] );

        return [ 'success' => $closed ];
    }

    /**
     * Reopen an attempt closed in error.
     *
     * Necessarily a narrow power: it is the only way to undo a mistaken force-submit,
     * and equally the only way to give one student a second look at a paper. It is
     * therefore restricted to the paper's own window — an attempt cannot be reopened
     * after the sitting has ended — and is loudly audited.
     */
    public function reopen( int $school_id, int $attempt_id, int $staff_id, string $reason ): array {
        global $wpdb;

        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $attempts = Schema::table( 'attempts' );
        $papers   = Schema::table( 'exam_papers' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.*, p.scheduled_at, p.duration_seconds
                 FROM {$attempts} a INNER JOIN {$papers} p ON p.id = a.paper_id
                 WHERE a.id = %d AND a.school_id = %d",
                $attempt_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'success' => false, 'error' => 'attempt_not_found' ];
        }

        $ends = $this->clock->to_timestamp( (string) $row['scheduled_at'] );

        if ( $ends === null || ( $ends + absint( $row['duration_seconds'] ) + absint( $row['extension_seconds'] ) ) < $this->clock->now() ) {
            return [ 'success' => false, 'error' => 'sitting_has_ended' ];
        }

        $wpdb->update(
            $attempts,
            [ 'status' => AttemptService::STATUS_IN_PROGRESS, 'submitted_at' => null, 'submit_reason' => '' ],
            [ 'id' => $attempt_id, 'school_id' => $school_id ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        EventDispatcher::action( 'educbt_attempt_reopened', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'staff_id'   => $staff_id,
            'reason'     => sanitize_text_field( $reason ),
        ] );

        return [ 'success' => true ];
    }

    /**
     * Flags raised against one attempt, for the invigilator to look at rather than
     * for the system to act on. A machine must not accuse a child of cheating.
     *
     * @return array<int,array<string,mixed>>
     */
    public function flags( int $school_id, int $attempt_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT event_type, payload, created_at FROM ' . Schema::table( 'attempt_events' ) .
                " WHERE attempt_id = %d AND school_id = %d AND event_type <> 'resumed'
                 ORDER BY created_at ASC",
                $attempt_id,
                $school_id
            ),
            ARRAY_A
        );
    }

    /**
     * Papers this invigilator is on duty for today.
     */
    public function my_papers( int $school_id, int $staff_id ): array {
        global $wpdb;

        $papers   = Schema::table( 'exam_papers' );
        $invig    = Schema::table( 'paper_invigilators' );
        $subjects = Schema::table( 'subjects_v2' );
        $classes  = Schema::table( 'classes' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.duration_seconds, p.status,
                        s.name AS subject_name, c.display_name AS class_name
                 FROM {$invig} i
                 INNER JOIN {$papers} p ON p.id = i.paper_id
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 LEFT JOIN {$classes} c ON c.id = p.class_id
                 WHERE i.school_id = %d AND i.staff_id = %d
                   AND DATE(p.scheduled_at) = DATE(%s)
                 ORDER BY p.scheduled_at ASC",
                $school_id,
                $staff_id,
                current_time( 'mysql', true )
            ),
            ARRAY_A
        );
    }
}
