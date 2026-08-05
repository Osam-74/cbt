<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ExamRepository;
use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\ExamAttemptRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamComparativeAnalyticsService {
    private ExamRepository $exam_repository;
    private ResultRepository $result_repository;
    private ExamAttemptRepository $attempt_repository;

    public function __construct(
        ?ExamRepository $exam_repository = null,
        ?ResultRepository $result_repository = null,
        ?ExamAttemptRepository $attempt_repository = null
    ) {
        $this->exam_repository   = $exam_repository   ?? new ExamRepository();
        $this->result_repository = $result_repository ?? new ResultRepository();
        $this->attempt_repository = $attempt_repository ?? new ExamAttemptRepository();
    }

    /**
     * Returns a side-by-side comparison of all exams (or a filtered subset) for a school,
     * sorted by descending average score. Filters: exam_ids (array), subject, session_year, term.
     */
    public function compare_exams( int $school_id, array $filters = [] ): array {
        $all_exams   = $this->exam_repository->get_all_exams( $school_id );
        $all_results = $this->result_repository->get_all_results( $school_id );
        $all_attempts = $this->attempt_repository->get_all_attempts( $school_id );

        // Optional exam_ids whitelist
        $exam_ids_filter = array_filter( array_map( 'absint', (array) ( $filters['exam_ids'] ?? [] ) ) );

        $results_by_exam = [];
        foreach ( $all_results as $result ) {
            $eid = absint( $result['exam_id'] ?? 0 );
            if ( $eid <= 0 ) {
                continue;
            }
            if ( ! empty( $exam_ids_filter ) && ! in_array( $eid, $exam_ids_filter, true ) ) {
                continue;
            }
            if ( ( $filters['subject'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['subject'] ?? '' ) ), trim( (string) $filters['subject'] ) ) !== 0 ) {
                continue;
            }
            if ( ( $filters['session_year'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['session_year'] ?? '' ) ), trim( (string) $filters['session_year'] ) ) !== 0 ) {
                continue;
            }
            if ( ( $filters['term'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['term'] ?? '' ) ), trim( (string) $filters['term'] ) ) !== 0 ) {
                continue;
            }
            $results_by_exam[ $eid ][] = $result;
        }

        $attempts_by_exam = [];
        foreach ( $all_attempts as $attempt ) {
            $eid = absint( $attempt['exam_id'] ?? 0 );
            if ( $eid > 0 ) {
                $attempts_by_exam[ $eid ][] = $attempt;
            }
        }

        $exam_lookup = [];
        foreach ( $all_exams as $exam ) {
            $exam_lookup[ absint( $exam['id'] ?? 0 ) ] = $exam;
        }

        $comparison = [];
        foreach ( $results_by_exam as $exam_id => $results ) {
            $metrics = $this->build_exam_metrics( $results, $attempts_by_exam[ $exam_id ] ?? [] );
            $exam_meta = $exam_lookup[ $exam_id ] ?? [];
            $comparison[] = array_merge(
                [
                    'exam_id'          => $exam_id,
                    'title'            => trim( (string) ( $exam_meta['title'] ?? 'Exam #' . $exam_id ) ),
                    'exam_type'        => trim( (string) ( $exam_meta['exam_type'] ?? '' ) ),
                    'duration_minutes' => absint( $exam_meta['duration_minutes'] ?? 0 ),
                ],
                $metrics
            );
        }

        // Sort by average_score descending (best-performing exam first)
        usort( $comparison, static function ( array $a, array $b ): int {
            return $b['average_score'] <=> $a['average_score'];
        } );

        return [
            'exams_compared' => count( $comparison ),
            'comparison'     => $comparison,
            'top_exam'       => $comparison[0] ?? null,
            'bottom_exam'    => count( $comparison ) > 1 ? end( $comparison ) : null,
        ];
    }

    private function build_exam_metrics( array $results, array $attempts ): array {
        $scores  = array_map( static fn( array $r ): float => floatval( $r['score'] ?? 0 ), $results );
        $total   = count( $scores );

        $avg     = $total > 0 ? round( array_sum( $scores ) / $total, 2 ) : 0.0;
        $passing = count( array_filter( $scores, static fn( float $s ): bool => $s >= 50 ) );
        $pass_rate = $total > 0 ? round( ( $passing / $total ) * 100, 2 ) : 0.0;

        $grade_counts = [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0 ];
        foreach ( $results as $r ) {
            $g = strtoupper( trim( (string) ( $r['grade'] ?? '' ) ) );
            if ( isset( $grade_counts[ $g ] ) ) {
                $grade_counts[ $g ]++;
            }
        }

        // Difficulty: inverse of average-score percentage
        $difficulty_index = $total > 0 ? round( 100 - $avg, 2 ) : 0.0;

        // Completion / dropout rate from attempts
        $total_attempts     = count( $attempts );
        $submitted_attempts = count( array_filter( $attempts, static function ( array $a ): bool {
            $s = strtolower( trim( (string) ( $a['status'] ?? '' ) ) );
            return $s === 'submitted' || $s === 'completed';
        } ) );
        $completion_rate = $total_attempts > 0 ? round( ( $submitted_attempts / $total_attempts ) * 100, 2 ) : 0.0;

        $sorted = $scores;
        sort( $sorted );
        $min_score = $total > 0 ? (float) reset( $sorted ) : 0.0;
        $max_score = $total > 0 ? (float) end( $sorted )   : 0.0;

        // Std deviation
        $std_dev = 0.0;
        if ( $total > 1 ) {
            $variance = array_sum( array_map( static fn( float $s ): float => ( $s - $avg ) ** 2, $scores ) ) / $total;
            $std_dev  = round( sqrt( $variance ), 2 );
        }

        return [
            'total_results'    => $total,
            'average_score'    => $avg,
            'pass_rate'        => $pass_rate,
            'difficulty_index' => $difficulty_index,
            'min_score'        => $min_score,
            'max_score'        => $max_score,
            'std_deviation'    => $std_dev,
            'grade_distribution' => $grade_counts,
            'total_attempts'   => $total_attempts,
            'completion_rate'  => $completion_rate,
        ];
    }
}
