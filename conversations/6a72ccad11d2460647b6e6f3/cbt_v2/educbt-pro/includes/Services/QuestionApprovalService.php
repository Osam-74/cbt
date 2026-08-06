<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Question submission and approval.
 *
 * A teacher writes questions; the exam officer or principal checks them before they
 * can appear in a paper. Two things make this worth having rather than trusting
 * everyone to be careful:
 *
 *  1. QUOTAS. A subject needs enough questions to build a paper from. Twenty
 *     objective and four theory is the working default — a forty-question paper
 *     drawn from a bank of forty is not a paper, it is the whole bank in order.
 *  2. A GATE BEFORE THE EXAM, NOT AFTER. A wrong answer key discovered during
 *     marking has already failed a class. Discovered at approval, it costs nothing.
 *
 * Approval is per QUESTION but reviewed per SUBMISSION — one teacher's questions for
 * one subject + level — because that is the unit a reviewer actually works through.
 */
class QuestionApprovalService {

    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REVISION = 'revision';

    public const DEFAULT_OBJECTIVE_QUOTA = 20;
    public const DEFAULT_THEORY_QUOTA    = 4;

    /**
     * @return array{objective:int,theory:int}
     */
    public function quotas( int $school_id ): array {
        $stored = get_option( 'educbt_question_quota_' . $school_id, [] );

        return [
            'objective' => absint( $stored['objective'] ?? self::DEFAULT_OBJECTIVE_QUOTA ),
            'theory'    => absint( $stored['theory'] ?? self::DEFAULT_THEORY_QUOTA ),
        ];
    }

    public function set_quotas( int $school_id, int $objective, int $theory ): bool {
        return update_option(
            'educbt_question_quota_' . $school_id,
            [ 'objective' => max( 0, $objective ), 'theory' => max( 0, $theory ) ],
            false
        );
    }

    /**
     * Every teacher's submission, per subject and class level, with progress against the quota.
     *
     * This is the reviewer's whole screen: who has submitted what, who is short, and
     * what is waiting to be looked at. Grouped by (subject_id, level_id, teacher_id).
     *
     * @param int $school_id
     * @param int $staff_id
     * @return array<int,array<string,mixed>>
     */
    public function submissions( int $school_id, int $staff_id = 0 ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        $sets      = Schema::table( 'question_sets' );
        $subjects  = Schema::table( 'subjects_v2' );
        $staff     = Schema::table( 'staff' );
        $levels    = Schema::table( 'class_levels' );
        $depts     = Schema::table( 'departments' );

        $where  = 'qs.school_id = %d';
        $params = [ $school_id ];

        if ( $staff_id > 0 ) {
            $where   .= ' AND qs.teacher_id = %d';
            $params[] = $staff_id;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT qs.subject_id,
                        qs.level_id,
                        qs.teacher_id,
                        qs.department_id,
                        s.name AS subject_name,
                        CONCAT(st.first_name, ' ', st.last_name) AS teacher_name,
                        l.name AS level_name,
                        d.name AS department_name,
                        GROUP_CONCAT(DISTINCT qs.id ORDER BY qs.id ASC) AS set_ids_csv,
                        MAX(qs.submitted_at) AS submitted_at,
                        MAX(CASE WHEN qs.exam_type = 'objective' THEN qs.status ELSE NULL END) AS objective_status,
                        MAX(CASE WHEN qs.exam_type = 'theory' THEN qs.status ELSE NULL END) AS theory_status,
                        SUM(CASE WHEN q.id IS NOT NULL AND q.question_type <> 'theory' THEN 1 ELSE 0 END) AS objective_total,
                        SUM(CASE WHEN q.id IS NOT NULL AND q.question_type = 'theory' THEN 1 ELSE 0 END) AS theory_total,
                        SUM(CASE WHEN q.id IS NOT NULL AND q.approval_status = 'approved' THEN 1 ELSE 0 END) AS approved,
                        SUM(CASE WHEN q.id IS NOT NULL AND q.approval_status = 'revision' THEN 1 ELSE 0 END) AS revision,
                        SUM(CASE WHEN q.id IS NOT NULL AND (q.approval_status = 'pending' OR q.approval_status IS NULL) THEN 1 ELSE 0 END) AS pending
                 FROM {$sets} qs
                 LEFT JOIN {$subjects} s ON s.id = qs.subject_id
                 LEFT JOIN {$staff} st ON st.id = qs.teacher_id
                 LEFT JOIN {$levels} l ON l.id = qs.level_id
                 LEFT JOIN {$depts} d ON d.id = qs.department_id
                 LEFT JOIN {$questions} q ON q.question_set_id = qs.id AND q.status = 'active'
                 WHERE {$where}
                 GROUP BY qs.subject_id, qs.level_id, qs.teacher_id
                 ORDER BY st.last_name ASC, st.first_name ASC, s.name ASC, l.level_order ASC, qs.level_id ASC",
                $params
            ),
            ARRAY_A
        );

        $quotas = $this->quotas( $school_id );
        $out    = [];

        foreach ( $rows as $row ) {
            $objective = absint( $row['objective_total'] );
            $theory    = absint( $row['theory_total'] );

            $short_objective = max( 0, $quotas['objective'] - $objective );
            $short_theory    = max( 0, $quotas['theory'] - $theory );

            $raw_ids = explode( ',', (string) ( $row['set_ids_csv'] ?? '' ) );
            $set_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

            $level_name = trim( (string) ( $row['level_name'] ?? '' ) );
            $dept_name  = trim( (string) ( $row['department_name'] ?? '' ) );
            $display_level = ( $level_name !== '' && $dept_name !== '' )
                ? $level_name . ' ' . $dept_name
                : $level_name;

            $submitted_at = (string) ( $row['submitted_at'] ?? '' );

            $out[] = [
                'subject_id'       => absint( $row['subject_id'] ),
                'subject_name'     => trim( (string) ( $row['subject_name'] ?? '' ) ) !== ''
                    ? (string) $row['subject_name']
                    : 'Unassigned subject',
                'staff_id'         => absint( $row['teacher_id'] ),
                'created_by_staff' => absint( $row['teacher_id'] ),
                'teacher_name'     => trim( (string) ( $row['teacher_name'] ?? '' ) ) ?: 'Not attributed',
                'level_id'         => absint( $row['level_id'] ),
                'level_name'       => $display_level,
                'department_id'    => absint( $row['department_id'] ),
                'objective'        => $objective,
                'objective_count'  => $objective,
                'objective_status' => (string) ( $row['objective_status'] ?? '' ),
                'theory'           => $theory,
                'theory_count'     => $theory,
                'theory_status'    => (string) ( $row['theory_status'] ?? '' ),
                'approved'         => absint( $row['approved'] ),
                'pending'          => absint( $row['pending'] ),
                'revision'         => absint( $row['revision'] ),
                'short_objective'  => $short_objective,
                'short_theory'     => $short_theory,
                'complete'         => $short_objective === 0 && $short_theory === 0,
                'submitted_at'     => $submitted_at,
                'last_submitted'   => $submitted_at,
                'set_ids'          => $set_ids,
                'question_set_ids' => $set_ids,
                'question_set_id'  => ! empty( $set_ids ) ? $set_ids[0] : 0,
                'sent_back'        => absint( $row['revision'] ) > 0,
                'all_approved'     => absint( $row['approved'] ) > 0 && absint( $row['pending'] ) === 0 && absint( $row['revision'] ) === 0,
            ];
        }

        return $out;
    }

    /**
     * The questions in one submission, for review.
     *
     * @param int $school_id
     * @param int $subject_id
     * @param int $staff_id
     * @param int $level_id
     * @return array<int,array<string,mixed>>
     */
    public function review_queue( int $school_id, int $subject_id, int $staff_id, int $level_id = 0, string $set_ids_csv = '' ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );
        $sets      = Schema::table( 'question_sets' );
        $levels    = Schema::table( 'class_levels' );

        // Match by created_by_staff OR question_set_id — older questions may not have
        // created_by_staff set, so the set_ids fallback catches them.
        $set_ids = array_filter( array_map( 'absint', explode( ',', $set_ids_csv ) ) );

        if ( ! empty( $set_ids ) ) {
            $set_placeholders = implode( ',', array_fill( 0, count( $set_ids ), '%d' ) );
            $where  = "q.school_id = %d AND q.subject_id = %d AND q.status = 'active' AND (q.created_by_staff = %d OR q.question_set_id IN ({$set_placeholders}))";
            $params = array_merge( [ $school_id, $subject_id, $staff_id ], $set_ids );
        } else {
            $where  = "q.school_id = %d AND q.subject_id = %d AND q.created_by_staff = %d AND q.status = 'active'";
            $params = [ $school_id, $subject_id, $staff_id ];
        }

        if ( $level_id > 0 ) {
            $level_name = (string) $wpdb->get_var(
                $wpdb->prepare( "SELECT name FROM {$levels} WHERE id = %d AND school_id = %d", $level_id, $school_id )
            );

            if ( $level_name !== '' ) {
                $where   .= " AND (q.class_level = %s OR q.question_set_id IN (SELECT id FROM {$sets} WHERE level_id = %d AND school_id = %d))";
                $params[] = $level_name;
                $params[] = $level_id;
                $params[] = $school_id;
            } else {
                $where   .= " AND q.question_set_id IN (SELECT id FROM {$sets} WHERE level_id = %d AND school_id = %d)";
                $params[] = $level_id;
                $params[] = $school_id;
            }
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.question_text, q.question_type, q.marks, q.approval_status, q.review_note, q.class_level
                 FROM {$questions} q
                 WHERE {$where}
                 ORDER BY q.question_type ASC, q.id ASC",
                $params
            ),
            ARRAY_A
        );

        foreach ( $rows as $i => $row ) {
            $rows[ $i ]['options'] = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_key, option_text, is_correct FROM {$options}
                     WHERE question_id = %d ORDER BY sort_order ASC",
                    absint( $row['id'] )
                ),
                ARRAY_A
            );

            // A reviewer needs to see this: an objective question with no correct
            // option marks every student wrong and is the single most common fault.
            $rows[ $i ]['has_answer'] = (string) $row['question_type'] === 'theory'
                || array_sum( array_map( static fn( array $o ): int => (int) $o['is_correct'], $rows[ $i ]['options'] ) ) > 0;
        }

        return $rows;
    }

    /**
     * Approve or send back a whole submission, or named questions within it.
     *
     * @param array<int,int> $question_ids empty means the whole submission
     * @return array{success:bool,changed:int}
     */
    public function decide( int $school_id, int $subject_id, int $staff_id, string $decision, string $note, int $reviewer_id, array $question_ids = [], string $set_ids_csv = '' ): array {
        global $wpdb;

        if ( ! in_array( $decision, [ self::APPROVED, self::REVISION, self::PENDING ], true ) ) {
            return [ 'success' => false, 'changed' => 0 ];
        }

        // Sending work back without saying why is not a review, it is an obstacle.
        if ( $decision === self::REVISION && trim( $note ) === '' ) {
            return [ 'success' => false, 'changed' => 0, 'error' => 'note_required' ];
        }

        $questions = $wpdb->prefix . 'educbt_questions';

        $decide_set_ids = array_filter( array_map( 'absint', explode( ',', $set_ids_csv ) ) );

        if ( ! empty( $decide_set_ids ) ) {
            $set_placeholders = implode( ',', array_fill( 0, count( $decide_set_ids ), '%d' ) );
            $sql    = "UPDATE {$questions} SET approval_status = %s, review_note = %s, reviewed_by = %d, reviewed_at = %s
                       WHERE school_id = %d AND subject_id = %d AND status = 'active'
                       AND (created_by_staff = %d OR question_set_id IN ({$set_placeholders}))";
            $params = array_merge( [ $decision, sanitize_textarea_field( $note ), $reviewer_id, current_time( 'mysql', true ), $school_id, $subject_id, $staff_id ], $decide_set_ids );
        } else {
            $sql    = "UPDATE {$questions} SET approval_status = %s, review_note = %s, reviewed_by = %d, reviewed_at = %s
                       WHERE school_id = %d AND subject_id = %d AND created_by_staff = %d AND status = 'active'";
            $params = [ $decision, sanitize_textarea_field( $note ), $reviewer_id, current_time( 'mysql', true ), $school_id, $subject_id, $staff_id ];
        }

        if ( ! empty( $question_ids ) ) {
            $ids          = array_map( 'absint', $question_ids );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $sql         .= " AND id IN ({$placeholders})";
            $params       = array_merge( $params, $ids );
        }

        $changed = absint( $wpdb->query( $wpdb->prepare( $sql, $params ) ) );

        // The decision used to touch individual question rows only, leaving the
        // question SET still sitting at "submitted". The reviewer saw no change in
        // the table, and the teacher could not edit work that had supposedly been
        // sent back. Move the set itself, and timestamp it.
        $sets_table = Schema::table( 'question_sets' );

        if ( ! empty( $decide_set_ids ) ) {
            $set_ids = $decide_set_ids;
        } else {
            $set_ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT question_set_id FROM {$questions}
                     WHERE school_id = %d AND subject_id = %d AND created_by_staff = %d
                       AND question_set_id IS NOT NULL AND question_set_id > 0",
                    $school_id,
                    $subject_id,
                    $staff_id
                )
            );
        }

        $new_status = $decision === self::APPROVED ? 'approved' : ( $decision === self::REVISION ? 'returned' : 'under_review' );
        $now        = current_time( 'mysql' );
        $sets_moved = 0;

        foreach ( $set_ids as $sid ) {
            $sid = absint( $sid );
            if ( $sid <= 0 ) {
                continue;
            }

            $current = (string) $wpdb->get_var(
                $wpdb->prepare( "SELECT status FROM {$sets_table} WHERE id = %d AND school_id = %d", $sid, $school_id )
            );

            // Only sets that are actually with the reviewer can be decided on.
            if ( ! in_array( $current, [ 'submitted', 'under_review' ], true ) ) {
                continue;
            }

            $wpdb->update(
                $sets_table,
                [
                    'status'            => $new_status,
                    'reviewed_at'       => $now,
                    'reviewed_by'       => $reviewer_id,
                    'reviewer_comment'  => sanitize_textarea_field( $note ),
                ],
                [ 'id' => $sid, 'school_id' => $school_id ],
                [ '%s', '%s', '%d', '%s' ],
                [ '%d', '%d' ]
            );

            $this->append_set_revision( $sid, $new_status, $reviewer_id, $note );
            $sets_moved++;
        }

        $this->notify_teacher( $school_id, $subject_id, $staff_id, $decision, $note, $changed );

        EventDispatcher::action( 'educbt_questions_reviewed', [
            'school_id'  => $school_id,
            'subject_id' => $subject_id,
            'staff_id'   => $staff_id,
            'decision'   => $decision,
            'changed'    => $changed,
            'reviewer'   => $reviewer_id,
        ] );

        return [ 'success' => true, 'changed' => $changed, 'sets' => $sets_moved ];
    }

    /**
     * Append a timestamped entry to a set's revision history, so the table can show
     * when each stage happened rather than just the current status.
     */
    private function append_set_revision( int $set_id, string $action, int $actor_id, string $note = '' ): void {
        global $wpdb;

        $table = Schema::table( 'question_sets' );

        $raw     = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT revision_history FROM {$table} WHERE id = %d", $set_id )
        );
        $history = (array) json_decode( $raw ?: '[]', true );

        $history[] = [
            'action' => $action,
            'by'     => $actor_id,
            'note'   => sanitize_textarea_field( $note ),
            'at'     => current_time( 'mysql' ),
        ];

        $wpdb->update(
            $table,
            [ 'revision_history' => wp_json_encode( $history ) ],
            [ 'id' => $set_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Nudge a teacher whose submission is short of the quota.
     *
     * @return array{success:bool,message:string}
     */
    public function remind( int $school_id, int $subject_id, int $staff_id, int $level_id = 0 ): array {
        global $wpdb;

        $summary = null;

        foreach ( $this->submissions( $school_id, $staff_id ) as $row ) {
            if ( $row['subject_id'] === $subject_id && ( $level_id === 0 || $row['level_id'] === $level_id ) ) {
                $summary = $row;
                break;
            }
        }

        if ( $summary === null ) {
            return [ 'success' => false, 'message' => 'nothing_submitted' ];
        }

        $user_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT wp_user_id FROM ' . Schema::table( 'staff' ) . ' WHERE id = %d AND school_id = %d',
                    $staff_id,
                    $school_id
                )
            )
        );

        if ( $user_id === 0 ) {
            return [ 'success' => false, 'message' => 'no_account' ];
        }

        $needed = [];

        if ( $summary['short_objective'] > 0 ) {
            $needed[] = sprintf( '%d more objective question(s)', $summary['short_objective'] );
        }

        if ( $summary['short_theory'] > 0 ) {
            $needed[] = sprintf( '%d more written question(s)', $summary['short_theory'] );
        }

        $body = empty( $needed )
            ? sprintf( 'Your %s questions are complete. Thank you.', $summary['subject_name'] )
            : sprintf(
                'Your %s submission still needs %s. Please complete it so the paper can be set.',
                $summary['subject_name'],
                implode( ' and ', $needed )
            );

        ( new NotificationService() )->notify(
            $school_id,
            $user_id,
            NotificationService::SCORE_SUBMITTED,
            'Question submission reminder',
            $body,
            home_url( '/portal/exams/questions/' )
        );

        return [ 'success' => true, 'message' => $body ];
    }

    /**
     * Tell the teacher the outcome of a review.
     */
    private function notify_teacher( int $school_id, int $subject_id, int $staff_id, string $decision, string $note, int $changed ): void {
        global $wpdb;

        if ( $changed === 0 || $decision === self::PENDING ) {
            return;
        }

        $user_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT wp_user_id FROM ' . Schema::table( 'staff' ) . ' WHERE id = %d AND school_id = %d',
                    $staff_id,
                    $school_id
                )
            )
        );

        if ( $user_id === 0 ) {
            return;
        }

        $subject_name = (string) $wpdb->get_var(
            $wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'subjects_v2' ) . ' WHERE id = %d', $subject_id )
        );

        $approved = $decision === self::APPROVED;

        ( new NotificationService() )->notify(
            $school_id,
            $user_id,
            NotificationService::SCORE_SUBMITTED,
            $approved
                ? sprintf( '%s questions approved', $subject_name )
                : sprintf( '%s questions need revision', $subject_name ),
            $approved
                ? sprintf( '%d question(s) have been approved and can now be used in a paper.', $changed )
                : sprintf( "%d question(s) were sent back.\n\n%s", $changed, $note ),
            home_url( '/portal/exams/questions/' )
        );
    }

    /**
     * How many approved questions a subject has, for the paper composer.
     */
    public function approved_count( int $school_id, int $subject_id ): int {
        global $wpdb;

        return absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $wpdb->prefix . "educbt_questions
                     WHERE school_id = %d AND subject_id = %d AND status = 'active' AND approval_status = %s",
                    $school_id,
                    $subject_id,
                    self::APPROVED
                )
            )
        );
    }
}
