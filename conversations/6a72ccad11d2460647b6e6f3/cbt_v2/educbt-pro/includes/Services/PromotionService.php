<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 8 — promotion.
 *
 * You said the manual page would not work for 500 students, and you were right.
 * Promotion here is a RULE-DRIVEN BATCH WITH HUMAN REVIEW, in four steps:
 *
 *   1. The principal defines a rule set per level.
 *   2. The system evaluates every student and produces a PROPOSAL.
 *   3. The principal reviews ONE SCREEN of counts and exceptions, overriding
 *      individuals with a reason where needed.
 *   4. Commit writes next session's enrollments in a single transaction.
 *
 * The design decision that matters: the proposal is stored, not applied. Nothing
 * moves until a human commits, and the decision for every student — proposed and
 * final — is kept, so "why was my child not promoted" has an answer on file.
 *
 * Three outcomes rather than two. "Promote on trial" is real practice in Nigerian
 * schools and a system offering only promote/repeat forces a principal to lie in
 * one direction or the other.
 */
class PromotionService {

    public const PROMOTE = 'promote';
    public const TRIAL   = 'trial';
    public const REPEAT  = 'repeat';
    public const GRADUATE = 'graduate';
    public const UNRESOLVED = 'unresolved';

    /**
     * Defaults reflecting common practice. Every value is overridable per level.
     *
     * @return array<string,mixed>
     */
    public static function default_rules(): array {
        return [
            'pass_mark'          => 40.0,
            'promote_average'    => 45.0,
            'trial_average'      => 40.0,
            'min_subjects_passed' => 6,
            // English and Mathematics are compulsory to pass in most schools.
            'must_pass_codes'    => [ 'ENG', 'MTH', 'ENG-J', 'MTH-J' ],
            'require_core'       => true,
        ];
    }

    public function rules_for( int $school_id, int $level_id ): array {
        $stored = get_option( 'educbt_promotion_rules_' . $school_id . '_' . $level_id, [] );

        return is_array( $stored ) && ! empty( $stored )
            ? array_merge( self::default_rules(), $stored )
            : self::default_rules();
    }

    public function set_rules( int $school_id, int $level_id, array $rules ): bool {
        return update_option(
            'educbt_promotion_rules_' . $school_id . '_' . $level_id,
            array_merge( self::default_rules(), $rules ),
            false
        );
    }

    /**
     * Evaluate a level and store a proposal. Nothing is applied.
     *
     * @return array{success:bool,batch_id?:int,summary?:array<string,int>,error?:string}
     */
    public function propose( int $school_id, int $level_id, int $from_session_id, int $to_session_id, int $actor_id ): array {
        global $wpdb;

        if ( $from_session_id === $to_session_id ) {
            return [ 'success' => false, 'error' => 'sessions_must_differ' ];
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

        $rules    = $this->rules_for( $school_id, $level_id );
        $students = $this->evaluate_level( $school_id, $level_id, $from_session_id, $rules, $level );

        if ( empty( $students ) ) {
            return [ 'success' => false, 'error' => 'no_students_to_evaluate' ];
        }

        $summary = [
            self::PROMOTE    => 0,
            self::TRIAL      => 0,
            self::REPEAT     => 0,
            self::GRADUATE   => 0,
            self::UNRESOLVED => 0,
        ];

        foreach ( $students as $student ) {
            $summary[ $student['outcome'] ]++;
        }

        $wpdb->insert(
            Schema::table( 'promotion_batches' ),
            [
                'school_id'        => $school_id,
                'from_session_id'  => $from_session_id,
                'to_session_id'    => $to_session_id,
                'level_id'         => $level_id,
                'rules'            => (string) wp_json_encode( $rules ),
                'total_evaluated'  => count( $students ),
                'total_promoted'   => $summary[ self::PROMOTE ] + $summary[ self::GRADUATE ],
                'total_trial'      => $summary[ self::TRIAL ],
                'total_repeated'   => $summary[ self::REPEAT ],
                'total_unresolved' => $summary[ self::UNRESOLVED ],
                'status'           => 'proposed',
                'created_by'       => $actor_id,
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d' ]
        );

        $batch_id = absint( $wpdb->insert_id );

        foreach ( $students as $student ) {
            $wpdb->insert(
                Schema::table( 'promotion_decisions' ),
                [
                    'school_id'        => $school_id,
                    'batch_id'         => $batch_id,
                    'student_id'       => $student['student_id'],
                    'from_class_id'    => $student['class_id'],
                    'to_class_id'      => $student['to_class_id'],
                    'proposed_outcome' => $student['outcome'],
                    'final_outcome'    => $student['outcome'],
                    'average_score'    => $student['average'],
                    'subjects_passed'  => $student['subjects_passed'],
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%d' ]
            );
        }

        EventDispatcher::action( 'educbt_promotion_proposed', [
            'school_id' => $school_id,
            'batch_id'  => $batch_id,
            'level_id'  => $level_id,
            'summary'   => $summary,
        ] );

        return [ 'success' => true, 'batch_id' => $batch_id, 'summary' => $summary ];
    }

    /**
     * Score every student in a level against the rules.
     *
     * The annual average is taken across ALL terms in the session, not just the
     * third. Promoting on one term's work would ignore two thirds of the year.
     *
     * @return array<int,array<string,mixed>>
     */
    private function evaluate_level( int $school_id, int $level_id, int $session_id, array $rules, array $level ): array {
        global $wpdb;

        $term_results    = Schema::table( 'term_results' );
        $subject_results = Schema::table( 'subject_results' );
        $enrollments     = Schema::table( 'enrollments' );
        $classes         = Schema::table( 'classes' );
        $students_table  = $wpdb->prefix . 'educbt_students';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.student_id, e.class_id, c.arm, c.department_id,
                        st.admission_number, CONCAT(st.first_name, ' ', st.last_name) AS name,
                        COUNT(DISTINCT tr.term_id) AS terms_recorded,
                        ROUND(AVG(tr.average_score), 2) AS annual_average
                 FROM {$enrollments} e
                 INNER JOIN {$classes} c ON c.id = e.class_id
                 INNER JOIN {$students_table} st ON st.id = e.student_id
                 LEFT JOIN {$term_results} tr
                        ON tr.student_id = e.student_id AND tr.session_id = e.session_id
                       AND tr.status = %s
                 WHERE e.school_id = %d AND c.level_id = %d AND e.session_id = %d AND e.status = 'active'
                 GROUP BY e.student_id",
                ResultWorkflowService::PUBLISHED,
                $school_id,
                $level_id,
                $session_id
            ),
            ARRAY_A
        );

        $next_level_id = $level['next_level_id'] !== null ? absint( $level['next_level_id'] ) : 0;
        $is_terminal   = ! empty( $level['is_terminal'] );

        $out = [];

        foreach ( $rows as $row ) {
            $student_id = absint( $row['student_id'] );
            $terms      = absint( $row['terms_recorded'] );
            $average    = (float) $row['annual_average'];

            // No published results means no defensible decision. Flagging is the
            // honest outcome; guessing would quietly repeat or promote a child on
            // no evidence.
            if ( $terms === 0 ) {
                $out[] = $this->decision( $row, self::UNRESOLVED, 0.0, 0, 0, 'no_published_results' );
                continue;
            }

            $passes = $this->subject_passes( $school_id, $student_id, $session_id, (float) $rules['pass_mark'] );

            $failed_core = false;

            if ( ! empty( $rules['require_core'] ) ) {
                foreach ( (array) $rules['must_pass_codes'] as $code ) {
                    if ( isset( $passes['by_code'][ $code ] ) && $passes['by_code'][ $code ] === false ) {
                        $failed_core = true;
                        break;
                    }
                }
            }

            // The subjects-passed threshold is CAPPED at the number the student
            // actually offers. A fixed "must pass 6" is impossible for a student
            // offering 5, and the first version of this silently demoted every one
            // of them to trial — including a student averaging 88%.
            $required_passes = min( absint( $rules['min_subjects_passed'] ), max( 1, $passes['total'] ) );

            if ( $is_terminal ) {
                // JSS3 and SS3 leave rather than advance.
                $outcome = ( $average >= (float) $rules['trial_average'] && ! $failed_core )
                    ? self::GRADUATE
                    : self::REPEAT;
            } elseif ( $failed_core ) {
                $outcome = self::REPEAT;
            } elseif ( $average >= (float) $rules['promote_average'] && $passes['passed'] >= $required_passes ) {
                $outcome = self::PROMOTE;
            } elseif ( $average >= (float) $rules['trial_average'] ) {
                $outcome = self::TRIAL;
            } else {
                $outcome = self::REPEAT;
            }

            $to_class = 0;

            if ( in_array( $outcome, [ self::PROMOTE, self::TRIAL ], true ) && $next_level_id > 0 ) {
                $to_class = $this->target_class( $school_id, $next_level_id, (string) $row['arm'], $row['department_id'] );
            } elseif ( $outcome === self::REPEAT ) {
                $to_class = absint( $row['class_id'] );
            }

            $out[] = $this->decision(
                $row,
                $outcome,
                $average,
                $passes['passed'],
                $to_class,
                $failed_core ? 'failed_compulsory_subject' : ''
            );
        }

        return $out;
    }

    private function decision( array $row, string $outcome, float $average, int $passed, int $to_class, string $note ): array {
        return [
            'student_id'      => absint( $row['student_id'] ),
            'name'            => (string) $row['name'],
            'admission_number' => (string) $row['admission_number'],
            'class_id'        => absint( $row['class_id'] ),
            'to_class_id'     => $to_class,
            'outcome'         => $outcome,
            'average'         => $average,
            'subjects_passed' => $passed,
            'note'            => $note,
        ];
    }

    /**
     * @return array{passed:int,total:int,by_code:array<string,bool>}
     */
    private function subject_passes( int $school_id, int $student_id, int $session_id, float $pass_mark ): array {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT s.code, AVG(sr.total) AS annual
                 FROM ' . Schema::table( 'subject_results' ) . ' sr
                 INNER JOIN ' . Schema::table( 'subjects_v2' ) . ' s ON s.id = sr.subject_id
                 WHERE sr.school_id = %d AND sr.student_id = %d AND sr.session_id = %d
                 GROUP BY sr.subject_id',
                $school_id,
                $student_id,
                $session_id
            ),
            ARRAY_A
        );

        $passed  = 0;
        $by_code = [];

        foreach ( $rows as $row ) {
            $is_pass                        = (float) $row['annual'] >= $pass_mark;
            $by_code[ (string) $row['code'] ] = $is_pass;

            if ( $is_pass ) {
                $passed++;
            }
        }

        return [ 'passed' => $passed, 'total' => count( $rows ), 'by_code' => $by_code ];
    }

    /**
     * Keep the student's arm and department where the next level has one.
     */
    private function target_class( int $school_id, int $next_level_id, string $arm, $department_id ): int {
        global $wpdb;

        $table = Schema::table( 'classes' );

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE school_id = %d AND level_id = %d AND arm = %s AND status = 'active'
                 ORDER BY (department_id <=> %d) DESC LIMIT 1",
                $school_id,
                $next_level_id,
                $arm,
                $department_id !== null ? absint( $department_id ) : null
            )
        );

        if ( $id ) {
            return absint( $id );
        }

        // Arm not offered at the next level: fall back to any class at that level.
        $fallback = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND level_id = %d AND status = 'active' ORDER BY arm ASC LIMIT 1",
                $school_id,
                $next_level_id
            )
        );

        return $fallback ? absint( $fallback ) : 0;
    }

    /**
     * The review screen: counts, plus only the students who need a human.
     *
     * A principal should not scroll 500 rows. They should see five numbers and the
     * handful of cases that are not clear-cut.
     *
     * @return array<string,mixed>
     */
    public function review( int $school_id, int $batch_id ): array {
        global $wpdb;

        $batch = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'promotion_batches' ) . ' WHERE id = %d AND school_id = %d',
                $batch_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $batch ) {
            return [ 'found' => false ];
        }

        $decisions = Schema::table( 'promotion_decisions' );
        $students  = $wpdb->prefix . 'educbt_students';

        $exceptions = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, st.admission_number, CONCAT(st.first_name, ' ', st.last_name) AS name
                 FROM {$decisions} d
                 INNER JOIN {$students} st ON st.id = d.student_id
                 WHERE d.batch_id = %d AND d.proposed_outcome <> %s
                 ORDER BY FIELD(d.proposed_outcome, %s, %s, %s), d.average_score ASC",
                $batch_id,
                self::PROMOTE,
                self::UNRESOLVED,
                self::REPEAT,
                self::TRIAL
            ),
            ARRAY_A
        );

        // Borderline promotions: within 3 marks of the threshold, worth a second look
        // even though the rules cleared them.
        $rules = json_decode( (string) $batch['rules'], true ) ?: self::default_rules();

        $borderline = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, st.admission_number, CONCAT(st.first_name, ' ', st.last_name) AS name
                 FROM {$decisions} d
                 INNER JOIN {$students} st ON st.id = d.student_id
                 WHERE d.batch_id = %d AND d.proposed_outcome = %s AND d.average_score < %f
                 ORDER BY d.average_score ASC",
                $batch_id,
                self::PROMOTE,
                (float) $rules['promote_average'] + 3.0
            ),
            ARRAY_A
        );

        return [
            'found'      => true,
            'batch'      => $batch,
            'summary'    => [
                'evaluated'  => absint( $batch['total_evaluated'] ),
                'promoted'   => absint( $batch['total_promoted'] ),
                'trial'      => absint( $batch['total_trial'] ),
                'repeated'   => absint( $batch['total_repeated'] ),
                'unresolved' => absint( $batch['total_unresolved'] ),
            ],
            'exceptions' => $exceptions,
            'borderline' => $borderline,
            'needs_attention' => count( $exceptions ),
        ];
    }

    /**
     * Override one student's outcome. A reason is required — "why was my child not
     * promoted" must be answerable from the record, not from memory.
     */
    public function override( int $school_id, int $batch_id, int $student_id, string $outcome, string $reason, int $actor_id ): array {
        global $wpdb;

        if ( ! in_array( $outcome, [ self::PROMOTE, self::TRIAL, self::REPEAT, self::GRADUATE ], true ) ) {
            return [ 'success' => false, 'error' => 'invalid_outcome' ];
        }

        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $batch_status = (string) $wpdb->get_var(
            $wpdb->prepare( 'SELECT status FROM ' . Schema::table( 'promotion_batches' ) . ' WHERE id = %d', $batch_id )
        );

        if ( $batch_status !== 'proposed' ) {
            return [ 'success' => false, 'error' => 'batch_already_committed' ];
        }

        $updated = $wpdb->update(
            Schema::table( 'promotion_decisions' ),
            [
                'final_outcome'   => $outcome,
                'override_reason' => sanitize_text_field( $reason ),
                'overridden_by'   => $actor_id,
            ],
            [ 'batch_id' => $batch_id, 'student_id' => $student_id, 'school_id' => $school_id ],
            [ '%s', '%s', '%d' ],
            [ '%d', '%d', '%d' ]
        );

        EventDispatcher::action( 'educbt_promotion_overridden', [
            'school_id'  => $school_id,
            'batch_id'   => $batch_id,
            'student_id' => $student_id,
            'outcome'    => $outcome,
            'reason'     => $reason,
            'actor_id'   => $actor_id,
        ] );

        return [ 'success' => (bool) $updated ];
    }

    /**
     * Commit: write next session's enrollments in one transaction.
     *
     * Refused while any student is unresolved. Committing around an unresolved case
     * silently loses a child from the roll — they are in no class next session and
     * nobody notices until they cannot log in.
     *
     * @return array{success:bool,enrolled?:int,graduated?:int,errors?:array<int,string>}
     */
    public function commit( int $school_id, int $batch_id, int $actor_id ): array {
        global $wpdb;

        $batches   = Schema::table( 'promotion_batches' );
        $decisions = Schema::table( 'promotion_decisions' );

        $batch = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$batches} WHERE id = %d AND school_id = %d", $batch_id, $school_id ),
            ARRAY_A
        );

        if ( ! $batch ) {
            return [ 'success' => false, 'errors' => [ 'batch_not_found' ] ];
        }

        if ( (string) $batch['status'] !== 'proposed' ) {
            return [ 'success' => false, 'errors' => [ 'already_committed' ] ];
        }

        $unresolved = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$decisions} WHERE batch_id = %d AND final_outcome = %s",
                    $batch_id,
                    self::UNRESOLVED
                )
            )
        );

        if ( $unresolved > 0 ) {
            return [ 'success' => false, 'errors' => [ 'unresolved_students:' . $unresolved ] ];
        }

        $missing_target = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$decisions}
                     WHERE batch_id = %d AND final_outcome IN (%s, %s) AND (to_class_id IS NULL OR to_class_id = 0)",
                    $batch_id,
                    self::PROMOTE,
                    self::TRIAL
                )
            )
        );

        if ( $missing_target > 0 ) {
            return [ 'success' => false, 'errors' => [ 'students_with_no_destination_class:' . $missing_target ] ];
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$decisions} WHERE batch_id = %d", $batch_id ),
            ARRAY_A
        );

        $enrollments   = Schema::table( 'enrollments' );
        $students_table = $wpdb->prefix . 'educbt_students';
        $to_session    = absint( $batch['to_session_id'] );

        $enrolled   = 0;
        $graduated  = 0;

        $wpdb->query( 'START TRANSACTION' );

        foreach ( $rows as $row ) {
            $student_id = absint( $row['student_id'] );
            $outcome    = (string) $row['final_outcome'];

            if ( $outcome === self::GRADUATE ) {
                $wpdb->update(
                    $students_table,
                    [ 'status' => 'graduated' ],
                    [ 'id' => $student_id, 'school_id' => $school_id ],
                    [ '%s' ],
                    [ '%d', '%d' ]
                );

                $graduated++;
                continue;
            }

            // UNIQUE (student_id, session_id) makes a re-commit harmless rather than
            // creating a second enrollment for the same year.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$enrollments}
                        (school_id, student_id, class_id, session_id, enrolled_on, status)
                     VALUES (%d, %d, %d, %d, %s, 'active')
                     ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), status = 'active'",
                    $school_id,
                    $student_id,
                    absint( $row['to_class_id'] ),
                    $to_session,
                    gmdate( 'Y-m-d' )
                )
            );

            $enrolled++;
        }

        $wpdb->update(
            $batches,
            [ 'status' => 'committed', 'committed_by' => $actor_id, 'committed_at' => current_time( 'mysql', true ) ],
            [ 'id' => $batch_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_promotion_committed', [
            'school_id' => $school_id,
            'batch_id'  => $batch_id,
            'enrolled'  => $enrolled,
            'graduated' => $graduated,
            'actor_id'  => $actor_id,
        ] );

        return [ 'success' => true, 'enrolled' => $enrolled, 'graduated' => $graduated ];
    }

    /**
     * Reverse a committed batch.
     *
     * Promotion touches every student in a year group, so a mistake caught an hour
     * later must be undoable without editing rows by hand.
     */
    public function reverse( int $school_id, int $batch_id, string $reason, int $actor_id ): array {
        global $wpdb;

        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $batches = Schema::table( 'promotion_batches' );

        $batch = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$batches} WHERE id = %d AND school_id = %d", $batch_id, $school_id ),
            ARRAY_A
        );

        if ( ! $batch || (string) $batch['status'] !== 'committed' ) {
            return [ 'success' => false, 'error' => 'batch_not_committed' ];
        }

        $decisions = Schema::table( 'promotion_decisions' );

        $student_ids = array_map(
            'absint',
            (array) $wpdb->get_col( $wpdb->prepare( "SELECT student_id FROM {$decisions} WHERE batch_id = %d", $batch_id ) )
        );

        if ( empty( $student_ids ) ) {
            return [ 'success' => false, 'error' => 'no_decisions' ];
        }

        $placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );

        $wpdb->query( 'START TRANSACTION' );

        $removed = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . Schema::table( 'enrollments' ) .
                " WHERE school_id = %d AND session_id = %d AND student_id IN ({$placeholders})",
                array_merge( [ $school_id, absint( $batch['to_session_id'] ) ], $student_ids )
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . "educbt_students SET status = 'active'
                 WHERE school_id = %d AND status = 'graduated' AND id IN ({$placeholders})",
                array_merge( [ $school_id ], $student_ids )
            )
        );

        $wpdb->update( $batches, [ 'status' => 'reversed' ], [ 'id' => $batch_id ], [ '%s' ], [ '%d' ] );
        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_promotion_reversed', [
            'school_id' => $school_id,
            'batch_id'  => $batch_id,
            'removed'   => absint( $removed ),
            'reason'    => $reason,
            'actor_id'  => $actor_id,
        ] );

        return [ 'success' => true, 'removed' => absint( $removed ) ];
    }
}
