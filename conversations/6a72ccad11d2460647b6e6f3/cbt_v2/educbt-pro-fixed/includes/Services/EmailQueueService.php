<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 9 — queued email delivery.
 *
 * Sending to 500 guardians inside the request that published results will time out,
 * and will lose messages half way through with no record of which half. Everything
 * is queued and drained by cron instead.
 *
 * Four properties this needs, none of which a direct wp_mail() loop has:
 *
 *   RESTARTABLE   a failed batch resumes rather than starting over or duplicating
 *   BOUNDED       a batch size that fits inside a shared host's execution limit
 *   RETRIED       shared-host SMTP fails transiently; one failure is not final
 *   AUDITABLE     "did the school email me" has an answer
 */
class EmailQueueService {

    public const HOOK        = 'educbt_drain_email_queue';
    public const SCHEDULE    = 'educbt_every_minute';
    public const BATCH_SIZE  = 20;
    public const MAX_ATTEMPTS = 4;

    public function init(): void {
        add_action( self::HOOK, [ $this, 'drain' ] );
        add_action( 'init', [ $this, 'ensure_scheduled' ] );
    }

    public function ensure_scheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 45, self::SCHEDULE, self::HOOK );
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
     * @return int queue id, or 0 if rejected
     */
    public function queue( int $school_id, string $recipient, string $subject, string $body, ?string $send_after = null ): int {
        $recipient = trim( $recipient );

        if ( ! is_email( $recipient ) ) {
            return 0;
        }

        // Placeholder addresses are generated for students and guardians who have
        // none. Mailing them would bounce for every such user in the school.
        if ( str_ends_with( strtolower( $recipient ), '.invalid' ) ) {
            return 0;
        }

        global $wpdb;

        $wpdb->insert(
            Schema::table( 'email_queue' ),
            [
                'school_id'     => $school_id,
                'recipient'     => $recipient,
                'subject'       => sanitize_text_field( $subject ),
                'body'          => wp_kses_post( $body ),
                'status'        => 'queued',
                'scheduled_for' => $send_after ?: current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        return absint( $wpdb->insert_id );
    }

    /**
     * Send one batch.
     *
     * Each message is claimed before sending, so two overlapping cron runs — which
     * happen on busy shared hosts — cannot both send the same email.
     *
     * @return array{sent:int,failed:int,remaining:int}
     */
    public function drain(): array {
        global $wpdb;

        $table = Schema::table( 'email_queue' );
        $now   = current_time( 'mysql', true );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'queued' AND scheduled_for <= %s AND attempts < %d
                 ORDER BY id ASC LIMIT %d",
                $now,
                self::MAX_ATTEMPTS,
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        $sent   = 0;
        $failed = 0;

        foreach ( $rows as $row ) {
            $id = absint( $row['id'] );

            // Claim it. If another run got there first, affected rows is 0 and this
            // one is skipped rather than sent twice.
            $claimed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = 'sending', attempts = attempts + 1
                     WHERE id = %d AND status = 'queued'",
                    $id
                )
            );

            if ( ! $claimed ) {
                continue;
            }

            $ok = $this->send( (string) $row['recipient'], (string) $row['subject'], (string) $row['body'] );

            if ( $ok ) {
                $wpdb->update(
                    $table,
                    [ 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ), 'last_error' => '' ],
                    [ 'id' => $id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                $sent++;
                continue;
            }

            $attempts = absint( $row['attempts'] ) + 1;

            if ( $attempts >= self::MAX_ATTEMPTS ) {
                $wpdb->update(
                    $table,
                    [ 'status' => 'failed', 'last_error' => 'max_attempts_reached' ],
                    [ 'id' => $id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
            } else {
                // Exponential backoff: a mail server rejecting everything right now
                // will not be helped by retrying every minute.
                $delay = (int) pow( 4, $attempts ) * MINUTE_IN_SECONDS;

                $wpdb->update(
                    $table,
                    [
                        'status'        => 'queued',
                        'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
                        'last_error'    => 'send_failed',
                    ],
                    [ 'id' => $id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
            }

            $failed++;
        }

        $remaining = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued' AND attempts < %d", self::MAX_ATTEMPTS )
            )
        );

        if ( $sent > 0 || $failed > 0 ) {
            EventDispatcher::action( 'educbt_email_batch_drained', [
                'sent'      => $sent,
                'failed'    => $failed,
                'remaining' => $remaining,
            ] );
        }

        return [ 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining ];
    }

    /**
     * Isolated so tests can substitute it and so a school can swap in an SMTP
     * plugin without touching the queue.
     */
    protected function send( string $recipient, string $subject, string $body ): bool {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        return (bool) wp_mail( $recipient, $subject, wpautop( $body ), $headers );
    }

    /**
     * @return array<string,int>
     */
    public function stats( int $school_id ): array {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS n FROM ' . Schema::table( 'email_queue' ) . ' WHERE school_id = %d GROUP BY status',
                $school_id
            ),
            ARRAY_A
        );

        $out = [ 'queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0 ];

        foreach ( $rows as $row ) {
            $out[ (string) $row['status'] ] = absint( $row['n'] );
        }

        return $out;
    }

    /**
     * Messages that gave up. Surfaced to the school, because "the parents were never
     * told" needs to be visible rather than buried in a status column.
     *
     * @return array<int,array<string,mixed>>
     */
    public function failures( int $school_id, int $limit = 50 ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, recipient, subject, attempts, last_error, scheduled_for
                 FROM ' . Schema::table( 'email_queue' ) . "
                 WHERE school_id = %d AND status = 'failed'
                 ORDER BY id DESC LIMIT %d",
                $school_id,
                $limit
            ),
            ARRAY_A
        );
    }

    public function retry_failed( int $school_id ): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . Schema::table( 'email_queue' ) . "
                     SET status = 'queued', attempts = 0, scheduled_for = %s, last_error = ''
                     WHERE school_id = %d AND status = 'failed'",
                    current_time( 'mysql', true ),
                    $school_id
                )
            )
        );
    }

    /**
     * A message stuck in `sending` means the process died mid-send. Returning it to
     * the queue is the safe recovery: a duplicate email is a nuisance, a result
     * notification nobody receives is a support call.
     */
    public function requeue_stalled( int $minutes = 10 ): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . Schema::table( 'email_queue' ) . "
                     SET status = 'queued'
                     WHERE status = 'sending' AND scheduled_for < DATE_SUB(%s, INTERVAL %d MINUTE)",
                    current_time( 'mysql', true ),
                    $minutes
                )
            )
        );
    }
}
