<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ExamTimetableRepository;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamTimetableService {
    private ExamTimetableRepository $repository;
    private const MAX_SUBJECTS_PER_DAY = 3;

    public function __construct( ?ExamTimetableRepository $repository = null ) {
        $this->repository = $repository ?? new ExamTimetableRepository();
    }

    public function list_timetables( int $school_id ): array {
        return $this->repository->get_all_timetables( $school_id );
    }

    public function create_timetable( int $school_id, array $data ): int {
        if ( $school_id <= 0 || empty( $data['exam_id'] ) ) {
            return 0;
        }

        $class_name = sanitize_text_field( (string) ( $data['class_name'] ?? '' ) );
        $department = sanitize_text_field( (string) ( $data['department'] ?? '' ) );
        $subject = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );

        if ( $class_name === '' || $department === '' || $subject === '' ) {
            return 0;
        }

        $constraint = $this->validate_daily_subject_constraints( $school_id, $data );
        if ( ! $constraint['success'] ) {
            return 0;
        }

        return $this->repository->create_timetable( $school_id, $data );
    }

    public function validate_daily_subject_constraints( int $school_id, array $data ): array {
        $class_name = sanitize_text_field( (string) ( $data['class_name'] ?? '' ) );
        $department = sanitize_text_field( (string) ( $data['department'] ?? '' ) );
        $subject = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );
        $exam_date = sanitize_text_field( (string) ( $data['exam_date'] ?? '' ) );

        if ( $class_name === '' || $department === '' || $subject === '' || $exam_date === '' ) {
            return [ 'success' => false, 'message' => 'class_department_subject_exam_date_required' ];
        }

        $subject_already_scheduled = $this->repository->is_subject_scheduled_for_day_scope( $school_id, $class_name, $department, $exam_date, $subject );
        $distinct_subject_count = $this->repository->count_distinct_subjects_for_day_scope( $school_id, $class_name, $department, $exam_date );

        if ( ! $subject_already_scheduled && $distinct_subject_count >= self::MAX_SUBJECTS_PER_DAY ) {
            return [ 'success' => false, 'message' => 'max_three_subjects_per_day_for_class_department' ];
        }

        return [ 'success' => true, 'message' => '' ];
    }

    public function get_exam_timetable( int $school_id, int $exam_id ): ?array {
        return $this->repository->get_exam_timetable( $school_id, $exam_id );
    }

    public function filter_exams_for_student( array $exams, int $school_id, array $student ): array {
        $class_name = strtolower( (string) ( $student['class'] ?? '' ) );
        $arm = strtolower( (string) ( $student['arm'] ?? '' ) );
        $department = strtolower( (string) ( $student['department'] ?? '' ) );
        $session_year = strtolower( (string) ( $student['session_year'] ?? '' ) );
        $subject_bundle = $this->normalize_subject_bundle( $student['subject_bundle'] ?? [] );
        $now = current_time( 'timestamp' );

        $visible = [];

        foreach ( $exams as $exam ) {
            if ( ! is_array( $exam ) ) {
                continue;
            }

            $exam_id = absint( $exam['id'] ?? 0 );
            if ( $exam_id <= 0 ) {
                continue;
            }

            $timetable = $this->repository->get_exam_timetable( $school_id, $exam_id );
            if ( ! $timetable ) {
                // Backward-compatible fallback: show published exam even if timetable is not yet configured.
                $exam['is_trial_mode'] = false;
                $exam['is_active_window'] = false;
                $visible[] = $exam;
                continue;
            }

            if ( ! $this->student_matches_timetable( $timetable, $class_name, $arm, $department, $session_year, $subject_bundle ) ) {
                continue;
            }

            $exam['timetable'] = $timetable;
            $exam['is_trial_mode'] = (bool) ( $timetable['is_trial_mode'] ?? 0 );
            $exam['is_active_window'] = $this->is_active_window( $timetable, $now );

            $visible[] = $exam;
        }

        usort(
            $visible,
            static function ( array $left, array $right ): int {
                if ( (bool) ( $left['is_active_window'] ?? false ) !== (bool) ( $right['is_active_window'] ?? false ) ) {
                    return ( $left['is_active_window'] ?? false ) ? -1 : 1;
                }

                if ( (bool) ( $left['is_trial_mode'] ?? false ) !== (bool) ( $right['is_trial_mode'] ?? false ) ) {
                    return ( $left['is_trial_mode'] ?? false ) ? 1 : -1;
                }

                $left_start = strtotime( (string) ( $left['start_time'] ?? '' ) ) ?: 0;
                $right_start = strtotime( (string) ( $right['start_time'] ?? '' ) ) ?: 0;

                return $left_start <=> $right_start;
            }
        );

        return $visible;
    }

    public function count_scheduled_subjects_for_student_on_date( int $school_id, array $student, string $exam_date ): int {
        if ( $school_id <= 0 || trim( $exam_date ) === '' ) {
            return 0;
        }

        $exam_date = sanitize_text_field( $exam_date );
        $class_name = strtolower( (string) ( $student['class'] ?? '' ) );
        $arm = strtolower( (string) ( $student['arm'] ?? '' ) );
        $department = strtolower( (string) ( $student['department'] ?? '' ) );
        $session_year = strtolower( (string) ( $student['session_year'] ?? '' ) );
        $subject_bundle = $this->normalize_subject_bundle( $student['subject_bundle'] ?? [] );

        $subjects = [];
        $timetables = $this->repository->get_all_timetables( $school_id );
        foreach ( $timetables as $timetable ) {
            if ( ! is_array( $timetable ) ) {
                continue;
            }

            $tt_date = sanitize_text_field( (string) ( $timetable['exam_date'] ?? '' ) );
            if ( $tt_date !== $exam_date ) {
                continue;
            }

            if ( ! $this->student_matches_timetable( $timetable, $class_name, $arm, $department, $session_year, $subject_bundle ) ) {
                continue;
            }

            $subject = strtolower( trim( (string) ( $timetable['subject'] ?? '' ) ) );
            if ( $subject !== '' ) {
                $subjects[ $subject ] = true;
            }
        }

        return count( $subjects );
    }

    private function student_matches_timetable( array $timetable, string $class_name, string $arm, string $department, string $session_year, array $subject_bundle ): bool {
        $tt_class = strtolower( (string) ( $timetable['class_name'] ?? '' ) );
        $tt_arm = strtolower( (string) ( $timetable['arm'] ?? '' ) );
        $tt_department = strtolower( (string) ( $timetable['department'] ?? '' ) );
        $tt_subject = strtolower( trim( (string) ( $timetable['subject'] ?? '' ) ) );
        $tt_session = strtolower( (string) ( $timetable['session_year'] ?? '' ) );

        if ( $tt_class !== '' && $class_name !== '' && $tt_class !== $class_name ) {
            return false;
        }

        if ( $tt_arm !== '' && $arm !== '' && $tt_arm !== $arm ) {
            return false;
        }

        if ( $tt_department !== '' && $department !== '' && $tt_department !== $department ) {
            return false;
        }

        if ( $tt_subject === '' ) {
            return false;
        }

        if ( ! empty( $subject_bundle ) && ! in_array( $tt_subject, $subject_bundle, true ) ) {
            return false;
        }

        if ( $tt_session !== '' && $session_year !== '' && $tt_session !== $session_year ) {
            return false;
        }

        return true;
    }

    private function normalize_subject_bundle( $raw ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $raw = $decoded;
            }
        }

        if ( ! is_array( $raw ) ) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $subject ): string => strtolower( trim( sanitize_text_field( (string) $subject ) ) ),
                        $raw
                    ),
                    static fn( string $subject ): bool => $subject !== ''
                )
            )
        );
    }

    private function is_active_window( array $timetable, int $now ): bool {
        $exam_date = trim( (string) ( $timetable['exam_date'] ?? '' ) );
        $start_time = trim( (string) ( $timetable['start_time'] ?? '' ) );
        $end_time = trim( (string) ( $timetable['end_time'] ?? '' ) );

        if ( $exam_date === '' || $start_time === '' || $end_time === '' ) {
            return false;
        }

        $start_ts = strtotime( $exam_date . ' ' . $start_time );
        $end_ts = strtotime( $exam_date . ' ' . $end_time );

        if ( ! $start_ts || ! $end_ts ) {
            return false;
        }

        return $now >= $start_ts && $now <= $end_ts;
    }

    /**
     * Build a timetable for an examination from the question sets approved for it.
     *
     * The schedule is derived, not invented: one paper per subject x level x
     * department that actually has approved questions, laid across consecutive
     * weekdays in the school's slots. Anything already scheduled for the series is
     * left alone, so re-running after a late approval adds the missing papers
     * without disturbing what the exam office has already adjusted by hand.
     *
     * @return array{success:bool,created:int,skipped:int,error?:string}
     */
    public function generate_for_series( int $school_id, int $series_id, string $starts_on = '', array $slots = [] ): array {
        global $wpdb;

        $series = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'exam_series' ) . ' WHERE id = %d AND school_id = %d',
                $series_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $series ) {
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'error' => 'series_not_found' ];
        }

        $sets    = Schema::table( 'question_sets' );
        $papers  = Schema::table( 'exam_papers' );
        $classes = Schema::table( 'classes' );

        $approved = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT subject_id, level_id, department_id,
                        SUM(CASE WHEN exam_type = 'objective' THEN min_required ELSE 0 END) AS objective_min
                 FROM {$sets}
                 WHERE school_id = %d AND session_id = %d AND COALESCE(term_id,0) = %d
                   AND status IN ('approved','published')
                 GROUP BY subject_id, level_id, department_id
                 ORDER BY level_id ASC, subject_id ASC",
                $school_id,
                absint( $series['session_id'] ),
                absint( $series['term_id'] )
            ),
            ARRAY_A
        );

        if ( empty( $approved ) ) {
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'error' => 'nothing_approved' ];
        }

        if ( empty( $slots ) ) {
            $slots = [ '09:00:00', '11:30:00', '14:00:00' ];
        }

        $day = $starts_on !== '' ? $starts_on : (string) ( $series['starts_on'] ?: current_time( 'Y-m-d' ) );

        $created = 0;
        $skipped = 0;
        $slot    = 0;

        foreach ( $approved as $row ) {
            $subject_id    = absint( $row['subject_id'] );
            $level_id      = absint( $row['level_id'] );
            $department_id = absint( $row['department_id'] );

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$papers}
                     WHERE school_id = %d AND series_id = %d AND subject_id = %d
                       AND COALESCE(level_id,0) = %d AND COALESCE(department_id,0) = %d
                     LIMIT 1",
                    $school_id,
                    $series_id,
                    $subject_id,
                    $level_id,
                    $department_id
                )
            );

            if ( $existing ) {
                $skipped++;
                continue;
            }

            // Weekends are not exam days.
            while ( in_array( (int) gmdate( 'N', strtotime( $day ) ), [ 6, 7 ], true ) ) {
                $day = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
            }

            $representative = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$classes}
                         WHERE school_id = %d AND level_id = %d
                           AND COALESCE(department_id,0) = %d AND status = 'active'
                         ORDER BY arm ASC LIMIT 1",
                        $school_id,
                        $level_id,
                        $department_id
                    )
                )
            );

            $wpdb->insert(
                $papers,
                [
                    'school_id'        => $school_id,
                    'series_id'        => $series_id,
                    'subject_id'       => $subject_id,
                    'class_id'         => $representative ?: null,
                    'level_id'         => $level_id,
                    'department_id'    => $department_id ?: null,
                    'scheduled_at'     => $day . ' ' . $slots[ $slot ],
                    'duration_seconds' => 3600,
                    'question_count'   => absint( $row['objective_min'] ),
                    'status'           => 'draft',
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s' ]
            );

            if ( absint( $wpdb->insert_id ) > 0 ) {
                $created++;
            }

            $slot++;
            if ( $slot >= count( $slots ) ) {
                $slot = 0;
                $day  = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
            }
        }

        return [ 'success' => true, 'created' => $created, 'skipped' => $skipped ];
    }

    /**
     * Send each class teacher the schedule for THEIR class only.
     *
     * A JS1 class teacher has no use for the SS3 timetable, and sending everyone
     * everything is how a schedule stops being read.
     *
     * @return array{sent:int,skipped:int}
     */
    public function notify_class_teachers( int $school_id, int $series_id ): array {
        global $wpdb;

        $papers   = Schema::table( 'exam_papers' );
        $classes  = Schema::table( 'classes' );
        $subjects = Schema::table( 'subjects_v2' );
        $assign   = Schema::table( 'staff_assignments' );
        $staff    = Schema::table( 'staff' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id AS class_id, c.display_name AS class_name,
                        st.wp_user_id, st.first_name,
                        s.name AS subject_name, p.scheduled_at, p.duration_seconds
                 FROM {$papers} p
                 INNER JOIN {$classes} c
                         ON c.school_id = p.school_id AND c.level_id = p.level_id
                        AND COALESCE(c.department_id,0) = COALESCE(p.department_id,0)
                        AND c.status = 'active'
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 INNER JOIN {$assign} a
                         ON a.school_id = p.school_id AND a.class_id = c.id
                        AND a.assignment_type = 'class_teacher' AND a.status = 'active'
                 INNER JOIN {$staff} st ON st.id = a.staff_id
                 WHERE p.school_id = %d AND p.series_id = %d
                 ORDER BY c.display_name ASC, p.scheduled_at ASC",
                $school_id,
                $series_id
            ),
            ARRAY_A
        );

        $by_class = [];

        foreach ( $rows as $row ) {
            $class_id = (int) $row['class_id'];

            $by_class[ $class_id ]['name']    = (string) $row['class_name'];
            $by_class[ $class_id ]['user_id'] = absint( $row['wp_user_id'] );
            $by_class[ $class_id ]['teacher'] = (string) $row['first_name'];
            $by_class[ $class_id ]['lines'][] = sprintf(
                '%s - %s (%d minutes)',
                (string) $row['subject_name'],
                mysql2date( 'j M Y, g:ia', (string) $row['scheduled_at'] ),
                (int) round( (int) $row['duration_seconds'] / 60 )
            );
        }

        $notifier = new NotificationService();
        $sent     = 0;
        $skipped  = 0;

        foreach ( $by_class as $class ) {
            if ( empty( $class['user_id'] ) ) {
                $skipped++;
                continue;
            }

            $id = $notifier->notify(
                $school_id,
                absint( $class['user_id'] ),
                NotificationService::EXAM_SCHEDULED,
                'Examination timetable - ' . $class['name'],
                trim( (string) $class['teacher'] ) . ', here is the examination timetable for '
                    . $class['name'] . ":\n\n" . implode( "\n", $class['lines'] ),
                home_url( '/portal/exams/timetable/' )
            );

            if ( $id > 0 ) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        return [ 'sent' => $sent, 'skipped' => $skipped ];
    }

}
