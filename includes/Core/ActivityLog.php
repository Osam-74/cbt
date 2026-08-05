<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The school's activity record.
 *
 * The principal's dashboard had an Activity panel that was always empty: the table
 * existed, the panel read from it, and nothing ever wrote to it. Every service
 * already fires a domain event, so rather than scattering log calls through the
 * codebase this subscribes to those events in one place.
 *
 * That also means the log describes what HAPPENED, not what someone remembered to
 * record — a service can't add a feature and forget to log it.
 */
class ActivityLog {

    /**
     * event => how to describe it.
     *
     * @return array<string,callable>
     */
    private static function subscriptions(): array {
        return [
            'educbt_student_registered' => static fn( array $p ): string =>
                sprintf( 'Registered student %s', $p['admission_number'] ?? '' ),

            'educbt_students_imported' => static fn( array $p ): string =>
                sprintf( 'Imported %d students', absint( $p['imported'] ?? 0 ) ),

            'educbt_staff_registered' => static fn( array $p ): string =>
                sprintf( 'Added staff member %s', $p['staff_number'] ?? '' ),

            'educbt_staff_assigned' => static fn( array $p ): string =>
                sprintf( 'Assigned a %s', str_replace( '_', ' ', (string) ( $p['type'] ?? '' ) ) ),

            'educbt_question_created' => static fn( array $p ): string =>
                'Added a question to the bank',

            'educbt_questions_imported' => static fn( array $p ): string =>
                sprintf( 'Imported %d questions', absint( $p['imported'] ?? 0 ) ),

            'educbt_paper_created' => static fn( array $p ): string =>
                'Scheduled an exam paper',

            'educbt_paper_published' => static fn( array $p ): string =>
                'Published an exam paper',

            'educbt_scores_awarded' => static fn( array $p ): string =>
                sprintf( 'Entered %d scores', absint( $p['saved'] ?? 0 ) ),

            'educbt_theory_marked' => static fn( array $p ): string =>
                sprintf( 'Marked %d written answers', absint( $p['marked'] ?? 0 ) ),

            'educbt_class_compiled' => static fn( array $p ): string =>
                sprintf( 'Compiled results for %d students', absint( $p['students'] ?? 0 ) ),

            'educbt_results_transitioned' => static fn( array $p ): string =>
                sprintf( 'Results moved from %s to %s', $p['from'] ?? '', $p['to'] ?? '' ),

            'educbt_results_published' => static fn( array $p ): string =>
                'Published results to students and parents',

            'educbt_promotion_proposed' => static fn( array $p ): string =>
                'Produced a promotion proposal',

            'educbt_promotion_committed' => static fn( array $p ): string =>
                sprintf( 'Committed promotion — %d enrolled', absint( $p['enrolled'] ?? 0 ) ),

            'educbt_transcript_issued' => static fn( array $p ): string =>
                sprintf( 'Issued transcript %s', $p['serial'] ?? '' ),

            'educbt_announcement_published' => static fn( array $p ): string =>
                sprintf( 'Sent an announcement to %d people', absint( $p['recipients'] ?? 0 ) ),
        ];
    }

    public function init(): void {
        foreach ( array_keys( self::subscriptions() ) as $event ) {
            add_action(
                $event,
                static function ( $payload ) use ( $event ): void {
                    ( new self() )->record( $event, is_array( $payload ) ? $payload : [] );
                },
                20,
                1
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function record( string $event, array $payload ): void {
        global $wpdb;

        $describe = self::subscriptions()[ $event ] ?? null;

        if ( $describe === null ) {
            return;
        }

        $school_id = absint( $payload['school_id'] ?? 0 );

        if ( $school_id === 0 ) {
            $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        }

        if ( $school_id === 0 ) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'educbt_audit_logs',
            [
                'school_id'   => $school_id,
                'user_id'     => get_current_user_id(),
                'action'      => substr( (string) $describe( $payload ), 0, 250 ),
                'object_type' => substr( str_replace( 'educbt_', '', $event ), 0, 90 ),
                'object_id'   => absint( $payload['id'] ?? 0 ),
                'created_at'  => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s' ]
        );
    }

    /**
     * Trim the log. A busy school writes thousands of rows a term and nobody reads
     * last year's.
     */
    public static function purge( int $days = 365 ): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'educbt_audit_logs WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',
                    current_time( 'mysql', true ),
                    $days
                )
            )
        );
    }
}
