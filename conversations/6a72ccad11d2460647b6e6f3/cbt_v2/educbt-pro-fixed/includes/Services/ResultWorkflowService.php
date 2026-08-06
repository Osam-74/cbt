<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 7 — the result approval chain.
 *
 * You described results being forwarded from teachers to the class teacher for
 * review and printing. This formalises that so nobody can quietly change a mark
 * after the fact:
 *
 *   draft      teacher is still entering marks
 *   submitted  subject teacher has signed off — LOCKED to them from here
 *   compiled   exam officer has computed totals and positions
 *   approved   principal has signed off
 *   published  visible to students and guardians
 *
 * Two rules make the chain worth having:
 *
 *  1. TRANSITIONS ARE ORDERED. You cannot approve what was never compiled, or
 *     publish what was never approved. A workflow you can skip is decoration.
 *
 *  2. GOING BACKWARDS IS PRIVILEGED AND LOUD. Once approved, an edit needs a
 *     principal-level unlock with a reason, and it is audit-logged. This is the
 *     control that answers "who changed this mark, and when".
 *
 * Nothing is visible to a student or guardian before `published`. That is enforced
 * in the query, not in the template — a template check is one forgotten `if` away
 * from leaking unapproved results to parents.
 */
class ResultWorkflowService {

    public const DRAFT     = 'draft';
    public const SUBMITTED = 'submitted';
    public const COMPILED  = 'compiled';
    public const APPROVED  = 'approved';
    public const PUBLISHED = 'published';

    /**
     * Which states may follow which. Anything not listed here is refused.
     *
     * @return array<string,array<int,string>>
     */
    public static function transitions(): array {
        return [
            self::DRAFT     => [ self::SUBMITTED ],
            self::SUBMITTED => [ self::COMPILED, self::DRAFT ],
            self::COMPILED  => [ self::APPROVED, self::SUBMITTED ],
            self::APPROVED  => [ self::PUBLISHED, self::COMPILED ],
            self::PUBLISHED => [ self::APPROVED ],
        ];
    }

    /**
     * The capability required to make a given move. Backward moves are deliberately
     * harder than forward ones.
     */
    public static function capability_for( string $from, string $to ): string {
        $map = [
            self::DRAFT . '>' . self::SUBMITTED     => Capabilities::SUBMIT_SCORES,
            self::SUBMITTED . '>' . self::COMPILED  => Capabilities::COMPILE_RESULTS,
            self::COMPILED . '>' . self::APPROVED   => Capabilities::APPROVE_RESULTS,
            self::APPROVED . '>' . self::PUBLISHED  => Capabilities::PUBLISH_RESULTS,
            // Every reversal requires the unlock capability, which only the principal
            // holds. Reopening a submitted mark is not a routine act.
            self::SUBMITTED . '>' . self::DRAFT     => Capabilities::UNLOCK_RESULTS,
            self::COMPILED . '>' . self::SUBMITTED  => Capabilities::UNLOCK_RESULTS,
            self::APPROVED . '>' . self::COMPILED   => Capabilities::UNLOCK_RESULTS,
            self::PUBLISHED . '>' . self::APPROVED  => Capabilities::UNLOCK_RESULTS,
        ];

        return $map[ $from . '>' . $to ] ?? Capabilities::UNLOCK_RESULTS;
    }

    public static function is_reversal( string $from, string $to ): bool {
        $order = [ self::DRAFT => 0, self::SUBMITTED => 1, self::COMPILED => 2, self::APPROVED => 3, self::PUBLISHED => 4 ];

        return ( $order[ $to ] ?? 0 ) < ( $order[ $from ] ?? 0 );
    }

    /**
     * @return array{allowed:bool,reason:string}
     */
    public function can_transition( string $from, string $to ): array {
        $allowed = self::transitions()[ $from ] ?? [];

        if ( ! in_array( $to, $allowed, true ) ) {
            return [ 'allowed' => false, 'reason' => 'invalid_transition:' . $from . '->' . $to ];
        }

        return [ 'allowed' => true, 'reason' => '' ];
    }

    /**
     * Move a whole class's term results to a new state.
     *
     * @return array{success:bool,moved?:int,error?:string}
     */
    public function transition_class( int $school_id, int $class_id, int $term_id, string $to, int $actor_id, string $reason = '' ): array {
        global $wpdb;

        $table = Schema::table( 'term_results' );

        $states = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT status FROM {$table} WHERE school_id = %d AND class_id = %d AND term_id = %d",
                $school_id,
                $class_id,
                $term_id
            )
        );

        if ( empty( $states ) ) {
            return [ 'success' => false, 'error' => 'no_results_to_move' ];
        }

        // A class part-way through a transition is a sign something went wrong;
        // moving the rest would hide it.
        if ( count( $states ) > 1 ) {
            return [ 'success' => false, 'error' => 'class_in_mixed_states:' . implode( ',', $states ) ];
        }

        $from  = (string) $states[0];
        $check = $this->can_transition( $from, $to );

        if ( ! $check['allowed'] ) {
            return [ 'success' => false, 'error' => $check['reason'] ];
        }

        if ( self::is_reversal( $from, $to ) && trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required_to_reverse' ];
        }

        $fields = [ 'status' => $to ];
        $format = [ '%s' ];

        if ( $to === self::SUBMITTED ) {
            $fields['submitted_by'] = $actor_id;
            $fields['submitted_at'] = current_time( 'mysql', true );
            $format                 = [ '%s', '%d', '%s' ];
        } elseif ( $to === self::APPROVED ) {
            $fields['approved_by'] = $actor_id;
            $fields['approved_at'] = current_time( 'mysql', true );
            $format                = [ '%s', '%d', '%s' ];
        } elseif ( $to === self::PUBLISHED ) {
            $fields['published_at'] = current_time( 'mysql', true );
            $format                 = [ '%s', '%s' ];
        }

        $moved = $wpdb->update(
            $table,
            $fields,
            [ 'school_id' => $school_id, 'class_id' => $class_id, 'term_id' => $term_id, 'status' => $from ],
            $format,
            [ '%d', '%d', '%d', '%s' ]
        );

        EventDispatcher::action( 'educbt_results_transitioned', [
            'school_id' => $school_id,
            'class_id'  => $class_id,
            'term_id'   => $term_id,
            'from'      => $from,
            'to'        => $to,
            'actor_id'  => $actor_id,
            'reason'    => sanitize_text_field( $reason ),
            'reversal'  => self::is_reversal( $from, $to ),
            'count'     => absint( $moved ),
        ] );

        return [ 'success' => true, 'moved' => absint( $moved ) ];
    }

    /**
     * Publishing is refused unless the results are actually fit to be seen. A parent
     * opening a report card with a missing subject is worse than one that is late.
     *
     * @return array{success:bool,published?:int,errors?:array<int,string>}
     */
    public function publish_class( int $school_id, int $class_id, int $session_id, int $term_id, int $actor_id ): array {
        $checks = $this->pre_publish_checks( $school_id, $class_id, $session_id, $term_id );

        if ( ! empty( $checks ) ) {
            return [ 'success' => false, 'errors' => $checks ];
        }

        $result = $this->transition_class( $school_id, $class_id, $term_id, self::PUBLISHED, $actor_id );

        if ( empty( $result['success'] ) ) {
            return [ 'success' => false, 'errors' => [ $result['error'] ?? 'transition_failed' ] ];
        }

        EventDispatcher::action( 'educbt_results_published', [
            'school_id' => $school_id,
            'class_id'  => $class_id,
            'term_id'   => $term_id,
            'count'     => $result['moved'],
        ] );

        return [ 'success' => true, 'published' => $result['moved'] ];
    }

    /**
     * @return array<int,string>
     */
    public function pre_publish_checks( int $school_id, int $class_id, int $session_id, int $term_id ): array {
        global $wpdb;

        $errors       = [];
        $term_results = Schema::table( 'term_results' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS n, SUM(subjects_offered = 0) AS empty_cards,
                        SUM(class_position = 0) AS unranked
                 FROM {$term_results}
                 WHERE school_id = %d AND class_id = %d AND term_id = %d
                 GROUP BY status",
                $school_id,
                $class_id,
                $term_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ 'nothing_compiled' ];
        }

        foreach ( $rows as $row ) {
            if ( (string) $row['status'] !== self::APPROVED ) {
                $errors[] = 'not_approved:' . $row['status'];
            }

            if ( absint( $row['empty_cards'] ) > 0 ) {
                $errors[] = 'students_with_no_subjects:' . $row['empty_cards'];
            }

            if ( absint( $row['unranked'] ) > 0 ) {
                $errors[] = 'students_without_a_position:' . $row['unranked'];
            }
        }

        $readiness = ( new ResultCompilationService() )->readiness( $school_id, $class_id, $session_id, $term_id );

        if ( ! $readiness['ready'] ) {
            $errors[] = 'missing_marks:' . count( $readiness['issues'] );
        }

        return array_values( array_unique( $errors ) );
    }

    /**
     * Remarks from the class teacher and principal.
     *
     * Allowed while compiled or approved, but NOT after publishing — a remark that
     * changes after a parent has read it is indistinguishable from a mistake.
     *
     * @return array{success:bool,error?:string}
     */
    public function set_remarks( int $school_id, int $student_id, int $term_id, array $remarks ): array {
        global $wpdb;

        $table = Schema::table( 'term_results' );

        $status = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$table} WHERE school_id = %d AND student_id = %d AND term_id = %d",
                $school_id,
                $student_id,
                $term_id
            )
        );

        if ( $status === '' ) {
            return [ 'success' => false, 'error' => 'result_not_found' ];
        }

        if ( $status === self::PUBLISHED ) {
            return [ 'success' => false, 'error' => 'already_published_unlock_first' ];
        }

        $fields = [];

        if ( isset( $remarks['class_teacher'] ) ) {
            $fields['class_teacher_remark'] = sanitize_textarea_field( (string) $remarks['class_teacher'] );
        }

        if ( isset( $remarks['principal'] ) ) {
            $fields['principal_remark'] = sanitize_textarea_field( (string) $remarks['principal'] );
        }

        if ( empty( $fields ) ) {
            return [ 'success' => false, 'error' => 'nothing_to_save' ];
        }

        $wpdb->update(
            $table,
            $fields,
            [ 'school_id' => $school_id, 'student_id' => $student_id, 'term_id' => $term_id ],
            array_fill( 0, count( $fields ), '%s' ),
            [ '%d', '%d', '%d' ]
        );

        return [ 'success' => true ];
    }

    /**
     * A student's own results — published only.
     *
     * The status filter lives in the QUERY rather than in the template. A template
     * check is one forgotten `if` away from showing a parent an unapproved result.
     *
     * @return array<int,array<string,mixed>>
     */
    public function published_for_student( int $school_id, int $student_id ): array {
        global $wpdb;

        $term_results = Schema::table( 'term_results' );
        $terms        = Schema::table( 'terms' );
        $sessions     = Schema::table( 'academic_sessions' );
        $classes      = Schema::table( 'classes' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.*, t.title AS term_name, se.title AS session_name, c.display_name AS class_name
                 FROM {$term_results} tr
                 INNER JOIN {$terms} t ON t.id = tr.term_id
                 INNER JOIN {$sessions} se ON se.id = tr.session_id
                 LEFT JOIN {$classes} c ON c.id = tr.class_id
                 WHERE tr.school_id = %d AND tr.student_id = %d AND tr.status = %s
                 ORDER BY se.title DESC, t.term_order DESC",
                $school_id,
                $student_id,
                self::PUBLISHED
            ),
            ARRAY_A
        );
    }

    /**
     * A guardian's view. Scoped through the guardian_student link AND the
     * `can_view_results` flag, so a household where only one parent may see results
     * is respected.
     *
     * @return array<int,array<string,mixed>>
     */
    public function published_for_guardian( int $school_id, int $guardian_id ): array {
        global $wpdb;

        $term_results = Schema::table( 'term_results' );
        $link         = Schema::table( 'guardian_student' );
        $students     = $wpdb->prefix . 'educbt_students';
        $terms        = Schema::table( 'terms' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.*, st.first_name, st.last_name, st.admission_number, t.title AS term_name
                 FROM {$link} gs
                 INNER JOIN {$students} st ON st.id = gs.student_id
                 INNER JOIN {$term_results} tr ON tr.student_id = st.id
                 INNER JOIN {$terms} t ON t.id = tr.term_id
                 WHERE gs.school_id = %d AND gs.guardian_id = %d AND gs.can_view_results = 1
                   AND tr.status = %s
                 ORDER BY st.last_name ASC, t.term_order DESC",
                $school_id,
                $guardian_id,
                self::PUBLISHED
            ),
            ARRAY_A
        );
    }

    /**
     * Where every class stands, for the principal's dashboard. This is the screen
     * that answers "what is holding up results" without anyone chasing by phone.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pipeline_overview( int $school_id, int $term_id ): array {
        global $wpdb;

        $term_results = Schema::table( 'term_results' );
        $classes      = Schema::table( 'classes' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id AS class_id, c.display_name AS class_name,
                        tr.status, COUNT(*) AS students
                 FROM {$term_results} tr
                 INNER JOIN {$classes} c ON c.id = tr.class_id
                 WHERE tr.school_id = %d AND tr.term_id = %d
                 GROUP BY c.id, tr.status
                 ORDER BY c.display_name ASC",
                $school_id,
                $term_id
            ),
            ARRAY_A
        );
    }
}
