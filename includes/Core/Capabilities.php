<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 2 — capability taxonomy.
 *
 * v1 defined eight roles but only NINE capabilities. `educbt_manage_exams` was
 * granted to teacher, principal and exam officer alike, so there was no way to
 * express "a teacher may write questions but may not approve results". There was
 * also no capability at all for registering a student, assigning a class teacher,
 * or publishing results to parents.
 *
 * Two further defects are fixed here:
 *
 *  - v1 added plugin capabilities to WordPress's built-in `editor`, `author` and
 *    `subscriber` roles, so any subscriber on the host site gained parent-portal
 *    access. Built-in roles are no longer touched.
 *  - v1 granted `educbt_school_administrator` the core caps `list_users` and
 *    `edit_users`, which is a door straight back into wp-admin user management —
 *    the exact opposite of running everything from the front end.
 *
 * IMPORTANT: a capability answers "what kind of action may this person perform".
 * It does NOT answer "on which class or subject". That is scope, and it lives in
 * Scope/Gate. Holding `educbt_enter_scores` does not let a teacher enter scores
 * for a class they don't teach.
 */
class Capabilities {

    // ---- School setup -------------------------------------------------
    public const MANAGE_SCHOOL          = 'educbt_manage_school';
    public const MANAGE_ACADEMIC_YEAR   = 'educbt_manage_academic_year';
    public const MANAGE_CLASSES         = 'educbt_manage_classes';
    public const MANAGE_SUBJECTS        = 'educbt_manage_subjects';
    public const MANAGE_GRADING         = 'educbt_manage_grading';

    // ---- People -------------------------------------------------------
    public const VIEW_STAFF             = 'educbt_view_staff';
    public const MANAGE_STAFF           = 'educbt_manage_staff';
    public const ASSIGN_STAFF           = 'educbt_assign_staff';
    public const VIEW_STUDENTS          = 'educbt_view_students';
    public const REGISTER_STUDENTS      = 'educbt_register_students';
    public const MANAGE_STUDENTS        = 'educbt_manage_students';
    public const PLACE_STUDENTS         = 'educbt_place_students';
    public const RESET_STUDENT_PASSWORD = 'educbt_reset_student_password';
    public const MANAGE_GUARDIANS       = 'educbt_manage_guardians';

    // ---- Question bank ------------------------------------------------
    public const VIEW_QUESTIONS         = 'educbt_view_questions';
    public const WRITE_QUESTIONS        = 'educbt_write_questions';
    public const APPROVE_QUESTIONS      = 'educbt_approve_questions';

    // ---- Exams --------------------------------------------------------
    public const VIEW_EXAMS             = 'educbt_view_exams';
    public const MANAGE_EXAM_SERIES     = 'educbt_manage_exam_series';
    public const MANAGE_PAPERS          = 'educbt_manage_papers';
    public const ASSIGN_INVIGILATORS    = 'educbt_assign_invigilators';
    public const INVIGILATE             = 'educbt_invigilate';
    public const RELEASE_ACCESS_CODE    = 'educbt_release_access_code';
    public const GRANT_EXTENSION        = 'educbt_grant_extension';

    // ---- Results ------------------------------------------------------
    public const ENTER_SCORES           = 'educbt_enter_scores';
    public const SUBMIT_SCORES          = 'educbt_submit_scores';
    public const COMPILE_RESULTS        = 'educbt_compile_results';
    public const REMARK_RESULTS         = 'educbt_remark_results';
    public const APPROVE_RESULTS        = 'educbt_approve_results';
    public const PUBLISH_RESULTS        = 'educbt_publish_results';
    public const UNLOCK_RESULTS         = 'educbt_unlock_results';
    public const VIEW_RESULTS           = 'educbt_view_results';
    public const VIEW_BROADSHEET        = 'educbt_view_broadsheet';

    // ---- Promotion and transcripts ------------------------------------
    public const RUN_PROMOTION          = 'educbt_run_promotion';
    public const COMMIT_PROMOTION       = 'educbt_commit_promotion';
    public const ISSUE_TRANSCRIPT       = 'educbt_issue_transcript';

    // ---- Communication ------------------------------------------------
    public const SEND_ANNOUNCEMENT      = 'educbt_send_announcement';
    public const SEND_SCHOOL_WIDE       = 'educbt_send_school_wide';
    public const SEND_MESSAGE           = 'educbt_send_message';

    // ---- Oversight ----------------------------------------------------
    public const VIEW_ACTIVITY_LOG      = 'educbt_view_activity_log';
    public const VIEW_ANALYTICS         = 'educbt_view_analytics';

    // ---- Portals ------------------------------------------------------
    public const STUDENT_PORTAL         = 'educbt_student_portal';
    public const SIT_EXAM               = 'educbt_sit_exam';
    public const GUARDIAN_PORTAL        = 'educbt_guardian_portal';
    public const VIEW_OWN_RESULTS       = 'educbt_view_own_results';
    public const VIEW_CHILD_RESULTS     = 'educbt_view_child_results';

    // ---- Platform -----------------------------------------------------
    public const MANAGE_PLATFORM        = 'educbt_manage_platform';
    public const MANAGE_SCHOOLS         = 'educbt_manage_schools';
    public const IMPERSONATE_SCHOOL     = 'educbt_impersonate_school';

    // -------------------------------------------------------------------

    public const ROLE_PLATFORM_ADMIN  = 'educbt_platform_admin';
    public const ROLE_PRINCIPAL       = 'educbt_principal';
    public const ROLE_VICE_PRINCIPAL  = 'educbt_vice_principal';
    public const ROLE_EXAM_OFFICER    = 'educbt_exam_officer';
    public const ROLE_TEACHER         = 'educbt_teacher';
    public const ROLE_STUDENT         = 'educbt_student';
    public const ROLE_GUARDIAN        = 'educbt_guardian';

    /**
     * @return array<string,string> role slug => display label
     */
    public static function roles(): array {
        return [
            self::ROLE_PLATFORM_ADMIN => 'EduCBT Platform Admin',
            self::ROLE_PRINCIPAL      => 'Principal',
            self::ROLE_VICE_PRINCIPAL => 'Vice Principal',
            self::ROLE_EXAM_OFFICER   => 'Examination Officer',
            self::ROLE_TEACHER        => 'Teacher',
            self::ROLE_STUDENT        => 'Student',
            self::ROLE_GUARDIAN       => 'Parent / Guardian',
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function all(): array {
        $reflection = new \ReflectionClass( self::class );
        $caps       = [];

        foreach ( $reflection->getConstants() as $name => $value ) {
            if ( strpos( $name, 'ROLE_' ) === 0 ) {
                continue;
            }
            $caps[] = (string) $value;
        }

        return array_values( array_unique( $caps ) );
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function role_map(): array {
        // A class teacher is an ASSIGNMENT, not a role, so these capabilities are
        // granted to the teacher role and then narrowed by Gate to the single class
        // the teacher actually holds. A teacher with no class-teacher assignment
        // fails the scope check and can exercise none of them.
        $class_teacher_extras = [
            self::REGISTER_STUDENTS,
            self::PLACE_STUDENTS,
            self::RESET_STUDENT_PASSWORD,
            self::MANAGE_GUARDIANS,
            self::REMARK_RESULTS,
            self::VIEW_BROADSHEET,
            self::SEND_ANNOUNCEMENT,
        ];

        $teacher_base = [
            'read',
            self::VIEW_STUDENTS,
            self::VIEW_STAFF,
            self::VIEW_QUESTIONS,
            self::WRITE_QUESTIONS,
            self::VIEW_EXAMS,
            self::RELEASE_ACCESS_CODE,
            self::ENTER_SCORES,
            self::SUBMIT_SCORES,
            self::VIEW_RESULTS,
            self::SEND_MESSAGE,
        ];

        $teacher = array_merge( $teacher_base, $class_teacher_extras );

        $exam_officer = array_merge(
            $teacher,
            [
                self::INVIGILATE,
                self::APPROVE_QUESTIONS,
                self::MANAGE_EXAM_SERIES,
                self::MANAGE_PAPERS,
                self::ASSIGN_INVIGILATORS,
                self::GRANT_EXTENSION,
                self::COMPILE_RESULTS,
                self::VIEW_ANALYTICS,
            ]
        );

        $vice_principal = array_merge(
            $exam_officer,
            [
                self::MANAGE_CLASSES,
                self::MANAGE_SUBJECTS,
                self::MANAGE_STAFF,
                self::ASSIGN_STAFF,
                self::MANAGE_STUDENTS,
                self::RUN_PROMOTION,
                self::SEND_SCHOOL_WIDE,
                self::VIEW_ACTIVITY_LOG,
            ]
        );

        // The principal's oversight of "what teachers and students are doing" falls
        // out of school-wide scope plus the activity log. It needs no special feature.
        $principal = array_merge(
            $vice_principal,
            [
                self::MANAGE_SCHOOL,
                self::MANAGE_ACADEMIC_YEAR,
                self::MANAGE_GRADING,
                self::APPROVE_RESULTS,
                self::PUBLISH_RESULTS,
                self::UNLOCK_RESULTS,
                self::COMMIT_PROMOTION,
                self::ISSUE_TRANSCRIPT,
            ]
        );

        return [
            self::ROLE_PLATFORM_ADMIN => array_merge(
                self::all(),
                [ 'read', 'manage_options' ]
            ),
            self::ROLE_PRINCIPAL      => $principal,
            self::ROLE_VICE_PRINCIPAL => $vice_principal,
            self::ROLE_EXAM_OFFICER   => $exam_officer,
            self::ROLE_TEACHER        => $teacher,
            self::ROLE_STUDENT        => [
                'read',
                self::STUDENT_PORTAL,
                self::SIT_EXAM,
                self::VIEW_OWN_RESULTS,
            ],
            self::ROLE_GUARDIAN       => [
                'read',
                self::GUARDIAN_PORTAL,
                self::VIEW_CHILD_RESULTS,
            ],
        ];
    }

    /**
     * Capabilities a teacher only exercises while holding a class-teacher assignment.
     * Gate checks the assignment before allowing them.
     *
     * @return array<int,string>
     */
    public static function class_teacher_only(): array {
        return [
            self::REGISTER_STUDENTS,
            self::PLACE_STUDENTS,
            self::RESET_STUDENT_PASSWORD,
            self::MANAGE_GUARDIANS,
            self::REMARK_RESULTS,
            self::VIEW_BROADSHEET,
            self::SEND_ANNOUNCEMENT,
        ];
    }

    /**
     * Roles whose scope is the whole school. Everyone else is narrowed by assignment.
     *
     * @return array<int,string>
     */
    public static function school_wide_roles(): array {
        return [
            self::ROLE_PLATFORM_ADMIN,
            self::ROLE_PRINCIPAL,
            self::ROLE_VICE_PRINCIPAL,
            self::ROLE_EXAM_OFFICER,
        ];
    }

    /**
     * WordPress capabilities school staff need beyond our own.
     *
     * Without upload_files, wp_enqueue_media() does nothing and every image picker in
     * the portal silently degrades to a "paste an address" box — which is exactly what
     * a school saw.
     *
     * @return array<int,string>
     */
    public static function wp_capabilities_for( string $role ): array {
        $uploaders = [
            self::ROLE_PRINCIPAL,
            self::ROLE_VICE_PRINCIPAL,
            self::ROLE_EXAM_OFFICER,
            self::ROLE_TEACHER,
        ];

        return in_array( $role, $uploaders, true ) ? [ 'upload_files' ] : [];
    }

    public static function install(): void {
        foreach ( self::roles() as $slug => $label ) {
            if ( ! get_role( $slug ) ) {
                add_role( $slug, $label, [ 'read' => true ] );
            }
        }

        foreach ( self::role_map() as $slug => $caps ) {
            $role = get_role( $slug );
            if ( ! $role ) {
                continue;
            }

            // Re-sync rather than only adding, so a capability removed from the map
            // is actually revoked on upgrade instead of lingering forever.
            foreach ( self::all() as $cap ) {
                if ( in_array( $cap, $caps, true ) ) {
                    $role->add_cap( $cap );
                } else {
                    $role->remove_cap( $cap );
                }
            }

            // WordPress's own capabilities, granted on top of ours.
            foreach ( self::wp_capabilities_for( $slug ) as $wp_cap ) {
                $role->add_cap( $wp_cap );
            }
        }

        // WordPress administrators keep full access so a site owner is never locked
        // out, but the built-in editor/author/subscriber roles are deliberately left
        // untouched — v1 polluted them and handed parent-portal access to every
        // subscriber on the site.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::all() as $cap ) {
                $admin->add_cap( $cap );
            }
        }

        self::purge_builtin_roles();
    }

    /**
     * Undo v1's pollution of the built-in WordPress roles.
     */
    public static function purge_builtin_roles(): void {
        $legacy = [
            'educbt_view_students', 'educbt_manage_students', 'educbt_view_teachers',
            'educbt_manage_teachers', 'educbt_view_results', 'educbt_manage_results',
            'educbt_manage_exams', 'educbt_parent_portal', 'educbt_student_portal',
        ];

        foreach ( [ 'editor', 'author', 'contributor', 'subscriber' ] as $slug ) {
            $role = get_role( $slug );
            if ( ! $role ) {
                continue;
            }

            foreach ( array_merge( self::all(), $legacy ) as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    public static function uninstall(): void {
        foreach ( array_keys( self::roles() ) as $slug ) {
            remove_role( $slug );
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::all() as $cap ) {
                $admin->remove_cap( $cap );
            }
        }
    }
}
