<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 3 — sessions and terms.
 *
 * v1 scattered `session_year` and `term` as free-text columns across students,
 * results and timetables. Nothing defined which session was "now", so every report
 * had to guess, and nearly every "why is this data wrong" bug in a school system
 * traces back to an ambiguous current session.
 *
 * The invariant enforced here: EXACTLY ONE current session per school, and exactly
 * one current term within it. Both are set transactionally — the old current is
 * cleared in the same operation that sets the new one, so there is never a moment
 * with two or zero.
 */
class AcademicYearService {

    /**
     * @return array{success:bool,session_id?:int,error?:string}
     */
    public function create_session( int $school_id, string $title, ?string $starts_on = null, ?string $ends_on = null, bool $make_current = false ): array {
        global $wpdb;

        $title = $this->normalise_title( $title );

        if ( $title === '' ) {
            return [ 'success' => false, 'error' => 'invalid_title' ];
        }

        $table = Schema::table( 'academic_sessions' );

        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND title = %s", $school_id, $title )
        );

        if ( $existing ) {
            return [ 'success' => false, 'error' => 'already_exists' ];
        }

        $start_year = (int) substr( $title, 0, 4 );

        $wpdb->insert(
            $table,
            [
                'school_id'  => $school_id,
                'title'      => $title,
                'starts_on'  => $starts_on ?: $start_year . '-09-01',
                'ends_on'    => $ends_on ?: ( $start_year + 1 ) . '-07-31',
                'is_current' => 0,
                'status'     => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        $session_id = absint( $wpdb->insert_id );

        if ( $session_id > 0 ) {
            $this->create_terms( $school_id, $session_id, $start_year );

            if ( $make_current ) {
                $this->set_current_session( $school_id, $session_id );
            }
        }

        return [ 'success' => true, 'session_id' => $session_id ];
    }

    /**
     * "2025/26", "2025-2026", "2025" all normalise to "2025/2026".
     */
    public function normalise_title( string $raw ): string {
        $raw = trim( $raw );

        if ( preg_match( '/(\d{4})\s*[\/\-]\s*(\d{2,4})/', $raw, $m ) ) {
            $start = (int) $m[1];
            $end   = strlen( $m[2] ) === 2 ? (int) ( substr( (string) $start, 0, 2 ) . $m[2] ) : (int) $m[2];

            // A session spans exactly one year boundary.
            if ( $end !== $start + 1 ) {
                return '';
            }

            return $start . '/' . $end;
        }

        if ( preg_match( '/^(\d{4})$/', $raw, $m ) ) {
            return $m[1] . '/' . ( (int) $m[1] + 1 );
        }

        return '';
    }

    private function create_terms( int $school_id, int $session_id, int $start_year ): void {
        global $wpdb;

        $terms = [
            [ 'First Term', 1, $start_year . '-09-01', $start_year . '-12-20' ],
            [ 'Second Term', 2, ( $start_year + 1 ) . '-01-08', ( $start_year + 1 ) . '-04-05' ],
            [ 'Third Term', 3, ( $start_year + 1 ) . '-04-22', ( $start_year + 1 ) . '-07-25' ],
        ];

        foreach ( $terms as [ $title, $order, $from, $to ] ) {
            $wpdb->insert(
                Schema::table( 'terms' ),
                [
                    'school_id'  => $school_id,
                    'session_id' => $session_id,
                    'title'      => $title,
                    'term_order' => $order,
                    'starts_on'  => $from,
                    'ends_on'    => $to,
                    'is_current' => 0,
                ],
                [ '%d', '%d', '%s', '%d', '%s', '%s', '%d' ]
            );
        }
    }

    /**
     * Clearing and setting happen together, so there is never a window in which the
     * school has two current sessions or none.
     */
    public function set_current_session( int $school_id, int $session_id ): bool {
        global $wpdb;

        $table = Schema::table( 'academic_sessions' );

        $belongs = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND school_id = %d", $session_id, $school_id )
        );

        if ( ! $belongs ) {
            return false;
        }

        $wpdb->query( 'START TRANSACTION' );

        $wpdb->query(
            $wpdb->prepare( "UPDATE {$table} SET is_current = 0 WHERE school_id = %d", $school_id )
        );

        $wpdb->query(
            $wpdb->prepare( "UPDATE {$table} SET is_current = 1 WHERE id = %d", $session_id )
        );

        $wpdb->query( 'COMMIT' );

        // Default the current term to the first term of the new session.
        $first = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'terms' ) . ' WHERE session_id = %d ORDER BY term_order ASC LIMIT 1',
                $session_id
            )
        );

        if ( $first ) {
            $this->set_current_term( $school_id, absint( $first ) );
        }

        EventDispatcher::action( 'educbt_session_changed', [
            'school_id'  => $school_id,
            'session_id' => $session_id,
        ] );

        return true;
    }

    public function set_current_term( int $school_id, int $term_id ): bool {
        global $wpdb;

        $table = Schema::table( 'terms' );

        $belongs = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND school_id = %d", $term_id, $school_id )
        );

        if ( ! $belongs ) {
            return false;
        }

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 0 WHERE school_id = %d", $school_id ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 1 WHERE id = %d", $term_id ) );
        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_term_changed', [
            'school_id' => $school_id,
            'term_id'   => $term_id,
        ] );

        return true;
    }

    public function current_session( int $school_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'academic_sessions' ) . ' WHERE school_id = %d AND is_current = 1 LIMIT 1',
                $school_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function current_term( int $school_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'terms' ) . ' WHERE school_id = %d AND is_current = 1 LIMIT 1',
                $school_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function list_sessions( int $school_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'academic_sessions' ) . ' WHERE school_id = %d ORDER BY title DESC',
                $school_id
            ),
            ARRAY_A
        );
    }

    public function list_terms( int $school_id, int $session_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'terms' ) . ' WHERE school_id = %d AND session_id = %d ORDER BY term_order ASC',
                $school_id,
                $session_id
            ),
            ARRAY_A
        );
    }

    /**
     * Closing a session locks its results against further edits and is the
     * precondition for running promotion.
     */
    public function close_session( int $school_id, int $session_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            Schema::table( 'academic_sessions' ),
            [ 'status' => 'closed', 'is_current' => 0 ],
            [ 'id' => $session_id, 'school_id' => $school_id ],
            [ '%s', '%d' ],
            [ '%d', '%d' ]
        );
    }
}
