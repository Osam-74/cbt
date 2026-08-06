<?php

namespace EduCBTPro\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GradeComputationService {
    /**
     * Compute grade from percentage using standard scale
     */
    public function compute_grade_from_percentage( float $percentage ): string {
        if ( $percentage >= 90 ) {
            return 'A';
        } elseif ( $percentage >= 80 ) {
            return 'B';
        } elseif ( $percentage >= 70 ) {
            return 'C';
        } elseif ( $percentage >= 60 ) {
            return 'D';
        } elseif ( $percentage >= 50 ) {
            return 'E';
        } else {
            return 'F';
        }
    }

    /**
     * Compute grade with custom scale
     */
    public function compute_grade_custom( float $percentage, array $scale ): string {
        foreach ( $scale as $threshold => $grade ) {
            if ( $percentage >= floatval( $threshold ) ) {
                return $grade;
            }
        }
        return 'F';
    }

    /**
     * Get grade remark/description
     */
    public function get_grade_remark( string $grade ): string {
        $remarks = [
            'A' => 'Excellent',
            'B' => 'Very Good',
            'C' => 'Good',
            'D' => 'Fair',
            'E' => 'Pass',
            'F' => 'Fail',
        ];
        return $remarks[ $grade ] ?? 'Unknown';
    }

    /**
     * Calculate final score from multiple components
     * Example: $components = ['test' => 0.4, 'assignment' => 0.3, 'exam' => 0.3]
     */
    public function calculate_weighted_score( array $scores, array $weights ): float {
        $total = 0.0;
        $weight_sum = 0.0;

        foreach ( $weights as $component => $weight ) {
            if ( isset( $scores[ $component ] ) ) {
                $total += floatval( $scores[ $component ] ) * floatval( $weight );
                $weight_sum += floatval( $weight );
            }
        }

        if ( $weight_sum === 0.0 ) {
            return 0.0;
        }

        return round( $total / $weight_sum, 2 );
    }

    /**
     * Calculate average score
     */
    public function calculate_average( array $scores ): float {
        if ( empty( $scores ) ) {
            return 0.0;
        }
        return round( array_sum( $scores ) / count( $scores ), 2 );
    }

    /**
     * Generate grade distribution report
     */
    public function generate_grade_distribution( array $results ): array {
        $distribution = [
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'E' => 0,
            'F' => 0,
        ];

        foreach ( $results as $result ) {
            $grade = $result['grade'] ?? 'F';
            if ( isset( $distribution[ $grade ] ) ) {
                $distribution[ $grade ]++;
            }
        }

        $total = array_sum( $distribution );
        $percentages = [];

        foreach ( $distribution as $grade => $count ) {
            $percentages[ $grade ] = $total > 0 ? round( ( $count / $total ) * 100, 2 ) : 0;
        }

        return [
            'count'       => $distribution,
            'percentage'  => $percentages,
            'total'       => $total,
        ];
    }

    /**
     * Calculate pass rate
     */
    public function calculate_pass_rate( array $results, string $pass_grade = 'E' ): float {
        if ( empty( $results ) ) {
            return 0.0;
        }

        $pass_grades = [ 'A', 'B', 'C', 'D', 'E' ];
        $pass_threshold = array_search( $pass_grade, $pass_grades, true );

        if ( $pass_threshold === false ) {
            return 0.0;
        }

        $pass_grades = array_slice( $pass_grades, 0, $pass_threshold + 1 );

        $passes = 0;
        foreach ( $results as $result ) {
            if ( in_array( $result['grade'] ?? 'F', $pass_grades, true ) ) {
                $passes++;
            }
        }

        return round( ( $passes / count( $results ) ) * 100, 2 );
    }

    /**
     * Calculate class statistics
     */
    public function calculate_class_statistics( array $results ): array {
        if ( empty( $results ) ) {
            return [];
        }

        $scores = array_map( function ( $r ) {
            return floatval( $r['score'] ?? 0 );
        }, $results );

        sort( $scores );

        $count = count( $scores );
        $average = $this->calculate_average( $scores );
        $median = $count % 2 === 0 
            ? ( $scores[ $count / 2 - 1 ] + $scores[ $count / 2 ] ) / 2 
            : $scores[ floor( $count / 2 ) ];

        $std_dev = $this->calculate_standard_deviation( $scores );

        return [
            'total_students'  => $count,
            'average_score'   => $average,
            'highest_score'   => (int) max( $scores ),
            'lowest_score'    => (int) min( $scores ),
            'median_score'    => $median,
            'std_deviation'   => $std_dev,
        ];
    }

    /**
     * Calculate standard deviation
     */
    private function calculate_standard_deviation( array $scores ): float {
        $average = array_sum( $scores ) / count( $scores );
        $sum_sq_diff = 0;

        foreach ( $scores as $score ) {
            $sum_sq_diff += pow( $score - $average, 2 );
        }

        $variance = $sum_sq_diff / count( $scores );
        return round( sqrt( $variance ), 2 );
    }
}
