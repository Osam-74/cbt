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
        $exam['is_practice']   = (bool) ( $timetable['is_practice'] ?? $timetable['is_trial_mode'] ?? 0 );
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
     * How the layout works, and why:
     *
     * An examination is a WEEK, not a queue. The previous version walked papers one
     * at a time, filling three slots a day and rolling to the next date whenever it
     * ran out — so it spread a five-subject exam across two weeks for no reason, and
     * left the exam office with a start date as the only control.
     *
     * The real constraint is much narrower than "one paper per slot": two papers may
     * run at the same time as long as no CLASS has to sit both. JS1 Mathematics and
     * SS2 Physics at 9am on Monday is normal — different students, different halls.
     * So slots are filled in parallel, and the only thing that forces a paper later
     * is a class already sitting something in that slot.
     *
     * Days are Monday to Friday. The generator fills the first week, and only spills
     * into the following week when a genuine clash leaves no room. If it still
     * cannot place a paper after the cap, it reports it rather than inventing a
     * Saturday.
     *
     * @return array{success:bool,created:int,skipped:int,unplaced:int,days:int,error?:string}
     */
    public function generate_for_series( int $school_id, int $series_id, string $starts_on = '', array $slots = [], string $ends_on = '', bool $regenerate = false ): array {
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
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'unplaced' => 0, 'days' => 0, 'error' => 'series_not_found' ];
        }

        $sets    = Schema::table( 'question_sets' );
        $papers  = Schema::table( 'exam_papers' );
        $classes = Schema::table( 'classes' );

        // When regenerating, delete old papers and invigilators for this series
        // so invigilators are re-assigned and manual adjustments are reset.
        if ( $regenerate ) {
            $invig_t = Schema::table( 'paper_invigilators' );
            $paper_ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$papers} WHERE school_id = %d AND series_id = %d AND status <> 'cancelled'",
                    $school_id,
                    $series_id
                )
            );
            if ( ! empty( $paper_ids ) ) {
                $pid_list = implode( ',', array_map( 'absint', $paper_ids ) );
                $wpdb->query( "DELETE FROM {$invig_t} WHERE paper_id IN ({$pid_list})" );
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$papers} WHERE school_id = %d AND series_id = %d AND status <> 'cancelled'",
                        $school_id,
                        $series_id
                    )
                );
            }
        }

        $approved = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT subject_id, level_id, department_id,
                        SUM(CASE WHEN exam_type = 'objective' THEN min_required ELSE 0 END) AS objective_min,
                        SUM(CASE WHEN exam_type = 'theory'   THEN min_required ELSE 0 END) AS theory_min,
                        MAX(delivery_mode) AS delivery_mode
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
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'unplaced' => 0, 'days' => 0, 'error' => 'nothing_approved' ];
        }

        if ( empty( $slots ) ) {
            $slots = [ '09:00:00', '11:30:00', '14:00:00' ];
        }

        // Start on the given day, or the series' own start, rolled forward to Monday
        // if it lands on a weekend.
        $start = $starts_on !== '' ? $starts_on : (string) ( $series['starts_on'] ?: current_time( 'Y-m-d' ) );
        $start = $this->next_weekday( $start );

        // Two weeks of weekdays is the ceiling. Beyond that something is wrong with
        // the school's setup, not with the schedule.
        // The sitting period the school has set aside. Papers are laid out inside it
        // and nowhere else — a schedule that runs past the last day is a schedule the
        // school cannot honour, and it is better to report the overflow than to
        // quietly book a hall on a day nobody expects to be sitting.
        $last = $ends_on !== '' ? $ends_on : (string) ( $series['ends_on'] ?? '' );

        $days = [];
        $day  = $start;

        for ( $i = 0; $i < 10; $i++ ) {
            if ( $last !== '' && strtotime( $day ) > strtotime( $last ) ) {
                break;
            }

            $days[] = $day;
            $day    = $this->next_weekday( gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) );
        }

        if ( empty( $days ) ) {
            return [
                'success'  => false,
                'created'  => 0,
                'skipped'  => 0,
                'unplaced' => 0,
                'days'     => 0,
                'error'    => 'window_too_short',
            ];
        }

        // What is already booked, so re-running never double-books a class and never
        // disturbs a paper the exam office has already moved by hand.
        //
        // Scoped to the school and TERM, not just this series. A subject appearing
        // in two different examination series for the same term (e.g. someone
        // creating a second series by mistake) must still be recognised as
        // "already scheduled" — otherwise the generator places a second paper
        // for the same subject and level, which shows up twice on every
        // affected student's dashboard.
        $occupied = [];   // "date time" => [ class_id, class_id, ... ]
        $series_t  = Schema::table( 'exam_series' );
        $existing = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.subject_id, p.level_id, p.department_id, p.scheduled_at
                 FROM {$papers} p
                 INNER JOIN {$series_t} es ON es.id = p.series_id
                 WHERE p.school_id = %d AND p.status <> 'cancelled'
                   AND es.session_id = %d AND es.term_id = %d",
                $school_id,
                absint( $series['session_id'] ),
                absint( $series['term_id'] )
            ),
            ARRAY_A
        );

        $already = [];

        foreach ( $existing as $row ) {
            $key             = absint( $row['subject_id'] ) . ':' . absint( $row['level_id'] ) . ':' . absint( $row['department_id'] );
            $already[ $key ] = true;

            // Convert UTC (as stored) back to local so the in-memory clash
            // detection compares like-for-like with the local slot strings.
            $when = substr( get_date_from_gmt( (string) $row['scheduled_at'] ), 0, 16 );
            foreach ( $this->classes_for_scope( $school_id, absint( $row['level_id'] ), absint( $row['department_id'] ) ) as $cid ) {
                $occupied[ $when ][] = $cid;
            }
        }

        $created  = 0;
        $skipped  = 0;
        $unplaced = 0;
        $last_day = $start;

        foreach ( $approved as $row ) {
            $subject_id    = absint( $row['subject_id'] );
            $level_id      = absint( $row['level_id'] );
            $department_id = absint( $row['department_id'] );

            if ( isset( $already[ $subject_id . ':' . $level_id . ':' . $department_id ] ) ) {
                $skipped++;
                continue;
            }

            $scope_classes = $this->classes_for_scope( $school_id, $level_id, $department_id );

            if ( empty( $scope_classes ) ) {
                $unplaced++;
                continue;
            }

            // Earliest slot where none of this paper's classes is already sitting.
            $placed_at = '';

            foreach ( $days as $candidate_day ) {
                foreach ( $slots as $slot ) {
                    $when = $candidate_day . ' ' . substr( $slot, 0, 5 );
                    $busy = $occupied[ $when ] ?? [];

                    if ( array_intersect( $scope_classes, $busy ) ) {
                        continue;   // one of these classes is already sitting a paper
                    }

                    $placed_at = $candidate_day . ' ' . $slot;
                    $occupied[ $when ] = array_merge( $busy, $scope_classes );
                    break 2;
                }
            }

            if ( $placed_at === '' ) {
                $unplaced++;
                continue;
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

            // Written papers don't need an access code — students sit with printed
            // papers, not at computers entering a code.
            $paper_delivery_mode = (string) ( $row['delivery_mode'] ?? 'cbt' );
            if ( ! in_array( $paper_delivery_mode, [ 'cbt', 'written' ], true ) ) {
                $paper_delivery_mode = 'cbt';
            }

            $access_code = '';
            $requires_code = 0;
            if ( $paper_delivery_mode === 'cbt' ) {
                $alphabet = 'BCDFGHJKMNPQRSTVWXYZ23456789';
                for ( $i = 0; $i < 6; $i++ ) {
                    $access_code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
                }
                $requires_code = 1;
            }

            // Duration: derive from the series type and question count so a 15-minute
            // CA test is not given a 60-minute window. CA tests are shorter; the
            // window (closes_at) extends to 2 hours after start so schools with
            // limited computers can rotate students within it.
            $question_total = absint( $row['objective_min'] ) + absint( $row['theory_min'] );
            if ( (string) $series['series_type'] === 'ca_test' ) {
                // CA test: 1 minute per question, minimum 15 minutes
                $duration_seconds = max( $question_total * 60, 900 );
            } else {
                // Exam: 2 minutes per question, minimum 60 minutes
                $duration_seconds = max( $question_total * 120, 3600 );
            }
            // The window for logging in is always generous (start + 2 hours),
            // regardless of the test duration — this is the rotation window for
            // schools with limited computers. The actual test duration stays
            // what was set above.
            $closes_at = get_gmt_from_date( gmdate( 'Y-m-d H:i:s', strtotime( $placed_at ) + 7200 ) );

            $wpdb->insert(
                $papers,
                [
                    'school_id'            => $school_id,
                    'series_id'            => $series_id,
                    'subject_id'           => $subject_id,
                    'class_id'             => $representative ?: null,
                    'level_id'             => $level_id,
                    'department_id'        => $department_id ?: null,
                    'scheduled_at'         => get_gmt_from_date( $placed_at ),
                    'closes_at'            => $closes_at,
                    'duration_seconds'     => $duration_seconds,
                    'question_count'       => absint( $row['objective_min'] ) + absint( $row['theory_min'] ),
                    'objective_per_student' => absint( $row['objective_min'] ),
                    'access_code'          => $access_code,
                    'requires_access_code' => $requires_code,
                    'delivery_mode'        => $paper_delivery_mode,
                    'status'               => 'draft',
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
            );

            $paper_id = absint( $wpdb->insert_id );

            if ( $paper_id > 0 ) {
                $created++;
                $utc_scheduled = get_gmt_from_date( $placed_at );
                $this->assign_invigilator( $school_id, $paper_id, $subject_id, $level_id, $department_id, $utc_scheduled );

                if ( substr( $placed_at, 0, 10 ) > $last_day ) {
                    $last_day = substr( $placed_at, 0, 10 );
                }
            }
        }

        $span = (int) round( ( strtotime( $last_day ) - strtotime( $start ) ) / 86400 ) + 1;

        // Mark the series as 'scheduled' once papers have been generated,
        // so the examinations table shows 'Timetable Built' instead of 'Draft'.
        if ( $created > 0 ) {
            $wpdb->update(
                Schema::table( 'exam_series' ),
                [ 'status' => 'scheduled' ],
                [ 'id' => $series_id, 'school_id' => $school_id ],
                [ '%s' ],
                [ '%d', '%d' ]
            );
        }

        return [
            'success'  => true,
            'created'  => $created,
            'skipped'  => $skipped,
            'unplaced' => $unplaced,
            'days'     => max( 1, $span ),
        ];
    }

    /**
     * Roll a date forward to the next Monday-to-Friday day.
     */
    private function next_weekday( string $date ): string {
        $ts = strtotime( $date ) ?: time();

        while ( in_array( (int) gmdate( 'N', $ts ), [ 6, 7 ], true ) ) {
            $ts = strtotime( '+1 day', $ts );
        }

        return gmdate( 'Y-m-d', $ts );
    }

    /**
     * Every active class arm covered by a level + department.
     *
     * @return array<int,int>
     */
    private function classes_for_scope( int $school_id, int $level_id, int $department_id ): array {
        global $wpdb;

        static $cache = [];

        $key = $school_id . ':' . $level_id . ':' . $department_id;

        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }

        $sql    = 'SELECT id FROM ' . Schema::table( 'classes' ) . "
                   WHERE school_id = %d AND level_id = %d AND status = 'active'";
        $params = [ $school_id, $level_id ];

        if ( $department_id > 0 ) {
            $sql     .= ' AND COALESCE(department_id,0) = %d';
            $params[] = $department_id;
        }

        $cache[ $key ] = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );

        // If no real classes match this scope, use a synthetic key so papers for
        // the same level/department still conflict with each other. Without this,
        // an empty result means array_intersect never fires and every paper lands
        // on the first slot of the first day — all at the same time.
        if ( empty( $cache[ $key ] ) ) {
            $cache[ $key ] = [ 'scope_' . $level_id . '_' . $department_id ];
        }

        return $cache[ $key ];
    }

    /**
     * Put an invigilator on a paper.
     *
     * Two rules, both of which exist for a reason:
     *
     *  - Never the teacher who teaches that subject to that class. An invigilator
     *    who wrote the paper is not invigilating, they are supervising their own work.
     *  - Never someone already invigilating elsewhere in the same slot. A person
     *    cannot be in two halls at once, and a schedule that says otherwise is
     *    discovered on the morning of the exam.
     *
     * Among those eligible, the one with the fewest duties in this series is chosen,
     * so the load spreads instead of landing on whoever sorts first.
     */
    private function assign_invigilator(
        int $school_id,
        int $paper_id,
        int $subject_id,
        int $level_id,
        int $department_id,
        string $scheduled_at
    ): bool {
        global $wpdb;

        $staff   = Schema::table( 'staff' );
        $assign  = Schema::table( 'staff_assignments' );
        $invig   = Schema::table( 'paper_invigilators' );
        $papers  = Schema::table( 'exam_papers' );

        $scope_classes = $this->classes_for_scope( $school_id, $level_id, $department_id );

        if ( empty( $scope_classes ) ) {
            return false;
        }

        $class_placeholders = implode( ',', array_fill( 0, count( $scope_classes ), '%d' ) );

        $sql = "SELECT st.id,
                       (SELECT COUNT(*) FROM {$invig} pi2
                         INNER JOIN {$papers} p2 ON p2.id = pi2.paper_id
                         WHERE pi2.staff_id = st.id AND p2.series_id = (SELECT series_id FROM {$papers} WHERE id = %d)
                       ) AS duties
                FROM {$staff} st
                WHERE st.school_id = %d AND st.status = 'active'
                  /* not the subject teacher for any class sitting this paper */
                  AND st.id NOT IN (
                      SELECT a.staff_id FROM {$assign} a
                      WHERE a.school_id = %d AND a.subject_id = %d
                        AND a.class_id IN ({$class_placeholders}) AND a.status = 'active'
                  )
                  /* not already invigilating in this slot */
                  AND st.id NOT IN (
                      SELECT pi.staff_id FROM {$invig} pi
                      INNER JOIN {$papers} p ON p.id = pi.paper_id
                      WHERE p.school_id = %d AND p.scheduled_at = %s AND p.status <> 'cancelled'
                  )
                ORDER BY duties ASC, st.id ASC
                LIMIT 1";

        $params = array_merge(
            [ $paper_id, $school_id, $school_id, $subject_id ],
            $scope_classes,
            [ $school_id, $scheduled_at ]
        );

        $staff_id = absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );

        if ( $staff_id <= 0 ) {
            // Nobody eligible. Left empty deliberately — a name that breaks one of
            // the two rules is worse than a blank the exam office can see and fill.
            return false;
        }

        // Delete any stale row first, then insert fresh — the unique key
        // is (paper_id), but explicit cleanup is safe before the migration runs.
        $wpdb->delete( $invig, [ 'paper_id' => $paper_id, 'school_id' => $school_id ], [ '%d', '%d' ] );

        $wpdb->insert(
            $invig,
            [
                'school_id'     => $school_id,
                'paper_id'      => $paper_id,
                'staff_id'      => $staff_id,
                'assigned_mode' => 'auto',
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        return true;
    }

    /**
     * Is this staff member already invigilating something in this slot?
     *
     * Used by the manual reschedule form, so a hand-picked invigilator is held to
     * the same rule the generator follows.
     */
    public function invigilator_conflict( int $school_id, int $staff_id, string $scheduled_at, int $ignore_paper_id = 0 ): ?array {
        global $wpdb;

        $invig    = Schema::table( 'paper_invigilators' );
        $papers   = Schema::table( 'exam_papers' );
        $subjects = Schema::table( 'subjects_v2' );

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.id, p.scheduled_at, s.name AS subject_name
                 FROM {$invig} pi
                 INNER JOIN {$papers} p ON p.id = pi.paper_id
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 WHERE pi.staff_id = %d AND p.school_id = %d
                   AND p.scheduled_at = %s AND p.status <> 'cancelled' AND p.id <> %d
                 LIMIT 1",
                $staff_id,
                $school_id,
                $scheduled_at,
                $ignore_paper_id
            ),
            ARRAY_A
        );
    }

    /**
     * Does this staff member teach this subject to any class sitting the paper?
     */
    public function teaches_paper_subject( int $school_id, int $staff_id, int $paper_id ): bool {
        global $wpdb;

        $papers = Schema::table( 'exam_papers' );
        $assign = Schema::table( 'staff_assignments' );

        $paper = $wpdb->get_row(
            $wpdb->prepare( "SELECT subject_id, level_id, department_id FROM {$papers} WHERE id = %d AND school_id = %d", $paper_id, $school_id ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return false;
        }

        $scope_classes = $this->classes_for_scope( $school_id, absint( $paper['level_id'] ), absint( $paper['department_id'] ) );

        if ( empty( $scope_classes ) ) {
            return false;
        }

        $placeholders = implode( ',', array_fill( 0, count( $scope_classes ), '%d' ) );

        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$assign}
                 WHERE school_id = %d AND staff_id = %d AND subject_id = %d
                   AND class_id IN ({$placeholders}) AND status = 'active' LIMIT 1",
                array_merge( [ $school_id, $staff_id, absint( $paper['subject_id'] ) ], $scope_classes )
            )
        );

        return ! empty( $found );
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

        // ── 1. Class teachers: their class timetable (no access codes) ──
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
                wp_date( 'j M Y, g:ia', strtotime( (string) $row['scheduled_at'] . ' UTC' ) ),
                (int) round( (int) $row['duration_seconds'] / 60 )
            );
        }

        $notifier = new NotificationService();
        $sent     = 0;
        $skipped  = 0;
        $notified_users = []; // Track who already got a notification

        foreach ( $by_class as $class ) {
            if ( empty( $class['user_id'] ) ) {
                $skipped++;
                continue;
            }

            $notified_users[] = (int) $class['user_id'];

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

        // ── 2. All other teachers: general timetable notification ──
        // Every teacher with a portal account should know the timetable is
        // available, even if they are not a class teacher. Invigilators will
        // see their access codes in the dashboard once the series is published.
        $all_staff = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, wp_user_id, first_name, last_name
                 FROM {$staff}
                 WHERE school_id = %d AND status = 'active'
                   AND wp_user_id > 0
                 ORDER BY last_name ASC",
                $school_id
            ),
            ARRAY_A
        );

        foreach ( $all_staff as $s_member ) {
            $uid = (int) $s_member['wp_user_id'];
            if ( in_array( $uid, $notified_users, true ) ) {
                continue; // Already got the class-specific notification
            }

            $id = $notifier->notify(
                $school_id,
                $uid,
                NotificationService::EXAM_SCHEDULED,
                'Examination timetable published',
                'The examination timetable has been published. '
                    . 'View it in your portal. '
                    . 'If you are invigilating, your access codes will appear on your dashboard.',
                home_url( '/portal/exams/timetable/' )
            );

            if ( $id > 0 ) {
                $sent++;
                $notified_users[] = $uid;
            }
        }

        // ── 3. Publish the series so invigilators see access codes in dashboard ──
        // Until this is set, the timetable is a working draft and access codes
        // are hidden from the teacher dashboard.
        if ( $sent > 0 ) {
            $wpdb->update(
                Schema::table( 'exam_series' ),
                [ 'status' => 'published' ],
                [ 'id' => $series_id, 'school_id' => $school_id ],
                [ '%s' ],
                [ '%d', '%d' ]
            );
        }

        return [ 'sent' => $sent, 'skipped' => $skipped ];
    }

    /**
     * Has this timetable been released to class teachers?
     */
    public function is_released( int $school_id, int $series_id ): bool {
        global $wpdb;

        $status = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . Schema::table( 'exam_series' ) . ' WHERE id = %d AND school_id = %d',
                $series_id,
                $school_id
            )
        );

        return $status === 'published';
    }

}
