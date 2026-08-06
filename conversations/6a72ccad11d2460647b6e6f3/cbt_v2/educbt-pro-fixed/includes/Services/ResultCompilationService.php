<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 7 — result compilation.
 *
 * Turns raw `assessment_scores` rows into `subject_results` and `term_results`:
 * totals, grades, class statistics and positions.
 *
 * Three things here are easy to get subtly wrong, and all three are the kind of
 * error a school discovers on printed report cards in front of parents:
 *
 *  1. POSITION WITH TIES. Two students on 78 are both 2nd, and the next student is
 *     4th — not 3rd. Getting this wrong understates every position below a tie.
 *
 *  2. THE AVERAGE DIVISOR. A student's average is over the subjects THEY offer, not
 *     a fixed number. A student offering 8 subjects and one offering 9 cannot share
 *     a divisor, or the 9-subject student is silently penalised.
 *
 *  3. WHO IS RANKED. Subject position is computed only among students who actually
 *     offer that subject. Including the whole class would rank a student against
 *     people who never sat the paper.
 */
class ResultCompilationService {

    /**
     * Compile one subject for one class and term.
     *
     * @return array{success:bool,students?:int,error?:string}
     */
    public function compile_subject( int $school_id, int $subject_id, int $class_id, int $session_id, int $term_id ): array {
        global $wpdb;

        $scores     = Schema::table( 'assessment_scores' );
        $components = Schema::table( 'assessment_components' );

        // Sum CA and exam separately: the report card shows them as separate columns,
        // and the exam component is the one the CBT engine writes into.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.student_id,
                        SUM(CASE WHEN c.is_exam = 0 THEN s.score ELSE 0 END) AS ca_total,
                        SUM(CASE WHEN c.is_exam = 1 THEN s.score ELSE 0 END) AS exam_total,
                        SUM(s.score) AS total,
                        COUNT(s.id) AS components_entered
                 FROM {$scores} s
                 INNER JOIN {$components} c ON c.id = s.component_id
                 WHERE s.school_id = %d AND s.subject_id = %d AND s.class_id = %d
                   AND s.session_id = %d AND s.term_id = %d
                 GROUP BY s.student_id",
                $school_id,
                $subject_id,
                $class_id,
                $session_id,
                $term_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ 'success' => false, 'error' => 'no_scores_recorded' ];
        }

        $totals = array_map( static fn( array $r ): float => (float) $r['total'], $rows );

        $stats = [
            'highest' => max( $totals ),
            'lowest'  => min( $totals ),
            'average' => round( array_sum( $totals ) / count( $totals ), 2 ),
        ];

        // Ranked among the students who offer this subject — nobody else.
        $positions = $this->rank( $rows, 'total' );

        $grading = new GradingService();
        $table   = Schema::table( 'subject_results' );

        foreach ( $rows as $row ) {
            $student_id = absint( $row['student_id'] );
            $total      = (float) $row['total'];
            $grade      = $grading->grade_for( $school_id, $total );

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table}
                        (school_id, student_id, subject_id, class_id, session_id, term_id,
                         ca_total, exam_total, total, grade, remark, subject_position,
                         class_average, highest_in_class, lowest_in_class, status, computed_at)
                     VALUES (%d, %d, %d, %d, %d, %d, %f, %f, %f, %s, %s, %d, %f, %f, %f, 'compiled', %s)
                     ON DUPLICATE KEY UPDATE
                        ca_total = VALUES(ca_total), exam_total = VALUES(exam_total),
                        total = VALUES(total), grade = VALUES(grade), remark = VALUES(remark),
                        subject_position = VALUES(subject_position),
                        class_average = VALUES(class_average),
                        highest_in_class = VALUES(highest_in_class),
                        lowest_in_class = VALUES(lowest_in_class),
                        status = 'compiled', computed_at = VALUES(computed_at)",
                    $school_id,
                    $student_id,
                    $subject_id,
                    $class_id,
                    $session_id,
                    $term_id,
                    (float) $row['ca_total'],
                    (float) $row['exam_total'],
                    $total,
                    $grade['grade'],
                    $grade['remark'],
                    $positions[ $student_id ] ?? 0,
                    $stats['average'],
                    $stats['highest'],
                    $stats['lowest'],
                    current_time( 'mysql', true )
                )
            );
        }

        return [ 'success' => true, 'students' => count( $rows ) ];
    }

    /**
     * Competition ranking: 1, 2, 2, 4.
     *
     * Two students tied on 78 are BOTH second, and the next student is fourth. Dense
     * ranking (1, 2, 2, 3) would make that student third and quietly flatter every
     * position below a tie — a difference parents notice and query.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,int> student_id => position
     */
    public function rank( array $rows, string $field ): array {
        usort( $rows, static fn( array $a, array $b ): int => (float) $b[ $field ] <=> (float) $a[ $field ] );

        $positions = [];
        $position  = 0;
        $seen      = 0;
        $previous  = null;

        foreach ( $rows as $row ) {
            $seen++;
            $value = (float) $row[ $field ];

            // A new position number only when the value actually changes; the counter
            // still advances, so the gap after a tie is preserved.
            if ( $previous === null || abs( $value - $previous ) > 0.0001 ) {
                $position = $seen;
                $previous = $value;
            }

            $positions[ absint( $row['student_id'] ) ] = $position;
        }

        return $positions;
    }

    /**
     * Compile the whole class: every subject, then the term summary.
     *
     * @return array{success:bool,subjects:int,students:int,skipped:array<int,string>}
     */
    public function compile_class( int $school_id, int $class_id, int $session_id, int $term_id ): array {
        global $wpdb;

        $scores = Schema::table( 'assessment_scores' );

        $subject_ids = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT subject_id FROM {$scores}
                     WHERE school_id = %d AND class_id = %d AND session_id = %d AND term_id = %d",
                    $school_id,
                    $class_id,
                    $session_id,
                    $term_id
                )
            )
        );

        $compiled = 0;
        $skipped  = [];

        foreach ( $subject_ids as $subject_id ) {
            $result = $this->compile_subject( $school_id, $subject_id, $class_id, $session_id, $term_id );

            if ( ! empty( $result['success'] ) ) {
                $compiled++;
            } else {
                $skipped[] = $subject_id . ':' . ( $result['error'] ?? 'unknown' );
            }
        }

        $summary = $this->compile_term_summary( $school_id, $class_id, $session_id, $term_id );

        EventDispatcher::action( 'educbt_class_compiled', [
            'school_id' => $school_id,
            'class_id'  => $class_id,
            'term_id'   => $term_id,
            'subjects'  => $compiled,
            'students'  => $summary['students'],
        ] );

        return [
            'success'  => true,
            'subjects' => $compiled,
            'students' => $summary['students'],
            'skipped'  => $skipped,
        ];
    }

    /**
     * Per-student term totals, averages and overall class position.
     *
     * @return array{students:int}
     */
    public function compile_term_summary( int $school_id, int $class_id, int $session_id, int $term_id ): array {
        global $wpdb;

        $subject_results = Schema::table( 'subject_results' );

        // The divisor is COUNT(*) per student — the subjects they actually offer.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT student_id,
                        COUNT(*) AS subjects_offered,
                        SUM(total) AS total_score,
                        ROUND(AVG(total), 2) AS average_score
                 FROM {$subject_results}
                 WHERE school_id = %d AND class_id = %d AND session_id = %d AND term_id = %d
                 GROUP BY student_id",
                $school_id,
                $class_id,
                $session_id,
                $term_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ 'students' => 0 ];
        }

        // Overall position is ranked on AVERAGE, not on total. Ranking on total would
        // put a student offering 9 subjects above one offering 8 who scored better in
        // every single subject.
        $positions  = $this->rank( $rows, 'average_score' );
        $class_size = count( $rows );

        $table = Schema::table( 'term_results' );

        foreach ( $rows as $row ) {
            $student_id = absint( $row['student_id'] );

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table}
                        (school_id, student_id, class_id, session_id, term_id,
                         subjects_offered, total_score, average_score, class_position, class_size, status)
                     VALUES (%d, %d, %d, %d, %d, %d, %f, %f, %d, %d, 'compiled')
                     ON DUPLICATE KEY UPDATE
                        subjects_offered = VALUES(subjects_offered),
                        total_score = VALUES(total_score),
                        average_score = VALUES(average_score),
                        class_position = VALUES(class_position),
                        class_size = VALUES(class_size),
                        status = CASE WHEN status IN ('approved','published') THEN status ELSE 'compiled' END",
                    $school_id,
                    $student_id,
                    $class_id,
                    $session_id,
                    $term_id,
                    absint( $row['subjects_offered'] ),
                    (float) $row['total_score'],
                    (float) $row['average_score'],
                    $positions[ $student_id ] ?? 0,
                    $class_size
                )
            );
        }

        return [ 'students' => $class_size ];
    }

    /**
     * Readiness check, run BEFORE compiling.
     *
     * Compiling a class with missing marks produces confident-looking results that
     * are wrong — a student missing a CA is ranked as though they scored zero. This
     * surfaces that first.
     *
     * @return array{ready:bool,issues:array<int,array<string,mixed>>}
     */
    public function readiness( int $school_id, int $class_id, int $session_id, int $term_id ): array {
        global $wpdb;

        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );
        $scores      = Schema::table( 'assessment_scores' );
        $components  = Schema::table( 'assessment_components' );
        $students    = $wpdb->prefix . 'educbt_students';
        $subjects    = Schema::table( 'subjects_v2' );

        $expected_components = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$components} WHERE school_id = %d AND status = 'active'", $school_id )
            )
        );

        // Every (student, subject) pair that should have marks, with how many it has.
        $issues = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id AS student_id, st.admission_number,
                        CONCAT(st.first_name, ' ', st.last_name) AS student_name,
                        sub.name AS subject_name,
                        COUNT(sc.id) AS entered, %d AS expected
                 FROM {$enrollments} e
                 INNER JOIN {$students} st ON st.id = e.student_id
                 INNER JOIN {$registered} rs ON rs.student_id = st.id AND rs.session_id = e.session_id
                 INNER JOIN {$subjects} sub ON sub.id = rs.subject_id
                 LEFT JOIN {$scores} sc
                        ON sc.student_id = st.id AND sc.subject_id = rs.subject_id AND sc.term_id = %d
                 WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
                 GROUP BY st.id, sub.id
                 HAVING entered < expected
                 ORDER BY st.last_name ASC, sub.name ASC",
                $expected_components,
                $term_id,
                $school_id,
                $class_id,
                $session_id
            ),
            ARRAY_A
        );

        return [ 'ready' => empty( $issues ), 'issues' => $issues ];
    }

    /**
     * One student's full report card.
     *
     * @return array<string,mixed>
     */
    public function report_card( int $school_id, int $student_id, int $term_id ): array {
        global $wpdb;

        $subject_results = Schema::table( 'subject_results' );
        $term_results    = Schema::table( 'term_results' );
        $subjects        = Schema::table( 'subjects_v2' );

        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$term_results} WHERE school_id = %d AND student_id = %d AND term_id = %d",
                $school_id,
                $student_id,
                $term_id
            ),
            ARRAY_A
        );

        if ( ! $summary ) {
            return [ 'found' => false ];
        }

        $lines = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sr.*, s.name AS subject_name, s.code AS subject_code
                 FROM {$subject_results} sr
                 INNER JOIN {$subjects} s ON s.id = sr.subject_id
                 WHERE sr.school_id = %d AND sr.student_id = %d AND sr.term_id = %d
                 ORDER BY s.name ASC",
                $school_id,
                $student_id,
                $term_id
            ),
            ARRAY_A
        );

        return [
            'found'    => true,
            'summary'  => $summary,
            'subjects' => $lines,
            'status'   => (string) $summary['status'],
        ];
    }
}
