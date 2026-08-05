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
 * one subject — because that is the unit a reviewer actually works through.
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
     * Every teacher's submission, per subject, with progress against the quota.
     *
     * This is the reviewer's whole screen: who has submitted what, who is short, and
     * what is waiting to be looked at.
     *
     * @return array<int,array<string,mixed>>
     */
    public function submissions( int $school_id, int $staff_id = 0 ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        $subjects  = Schema::table( 'subjects_v2' );
        $staff     = Schema::table( 'staff' );
        $assign    = Schema::table( 'staff_assignments' );

        $where  = 'q.school_id = %d AND q.status = %s';
        $params = [ $school_id, 'active' ];

        if ( $staff_id > 0 ) {
            $where   .= ' AND q.created_by_staff = %d';
            $params[] = $staff_id;
        }

        // The subject and staff joins are LEFT, deliberately.
        //
        // An INNER JOIN on subjects hid every question whose subject row had been
        // renamed away or whose subject_id predates the column, so a bank with real
        // work in it reported that nothing had been submitted — and the reviewer had
        // no way to discover the questions existed at all.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.subject_id, q.created_by_staff,
                        s.name AS subject_name,
                        CONCAT(st.first_name, ' ', st.last_name) AS teacher_name,
                        SUM(CASE WHEN q.question_type <> 'theory' THEN 1 ELSE 0 END) AS objective_total,
                        SUM(CASE WHEN q.question_type = 'theory' THEN 1 ELSE 0 END) AS theory_total,
                        SUM(CASE WHEN q.approval_status = 'approved' THEN 1 ELSE 0 END) AS approved,
                        SUM(CASE WHEN q.approval_status = 'revision' THEN 1 ELSE 0 END) AS revision,
                        SUM(CASE WHEN q.approval_status = 'pending' OR q.approval_status IS NULL THEN 1 ELSE 0 END) AS pending,
                        MAX(q.created_at) AS last_submitted
                 FROM {$questions} q
                 LEFT JOIN {$subjects} s ON s.id = q.subject_id
                 LEFT JOIN {$staff} st ON st.id = q.created_by_staff
                 WHERE {$where}
                 GROUP BY q.subject_id, q.created_by_staff
                 ORDER BY st.last_name ASC, s.name ASC",
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

            $out[] = [
                'subject_id'      => absint( $row['subject_id'] ),
                // Named honestly when the link is broken, rather than blank.
                'subject_name'    => trim( (string) $row['subject_name'] ) !== ''
                    ? (string) $row['subject_name']
                    : 'Unassigned subject',
                'staff_id'        => absint( $row['created_by_staff'] ),
                'teacher_name'    => trim( (string) $row['teacher_name'] ) ?: 'Not attributed',
                'objective'       => $objective,
                'theory'          => $theory,
                'approved'        => absint( $row['approved'] ),
                'pending'         => absint( $row['pending'] ),
                'revision'        => absint( $row['revision'] ),
                'short_objective' => $short_objective,
                'short_theory'    => $short_theory,
                'complete'        => $short_objective === 0 && $short_theory === 0,
                'last_submitted'  => (string) $row['last_submitted'],
            ];
        }

        return $out;
    }

    /**
     * The questions in one submission, for review.
     *
     * @return array<int,array<string,mixed>>
     */
    public function review_queue( int $school_id, int $subject_id, int $staff_id ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, question_text, question_type, marks, approval_status, review_note, class_level
                 FROM {$questions}
                 WHERE school_id = %d AND subject_id = %d AND created_by_staff = %d AND status = 'active'
                 ORDER BY question_type ASC, id ASC",
                $school_id,
                $subject_id,
                $staff_id
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
    public function decide( int $school_id, int $subject_id, int $staff_id, string $decision, string $note, int $reviewer_id, array $question_ids = [] ): array {
        global $wpdb;

        if ( ! in_array( $decision, [ self::APPROVED, self::REVISION, self::PENDING ], true ) ) {
            return [ 'success' => false, 'changed' => 0 ];
        }

        // Sending work back without saying why is not a review, it is an obstacle.
        if ( $decision === self::REVISION && trim( $note ) === '' ) {
            return [ 'success' => false, 'changed' => 0, 'error' => 'note_required' ];
        }

        $questions = $wpdb->prefix . 'educbt_questions';

        $sql    = "UPDATE {$questions} SET approval_status = %s, review_note = %s, reviewed_by = %d, reviewed_at = %s
                   WHERE school_id = %d AND subject_id = %d AND created_by_staff = %d AND status = 'active'";
        $params = [ $decision, sanitize_textarea_field( $note ), $reviewer_id, current_time( 'mysql', true ), $school_id, $subject_id, $staff_id ];

        if ( ! empty( $question_ids ) ) {
            $ids          = array_map( 'absint', $question_ids );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $sql         .= " AND id IN ({$placeholders})";
            $params       = array_merge( $params, $ids );
        }

        $changed = absint( $wpdb->query( $wpdb->prepare( $sql, $params ) ) );

        $this->notify_teacher( $school_id, $subject_id, $staff_id, $decision, $note, $changed );

        EventDispatcher::action( 'educbt_questions_reviewed', [
            'school_id'  => $school_id,
            'subject_id' => $subject_id,
            'staff_id'   => $staff_id,
            'decision'   => $decision,
            'changed'    => $changed,
            'reviewer'   => $reviewer_id,
        ] );

        return [ 'success' => true, 'changed' => $changed ];
    }

    /**
     * Nudge a teacher whose submission is short of the quota.
     *
     * @return array{success:bool,message:string}
     */
    public function remind( int $school_id, int $subject_id, int $staff_id ): array {
        global $wpdb;

        $summary = null;

        foreach ( $this->submissions( $school_id, $staff_id ) as $row ) {
            if ( $row['subject_id'] === $subject_id ) {
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
