<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 4 — staff and assignments.
 *
 * The v1 teacher form asked for a teacher ID that a human had to invent. You were
 * right that this should be generated. What the form actually needs is a name and a
 * contact; everything else is either derived or is an ASSIGNMENT made separately.
 *
 * The separation matters: creating a teacher and giving them a class are different
 * acts, done by different people at different times. Collapsing them into one form
 * is why v1's role model could not express "a teacher who has not been given
 * anything yet" — which is the correct state for a new hire.
 */
class StaffService {

    /**
     * @return array{success:bool,staff_id?:int,staff_number?:string,credentials?:array,errors?:array}
     */
    public function register( int $school_id, array $data ): array {
        global $wpdb;

        $first = trim( (string) ( $data['first_name'] ?? '' ) );
        $last  = trim( (string) ( $data['last_name'] ?? '' ) );
        $email = trim( (string) ( $data['email'] ?? '' ) );

        $errors = [];

        if ( $first === '' ) {
            $errors['first_name'] = 'required';
        }

        if ( $last === '' ) {
            $errors['last_name'] = 'required';
        }

        if ( $email !== '' && ! is_email( $email ) ) {
            $errors['email'] = 'invalid';
        }

        $role = (string) ( $data['role_slug'] ?? Capabilities::ROLE_TEACHER );

        if ( ! array_key_exists( $role, Capabilities::roles() ) || $role === Capabilities::ROLE_PLATFORM_ADMIN ) {
            $errors['role_slug'] = 'invalid';
        }

        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        $staff_number = $this->allocate_staff_number( $school_id );

        $wpdb->insert(
            Schema::table( 'staff' ),
            [
                'school_id'    => $school_id,
                'staff_number' => $staff_number,
                'first_name'   => sanitize_text_field( $first ),
                'last_name'    => sanitize_text_field( $last ),
                'title'        => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
                'gender'       => sanitize_text_field( (string) ( $data['gender'] ?? '' ) ),
                'email'        => sanitize_email( $email ),
                'phone'        => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
                'photo'        => esc_url_raw( (string) ( $data['photo'] ?? '' ) ),
                'role_slug'    => $role,
                'status'       => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $staff_id    = absint( $wpdb->insert_id );
        $credentials = $this->provision_login( $school_id, $staff_id, $staff_number, $first, $last, $email, $role );

        EventDispatcher::action( 'educbt_staff_registered', [
            'school_id'    => $school_id,
            'staff_id'     => $staff_id,
            'staff_number' => $staff_number,
            'role'         => $role,
        ] );

        return [
            'success'      => true,
            'staff_id'     => $staff_id,
            'staff_number' => $staff_number,
            'credentials'  => $credentials,
        ];
    }

    /**
     * GRE/STF/001 — generated, so nobody invents or duplicates one.
     */
    public function allocate_staff_number( int $school_id ): string {
        global $wpdb;

        $code = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT school_code FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
                $school_id
            )
        );

        $prefix = ( $code !== '' ? $code : 'SCH' ) . '/STF/';
        $table  = Schema::table( 'staff' );

        $last = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT staff_number FROM {$table} WHERE school_id = %d AND staff_number LIKE %s ORDER BY id DESC LIMIT 1",
                $school_id,
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        $sequence = 1;

        if ( $last !== '' && preg_match( '/(\d+)$/', $last, $m ) ) {
            $sequence = (int) $m[1] + 1;
        }

        for ( $attempt = 0; $attempt < 50; $attempt++ ) {
            $candidate = $prefix . str_pad( (string) $sequence, 3, '0', STR_PAD_LEFT );

            $taken = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND staff_number = %s", $school_id, $candidate )
            );

            if ( ! $taken ) {
                return $candidate;
            }

            $sequence++;
        }

        return $prefix . strtoupper( wp_generate_password( 3, false, false ) );
    }

    private function provision_login( int $school_id, int $staff_id, string $staff_number, string $first, string $last, string $email, string $role ): array {
        $username = sanitize_user( str_replace( '/', '.', $staff_number ), true );
        $password = wp_generate_password( 10, false, false );

        if ( $email === '' || ! is_email( $email ) ) {
            $email = sanitize_title( $username ) . '@staff.invalid';
        }

        $user_id = wp_insert_user(
            [
                'user_login'   => $username,
                'user_pass'    => $password,
                'user_email'   => $email,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => trim( $first . ' ' . $last ),
                'role'         => $role,
            ]
        );

        if ( is_wp_error( $user_id ) ) {
            return [ 'username' => $username, 'temporary_password' => '', 'user_id' => 0 ];
        }

        $user_id = absint( $user_id );

        update_user_meta( $user_id, '_educbt_must_change_password', 1 );
        update_user_meta( $user_id, '_educbt_school_id', $school_id );

        global $wpdb;

        $wpdb->update( Schema::table( 'staff' ), [ 'wp_user_id' => $user_id ], [ 'id' => $staff_id ], [ '%d' ], [ '%d' ] );

        return [ 'username' => $username, 'temporary_password' => $password, 'user_id' => $user_id, 'must_change' => true ];
    }

    // ---------------------------------------------------------------
    // Assignments
    // ---------------------------------------------------------------

    /**
     * @return array{success:bool,assignment_id?:int,error?:string}
     */
    public function assign( int $school_id, int $staff_id, string $type, array $target, int $session_id ): array {
        global $wpdb;

        $valid_types = [ 'class_teacher', 'subject_teacher', 'hod' ];

        if ( ! in_array( $type, $valid_types, true ) ) {
            return [ 'success' => false, 'error' => 'invalid_type' ];
        }

        $class_id      = absint( $target['class_id'] ?? 0 );
        $subject_id    = absint( $target['subject_id'] ?? 0 );
        $department_id = absint( $target['department_id'] ?? 0 );

        $table = Schema::table( 'staff_assignments' );

        // Exactly one class teacher per class per session. Two people both believing
        // they own a class is how remarks and promotion decisions get overwritten.
        if ( $type === 'class_teacher' ) {
            if ( $class_id <= 0 ) {
                return [ 'success' => false, 'error' => 'class_required' ];
            }

            $incumbent = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT staff_id FROM {$table}
                     WHERE school_id = %d AND assignment_type = 'class_teacher'
                     AND class_id = %d AND session_id = %d AND status = 'active'",
                    $school_id,
                    $class_id,
                    $session_id
                )
            );

            if ( $incumbent && absint( $incumbent ) !== $staff_id ) {
                // Replace rather than refuse: reassigning a class mid-session is a
                // normal event, but the outgoing teacher must be stood down explicitly.
                $wpdb->update(
                    $table,
                    [ 'status' => 'replaced' ],
                    [
                        'school_id'       => $school_id,
                        'assignment_type' => 'class_teacher',
                        'class_id'        => $class_id,
                        'session_id'      => $session_id,
                        'status'          => 'active',
                    ],
                    [ '%s' ],
                    [ '%d', '%s', '%d', '%d', '%s' ]
                );
            }
        }

        if ( $type === 'subject_teacher' && ( $class_id <= 0 || $subject_id <= 0 ) ) {
            return [ 'success' => false, 'error' => 'class_and_subject_required' ];
        }

        if ( $type === 'hod' && $department_id <= 0 ) {
            return [ 'success' => false, 'error' => 'department_required' ];
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (school_id, staff_id, assignment_type, class_id, subject_id, department_id, session_id, status)
                 VALUES (%d, %d, %s, %d, %d, %d, %d, 'active')
                 ON DUPLICATE KEY UPDATE status = 'active'",
                $school_id,
                $staff_id,
                $type,
                $class_id ?: null,
                $subject_id ?: null,
                $department_id ?: null,
                $session_id
            )
        );

        EventDispatcher::action( 'educbt_staff_assigned', [
            'school_id' => $school_id,
            'staff_id'  => $staff_id,
            'type'      => $type,
            'target'    => $target,
        ] );

        return [ 'success' => true, 'assignment_id' => absint( $wpdb->insert_id ) ];
    }

    public function unassign( int $school_id, int $assignment_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            Schema::table( 'staff_assignments' ),
            [ 'status' => 'ended' ],
            [ 'id' => $assignment_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );
    }

    public function assignments_for( int $school_id, int $staff_id, int $session_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'staff_assignments' ) .
                " WHERE school_id = %d AND staff_id = %d AND session_id = %d AND status = 'active'",
                $school_id,
                $staff_id,
                $session_id
            ),
            ARRAY_A
        );
    }

    public function class_teacher_of( int $school_id, int $class_id, int $session_id ): ?array {
        global $wpdb;

        $staff       = Schema::table( 'staff' );
        $assignments = Schema::table( 'staff_assignments' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.* FROM {$assignments} a
                 INNER JOIN {$staff} s ON s.id = a.staff_id
                 WHERE a.school_id = %d AND a.class_id = %d AND a.session_id = %d
                 AND a.assignment_type = 'class_teacher' AND a.status = 'active' LIMIT 1",
                $school_id,
                $class_id,
                $session_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Teachers eligible to invigilate a paper, ranked fairest-first.
     *
     * Eligibility, in the order it actually matters:
     *   1. Must NOT teach that subject to that class — removes the conflict of
     *      interest of a teacher supervising their own students on their own paper.
     *   2. Must be free at that timeslot — no other paper assigned then.
     *   3. Ranked by fewest invigilations so far in the series, so the duty rotates
     *      instead of landing on whoever sorts first alphabetically.
     *
     * @return array<int,array<string,mixed>>
     */
    public function eligible_invigilators( int $school_id, int $paper_id ): array {
        global $wpdb;

        $papers = Schema::table( 'exam_papers' );

        $paper = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$papers} WHERE id = %d AND school_id = %d", $paper_id, $school_id ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return [];
        }

        $staff        = Schema::table( 'staff' );
        $assignments  = Schema::table( 'staff_assignments' );
        $invigilators = Schema::table( 'paper_invigilators' );

        $subject_id = absint( $paper['subject_id'] );
        $class_id   = absint( $paper['class_id'] );
        $series_id  = absint( $paper['series_id'] );
        $scheduled  = (string) $paper['scheduled_at'];
        $duration   = absint( $paper['duration_seconds'] );

        $sql = "SELECT s.id, s.staff_number, s.first_name, s.last_name,
                       COALESCE(load_count.total, 0) AS current_load
                FROM {$staff} s
                LEFT JOIN (
                    SELECT pi.staff_id, COUNT(*) AS total
                    FROM {$invigilators} pi
                    INNER JOIN {$papers} p ON p.id = pi.paper_id
                    WHERE p.series_id = %d
                    GROUP BY pi.staff_id
                ) load_count ON load_count.staff_id = s.id
                WHERE s.school_id = %d
                  AND s.status = 'active'
                  AND s.role_slug <> %s
                  AND s.id NOT IN (
                      SELECT a.staff_id FROM {$assignments} a
                      WHERE a.school_id = %d AND a.assignment_type = 'subject_teacher'
                        AND a.subject_id = %d AND a.class_id = %d AND a.status = 'active'
                  )
                  AND s.id NOT IN (
                      SELECT pi2.staff_id FROM {$invigilators} pi2
                      INNER JOIN {$papers} p2 ON p2.id = pi2.paper_id
                      WHERE p2.school_id = %d
                        AND p2.scheduled_at < DATE_ADD(%s, INTERVAL %d SECOND)
                        AND DATE_ADD(p2.scheduled_at, INTERVAL p2.duration_seconds SECOND) > %s
                  )
                ORDER BY current_load ASC, s.last_name ASC";

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                $sql,
                $series_id,
                $school_id,
                Capabilities::ROLE_PRINCIPAL,
                $school_id,
                $subject_id,
                $class_id,
                $school_id,
                $scheduled,
                $duration,
                $scheduled
            ),
            ARRAY_A
        );
    }

    /**
     * Assign the fairest eligible invigilator automatically. Overridable by hand,
     * which is why `assigned_mode` records how the choice was made.
     *
     * @return array{success:bool,staff_id?:int,error?:string}
     */
    public function auto_assign_invigilator( int $school_id, int $paper_id ): array {
        $eligible = $this->eligible_invigilators( $school_id, $paper_id );

        if ( empty( $eligible ) ) {
            return [ 'success' => false, 'error' => 'no_eligible_invigilator' ];
        }

        $chosen = $eligible[0];

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . Schema::table( 'paper_invigilators' ) .
                " (school_id, paper_id, staff_id, assigned_mode) VALUES (%d, %d, %d, 'auto')",
                $school_id,
                $paper_id,
                absint( $chosen['id'] )
            )
        );

        return [ 'success' => true, 'staff_id' => absint( $chosen['id'] ) ];
    }
}
