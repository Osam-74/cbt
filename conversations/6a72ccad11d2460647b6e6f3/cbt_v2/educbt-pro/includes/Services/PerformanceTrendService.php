<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\StudentRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PerformanceTrendService {
    private ResultRepository $result_repository;
    private StudentRepository $student_repository;

    public function __construct(
        ?ResultRepository $result_repository = null,
        ?StudentRepository $student_repository = null
    ) {
        $this->result_repository = $result_repository ?? new ResultRepository();
        $this->student_repository = $student_repository ?? new StudentRepository();
    }

    /**
     * Returns time-series performance data across terms/sessions at school, class, subject,
     * and individual student levels. Filters: student_id, subject, class, session_year, term.
     */
    public function get_performance_trends( int $school_id, array $filters = [] ): array {
        $results = $this->apply_filters( $this->result_repository->get_all_results( $school_id ), $filters );

        if ( empty( $results ) ) {
            return [
                'timeline'        => [],
                'subjects'        => [],
                'student_trends'  => [],
                'trajectory'      => 'Insufficient data',
                'trend_direction' => 'neutral',
            ];
        }

        $timeline = $this->build_timeline( $results );
        $subject_trends = $this->build_subject_trends( $results );
        $student_trends = $this->build_student_trends( $results, $school_id, $filters );
        $direction = $this->compute_trend_direction( $timeline );

        return [
            'timeline'        => $timeline,
            'subjects'        => $subject_trends,
            'student_trends'  => $student_trends,
            'trajectory'      => $this->label_trajectory( $direction ),
            'trend_direction' => $direction,
        ];
    }

    /**
     * Aggregates average score, pass rate and result count per chronological period
     * (session_year / term).
     */
    private function build_timeline( array $results ): array {
        $periods = [];
        foreach ( $results as $result ) {
            $session_year = trim( (string) ( $result['session_year'] ?? '' ) );
            $term = trim( (string) ( $result['term'] ?? '' ) );
            $key = $session_year !== '' ? $session_year : 'Unknown';
            if ( $term !== '' ) {
                $key .= ' / ' . $term;
            }
            $periods[ $key ][] = floatval( $result['score'] ?? 0 );
        }

        // Sort periods chronologically (alphabetical sort works well for consistent "YYYY/YYYY / Term N" patterns)
        ksort( $periods );

        $timeline = [];
        foreach ( $periods as $label => $scores ) {
            $count = count( $scores );
            $avg = $count > 0 ? round( array_sum( $scores ) / $count, 2 ) : 0.0;
            $passing = count( array_filter( $scores, static fn( float $s ): bool => $s >= 50 ) );
            $timeline[] = [
                'period'     => $label,
                'total'      => $count,
                'average'    => $avg,
                'pass_rate'  => $count > 0 ? round( ( $passing / $count ) * 100, 2 ) : 0.0,
                'min_score'  => $count > 0 ? (float) min( $scores ) : 0.0,
                'max_score'  => $count > 0 ? (float) max( $scores ) : 0.0,
            ];
        }

        return $timeline;
    }

    /**
     * Per-subject time-series: each subject entry contains an ordered array of period averages.
     */
    private function build_subject_trends( array $results ): array {
        $by_subject = [];
        foreach ( $results as $result ) {
            $subject = trim( (string) ( $result['subject'] ?? '' ) ) ?: 'Unknown';
            $session_year = trim( (string) ( $result['session_year'] ?? '' ) );
            $term = trim( (string) ( $result['term'] ?? '' ) );
            $period = $session_year !== '' ? $session_year : 'Unknown';
            if ( $term !== '' ) {
                $period .= ' / ' . $term;
            }
            $by_subject[ $subject ][ $period ][] = floatval( $result['score'] ?? 0 );
        }

        $trends = [];
        foreach ( $by_subject as $subject => $period_map ) {
            ksort( $period_map );
            $series = [];
            foreach ( $period_map as $period => $scores ) {
                $count = count( $scores );
                $avg = $count > 0 ? round( array_sum( $scores ) / $count, 2 ) : 0.0;
                $series[] = [ 'period' => $period, 'average' => $avg, 'total' => $count ];
            }
            $trends[ $subject ] = [
                'series'    => $series,
                'direction' => $this->compute_trend_direction( $series ),
            ];
        }

        return $trends;
    }

    /**
     * Per-student time-series with improvement delta.
     */
    private function build_student_trends( array $results, int $school_id, array $filters ): array {
        $class_filter = trim( (string) ( $filters['class'] ?? '' ) );

        $student_rows = $this->student_repository->get_all_students( $school_id );
        $student_lookup = [];
        foreach ( $student_rows as $s ) {
            $id = (string) ( $s['id'] ?? $s['student_id'] ?? '' );
            if ( $id !== '' ) {
                $student_lookup[ $id ] = $s;
            }
        }

        $by_student = [];
        foreach ( $results as $result ) {
            $student_id = (string) absint( $result['student_id'] ?? 0 );
            if ( $class_filter !== '' ) {
                $student_class = trim( (string) ( $student_lookup[ $student_id ]['class'] ?? '' ) );
                if ( strcasecmp( $student_class, $class_filter ) !== 0 ) {
                    continue;
                }
            }
            $session_year = trim( (string) ( $result['session_year'] ?? '' ) );
            $term = trim( (string) ( $result['term'] ?? '' ) );
            $period = $session_year !== '' ? $session_year : 'Unknown';
            if ( $term !== '' ) {
                $period .= ' / ' . $term;
            }
            $by_student[ $student_id ][ $period ][] = floatval( $result['score'] ?? 0 );
        }

        $student_trends = [];
        foreach ( $by_student as $student_id => $period_map ) {
            ksort( $period_map );
            $series = [];
            foreach ( $period_map as $period => $scores ) {
                $count = count( $scores );
                $avg = $count > 0 ? round( array_sum( $scores ) / $count, 2 ) : 0.0;
                $series[] = [ 'period' => $period, 'average' => $avg, 'total' => $count ];
            }
            $averages = array_column( $series, 'average' );
            $delta = count( $averages ) >= 2 ? round( end( $averages ) - reset( $averages ), 2 ) : 0.0;
            $student_data = $student_lookup[ $student_id ] ?? [];
            $student_trends[] = [
                'student_id'  => absint( $student_id ),
                'full_name'   => $student_data['full_name'] ?? '',
                'class'       => $student_data['class'] ?? '',
                'series'      => $series,
                'delta'       => $delta,
                'direction'   => $this->compute_trend_direction( $series ),
            ];
        }

        // Sort descending by absolute delta so biggest movers appear first
        usort( $student_trends, static function ( array $a, array $b ): int {
            return abs( (int) $b['delta'] ) <=> abs( (int) $a['delta'] );
        } );

        return $student_trends;
    }

    /**
     * Computes trend direction from an ordered series of ['average' => float, ...] entries.
     * Returns 'up', 'down', or 'stable'.
     */
    public function compute_trend_direction( array $series ): string {
        $averages = array_column( $series, 'average' );
        if ( count( $averages ) < 2 ) {
            return 'stable';
        }

        $first = (float) reset( $averages );
        $last = (float) end( $averages );
        $delta = $last - $first;

        if ( $delta > 2.0 ) {
            return 'up';
        }

        if ( $delta < -2.0 ) {
            return 'down';
        }

        return 'stable';
    }

    private function label_trajectory( string $direction ): string {
        return match ( $direction ) {
            'up'   => 'Improving',
            'down' => 'Declining',
            default => 'Stable',
        };
    }

    private function apply_filters( array $results, array $filters ): array {
        return array_values( array_filter( $results, static function ( array $result ) use ( $filters ): bool {
            if ( ! empty( $filters['student_id'] ) && absint( $result['student_id'] ?? 0 ) !== absint( $filters['student_id'] ) ) {
                return false;
            }

            if ( ( $filters['subject'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['subject'] ?? '' ) ), trim( (string) $filters['subject'] ) ) !== 0 ) {
                return false;
            }

            if ( ( $filters['session_year'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['session_year'] ?? '' ) ), trim( (string) $filters['session_year'] ) ) !== 0 ) {
                return false;
            }

            return true;
        } ) );
    }
}
