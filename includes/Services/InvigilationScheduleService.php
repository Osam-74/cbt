<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Building and maintaining an invigilation schedule.
 *
 * The flow a school actually follows:
 *
 *   1. Nothing exists yet — offer to build one for a chosen examination.
 *   2. Propose an invigilator for every paper, spreading the load fairly.
 *   3. Let the exam officer swap anyone before committing.
 *   4. Keep it changeable until the last paper is written.
 *
 * Two rules make the proposal worth anything:
 *
 *   - THE SUBJECT TEACHER DOES NOT INVIGILATE THEIR OWN PAPER. It puts a teacher in
 *     an impossible position and it is the first thing an external moderator checks.
 *   - NOBODY IS IN TWO HALLS AT ONCE. Overlap is checked against the actual paper
 *     window, not merely the start time.
 *
 * If the timetable moves afterwards, the schedule can silently become wrong, so
 * `drift()` reports where it no longer matches.
 */
class InvigilationScheduleService {

    /**
     * Papers in a series, with whoever is currently assigned.
     *
     * @return array<int,array<string,mixed>>
     */
    public function papers( int $school_id, int $series_id ): array {
        global $wpdb;

        $papers    = Schema::table( 'exam_papers' );
        $subjects  = Schema::table( 'subjects_v2' );
        $classes   = Schema::table( 'classes' );
        $invig     = Schema::table( 'paper_invigilators' );
        $staff     = Schema::table( 'staff' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, p.duration_seconds, p.status,
                        s.name AS subject_name, s.id AS subject_id,
                        c.display_name AS class_name, c.id AS class_id,
                        i.staff_id AS invigilator_id,
                        CONCAT(st.first_name, ' ', st.last_name) AS invigilator_name
                 FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 LEFT JOIN {$classes} c ON c.id = p.class_id
                 LEFT JOIN {$invig} i ON i.paper_id = p.id
                 LEFT JOIN {$staff} st ON st.id = i.staff_id
                 WHERE p.school_id = %d AND p.series_id = %d AND p.status <> 'cancelled'
                 ORDER BY p.scheduled_at ASC",
                $school_id,
                $series_id
            ),
            ARRAY_A
        );
    }

    /**
     * Staff available to invigilate, with how much they already carry.
     *
     * @return array<int,array<string,mixed>>
     */
    public function available_staff( int $school_id ): array {
        global $wpdb;

        $staff = Schema::table( 'staff' );
        $invig = Schema::table( 'paper_invigilators' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, CONCAT(s.first_name, ' ', s.last_name) AS name, s.role_slug,
                        (SELECT COUNT(*) FROM {$invig} i WHERE i.staff_id = s.id) AS duties
                 FROM {$staff} s
                 WHERE s.school_id = %d AND s.status = 'active'
                 ORDER BY duties ASC, s.last_name ASC",
                $school_id
            ),
            ARRAY_A
        );
    }

    /**
     * Propose an invigilator for every paper that has none.
     *
     * @return array{assigned:int,unfilled:array<int,string>}
     */
    public function propose( int $school_id, int $series_id ): array {
        global $wpdb;

        $papers = $this->papers( $school_id, $series_id );
        $staff  = $this->available_staff( $school_id );

        if ( empty( $staff ) ) {
            return [ 'assigned' => 0, 'unfilled' => [ 'no active staff to assign' ] ];
        }

        $assign_table = Schema::table( 'staff_assignments' );
        $invig        = Schema::table( 'paper_invigilators' );

        // Running load, so the proposal spreads work rather than piling it on whoever
        // sorts first.
        $load = [];

        foreach ( $staff as $member ) {
            $load[ (int) $member['id'] ] = (int) $member['duties'];
        }

        $booked   = [];
        $assigned = 0;
        $unfilled = [];

        foreach ( $papers as $paper ) {
            if ( ! empty( $paper['invigilator_id'] ) ) {
                $booked[ (int) $paper['invigilator_id'] ][] = $paper;
                continue;
            }

            $start = strtotime( (string) $paper['scheduled_at'] );
            $end   = $start + (int) $paper['duration_seconds'];

            $candidates = $staff;

            usort(
                $candidates,
                static fn( array $a, array $b ): int => ( $load[ (int) $a['id'] ] ?? 0 ) <=> ( $load[ (int) $b['id'] ] ?? 0 )
            );

            $chosen = 0;

            foreach ( $candidates as $member ) {
                $staff_id = (int) $member['id'];

                // Never the teacher of that subject in that class.
                $teaches = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$assign_table}
                             WHERE staff_id = %d AND subject_id = %d AND class_id = %d
                               AND assignment_type = 'subject_teacher' AND status = 'active' LIMIT 1",
                            $staff_id,
                            absint( $paper['subject_id'] ),
                            absint( $paper['class_id'] )
                        )
                    )
                );

                if ( $teaches > 0 ) {
                    continue;
                }

                // Never in two halls at once — compared against the whole window.
                $clash = false;

                foreach ( (array) ( $booked[ $staff_id ] ?? [] ) as $other ) {
                    $other_start = strtotime( (string) $other['scheduled_at'] );
                    $other_end   = $other_start + (int) $other['duration_seconds'];

                    if ( $start < $other_end && $other_start < $end ) {
                        $clash = true;
                        break;
                    }
                }

                if ( $clash ) {
                    continue;
                }

                $chosen = $staff_id;
                break;
            }

            if ( $chosen === 0 ) {
                $unfilled[] = sprintf( '%s — %s', $paper['subject_name'], $paper['class_name'] );
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$invig} (school_id, paper_id, staff_id, role, status)
                     VALUES (%d, %d, %d, 'invigilator', 'assigned')
                     ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id)",
                    $school_id,
                    absint( $paper['id'] ),
                    $chosen
                )
            );

            $load[ $chosen ]   = ( $load[ $chosen ] ?? 0 ) + 1;
            $booked[ $chosen ][] = $paper;
            $assigned++;
        }

        EventDispatcher::action( 'educbt_invigilation_proposed', [
            'school_id' => $school_id,
            'series_id' => $series_id,
            'assigned'  => $assigned,
        ] );

        return [ 'assigned' => $assigned, 'unfilled' => $unfilled ];
    }

    /**
     * Swap one paper's invigilator.
     *
     * @return array{success:bool,error?:string}
     */
    public function reassign( int $school_id, int $paper_id, int $staff_id ): array {
        global $wpdb;

        $invig  = Schema::table( 'paper_invigilators' );
        $papers = Schema::table( 'exam_papers' );

        $paper = (array) $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, subject_id, class_id, scheduled_at, duration_seconds FROM {$papers}
                 WHERE id = %d AND school_id = %d",
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( empty( $paper ) ) {
            return [ 'success' => false, 'error' => 'paper_not_found' ];
        }

        if ( $staff_id === 0 ) {
            $wpdb->delete( $invig, [ 'paper_id' => $paper_id, 'school_id' => $school_id ], [ '%d', '%d' ] );

            return [ 'success' => true ];
        }

        // The same two rules apply to a manual swap. An exam officer choosing from a
        // dropdown cannot see a clash, so it must be checked here.
        $teaches = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . Schema::table( 'staff_assignments' ) . "
                     WHERE staff_id = %d AND subject_id = %d AND class_id = %d
                       AND assignment_type = 'subject_teacher' AND status = 'active' LIMIT 1",
                    $staff_id,
                    absint( $paper['subject_id'] ),
                    absint( $paper['class_id'] )
                )
            )
        );

        if ( $teaches > 0 ) {
            return [ 'success' => false, 'error' => 'teaches_this_subject' ];
        }

        $start = strtotime( (string) $paper['scheduled_at'] );
        $end   = $start + (int) $paper['duration_seconds'];

        $clash = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT p.id FROM {$invig} i
                     INNER JOIN {$papers} p ON p.id = i.paper_id
                     WHERE i.staff_id = %d AND i.paper_id <> %d AND p.status <> 'cancelled'
                       AND UNIX_TIMESTAMP(p.scheduled_at) < %d
                       AND (UNIX_TIMESTAMP(p.scheduled_at) + p.duration_seconds) > %d
                     LIMIT 1",
                    $staff_id,
                    $paper_id,
                    $end,
                    $start
                )
            )
        );

        if ( $clash > 0 ) {
            return [ 'success' => false, 'error' => 'already_invigilating_then' ];
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$invig} (school_id, paper_id, staff_id, role, status)
                 VALUES (%d, %d, %d, 'invigilator', 'assigned')
                 ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id)",
                $school_id,
                $paper_id,
                $staff_id
            )
        );

        return [ 'success' => true ];
    }

    /**
     * Where the schedule no longer matches the timetable.
     *
     * A paper moved after the schedule was built can leave an invigilator double
     * booked, or watching a subject they teach — neither of which announces itself.
     *
     * @return array<int,string>
     */
    public function drift( int $school_id, int $series_id ): array {
        $papers = $this->papers( $school_id, $series_id );
        $issues = [];

        global $wpdb;
        $assign_table = Schema::table( 'staff_assignments' );

        foreach ( $papers as $paper ) {
            $staff_id = absint( $paper['invigilator_id'] );

            if ( $staff_id === 0 ) {
                $issues[] = sprintf( '%s — %s has no invigilator', $paper['subject_name'], $paper['class_name'] );
                continue;
            }

            $teaches = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$assign_table}
                         WHERE staff_id = %d AND subject_id = %d AND class_id = %d
                           AND assignment_type = 'subject_teacher' AND status = 'active' LIMIT 1",
                        $staff_id,
                        absint( $paper['subject_id'] ),
                        absint( $paper['class_id'] )
                    )
                )
            );

            if ( $teaches > 0 ) {
                $issues[] = sprintf( '%s invigilates %s, which they teach', $paper['invigilator_name'], $paper['subject_name'] );
            }

            $start = strtotime( (string) $paper['scheduled_at'] );
            $end   = $start + (int) $paper['duration_seconds'];

            foreach ( $papers as $other ) {
                if ( (int) $other['id'] === (int) $paper['id'] || absint( $other['invigilator_id'] ) !== $staff_id ) {
                    continue;
                }

                $other_start = strtotime( (string) $other['scheduled_at'] );
                $other_end   = $other_start + (int) $other['duration_seconds'];

                if ( $start < $other_end && $other_start < $end && (int) $paper['id'] < (int) $other['id'] ) {
                    $issues[] = sprintf(
                        '%s is in two halls at once (%s and %s)',
                        $paper['invigilator_name'],
                        $paper['subject_name'],
                        $other['subject_name']
                    );
                }
            }
        }

        return array_values( array_unique( $issues ) );
    }
}
