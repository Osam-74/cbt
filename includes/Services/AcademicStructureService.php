<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 3 — class structure and subject offerings.
 *
 * Two jobs:
 *
 *   1. Create classes as (level x arm x department) rather than as a free-text
 *      name, so "JSS 1A" cannot drift from "JSS1 A" and orphan its own history.
 *
 *   2. Enforce the subject-registration rules a Nigerian secondary school actually
 *      runs on. This matters more than it looks: it is the rule that stops a
 *      student being shown an exam paper for a subject they do not offer, which
 *      is otherwise a whole class of support ticket.
 */
class AcademicStructureService {

    // ---------------------------------------------------------------
    // Classes
    // ---------------------------------------------------------------

    /**
     * @return array{success:bool,class_id?:int,error?:string}
     */
    public function create_class( int $school_id, int $level_id, string $arm, ?int $department_id = null, int $capacity = 0 ): array {
        global $wpdb;

        $arm = strtoupper( trim( $arm ) );

        if ( $arm !== '' && ! preg_match( '/^[A-Z]{1,3}$/', $arm ) ) {
            return [ 'success' => false, 'error' => 'invalid_arm' ];
        }

        $level = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'class_levels' ) . ' WHERE id = %d AND school_id = %d',
                $level_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $level ) {
            return [ 'success' => false, 'error' => 'level_not_found' ];
        }

        // Departments are a senior-school concept. A junior class carrying one would
        // silently break subject offering lookups.
        if ( $department_id && (string) $level['stage'] !== 'senior' ) {
            return [ 'success' => false, 'error' => 'department_not_allowed_for_junior' ];
        }

        $display = trim( (string) $level['code'] . ( $arm !== '' ? ' ' . $arm : '' ) );

        // Include the department. A senior school routinely has SS1 Science and SS1
        // Commercial; showing both as "SS1 A" makes every dropdown a guess.
        if ( $department_id ) {
            $department_name = (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT name FROM ' . Schema::table( 'departments' ) . ' WHERE id = %d',
                    absint( $department_id )
                )
            );

            if ( $department_name !== '' ) {
                $display .= ' ' . $department_name;
            }
        }

        // The UNIQUE key on (school, level, department, arm) does NOT catch this when
        // department_id is NULL, because in SQL NULL never equals NULL — so every
        // junior class could be created twice over. Check explicitly.
        $classes_table = Schema::table( 'classes' );

        $duplicate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$classes_table}
                 WHERE school_id = %d AND level_id = %d AND arm = %s
                   AND COALESCE(department_id, 0) = %d AND status = 'active'
                 LIMIT 1",
                $school_id,
                $level_id,
                $arm,
                $department_id ? absint( $department_id ) : 0
            )
        );

        if ( $duplicate ) {
            return [ 'success' => false, 'error' => 'duplicate_class' ];
        }

        $inserted = $wpdb->insert(
            Schema::table( 'classes' ),
            [
                'school_id'     => $school_id,
                'level_id'      => $level_id,
                'department_id' => $department_id,
                'arm'           => $arm,
                'display_name'  => $display,
                'capacity'      => max( 0, $capacity ),
                'status'        => 'active',
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            // The unique key on (school, level, department, arm) is what caught this.
            return [ 'success' => false, 'error' => 'duplicate_class' ];
        }

        return [ 'success' => true, 'class_id' => absint( $wpdb->insert_id ) ];
    }

    /**
     * Create every arm of a level in one action — schools set up A, B, C at once.
     *
     * @return array{created:int,errors:array<int,string>}
     */
    public function create_arms( int $school_id, int $level_id, array $arms, ?int $department_id = null ): array {
        $created = 0;
        $errors  = [];

        foreach ( $arms as $arm ) {
            $result = $this->create_class( $school_id, $level_id, (string) $arm, $department_id );

            if ( $result['success'] ) {
                $created++;
            } else {
                $errors[] = $arm . ':' . ( $result['error'] ?? 'unknown' );
            }
        }

        return [ 'created' => $created, 'errors' => $errors ];
    }

    public function list_classes( int $school_id, ?int $level_id = null ): array {
        global $wpdb;

        $classes = Schema::table( 'classes' );
        $levels  = Schema::table( 'class_levels' );

        $sql = "SELECT c.*, l.name AS level_name, l.code AS level_code, l.stage, l.level_order
                FROM {$classes} c
                INNER JOIN {$levels} l ON l.id = c.level_id
                WHERE c.school_id = %d AND c.status = 'active'";

        $params = [ $school_id ];

        if ( $level_id !== null ) {
            $sql     .= ' AND c.level_id = %d';
            $params[] = $level_id;
        }

        $sql .= ' ORDER BY l.level_order ASC, c.arm ASC';

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    // ---------------------------------------------------------------
    // Subject offerings
    // ---------------------------------------------------------------

    /**
     * Which subjects are available to a given level and department.
     */
    public function offerings( int $school_id, int $level_id, ?int $department_id = null ): array {
        global $wpdb;

        $subjects = Schema::table( 'subjects_v2' );
        $levels   = Schema::table( 'class_levels' );

        $stage = $wpdb->get_var(
            $wpdb->prepare( "SELECT stage FROM {$levels} WHERE id = %d AND school_id = %d", $level_id, $school_id )
        );

        if ( ! $stage ) {
            return [];
        }

        $sql = "SELECT * FROM {$subjects}
                WHERE school_id = %d AND status = 'active' AND stage IN (%s, 'both')
                AND ( department_id IS NULL";

        $params = [ $school_id, $stage ];

        if ( $department_id ) {
            $sql     .= ' OR department_id = %d';
            $params[] = $department_id;
        }

        $sql .= ' ) ORDER BY is_compulsory DESC, name ASC';

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Registration rules, configurable per school but defaulting to standard
     * Nigerian practice.
     *
     * @return array{min:int,max:int,mandatory_codes:array<int,string>,min_departmental:int}
     */
    public function registration_rules( int $school_id, string $stage ): array {
        $settings = get_option( 'educbt_subject_rules_' . $school_id, [] );

        if ( is_array( $settings ) && isset( $settings[ $stage ] ) ) {
            return array_merge( $this->default_rules( $stage ), (array) $settings[ $stage ] );
        }

        return $this->default_rules( $stage );
    }

    private function default_rules( string $stage ): array {
        if ( $stage === 'junior' ) {
            return [
                'min'              => 9,
                'max'              => 13,
                'mandatory_codes'  => [ 'ENG-J', 'MTH-J' ],
                'min_departmental' => 0,
            ];
        }

        // SSCE candidates sit eight or nine subjects.
        return [
            'min'              => 8,
            'max'              => 9,
            'mandatory_codes'  => [ 'ENG', 'MTH', 'CVE' ],
            'min_departmental' => 3,
        ];
    }

    /**
     * @param array<int,int> $subject_ids
     * @return array{valid:bool,errors:array<int,string>,count:int}
     */
    public function validate_registration( int $school_id, int $level_id, ?int $department_id, array $subject_ids ): array {
        global $wpdb;

        $errors      = [];
        $subject_ids = array_values( array_unique( array_map( 'absint', $subject_ids ) ) );
        $count       = count( $subject_ids );

        $stage = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT stage FROM ' . Schema::table( 'class_levels' ) . ' WHERE id = %d AND school_id = %d',
                $level_id,
                $school_id
            )
        );

        if ( $stage === '' ) {
            return [ 'valid' => false, 'errors' => [ 'level_not_found' ], 'count' => $count ];
        }

        $rules = $this->registration_rules( $school_id, $stage );

        if ( $count < $rules['min'] ) {
            $errors[] = 'too_few_subjects:' . $count . '/' . $rules['min'];
        }

        if ( $count > $rules['max'] ) {
            $errors[] = 'too_many_subjects:' . $count . '/' . $rules['max'];
        }

        if ( empty( $subject_ids ) ) {
            return [ 'valid' => false, 'errors' => $errors ?: [ 'no_subjects' ], 'count' => 0 ];
        }

        $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, code, category, department_id, stage FROM ' . Schema::table( 'subjects_v2' ) .
                " WHERE school_id = %d AND id IN ({$placeholders})",
                array_merge( [ $school_id ], $subject_ids )
            ),
            ARRAY_A
        );

        if ( count( $rows ) !== $count ) {
            $errors[] = 'unknown_subject_in_selection';
        }

        $codes         = array_column( $rows, 'code' );
        $departmental  = 0;
        $wrong_stage   = 0;
        $wrong_dept    = 0;

        foreach ( $rows as $row ) {
            if ( (string) $row['stage'] !== 'both' && (string) $row['stage'] !== $stage ) {
                $wrong_stage++;
            }

            $subject_dept = $row['department_id'] !== null ? absint( $row['department_id'] ) : null;

            if ( $subject_dept !== null ) {
                if ( $department_id !== null && $subject_dept === absint( $department_id ) ) {
                    $departmental++;
                } elseif ( $department_id !== null ) {
                    $wrong_dept++;
                }
            }
        }

        foreach ( $rules['mandatory_codes'] as $code ) {
            if ( ! in_array( $code, $codes, true ) ) {
                $errors[] = 'missing_compulsory:' . $code;
            }
        }

        if ( $wrong_stage > 0 ) {
            $errors[] = 'subject_wrong_stage:' . $wrong_stage;
        }

        if ( $wrong_dept > 0 ) {
            $errors[] = 'subject_outside_department:' . $wrong_dept;
        }

        if ( $rules['min_departmental'] > 0 && $department_id !== null && $departmental < $rules['min_departmental'] ) {
            $errors[] = 'too_few_departmental:' . $departmental . '/' . $rules['min_departmental'];
        }

        return [ 'valid' => empty( $errors ), 'errors' => array_values( array_unique( $errors ) ), 'count' => $count ];
    }

    /**
     * @return array{success:bool,errors?:array<int,string>,registered?:int}
     */
    public function register_student_subjects( int $school_id, int $student_id, int $session_id, int $level_id, ?int $department_id, array $subject_ids ): array {
        $check = $this->validate_registration( $school_id, $level_id, $department_id, $subject_ids );

        if ( ! $check['valid'] ) {
            return [ 'success' => false, 'errors' => $check['errors'] ];
        }

        global $wpdb;

        $table = Schema::table( 'student_subjects' );

        $wpdb->query( 'START TRANSACTION' );

        $wpdb->delete(
            $table,
            [ 'student_id' => $student_id, 'session_id' => $session_id ],
            [ '%d', '%d' ]
        );

        foreach ( array_unique( array_map( 'absint', $subject_ids ) ) as $subject_id ) {
            $wpdb->insert(
                $table,
                [
                    'school_id'  => $school_id,
                    'student_id' => $student_id,
                    'subject_id' => $subject_id,
                    'session_id' => $session_id,
                ],
                [ '%d', '%d', '%d', '%d' ]
            );
        }

        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_subjects_registered', [
            'school_id'  => $school_id,
            'student_id' => $student_id,
            'session_id' => $session_id,
            'count'      => $check['count'],
        ] );

        return [ 'success' => true, 'registered' => $check['count'] ];
    }


    // ---------------------------------------------------------------
    // Two-stage registration: teacher sets the core, student picks electives
    // ---------------------------------------------------------------

    /**
     * Split a level's offerings into what is decided FOR a student and what is
     * decided BY them. This is what the two registration screens render from.
     *
     * @return array{core:array<int,array<string,mixed>>,electives:array<int,array<string,mixed>>,rules:array<string,mixed>}
     */
    public function offering_split( int $school_id, int $level_id, ?int $department_id = null ): array {
        global $wpdb;

        $stage = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT stage FROM ' . Schema::table( 'class_levels' ) . ' WHERE id = %d AND school_id = %d',
                $level_id,
                $school_id
            )
        );

        $rules     = $this->registration_rules( $school_id, $stage ?: 'senior' );
        $offerings = $this->offerings( $school_id, $level_id, $department_id );

        $core      = [];
        $electives = [];

        foreach ( $offerings as $subject ) {
            $is_core = ! empty( $subject['is_compulsory'] )
                || in_array( (string) $subject['code'], $rules['mandatory_codes'], true );

            if ( $is_core ) {
                $core[] = $subject;
            } else {
                $electives[] = $subject;
            }
        }

        $rules['core_count']       = count( $core );
        $rules['electives_needed'] = max( 0, $rules['min'] - count( $core ) );

        return [ 'core' => $core, 'electives' => $electives, 'rules' => $rules ];
    }

    /**
     * A class teacher registers the compulsory subjects for EVERY student in their
     * class in one action.
     *
     * This deliberately does NOT complete registration. Core subjects alone are below
     * the minimum, so each student is left in a `pending_electives` state and must
     * finish the job themselves. Registering electives on a student's behalf is how
     * a school ends up with forty students silently entered for a subject none of
     * them chose.
     *
     * Existing elective choices are preserved: re-running this after some students
     * have already chosen must not wipe their work.
     *
     * @return array{students:int,core_subjects:int,already_complete:int,pending:array<int,int>}
     */
    public function register_core_for_class( int $school_id, int $class_id, int $session_id ): array {
        global $wpdb;

        $class = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'classes' ) . ' WHERE id = %d AND school_id = %d',
                $class_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $class ) {
            return [ 'students' => 0, 'core_subjects' => 0, 'already_complete' => 0, 'pending' => [] ];
        }

        $department_id = $class['department_id'] !== null ? absint( $class['department_id'] ) : null;
        $split         = $this->offering_split( $school_id, absint( $class['level_id'] ), $department_id );
        $core_ids      = array_map( static fn( array $s ): int => absint( $s['id'] ), $split['core'] );

        $students = (array) $wpdb->get_col(
            $wpdb->prepare(
                'SELECT student_id FROM ' . Schema::table( 'enrollments' ) .
                " WHERE school_id = %d AND class_id = %d AND session_id = %d AND status = 'active'",
                $school_id,
                $class_id,
                $session_id
            )
        );

        $table            = Schema::table( 'student_subjects' );
        $pending          = [];
        $already_complete = 0;

        foreach ( array_map( 'absint', $students ) as $student_id ) {
            foreach ( $core_ids as $subject_id ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$table} (school_id, student_id, subject_id, session_id) VALUES (%d, %d, %d, %d)",
                        $school_id,
                        $student_id,
                        $subject_id,
                        $session_id
                    )
                );
            }

            $total = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND session_id = %d",
                        $student_id,
                        $session_id
                    )
                )
            );

            if ( $total >= absint( $split['rules']['min'] ) ) {
                $already_complete++;
            } else {
                $pending[] = $student_id;
            }
        }

        EventDispatcher::action( 'educbt_core_subjects_registered', [
            'school_id'  => $school_id,
            'class_id'   => $class_id,
            'session_id' => $session_id,
            'students'   => count( $students ),
            'pending'    => count( $pending ),
        ] );

        return [
            'students'         => count( $students ),
            'core_subjects'    => count( $core_ids ),
            'already_complete' => $already_complete,
            'pending'          => $pending,
        ];
    }

    /**
     * What a student sees on their own registration screen: their locked core, the
     * electives available to them, what they have chosen, and how many more they owe.
     *
     * @return array<string,mixed>
     */
    public function student_registration_view( int $school_id, int $student_id, int $session_id ): array {
        global $wpdb;

        $enrollment = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT e.*, c.level_id, c.department_id, c.display_name
                 FROM ' . Schema::table( 'enrollments' ) . ' e
                 INNER JOIN ' . Schema::table( 'classes' ) . " c ON c.id = e.class_id
                 WHERE e.student_id = %d AND e.session_id = %d AND e.status = 'active' LIMIT 1",
                $student_id,
                $session_id
            ),
            ARRAY_A
        );

        if ( ! $enrollment ) {
            return [ 'error' => 'not_enrolled' ];
        }

        $department_id = $enrollment['department_id'] !== null ? absint( $enrollment['department_id'] ) : null;
        $split         = $this->offering_split( $school_id, absint( $enrollment['level_id'] ), $department_id );

        $chosen = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT subject_id FROM ' . Schema::table( 'student_subjects' ) .
                    ' WHERE student_id = %d AND session_id = %d',
                    $student_id,
                    $session_id
                )
            )
        );

        $core_ids = array_map( static fn( array $s ): int => absint( $s['id'] ), $split['core'] );

        foreach ( $split['electives'] as &$elective ) {
            $elective['selected'] = in_array( absint( $elective['id'] ), $chosen, true );
        }
        unset( $elective );

        $elective_count = count( array_diff( $chosen, $core_ids ) );
        $total          = count( $chosen );
        $rules          = $split['rules'];

        return [
            'class'             => (string) $enrollment['display_name'],
            'core'              => $split['core'],
            'core_registered'   => ! empty( array_intersect( $core_ids, $chosen ) ),
            'electives'         => $split['electives'],
            'selected_total'    => $total,
            'selected_electives' => $elective_count,
            'minimum'           => absint( $rules['min'] ),
            'maximum'           => absint( $rules['max'] ),
            'still_to_choose'   => max( 0, absint( $rules['min'] ) - $total ),
            'may_add'           => max( 0, absint( $rules['max'] ) - $total ),
            'locked'            => $this->registration_locked( $school_id, $session_id ),
            'complete'          => $total >= absint( $rules['min'] ) && $total <= absint( $rules['max'] ),
        ];
    }

    /**
     * A student adds or removes their own electives.
     *
     * Three protections, all of which matter:
     *
     *  - A CORE subject can never be dropped by a student. The teacher decided it.
     *  - Registration closes once the window is locked, so nobody adds a subject the
     *    day before an exam they have done no work in.
     *  - A subject already sat, or with a mark recorded, cannot be dropped. Dropping
     *    it would orphan the score and silently alter an average.
     *
     * @param array<int,int> $elective_ids
     * @return array{success:bool,total?:int,errors?:array<int,string>}
     */
    public function set_student_electives( int $school_id, int $student_id, int $session_id, array $elective_ids ): array {
        global $wpdb;

        if ( $this->registration_locked( $school_id, $session_id ) ) {
            return [ 'success' => false, 'errors' => [ 'registration_closed' ] ];
        }

        $view = $this->student_registration_view( $school_id, $student_id, $session_id );

        if ( isset( $view['error'] ) ) {
            return [ 'success' => false, 'errors' => [ $view['error'] ] ];
        }

        $core_ids      = array_map( static fn( array $s ): int => absint( $s['id'] ), $view['core'] );
        $allowed_ids   = array_map( static fn( array $s ): int => absint( $s['id'] ), $view['electives'] );
        $elective_ids  = array_values( array_unique( array_map( 'absint', $elective_ids ) ) );

        $unknown = array_diff( $elective_ids, $allowed_ids );

        if ( ! empty( $unknown ) ) {
            return [ 'success' => false, 'errors' => [ 'subject_not_available_to_you' ] ];
        }

        $final = array_values( array_unique( array_merge( $core_ids, $elective_ids ) ) );

        $enrollment = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT e.class_id, c.level_id, c.department_id FROM ' . Schema::table( 'enrollments' ) . ' e
                 INNER JOIN ' . Schema::table( 'classes' ) . ' c ON c.id = e.class_id
                 WHERE e.student_id = %d AND e.session_id = %d LIMIT 1',
                $student_id,
                $session_id
            ),
            ARRAY_A
        );

        $check = $this->validate_registration(
            $school_id,
            absint( $enrollment['level_id'] ?? 0 ),
            $enrollment['department_id'] !== null ? absint( $enrollment['department_id'] ) : null,
            $final
        );

        if ( ! $check['valid'] ) {
            return [ 'success' => false, 'errors' => $check['errors'] ];
        }

        // Anything already examined or marked is immovable.
        $protected = $this->subjects_with_activity( $school_id, $student_id, $session_id );
        $dropped   = array_diff( $protected, $final );

        if ( ! empty( $dropped ) ) {
            return [ 'success' => false, 'errors' => [ 'cannot_drop_a_subject_already_assessed' ] ];
        }

        $table = Schema::table( 'student_subjects' );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->delete( $table, [ 'student_id' => $student_id, 'session_id' => $session_id ], [ '%d', '%d' ] );

        foreach ( $final as $subject_id ) {
            $wpdb->insert(
                $table,
                [ 'school_id' => $school_id, 'student_id' => $student_id, 'subject_id' => $subject_id, 'session_id' => $session_id ],
                [ '%d', '%d', '%d', '%d' ]
            );
        }

        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_electives_selected', [
            'school_id'  => $school_id,
            'student_id' => $student_id,
            'session_id' => $session_id,
            'total'      => count( $final ),
        ] );

        return [ 'success' => true, 'total' => count( $final ) ];
    }

    /**
     * Subjects the student has already been assessed in this session.
     *
     * @return array<int,int>
     */
    private function subjects_with_activity( int $school_id, int $student_id, int $session_id ): array {
        global $wpdb;

        $scores = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT DISTINCT subject_id FROM ' . Schema::table( 'assessment_scores' ) .
                    ' WHERE school_id = %d AND student_id = %d AND session_id = %d',
                    $school_id,
                    $student_id,
                    $session_id
                )
            )
        );

        $sat = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT DISTINCT p.subject_id FROM ' . Schema::table( 'attempts' ) . ' a
                     INNER JOIN ' . Schema::table( 'exam_papers' ) . ' p ON p.id = a.paper_id
                     WHERE a.school_id = %d AND a.student_id = %d',
                    $school_id,
                    $student_id
                )
            )
        );

        return array_values( array_unique( array_merge( $scores, $sat ) ) );
    }

    public function registration_locked( int $school_id, int $session_id ): bool {
        return (bool) get_option( 'educbt_registration_locked_' . $school_id . '_' . $session_id, false );
    }

    /**
     * Close registration for a session. Typically done once the exam timetable is
     * published.
     */
    public function set_registration_lock( int $school_id, int $session_id, bool $locked ): bool {
        return update_option( 'educbt_registration_locked_' . $school_id . '_' . $session_id, $locked ? 1 : 0, false );
    }

    /**
     * Students who have not finished choosing. This is the list a class teacher chases,
     * and it must be visible BEFORE the timetable is published rather than discovered
     * when a student cannot open a paper.
     *
     * @return array<int,array<string,mixed>>
     */
    public function incomplete_registrations( int $school_id, int $class_id, int $session_id ): array {
        global $wpdb;

        $class = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT level_id, department_id FROM ' . Schema::table( 'classes' ) . ' WHERE id = %d AND school_id = %d',
                $class_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $class ) {
            return [];
        }

        $split   = $this->offering_split(
            $school_id,
            absint( $class['level_id'] ),
            $class['department_id'] !== null ? absint( $class['department_id'] ) : null
        );
        $minimum = absint( $split['rules']['min'] );

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id AS student_id, st.admission_number, st.first_name, st.last_name,
                        COUNT(rs.id) AS registered_count, %d AS required
                 FROM {$enrollments} e
                 INNER JOIN {$students} st ON st.id = e.student_id
                 LEFT JOIN {$registered} rs ON rs.student_id = st.id AND rs.session_id = e.session_id
                 WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
                 GROUP BY st.id
                 HAVING registered_count < %d
                 ORDER BY st.last_name ASC",
                $minimum,
                $school_id,
                $class_id,
                $session_id,
                $minimum
            ),
            ARRAY_A
        );
    }
    /**
     * Auto-select the compulsory subjects for a level so a registration form opens
     * pre-filled rather than blank.
     *
     * @return array<int,int>
     */
    public function default_selection( int $school_id, int $level_id, ?int $department_id = null ): array {
        $offerings = $this->offerings( $school_id, $level_id, $department_id );
        $selected  = [];

        foreach ( $offerings as $subject ) {
            if ( ! empty( $subject['is_compulsory'] ) ) {
                $selected[] = absint( $subject['id'] );
            }
        }

        return $selected;
    }
}
