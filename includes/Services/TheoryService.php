<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Theory (essay) questions, and the marking of them.
 *
 * WHY THIS EXISTS.
 *
 * A WAEC paper is not one shape. English Language alone is three papers: objective
 * lexis and structure, then essay plus comprehension plus summary, then orals. Most
 * other subjects have an objective section and a theory section in the same sitting.
 * A platform that can only express multiple choice cannot represent the examination
 * a Nigerian school actually sits, so a school would keep marking on paper and the
 * CBT would only ever be half the job.
 *
 * Two consequences follow, and both are handled here rather than pretended away:
 *
 *  1. A THEORY QUESTION CANNOT BE AUTO-MARKED. It is stored with no correct option,
 *     graded by a human, and until that happens the paper is only part marked.
 *  2. A PAPER WITH THEORY IS NOT FINISHED WHEN THE STUDENT SUBMITS. Results must not
 *     compile as though the theory scored zero — that would fail a whole class
 *     silently, which is exactly the sort of quiet wrongness that reaches a parent.
 */
class TheoryService {

    /**
     * Save a theory answer.
     *
     * Deliberately separate from the objective path: text answers are large, arrive
     * on a timer, and must never touch the correctness columns.
     *
     * @return array{success:bool,reason?:string}
     */
    public function save_text_answer( int $school_id, int $attempt_id, int $question_id, string $text ): array {
        global $wpdb;

        $table = Schema::table( 'attempt_answers' );

        // A generous ceiling rather than a tight one: an essay answer runs long, and
        // truncating a student's work mid-sentence is unforgivable. The cap exists
        // only to stop a runaway paste filling the table.
        $text = mb_substr( wp_kses_post( $text ), 0, 20000 );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (school_id, attempt_id, question_id, option_id, answer_text, is_correct, marks_awarded)
                 VALUES (%d, %d, %d, NULL, %s, NULL, 0)
                 ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text)",
                $school_id,
                $attempt_id,
                $question_id,
                $text
            )
        );

        return [ 'success' => true ];
    }

    /**
     * Theory answers for one paper, ready to mark.
     *
     * Grouped by QUESTION rather than by student on purpose: marking every answer to
     * question 1 before moving to question 2 is how examiners actually work, and it
     * keeps the standard consistent across a class.
     *
     * @return array<int,array<string,mixed>>
     */
    public function marking_queue( int $school_id, int $paper_id ): array {
        global $wpdb;

        $answers   = Schema::table( 'attempt_answers' );
        $attempts  = Schema::table( 'attempts' );
        $questions = $wpdb->prefix . 'educbt_questions';
        $students  = $wpdb->prefix . 'educbt_students';
        $paper_q   = Schema::table( 'paper_questions' );

        // The JOIN on paper_questions validates that the question belongs to the
        // paper. Sub-questions (children) are not directly in paper_questions —
        // their parent is — so we LEFT JOIN through parent_id to find them.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id AS answer_id, a.question_id, a.answer_text, a.marks_awarded, a.is_correct,
                        q.question_text, q.explanations AS marking_guide,
                        q.part_label, q.parent_id,
                        COALESCE(pq.marks, q.marks) AS max_marks,
                        st.id AS student_id, st.admission_number,
                        CONCAT(st.first_name, ' ', st.last_name) AS student_name
                 FROM {$answers} a
                 INNER JOIN {$attempts} at ON at.id = a.attempt_id
                 INNER JOIN {$questions} q ON q.id = a.question_id
                 LEFT JOIN {$paper_q} pq ON pq.paper_id = at.paper_id AND pq.question_id = a.question_id
                 LEFT JOIN {$paper_q} pq_parent ON pq_parent.paper_id = at.paper_id AND pq_parent.question_id = q.parent_id
                 INNER JOIN {$students} st ON st.id = at.student_id
                 WHERE a.school_id = %d AND at.paper_id = %d AND q.question_type = %s
                   AND (pq.id IS NOT NULL OR pq_parent.id IS NOT NULL)
                 ORDER BY q.parent_id ASC, a.question_id ASC, st.last_name ASC",
                $school_id,
                $paper_id,
                QuestionBankService::TYPE_THEORY
            ),
            ARRAY_A
        );

        $grouped = [];

        foreach ( $rows as $row ) {
            $qid = absint( $row['question_id'] );

            if ( ! isset( $grouped[ $qid ] ) ) {
                $grouped[ $qid ] = [
                    'question_id'    => $qid,
                    'question_text'  => (string) $row['question_text'],
                    'marking_guide'  => (string) $row['marking_guide'],
                    'max_marks'      => (float) ( $row['max_marks'] ?? 0 ),
                    'part_label'     => (string) ( $row['part_label'] ?? '' ),
                    'parent_id'      => absint( $row['parent_id'] ?? 0 ),
                    'answers'        => [],
                    'marked'         => 0,
                    'total'          => 0,
                ];
            }

            $marked = $row['is_correct'] !== null;

            $grouped[ $qid ]['answers'][] = [
                'answer_id'        => absint( $row['answer_id'] ),
                'student_id'       => absint( $row['student_id'] ),
                'student_name'     => (string) $row['student_name'],
                'admission_number' => (string) $row['admission_number'],
                'answer_text'      => (string) $row['answer_text'],
                'marks_awarded'    => (float) $row['marks_awarded'],
                'marked'           => $marked,
            ];

            $grouped[ $qid ]['total']++;

            if ( $marked ) {
                $grouped[ $qid ]['marked']++;
            }
        }

        return array_values( $grouped );
    }

    /**
     * Theory answers for one paper, grouped by student.
     *
     * The counterpart to marking_queue(): instead of "all answers to Q1A, then all
     * to Q1B", this returns one row per student, each containing every theory
     * question that student answered, ordered by part/sub-question. A teacher
     * marks one student's entire paper before moving to the next — which is how
     * WAEC and NECO examiners actually work, and keeps the standard consistent
     * for each candidate.
     *
     * @return array<int,array<string,mixed>>
     */
    public function marking_queue_by_student( int $school_id, int $paper_id ): array {
        global $wpdb;

        $answers   = Schema::table( 'attempt_answers' );
        $attempts  = Schema::table( 'attempts' );
        $questions = $wpdb->prefix . 'educbt_questions';
        $students  = $wpdb->prefix . 'educbt_students';
        $paper_q   = Schema::table( 'paper_questions' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id AS answer_id, a.question_id, a.answer_text, a.marks_awarded, a.is_correct,
                        q.question_text, q.explanations AS marking_guide,
                        q.part_label, q.parent_id,
                        COALESCE(pq.marks, q.marks) AS max_marks,
                        st.id AS student_id, st.admission_number,
                        CONCAT(st.first_name, ' ', st.last_name) AS student_name
                 FROM {$answers} a
                 INNER JOIN {$attempts} at ON at.id = a.attempt_id
                 INNER JOIN {$questions} q ON q.id = a.question_id
                 LEFT JOIN {$paper_q} pq ON pq.paper_id = at.paper_id AND pq.question_id = a.question_id
                 LEFT JOIN {$paper_q} pq_parent ON pq_parent.paper_id = at.paper_id AND pq_parent.question_id = q.parent_id
                 INNER JOIN {$students} st ON st.id = at.student_id
                 WHERE a.school_id = %d AND at.paper_id = %d AND q.question_type = %s
                   AND (pq.id IS NOT NULL OR pq_parent.id IS NOT NULL)
                 ORDER BY st.last_name ASC, st.first_name ASC, q.parent_id ASC, a.question_id ASC",
                $school_id,
                $paper_id,
                QuestionBankService::TYPE_THEORY
            ),
            ARRAY_A
        );

        $grouped = [];

        foreach ( $rows as $row ) {
            $sid = absint( $row['student_id'] );

            if ( ! isset( $grouped[ $sid ] ) ) {
                $grouped[ $sid ] = [
                    'student_id'       => $sid,
                    'student_name'     => (string) $row['student_name'],
                    'admission_number' => (string) $row['admission_number'],
                    'answers'          => [],
                    'marked'           => 0,
                    'total'            => 0,
                    'total_awarded'    => 0.0,
                    'total_max'        => 0.0,
                ];
            }

            $marked = $row['is_correct'] !== null;
            $max    = (float) ( $row['max_marks'] ?? 0 );

            $grouped[ $sid ]['answers'][] = [
                'answer_id'      => absint( $row['answer_id'] ),
                'question_id'    => absint( $row['question_id'] ),
                'question_text'  => (string) $row['question_text'],
                'marking_guide'  => (string) $row['marking_guide'],
                'part_label'     => (string) ( $row['part_label'] ?? '' ),
                'parent_id'      => absint( $row['parent_id'] ?? 0 ),
                'max_marks'      => $max,
                'answer_text'    => (string) $row['answer_text'],
                'marks_awarded'  => (float) $row['marks_awarded'],
                'marked'         => $marked,
            ];

            $grouped[ $sid ]['total']++;
            $grouped[ $sid ]['total_max'] += $max;

            if ( $marked ) {
                $grouped[ $sid ]['marked']++;
                $grouped[ $sid ]['total_awarded'] += (float) $row['marks_awarded'];
            }
        }

        return array_values( $grouped );
    }

    /**
     * Record marks for theory answers.
     *
     * `is_correct` is set to 1 or 0 purely to record THAT it was marked — a written
     * answer is rarely wholly right or wrong, and the mark itself is what counts.
     *
     * @param array<int,float> $marks answer_id => marks
     * @return array{marked:int,skipped:int,errors:array<int,string>}
     */
    public function record_marks( int $school_id, array $marks, float $max_marks, int $marker_id ): array {
        global $wpdb;

        $table  = Schema::table( 'attempt_answers' );
        $marked = 0;
        $skipped = 0;
        $errors = [];

        // Marking updates the answer row only. The attempt's total score is a
        // separate column (raw_score/max_score/percentage) that was last written
        // by auto-grading BEFORE any theory mark existed, so every affected
        // attempt must be re-totalled once marking is done — otherwise a
        // teacher's marks sit in attempt_answers forever and never reach the
        // student's result.
        $affected_attempts = [];

        foreach ( $marks as $answer_id => $value ) {
            $answer_id = absint( $answer_id );

            // Blank means "not marked yet", which is different from zero. Skipping
            // keeps a half-finished marking session honest.
            if ( $value === '' || $value === null ) {
                $skipped++;
                continue;
            }

            $score = (float) $value;

            // Look up this answer's per-question max marks from the database.
            // A single student form contains questions with different max marks
            // (Q1A might be 5, Q1B might be 10), so the old single max_marks
            // parameter is only a fallback when the DB lookup fails.
            $paper_q = Schema::table( 'paper_questions' );
            $questions_t = $wpdb->prefix . 'educbt_questions';

            $row_info = (array) $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT a.attempt_id, a.question_id,
                            COALESCE(pq.marks, q.marks, pq_parent.marks, %f) AS per_max
                     FROM {$table} a
                     INNER JOIN {$questions_t} q ON q.id = a.question_id
                     LEFT JOIN {$paper_q} pq ON pq.question_id = a.question_id
                     LEFT JOIN {$paper_q} pq_parent
                            ON pq_parent.question_id = (
                                SELECT parent_id FROM {$questions_t} WHERE id = a.question_id
                            )
                     WHERE a.id = %d AND a.school_id = %d",
                    $max_marks,
                    $answer_id,
                    $school_id
                ),
                ARRAY_A
            );

            $per_max   = (float) ( $row_info['per_max'] ?? $max_marks );
            $attempt_id = absint( $row_info['attempt_id'] ?? 0 );

            if ( $per_max > 0 && ( $score < 0 || $score > $per_max ) ) {
                $errors[] = sprintf( 'Answer %d: %s is outside 0–%s', $answer_id, $score, $per_max );
                $skipped++;
                continue;
            }

            $wpdb->update(
                $table,
                [
                    'marks_awarded' => $score,
                    'is_correct'    => $score > 0 ? 1 : 0,
                ],
                [ 'id' => $answer_id, 'school_id' => $school_id ],
                [ '%f', '%d' ],
                [ '%d', '%d' ]
            );

            $marked++;

            if ( $attempt_id > 0 ) {
                $affected_attempts[ $attempt_id ] = true;
            }
        }

        $regraded = 0;

        foreach ( array_keys( $affected_attempts ) as $attempt_id ) {
            if ( $this->retotal_attempt( $school_id, $attempt_id ) ) {
                $regraded++;
            }
        }

        EventDispatcher::action( 'educbt_theory_marked', [
            'school_id' => $school_id,
            'marked'    => $marked,
            'marker_id' => $marker_id,
            'regraded'  => $regraded,
        ] );

        return [ 'marked' => $marked, 'skipped' => $skipped, 'errors' => $errors ];
    }

    /**
     * Re-total an attempt after theory marks have been recorded, and push the
     * new score into the assessment component it feeds.
     *
     * Mirrors GradingWorker's totalling logic exactly (SUM of marks_awarded over
     * ALL answers — objective and theory together — against SUM of marks in
     * paper_questions), because a paper's score must always be one consistent
     * number regardless of when the theory part happened to get marked.
     */
    private function retotal_attempt( int $school_id, int $attempt_id ): bool {
        global $wpdb;

        $attempts        = Schema::table( 'attempts' );
        $answers         = Schema::table( 'attempt_answers' );
        $paper_questions = Schema::table( 'paper_questions' );

        $attempt = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$attempts} WHERE id = %d AND school_id = %d", $attempt_id, $school_id ),
            ARRAY_A
        );

        if ( ! $attempt ) {
            return false;
        }

        $raw = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(marks_awarded), 0) FROM {$answers} WHERE attempt_id = %d AND school_id = %d",
                $attempt_id,
                $school_id
            )
        );

        $questions_t = $wpdb->prefix . 'educbt_questions';
        $max = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(
                    CASE
                        WHEN children.total > 0 THEN children.total
                        ELSE pq.marks
                    END
                ), 0)
                 FROM {$paper_questions} pq
                 LEFT JOIN (
                     SELECT parent_id, SUM(marks) AS total
                     FROM {$questions_t}
                     WHERE parent_id IS NOT NULL AND parent_id > 0
                     GROUP BY parent_id
                 ) children ON children.parent_id = pq.question_id
                 WHERE pq.paper_id = %d",
                absint( $attempt['paper_id'] )
            )
        );

        if ( $max <= 0 ) {
            $max = (float) $attempt['max_score'];
        }

        $percentage = $max > 0 ? round( ( $raw / $max ) * 100, 2 ) : 0.0;

        $wpdb->update(
            $attempts,
            [
                'raw_score'  => $raw,
                'max_score'  => $max,
                'percentage' => $percentage,
            ],
            [ 'id' => $attempt_id ],
            [ '%f', '%f', '%f' ],
            [ '%d' ]
        );

        // Push the corrected score into the assessment component (CA or exam)
        // this attempt feeds, scaled to that component's max — the same step
        // GradingWorker takes right after auto-grading.
        ( new AssessmentService() )->record_attempt_score( $school_id, $attempt_id );

        EventDispatcher::action( 'educbt_attempt_regraded', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'raw_score'  => $raw,
            'max_score'  => $max,
            'percentage' => $percentage,
        ] );

        return true;
    }

    /**
     * How much of a paper's theory is still unmarked.
     *
     * Checked before results are compiled: compiling with theory outstanding scores
     * those answers as zero and fails a class silently.
     *
     * @return array{total:int,marked:int,outstanding:int}
     */
    public function marking_progress( int $school_id, int $paper_id ): array {
        global $wpdb;

        $answers   = Schema::table( 'attempt_answers' );
        $attempts  = Schema::table( 'attempts' );
        $questions = $wpdb->prefix . 'educbt_questions';

        $row = (array) $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, SUM(CASE WHEN a.is_correct IS NOT NULL THEN 1 ELSE 0 END) AS marked
                 FROM {$answers} a
                 INNER JOIN {$attempts} at ON at.id = a.attempt_id
                 INNER JOIN {$questions} q ON q.id = a.question_id
                 WHERE a.school_id = %d AND at.paper_id = %d AND q.question_type = %s",
                $school_id,
                $paper_id,
                QuestionBankService::TYPE_THEORY
            ),
            ARRAY_A
        );

        $total  = absint( $row['total'] ?? 0 );
        $marked = absint( $row['marked'] ?? 0 );

        return [ 'total' => $total, 'marked' => $marked, 'outstanding' => max( 0, $total - $marked ) ];
    }

    /**
     * Papers with theory still to mark, for the teacher's dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public function papers_awaiting_marking( int $school_id, int $staff_id = 0 ): array {
        global $wpdb;

        $answers   = Schema::table( 'attempt_answers' );
        $attempts  = Schema::table( 'attempts' );
        $questions = $wpdb->prefix . 'educbt_questions';
        $papers    = Schema::table( 'exam_papers' );
        $subjects  = Schema::table( 'subjects_v2' );
        $classes   = Schema::table( 'classes' );
        $assign    = Schema::table( 'staff_assignments' );

        $where  = 'a.school_id = %d AND q.question_type = %s AND a.is_correct IS NULL';
        $params = [ $school_id, QuestionBankService::TYPE_THEORY ];

        if ( $staff_id > 0 ) {
            $where   .= " AND EXISTS (SELECT 1 FROM {$assign} sa WHERE sa.staff_id = %d
                          AND sa.subject_id = p.subject_id AND sa.class_id = p.class_id AND sa.status = 'active')";
            $params[] = $staff_id;
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, s.name AS subject_name, c.display_name AS class_name,
                        COUNT(*) AS outstanding
                 FROM {$answers} a
                 INNER JOIN {$attempts} at ON at.id = a.attempt_id
                 INNER JOIN {$papers} p ON p.id = at.paper_id
                 INNER JOIN {$questions} q ON q.id = a.question_id
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 LEFT JOIN {$classes} c ON c.id = p.class_id
                 WHERE {$where}
                 GROUP BY p.id
                 ORDER BY p.scheduled_at DESC",
                $params
            ),
            ARRAY_A
        );
    }
}
