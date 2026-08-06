<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 3 — grading configuration.
 *
 * v1 hard-coded grade boundaries in PHP (`>= 90 return 'A'`), which is wrong twice
 * over: those aren't Nigerian boundaries, and every school tweaks them, so every
 * tweak would have been a code deploy.
 *
 * Boundaries now live in `grade_bands` rows. This service reads them, and enforces
 * the two invariants that stop a school configuring itself into nonsense:
 *
 *   1. Grade bands must cover 0-100 with no gap and no overlap.
 *   2. Assessment component weights must total exactly 100.
 *
 * Both are checked BEFORE saving. A school that silently saves a scale summing to
 * 90 discovers it at the end of term, on printed report cards.
 */
class GradingService {

    // ---------------------------------------------------------------
    // Grade lookup
    // ---------------------------------------------------------------

    /**
     * @return array{grade:string,remark:string,is_pass:bool}
     */
    public function grade_for( int $school_id, float $score, ?int $scale_id = null ): array {
        $bands = $this->bands( $school_id, $scale_id );

        if ( empty( $bands ) ) {
            return [ 'grade' => '', 'remark' => '', 'is_pass' => false ];
        }

        $score = max( 0.0, min( 100.0, $score ) );

        foreach ( $bands as $band ) {
            $min = (float) $band['min_score'];
            $max = (float) $band['max_score'];

            if ( $score >= $min && $score <= $max ) {
                return [
                    'grade'   => (string) $band['grade'],
                    'remark'  => (string) $band['remark'],
                    'is_pass' => (bool) $band['is_pass'],
                ];
            }
        }

        // Unreachable once validate_bands() has run, but a score must never come
        // back ungraded — an empty grade on a report card is worse than a floor.
        $last = end( $bands );

        return [
            'grade'   => (string) $last['grade'],
            'remark'  => (string) $last['remark'],
            'is_pass' => (bool) $last['is_pass'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function bands( int $school_id, ?int $scale_id = null ): array {
        global $wpdb;

        if ( $scale_id === null ) {
            $scale_id = $this->default_scale_id( $school_id );
        }

        if ( $scale_id <= 0 ) {
            return [];
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'grade_bands' ) . ' WHERE scale_id = %d ORDER BY sort_order ASC',
                $scale_id
            ),
            ARRAY_A
        );
    }

    public function default_scale_id( int $school_id, string $stage = 'both' ): int {
        global $wpdb;

        $table = Schema::table( 'grading_scales' );

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND applies_to_stage IN (%s, 'both') ORDER BY is_default DESC, id ASC LIMIT 1",
                $school_id,
                $stage
            )
        );

        return $id ? absint( $id ) : 0;
    }

    public function scales( int $school_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'grading_scales' ) . ' WHERE school_id = %d ORDER BY is_default DESC, name ASC',
                $school_id
            ),
            ARRAY_A
        );
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    /**
     * Bands must tile 0-100 exactly: no gap a score could fall into, no overlap
     * where two grades both claim a score.
     *
     * @param array<int,array{grade:string,min_score:float,max_score:float}> $bands
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function validate_bands( array $bands ): array {
        $errors = [];

        if ( empty( $bands ) ) {
            return [ 'valid' => false, 'errors' => [ 'no_bands_defined' ] ];
        }

        foreach ( $bands as $band ) {
            $min = (float) ( $band['min_score'] ?? -1 );
            $max = (float) ( $band['max_score'] ?? -1 );

            if ( $min < 0 || $max > 100 ) {
                $errors[] = 'band_out_of_range:' . ( $band['grade'] ?? '?' );
            }

            if ( $min > $max ) {
                $errors[] = 'band_inverted:' . ( $band['grade'] ?? '?' );
            }
        }

        // Sort ascending by floor, then walk the number line.
        usort( $bands, static fn( array $a, array $b ): int => (float) $a['min_score'] <=> (float) $b['min_score'] );

        if ( (float) $bands[0]['min_score'] > 0.0 ) {
            $errors[] = 'gap_below:' . $bands[0]['min_score'];
        }

        $count = count( $bands );

        for ( $i = 0; $i < $count - 1; $i++ ) {
            $this_max = (float) $bands[ $i ]['max_score'];
            $next_min = (float) $bands[ $i + 1 ]['min_score'];

            if ( $next_min <= $this_max ) {
                $errors[] = 'overlap:' . $bands[ $i ]['grade'] . '/' . $bands[ $i + 1 ]['grade'];
            } elseif ( $next_min - $this_max > 1.0 ) {
                $errors[] = 'gap:' . $this_max . '-' . $next_min;
            }
        }

        if ( (float) $bands[ $count - 1 ]['max_score'] < 100.0 ) {
            $errors[] = 'gap_above:' . $bands[ $count - 1 ]['max_score'];
        }

        return [ 'valid' => empty( $errors ), 'errors' => array_values( array_unique( $errors ) ) ];
    }

    /**
     * @param array<int,array{code:string,max_score:float}> $components
     * @return array{valid:bool,total:float,errors:array<int,string>}
     */
    public function validate_components( array $components ): array {
        $errors = [];
        $total  = 0.0;
        $codes  = [];
        $exams  = 0;

        foreach ( $components as $component ) {
            $total += (float) ( $component['max_score'] ?? 0 );

            $code = (string) ( $component['code'] ?? '' );
            if ( $code === '' ) {
                $errors[] = 'component_missing_code';
            } elseif ( in_array( $code, $codes, true ) ) {
                $errors[] = 'duplicate_code:' . $code;
            } else {
                $codes[] = $code;
            }

            if ( ! empty( $component['is_exam'] ) ) {
                $exams++;
            }
        }

        if ( abs( $total - 100.0 ) > 0.001 ) {
            $errors[] = 'weights_total_' . rtrim( rtrim( number_format( $total, 2, '.', '' ), '0' ), '.' );
        }

        // The CBT engine writes into exactly one component; zero means auto-scoring
        // has nowhere to go, more than one means it would be ambiguous.
        if ( $exams === 0 ) {
            $errors[] = 'no_exam_component';
        } elseif ( $exams > 1 ) {
            $errors[] = 'multiple_exam_components';
        }

        return [ 'valid' => empty( $errors ), 'total' => $total, 'errors' => array_values( array_unique( $errors ) ) ];
    }

    // ---------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------

    /**
     * @return array{success:bool,errors?:array<int,string>}
     */
    public function save_components( int $school_id, array $components ): array {
        $check = $this->validate_components( $components );

        if ( ! $check['valid'] ) {
            return [ 'success' => false, 'errors' => $check['errors'] ];
        }

        global $wpdb;

        $table = Schema::table( 'assessment_components' );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'retired' WHERE school_id = %d", $school_id ) );

        $order = 0;

        foreach ( $components as $component ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (school_id, name, code, max_score, is_exam, sort_order, status)
                     VALUES (%d, %s, %s, %f, %d, %d, 'active')
                     ON DUPLICATE KEY UPDATE name = VALUES(name), max_score = VALUES(max_score),
                     is_exam = VALUES(is_exam), sort_order = VALUES(sort_order), status = 'active'",
                    $school_id,
                    sanitize_text_field( (string) ( $component['name'] ?? '' ) ),
                    sanitize_text_field( (string) ( $component['code'] ?? '' ) ),
                    (float) ( $component['max_score'] ?? 0 ),
                    ! empty( $component['is_exam'] ) ? 1 : 0,
                    $order++
                )
            );
        }

        $wpdb->query( 'COMMIT' );

        return [ 'success' => true ];
    }

    /**
     * @return array{success:bool,errors?:array<int,string>}
     */
    public function save_bands( int $school_id, int $scale_id, array $bands ): array {
        $check = $this->validate_bands( $bands );

        if ( ! $check['valid'] ) {
            return [ 'success' => false, 'errors' => $check['errors'] ];
        }

        global $wpdb;

        $scales = Schema::table( 'grading_scales' );

        $owns = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$scales} WHERE id = %d AND school_id = %d", $scale_id, $school_id )
        );

        if ( ! $owns ) {
            return [ 'success' => false, 'errors' => [ 'scale_not_found' ] ];
        }

        $table = Schema::table( 'grade_bands' );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->delete( $table, [ 'scale_id' => $scale_id ], [ '%d' ] );

        usort( $bands, static fn( array $a, array $b ): int => (float) $b['min_score'] <=> (float) $a['min_score'] );

        $order = 0;

        foreach ( $bands as $band ) {
            $wpdb->insert(
                $table,
                [
                    'scale_id'   => $scale_id,
                    'grade'      => sanitize_text_field( (string) ( $band['grade'] ?? '' ) ),
                    'min_score'  => (float) $band['min_score'],
                    'max_score'  => (float) $band['max_score'],
                    'remark'     => sanitize_text_field( (string) ( $band['remark'] ?? '' ) ),
                    'is_pass'    => ! empty( $band['is_pass'] ) ? 1 : 0,
                    'sort_order' => $order++,
                ],
                [ '%d', '%s', '%f', '%f', '%s', '%d', '%d' ]
            );
        }

        $wpdb->query( 'COMMIT' );

        return [ 'success' => true ];
    }

    public function components( int $school_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'assessment_components' ) . " WHERE school_id = %d AND status = 'active' ORDER BY sort_order ASC",
                $school_id
            ),
            ARRAY_A
        );
    }

    /**
     * Total obtainable marks for a term, from the component weights.
     */
    public function term_total( int $school_id ): float {
        $total = 0.0;

        foreach ( $this->components( $school_id ) as $component ) {
            $total += (float) $component['max_score'];
        }

        return $total;
    }
}
