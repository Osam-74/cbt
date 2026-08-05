<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 2 — scope.
 *
 * v1 had no concept of scope at all. A teacher holding `educbt_manage_exams` could
 * manage every exam in the school, including subjects they don't teach and classes
 * they have never met. Capability answered "what may this person do" and nothing
 * ever answered "to whom".
 *
 * Scope resolves the current user into an ACTOR (staff / student / guardian) and
 * loads the assignments that bound what they may touch:
 *
 *   class_teacher_of(class, session)      one teacher per class per session
 *   subject_teacher_of(subject, class)    many
 *   hod_of(department)                    optional
 *   invigilator_of(paper)                 per paper
 *   guardian_of(student)                  many-to-many, so siblings work
 *
 * Everything is loaded once per request and cached, because Gate is called on every
 * REST route and a per-check query would be pathological during an exam.
 */
class Scope {

    public const ACTOR_PLATFORM = 'platform';
    public const ACTOR_STAFF    = 'staff';
    public const ACTOR_STUDENT  = 'student';
    public const ACTOR_GUARDIAN = 'guardian';
    public const ACTOR_NONE     = 'none';

    private TenantContext $tenant;

    /** @var array<string,mixed>|null */
    private ?array $actor = null;

    /** @var array<string,array<int,int>>|null */
    private ?array $assignments = null;

    public function __construct( ?TenantContext $tenant = null ) {
        $this->tenant = $tenant ?? new TenantContext();
    }

    public function school_id(): int {
        return absint( $this->tenant->get_school_id() ?? 0 );
    }

    /**
     * Resolve the current WordPress user into a domain actor.
     *
     * @return array{type:string,id:int,school_id:int,role:string}
     */
    public function actor(): array {
        if ( $this->actor !== null ) {
            return $this->actor;
        }

        $user_id   = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        $school_id = $this->school_id();

        $none = [ 'type' => self::ACTOR_NONE, 'id' => 0, 'school_id' => $school_id, 'role' => '' ];

        if ( $user_id <= 0 ) {
            return $this->actor = $none;
        }

        if ( current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
            return $this->actor = [
                'type'      => self::ACTOR_PLATFORM,
                'id'        => $user_id,
                'school_id' => $school_id,
                'role'      => Capabilities::ROLE_PLATFORM_ADMIN,
            ];
        }

        global $wpdb;

        $staff_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'staff' ) . ' WHERE wp_user_id = %d AND status = %s LIMIT 1',
                $user_id,
                'active'
            )
        );

        if ( $staff_id ) {
            return $this->actor = [
                'type'      => self::ACTOR_STAFF,
                'id'        => absint( $staff_id ),
                'school_id' => $school_id,
                'role'      => $this->primary_role( $user_id ),
            ];
        }

        $students = $wpdb->prefix . 'educbt_students';
        $student_id = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$students} WHERE wp_user_id = %d LIMIT 1", $user_id )
        );

        if ( $student_id ) {
            return $this->actor = [
                'type'      => self::ACTOR_STUDENT,
                'id'        => absint( $student_id ),
                'school_id' => $school_id,
                'role'      => Capabilities::ROLE_STUDENT,
            ];
        }

        $guardian_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'guardians' ) . ' WHERE wp_user_id = %d AND status = %s LIMIT 1',
                $user_id,
                'active'
            )
        );

        if ( $guardian_id ) {
            return $this->actor = [
                'type'      => self::ACTOR_GUARDIAN,
                'id'        => absint( $guardian_id ),
                'school_id' => $school_id,
                'role'      => Capabilities::ROLE_GUARDIAN,
            ];
        }

        // Last resort: the user carries a school in their own metadata but has no
        // row in any of the tables above. That should not happen, but when it did —
        // a principal whose staff row failed to insert — it denied them every
        // capability and produced a redirect loop on their own dashboard.
        //
        // Trusting user meta here is safe: it is written server-side at account
        // creation and is not reachable from a request.
        $meta_school = absint( get_user_meta( $user_id, '_educbt_school_id', true ) );
        $role        = $this->primary_role( $user_id );

        if ( $meta_school > 0 && $role !== '' ) {
            return $this->actor = [
                'type'      => self::ACTOR_STAFF,
                'id'        => 0,
                'school_id' => $meta_school,
                'role'      => $role,
            ];
        }

        return $this->actor = $none;
    }

    private function primary_role( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->roles ) ) {
            return '';
        }

        // Most privileged wins, so a principal who is also recorded as a teacher is
        // treated as a principal.
        foreach ( [
            Capabilities::ROLE_PRINCIPAL,
            Capabilities::ROLE_VICE_PRINCIPAL,
            Capabilities::ROLE_EXAM_OFFICER,
            Capabilities::ROLE_TEACHER,
        ] as $slug ) {
            if ( in_array( $slug, (array) $user->roles, true ) ) {
                return $slug;
            }
        }

        return (string) ( $user->roles[0] ?? '' );
    }

    /**
     * True when this actor's remit is the entire school and assignment checks
     * are therefore unnecessary.
     */
    public function is_school_wide(): bool {
        $actor = $this->actor();

        if ( $actor['type'] === self::ACTOR_PLATFORM ) {
            return true;
        }

        return $actor['type'] === self::ACTOR_STAFF
            && in_array( $actor['role'], Capabilities::school_wide_roles(), true );
    }

    /**
     * Assignment sets for the current staff actor, loaded once.
     *
     * @return array{class_teacher:array<int,int>,subject_teacher:array<int,string>,hod:array<int,int>,invigilator:array<int,int>}
     */
    public function assignments(): array {
        if ( $this->assignments !== null ) {
            return $this->assignments;
        }

        $empty = [ 'class_teacher' => [], 'subject_teacher' => [], 'hod' => [], 'invigilator' => [] ];
        $actor = $this->actor();

        if ( $actor['type'] !== self::ACTOR_STAFF ) {
            return $this->assignments = $empty;
        }

        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT assignment_type, class_id, subject_id, department_id FROM ' . Schema::table( 'staff_assignments' ) .
                ' WHERE school_id = %d AND staff_id = %d AND status = %s',
                $actor['school_id'],
                $actor['id'],
                'active'
            ),
            ARRAY_A
        );

        $out = $empty;

        foreach ( $rows as $row ) {
            $type    = (string) ( $row['assignment_type'] ?? '' );
            $class   = absint( $row['class_id'] ?? 0 );
            $subject = absint( $row['subject_id'] ?? 0 );

            if ( $type === 'class_teacher' && $class > 0 ) {
                $out['class_teacher'][] = $class;
            } elseif ( $type === 'subject_teacher' && $subject > 0 ) {
                // Composite key: a teacher may take Maths in SS1A but not SS1B.
                $out['subject_teacher'][] = $subject . ':' . $class;
            } elseif ( $type === 'hod' ) {
                $out['hod'][] = absint( $row['department_id'] ?? 0 );
            }
        }

        $out['invigilator'] = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT paper_id FROM ' . Schema::table( 'paper_invigilators' ) . ' WHERE school_id = %d AND staff_id = %d',
                    $actor['school_id'],
                    $actor['id']
                )
            )
        );

        return $this->assignments = $out;
    }

    public function is_class_teacher_of( int $class_id ): bool {
        if ( $class_id <= 0 ) {
            return false;
        }

        return in_array( $class_id, $this->assignments()['class_teacher'], true );
    }

    public function teaches_subject( int $subject_id, int $class_id ): bool {
        if ( $subject_id <= 0 || $class_id <= 0 ) {
            return false;
        }

        return in_array( $subject_id . ':' . $class_id, $this->assignments()['subject_teacher'], true );
    }

    public function is_hod_of( int $department_id ): bool {
        return $department_id > 0 && in_array( $department_id, $this->assignments()['hod'], true );
    }

    public function invigilates( int $paper_id ): bool {
        return $paper_id > 0 && in_array( $paper_id, $this->assignments()['invigilator'], true );
    }

    /**
     * Class IDs this actor may act on. Empty array for school-wide actors, which
     * callers must read as "no restriction" rather than "nothing".
     *
     * @return array<int,int>
     */
    public function reachable_class_ids(): array {
        if ( $this->is_school_wide() ) {
            return [];
        }

        $assignments = $this->assignments();
        $classes     = $assignments['class_teacher'];

        foreach ( $assignments['subject_teacher'] as $pair ) {
            $parts = explode( ':', (string) $pair );
            $class = absint( $parts[1] ?? 0 );
            if ( $class > 0 ) {
                $classes[] = $class;
            }
        }

        return array_values( array_unique( $classes ) );
    }

    /**
     * Students a guardian may see. Siblings included; the many-to-many link is what
     * makes that possible, where v1's text field on the student row did not.
     *
     * @return array<int,int>
     */
    public function ward_ids(): array {
        $actor = $this->actor();

        if ( $actor['type'] !== self::ACTOR_GUARDIAN ) {
            return [];
        }

        global $wpdb;

        return array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT student_id FROM ' . Schema::table( 'guardian_student' ) .
                    ' WHERE school_id = %d AND guardian_id = %d AND can_view_results = 1',
                    $actor['school_id'],
                    $actor['id']
                )
            )
        );
    }

    /**
     * May this actor see this particular student?
     */
    public function can_see_student( int $student_id ): bool {
        if ( $student_id <= 0 ) {
            return false;
        }

        $actor = $this->actor();

        if ( $this->is_school_wide() ) {
            return true;
        }

        if ( $actor['type'] === self::ACTOR_STUDENT ) {
            return $actor['id'] === $student_id;
        }

        if ( $actor['type'] === self::ACTOR_GUARDIAN ) {
            return in_array( $student_id, $this->ward_ids(), true );
        }

        if ( $actor['type'] !== self::ACTOR_STAFF ) {
            return false;
        }

        $classes = $this->reachable_class_ids();
        if ( empty( $classes ) ) {
            return false;
        }

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $classes ), '%d' ) );

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'enrollments' ) .
                " WHERE student_id = %d AND status = 'active' AND class_id IN ({$placeholders}) LIMIT 1",
                array_merge( [ $student_id ], $classes )
            )
        );

        return (bool) $found;
    }

    /** Test seam. */
    public function prime( array $actor, array $assignments ): void {
        $this->actor       = $actor;
        $this->assignments = array_merge(
            [ 'class_teacher' => [], 'subject_teacher' => [], 'hod' => [], 'invigilator' => [] ],
            $assignments
        );
    }
}
