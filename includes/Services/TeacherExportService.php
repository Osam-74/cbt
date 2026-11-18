<?php
/**
 * What a teacher may take away as a document: their questions, how their students did, and the
 * recorded CA and exam scores.
 *
 * Everything here is scoped by the teacher's own assignments. A teacher can only export a subject in a
 * class they hold, so two teachers who share a subject (or who take it in different arms) each get their
 * own correct document, and nobody can read another subject's questions or another class's marks by
 * changing a number in the address bar.
 *
 * Kept out of the page templates so the same data can feed the web page, the printable document and,
 * later, the SaaS, and so it can be tested without a browser.
 *
 * @package EduCBTPro
 */

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeacherExportService {

    /**
     * The (class, subject) pairs a teacher holds this session, one row each.
     *
     * @return array<int,array{class_id:int,class_name:string,subject_id:int,subject_name:string}>
     */
    public function pairs( int $school_id, int $staff_id, bool $school_wide = false ): array {
        global $wpdb;

        $assign   = Schema::table( 'staff_assignments' );
        $classes  = Schema::table( 'classes' );
        $subjects = Schema::table( 'subjects_v2' );

        if ( $school_wide ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT c.id AS class_id, c.display_name AS class_name, s.id AS subject_id, s.name AS subject_name
                     FROM {$classes} c CROSS JOIN {$subjects} s
                     WHERE c.school_id = %d AND c.status = 'active' AND s.school_id = %d AND s.status = 'active'
                     ORDER BY s.name ASC, c.display_name ASC",
                    $school_id,
                    $school_id
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT c.id AS class_id, c.display_name AS class_name, s.id AS subject_id, s.name AS subject_name
                     FROM {$assign} a
                     INNER JOIN {$classes} c ON c.id = a.class_id
                     INNER JOIN {$subjects} s ON s.id = a.subject_id
                     WHERE a.school_id = %d AND a.staff_id = %d AND a.assignment_type = 'subject_teacher' AND a.status = 'active'
                     ORDER BY s.name ASC, c.display_name ASC",
                    $school_id,
                    $staff_id
                ),
                ARRAY_A
            );
        }

        return array_map(
            static fn ( array $r ): array => [
                'class_id'     => (int) $r['class_id'],
                'class_name'   => (string) $r['class_name'],
                'subject_id'   => (int) $r['subject_id'],
                'subject_name' => (string) $r['subject_name'],
            ],
            (array) $rows
        );
    }

    /**
     * Whether this teacher holds the subject (in any class, or in the given one).
     */
    public function may_export( int $school_id, int $staff_id, int $subject_id, int $class_id = 0, bool $school_wide = false ): bool {
        if ( $subject_id <= 0 ) {
            return false;
        }

        foreach ( $this->pairs( $school_id, $staff_id, $school_wide ) as $pair ) {
            if ( $pair['subject_id'] === $subject_id && ( $class_id <= 0 || $pair['class_id'] === $class_id ) ) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------
    // 1. Questions
    // ---------------------------------------------------------------

    /**
     * The teacher's own questions in one subject, with options, correct answers and theory parts.
     *
     * "Own" follows the same rule the approval screen uses: written by this teacher, or belonging to one of
     * this teacher's question sets (older questions were saved without an author). A school-wide role such
     * as the exam officer sees every question in the subject.
     *
     * @return array<int,array<string,mixed>>
     */
    public function questions( int $school_id, int $staff_id, int $subject_id, bool $school_wide = false ): array {
        global $wpdb;

        if ( ! $this->may_export( $school_id, $staff_id, $subject_id, 0, $school_wide ) ) {
            return [];
        }

        $questions = $wpdb->prefix . 'educbt_questions';
        $sets      = Schema::table( 'question_sets' );
        $options   = Schema::table( 'question_options' );
        $subitems  = Schema::table( 'question_sub_items' );

        $subject_name = (string) $wpdb->get_var(
            $wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'subjects_v2' ) . ' WHERE id = %d AND school_id = %d', $subject_id, $school_id )
        );

        // Match the subject by id, or by name for questions saved before ids were recorded.
        $where  = 'q.school_id = %d AND (q.subject_id = %d OR (q.subject_id IS NULL OR q.subject_id = 0) AND q.subject = %s)
                   AND (q.parent_id IS NULL OR q.parent_id = 0)';
        $params = [ $school_id, $subject_id, $subject_name ];

        if ( ! $school_wide ) {
            $where   .= " AND (q.created_by_staff = %d OR q.question_set_id IN (SELECT id FROM {$sets} WHERE teacher_id = %d AND school_id = %d))";
            $params[] = $staff_id;
            $params[] = $staff_id;
            $params[] = $school_id;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.question_text, q.question_type, q.marks, q.difficulty, q.topic, q.class_level, q.status,
                        q.approval_status, q.options AS options_json, q.answers AS answers_json, q.marking_guide, q.section_label
                 FROM {$questions} q
                 WHERE {$where} AND q.status NOT IN ('deleted','archived')
                 ORDER BY q.question_type ASC, q.id ASC",
                $params
            ),
            ARRAY_A
        );

        foreach ( $rows as $i => $row ) {
            $qid  = (int) $row['id'];
            $opts = (array) $wpdb->get_results(
                $wpdb->prepare( "SELECT option_key, option_text, is_correct FROM {$options} WHERE question_id = %d ORDER BY sort_order ASC", $qid ),
                ARRAY_A
            );

            // Older questions keep their options as JSON on the row rather than in the options table.
            if ( empty( $opts ) && (string) $row['options_json'] !== '' ) {
                $list    = json_decode( (string) $row['options_json'], true );
                $correct = json_decode( (string) $row['answers_json'], true );
                $correct = is_array( $correct ) ? $correct : [];
                $keys    = range( 'A', 'Z' );

                foreach ( is_array( $list ) ? array_values( $list ) : [] as $n => $text ) {
                    $opts[] = [
                        'option_key'  => $keys[ $n ] ?? (string) ( $n + 1 ),
                        'option_text' => (string) $text,
                        'is_correct'  => in_array( $text, $correct, true ) ? 1 : 0,
                    ];
                }
            }

            $rows[ $i ]['options']   = $opts;
            $rows[ $i ]['sub_items'] = (string) $row['question_type'] === 'theory'
                ? (array) $wpdb->get_results(
                    $wpdb->prepare( "SELECT label, text, marks FROM {$subitems} WHERE question_id = %d ORDER BY sequence ASC", $qid ),
                    ARRAY_A
                )
                : [];
            unset( $rows[ $i ]['options_json'], $rows[ $i ]['answers_json'] );
        }

        return $rows;
    }

    // ---------------------------------------------------------------
    // 2. Student performance, and 3. recorded CA / exam scores
    // ---------------------------------------------------------------

    /**
     * Every score recorded for one subject in one class this term: one row per student, one column per
     * assessment component (CA 1, CA 2, Exam ...), a total and a grade.
     *
     * @return array{components:array<int,array<string,mixed>>,rows:array<int,array<string,mixed>>,summary:array<string,float|int>}
     */
    public function recorded_scores( int $school_id, int $staff_id, int $subject_id, int $class_id, int $session_id, int $term_id, bool $school_wide = false ): array {
        global $wpdb;

        $empty = [ 'components' => [], 'rows' => [], 'summary' => $this->summarise( [] ) ];

        if ( $session_id <= 0 || $term_id <= 0 || ! $this->may_export( $school_id, $staff_id, $subject_id, $class_id, $school_wide ) ) {
            return $empty;
        }

        $components = ( new AssessmentService() )->components( $school_id );

        if ( empty( $components ) ) {
            return $empty;
        }

        $students = $wpdb->prefix . 'educbt_students';
        $enrol    = Schema::table( 'enrollments' );
        $scores   = Schema::table( 'assessment_scores' );

        // Everyone enrolled in the class, so a student with no score yet still appears (blank, not missing).
        $people = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id, st.admission_number, st.first_name, st.last_name
                 FROM {$enrol} e INNER JOIN {$students} st ON st.id = e.student_id
                 WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active'
                 ORDER BY st.last_name ASC, st.first_name ASC",
                $school_id,
                $class_id
            ),
            ARRAY_A
        );

        $recorded = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT student_id, component_id, score, max_score FROM {$scores}
                 WHERE school_id = %d AND subject_id = %d AND class_id = %d AND session_id = %d AND term_id = %d",
                $school_id,
                $subject_id,
                $class_id,
                $session_id,
                $term_id
            ),
            ARRAY_A
        );

        $by_student = [];

        foreach ( $recorded as $r ) {
            $by_student[ (int) $r['student_id'] ][ (int) $r['component_id'] ] = (float) $r['score'];
        }

        $out = [];

        foreach ( $people as $p ) {
            $sid   = (int) $p['id'];
            $cells = [];
            $total = 0.0;
            $any   = false;

            foreach ( $components as $c ) {
                $cid = (int) $c['id'];

                if ( isset( $by_student[ $sid ][ $cid ] ) ) {
                    $cells[ $cid ] = $by_student[ $sid ][ $cid ];
                    $total        += $by_student[ $sid ][ $cid ];
                    $any           = true;
                } else {
                    $cells[ $cid ] = null;
                }
            }

            $out[] = [
                'student_id' => $sid,
                'admission'  => (string) $p['admission_number'],
                'name'       => trim( (string) $p['last_name'] . ' ' . (string) $p['first_name'] ),
                'cells'      => $cells,
                'total'      => $any ? round( $total, 2 ) : null,
            ];
        }

        $max_total = array_sum( array_map( static fn ( array $c ): float => (float) $c['max_score'], $components ) );

        // Position within the class, by total, with ties sharing a position.
        $totals = array_values( array_filter( array_column( $out, 'total' ), static fn ( $t ): bool => $t !== null ) );
        rsort( $totals );

        foreach ( $out as $i => $row ) {
            $out[ $i ]['position'] = $row['total'] === null ? null : 1 + array_search( $row['total'], $totals, true );
            $out[ $i ]['percent']  = ( $row['total'] !== null && $max_total > 0 ) ? round( $row['total'] / $max_total * 100, 1 ) : null;
        }

        return [
            'components' => array_map(
                static fn ( array $c ): array => [ 'id' => (int) $c['id'], 'name' => (string) $c['name'], 'max' => (float) $c['max_score'] ],
                $components
            ),
            'rows'       => $out,
            'summary'    => $this->summarise( array_filter( array_column( $out, 'percent' ), static fn ( $v ): bool => $v !== null ), $max_total ),
        ];
    }

    /**
     * How a subject's students did overall: average, highest, lowest, pass rate and the grade spread.
     *
     * @param array<int,float|int> $percents
     * @return array<string,float|int>
     */
    public function summarise( array $percents, float $max_total = 100.0, float $pass_mark = 40.0 ): array {
        $percents = array_values( array_map( 'floatval', $percents ) );
        $n        = count( $percents );

        if ( $n === 0 ) {
            return [ 'count' => 0, 'average' => 0.0, 'highest' => 0.0, 'lowest' => 0.0, 'passed' => 0, 'failed' => 0, 'pass_rate' => 0.0, 'max_total' => $max_total ];
        }

        $passed = count( array_filter( $percents, static fn ( float $p ): bool => $p >= $pass_mark ) );

        return [
            'count'     => $n,
            'average'   => round( array_sum( $percents ) / $n, 1 ),
            'highest'   => round( max( $percents ), 1 ),
            'lowest'    => round( min( $percents ), 1 ),
            'passed'    => $passed,
            'failed'    => $n - $passed,
            'pass_rate' => round( $passed / $n * 100, 1 ),
            'max_total' => $max_total,
        ];
    }

    // ---------------------------------------------------------------
    // 4. Admin: who teaches what, and who is class teacher
    // ---------------------------------------------------------------

    /**
     * One row per active duty, for the office to print or open in a spreadsheet.
     *
     * Subject teachers: one row per teacher, class and subject, with the other teachers sharing that
     * subject in that class. Class teachers: one row per class. Sorted by class then subject so the sheet
     * reads like the timetable.
     *
     * @return array{subject:array<int,array<string,string>>,class:array<int,array<string,string>>}
     */
    public function assignment_rows( int $school_id ): array {
        global $wpdb;

        $assign   = Schema::table( 'staff_assignments' );
        $classes  = Schema::table( 'classes' );
        $subjects = Schema::table( 'subjects_v2' );
        $staff    = $wpdb->prefix . 'educbt_staff';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.assignment_type, a.class_id, a.subject_id, c.display_name AS class_name, s.name AS subject_name,
                        st.id AS staff_id, st.staff_number, st.title, st.first_name, st.last_name, st.email, st.phone
                 FROM {$assign} a
                 INNER JOIN {$staff} st ON st.id = a.staff_id AND st.school_id = a.school_id
                 LEFT JOIN {$classes} c ON c.id = a.class_id
                 LEFT JOIN {$subjects} s ON s.id = a.subject_id
                 WHERE a.school_id = %d AND a.status = 'active' AND a.assignment_type IN ('subject_teacher','class_teacher')
                 ORDER BY c.display_name ASC, s.name ASC, st.last_name ASC, st.first_name ASC",
                $school_id
            ),
            ARRAY_A
        );

        $name = static fn ( array $r ): string => trim( implode( ' ', array_filter( [ (string) $r['title'], (string) $r['first_name'], (string) $r['last_name'] ] ) ) );

        // Who shares each (class, subject): lets the sheet show "also taught by ..." without a second query.
        $sharing = [];
        foreach ( $rows as $r ) {
            if ( $r['assignment_type'] === 'subject_teacher' ) {
                $sharing[ $r['class_id'] . ':' . $r['subject_id'] ][] = $name( $r );
            }
        }

        $out = [ 'subject' => [], 'class' => [] ];

        foreach ( $rows as $r ) {
            $base = [
                'class'        => (string) $r['class_name'],
                'teacher'      => $name( $r ),
                'staff_number' => (string) $r['staff_number'],
                'email'        => (string) $r['email'],
                'phone'        => (string) $r['phone'],
            ];

            if ( $r['assignment_type'] === 'class_teacher' ) {
                $out['class'][] = $base;
                continue;
            }

            $mine   = $name( $r );
            $others = array_values( array_diff( $sharing[ $r['class_id'] . ':' . $r['subject_id'] ] ?? [], [ $mine ] ) );

            $out['subject'][] = $base + [
                'subject'     => (string) $r['subject_name'],
                'shared_with' => implode( '; ', $others ),
            ];
        }

        return $out;
    }

    /**
     * Neutralise a spreadsheet formula. A cell that starts with = + - or @ is run as a formula by Excel and
     * Sheets, and these cells hold names and notes typed by users.
     */
    public static function csv_safe( string $value ): string {
        return $value !== '' && strpos( "=+-@\t\r", $value[0] ) !== false ? "'" . $value : $value;
    }
}
