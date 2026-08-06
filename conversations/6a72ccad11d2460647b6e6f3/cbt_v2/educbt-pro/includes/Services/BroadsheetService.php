<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 7 — the broadsheet.
 *
 * One grid: students down the side, subjects across the top, totals and position on
 * the right. It is the document a Nigerian school actually works from — the thing
 * pinned up in the staff room and argued over in a results meeting.
 *
 * Built in TWO queries regardless of class size, then pivoted in PHP. The obvious
 * implementation — loop the students, query each one's subjects — is 60 queries for
 * a class of 60 and gets slower exactly as the school grows.
 *
 * A blank cell means the student does not offer that subject. That is different from
 * a zero, and the two must never be conflated: a zero is a mark, a blank is an
 * absence of one.
 */
class BroadsheetService {

    /**
     * @return array{subjects:array<int,array<string,mixed>>,rows:array<int,array<string,mixed>>,stats:array<string,mixed>}
     */
    public function build( int $school_id, int $class_id, int $session_id, int $term_id ): array {
        global $wpdb;

        $subject_results = Schema::table( 'subject_results' );
        $term_results    = Schema::table( 'term_results' );
        $subjects_table  = Schema::table( 'subjects_v2' );
        $students_table  = $wpdb->prefix . 'educbt_students';

        // Query 1 — every subject mark in the class.
        $marks = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sr.student_id, sr.subject_id, sr.ca_total, sr.exam_total, sr.total,
                        sr.grade, sr.subject_position, s.name AS subject_name, s.code AS subject_code
                 FROM {$subject_results} sr
                 INNER JOIN {$subjects_table} s ON s.id = sr.subject_id
                 WHERE sr.school_id = %d AND sr.class_id = %d AND sr.session_id = %d AND sr.term_id = %d
                 ORDER BY s.name ASC",
                $school_id,
                $class_id,
                $session_id,
                $term_id
            ),
            ARRAY_A
        );

        // Query 2 — the per-student summary line.
        $summaries = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.*, st.admission_number, st.first_name, st.last_name
                 FROM {$term_results} tr
                 INNER JOIN {$students_table} st ON st.id = tr.student_id
                 WHERE tr.school_id = %d AND tr.class_id = %d AND tr.session_id = %d AND tr.term_id = %d
                 ORDER BY tr.class_position ASC, st.last_name ASC",
                $school_id,
                $class_id,
                $session_id,
                $term_id
            ),
            ARRAY_A
        );

        $subjects = [];
        $grid     = [];

        foreach ( $marks as $mark ) {
            $subject_id = absint( $mark['subject_id'] );

            $subjects[ $subject_id ] = [
                'id'   => $subject_id,
                'name' => (string) $mark['subject_name'],
                'code' => (string) $mark['subject_code'],
            ];

            $grid[ absint( $mark['student_id'] ) ][ $subject_id ] = [
                'ca'       => (float) $mark['ca_total'],
                'exam'     => (float) $mark['exam_total'],
                'total'    => (float) $mark['total'],
                'grade'    => (string) $mark['grade'],
                'position' => absint( $mark['subject_position'] ),
            ];
        }

        $rows = [];

        foreach ( $summaries as $summary ) {
            $student_id = absint( $summary['student_id'] );
            $cells      = [];

            foreach ( array_keys( $subjects ) as $subject_id ) {
                // null, not 0. A student who does not offer Further Mathematics has
                // not scored zero in it.
                $cells[ $subject_id ] = $grid[ $student_id ][ $subject_id ] ?? null;
            }

            $rows[] = [
                'student_id'       => $student_id,
                'admission_number' => (string) $summary['admission_number'],
                'name'             => trim( $summary['first_name'] . ' ' . $summary['last_name'] ),
                'cells'            => $cells,
                'subjects_offered' => absint( $summary['subjects_offered'] ),
                'total'            => (float) $summary['total_score'],
                'average'          => (float) $summary['average_score'],
                'position'         => absint( $summary['class_position'] ),
                'status'           => (string) $summary['status'],
            ];
        }

        return [
            'subjects' => array_values( $subjects ),
            'rows'     => $rows,
            'stats'    => $this->stats( $rows, $subjects, $grid ),
        ];
    }

    /**
     * Column and cohort statistics — the numbers a results meeting asks for.
     *
     * @return array<string,mixed>
     */
    private function stats( array $rows, array $subjects, array $grid ): array {
        $averages = array_map( static fn( array $r ): float => (float) $r['average'], $rows );

        $per_subject = [];

        foreach ( $subjects as $subject_id => $subject ) {
            $totals = [];
            $passes = 0;

            foreach ( $grid as $student_marks ) {
                if ( ! isset( $student_marks[ $subject_id ] ) ) {
                    continue;
                }

                $total    = (float) $student_marks[ $subject_id ]['total'];
                $totals[] = $total;

                if ( $total >= 40 ) {
                    $passes++;
                }
            }

            if ( empty( $totals ) ) {
                continue;
            }

            $per_subject[] = [
                'subject_id' => $subject_id,
                'name'       => $subject['name'],
                'entered'    => count( $totals ),
                'average'    => round( array_sum( $totals ) / count( $totals ), 2 ),
                'highest'    => max( $totals ),
                'lowest'     => min( $totals ),
                'pass_rate'  => round( ( $passes / count( $totals ) ) * 100, 1 ),
            ];
        }

        return [
            'class_size'    => count( $rows ),
            'class_average' => $averages ? round( array_sum( $averages ) / count( $averages ), 2 ) : 0.0,
            'highest'       => $averages ? max( $averages ) : 0.0,
            'lowest'        => $averages ? min( $averages ) : 0.0,
            'per_subject'   => $per_subject,
        ];
    }

    /**
     * Flatten to rows suitable for CSV export, which is how schools actually move a
     * broadsheet into whatever they print from.
     *
     * @return array<int,array<int,string>>
     */
    public function to_rows( array $broadsheet ): array {
        $header = [ 'Adm. No', 'Name' ];

        foreach ( $broadsheet['subjects'] as $subject ) {
            $header[] = $subject['code'] ?: $subject['name'];
        }

        $header[] = 'Total';
        $header[] = 'Average';
        $header[] = 'Position';

        $out = [ $header ];

        foreach ( $broadsheet['rows'] as $row ) {
            $line = [ $row['admission_number'], $row['name'] ];

            foreach ( $broadsheet['subjects'] as $subject ) {
                $cell   = $row['cells'][ $subject['id'] ] ?? null;
                $line[] = $cell === null ? '' : (string) $cell['total'];
            }

            $line[] = (string) $row['total'];
            $line[] = (string) $row['average'];
            $line[] = (string) $row['position'];

            $out[] = $line;
        }

        return $out;
    }
}
