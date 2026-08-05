<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\ResultRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRS §13 – Continuous Assessment (CA) component breakdown.
 *
 * Default weight schema (configurable):
 *   assignment  = 10 %
 *   test1       = 10 %
 *   test2       = 10 %
 *   project     = 10 %
 *   attendance  =  0 %   (tracked but unweighted by default)
 *   exam        = 60 %
 *   ──────────────────
 *   total       = 100 %
 *
 * The service accepts an optional custom weight map so schools can override
 * to any ratio (e.g. CA=40 / Exam=60, or CA=30 / Exam=70) as long as total = 100.
 */
class ContinuousAssessmentService {
    public const DEFAULT_WEIGHTS = [
        'assignment' => 10,
        'test1'      => 10,
        'test2'      => 10,
        'project'    => 10,
        'attendance' =>  0,
        'exam'       => 60,
    ];

    private ResultRepository $repository;

    public function __construct( ?ResultRepository $repository = null ) {
        $this->repository = $repository ?? new ResultRepository();
    }

    /**
     * Compute the weighted CA total for a set of raw component scores.
     *
     * @param array $components  Assoc array: ['assignment'=>85, 'test1'=>70, 'exam'=>80, ...]
     * @param array $weights     Optional custom weight map (values = percentage points, must sum ≤ 100)
     * @return array{
     *   components: array,
     *   weighted_scores: array,
     *   ca_total: float,
     *   exam_component: float,
     *   grand_total: float,
     *   grade: string,
     *   remark: string,
     *   breakdown: array
     * }
     */
    public function compute_ca( array $components, array $weights = [] ): array {
        $weights = $this->resolve_weights( $weights );
        $weights = EventDispatcher::filter( 'ca_weights', $weights, [ 'components' => $components ] );
        if ( ! is_array( $weights ) ) {
            $weights = $this->resolve_weights( [] );
        }
        $this->validate_weights( $weights );

        $weighted_scores = [];
        $ca_total = 0.0;
        $exam_component = 0.0;
        $breakdown = [];

        foreach ( $weights as $component => $weight ) {
            if ( $weight <= 0 ) {
                continue;
            }

            $raw = floatval( $components[ $component ] ?? 0 );
            $raw = max( 0.0, min( 100.0, $raw ) ); // clamp to [0,100]
            $weighted = round( ( $raw * $weight ) / 100, 4 );
            $weighted_scores[ $component ] = $weighted;

            $breakdown[ $component ] = [
                'raw_score'      => $raw,
                'weight_percent' => $weight,
                'weighted_score' => $weighted,
            ];

            if ( $component === 'exam' ) {
                $exam_component = $weighted;
            } else {
                $ca_total += $weighted;
            }
        }

        $grand_total = round( $ca_total + $exam_component, 2 );
        $grade = $this->grade( $grand_total );
        $remark = $this->remark( $grade );

        EventDispatcher::action( 'ca_computed', [
            'components'  => $components,
            'weights'     => $weights,
            'ca_total'    => round( $ca_total, 2 ),
            'exam_total'  => round( $exam_component, 2 ),
            'grand_total' => $grand_total,
            'grade'       => $grade,
            'remark'      => $remark,
        ] );

        return [
            'components'      => $components,
            'weighted_scores' => $weighted_scores,
            'ca_total'        => round( $ca_total, 2 ),
            'exam_component'  => round( $exam_component, 2 ),
            'grand_total'     => $grand_total,
            'grade'           => $grade,
            'remark'          => $remark,
            'breakdown'       => $breakdown,
        ];
    }

    /**
     * Compute CA for all results belonging to a student+subject+term, storing
     * component scores as JSON in the 'student_responses' column under the
     * 'ca_components' key (convention).
     */
    public function compute_ca_for_results( int $school_id, int $student_id, string $subject, string $term, string $session_year, array $weights = [] ): array {
        $results = $this->repository->get_all_results( $school_id );

        $matching = array_values( array_filter( $results, static function ( array $r ) use ( $student_id, $subject, $term, $session_year ): bool {
            return absint( $r['student_id'] ?? 0 ) === $student_id
                && strcasecmp( trim( (string) ( $r['subject'] ?? '' ) ), $subject ) === 0
                && strcasecmp( trim( (string) ( $r['term'] ?? '' ) ), $term ) === 0
                && strcasecmp( trim( (string) ( $r['session_year'] ?? '' ) ), $session_year ) === 0;
        } ) );

        if ( empty( $matching ) ) {
            return [ 'error' => 'no_matching_results' ];
        }

        // Pick the most recent / first result
        $result = $matching[0];
        $components = $this->extract_ca_components( $result );

        return array_merge(
            [ 'result_id' => absint( $result['id'] ?? 0 ) ],
            $this->compute_ca( $components, $weights )
        );
    }

    /**
     * Validate that a custom weight map is sensible.
     * Throws an \InvalidArgumentException if the weights exceed 100.
     */
    public function validate_weights( array $weights ): void {
        $total = array_sum( $weights );
        if ( $total > 100 ) {
            throw new \InvalidArgumentException( sprintf( 'Weights must sum to ≤ 100; got %d.', $total ) );
        }
    }

    // ------------------------------------------------------------------ //

    private function resolve_weights( array $custom ): array {
        if ( empty( $custom ) ) {
            return self::DEFAULT_WEIGHTS;
        }

        // Merge: custom values override defaults; defaults fill any missing keys
        return array_merge( self::DEFAULT_WEIGHTS, array_intersect_key( $custom, self::DEFAULT_WEIGHTS ) );
    }

    private function extract_ca_components( array $result ): array {
        $responses = $result['student_responses'] ?? '';
        if ( is_string( $responses ) ) {
            $decoded = json_decode( $responses, true );
            if ( is_array( $decoded ) && isset( $decoded['ca_components'] ) && is_array( $decoded['ca_components'] ) ) {
                return $decoded['ca_components'];
            }
        }

        // Fall back: the score field holds the exam mark, CA components are 0
        return [ 'exam' => floatval( $result['score'] ?? 0 ) ];
    }

    private function grade( float $score ): string {
        if ( $score >= 90 ) { return 'A'; }
        if ( $score >= 80 ) { return 'B'; }
        if ( $score >= 70 ) { return 'C'; }
        if ( $score >= 60 ) { return 'D'; }
        if ( $score >= 50 ) { return 'E'; }
        return 'F';
    }

    private function remark( string $grade ): string {
        return match ( $grade ) {
            'A' => 'Excellent',
            'B' => 'Very Good',
            'C' => 'Good',
            'D' => 'Fair',
            'E' => 'Pass',
            default => 'Fail',
        };
    }
}
