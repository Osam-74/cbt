<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 2 — the permission gate.
 *
 * Every permission decision answers TWO questions:
 *
 *   1. Does this person's role hold the capability?          (Capabilities)
 *   2. Does their assignment cover this specific object?     (Scope)
 *
 * v1 only ever asked the first, which is why a teacher could manage any exam in the
 * school. `current_user_can()` alone is never sufficient in this system, and the
 * only correct entry point is Gate::allows().
 *
 * Usage:
 *   Gate::allows( Capabilities::ENTER_SCORES, [ 'subject_id' => 12, 'class_id' => 4 ] )
 *   Gate::allows( Capabilities::VIEW_RESULTS, [ 'student_id' => 88 ] )
 *   Gate::require( Capabilities::APPROVE_RESULTS );   // returns WP_Error on failure
 */
class Gate {

    private static ?Scope $scope = null;

    public static function scope(): Scope {
        if ( self::$scope === null ) {
            self::$scope = new Scope();
        }

        return self::$scope;
    }

    /** Test seam. */
    public static function set_scope( ?Scope $scope ): void {
        self::$scope = $scope;
    }

    /**
     * @param array<string,int> $context class_id, subject_id, student_id, paper_id, department_id
     */
    public static function allows( string $capability, array $context = [] ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        // Layer 1 — capability.
        if ( ! current_user_can( $capability ) ) {
            return false;
        }

        $scope = self::scope();
        $actor = $scope->actor();

        // An actor with no school context has no business acting on school data,
        // regardless of what capabilities their role carries.
        if ( $actor['type'] === Scope::ACTOR_NONE ) {
            return false;
        }

        if ( $actor['type'] === Scope::ACTOR_PLATFORM ) {
            return true;
        }

        // Layer 2 — scope.
        return self::in_scope( $capability, $context, $scope, $actor );
    }

    /**
     * @param array<string,int>  $context
     * @param array<string,mixed> $actor
     */
    private static function in_scope( string $capability, array $context, Scope $scope, array $actor ): bool {
        $class_id      = absint( $context['class_id'] ?? 0 );
        $subject_id    = absint( $context['subject_id'] ?? 0 );
        $student_id    = absint( $context['student_id'] ?? 0 );
        $paper_id      = absint( $context['paper_id'] ?? 0 );
        $department_id = absint( $context['department_id'] ?? 0 );

        // --- Students -------------------------------------------------
        if ( $actor['type'] === Scope::ACTOR_STUDENT ) {
            // A student may only ever act on their own record.
            return $student_id === 0 || $student_id === $actor['id'];
        }

        // --- Guardians ------------------------------------------------
        if ( $actor['type'] === Scope::ACTOR_GUARDIAN ) {
            if ( $student_id === 0 ) {
                return true;
            }

            return in_array( $student_id, $scope->ward_ids(), true );
        }

        if ( $actor['type'] !== Scope::ACTOR_STAFF ) {
            return false;
        }

        // --- School-wide staff ----------------------------------------
        if ( $scope->is_school_wide() ) {
            return true;
        }

        // --- Teachers: narrowed by assignment -------------------------

        // Capabilities a teacher only holds while they are a class teacher, and then
        // only for the class they actually hold.
        if ( in_array( $capability, Capabilities::class_teacher_only(), true ) ) {
            if ( $class_id > 0 ) {
                return $scope->is_class_teacher_of( $class_id );
            }

            if ( $student_id > 0 ) {
                return $scope->can_see_student( $student_id );
            }

            // No object named: allowed only if they hold any class-teacher post,
            // and the listing query is expected to filter by reachable_class_ids().
            return ! empty( $scope->assignments()['class_teacher'] );
        }

        if ( $capability === Capabilities::INVIGILATE || $capability === Capabilities::RELEASE_ACCESS_CODE ) {
            return $paper_id === 0 || $scope->invigilates( $paper_id );
        }

        if ( $department_id > 0 && $scope->is_hod_of( $department_id ) ) {
            return true;
        }

        // Score entry and question authorship are bounded by the subject/class pair.
        if ( $subject_id > 0 && $class_id > 0 ) {
            return $scope->teaches_subject( $subject_id, $class_id );
        }

        if ( $class_id > 0 ) {
            return in_array( $class_id, $scope->reachable_class_ids(), true );
        }

        if ( $student_id > 0 ) {
            return $scope->can_see_student( $student_id );
        }

        // Unscoped read of a listing endpoint. Permitted, but the query layer must
        // narrow the result to reachable_class_ids() — see Gate::filter_clause().
        return true;
    }

    /**
     * @param array<string,int> $context
     * @return true|\WP_Error
     */
    public static function require( string $capability, array $context = [] ) {
        if ( self::allows( $capability, $context ) ) {
            return true;
        }

        EventDispatcher::action( 'educbt_permission_denied', [
            'capability' => $capability,
            'context'    => $context,
            'user_id'    => function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0,
        ] );

        if ( class_exists( '\WP_Error' ) ) {
            return new \WP_Error(
                'educbt_forbidden',
                __( 'You do not have permission to perform this action.', 'educbt-pro' ),
                [ 'status' => is_user_logged_in() ? 403 : 401 ]
            );
        }

        return false;
    }

    /**
     * SQL fragment narrowing a listing query to the classes this actor may reach.
     * Returns '1=1' for school-wide actors and '1=0' for actors with no reach, so a
     * caller that forgets to branch fails closed rather than leaking the school.
     */
    public static function class_filter_clause( string $column = 'class_id' ): string {
        $scope = self::scope();

        if ( $scope->is_school_wide() ) {
            return '1=1';
        }

        $classes = $scope->reachable_class_ids();

        if ( empty( $classes ) ) {
            return '1=0';
        }

        $ids = implode( ',', array_map( 'absint', $classes ) );

        return esc_sql( $column ) . " IN ({$ids})";
    }
}
