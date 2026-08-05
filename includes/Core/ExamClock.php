<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 0 SECURITY FIX — server-authoritative exam timing.
 *
 * The previous implementation accepted `timer_seconds_remaining` from the browser
 * and treated it as the authority for session expiry. A student could post 3600
 * every few seconds and sit an unlimited exam.
 *
 * Remaining time is now always DERIVED on the server from:
 *
 *     remaining = duration_seconds - (now - started_at) + authorised_extension
 *
 * The client countdown is cosmetic. The stored `timer_seconds_remaining` column is
 * retained only as a denormalised cache for reporting; it is written by the server
 * and never read as an authority.
 */
class ExamClock {

    /** Grace period (seconds) allowed for a submit request in flight at expiry. */
    public const SUBMIT_GRACE_SECONDS = 15;

    /**
     * UTC timestamp for "now", independent of display timezone.
     */
    public function now(): int {
        return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
    }

    /**
     * Convert a stored MySQL datetime (site local time) to a UTC timestamp.
     */
    public function to_timestamp( ?string $mysql_datetime ): ?int {
        if ( $mysql_datetime === null || trim( $mysql_datetime ) === '' || $mysql_datetime === '0000-00-00 00:00:00' ) {
            return null;
        }

        if ( function_exists( 'get_gmt_from_date' ) ) {
            $gmt = get_gmt_from_date( $mysql_datetime, 'U' );
            if ( is_numeric( $gmt ) ) {
                return (int) $gmt;
            }
        }

        $ts = strtotime( $mysql_datetime . ' UTC' );
        return $ts === false ? null : $ts;
    }

    /**
     * Total allowed seconds for an attempt, including any authorised extension.
     */
    public function allowed_seconds( array $attempt, array $exam ): int {
        $duration = 0;

        if ( isset( $exam['duration_seconds'] ) && (int) $exam['duration_seconds'] > 0 ) {
            $duration = (int) $exam['duration_seconds'];
        } elseif ( isset( $exam['duration_minutes'] ) ) {
            $duration = (int) $exam['duration_minutes'] * 60;
        }

        $extension = isset( $attempt['extension_seconds'] ) ? max( 0, (int) $attempt['extension_seconds'] ) : 0;

        return max( 0, $duration + $extension );
    }

    /**
     * Seconds elapsed since the attempt began, per the server clock.
     */
    public function elapsed_seconds( array $attempt ): int {
        $started = $this->to_timestamp( $attempt['time_started'] ?? null );
        if ( $started === null ) {
            return 0;
        }

        return max( 0, $this->now() - $started );
    }

    /**
     * THE authoritative remaining-seconds value. Never trust any client input here.
     */
    public function remaining_seconds( array $attempt, array $exam ): int {
        $allowed = $this->allowed_seconds( $attempt, $exam );
        if ( $allowed <= 0 ) {
            return 0;
        }

        return max( 0, $allowed - $this->elapsed_seconds( $attempt ) );
    }

    /**
     * Has the attempt's window closed on the server clock?
     */
    public function has_expired( array $attempt, array $exam ): bool {
        return $this->remaining_seconds( $attempt, $exam ) <= 0;
    }

    /**
     * Whether a submission arriving now should still be accepted.
     * A short grace window absorbs requests already in flight at the moment of expiry.
     */
    public function accepts_submission( array $attempt, array $exam ): bool {
        $allowed = $this->allowed_seconds( $attempt, $exam );
        if ( $allowed <= 0 ) {
            return false;
        }

        return $this->elapsed_seconds( $attempt ) <= ( $allowed + self::SUBMIT_GRACE_SECONDS );
    }

    /**
     * Wall-clock UTC timestamp at which the attempt expires.
     */
    public function expires_at( array $attempt, array $exam ): ?int {
        $started = $this->to_timestamp( $attempt['time_started'] ?? null );
        if ( $started === null ) {
            return null;
        }

        return $started + $this->allowed_seconds( $attempt, $exam );
    }

    /**
     * Payload handed to the browser so it can render a countdown and resync after a
     * refresh or network drop. `server_time` lets the client correct for clock skew.
     */
    public function client_payload( array $attempt, array $exam ): array {
        return [
            'remaining_seconds' => $this->remaining_seconds( $attempt, $exam ),
            'allowed_seconds'   => $this->allowed_seconds( $attempt, $exam ),
            'server_time'       => $this->now(),
            'expires_at'        => $this->expires_at( $attempt, $exam ),
            'expired'           => $this->has_expired( $attempt, $exam ),
        ];
    }
}
