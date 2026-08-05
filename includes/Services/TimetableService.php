<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 5 — timetable.
 *
 * There is no timetable table. A timetable is papers, grouped by date and sorted by
 * time. Storing it separately was what made v1's `exams` and `exam_timetables` drift
 * apart and disagree about duration.
 *
 * This class also answers the question the student portal actually asks: WHICH PAPER
 * CAN I OPEN RIGHT NOW. That is deliberately narrow — a student sees a paper only
 * when all of the following hold:
 *
 *   - they are enrolled in the class the paper is set for
 *   - they are registered for the subject
 *   - the paper is published
 *   - the clock is inside the sitting window
 *   - they have not already submitted an attempt
 *
 * Every one of those is a filter v1 lacked, and each omission is a support ticket:
 * a student seeing another class's paper, a paper for a subject they dropped, or a
 * paper they already sat.
 */
class TimetableService {

    /** Minutes before the scheduled time that a paper becomes openable. */
    public const EARLY_ENTRY_SECONDS = 900;

    /**
     * Papers for a class in a series, grouped by date — the printed timetable.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function for_class( int $school_id, int $class_id, int $series_id ): array {
        global $wpdb;

        $papers   = Schema::table( 'exam_papers' );
        $subjects = Schema::table( 'subjects_v2' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.duration_seconds, p.venue, p.question_count,
                        p.status, s.name AS subject_name, s.code AS subject_code
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 WHERE p.school_id = %d AND p.class_id = %d AND p.series_id = %d
                   AND p.status <> 'cancelled'
                 ORDER BY p.scheduled_at ASC",
                $school_id,
                $class_id,
                $series_id
            ),
            ARRAY_A
        );

        $grouped = [];

        foreach ( $rows as $row ) {
            $date = substr( (string) $row['scheduled_at'], 0, 10 );

            $row['duration_minutes'] = (int) round( absint( $row['duration_seconds'] ) / 60 );
            $row['ends_at']          = gmdate(
                'Y-m-d H:i:s',
                (int) strtotime( (string) $row['scheduled_at'] ) + absint( $row['duration_seconds'] )
            );

            $grouped[ $date ][] = $row;
        }

        return $grouped;
    }

    /**
     * The whole school's timetable for a series — the exam officer's planning view.
     */
    public function for_series( int $school_id, int $series_id ): array {
        global $wpdb;

        $papers   = Schema::table( 'exam_papers' );
        $subjects = Schema::table( 'subjects_v2' );
        $classes  = Schema::table( 'classes' );
        $invig    = Schema::table( 'paper_invigilators' );
        $staff    = Schema::table( 'staff' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.duration_seconds, p.venue, p.status,
                        s.name AS subject_name, c.display_name AS class_name,
                        GROUP_CONCAT(CONCAT(st.first_name, ' ', st.last_name) SEPARATOR ', ') AS invigilators
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 LEFT JOIN {$classes} c ON c.id = p.class_id
                 LEFT JOIN {$invig} pi ON pi.paper_id = p.id
                 LEFT JOIN {$staff} st ON st.id = pi.staff_id
                 WHERE p.school_id = %d AND p.series_id = %d AND p.status <> 'cancelled'
                 GROUP BY p.id
                 ORDER BY p.scheduled_at ASC",
                $school_id,
                $series_id
            ),
            ARRAY_A
        );

        $grouped = [];

        foreach ( $rows as $row ) {
            $grouped[ substr( (string) $row['scheduled_at'], 0, 10 ) ][] = $row;
        }

        return $grouped;
    }

    /**
     * Papers this student may open right now.
     *
     * @return array<int,array<string,mixed>>
     */
    public function active_for_student( int $school_id, int $student_id ): array {
        global $wpdb;

        $papers      = Schema::table( 'exam_papers' );
        $subjects    = Schema::table( 'subjects_v2' );
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );
        $attempts    = Schema::table( 'attempts' );

        $now = current_time( 'mysql', true );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.duration_seconds, p.question_count,
                        p.requires_access_code, p.is_practice, p.allow_review,
                        s.name AS subject_name,
                        a.id AS attempt_id, a.status AS attempt_status
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 INNER JOIN {$enrollments} e
                         ON e.class_id = p.class_id
                        AND e.student_id = %d
                        AND e.status = 'active'
                 INNER JOIN {$registered} rs
                         ON rs.student_id = e.student_id
                        AND rs.subject_id = p.subject_id
                        AND rs.session_id = e.session_id
                 LEFT JOIN {$attempts} a
                        ON a.paper_id = p.id AND a.student_id = e.student_id
                 WHERE p.school_id = %d
                   AND p.status = 'published'
                   AND DATE_SUB(p.scheduled_at, INTERVAL %d SECOND) <= %s
                   AND DATE_ADD(p.scheduled_at, INTERVAL p.duration_seconds SECOND) > %s
                   AND ( a.id IS NULL OR a.status = 'in_progress' )
                 ORDER BY p.scheduled_at ASC",
                $student_id,
                $school_id,
                self::EARLY_ENTRY_SECONDS,
                $now,
                $now
            ),
            ARRAY_A
        );
    }

    /**
     * Upcoming papers for a student — the dashboard's "what's next" panel.
     */
    public function upcoming_for_student( int $school_id, int $student_id, int $limit = 10 ): array {
        global $wpdb;

        $papers      = Schema::table( 'exam_papers' );
        $subjects    = Schema::table( 'subjects_v2' );
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.duration_seconds, p.venue, s.name AS subject_name
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 INNER JOIN {$enrollments} e ON e.class_id = p.class_id AND e.student_id = %d AND e.status = 'active'
                 INNER JOIN {$registered} rs ON rs.student_id = e.student_id AND rs.subject_id = p.subject_id AND rs.session_id = e.session_id
                 WHERE p.school_id = %d AND p.status = 'published'
                   AND p.scheduled_at > %s
                 ORDER BY p.scheduled_at ASC
                 LIMIT %d",
                $student_id,
                $school_id,
                current_time( 'mysql', true ),
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * May this student open this specific paper? Returns a reason on refusal so the
     * portal can say something useful rather than "access denied".
     *
     * @return array{allowed:bool,reason:string,paper?:array<string,mixed>}
     */
    public function can_open( int $school_id, int $student_id, int $paper_id, string $access_code = '' ): array {
        global $wpdb;

        $papers      = Schema::table( 'exam_papers' );
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );

        $paper = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$papers} WHERE id = %d AND school_id = %d", $paper_id, $school_id ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return [ 'allowed' => false, 'reason' => 'paper_not_found' ];
        }

        if ( (string) $paper['status'] !== 'published' ) {
            return [ 'allowed' => false, 'reason' => 'paper_not_published' ];
        }

        $enrolled = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$enrollments} WHERE student_id = %d AND class_id = %d AND status = 'active' LIMIT 1",
                $student_id,
                absint( $paper['class_id'] )
            ),
            ARRAY_A
        );

        if ( ! $enrolled ) {
            return [ 'allowed' => false, 'reason' => 'not_in_this_class' ];
        }

        $offers = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$registered} WHERE student_id = %d AND subject_id = %d AND session_id = %d",
                $student_id,
                absint( $paper['subject_id'] ),
                absint( $enrolled['session_id'] )
            )
        );

        if ( ! $offers ) {
            return [ 'allowed' => false, 'reason' => 'subject_not_registered' ];
        }

        $now   = (int) strtotime( current_time( 'mysql', true ) );
        $start = (int) strtotime( (string) $paper['scheduled_at'] );
        $end   = $start + absint( $paper['duration_seconds'] );

        if ( $now < $start - self::EARLY_ENTRY_SECONDS ) {
            return [ 'allowed' => false, 'reason' => 'too_early' ];
        }

        if ( $now >= $end ) {
            return [ 'allowed' => false, 'reason' => 'window_closed' ];
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, status FROM ' . Schema::table( 'attempts' ) . ' WHERE paper_id = %d AND student_id = %d',
                $paper_id,
                $student_id
            ),
            ARRAY_A
        );

        if ( $existing && (string) $existing['status'] !== 'in_progress' ) {
            return [ 'allowed' => false, 'reason' => 'already_submitted' ];
        }

        // The access code is checked last, so a student who fails an earlier test is
        // not told the code was the problem.
        if ( ! empty( $paper['requires_access_code'] ) ) {
            if ( strcasecmp( trim( $access_code ), (string) $paper['access_code'] ) !== 0 ) {
                return [ 'allowed' => false, 'reason' => 'invalid_access_code' ];
            }
        }

        return [ 'allowed' => true, 'reason' => '', 'paper' => $paper ];
    }
}
