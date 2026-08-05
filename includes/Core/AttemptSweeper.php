<?php

namespace EduCBTPro\Core;

use EduCBTPro\Services\ExamAttemptService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 0 — abandoned attempt sweep.
 *
 * Auto-submit previously depended entirely on the browser reaching zero and calling
 * the API. A student who closed the tab, lost power, or lost connectivity left an
 * attempt stuck at `in_progress` forever, and was never scored.
 *
 * This sweep runs on the server and closes out any attempt whose window has passed,
 * regardless of whether the client is still alive.
 */
class AttemptSweeper {

    public const HOOK     = 'educbt_sweep_expired_attempts';
    public const SCHEDULE = 'educbt_every_minute';

    public function init(): void {
        add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
        add_action( self::HOOK, [ $this, 'run' ] );
        add_action( 'init', [ $this, 'ensure_scheduled' ] );
    }

    /**
     * A one-minute interval. Exam windows close on the minute, and a student
     * should not wait five minutes to see a submitted state.
     */
    public function register_schedule( $schedules ) {
        if ( ! is_array( $schedules ) ) {
            $schedules = [];
        }

        $schedules[ self::SCHEDULE ] = [
            'interval' => 60,
            'display'  => __( 'Every Minute (EduCBT)', 'educbt-pro' ),
        ];

        return $schedules;
    }

    public function ensure_scheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
            $timestamp = wp_next_scheduled( self::HOOK );
        }
    }

    /**
     * Sweep every school that currently has at least one in-progress attempt.
     * Scoping the query this way keeps the job cheap outside exam periods.
     */
    public function run(): int {
        return $this->sweep_v1() + $this->sweep_v2();
    }

    /**
     * PHASE 6: sweep the v2 attempts table. A student who closed the browser, lost
     * power, or lost connectivity is still scored — the server closes the attempt on
     * its own clock rather than waiting for a client that will never come back.
     */
    private function sweep_v2(): int {
        global $wpdb;

        $attempts = Schema::table( 'attempts' );
        $papers   = Schema::table( 'exam_papers' );

        // One set-based query finds every attempt past its own window, including any
        // authorised extension. No PHP loop over a hall's worth of rows.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id, a.school_id FROM {$attempts} a
                 INNER JOIN {$papers} p ON p.id = a.paper_id
                 WHERE a.status = 'in_progress'
                   AND DATE_ADD(a.started_at,
                        INTERVAL (p.duration_seconds + a.extension_seconds) SECOND) <= %s
                 LIMIT 200",
                current_time( 'mysql', true )
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $service = new \EduCBTPro\Services\AttemptService();
        $closed  = 0;

        foreach ( $rows as $row ) {
            if ( $service->close( absint( $row['school_id'] ), absint( $row['id'] ), \EduCBTPro\Services\AttemptService::REASON_TIMEOUT ) ) {
                $closed++;
            }
        }

        return $closed;
    }

    private function sweep_v1(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'educbt_exam_attempts';

        $school_ids = $wpdb->get_col(
            "SELECT DISTINCT school_id FROM {$table} WHERE status = 'in_progress'"
        );

        if ( empty( $school_ids ) ) {
            return 0;
        }

        $service = new ExamAttemptService();
        $closed  = 0;

        foreach ( $school_ids as $school_id ) {
            $closed += $service->sweep_expired_attempts( absint( $school_id ) );
        }

        if ( $closed > 0 ) {
            EventDispatcher::action( 'attempts_swept', [ 'closed' => $closed ] );
        }

        return $closed;
    }
}
