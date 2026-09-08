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
                "SELECT p.id, p.scheduled_at, p.closes_at, p.duration_seconds, p.venue, p.status,
                        p.level_id, p.department_id, p.is_practice, p.delivery_mode, p.requires_access_code, p.access_code,
                        MIN(pi.staff_id) AS invigilator_id,
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
            // Provide local-time equivalents for datetime-local inputs in the
            // reschedule form. Without these, the form would show UTC times and
            // saving would shift everything by the timezone offset.
            $row['scheduled_at_local'] = str_replace( ' ', 'T', substr( get_date_from_gmt( (string) $row['scheduled_at'] ), 0, 16 ) );
            $row['closes_at_local']     = '';
            if ( ! empty( $row['closes_at'] ) && $row['closes_at'] !== '0000-00-00 00:00:00' ) {
                $row['closes_at_local'] = str_replace( ' ', 'T', substr( get_date_from_gmt( (string) $row['closes_at'] ), 0, 16 ) );
            }
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

        $sessions = Schema::table( 'academic_sessions' );

        // Registration decides what a student sits.
        //
        // The clause below used to read "registered OR enrolled in the class",
        // which meant a student who had never registered the subject was still
        // shown the paper simply for being in that class — registration was
        // optional in practice, and a student could sit a subject they do not
        // offer.
        //
        // Registration is now authoritative. The class fallback applies ONLY to a
        // student who has registered nothing at all, so a school that has not yet
        // rolled registration out is not locked out of its own examinations.
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.closes_at, p.duration_seconds, p.question_count,
                        p.requires_access_code, p.is_practice, p.delivery_mode, p.allow_review,
                        s.name AS subject_name,
                        (SELECT a.id FROM {$attempts} a
                          WHERE a.paper_id = p.id AND a.student_id = %d
                          ORDER BY a.id DESC LIMIT 1) AS attempt_id,
                        (SELECT a2.status FROM {$attempts} a2
                          WHERE a2.paper_id = p.id AND a2.student_id = %d
                          ORDER BY a2.id DESC LIMIT 1) AS attempt_status
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 WHERE p.school_id = %d
                   AND p.status = 'published'
                   AND p.is_practice = 0
                   AND DATE_SUB(p.scheduled_at, INTERVAL %d SECOND) <= %s
                   AND (
                       (p.closes_at IS NOT NULL AND p.closes_at > %s)
                       OR (p.closes_at IS NULL AND DATE_ADD(p.scheduled_at, INTERVAL p.duration_seconds SECOND) > %s)
                   )
                   AND (
                       (SELECT a3.status FROM {$attempts} a3
                         WHERE a3.paper_id = p.id AND a3.student_id = %d
                         ORDER BY a3.id DESC LIMIT 1) IS NULL
                       OR (SELECT a4.status FROM {$attempts} a4
                         WHERE a4.paper_id = p.id AND a4.student_id = %d
                         ORDER BY a4.id DESC LIMIT 1) = 'in_progress'
                   )
                   AND (
                       EXISTS (SELECT 1 FROM {$registered} rs WHERE rs.student_id = %d AND rs.subject_id = p.subject_id)
                       OR (
                           NOT EXISTS (SELECT 1 FROM {$registered} rs2 WHERE rs2.student_id = %d)
                           AND p.class_id IS NOT NULL
                           AND EXISTS (
                               SELECT 1 FROM {$enrollments} e
                               INNER JOIN {$sessions} ss ON ss.id = e.session_id AND ss.is_current = 1
                               WHERE e.student_id = %d AND e.class_id = p.class_id AND e.status = 'active'
                           )
                       )
                   )
                 ORDER BY p.scheduled_at ASC",
                $student_id,
                $student_id,
                $school_id,
                self::EARLY_ENTRY_SECONDS,
                $now,
                $now,
                $now,
                $student_id,
                $student_id,
                $student_id,
                $student_id,
                // Third $student_id for the registration clause: registered,
                // has-no-registrations-at-all, and enrolled.
                $student_id
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
        $sessions    = Schema::table( 'academic_sessions' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.closes_at, p.duration_seconds, p.venue, p.access_code, p.requires_access_code, p.delivery_mode, s.name AS subject_name
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 WHERE p.school_id = %d AND p.status = 'published'
                   AND p.is_practice = 0
                   AND (
                       (p.closes_at IS NOT NULL AND p.closes_at > %s)
                       OR (p.closes_at IS NULL AND DATE_ADD(p.scheduled_at, INTERVAL p.duration_seconds SECOND) > %s)
                   )
                   AND (
                       EXISTS (SELECT 1 FROM {$registered} rs WHERE rs.student_id = %d AND rs.subject_id = p.subject_id)
                       OR (p.class_id IS NOT NULL AND EXISTS (
                           SELECT 1 FROM {$enrollments} e
                           INNER JOIN {$sessions} ss ON ss.id = e.session_id AND ss.is_current = 1
                           WHERE e.student_id = %d AND e.class_id = p.class_id AND e.status = 'active'
                       ))
                       OR (p.class_id IS NULL AND EXISTS (
                           SELECT 1 FROM {$registered} rs2 WHERE rs2.student_id = %d AND rs2.subject_id = p.subject_id
                       ))
                   )
                 ORDER BY p.scheduled_at ASC
                 LIMIT %d",
                $school_id,
                current_time( 'mysql', true ),
                current_time( 'mysql', true ),
                $student_id,
                $student_id,
                $student_id,
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

        // Access gate: the student must be registered for this paper's subject
        // OR be enrolled in the paper's class for the current session. Subject
        // registration is preferred (it survives class promotions) but class
        // enrollment is the fallback for schools that haven't completed subject
        // registration yet.
        $offers = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$registered} WHERE student_id = %d AND subject_id = %d",
                $student_id,
                absint( $paper['subject_id'] )
            )
        );

        if ( ! $offers && ! empty( $paper['class_id'] ) ) {
            $sessions = Schema::table( 'academic_sessions' );
            $offers = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT 1 FROM {$enrollments} e
                     INNER JOIN {$sessions} ss ON ss.id = e.session_id AND ss.is_current = 1
                     WHERE e.student_id = %d AND e.class_id = %d AND e.status = 'active'
                     LIMIT 1",
                    $student_id,
                    absint( $paper['class_id'] )
                )
            );
        }

        // If the paper has no class restriction, any actively enrolled student may sit it.
        if ( ! $offers && empty( $paper['class_id'] ) ) {
            $sessions = Schema::table( 'academic_sessions' );
            $offers = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT 1 FROM {$enrollments} e
                     INNER JOIN {$sessions} ss ON ss.id = e.session_id AND ss.is_current = 1
                     WHERE e.student_id = %d AND e.status = 'active'
                     LIMIT 1",
                    $student_id
                )
            );
        }

        if ( ! $offers ) {
            return [ 'allowed' => false, 'reason' => 'subject_not_registered' ];
        }

        $now   = (int) strtotime( current_time( 'mysql', true ) );
        $start = (int) strtotime( (string) $paper['scheduled_at'] );
        $end   = $start + absint( $paper['duration_seconds'] );

        if ( $now < $start - self::EARLY_ENTRY_SECONDS ) {
            return [ 'allowed' => false, 'reason' => 'too_early' ];
        }

        // For practice/CA tests, duration_seconds is how long each student has to
        // COMPLETE the test once they start — NOT when the test stops being
        // available. The test stays open as long as it is published, unless an
        // optional closes_at time has been set and has passed.
        if ( ! empty( $paper['is_practice'] ) ) {
            if ( ! empty( $paper['closes_at'] ) && $now >= (int) strtotime( (string) $paper['closes_at'] ) ) {
                return [ 'allowed' => false, 'reason' => 'window_closed' ];
            }
        } else {
            // For scheduled examinations, the sitting window is scheduled_at + duration.
            // If closes_at is set, use it as the hard cutoff instead.
            $window_end = $end;
            if ( ! empty( $paper['closes_at'] ) && $paper['closes_at'] !== '0000-00-00 00:00:00' ) {
                $window_end = (int) strtotime( (string) $paper['closes_at'] );
            }
            if ( $now >= $window_end ) {
                return [ 'allowed' => false, 'reason' => 'window_closed' ];
            }
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
