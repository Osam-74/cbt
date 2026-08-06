<?php

namespace EduCBTPro\Core;

use EduCBTPro\Services\AssessmentService;
use EduCBTPro\Services\AttemptService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 6 — grading worker.
 *
 * Submission deliberately does not mark anything. Three hundred students in a hall
 * submit within the same few seconds when the clock runs out, and marking inside
 * those requests is how a CBT system falls over at the exact moment it must not.
 *
 * So marking happens here, afterwards, and in two deliberate ways:
 *
 *  1. SET-BASED. One UPDATE...JOIN marks every answer in an attempt against
 *     question_options. Not a PHP loop over sixty answers issuing sixty queries —
 *     that is the difference between marking a hall in seconds and in minutes.
 *
 *  2. BATCHED. The cron takes a bounded number of attempts per run, so a slow host
 *     degrades into "results appear over the next few minutes" rather than into a
 *     PHP timeout that leaves half a hall ungraded with no record of why.
 *
 * Marking is also IDEMPOTENT. Re-running it on a graded attempt produces the same
 * result, which is what makes a regrade after a corrected answer key safe.
 */
class GradingWorker {

    public const HOOK       = 'educbt_grade_attempts';
    public const SCHEDULE   = 'educbt_every_minute';
    public const BATCH_SIZE = 25;

    public function init(): void {
        add_action( self::HOOK, [ $this, 'run' ] );
        add_action( 'init', [ $this, 'ensure_scheduled' ] );

        // Grade promptly when a single student submits early, without making the
        // student wait for it. During the end-of-paper surge the cron does the work.
        add_action( 'educbt_attempt_submitted', [ $this, 'on_submitted' ], 10, 1 );
    }

    public function ensure_scheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );

        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
            $timestamp = wp_next_scheduled( self::HOOK );
        }
    }

    /**
     * A lone early submission is graded on the next tick rather than inline, so the
     * student's submit request still returns immediately.
     *
     * @param array<string,mixed> $payload
     */
    public function on_submitted( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::HOOK );
        }
    }

    /**
     * Grade a batch of submitted attempts.
     *
     * @return int attempts graded
     */
    public function run(): int {
        global $wpdb;

        $attempts = Schema::table( 'attempts' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, school_id FROM {$attempts}
                 WHERE status = %s
                 ORDER BY submitted_at ASC
                 LIMIT %d",
                AttemptService::STATUS_SUBMITTED,
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        $graded = 0;

        foreach ( $rows as $row ) {
            if ( $this->grade( absint( $row['school_id'] ), absint( $row['id'] ) )['success'] ) {
                $graded++;
            }
        }

        if ( $graded > 0 ) {
            EventDispatcher::action( 'educbt_attempts_graded', [ 'count' => $graded ] );
        }

        return $graded;
    }

    /**
     * Mark one attempt.
     *
     * @return array{success:bool,raw_score?:float,max_score?:float,percentage?:float,error?:string}
     */
    public function grade( int $school_id, int $attempt_id ): array {
        global $wpdb;

        $attempts = Schema::table( 'attempts' );

        $attempt = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$attempts} WHERE id = %d AND school_id = %d", $attempt_id, $school_id ),
            ARRAY_A
        );

        if ( ! $attempt ) {
            return [ 'success' => false, 'error' => 'attempt_not_found' ];
        }

        if ( (string) $attempt['status'] === AttemptService::STATUS_IN_PROGRESS ) {
            return [ 'success' => false, 'error' => 'attempt_still_open' ];
        }

        $answers         = Schema::table( 'attempt_answers' );
        $options         = Schema::table( 'question_options' );
        $paper_questions = Schema::table( 'paper_questions' );

        // Step 1 — mark every answer in one statement.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$answers} a
                 INNER JOIN {$options} o ON o.id = a.option_id
                 LEFT JOIN {$paper_questions} pq ON pq.paper_id = %d AND pq.question_id = a.question_id
                 SET a.is_correct = o.is_correct,
                     a.marks_awarded = CASE WHEN o.is_correct = 1 THEN COALESCE(pq.marks, 1) ELSE 0 END
                 WHERE a.attempt_id = %d AND a.school_id = %d",
                absint( $attempt['paper_id'] ),
                $attempt_id,
                $school_id
            )
        );

        // An unanswered question is worth nothing, but it must be recorded as wrong
        // rather than left NULL, or "how many did they attempt" becomes unanswerable.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$answers} SET is_correct = 0, marks_awarded = 0
                 WHERE attempt_id = %d AND school_id = %d AND option_id IS NULL",
                $attempt_id,
                $school_id
            )
        );

        // Step 2 — total.
        $raw = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(marks_awarded), 0) FROM {$answers} WHERE attempt_id = %d AND school_id = %d",
                $attempt_id,
                $school_id
            )
        );

        // The denominator is the paper's marks, not the marks the student attempted.
        // Using the latter would give a student who answered one question and got it
        // right a score of 100%.
        $max = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(marks), 0) FROM {$paper_questions} WHERE paper_id = %d",
                absint( $attempt['paper_id'] )
            )
        );

        if ( $max <= 0 ) {
            $max = (float) $attempt['max_score'];
        }

        $percentage = $max > 0 ? round( ( $raw / $max ) * 100, 2 ) : 0.0;

        $wpdb->update(
            $attempts,
            [
                'raw_score'  => $raw,
                'max_score'  => $max,
                'percentage' => $percentage,
                'graded_at'  => current_time( 'mysql', true ),
                'status'     => AttemptService::STATUS_GRADED,
            ],
            [ 'id' => $attempt_id ],
            [ '%f', '%f', '%f', '%s', '%s' ],
            [ '%d' ]
        );

        // Step 3 — push into the assessment component (CA or exam), scaled.
        $recorded = ( new AssessmentService() )->record_attempt_score( $school_id, $attempt_id );

        EventDispatcher::action( 'educbt_attempt_graded', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'raw_score'  => $raw,
            'max_score'  => $max,
            'percentage' => $percentage,
            'recorded'   => ! empty( $recorded['success'] ),
        ] );

        return [
            'success'    => true,
            'raw_score'  => $raw,
            'max_score'  => $max,
            'percentage' => $percentage,
        ];
    }

    /**
     * Re-mark every attempt on a paper.
     *
     * Needed for the case that actually happens: a question's answer key was wrong,
     * a teacher corrects it, and every student who sat the paper must be re-marked.
     * Without this the only remedy is editing scores by hand.
     *
     * @return array{regraded:int,paper_id:int}
     */
    public function regrade_paper( int $school_id, int $paper_id ): array {
        global $wpdb;

        $attempts = Schema::table( 'attempts' );

        $ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$attempts} WHERE school_id = %d AND paper_id = %d AND status <> %s",
                $school_id,
                $paper_id,
                AttemptService::STATUS_IN_PROGRESS
            )
        );

        $count = 0;

        foreach ( array_map( 'absint', $ids ) as $attempt_id ) {
            if ( $this->grade( $school_id, $attempt_id )['success'] ) {
                $count++;
            }
        }

        EventDispatcher::action( 'educbt_paper_regraded', [
            'school_id' => $school_id,
            'paper_id'  => $paper_id,
            'regraded'  => $count,
        ] );

        return [ 'regraded' => $count, 'paper_id' => $paper_id ];
    }

    /**
     * Attempts stuck in `submitted` for longer than expected. Surfaced on the exam
     * officer's screen, because "results are missing" must have a visible cause
     * rather than being something a school discovers on report-card day.
     *
     * @return array<int,array<string,mixed>>
     */
    public function stalled( int $school_id, int $minutes = 15 ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, paper_id, student_id, submitted_at FROM ' . Schema::table( 'attempts' ) .
                ' WHERE school_id = %d AND status = %s AND submitted_at < DATE_SUB(%s, INTERVAL %d MINUTE)',
                $school_id,
                AttemptService::STATUS_SUBMITTED,
                current_time( 'mysql', true ),
                $minutes
            ),
            ARRAY_A
        );
    }
}
